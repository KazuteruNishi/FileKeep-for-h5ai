// Integrated MKV audio playback: libFLAC and AC3/E-AC3/DTS WASM run client-side.
let decoderLibrary;

function loadDecoder() {
  if (window['flac-decoder']?.FLACDecoderWebWorker) return Promise.resolve(window['flac-decoder']);
  if (!decoderLibrary) decoderLibrary = new Promise((resolve, reject) => {
    const tag = document.createElement('script');
    tag.src = new URL('./audio/flac-decoder.min.js?v=0.2.11', import.meta.url).href;
    tag.charset = 'UTF-8';
    const timer = setTimeout(() => { tag.remove(); reject(new Error('Le décodeur FLAC ne répond pas.')); }, 15000);
    tag.onload = () => {
      clearTimeout(timer);
      if (window['flac-decoder']?.FLACDecoderWebWorker) resolve(window['flac-decoder']);
      else reject(new Error('Le décodeur FLAC ne peut pas démarrer.'));
    };
    tag.onerror = () => { clearTimeout(timer); tag.remove(); reject(new Error('Fichier audio/flac-decoder.min.js inaccessible.')); };
    document.head.append(tag);
  }).catch(error => { decoderLibrary = null; throw error; });
  return decoderLibrary;
}

export async function prepareAudio(video, href, options = {}) {
  const { signal, onStatus = () => {}, onState = () => {}, onTracks = () => {} } = options;
  if (signal?.aborted) return null;
  const { Input, MATROSKA, UrlSource, EncodedPacketSink, AudioBufferSink } = await import('./audio/mediabunny.min.js?v=1.65.0');
  if (signal?.aborted) return null;
  let input;
  const cancelInput = () => input?.dispose();
  input = new Input({ formats: [MATROSKA], source: new UrlSource(new URL(href, location.href), {
    maxCacheSize: 4 * 1024 * 1024, parallelism: 1,
    requestInit: { credentials: 'same-origin' }, getRetryDelay: () => null,
    handleUnhandledError: () => {},
    fetchFn: async (url, init) => {
      const response = await fetch(url, init);
      const range = new Headers(init?.headers).get('Range');
      if (!response.ok || (range && response.status !== 206)) {
        response.body?.cancel().catch(() => {});
        throw new Error('Lecture audio interrompue : vérifie la session et les requêtes Range du serveur.');
      }
      return response;
    }
  }) });
  signal?.addEventListener('abort', cancelInput, { once: true });
  let controller;
  try {
    const track = await input.getPrimaryAudioTrack();
    const supported = ['flac', 'ac3', 'eac3', 'dts'];
    const primaryCodec = track ? await track.getCodec() : null;
    if (!supported.includes(primaryCodec)) {
      input.dispose(); signal?.removeEventListener('abort', cancelInput);
      return null;
    }
    if (signal?.aborted) { input.dispose(); return null; }
    const choices = [];
    for (const candidate of await input.getAudioTracks()) {
      const codec = await candidate.getCodec();
      if (supported.includes(codec)) choices.push({ id: candidate.id, codec,
        label: await candidate.getName() || codec.toUpperCase(), language: await candidate.getLanguageCode(), track: candidate });
    }
    const createStream = async info => {
      const rate = await info.track.getSampleRate(), channels = await info.track.getNumberOfChannels();
      if (channels < 1 || channels > 8 || rate < 8000 || rate > 192000) throw new Error('Configuration audio non prise en charge.');
      onStatus('Préparation du son ' + info.codec.toUpperCase() + '…');
      let decoder = null, sink;
      if (info.codec === 'flac') {
        const library = await loadDecoder(); decoder = new library.FLACDecoderWebWorker();
        try { await decoder.ready; } catch (error) { decoder?.terminate(); throw error; }
        sink = new EncodedPacketSink(info.track);
      } else {
        if (info.codec === 'dts') {
          const extension = await import('./audio/mediabunny-dts.min.js?v=1.65.0'); extension.registerDtsDecoder();
        } else {
          const extension = await import('./audio/mediabunny-ac3.min.js?v=1.65.0'); extension.registerAc3Decoder();
        }
        sink = new AudioBufferSink(info.track);
      }
      return { sink, decoder, rate, channels, codec: info.codec, id: info.id };
    };
    const initial = choices.find(info => info.id === track.id);
    const stream = await createStream(initial);
    if (signal?.aborted) { stream.decoder?.terminate(); input.dispose(); return null; }
    controller = new SoftwareAudioPlayback(video, input, stream, { onStatus, onState, onTracks, choices, createStream });
    signal?.removeEventListener('abort', cancelInput);
    signal?.addEventListener('abort', () => controller.destroy(), { once: true });
    return controller;
  } catch (error) {
    input?.dispose(); signal?.removeEventListener('abort', cancelInput);
    if (signal?.aborted) return null;
    throw error;
  }
}

