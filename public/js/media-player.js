(() => {
  'use strict';
  const sourceScript = document.currentScript;
  const assetRoot = new URL('./', sourceScript.src);
  const subtitleEndpoint = sourceScript.dataset.subtitles;
  const storyboardEndpoint = sourceScript.dataset.storyboards || new URL('../../../lecture/planches', assetRoot).href;
  const instances = new WeakMap();
  const audioStorageKey = 'filekeep:player-audio';
  const autoplayStorageKey = 'filekeep:autoplay-next';
  let autoplayNext = false, autoplayTarget = null;
  try { autoplayNext = localStorage.getItem(autoplayStorageKey) === 'true'; } catch (_) {}
  let audioPreferences = null;
  try {
    const saved = JSON.parse(localStorage.getItem(audioStorageKey));
    if (saved && Number.isFinite(saved.volume) && saved.volume >= 0 && saved.volume <= 1 && typeof saved.muted === 'boolean') {
      audioPreferences = { volume: saved.volume, muted: saved.muted };
    }
  } catch (_) { /* Keep in-memory preferences when browser storage is unavailable. */ }
  function useNativeVodDuration(player, video, signal) {
    const original = player.seekRange;
    const nativeMode = shaka.Player.LoadMode.SRC_EQUALS;
    const range = function () {
      const bounds = original.call(player), duration = video.duration;
      if (player.getLoadMode() !== nativeMode || player.isLive() || !Number.isFinite(duration) || duration <= 0) return bounds;
      const configuredEnd = player.getConfiguration().playRangeEnd;
      return { start: bounds.start, end: Math.max(bounds.start, Math.min(duration, configuredEnd ?? Infinity)) };
    };
    // Shaka's compiled UI calls the internal alias of seekRange. Replace every
    // alias of that exact function on this instance, without editing the library.
    const aliases = new Map();
    for (let object = player; object && object !== Object.prototype; object = Object.getPrototypeOf(object)) {
      for (const key of Object.getOwnPropertyNames(object)) {
        if (aliases.has(key) || Object.getOwnPropertyDescriptor(object, key)?.value !== original) continue;
        aliases.set(key, Object.getOwnPropertyDescriptor(player, key));
      }
    }
    for (const key of aliases.keys()) Object.defineProperty(player, key, { configurable: true, writable: true, value: range });
    signal.addEventListener('abort', () => {
      for (const [key, descriptor] of aliases) {
        if (descriptor) Object.defineProperty(player, key, descriptor);
        else delete player[key];
      }
    }, { once: true });
  }
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
    // Shaka 5.3.3 removes paths from a live NodeList and can leave a path
    // behind for multi-part icons. Clear a static list before every update.
    const useIcon = shaka.ui.Icon.prototype.use;
    shaka.ui.Icon.prototype.use = function (icon) {
      if (icon) {
        for (const path of this.getSvgElement().querySelectorAll('path')) {
          for (const animation of path.getAnimations()) animation.cancel();
          path.remove();
        }
      }
      return useIcon.call(this, icon);
    };
    // Control artwork inspected on the reference YouTube player (2026-11-02).
    // Geometry only; all playback and menu behavior remains managed by Shaka.
    const icons = {
      "REPLAY": {
        "path": "M12 2a10 10 0 1 1-10 10h2a8 8 0 1 0 2.34-5.66L9 9H2V2l2.93 2.93A9.96 9.96 0 0 1 12 2ZM10 7v10l7-5Z",
        "viewBox": "0 0 24 24"
      },
      "PLAY": {
        "path": "M 17 8.6 L 10.89 4.99 C 9.39 4.11 7.5 5.19 7.5 6.93 C 7.5 6.93 7.5 6.93 7.5 6.93 L 7.5 29.06 C 7.5 30.8 9.39 31.88 10.89 31 C 10.89 31 10.89 31 10.89 31 L 17 27.4 C 17 27.4 17 27.4 17 27.4 C 17 27.4 17 27.4 17 27.4 L 17 8.6 C 17 8.6 17 8.6 17 8.6 C 17 8.6 17 8.6 17 8.6 Z M 17 8.6 L 17 8.6 C 17 8.6 17 8.6 17 8.6 C 17 8.6 17 8.6 17 8.6 V 27.4 C 17 27.4 17 27.4 17 27.4 C 17 27.4 17 27.4 17 27.4 L 33 18 C 33 18 33 18 33 18 C 33 18 33 18 33 18 V 18 L 17 8.6 C 17 8.6 17 8.6 17 8.6 C 17 8.6 17 8.6 17 8.6 Z",
        "viewBox": "0 0 36 36"
      },
      "PAUSE": {
        "path": "M 12.75 4.5 L 9.75 4.5 C 9.15 4.5 8.58 4.73 8.15 5.15 C 7.73 5.58 7.5 6.15 7.5 6.75 L 7.5 29.25 C 7.5 29.84 7.73 30.41 8.15 30.84 C 8.58 31.26 9.15 31.5 9.75 31.5 L 12.75 31.5 C 13.34 31.5 13.91 31.26 14.34 30.84 C 14.76 30.41 15 29.84 15 29.25 L 15 6.75 C 15 6.15 14.76 5.58 14.34 5.15 C 13.91 4.73 13.34 4.5 12.75 4.5 Z M 26.25 4.5 L 23.25 4.5 C 22.65 4.5 22.08 4.73 21.65 5.15 C 21.23 5.58 21 6.15 21 6.75 V 29.25 C 21 29.84 21.23 30.41 21.65 30.84 C 22.08 31.26 22.65 31.5 23.25 31.5 L 26.25 31.5 C 26.84 31.5 27.41 31.26 27.84 30.84 C 28.26 30.41 28.5 29.84 28.5 29.25 V 6.75 L 28.5 6.75 C 28.5 6.15 28.26 5.58 27.84 5.15 C 27.41 4.73 26.84 4.5 26.25 4.5 Z",
        "viewBox": "0 0 36 36"
      },
      "MUTE": {
        "path": [
          "M 11.60 2.08 L 11.48 2.14 L 3.91 6.68 C 3.02 7.21 2.28 7.97 1.77 8.87 C 1.26 9.77 1.00 10.79 1 11.83 V 12.16 L 1.01 12.56 C 1.07 13.52 1.37 14.46 1.87 15.29 C 2.38 16.12 3.08 16.81 3.91 17.31 L 11.48 21.85 C 11.63 21.94 11.80 21.99 11.98 21.99 C 12.16 22.00 12.33 21.95 12.49 21.87 C 12.64 21.78 12.77 21.65 12.86 21.50 C 12.95 21.35 13 21.17 13 21 V 3 C 12.99 2.83 12.95 2.67 12.87 2.52 C 12.80 2.37 12.68 2.25 12.54 2.16 C 12.41 2.07 12.25 2.01 12.08 2.00 C 11.92 1.98 11.75 2.01 11.60 2.08 Z",
          " M 15.53 7.05 C 15.35 7.22 15.25 7.45 15.24 7.70 C 15.23 7.95 15.31 8.19 15.46 8.38 L 15.53 8.46 L 15.70 8.64 C 16.09 9.06 16.39 9.55 16.61 10.08 L 16.70 10.31 C 16.90 10.85 17 11.42 17 12 L 16.99 12.24 C 16.96 12.73 16.87 13.22 16.70 13.68 L 16.61 13.91 C 16.36 14.51 15.99 15.07 15.53 15.53 C 15.35 15.72 15.25 15.97 15.26 16.23 C 15.26 16.49 15.37 16.74 15.55 16.92 C 15.73 17.11 15.98 17.21 16.24 17.22 C 16.50 17.22 16.76 17.12 16.95 16.95 C 17.6 16.29 18.11 15.52 18.46 14.67 L 18.59 14.35 C 18.82 13.71 18.95 13.03 18.99 12.34 L 19 12 C 18.99 11.19 18.86 10.39 18.59 9.64 L 18.46 9.32 C 18.15 8.57 17.72 7.89 17.18 7.3 L 16.95 7.05 L 16.87 6.98 C 16.68 6.82 16.43 6.74 16.19 6.75 C 15.94 6.77 15.71 6.87 15.53 7.05",
          "M18.36 4.22C18.18 4.39 18.08 4.62 18.07 4.87C18.05 5.12 18.13 5.36 18.29 5.56L18.36 5.63L18.66 5.95C19.36 6.72 19.91 7.60 20.31 8.55L20.47 8.96C20.82 9.94 21 10.96 21 11.99L20.98 12.44C20.94 13.32 20.77 14.19 20.47 15.03L20.31 15.44C19.86 16.53 19.19 17.52 18.36 18.36C18.17 18.55 18.07 18.80 18.07 19.07C18.07 19.33 18.17 19.59 18.36 19.77C18.55 19.96 18.80 20.07 19.07 20.07C19.33 20.07 19.59 19.96 19.77 19.77C20.79 18.75 21.61 17.54 22.16 16.20L22.35 15.70C22.72 14.68 22.93 13.62 22.98 12.54L23 12C22.99 10.73 22.78 9.48 22.35 8.29L22.16 7.79C21.67 6.62 20.99 5.54 20.15 4.61L19.77 4.22L19.70 4.15C19.51 3.99 19.26 3.91 19.02 3.93C18.77 3.94 18.53 4.04 18.36 4.22 Z"
        ],
        "viewBox": "0 0 24 24"
      },
      "UNMUTE": {
        "path": "M11.60 2.08L11.48 2.14L3.91 6.68C3.02 7.21 2.28 7.97 1.77 8.87C1.26 9.77 1.00 10.79 1 11.83V12.16L1.01 12.56C1.07 13.52 1.37 14.46 1.87 15.29C2.38 16.12 3.08 16.81 3.91 17.31L11.48 21.85C11.63 21.94 11.80 21.99 11.98 21.99C12.16 22.00 12.33 21.95 12.49 21.87C12.64 21.78 12.77 21.65 12.86 21.50C12.95 21.35 13 21.17 13 21V3C12.99 2.83 12.95 2.67 12.87 2.52C12.80 2.37 12.68 2.25 12.54 2.16C12.41 2.07 12.25 2.01 12.08 2.00C11.92 1.98 11.75 2.01 11.60 2.08ZM4.94 8.4V8.40L11 4.76V19.23L4.94 15.6C4.38 15.26 3.92 14.80 3.58 14.25C3.24 13.70 3.05 13.07 3.00 12.43L3 12.17V11.83C2.99 11.14 3.17 10.46 3.51 9.86C3.85 9.25 4.34 8.75 4.94 8.4ZM21.29 8.29L19 10.58L16.70 8.29L16.63 8.22C16.43 8.07 16.19 7.99 15.95 8.00C15.70 8.01 15.47 8.12 15.29 8.29C15.12 8.47 15.01 8.70 15.00 8.95C14.99 9.19 15.07 9.43 15.22 9.63L15.29 9.70L17.58 12L15.29 14.29C15.19 14.38 15.12 14.49 15.06 14.61C15.01 14.73 14.98 14.87 14.98 15.00C14.98 15.13 15.01 15.26 15.06 15.39C15.11 15.51 15.18 15.62 15.28 15.71C15.37 15.81 15.48 15.88 15.60 15.93C15.73 15.98 15.86 16.01 15.99 16.01C16.12 16.01 16.26 15.98 16.38 15.93C16.50 15.87 16.61 15.80 16.70 15.70L19 13.41L21.29 15.70L21.36 15.77C21.56 15.93 21.80 16.01 22.05 15.99C22.29 15.98 22.53 15.88 22.70 15.70C22.88 15.53 22.98 15.29 22.99 15.05C23.00 14.80 22.93 14.56 22.77 14.36L22.70 14.29L20.41 12L22.70 9.70C22.80 9.61 22.87 9.50 22.93 9.38C22.98 9.26 23.01 9.12 23.01 8.99C23.01 8.86 22.98 8.73 22.93 8.60C22.88 8.48 22.81 8.37 22.71 8.28C22.62 8.18 22.51 8.11 22.39 8.06C22.26 8.01 22.13 7.98 22.00 7.98C21.87 7.98 21.73 8.01 21.61 8.06C21.49 8.12 21.38 8.19 21.29 8.29Z",
        "viewBox": "0 0 24 24"
      },
      "FULLSCREEN": {
        "path": "M10 3H3V10C3 10.26 3.10 10.51 3.29 10.70C3.48 10.89 3.73 11 4 11C4.26 11 4.51 10.89 4.70 10.70C4.89 10.51 5 10.26 5 10V6.41L9.29 10.70L9.36 10.77C9.56 10.92 9.80 11.00 10.04 10.99C10.29 10.98 10.52 10.87 10.70 10.70C10.87 10.52 10.98 10.29 10.99 10.04C11.00 9.80 10.92 9.56 10.77 9.36L10.70 9.29L6.41 5H10C10.26 5 10.51 4.89 10.70 4.70C10.89 4.51 11 4.26 11 4C11 3.73 10.89 3.48 10.70 3.29C10.51 3.10 10.26 3 10 3ZM20 13C19.73 13 19.48 13.10 19.29 13.29C19.10 13.48 19 13.73 19 14V17.58L14.70 13.29L14.63 13.22C14.43 13.07 14.19 12.99 13.95 13.00C13.70 13.01 13.47 13.12 13.29 13.29C13.12 13.47 13.01 13.70 13.00 13.95C12.99 14.19 13.07 14.43 13.22 14.63L13.29 14.70L17.58 19H14C13.73 19 13.48 19.10 13.29 19.29C13.10 19.48 13 19.73 13 20C13 20.26 13.10 20.51 13.29 20.70C13.48 20.89 13.73 21 14 21H21V14C21 13.73 20.89 13.48 20.70 13.29C20.51 13.10 20.26 13 20 13Z",
        "viewBox": "0 0 24 24"
      },
      "EXIT_FULLSCREEN": {
        "path": "M3.29 3.29C3.11 3.46 3.01 3.70 3.00 3.94C2.98 4.19 3.06 4.43 3.22 4.63L3.29 4.70L7.58 8.99H5C4.73 8.99 4.48 9.10 4.29 9.29C4.10 9.47 4 9.73 4 9.99C4 10.26 4.10 10.51 4.29 10.70C4.48 10.89 4.73 10.99 5 10.99H11V4.99C11 4.73 10.89 4.47 10.70 4.29C10.51 4.10 10.26 3.99 10 3.99C9.73 3.99 9.48 4.10 9.29 4.29C9.10 4.47 9 4.73 9 4.99V7.58L4.70 3.29L4.63 3.22C4.43 3.06 4.19 2.98 3.94 3.00C3.70 3.01 3.46 3.11 3.29 3.29ZM19 13H13V19C13 19.26 13.10 19.51 13.29 19.70C13.48 19.89 13.73 20 14 20C14.26 20 14.51 19.89 14.70 19.70C14.89 19.51 15 19.26 15 19V16.41L19.29 20.70L19.36 20.77C19.56 20.92 19.80 21.00 20.04 20.99C20.29 20.98 20.52 20.87 20.70 20.70C20.87 20.52 20.98 20.29 20.99 20.04C21.00 19.80 20.92 19.56 20.77 19.36L20.70 19.29L16.41 15H19C19.26 15 19.51 14.89 19.70 14.70C19.89 14.51 20 14.26 20 14C20 13.73 19.89 13.48 19.70 13.29C19.51 13.10 19.26 13 19 13Z",
        "viewBox": "0 0 24 24"
      },
      "OPEN_OVERFLOW": {
        "path": "M12.84 1H11.15C10.72 .99 10.30 1.14 9.95 1.40C9.60 1.66 9.35 2.02 9.23 2.44L9.19 2.61C9.11 3.00 8.96 3.38 8.73 3.71C8.51 4.04 8.22 4.33 7.89 4.55L7.75 4.64C7.37 4.85 6.96 4.98 6.53 5.02C6.11 5.06 5.68 5.01 5.27 4.87C4.86 4.73 4.42 4.73 4.00 4.86C3.59 5.00 3.23 5.26 2.99 5.62L2.89 5.77L2.05 7.23C1.82 7.63 1.73 8.10 1.81 8.55C1.88 9.01 2.12 9.43 2.47 9.73L2.58 9.84C3.15 10.39 3.50 11.15 3.50 12L3.49 12.16C3.47 12.56 3.37 12.95 3.19 13.31C3.01 13.67 2.77 13.99 2.47 14.26C2.12 14.56 1.88 14.98 1.81 15.43C1.73 15.89 1.82 16.36 2.05 16.76L2.89 18.22L2.99 18.37C3.24 18.73 3.59 18.99 4.01 19.13C4.42 19.26 4.86 19.26 5.27 19.12L5.42 19.07C5.81 18.96 6.21 18.93 6.61 18.98C7.01 19.03 7.40 19.15 7.75 19.36L7.89 19.44C8.22 19.66 8.51 19.95 8.73 20.28C8.96 20.61 9.11 20.99 9.19 21.38C9.28 21.84 9.52 22.24 9.88 22.54C10.24 22.83 10.69 23.00 11.15 23H12.84C13.30 23.00 13.75 22.83 14.11 22.54C14.47 22.24 14.71 21.84 14.80 21.38C14.89 20.96 15.06 20.56 15.31 20.21C15.55 19.86 15.88 19.57 16.25 19.36L16.39 19.28C16.75 19.10 17.14 18.99 17.54 18.96C17.94 18.94 18.34 18.99 18.72 19.12L18.89 19.17C19.31 19.27 19.75 19.24 20.15 19.07C20.55 18.90 20.88 18.60 21.10 18.23L21.95 16.76C22.18 16.36 22.26 15.89 22.19 15.43C22.11 14.98 21.88 14.56 21.53 14.26C21.23 13.99 20.98 13.67 20.80 13.31C20.63 12.95 20.52 12.56 20.50 12.16L20.50 12C20.50 11.57 20.59 11.14 20.77 10.75C20.94 10.36 21.20 10.01 21.53 9.73C21.88 9.43 22.11 9.01 22.19 8.55C22.26 8.10 22.18 7.63 21.95 7.23L21.10 5.76C20.88 5.39 20.55 5.09 20.15 4.92C19.76 4.75 19.31 4.72 18.89 4.82L18.72 4.87C18.34 5.00 17.94 5.05 17.54 5.03C17.14 5.00 16.75 4.89 16.4 4.71L16.25 4.63C15.88 4.42 15.56 4.13 15.31 3.78C15.06 3.43 14.89 3.03 14.80 2.61C14.71 2.15 14.47 1.74 14.11 1.45C13.75 1.16 13.30 .99 12.84 1ZM11.15 3H12.84C12.98 3.70 13.26 4.36 13.68 4.94C14.09 5.52 14.63 6.01 15.25 6.37C15.87 6.72 16.55 6.94 17.26 7.01C17.97 7.08 18.69 6.99 19.37 6.76L20.21 8.23C19.67 8.69 19.24 9.27 18.94 9.92C18.65 10.57 18.50 11.28 18.5 12C18.50 12.71 18.65 13.42 18.95 14.07C19.24 14.72 19.67 15.29 20.21 15.76L19.37 17.23C18.69 16.99 17.97 16.91 17.26 16.98C16.55 17.05 15.86 17.27 15.25 17.63C14.63 17.98 14.09 18.47 13.68 19.05C13.26 19.63 12.98 20.29 12.84 21H11.15C11.01 20.29 10.73 19.63 10.31 19.05C9.90 18.47 9.36 17.98 8.75 17.62C8.13 17.27 7.44 17.05 6.73 16.98C6.02 16.91 5.30 16.99 4.62 17.23L3.78 15.76C4.32 15.29 4.75 14.71 5.05 14.06C5.34 13.41 5.49 12.71 5.5 12C5.50 11.28 5.34 10.57 5.05 9.92C4.75 9.27 4.32 8.69 3.78 8.23L4.62 6.76C5.30 7.00 6.02 7.08 6.73 7.01C7.44 6.94 8.13 6.72 8.75 6.37C9.36 6.01 9.90 5.52 10.31 4.94C10.73 4.36 11.01 3.70 11.15 3ZM12.00 8C10.94 8 9.92 8.42 9.17 9.17C8.42 9.92 8.00 10.93 8.00 12C8.00 13.06 8.42 14.07 9.17 14.82C9.92 15.57 10.94 16 12.00 16C13.06 16 14.08 15.57 14.83 14.82C15.58 14.07 16.00 13.06 16.00 12C16.00 10.93 15.58 9.92 14.83 9.17C14.08 8.42 13.06 8 12.00 8ZM12.00 10H12L12.20 10.01C12.69 10.06 13.15 10.29 13.48 10.65C13.81 11.02 14.00 11.50 14 12L13.99 12.20C13.95 12.58 13.80 12.95 13.55 13.25C13.31 13.55 12.98 13.78 12.62 13.90C12.25 14.02 11.85 14.03 11.48 13.93C11.11 13.83 10.77 13.62 10.51 13.34C10.25 13.05 10.08 12.69 10.02 12.31C9.96 11.93 10.01 11.54 10.17 11.18C10.32 10.83 10.58 10.53 10.91 10.32C11.23 10.11 11.61 10.00 12 10",
        "viewBox": "0 0 24 24"
      },
      "PIP": {
        "path": "M1 6a2 2 0 012-2h18a2 2 0 012 2v12a2 2 0 01-2 2H3a2 2 0 01-2-2V6Zm2 0v12h18V6H3Zm16 6h-6v4h6v-4Z",
        "viewBox": "0 0 24 24"
      },
      "EXIT_PIP": {
        "path": "M1 6a2 2 0 012-2h18a2 2 0 012 2v12a2 2 0 01-2 2H3a2 2 0 01-2-2V6Zm2 0v12h18V6H3Zm16 6h-6v4h6v-4Z",
        "viewBox": "0 0 24 24"
      },
      "CLOSED_CAPTIONS": {
        "path": "M21 3H3C2.46 3 1.96 3.21 1.58 3.58C1.21 3.96 1 4.46 1 5V19C1 19.53 1.21 20.03 1.58 20.41C1.96 20.78 2.46 21 3 21H21C21.53 21 22.03 20.78 22.41 20.41C22.78 20.03 23 19.53 23 19V5C23 4.46 22.78 3.96 22.41 3.58C22.03 3.21 21.53 3 21 3ZM6 11H8C8.26 11 8.51 11.10 8.70 11.29C8.89 11.48 9 11.73 9 12C9 12.26 8.89 12.51 8.70 12.70C8.51 12.89 8.26 13 8 13H6C5.73 13 5.48 12.89 5.29 12.70C5.10 12.51 5 12.26 5 12C5 11.73 5.10 11.48 5.29 11.29C5.48 11.10 5.73 11 6 11ZM12 11H18C18.26 11 18.51 11.10 18.70 11.29C18.89 11.48 19 11.73 19 12C19 12.26 18.89 12.51 18.70 12.70C18.51 12.89 18.26 13 18 13H12C11.73 13 11.48 12.89 11.29 12.70C11.10 12.51 11 12.26 11 12C11 11.73 11.10 11.48 11.29 11.29C11.48 11.10 11.73 11 12 11ZM16 15H18C18.26 15 18.51 15.10 18.70 15.29C18.89 15.48 19 15.73 19 16C19 16.26 18.89 16.51 18.70 16.70C18.51 16.89 18.26 17 18 17H16C15.73 17 15.48 16.89 15.29 16.70C15.10 16.51 15 16.26 15 16C15 15.73 15.10 15.48 15.29 15.29C15.48 15.10 15.73 15 16 15ZM6 15H12C12.26 15 12.51 15.10 12.70 15.29C12.89 15.48 13 15.73 13 16C13 16.26 12.89 16.51 12.70 16.70C12.51 16.89 12.26 17 12 17H6C5.73 17 5.48 16.89 5.29 16.70C5.10 16.51 5 16.26 5 16C5 15.73 5.10 15.48 5.29 15.29C5.48 15.10 5.73 15 6 15Z",
        "viewBox": "0 0 24 24"
      },
      "CLOSED_CAPTIONS_OFF": {
        "path": "M21.20 3.01L21 3H3L2.79 3.01C2.30 3.06 1.84 3.29 1.51 3.65C1.18 4.02 .99 4.50 1 5V19L1.01 19.20C1.05 19.66 1.26 20.08 1.58 20.41C1.91 20.73 2.33 20.94 2.79 20.99L3 21H21L21.20 20.98C21.66 20.94 22.08 20.73 22.41 20.41C22.73 20.08 22.94 19.66 22.99 19.20L23 19V5C23.00 4.50 22.81 4.02 22.48 3.65C22.15 3.29 21.69 3.06 21.20 3.01ZM3 19V5H21V19H3ZM8 11H6C5.73 11 5.48 11.10 5.29 11.29C5.10 11.48 5 11.73 5 12C5 12.26 5.10 12.51 5.29 12.70C5.48 12.89 5.73 13 6 13H8C8.26 13 8.51 12.89 8.70 12.70C8.89 12.51 9 12.26 9 12C9 11.73 8.89 11.48 8.70 11.29C8.51 11.10 8.26 11 8 11ZM18 11H12C11.73 11 11.48 11.10 11.29 11.29C11.10 11.48 11 11.73 11 12C11 12.26 11.10 12.51 11.29 12.70C11.48 12.89 11.73 13 12 13H18C18.26 13 18.51 12.89 18.70 12.70C18.89 12.51 19 12.26 19 12C19 11.73 18.89 11.48 18.70 11.29C18.51 11.10 18.26 11 18 11ZM18 15H16C15.73 15 15.48 15.10 15.29 15.29C15.10 15.48 15 15.73 15 16C15 16.26 15.10 16.51 15.29 16.70C15.48 16.89 15.73 17 16 17H18C18.26 17 18.51 16.89 18.70 16.70C18.89 16.51 19 16.26 19 16C19 15.73 18.89 15.48 18.70 15.29C18.51 15.10 18.26 15 18 15ZM12 15H6C5.73 15 5.48 15.10 5.29 15.29C5.10 15.48 5 15.73 5 16C5 16.26 5.10 16.51 5.29 16.70C5.48 16.89 5.73 17 6 17H12C12.26 17 12.51 16.89 12.70 16.70C12.89 16.51 13 16.26 13 16C13 15.73 12.89 15.48 12.70 15.29C12.51 15.10 12.26 15 12 15Z",
        "viewBox": "0 0 24 24"
      }
    };
    Object.assign(shaka.ui.Enums.MaterialDesignSVGIcons, icons);
    for (const name of ['FULLSCREEN', 'EXIT_FULLSCREEN']) {
      const icon = shaka.ui.Enums.MaterialDesignSVGIcons[name];
      icon.path = icon.path.match(/[Mm][^Mm]*/g);
    }
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
    class SoftwareAudioSelection extends shaka.ui.SettingsMenu {
      constructor(parent, controls) {
        super(parent, controls, shaka.ui.Enums.MaterialDesignSVGIcons.LANGUAGE);
        const root = controls.getVideoContainer();
        const state = instances.get(root);
        this.button.classList.add('fk-audio-language-button');
        this.menu.classList.add('fk-audio-languages');
        this.button.setAttribute('aria-label', 'Piste audio');
        this.nameSpan.textContent = 'Piste audio'; this.backSpan.textContent = 'Piste audio';
        const update = () => {
          const tracks = state?.audioTracks || [];
          this.button.hidden = tracks.length < 2;
          for (const child of Array.from(this.menu.children)) if (child !== this.backButton) child.remove();
          for (const track of tracks) {
            const row = document.createElement('button'); row.type = 'button';
            row.setAttribute('role', 'menuitemradio');
            const chosen = track.id === state.selectedAudioId;
            row.setAttribute('aria-checked', String(chosen));
            const label = track.label + ' · ' + track.language + ' (' + track.codec.toUpperCase() + ')';
            const text = document.createElement('span'); text.textContent = label; row.append(text);
            if (chosen) {
              text.classList.add('shaka-chosen-item');
              new shaka.ui.Icon(row, shaka.ui.Enums.MaterialDesignSVGIcons.CHECKMARK);
              this.currentSelection.textContent = label;
            }
            this.menu.append(row);
            row.addEventListener('click', () => {
              state.audioController?.selectTrack(track.id).catch(error => state.audioStatus(error.message));
            });
          }
        };
        this.eventManager.listen(root, 'filekeep-audio-updated', update); update();
      }
    }
    shaka.ui.OverflowMenu.registerElement('filekeep_audio', { create: (parent, controls) => new SoftwareAudioSelection(parent, controls) });
    registered = true;
  }
  window.FileKeepPlayer = {
    create(video, href, navigation = null) {
      if (autoplayTarget === new URL(href, location.href).href) {
        video.autoplay = true; autoplayTarget = null;
      }
      const root = document.createElement('section');
      root.className = 'fk-player'; root.tabIndex = 0;
      root.dataset.player = 'shaka'; root.setAttribute('aria-label', 'Lecteur vidéo Shaka');
      const filename = new URL(href, location.href).pathname.split('/').pop() || 'Vidéo';
      let title;
      try { title = decodeURIComponent(filename); } catch (_) { title = filename; }
      video.title = title.replace(/\.(?:mkv|mp4|webm|m4v|mov|avi|flv|mpg|mpeg)$/i, '');
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
      let cleanupPromise = null, addingTracks = false, activeAssId = null, softwareAudio = null;
      const assTracks = new Map(); const trackUrls = [];
      let chapterData = [];
      const on = (target, event, handler, capture = false) => target.addEventListener(event, handler, { signal: abort.signal, capture });
      window.FileKeepViews?.track(video, href, abort.signal);
      const playbackProgress = window.FileKeepProgress?.track(video, href, abort.signal);
      window.FileKeepFileJournal?.track(video, href, abort.signal);
      if (audioPreferences) {
        video.volume = audioPreferences.volume;
        video.muted = audioPreferences.muted;
      }
      const rememberAudio = () => {
        // Read the logical mute state, including software-decoded audio.
        // Ignore late events from a player whose asynchronous cleanup started.
        if (!disposed) {
          audioPreferences = { volume: video.volume, muted: video.muted };
          try { localStorage.setItem(audioStorageKey, JSON.stringify(audioPreferences)); } catch (_) {}
        }
      };
      on(video, 'volumechange', rememberAudio);
      on(video, 'enterpictureinpicture', () => root.classList.add('fk-native-pip'));
      on(video, 'leavepictureinpicture', () => root.classList.remove('fk-native-pip'));
      on(video, 'webkitpresentationmodechanged', () => {
        root.classList.toggle('fk-native-pip', video.webkitPresentationMode === 'picture-in-picture');
      });
      const feedback = document.createElement('div');
      feedback.className = 'fk-playback-feedback'; feedback.setAttribute('aria-hidden', 'true');
      const feedbackDisk = document.createElement('div');
      feedbackDisk.className = 'fk-playback-feedback-disk'; feedback.append(feedbackDisk);
      const feedbackAnchor = document.createElement('div');
      feedbackAnchor.className = 'fk-playback-feedback-anchor';
      feedbackAnchor.setAttribute('aria-hidden', 'true');
      feedbackAnchor.append(feedback); root.append(feedbackAnchor);
      let feedbackIcon = null, feedbackAnimation = null, feedbackDiskAnimation = null, playbackIntent = 0;
      on(root, 'click', event => {
        const control = event.target.closest('button,input,select,a,[role="menu"]');
        if (!control || control.classList.contains('shaka-play-button')) playbackIntent = performance.now();
      }, true);
      on(root, 'keydown', event => {
        if (event.target.closest('input,textarea,select,button,[contenteditable="true"]')) return;
        if (event.key === ' ' || event.key.toLowerCase() === 'k') playbackIntent = performance.now();
      }, true);
      function showPlaybackFeedback(event) {
        if (!feedbackIcon || !playbackIntent || performance.now() - playbackIntent > 1000 || video.seeking || video.ended) return;
        playbackIntent = 0;
        feedbackIcon.use(shaka.ui.Enums.MaterialDesignSVGIcons[event.type === 'play' ? 'PLAY' : 'PAUSE']);
        feedbackAnimation?.cancel();
        feedbackDiskAnimation?.cancel();
        // The circle grows while the icon stays on a fixed pixel grid.
        // Scaling thin SVG strokes caused visible shimmer during the zoom.
        const size = Math.round(Math.max(56, Math.min(96, window.innerWidth * .09)) / 2) * 2;
        feedback.style.width = feedback.style.height = size + 'px';
        const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        feedbackAnimation = feedback.animate([
          { opacity: 1 },
          { opacity: 0 }
        ], { duration: 600, easing: 'ease-out' });
        feedbackDiskAnimation = feedbackDisk.animate([
          { transform: 'scale(1)' },
          { transform: `scale(${reducedMotion ? 1 : 1.25})` }
        ], { duration: 600, easing: 'ease-out' });
      }
      on(video, 'play', showPlaybackFeedback);
      on(video, 'pause', showPlaybackFeedback);
      const seekFeedback = document.createElement('div');
      seekFeedback.className = 'fk-seek-feedback'; seekFeedback.setAttribute('aria-hidden', 'true');
      const seekArrows = document.createElement('div'); seekArrows.className = 'fk-seek-arrows';
      const seekLabel = document.createElement('span'); seekLabel.className = 'fk-seek-duration';
      seekFeedback.append(seekArrows, seekLabel); root.append(seekFeedback);
      const seekAnimations = new Set();
      let seekFadeTimer = null, seekFadeAnimation = null, lastSeekAt = 0, lastSeekDirection = 0, seekSeconds = 0;
      const cancelSeekFeedback = () => {
        clearTimeout(seekFadeTimer); seekFadeAnimation = null;
        for (const animation of seekAnimations) animation.cancel();
        seekAnimations.clear(); seekFeedback.style.opacity = '0';
      };
      const animateSeek = (element, frames, timing) => {
        const animation = element.animate(frames, timing); seekAnimations.add(animation);
        const remove = () => seekAnimations.delete(animation);
        animation.addEventListener('finish', remove, { once: true });
        animation.addEventListener('cancel', remove, { once: true });
        return animation;
      };
      const makeSeekArrow = direction => {
        const arrow = document.createElement('div'); arrow.className = 'fk-seek-arrow';
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('viewBox', '0 0 22 32'); svg.setAttribute('width', '22'); svg.setAttribute('height', '24');
        const path = document.createElementNS(svg.namespaceURI, 'path');
        path.setAttribute('d', direction < 0 ? 'M 18 4 L 6 16 L 18 28' : 'M 4 4 L 16 16 L 4 28');
        path.setAttribute('stroke', 'white'); path.setAttribute('stroke-width', '4');
        path.setAttribute('stroke-linecap', 'round'); path.setAttribute('fill', 'none');
        svg.append(path); arrow.append(svg); return arrow;
      };
      abort.signal.addEventListener('abort', cancelSeekFeedback, { once: true });
      on(root, 'keydown', event => {
        const key = event.key.toLowerCase();
        const arrowKey = key === 'arrowleft' || key === 'arrowright';
        if ((!arrowKey && key !== 'j' && key !== 'l') || event.altKey || event.ctrlKey || event.metaKey || event.isComposing) return;
        if (event.target.closest('textarea,select,[contenteditable]:not([contenteditable="false"]),input:not([type="range"])')) return;
        if (arrowKey && (event.target.closest('.shaka-settings-menu,.shaka-overflow-menu,[role="menu"]')
          || event.target.matches('input[type="range"]:not(.shaka-seek-bar)'))) return;
        if (!player || disposed || video.readyState < 1) return;
        const bounds = player.seekRange();
        const start = Number.isFinite(bounds.start) ? bounds.start : 0;
        const end = Number.isFinite(bounds.end) ? bounds.end : video.duration;
        if (!Number.isFinite(end) || end <= start) return;
        event.preventDefault(); event.stopImmediatePropagation();
        const direction = key === 'j' || key === 'arrowleft' ? -1 : 1;
        const distance = arrowKey ? 5 : 10;
        const target = Math.max(start, Math.min(end, video.currentTime + direction * distance));
        if (Math.abs(target - video.currentTime) < .001) return;
        video.currentTime = target;
        ui.getControls().showUI();
        const now = performance.now();
        const repeated = direction === lastSeekDirection && now - lastSeekAt < 1200;
        seekSeconds = repeated ? seekSeconds + distance : distance;
        lastSeekAt = now; lastSeekDirection = direction;
        if (!repeated) { cancelSeekFeedback(); seekArrows.replaceChildren(); }
        else { clearTimeout(seekFadeTimer); seekFadeAnimation?.cancel(); }
        seekFeedback.dataset.direction = direction < 0 ? 'back' : 'forward';
        seekLabel.textContent = (direction < 0 ? '− ' : '+ ') + seekSeconds;
        seekFeedback.style.opacity = '1';
        const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        // Geometry, keyframes and timings inspected on YouTube's current
        // ytp-seek-overlay: a morphing chevron and signed duration, no bezel.
        const arrow = makeSeekArrow(direction);
        arrow.classList.add(repeated ? 'fk-seek-arrow-additional' : 'fk-seek-arrow-persistent');
        if (!repeated || !reducedMotion) seekArrows.append(arrow);
        const easing = 'cubic-bezier(.05,0,0,1)';
        if (!repeated) animateSeek(seekFeedback, [{ opacity: 0 }, { opacity: 1 }], { duration: 200, easing: 'cubic-bezier(.2,0,.6,1)' });
        if (!reducedMotion) {
          const frames = [{ transform: `translateX(${-direction * 20}px)`, opacity: repeated ? 0 : 1 }];
          if (repeated) frames.push({ offset: .5, opacity: 1 });
          frames.push({ transform: 'translateX(0)', opacity: 1 });
          const movement = animateSeek(arrow, frames, { duration: 400, easing });
          if (repeated) movement.addEventListener('finish', () => arrow.remove(), { once: true });
          const path = arrow.querySelector('path');
          const flat = direction < 0 ? 'M 18 4 L 18 16 L 18 28' : 'M 4 4 L 4 16 L 4 28';
          animateSeek(path, [{ d: `path("${flat}")` }, { d: `path("${path.getAttribute('d')}")` }], { duration: 333, easing });
          if (repeated) {
            for (const animation of seekLabel.getAnimations()) animation.cancel();
            animateSeek(seekLabel, [
              { transform: 'scale(1)', offset: 0, easing: 'cubic-bezier(.2,0,.6,1)' },
              { transform: 'scale(.9)', offset: .64, easing: 'cubic-bezier(.2,0,.6,1)' },
              { transform: 'scale(1)', offset: 1 }
            ], { duration: 184 });
          }
        }
        seekFadeTimer = setTimeout(() => {
          seekFeedback.style.opacity = '0';
          seekFadeAnimation = animateSeek(seekFeedback, [{ opacity: 1 }, { opacity: 0 }], { duration: 200, easing: 'cubic-bezier(.2,0,.6,1)' });
        }, 1000);
      }, true);
      function animateButtons() {
        const motion = window.matchMedia('(prefers-reduced-motion: reduce)');
        const animations = new Set();
        const animate = (element, frames, options) => {
          if (disposed) return;
          const animation = element.animate(frames, motion.matches ? { ...options, duration: 100, delay: 0 } : options);
          animations.add(animation);
          animation.addEventListener('finish', () => animations.delete(animation), { once: true });
          animation.addEventListener('cancel', () => animations.delete(animation), { once: true });
          return animation;
        };
        const observers = [];
        const playIcon = root.querySelector('.shaka-play-button>.shaka-ui-icon');
        if (playIcon) {
          let previous = playIcon.querySelector('path')?.getAttribute('d');
          let morph;
          const observer = new MutationObserver(() => {
            const path = playIcon.querySelector('path');
            const next = path?.getAttribute('d');
            const icons = shaka.ui.Enums.MaterialDesignSVGIcons;
            const playbackPaths = [icons.PLAY.path, icons.PAUSE.path];
            if (next !== previous && playbackPaths.includes(previous) && playbackPaths.includes(next)) {
              morph?.cancel();
              morph = animate(path, [{ d: `path("${previous}")` }, { d: `path("${next}")` }],
                { duration: 200, easing: 'cubic-bezier(.4,0,.2,1)' });
            }
            previous = next;
          });
          observer.observe(playIcon, { childList: true, subtree: true }); observers.push(observer);
        }
        const muteButton = root.querySelector('.shaka-mute-button');
        if (muteButton) {
          const icons = shaka.ui.Enums.MaterialDesignSVGIcons;
          let muted = video.muted || video.volume === 0, revision = 0;
          const waveLevels = () => video.muted || video.volume === 0 ? [0, 0]
            : [Math.min(1, video.volume * 2), Math.max(0, video.volume * 2 - 1)];
          let levels = waveLevels();
          let waveAnimations = [];
          // Keep a stable drawing while Shaka updates the native icon and label.
          const drawing = new shaka.ui.Icon(null, muted ? icons.UNMUTE : icons.MUTE);
          const svg = drawing.getSvgElement(); svg.classList.add('fk-volume-icon'); muteButton.append(svg);
          const applyLevels = values => {
            Array.from(svg.querySelectorAll('path')).slice(1).forEach((path, index) => {
              path.style.transformBox = 'view-box'; path.style.transformOrigin = `${index === 0 ? 18 : 22}px 12px`;
              path.style.transform = `scale(${values[index]})`;
            });
          };
          applyLevels(levels);
          // Wave growth sampled from the reference player's 250 ms transition.
          const growth = [[0, 0], [.1, .11], [.2, .18], [.3, .25], [.4, .29], [.5, .35], [.6, .42], [.7, .51], [.8, .63], [.9, .79], [1, 1]];
          on(video, 'volumechange', () => {
            const nextMuted = video.muted || video.volume === 0;
            const nextLevels = waveLevels();
            if (disposed || levels.every((value, index) => value === nextLevels[index])) return;
            muted = nextMuted;
            levels = nextLevels;
            const version = ++revision;
            const previousWaves = Array.from(svg.querySelectorAll('path')).slice(1);
            const from = previousWaves.map(path => new DOMMatrix(getComputedStyle(path).transform).a);
            waveAnimations.forEach(animation => animation.cancel()); waveAnimations = [];
            if (!previousWaves.length) drawing.use(icons.MUTE);
            for (const [index, path] of Array.from(svg.querySelectorAll('path')).slice(1).entries()) {
              path.style.transformBox = 'view-box'; path.style.transformOrigin = `${index === 0 ? 18 : 22}px 12px`;
              const start = from[index] || 0, to = levels[index];
              const animation = animate(path, growth.map(([offset, value]) => ({ offset, transform: `scale(${start + (to - start) * value})` })),
                { duration: 250, easing: 'linear', fill: 'both' });
              if (animation) waveAnimations.push(animation);
            }
            Promise.all(waveAnimations.map(animation => animation.finished)).then(() => {
              if (disposed || version !== revision) return;
              waveAnimations.forEach(animation => animation.cancel()); waveAnimations = [];
              drawing.use(muted ? icons.UNMUTE : icons.MUTE);
              applyLevels(levels);
            }).catch(() => {});
          });
        }
        const cancel = () => { for (const animation of animations) animation.cancel(); animations.clear(); };
        abort.signal.addEventListener('abort', () => { observers.forEach(observer => observer.disconnect()); cancel(); }, { once: true });
      }
      function configureAutoplay(actions) {
        if (!navigation || navigation.count < 2) return;
        const button = document.createElement('button'); button.type = 'button';
        button.className = 'fk-autoplay-next shaka-no-propagation'; button.setAttribute('role', 'switch');
        const track = document.createElement('span'); track.className = 'fk-autoplay-track';
        track.setAttribute('aria-hidden', 'true'); button.append(track); actions.prepend(button);
        const update = () => {
          const label = `Lecture automatique : ${autoplayNext ? 'activée' : 'désactivée'}`;
          button.setAttribute('aria-checked', String(autoplayNext)); button.setAttribute('aria-label', label);
          button.dataset.tooltipLabel = label;
          const tooltipLabel = button.querySelector('.fk-shortcut-tooltip')?.firstElementChild;
          if (tooltipLabel) tooltipLabel.textContent = label;
        };
        update();
        on(button, 'click', () => {
          autoplayNext = !autoplayNext;
          try { localStorage.setItem(autoplayStorageKey, String(autoplayNext)); } catch (_) {}
          update();
        });
        let advanced = false;
        on(video, 'play', () => { advanced = false; });
        on(video, 'ended', () => {
          if (disposed || advanced || !autoplayNext || !navigation.next) return;
          advanced = true;
          autoplayTarget = new URL(navigation.next.href, location.href).href;
          navigation.moveNext();
          // The next h5ai preview is created in a microtask. Do not retain a
          // pending autoplay request if navigation was cancelled or failed.
          setTimeout(() => { autoplayTarget = null; }, 0);
        });
      }
      function configureNavigation(panel) {
        if (!navigation || navigation.count < 2) return;
        const group = document.createElement('div'); group.className = 'fk-playlist-actions';
        group.setAttribute('role', 'group'); group.setAttribute('aria-label', 'Navigation entre les vidéos');
        panel.querySelector('.shaka-play-button').after(group);
        for (const [direction, path, label] of [
          ['previous', 'M4 4C3.73 4 3.48 4.10 3.29 4.29C3.10 4.48 3 4.73 3 5V19C3 19.26 3.10 19.51 3.29 19.70C3.48 19.89 3.73 20 4 20C4.26 20 4.51 19.89 4.70 19.70C4.89 19.51 5 19.26 5 19V5C5 4.73 4.89 4.48 4.70 4.29C4.51 4.10 4.26 4 4 4ZM18.95 4.23L6 12.00L18.95 19.77C19.15 19.89 19.39 19.96 19.63 19.96C19.87 19.97 20.10 19.91 20.31 19.79C20.52 19.67 20.69 19.50 20.81 19.29C20.93 19.09 21.00 18.85 21 18.61V5.38C20.99 5.14 20.93 4.91 20.81 4.70C20.69 4.50 20.52 4.33 20.31 4.21C20.10 4.09 19.87 4.03 19.63 4.03C19.39 4.04 19.15 4.10 18.95 4.23Z', 'Précédente'],
          ['next', 'M20 20C20.26 20 20.51 19.89 20.70 19.70C20.89 19.51 21 19.26 21 19V5C21 4.73 20.89 4.48 20.70 4.29C20.51 4.10 20.26 4 20 4C19.73 4 19.48 4.10 19.29 4.29C19.10 4.48 19 4.73 19 5V19C19 19.26 19.10 19.51 19.29 19.70C19.48 19.89 19.73 20 20 20ZM5.04 19.77L18 12L5.04 4.22C4.84 4.10 4.60 4.03 4.36 4.03C4.12 4.03 3.89 4.09 3.68 4.21C3.47 4.32 3.30 4.49 3.18 4.70C3.06 4.91 2.99 5.14 3 5.38V18.61C2.99 18.85 3.06 19.08 3.18 19.29C3.30 19.50 3.47 19.67 3.68 19.79C3.89 19.90 4.12 19.96 4.36 19.96C4.60 19.96 4.84 19.89 5.04 19.77Z', 'Suivante']
        ]) {
          const target = navigation[direction];
          const button = document.createElement('button'); button.type = 'button';
          button.className = 'fk-video-' + direction + ' shaka-no-propagation';
          button.setAttribute('aria-label', target ? label : 'Revoir');
          button.dataset.tooltipLabel = target ? label.toLocaleUpperCase('fr-FR') : 'Revoir';
          if (target) {
            button.dataset.previewHref = target.href;
            button.dataset.previewLabel = target.label;
          }
          new shaka.ui.Icon(button).use({ path, viewBox: '0 0 24 24' });
          const activate = () => {
            if (disposed) return;
            if (target) {
              video.pause();
              navigation[direction === 'previous' ? 'movePrevious' : 'moveNext']();
            } else {
              video.currentTime = player.seekRange().start || 0;
              video.play().catch(() => {});
            }
          };
          on(button, 'click', activate);
          on(root, 'keydown', event => {
            if (!event.shiftKey || event.altKey || event.ctrlKey || event.metaKey || event.isComposing) return;
            if (event.key.toLowerCase() !== (direction === 'previous' ? 'p' : 'n')) return;
            if (event.target.closest('input,textarea,select,[contenteditable]:not([contenteditable="false"])')) return;
            event.preventDefault(); event.stopImmediatePropagation();
            if (!event.repeat) activate();
          }, true);
          group.append(button);
        }
      }
      function configureShortcutTooltips() {
        const observers = [];
        const positions = [];
        const shortcuts = [
          ['.shaka-play-button', 'K'], ['.shaka-mute-button', 'M'],
          ['.fk-video-previous', navigation?.previous ? 'Shift+P' : ''],
          ['.fk-video-next', navigation?.next ? 'Shift+N' : ''],
          ['.fk-autoplay-next', ''],
          ['.fk-player-actions .shaka-caption-button', 'C'],
          ['.fk-player-actions .shaka-fullscreen-button', 'F'],
          ['.fk-player-actions .shaka-pip-button', 'P'],
          ['.fk-player-actions .shaka-overflow-menu-button', '']
        ];
        for (const [selector, shortcut] of shortcuts) {
          const button = root.querySelector(selector);
          if (!button) continue;
          const tooltip = document.createElement('span'); tooltip.className = 'fk-shortcut-tooltip'; tooltip.setAttribute('aria-hidden', 'true');
          const label = document.createElement('span'); label.textContent = button.dataset.tooltipLabel || button.getAttribute('aria-label'); tooltip.append(label);
          if (shortcut) {
            const key = document.createElement('kbd'); key.textContent = shortcut; tooltip.append(key);
            button.setAttribute('aria-keyshortcuts', shortcut.toLowerCase());
          }
          button.classList.add('fk-has-tooltip'); button.append(tooltip);
          const position = () => {
            const frame = root.getBoundingClientRect(), box = button.getBoundingClientRect();
            if (!frame.width || !box.width) return;
            tooltip.style.maxWidth = Math.max(0, frame.width - 24) + 'px';
            const centered = box.left + (box.width - tooltip.offsetWidth) / 2;
            const left = Math.max(frame.left + 12, Math.min(frame.right - 12 - tooltip.offsetWidth, centered));
            const pixelRatio = window.devicePixelRatio || 1;
            const alignPixel = value => Math.round(value * pixelRatio) / pixelRatio;
            const track = button.dataset.previewHref && root.querySelector('.shaka-seek-bar-container');
            const anchor = track ? track.getBoundingClientRect().top + parseFloat(getComputedStyle(track).borderTopWidth) - 8 : box.top - 16;
            const top = anchor - tooltip.getBoundingClientRect().height;
            tooltip.style.left = (alignPixel(left) - box.left) + 'px';
            tooltip.style.top = (alignPixel(top) - box.top) + 'px';
            tooltip.style.bottom = 'auto';
            tooltip.style.transform = 'none';
          };
          positions.push(position);
          on(button, 'pointerenter', position); on(button, 'focus', position);
          if (button.dataset.previewHref) {
            tooltip.classList.add('fk-navigation-tooltip');
            const heading = document.createElement('span'); heading.className = 'fk-navigation-tooltip-title';
            while (tooltip.firstChild) heading.append(tooltip.firstChild);
            tooltip.append(heading);
            const image = document.createElement('span'); image.className = 'fk-navigation-tooltip-image'; image.hidden = true;
            const thumbnail = document.createElement('canvas'); thumbnail.width = 194; thumbnail.height = 113;
            thumbnail.setAttribute('aria-label', button.dataset.previewLabel); thumbnail.hidden = true;
            const durationLabel = document.createElement('span'); durationLabel.className = 'fk-navigation-tooltip-duration'; durationLabel.hidden = true;
            image.append(thumbnail, durationLabel); tooltip.append(image);
            let preview = null, timeout = null, complete = false, captureTime = null;
            const updateDuration = () => {
              if (!preview || !Number.isFinite(preview.duration) || preview.duration <= 0) return;
              const seconds = Math.floor(preview.duration), hours = Math.floor(seconds / 3600);
              durationLabel.textContent = (hours ? hours + ':' : '') + String(Math.floor(seconds / 60) % 60).padStart(hours ? 2 : 1, '0') + ':' + String(seconds % 60).padStart(2, '0');
              durationLabel.hidden = false;
            };
            const release = () => {
              clearTimeout(timeout);
              if (preview) { preview.removeAttribute('src'); preview.load(); preview = null; }
            };
            const load = () => {
              if (complete || preview || disposed) return;
              preview = document.createElement('video'); preview.muted = true; preview.preload = 'auto'; preview.playsInline = true;
              on(preview, 'loadedmetadata', () => {
                updateDuration();
                // Opening frames are often black. Sample later, and capture
                // only once the decoder has actually finished seeking there.
                const duration = preview.duration;
                captureTime = Number.isFinite(duration) && duration > 0
                  ? Math.min(30, Math.max(1, duration * .1), Math.max(0, duration - .05)) : 1;
                preview.currentTime = captureTime;
              });
              const capture = () => {
                if (!preview || complete || captureTime === null || preview.seeking || preview.readyState < 2
                    || Math.abs(preview.currentTime - captureTime) > .25) return;
                try {
                  const ctx = thumbnail.getContext('2d');
                  const ratio = preview.videoWidth / preview.videoHeight;
                  const width = Math.min(thumbnail.width, thumbnail.height * ratio), height = width / ratio;
                  ctx.fillStyle = '#000'; ctx.fillRect(0, 0, thumbnail.width, thumbnail.height);
                  ctx.drawImage(preview, (thumbnail.width - width) / 2, (thumbnail.height - height) / 2, width, height);
                  updateDuration(); thumbnail.hidden = false; image.hidden = false; complete = true; position();
                } catch (_) { complete = true; }
                release();
              };
              on(preview, 'seeked', capture);
              on(preview, 'loadeddata', capture);
              on(preview, 'durationchange', updateDuration);
              on(preview, 'error', () => { complete = true; release(); });
              timeout = setTimeout(() => { complete = true; release(); }, 5000);
              preview.src = new URL(button.dataset.previewHref, location.href).href;
            };
            on(button, 'pointerenter', load); on(button, 'focus', load);
            abort.signal.addEventListener('abort', release, { once: true });
          }
          const observer = new MutationObserver(() => { label.textContent = button.dataset.tooltipLabel || button.getAttribute('aria-label'); position(); });
          observer.observe(button, { attributes: true, attributeFilter: ['aria-label'] }); observers.push(observer);
        }
        const resizeObserver = new ResizeObserver(() => positions.forEach(position => position()));
        resizeObserver.observe(root);
        on(document, 'fullscreenchange', () => positions.forEach(position => position()));
        let captionId = null;
        on(player, 'textchanged', () => {
          const active = player.getTextTracks().find(track => track.active);
          if (active) captionId = active.id;
        });
        on(root, 'keydown', event => {
          if (event.key.toLowerCase() !== 'c' || event.altKey || event.ctrlKey || event.metaKey) return;
          if (event.target.closest('textarea,select,[contenteditable="true"],input:not([type="range"])')) return;
          const tracks = player.getTextTracks();
          if (!tracks.length) return;
          event.preventDefault(); event.stopImmediatePropagation();
          if (event.repeat) return;
          if (tracks.some(track => track.active)) player.selectTextTrack();
          else player.selectTextTrack(tracks.find(track => track.id === captionId) || tracks[0]);
          ui.getControls().showUI();
        }, true);
        abort.signal.addEventListener('abort', () => { observers.forEach(observer => observer.disconnect()); resizeObserver.disconnect(); }, { once: true });
      }
      function configureProgress() {
        const range = root.querySelector('.shaka-seek-bar');
        const track = root.querySelector('.shaka-seek-bar-container');
        if (!range || !track) return;
        range.step = '0.001';
        const bufferedTrack = document.createElement('span');
        bufferedTrack.className = 'fk-buffered-track'; bufferedTrack.setAttribute('aria-hidden', 'true');
        track.prepend(bufferedTrack);
        let dragging = false, hoverPercent = null, playedPercent = 0, controlsHold = null;
        const holdControls = () => {
          if (disposed || controlsHold !== null || document.hidden) return;
          ui.getControls().showUI();
          controlsHold = setInterval(() => { if (!disposed) ui.getControls().showUI(); }, 1000);
        };
        const releaseControls = () => { clearInterval(controlsHold); controlsHold = null; };
        on(track, 'pointerenter', event => { if (event.pointerType !== 'touch') holdControls(); });
        const preview = document.createElement('div'); preview.className = 'fk-seek-preview'; preview.hidden = true;
        const canvas = document.createElement('canvas'); canvas.width = 192; canvas.height = 108; canvas.hidden = true;
        const previewImage = document.createElement('span'); previewImage.className = 'fk-seek-preview-image'; previewImage.hidden = true;
        previewImage.append(canvas);
        const previewTime = document.createElement('span'); previewTime.className = 'fk-seek-preview-time';
        const previewChapter = document.createElement('span'); previewChapter.className = 'fk-preview-chapter-title';
        preview.append(previewImage, previewTime); root.append(preview); root.classList.add('fk-has-preview');
        const context = canvas.getContext('2d', { alpha: false, colorSpace: 'srgb' });
        if (context) { context.imageSmoothingEnabled = true; context.imageSmoothingQuality = 'high'; }
        const thumbnailLimit = Math.min(64, Math.floor(16 * 1024 * 1024 / (canvas.width * canvas.height * 4)));
        const chapterTrack = document.createElement('span'); chapterTrack.className = 'fk-chapter-track'; chapterTrack.setAttribute('aria-hidden', 'true'); track.prepend(chapterTrack);
        const chapterCurrent = document.createElement('div'); chapterCurrent.className = 'fk-chapter-current'; chapterCurrent.hidden = true;
        on(chapterCurrent, 'click', event => event.stopPropagation());
        on(chapterCurrent, 'dblclick', event => event.stopPropagation());
        const chapterName = document.createElement('span');
        const chapterChevron = document.createElementNS('http://www.w3.org/2000/svg', 'svg'); chapterChevron.setAttribute('viewBox', '0 0 24 24'); chapterChevron.setAttribute('aria-hidden', 'true');
        const chevronPath = document.createElementNS(chapterChevron.namespaceURI, 'path'); chevronPath.setAttribute('d', 'M9.71 18.71l-1.42-1.42 5.3-5.29-5.3-5.29 1.42-1.42 6.7 6.71z'); chapterChevron.append(chevronPath);
        chapterCurrent.append(chapterName, chapterChevron); root.querySelector('.shaka-current-time')?.after(chapterCurrent);
        let chapters = [], chapterSegments = [];
        const chapterAt = time => {
          for (let index = chapters.length - 1; index >= 0; index--) if (time >= chapters[index].start) return chapters[index];
          return null;
        };
        const syncChapterLabels = () => {
          const current = chapterAt(dragging ? Number(range.value) : video.currentTime);
          chapterCurrent.hidden = !current;
          chapterName.textContent = current?.title || '';
          chapterCurrent.setAttribute('aria-label', current ? 'Chapitre : ' + current.title : '');
          const hovered = wantedTime === null ? null : chapterAt(wantedTime);
          previewChapter.textContent = hovered?.title || '';
          if (hovered && previewChapter.parentElement !== previewTime) previewTime.append(previewChapter);
          else if (!hovered) previewChapter.remove();
        };
        const buildChapters = () => {
          chapters = chapterData.filter(chapter => Number.isFinite(chapter.start) && chapter.start >= 0
            && Number.isFinite(video.duration) && chapter.start < video.duration)
            .map(chapter => ({ start: chapter.start, title: String(chapter.title || 'Chapitre') }))
            .sort((a, b) => a.start - b.start).filter((chapter, index, list) => !index || chapter.start !== list[index - 1].start);
          if (chapters.length && chapters[0].start > 0) chapters.unshift({ start: 0, title: 'Début' });
          chapterTrack.replaceChildren();
          chapterSegments = chapters.map(chapter => {
            const element = document.createElement('span'); element.className = 'fk-chapter-segment';
            const layers = ['played', 'buffered', 'hover'].map(name => {
              const layer = document.createElement('span'); layer.className = 'fk-chapter-' + name; element.append(layer); return layer;
            });
            chapterTrack.append(element); return { element, layers };
          });
          root.classList.toggle('fk-has-chapters', chapters.length > 0);
          update(); syncChapterLabels();
        };
        const paintChapters = bufferedEnd => {
          if (!chapters.length) return;
          const bounds = player.seekRange(), span = bounds.end - bounds.start;
          if (span <= 0) return;
          const width = track.clientWidth;
          const globalPlayed = width * playedPercent / 100;
          const hoveredChapter = wantedTime === null ? null : chapterAt(wantedTime);
          chapterSegments.forEach(({ element, layers }, index) => {
            const start = Math.max(0, (chapters[index].start - bounds.start) / span * 100);
            const end = Math.min(100, ((chapters[index + 1]?.start ?? bounds.end) - bounds.start) / span * 100);
            const length = end - start, inset = index ? 2 : 0, trailing = index < chapters.length - 1 ? 2 : 0;
            element.style.left = `calc(${start}% + ${inset}px)`;
            element.style.width = `max(0px, calc(${Math.max(0, length)}% - ${inset + trailing}px))`;
            element.dataset.hovered = String(hoveredChapter === chapters[index]);
            const paint = (layer, from, to) => {
              const left = Math.max(start, from), right = Math.min(end, to);
              layer.style.left = (length > 0 ? (left - start) / length * 100 : 0) + '%';
              layer.style.width = (length > 0 ? Math.max(0, right - left) / length * 100 : 0) + '%';
            };
            paint(layers[0], 0, playedPercent);
            layers[0].style.backgroundSize = globalPlayed + 'px 100%';
            layers[0].style.backgroundPosition = -(width * start / 100 + inset) + 'px 0';
            paint(layers[1], playedPercent, bufferedEnd);
            paint(layers[2], hoverPercent === null ? playedPercent : Math.min(playedPercent, hoverPercent), hoverPercent === null ? playedPercent : Math.max(playedPercent, hoverPercent));
          });
        };
        const thumbnails = new Map();
        let storyboardController = null;
        let previewVideo = null, seekTimeout = null;
        let wantedTime = null, seekingKey = null, seekingTime = null, busy = false, previewFailed = !context, previewX = 0;
        const positionPreview = () => {
          const box = root.getBoundingClientRect(), halfWidth = preview.offsetWidth / 2;
          preview.style.left = Math.max(halfWidth + 8, Math.min(box.width - halfWidth - 8, previewX - box.left)) + 'px';
          const barTop = track.getBoundingClientRect().top + parseFloat(getComputedStyle(track).borderTopWidth);
          preview.style.bottom = (box.bottom - barTop + 36) + 'px';
        };
        const thumbnailKey = time => Math.round(time / 2) * 2;
        const paintStoryboard = () => {
          if (wantedTime === null || !storyboardController?.draw(wantedTime, canvas)) return false;
          canvas.hidden = false; previewImage.hidden = false; positionPreview(); return true;
        };
        const paintThumbnail = key => {
          if (paintStoryboard()) return;
          if (wantedTime === null || key !== thumbnailKey(wantedTime) || !thumbnails.has(key)) return;
          const frame = thumbnails.get(key);
          thumbnails.delete(key); thumbnails.set(key, frame);
          context.drawImage(frame, 0, 0); canvas.hidden = false; previewImage.hidden = false; positionPreview();
        };
        const disablePreviewVideo = () => {
          previewFailed = true; busy = false; clearTimeout(seekTimeout);
          if (previewVideo) { previewVideo.removeAttribute('src'); previewVideo.load(); }
          canvas.hidden = true; previewImage.hidden = true;
        };
        const captureThumbnail = () => {
          if (disposed || !busy || previewVideo.seeking || previewVideo.readyState < 2
              || Math.abs(previewVideo.currentTime - seekingTime) > .25) return;
          clearTimeout(seekTimeout);
          try {
            // Recycle fallback surfaces. Permanent sheets contain tiles at
            // exactly the displayed resolution; no oversized frames are kept.
            const oldest = thumbnails.keys().next().value;
            const frame = thumbnails.size >= thumbnailLimit ? thumbnails.get(oldest) : document.createElement('canvas');
            if (thumbnails.size >= thumbnailLimit) thumbnails.delete(oldest);
            if (frame.width !== canvas.width || frame.height !== canvas.height) { frame.width = canvas.width; frame.height = canvas.height; }
            const ctx = frame.getContext('2d', { alpha: false, colorSpace: 'srgb' });
            if (!ctx) { disablePreviewVideo(); return; }
            ctx.imageSmoothingEnabled = true; ctx.imageSmoothingQuality = 'high';
            const ratio = previewVideo.videoWidth / previewVideo.videoHeight;
            const width = Math.min(frame.width, frame.height * ratio), height = width / ratio;
            ctx.fillStyle = '#000'; ctx.fillRect(0, 0, frame.width, frame.height);
            ctx.drawImage(previewVideo, (frame.width - width) / 2, (frame.height - height) / 2, width, height);
            thumbnails.set(seekingKey, frame);
            paintThumbnail(seekingKey); busy = false;
            if (wantedTime !== null && !thumbnails.has(thumbnailKey(wantedTime))) scheduleThumbnail();
          } catch (_) { disablePreviewVideo(); }
        };
        const preparePreviewVideo = () => {
          if (disposed || previewFailed) return;
          if (!previewVideo) {
            previewVideo = document.createElement('video'); previewVideo.muted = true; previewVideo.playsInline = true;
            previewVideo.preload = 'metadata';
            on(previewVideo, 'loadedmetadata', loadThumbnail);
            on(previewVideo, 'seeked', captureThumbnail);
            on(previewVideo, 'loadeddata', () => { if (!previewVideo.seeking) captureThumbnail(); });
            on(previewVideo, 'error', disablePreviewVideo);
            previewVideo.src = new URL(href, location.href).href;
          }
        };
        const loadThumbnail = () => {
          if (disposed || wantedTime === null) return;
          if (paintStoryboard()) return;
          storyboardController?.request(wantedTime);
          if (storyboardController?.covers(wantedTime) || previewFailed || busy) return;
          const key = thumbnailKey(wantedTime);
          if (thumbnails.has(key)) { paintThumbnail(key); return; }
          preparePreviewVideo();
          const duration = Number.isFinite(previewVideo.duration) ? previewVideo.duration : video.duration;
          if (previewVideo.readyState < 1 || !Number.isFinite(duration)) return;
          busy = true; seekingKey = key;
          seekTimeout = setTimeout(disablePreviewVideo, 5000);
          seekingTime = Math.min(key, Math.max(0, duration - .05));
          previewVideo.currentTime = seekingTime;
          if (!previewVideo.seeking && previewVideo.readyState >= 2) captureThumbnail();
        };
        const scheduleThumbnail = () => {
          if (!previewFailed && wantedTime !== null) loadThumbnail();
        };
        // Fetch only metadata before the pointer reaches the timeline. No
        // speculative seeks compete with playback for bandwidth or decoding.
        on(root, 'pointerenter', preparePreviewVideo);
        on(root, 'pointermove', preparePreviewVideo);
        on(root, 'focusin', preparePreviewVideo);
        abort.signal.addEventListener('abort', () => {
          releaseControls();
          clearTimeout(seekTimeout); wantedTime = null;
          if (previewVideo) { previewVideo.removeAttribute('src'); previewVideo.load(); }
          thumbnails.clear();
          storyboardController?.destroy();
        }, { once: true });
        root.dataset.storyboardState = 'loading';
        import(new URL('storyboards.js?v=1.0.1', assetRoot).href).then(({ createStoryboards }) => {
          if (disposed) return;
          storyboardController = createStoryboards({ endpoint: storyboardEndpoint, href, signal: abort.signal, onUpdate: () => {
            if (disposed) return;
            root.dataset.storyboardState = storyboardController.status;
            storyboardController.request(wantedTime ?? video.currentTime);
            if (wantedTime !== null && !paintStoryboard()) scheduleThumbnail();
          } });
        }).catch(() => { if (!disposed) root.dataset.storyboardState = 'unavailable'; });
        on(video, 'timeupdate', () => storyboardController?.request(wantedTime ?? video.currentTime));
        const updateHover = () => {
          const bounds = player.seekRange();
          let bufferedUntil = video.currentTime;
          for (let index = 0; index < video.buffered.length; index++) {
            if (video.buffered.start(index) <= video.currentTime && video.buffered.end(index) >= video.currentTime) {
              bufferedUntil = video.buffered.end(index); break;
            }
          }
          const bufferedPercent = bounds.end > bounds.start ? (bufferedUntil - bounds.start) / (bounds.end - bounds.start) * 100 : 0;
          const start = Math.max(playedPercent, Math.min(100, bufferedPercent));
          track.style.setProperty('--fk-buffered-width', Math.max(0, start - playedPercent) + '%');
          track.style.setProperty('--fk-buffered-end-radius', start >= 100 ? '3px' : '0px');
          const hoverStart = hoverPercent === null ? playedPercent : Math.min(playedPercent, hoverPercent);
          const hoverEnd = hoverPercent === null ? playedPercent : Math.max(playedPercent, hoverPercent);
          track.style.setProperty('--fk-hover-start', hoverStart + '%');
          track.style.setProperty('--fk-hover-width', hoverPercent === null ? '0%' : (hoverEnd - hoverStart) + '%');
          track.style.setProperty('--fk-hover-color', hoverPercent !== null && hoverPercent <= start ? 'rgba(0,0,0,.125)' : 'rgba(255,255,255,.5)');
          track.style.setProperty('--fk-hover-start-radius', hoverPercent !== null && hoverStart <= 0 ? '3px' : '0px');
          track.style.setProperty('--fk-hover-end-radius', hoverPercent !== null && hoverEnd >= 100 ? '3px' : '0px');
          paintChapters(start); syncChapterLabels();
        };
        // Keep Shaka's native dragging, tooltip and keyboard handling, but
        // defer its debounced media seeks until the pointer is released.
        const mediaTime = Object.getOwnPropertyDescriptor(HTMLMediaElement.prototype, 'currentTime');
        const ownTime = Object.getOwnPropertyDescriptor(video, 'currentTime');
        if (mediaTime?.get && mediaTime?.set && (!ownTime || ownTime.configurable)) {
          Object.defineProperty(video, 'currentTime', {
            configurable: true,
            get: () => mediaTime.get.call(video),
            set: value => { if (!dragging) mediaTime.set.call(video, value); }
          });
          abort.signal.addEventListener('abort', () => {
            dragging = false;
            if (ownTime) Object.defineProperty(video, 'currentTime', ownTime);
            else delete video.currentTime;
          }, { once: true });
        }
        const update = (fromInput = false) => {
          if (disposed) return;
          const bounds = player.seekRange();
          const start = fromInput ? Number(range.min) : bounds.start;
          const end = fromInput ? Number(range.max) : bounds.end;
          const value = fromInput ? Number(range.value) : video.currentTime;
          const percent = end > start ? Math.max(0, Math.min(100, (value - start) / (end - start) * 100)) : 0;
          track.style.setProperty('--fk-played', percent + '%');
          playedPercent = percent; updateHover();
        };
        on(track, 'pointermove', event => {
          if (event.pointerType === 'touch') return;
          holdControls();
          const rect = range.getBoundingClientRect();
          hoverPercent = rect.width > 12 ? Math.max(0, Math.min(100, (event.clientX - rect.left - 6) / (rect.width - 12) * 100)) : 0;
          const bounds = player.seekRange();
          wantedTime = Math.max(bounds.start, bounds.start + (bounds.end - bounds.start) * hoverPercent / 100);
          const seconds = Math.floor(wantedTime), hours = Math.floor(seconds / 3600);
          previewTime.textContent = (hours ? hours + ':' : '') + String(Math.floor(seconds / 60) % 60).padStart(hours ? 2 : 1, '0') + ':' + String(seconds % 60).padStart(2, '0');
          syncChapterLabels();
          preview.hidden = false;
          previewX = event.clientX; positionPreview();
          if (paintStoryboard()) { /* Already decoded sheet: draw synchronously. */ }
          else if (thumbnails.has(thumbnailKey(wantedTime))) paintThumbnail(thumbnailKey(wantedTime));
          else { canvas.hidden = true; previewImage.hidden = true; scheduleThumbnail(); }
          updateHover();
        });
        const clearHover = () => { releaseControls(); hoverPercent = null; wantedTime = null; preview.hidden = true; updateHover(); };
        on(track, 'pointerleave', clearHover);
        on(window, 'blur', clearHover);
        on(document, 'visibilitychange', () => { if (document.hidden) clearHover(); });
        on(video, 'progress', updateHover);
        on(range, 'pointerdown', () => { dragging = true; root.dataset.seekKeyboard = 'false'; });
        on(window, 'pointerup', () => { if (dragging) { dragging = false; update(true); } });
        on(window, 'pointercancel', () => { if (dragging) range.blur(); dragging = false; update(); });
        on(range, 'keydown', () => { root.dataset.seekKeyboard = 'true'; });
        on(range, 'blur', () => {
          root.dataset.seekKeyboard = 'false';
          if (dragging) { dragging = false; video.currentTime = Number(range.value); update(true); }
        });
        on(range, 'input', () => update(true));
        on(range, 'mousedown', () => { if (dragging) update(true); });
        on(document, 'mousemove', () => { if (dragging) update(true); });
        on(range, 'touchmove', () => { if (dragging) update(true); });
        for (const event of ['timeupdate', 'seeking', 'seeked', 'durationchange']) {
          on(video, event, () => { if (!dragging) update(); });
        }
        on(root, 'filekeep-chapters', buildChapters);
        on(video, 'durationchange', buildChapters);
        const chapterResize = new ResizeObserver(() => updateHover()); chapterResize.observe(track);
        abort.signal.addEventListener('abort', () => chapterResize.disconnect(), { once: true });
        buildChapters();
      }
      const status = text => { subtitleStatus.textContent = text; };
      const state = {
        isMkv: /\.mkv$/i.test(new URL(href, location.href).pathname), hasAssTracks: false, delayButton: null, offset: 0,
        audioTracks: [], selectedAudioId: null, audioController: null,
        audioStatus: text => { if (!disposed) audioStatus.textContent = text; },
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
          chapterData = Array.isArray(data.chapters) ? data.chapters : [];
          root.dispatchEvent(new Event('filekeep-chapters'));
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
      // Shaka follows range drags through document mousemove listeners. Do not
      // stop these events here. Capture the pointer so a drag outside the player
      // still targets its range instead of h5ai's dismiss/previous/next areas.
      on(root, 'pointerdown', event => {
        if (event.button !== 0 || !['mouse', 'pen'].includes(event.pointerType)) return;
        const range = event.target.closest('input[type="range"]');
        if (!range || !root.contains(range)) return;
        try { range.setPointerCapture(event.pointerId); } catch (_) {}
      }, true);
      on(window, 'blur', () => {
        const active = document.activeElement;
        if (root.contains(active) && active.matches('input[type="range"]')) active.blur();
      });
      function fitPlayer() {
        const parent = root.parentElement;
        if (!parent || document.fullscreenElement === root) return;
        const width = parent.clientWidth, height = parent.clientHeight;
        if (!width || !height) return;
        const ratio = video.videoWidth && video.videoHeight ? video.videoWidth / video.videoHeight : 16 / 9;
        const fittedWidth = Math.min(width, height * ratio), fittedHeight = fittedWidth / ratio;
        Object.assign(root.style, {
          width: fittedWidth + 'px', height: fittedHeight + 'px',
          left: (width - fittedWidth) / 2 + 'px', top: (height - fittedHeight) / 2 + 'px'
        });
      }
      on(video, 'loadedmetadata', () => { fitPlayer(); window.dispatchEvent(new Event('resize')); });
      on(video, 'resize', fitPlayer);
      on(window, 'resize', fitPlayer);
      on(document, 'fullscreenchange', fitPlayer);
      const layoutObserver = new ResizeObserver(fitPlayer);
      let layoutParent = null;
      const originalUnload = video.unload;
      video.unload = () => {
        if (disposed) return cleanupPromise;
        rememberAudio();
        disposed = true; selection++; abort.abort(); feedbackAnimation?.cancel(); feedbackDiskAnimation?.cancel(); layoutObserver.disconnect(); destroyRenderer(); instances.delete(root);
        video.pause();
        cleanupPromise = (async () => {
          if (softwareAudio) await softwareAudio.destroy();
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
        if (root.isConnected) {
          mounted = true;
          if (layoutParent !== root.parentElement) {
            layoutObserver.disconnect(); layoutParent = root.parentElement;
            layoutObserver.observe(layoutParent);
          }
          fitPlayer();
        }
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
          feedbackIcon = new shaka.ui.Icon(feedback);
          player = new shaka.Player(); useNativeVodDuration(player, video, abort.signal); await player.attach(video, false);
          if (disposed) { await player.destroy(); return; }
          ui = new shaka.ui.Overlay(player, root, video);
          ui.getControls().getLocalization().changeLocale(['fr']);
          ui.configure({
            topControlPanelElements: ['content_title'],
            controlPanelElements: ['play_pause', 'mute_volume', 'time_and_duration', 'spacer', 'captions', 'overflow_menu', 'picture_in_picture', 'fullscreen'],
            overflowMenuButtons: ['captions', 'filekeep_subtitle_delay', 'playback_rate', 'language', 'filekeep_audio', 'picture_in_picture'],
            textTrackLabelFormat: shaka.ui.Overlay.TrackLabelFormat.LABEL,
            addSeekBar: true, enableTooltips: true,
            seekBarColors: { base: 'rgba(0,0,0,.35)', buffered: 'rgba(0,0,0,.35)', played: 'rgba(0,0,0,.35)' }
          });
          const panel = root.querySelector('.shaka-bottom-controls .shaka-controls-button-panel');
          const actions = document.createElement('div');
          actions.className = 'fk-player-actions';
          actions.setAttribute('role', 'group'); actions.setAttribute('aria-label', 'Options de lecture');
          const actionButtons = panel.querySelectorAll(':scope > .shaka-caption-button, :scope > .shaka-overflow-menu-button, :scope > .shaka-pip-button, :scope > .shaka-fullscreen-button');
          if (actionButtons.length) {
            panel.insertBefore(actions, actionButtons[0]);
            actions.append(...actionButtons);
          }
          const captionsButton = actions.querySelector('.shaka-caption-button');
          if (captionsButton) {
            const updateCaptionsButton = () => {
              captionsButton.setAttribute('aria-haspopup', player.getTextTracks().length === 1 ? 'false' : 'menu');
            };
            on(player, 'trackschanged', updateCaptionsButton);
            updateCaptionsButton();
            on(captionsButton, 'click', event => {
              const tracks = player.getTextTracks();
              if (tracks.length !== 1 || !ui.getControls().isOpaque()) return;
              event.preventDefault(); event.stopImmediatePropagation();
              if (tracks[0].active) player.selectTextTrack();
              else player.selectTextTrack(tracks[0]);
            }, true);
          }
          configureNavigation(panel); configureAutoplay(actions); configureShortcutTooltips();
          root.dataset.shakaVersion = shaka.Player.version;
          player.addEventListener('textchanged', syncSubtitles);
          player.addEventListener('error', () => {
            if (!disposed) playbackStatus.textContent = 'Lecture impossible : ce format audio ou vidéo peut être incompatible avec le navigateur.';
          });
          const ext = new URL(href, location.href).pathname.split('.').pop().toLowerCase();
          const mime = { mp4: 'video/mp4', m4v: 'video/mp4', webm: 'video/webm', mkv: 'video/x-matroska', mov: 'video/quicktime' }[ext];
          const resumePosition = await playbackProgress?.ready;
          if (disposed) return;
          await player.load(new URL(href, location.href).href, resumePosition ?? null, mime);
          if (!disposed) playbackProgress?.start();
          if (!disposed) { animateButtons(); configureProgress(); }
          if (!disposed) discoverSubtitles();
          if (!disposed && state.isMkv) {
            root.dataset.audioEngine = 'probing';
            import(new URL('flac-audio.js?v=2.0.1', assetRoot).href).then(async ({ prepareAudio }) => {
              if (disposed) return;
              const controller = await prepareAudio(video, href, {
                signal: abort.signal,
                onStatus: text => { if (!disposed) audioStatus.textContent = text; },
                onState: mode => {
                  if (disposed) return;
                  root.dataset.audioEngine = mode;
                  root.classList.toggle('fk-software-audio', mode !== 'error');
                  if (mode === 'error') { state.audioTracks = []; root.dispatchEvent(new Event('filekeep-audio-updated')); }
                },
                onTracks: (tracks, selectedId) => {
                  if (disposed) return;
                  state.audioTracks = tracks; state.selectedAudioId = selectedId;
                  root.dispatchEvent(new Event('filekeep-audio-updated'));
                }
              });
              if (disposed) { await controller?.destroy(); return; }
              softwareAudio = controller; state.audioController = controller;
              if (!controller) root.dataset.audioEngine = 'native';
            }).catch(error => {
              if (!disposed) {
                root.dataset.audioEngine = 'error';
                audioStatus.textContent = 'Son indisponible : ' + error.message;
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
            if (!ui) {
              on(video, 'loadedmetadata', async () => {
                const position = await playbackProgress?.ready;
                if (disposed) return;
                if (position > 0 && position < video.duration) video.currentTime = position;
                playbackProgress?.start();
              });
              video.controls = true; video.src = href; root.dataset.player = 'native-fallback';
            }
          }
        }
      }
      init(); return root;
    }
  };
})();
