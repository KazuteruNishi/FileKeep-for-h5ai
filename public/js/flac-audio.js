// FLAC-only software playback. MKV demuxing and libFLAC run entirely client-side.
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

export async function prepareFlacAudio(video, href, options = {}) {
  const { signal, onStatus = () => {}, onState = () => {} } = options;
  if (signal?.aborted) return null;
  const { Input, MATROSKA, UrlSource, EncodedPacketSink } = await import('./audio/mediabunny.min.js?v=1.65.0');
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
    if (!track || await track.getCodec() !== 'flac') {
      input.dispose(); signal?.removeEventListener('abort', cancelInput);
      return null;
    }
    if (signal?.aborted) { input.dispose(); return null; }
    onStatus('Préparation du son FLAC…');
    const rate = await track.getSampleRate();
    const channels = await track.getNumberOfChannels();
    if (channels < 1 || channels > 8 || rate < 8000 || rate > 192000) throw new Error('Configuration FLAC non prise en charge.');
    const library = await loadDecoder();
    if (signal?.aborted) { input.dispose(); return null; }
    const decoder = new library.FLACDecoderWebWorker();
    try { await decoder.ready; }
    catch (error) { decoder.terminate(); throw error; }
    if (signal?.aborted) { decoder.terminate(); input.dispose(); return null; }
    controller = new FlacPlayback(video, input, new EncodedPacketSink(track), decoder, { rate, channels, onStatus, onState });
    signal?.removeEventListener('abort', cancelInput);
    signal?.addEventListener('abort', () => controller.destroy(), { once: true });
    return controller;
  } catch (error) {
    input?.dispose(); signal?.removeEventListener('abort', cancelInput);
    if (signal?.aborted) return null;
    throw error;
  }
}

class FlacPlayback {
  constructor(video, input, sink, decoder, { rate, channels, onStatus, onState }) {
    this.video = video; this.input = input; this.sink = sink; this.decoder = decoder;
    this.rate = rate; this.channels = channels; this.onStatus = onStatus; this.onState = onState;
    this.epoch = 0; this.needsReset = true; this.busy = false; this.disposed = false;
    this.failed = false; this.waiting = false; this.ended = false; this.sources = new Set();
    this.anchor = null; this.iterator = null; this.queuedUntil = 0; this.nextMediaTimestamp = null;
    this.events = new AbortController();
    const Context = window.AudioContext || window.webkitAudioContext;
    if (!Context) { decoder.terminate(); throw new Error('Web Audio indisponible sur ce navigateur.'); }
    try {
      this.context = new Context({ sampleRate: rate, latencyHint: 'interactive' });
      this.gain = this.context.createGain(); this.gain.connect(this.context.destination);
      // Silence the native track without changing video.muted: Shaka keeps its
      // real volume/mute state, and cannot accidentally produce duplicate audio.
      this.nativeSource = this.context.createMediaElementSource(video);
      this.nativeGain = this.context.createGain(); this.nativeGain.gain.value = 0;
      this.nativeSource.connect(this.nativeGain); this.nativeGain.connect(this.context.destination);
    } catch (error) {
      decoder.terminate(); this.context?.close().catch(() => {}); throw error;
    }
    const on = (target, name, fn, capture = false) => target.addEventListener(name, fn, { signal: this.events.signal, capture });
    const root = video.closest('.fk-player') || video;
    const resume = () => {
      if (this.disposed || this.failed || this.context.state === 'closed') return;
      this.context.resume().then(() => {
        if (!this.disposed && !this.failed) { this.onStatus(''); this.restart(); }
      }).catch(() => this.onStatus('Clique sur le lecteur pour activer le son FLAC.'));
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
      else if (!this.disposed && !this.failed && !video.paused) this.onStatus('Clique sur le lecteur pour activer le son FLAC.');
    });
    this.updateVolume(); this.onState('flac');
    this.timer = setInterval(() => this.tick(), 60);
    if (this.context.state === 'suspended') this.onStatus('Clique sur le lecteur pour activer le son FLAC.');
    resume();
  }

  updateVolume() {
    this.gain.gain.setValueAtTime(this.video.muted ? 0 : this.video.volume, this.context.currentTime);
  }

  stopSources() {
    for (const source of this.sources) { try { source.stop(); } catch (_) {} source.disconnect(); }
    this.sources.clear();
  }

  restart() {
    if (this.disposed || this.failed) return;
    this.epoch++; this.needsReset = true; this.anchor = null; this.ended = false; this.nextMediaTimestamp = null;
    this.stopSources(); this.queuedUntil = this.video.currentTime;
    this.tick();
  }

  canPlay() {
    return !this.disposed && !this.failed && !this.video.paused && !this.video.ended
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
        await this.decoder.reset();
        if (!valid()) return;
        const packet = await this.sink.getPacket(this.video.currentTime) || await this.sink.getFirstPacket();
        if (!valid()) return;
        if (!packet) { this.ended = true; return; }
        this.iterator = this.sink.packets(packet);
        this.anchor = { media: this.video.currentTime, context: this.context.currentTime, rate: this.video.playbackRate };
      }
      if (this.ended || !this.anchor) return;
      const ahead = Math.min(4, Math.max(1, 1.5 * this.anchor.rate));
      for (let count = 0; count < 128 && valid() && this.queuedUntil < this.video.currentTime + ahead; count++) {
        const next = await this.iterator.next();
        if (!valid()) return;
        if (next.done) { this.ended = true; break; }
        const packet = next.value;
        const decoded = await this.decoder.decodeFrames([packet.data]);
        if (!valid()) return;
        if (decoded.errors?.length) throw new Error('Une trame FLAC ne peut pas être décodée.');
        if (!decoded.samplesDecoded) continue;
        if (decoded.channelData.length !== this.channels || decoded.sampleRate !== this.rate) throw new Error('La configuration FLAC change pendant la lecture.');
        const buffer = this.context.createBuffer(this.channels, decoded.samplesDecoded, this.rate);
        for (let channel = 0; channel < this.channels; channel++) buffer.copyToChannel(decoded.channelData[channel], channel);
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
    this.gain.gain.value = 0; this.nativeGain.gain.value = 1;
    this.input.dispose(); this.decoder.terminate();
    this.onState('error'); this.onStatus(error.message || 'Le décodage FLAC a échoué.');
  }

  async destroy() {
    if (this.disposed) return;
    this.disposed = true; this.epoch++; clearInterval(this.timer); this.events.abort();
    this.stopSources(); this.input.dispose(); this.decoder.terminate();
    this.gain.disconnect(); this.nativeSource.disconnect(); this.nativeGain.disconnect();
    await this.context.close().catch(() => {});
  }
}