// Compatibility for cached clients using the first FLAC-only entry point.
export const prepareFlacAudio = prepareAudio;

class SoftwareAudioPlayback {
  constructor(video, input, stream, { onStatus, onState, onTracks, choices, createStream }) {
    const { sink, decoder, rate, channels, codec, id } = stream;
    this.codec = codec; this.activeTrackId = id; this.choices = choices; this.createStream = createStream; this.onTracks = onTracks;
    this.switchRequest = 0; this.switching = false;
    this.video = video; this.input = input; this.sink = sink; this.decoder = decoder;
    this.rate = rate; this.channels = channels; this.onStatus = onStatus; this.onState = onState;
    this.epoch = 0; this.needsReset = true; this.busy = false; this.disposed = false;
    this.failed = false; this.waiting = false; this.ended = false; this.sources = new Set();
    this.anchor = null; this.iterator = null; this.queuedUntil = 0; this.nextMediaTimestamp = null;
    this.events = new AbortController();
    const Context = window.AudioContext || window.webkitAudioContext;
    if (!Context) { decoder?.terminate(); throw new Error('Web Audio indisponible sur ce navigateur.'); }
    try {
      this.context = new Context({ sampleRate: rate, latencyHint: 'interactive' });
      this.gain = this.context.createGain(); this.gain.connect(this.context.destination);
      // Keep the native decoder on its own media clock. Routing the video into
      // Web Audio just to silence it also couples video playback to that graph.
      // Shaka reads the user's mute state; the underlying native track stays
      // muted while our independent PCM output follows that state and volume.
      const nativeMuted = Object.getOwnPropertyDescriptor(HTMLMediaElement.prototype, 'muted');
      const ownMuted = Object.getOwnPropertyDescriptor(video, 'muted');
      let muted = video.muted;
      Object.defineProperty(video, 'muted', {
        configurable: true,
        get: () => muted,
        set: value => {
          const next = Boolean(value);
          if (next === muted) return;
          muted = next; video.dispatchEvent(new Event('volumechange'));
        }
      });
      this.restoreNativeAudio = () => {
        if (ownMuted) Object.defineProperty(video, 'muted', ownMuted);
        else delete video.muted;
        nativeMuted.set.call(video, muted);
        this.restoreNativeAudio = null;
      };
      nativeMuted.set.call(video, true);
    } catch (error) {
      this.restoreNativeAudio?.();
      decoder?.terminate(); this.context?.close().catch(() => {}); throw error;
    }
    const on = (target, name, fn, capture = false) => target.addEventListener(name, fn, { signal: this.events.signal, capture });
    const root = video.closest('.fk-player') || video;
    const resume = () => {
      if (this.disposed || this.failed || this.context.state === 'closed') return;
      this.context.resume().then(() => {
        if (!this.disposed && !this.failed) { this.onStatus(''); this.restart(); }
      }).catch(() => this.onStatus('Clique sur le lecteur pour activer le son.'));
    };
    on(root, 'pointerdown', () => { if (this.context.state === 'suspended') resume(); }, true);
    on(root, 'keydown', () => { if (this.context.state === 'suspended') resume(); }, true);
    on(video, 'volumechange', () => this.updateVolume());
    on(video, 'play', () => { this.waiting = false; resume(); });
    on(video, 'playing', () => { this.waiting = false; this.restart(); });
    on(video, 'pause', () => this.restart());
    on(video, 'ended', () => this.restart());
    on(video, 'seeking', () => this.restart());
    on(video, 'seeked', () => { this.waiting = false; this.restart(); });
    on(video, 'ratechange', () => this.restart());
    on(video, 'waiting', () => { this.waiting = true; this.restart(); });
    on(document, 'visibilitychange', () => { if (!document.hidden) this.restart(); });
    on(this.context, 'statechange', () => {
      if (this.context.state === 'running') this.restart();
      else if (!this.disposed && !this.failed && !video.paused) this.onStatus('Clique sur le lecteur pour activer le son.');
    });
    this.updateVolume(); this.onState(codec); this.notifyTracks();
    this.timer = setInterval(() => this.tick(), 60);
    if (this.context.state === 'suspended') this.onStatus('Clique sur le lecteur pour activer le son.');
    resume();
  }

