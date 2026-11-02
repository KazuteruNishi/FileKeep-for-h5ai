(() => {
  'use strict';
  const sourceScript = document.currentScript;
  const assetRoot = new URL('./', sourceScript.src);
  const subtitleEndpoint = sourceScript.dataset.subtitles;
  const instances = new WeakMap();
  let rendererLibrary;
  let shakaLibrary;
  let registered = false;
  function loadShaka() {
    const cssUrl = new URL('../css/shaka/controls.css?v=5.3.3', assetRoot);
    if (!Array.from(document.querySelectorAll('link[rel="stylesheet"]')).some(link => link.href === cssUrl.href)) {
      const link = document.createElement('link');
      link.rel = 'stylesheet'; link.href = cssUrl.href; document.head.append(link);
    }
    if (window.shaka?.ui?.Overlay) return Promise.resolve();
    if (!shakaLibrary) shakaLibrary = new Promise((resolve, reject) => {
      const script = document.createElement('script');
      const url = new URL('shaka/shaka-player.ui.js?v=5.3.3', assetRoot);
      const timeout = setTimeout(() => {
        script.remove(); reject(new Error(`Chargement de Shaka trop long : ${url.pathname}. Vérifie son accès sur le serveur.`));
      }, 15000);
      script.src = url.href;
      script.onload = () => {
        clearTimeout(timeout);
        if (window.shaka?.ui?.Overlay) resolve();
        else reject(new Error('Shaka a été téléchargé mais ne peut pas démarrer. Vérifie les erreurs JavaScript du navigateur.'));
      };
      script.onerror = () => {
        clearTimeout(timeout); script.remove();
        reject(new Error(`Impossible de charger ${url.pathname}. Vérifie que ce fichier a été transféré et qu’Apache le sert en JavaScript.`));
      };
      document.head.append(script);
    }).catch(error => { shakaLibrary = null; throw error; });
    return shakaLibrary;
  }
  function loadRenderer() {
    if (!rendererLibrary) rendererLibrary = new Promise((resolve, reject) => {
      const script = document.createElement('script');
      script.src = new URL('jassub/jassub.umd.js', assetRoot).href;
      script.onload = () => window.JASSUB ? resolve(window.JASSUB) : reject(new Error('Moteur ASS indisponible.'));
      script.onerror = () => reject(new Error('Impossible de charger le moteur ASS.'));
      document.head.append(script);
    }).catch(error => { rendererLibrary = null; throw error; });
    return rendererLibrary;
  }
  function registerSubtitleDelay() {
    if (registered) return;
    class SubtitleDelay extends shaka.ui.SettingsMenu {
      constructor(parent, controls) {
        super(parent, controls, shaka.ui.Enums.MaterialDesignSVGIcons.CLOSED_CAPTIONS);
        const state = instances.get(controls.getVideoContainer());
        this.button.classList.add('fk-subtitle-delay-button');
        this.menu.classList.add('fk-subtitle-delay-menu');
        const label = 'Décalage des sous-titres';
        this.button.setAttribute('aria-label', label);
        this.nameSpan.textContent = label; this.backSpan.textContent = label;
        this.currentSelection.textContent = '0 s';
        this.button.hidden = !state?.hasAssTracks;
        const field = document.createElement('label');
        field.className = 'fk-delay-field'; field.textContent = 'Secondes';
        const input = document.createElement('input');
        input.type = 'number'; input.min = '-600'; input.max = '600'; input.step = '0.1'; input.value = String(state?.offset || 0);
        input.setAttribute('aria-label', label + ' en secondes');
        field.append(input); this.menu.append(field);
        const reset = document.createElement('button'); reset.type = 'button';
        reset.textContent = 'Réinitialiser'; reset.className = 'shaka-no-propagation';
        this.menu.append(reset);
        const update = () => {
          const offset = Math.max(-600, Math.min(600, Number(input.value) || 0));
          if (state) state.setOffset(offset);
          this.currentSelection.textContent = offset.toLocaleString('fr-FR') + ' s';
        };
        this.eventManager.listen(input, 'input', update);
        this.eventManager.listen(field, 'click', event => event.stopPropagation());
        this.eventManager.listen(input, 'keydown', event => event.stopPropagation());
        this.eventManager.listen(reset, 'click', () => { input.value = '0'; update(); });
        if (state) state.delayButton = this.button;
      }
    }
    shaka.ui.OverflowMenu.registerElement('filekeep_subtitle_delay', { create: (parent, controls) => new SubtitleDelay(parent, controls) });
    registered = true;
  }
  window.FileKeepPlayer = {
    create(video, href) {
      const root = document.createElement('section');
      root.className = 'fk-player'; root.tabIndex = 0;
      root.dataset.player = 'shaka'; root.setAttribute('aria-label', 'Lecteur vidéo Shaka');
      video.controls = false; video.playsInline = true;
      root.append(video);
      const messages = document.createElement('div'); messages.className = 'fk-messages';
      const playbackStatus = document.createElement('p');
      playbackStatus.className = 'fk-play-status'; playbackStatus.setAttribute('role', 'status'); messages.append(playbackStatus);
      const subtitleStatus = document.createElement('p');
      subtitleStatus.className = 'fk-sub-status'; subtitleStatus.setAttribute('role', 'status'); messages.append(subtitleStatus);
      const audioStatus = document.createElement('p');
      audioStatus.className = 'fk-audio-status'; audioStatus.setAttribute('role', 'status'); messages.append(audioStatus);
      root.append(messages);
      const abort = new AbortController();
      let player = null, ui = null, renderer = null, manifest = null, selection = 0, disposed = false;
      let cleanupPromise = null, addingTracks = false, activeAssId = null, flacAudio = null;
      const assTracks = new Map(); const trackUrls = [];
      const on = (target, event, handler) => target.addEventListener(event, handler, { signal: abort.signal });
      const status = text => { subtitleStatus.textContent = text; };
      const state = {
        isMkv: /\.mkv$/i.test(new URL(href, location.href).pathname), hasAssTracks: false, delayButton: null, offset: 0,
        setOffset(offset) { this.offset = offset; if (renderer) renderer.timeOffset = offset; }
      };
      instances.set(root, state);
      function destroyRenderer() {
        if (renderer) { try { renderer.destroy(); } catch (_) {} renderer = null; }

      }
      async function syncSubtitles() {
        if (disposed || addingTracks || !player) return;
        const active = player.getTextTracks().find(track => track.active);
        const track = active ? assTracks.get(active.id) : null;
        if ((track?.id ?? null) === activeAssId) return;
        activeAssId = track?.id ?? null;
        const version = ++selection;
        destroyRenderer();
        if (!track || !manifest) { root.dataset.assState = 'disabled'; status(''); return; }
        root.dataset.assState = 'loading';
        status('Chargement du rendu ASS…');
        try {
          if (!window.WebAssembly || !window.Worker) throw new Error('Ce navigateur ne permet pas le rendu ASS.');
          const Renderer = await loadRenderer();
          const response = await fetch(track.url, { credentials: 'same-origin', signal: abort.signal });
          if (!response.ok || !(response.headers.get('content-type') || '').startsWith('text/plain')) throw new Error('Sous-titres indisponibles. Recharge la page si ta session a expiré.');
          const content = await response.text();
          if (disposed || !root.isConnected || version !== selection) return;
          const vendor = new URL('jassub/', assetRoot);
          renderer = new Renderer({ video, subContent: content,
            workerUrl: new URL('jassub-worker.js', vendor).href,
            wasmUrl: new URL('jassub-worker.wasm', vendor).href,
            modernWasmUrl: new URL('jassub-worker-modern.wasm', vendor).href,
            fonts: manifest.fonts,
            availableFonts: { 'liberation sans': new URL('default.woff2', vendor).href },
            fallbackFont: 'liberation sans', useLocalFonts: false,
            libassMemoryLimit: 32, libassGlyphLimit: 16,
            timeOffset: state.offset
          });
          renderer.addEventListener('error', () => { if (!disposed) status('Le rendu ASS a échoué. Vérifie les fichiers JASSUB et la configuration Apache.'); });
          renderer.addEventListener('ready', () => {
            if (!disposed && version === selection) { root.dataset.assState = 'ready'; status(''); }
          });

        } catch (error) { if (error.name !== 'AbortError' && !disposed && version === selection) status(error.message); }
      }
      async function discoverSubtitles() {
        if (!state.isMkv) return;
        root.dataset.assState = 'discovering';
        status('Recherche des sous-titres intégrés…');
        try {
          const body = new URLSearchParams({ file: new URL(href, location.href).pathname,
            _csrf: document.querySelector('meta[name="h5ai-csrf"]').content });
          const response = await fetch(subtitleEndpoint, { method: 'POST', body, credentials: 'same-origin', signal: abort.signal,
            headers: { Accept: 'application/json' } });
          if (!(response.headers.get('content-type') || '').includes('application/json')) throw new Error('Session expirée : recharge la page pour te reconnecter.');
          const data = await response.json();
          if (!response.ok) throw new Error(data.message || 'Analyse impossible.');
          if (disposed || !root.isConnected) return;
          manifest = data; addingTracks = true;
          for (const track of data.tracks) {
            if (disposed) return;
            // Shaka's native text tracks provide its real menu and selection.
            // Empty cues leave rendering to libass, preserving the original ASS.
            const url = URL.createObjectURL(new Blob(['WEBVTT\n\n'], { type: 'text/vtt' }));
            trackUrls.push(url);
            const label = track.label + ' · ' + track.language;
            const shakaTrack = await player.addTextTrackAsync(url, track.language || 'und', 'subtitles', 'text/vtt', '', label);
            assTracks.set(shakaTrack.id, track);
          }
          if (disposed) return;
          player.selectTextTrack();
          state.hasAssTracks = assTracks.size > 0;
          if (state.delayButton) state.delayButton.hidden = !state.hasAssTracks;
          root.dataset.assState = state.hasAssTracks ? 'available' : 'unavailable';
          status(data.warnings.join(' '));
        } catch (error) {
          if (error.name !== 'AbortError' && !disposed) { root.dataset.assState = 'error'; status(error.message); }
        } finally { addingTracks = false; }
      }
      // Preserve native input actions, avoiding h5ai's overlay event cancellation.
      for (const name of ['mousedown', 'mousemove', 'touchstart', 'touchmove']) on(root, name, event => event.stopPropagation());
      on(video, 'loadedmetadata', () => window.dispatchEvent(new Event('resize')));
      const originalUnload = video.unload;
      video.unload = () => {
        if (disposed) return cleanupPromise;
        disposed = true; selection++; abort.abort(); destroyRenderer(); instances.delete(root);
        video.pause();
        cleanupPromise = (async () => {
          if (flacAudio) await flacAudio.destroy();
          try { if (ui) await ui.destroy(); else if (player) await player.destroy(); }
          catch (_) {}
          for (const url of trackUrls) URL.revokeObjectURL(url);
          if (originalUnload) originalUnload();
          else { video.removeAttribute('src'); video.load(); }
        })();
        return cleanupPromise;
      };
      let mounted = false;
      const observer = new MutationObserver(() => {
        if (root.isConnected) mounted = true;
        else if (mounted) { video.unload(); observer.disconnect(); }
      });
      observer.observe(document.body, { childList: true, subtree: true });
      const mountTimeout = setTimeout(() => { if (!root.isConnected) { video.unload(); observer.disconnect(); } }, 5000);
      abort.signal.addEventListener('abort', () => clearTimeout(mountTimeout), { once: true });
      on(window, 'pagehide', () => { video.unload(); observer.disconnect(); });
      async function init() {
        try {
          await loadShaka();
          if (disposed) return;
          shaka.polyfill.installAll(); registerSubtitleDelay();
          player = new shaka.Player(); await player.attach(video, false);
          if (disposed) { await player.destroy(); return; }
          ui = new shaka.ui.Overlay(player, root, video);
          ui.getControls().getLocalization().changeLocale(['fr']);
          ui.configure({
            controlPanelElements: ['play_pause', 'mute', 'volume', 'time_and_duration', 'spacer', 'captions', 'overflow_menu', 'fullscreen'],
            overflowMenuButtons: ['captions', 'filekeep_subtitle_delay', 'playback_rate', 'language', 'picture_in_picture'],
            textTrackLabelFormat: shaka.ui.Overlay.TrackLabelFormat.LABEL,
            addSeekBar: true, enableTooltips: true,
            seekBarColors: { base: 'rgba(255,255,255,.25)', buffered: 'rgba(255,255,255,.5)', played: '#ededed' }
          });
          root.dataset.shakaVersion = shaka.Player.version;
          player.addEventListener('textchanged', syncSubtitles);
          player.addEventListener('error', () => {
            if (!disposed) playbackStatus.textContent = 'Lecture impossible : ce format audio ou vidéo peut être incompatible avec le navigateur.';
          });
          const ext = new URL(href, location.href).pathname.split('.').pop().toLowerCase();
          const mime = { mp4: 'video/mp4', m4v: 'video/mp4', webm: 'video/webm', mkv: 'video/x-matroska', mov: 'video/quicktime' }[ext];
          await player.load(new URL(href, location.href).href, null, mime);
          if (!disposed) discoverSubtitles();
          if (!disposed && state.isMkv) {
            root.dataset.audioEngine = 'probing';
            import(new URL('flac-audio.js?v=1.0.0', assetRoot).href).then(async ({ prepareFlacAudio }) => {
              if (disposed) return;
              const controller = await prepareFlacAudio(video, href, {
                signal: abort.signal,
                onStatus: text => { if (!disposed) audioStatus.textContent = text; },
                onState: mode => { if (!disposed) root.dataset.audioEngine = mode; }
              });
              if (disposed) { await controller?.destroy(); return; }
              flacAudio = controller;
              if (!controller) root.dataset.audioEngine = 'native';
            }).catch(error => {
              if (!disposed) {
                root.dataset.audioEngine = 'error';
                audioStatus.textContent = 'Son FLAC indisponible : ' + error.message;
              }
            });
          }
          if (!disposed && video.autoplay) {
            try { await video.play(); } catch (_) { /* Browser may require a click. */ }
          }
        } catch (error) {
          if (!disposed) {
            playbackStatus.textContent = error.message || 'Ce format audio ou vidéo est incompatible avec le navigateur.';
            // Keep an actionable fallback when Shaka itself failed to initialize.
            if (!ui) { video.controls = true; video.src = href; root.dataset.player = 'native-fallback'; }
          }
        }
      }
      init(); return root;
    }
  };
})();