  notifyTracks() {
    this.onTracks(this.choices.map(({ id, codec, label, language }) => ({ id, codec, label, language })), this.activeTrackId);
  }

  async selectTrack(id) {
    if (this.disposed || this.failed || id === this.activeTrackId) return;
    const info = this.choices.find(info => info.id === id);
    if (!info) throw new Error('Piste audio introuvable.');
    const request = ++this.switchRequest;
    this.switching = true; this.epoch++; this.stopSources();
    let stream;
    try {
      stream = await this.createStream(info);
      const deadline = performance.now() + 10000;
      while (this.busy && !this.disposed && request === this.switchRequest) {
        if (performance.now() > deadline) throw new Error('Changement de piste trop long. Réessayez.');
        await new Promise(resolve => setTimeout(resolve, 10));
      }
      if (this.disposed || request !== this.switchRequest) { stream.decoder?.terminate(); return; }
      if (this.iterator) await this.iterator.return();
      if (this.disposed || request !== this.switchRequest) { stream.decoder?.terminate(); return; }
      this.decoder?.terminate(); this.iterator = null;
      Object.assign(this, { sink: stream.sink, decoder: stream.decoder, rate: stream.rate, channels: stream.channels,
        codec: stream.codec, activeTrackId: stream.id });
      this.onState(this.codec); this.notifyTracks(); this.onStatus('');
    } catch (error) {
      stream?.decoder?.terminate();
      if (!this.disposed && request === this.switchRequest) { this.onStatus(error.message); throw error; }
    } finally {
      if (request === this.switchRequest && !this.disposed) { this.switching = false; this.restart(); }
    }
  }

  updateVolume() {
    this.gain.gain.setValueAtTime(this.video.muted ? 0 : this.video.volume, this.context.currentTime);
  }

  stopSources() {
    for (const source of this.sources) { try { source.stop(); } catch (_) {} source.disconnect(); }
    this.sources.clear();
  }

  adaptToOutput(buffer) {
    const count = buffer.numberOfChannels;
    if (count <= 2 || this.context.destination.channelCount > 2) return buffer;
    // Explicit downmix also covers 6.1/7.1 layouts that browsers may otherwise
    // treat as discrete channels, losing dialogue from the centre channel.
    const left = new Array(count).fill(0), right = new Array(count).fill(0);
    left[0] = 1; right[1] = 1;
    if (count === 4) { left[2] = 0.707; right[3] = 0.707; }
    else {
      left[2] = right[2] = 0.707;
      if (count === 5) { left[3] = 0.5; right[4] = 0.5; }
      if (count >= 6) { left[3] = right[3] = 0.25; }
      if (count === 6) { left[4] = 0.5; right[5] = 0.5; }
      if (count === 7) { left[4] = right[4] = 0.5; left[5] = 0.5; right[6] = 0.5; }
      if (count === 8) { left[4] = left[6] = 0.5; right[5] = right[7] = 0.5; }
    }
    const result = this.context.createBuffer(2, buffer.length, buffer.sampleRate);
    for (let output = 0; output < 2; output++) {
      const weights = output ? right : left;
      const scale = 1 / Math.max(1, weights.reduce((sum, value) => sum + value, 0));
      const samples = result.getChannelData(output);
      for (let channel = 0; channel < count; channel++) {
        if (!weights[channel]) continue;
        const source = buffer.getChannelData(channel), gain = weights[channel] * scale;
        for (let i = 0; i < samples.length; i++) samples[i] += source[i] * gain;
      }
    }
    return result;
  }

  restart() {
    if (this.disposed || this.failed) return;
    this.epoch++; this.needsReset = true; this.anchor = null; this.ended = false; this.nextMediaTimestamp = null;
    this.stopSources(); this.queuedUntil = this.video.currentTime;
    this.tick();
  }

  canPlay() {
    return !this.disposed && !this.failed && !this.switching && !this.video.paused && !this.video.ended
      && !this.video.seeking && !this.waiting && this.context.state === 'running';
  }

  tick() {
    if (!this.canPlay() || this.busy) return;
    if (this.anchor) {
      const predicted = this.anchor.media + (this.context.currentTime - this.anchor.context) * this.anchor.rate;
      if (Math.abs(predicted - this.video.currentTime) > 0.15) { this.restart(); return; }
    }
    this.pump().catch(error => this.fail(error));
  }

  async pump() {
    if (this.busy) return;
    this.busy = true; const epoch = this.epoch;
    const valid = () => this.canPlay() && epoch === this.epoch;
    try {
      if (this.needsReset) {
        this.needsReset = false;
        if (this.iterator) await this.iterator.return();
        if (this.decoder) {
          await this.decoder.reset();
          if (!valid()) return;
          const packet = await this.sink.getPacket(this.video.currentTime) || await this.sink.getFirstPacket();
          if (!valid()) return;
          if (!packet) { this.ended = true; return; }
          this.iterator = this.sink.packets(packet);
        } else {
          this.iterator = this.sink.buffers(this.video.currentTime);
        }
        this.anchor = { media: this.video.currentTime, context: this.context.currentTime, rate: this.video.playbackRate };
      }
      if (this.ended || !this.anchor) return;
      const ahead = Math.min(4, Math.max(1, 1.5 * this.anchor.rate));
      for (let count = 0; count < 128 && valid() && this.queuedUntil < this.video.currentTime + ahead; count++) {
        const next = await this.iterator.next();
        if (!valid()) return;
        if (next.done) { this.ended = true; break; }
        const packet = next.value;
        let buffer;
        if (this.decoder) {
          const decoded = await this.decoder.decodeFrames([packet.data]);
          if (!valid()) return;
          if (decoded.errors?.length) throw new Error('Une trame FLAC ne peut pas être décodée.');
          if (!decoded.samplesDecoded) continue;
          if (decoded.channelData.length !== this.channels || decoded.sampleRate !== this.rate) throw new Error('La configuration audio change pendant la lecture.');
          buffer = this.context.createBuffer(this.channels, decoded.samplesDecoded, this.rate);
          for (let channel = 0; channel < this.channels; channel++) buffer.copyToChannel(decoded.channelData[channel], channel);
        } else {
          buffer = packet.buffer;
          if (buffer.numberOfChannels < 1 || buffer.numberOfChannels > 8) throw new Error('Disposition audio non prise en charge.');
        }
        buffer = this.adaptToOutput(buffer);
        // MKV timestamps are often rounded to milliseconds. Keep decoded sample
        // boundaries contiguous instead of introducing tiny overlaps or gaps.
        const timestamp = this.nextMediaTimestamp !== null && Math.abs(packet.timestamp - this.nextMediaTimestamp) < 0.004
          ? this.nextMediaTimestamp : packet.timestamp;
        this.nextMediaTimestamp = timestamp + buffer.duration;
        const scheduled = this.anchor.context + (timestamp - this.anchor.media) / this.anchor.rate;
        const when = Math.max(this.context.currentTime + 0.01, scheduled);
        const offset = Math.max(0, (when - scheduled) * this.anchor.rate);
        this.queuedUntil = this.nextMediaTimestamp;
        if (offset >= buffer.duration) continue;
        const source = this.context.createBufferSource(); source.buffer = buffer;
        source.playbackRate.value = this.anchor.rate; source.connect(this.gain);
        source.onended = () => { this.sources.delete(source); source.disconnect(); };
        this.sources.add(source); source.start(when, offset);
      }
    } finally { this.busy = false; }
  }

  fail(error) {
    if (this.disposed || this.failed) return;
    this.failed = true; this.epoch++; clearInterval(this.timer); this.stopSources();
    this.gain.gain.value = 0; this.restoreNativeAudio?.();
    this.input.dispose(); this.decoder?.terminate();
    this.iterator?.return().catch(() => {});
    this.onState('error'); this.onStatus(error.message || 'Le décodage audio a échoué.');
  }

  async destroy() {
    if (this.disposed) return;
    this.disposed = true; this.epoch++; clearInterval(this.timer); this.events.abort();
    this.stopSources(); this.input.dispose(); this.decoder?.terminate();
    try { await this.iterator?.return(); } catch (_) {}
    this.gain.disconnect(); this.restoreNativeAudio?.();
    await this.context.close().catch(() => {});
  }
}
