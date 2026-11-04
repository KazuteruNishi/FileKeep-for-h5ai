(() => {
  'use strict';
  const endpoint = document.currentScript.dataset.endpoint;
  const mediaPattern = /\.(mp4|m4v|mkv|webm|mov|avi|flv|mpg|mpeg|mp3|ogg|oga|wav|flac|m4a|aac|opus|wma|aiff)$/i;
  function source(href) {
    try { const url = new URL(href, location.href); return url.origin === location.origin ? url.pathname : null; }
    catch (_) { return null; }
  }
  function open(href) {
    const file = source(href);
    if (!file) return null;
    const token = Array.from(crypto.getRandomValues(new Uint8Array(16)), n => n.toString(16).padStart(2, '0')).join('');
    const body = action => new URLSearchParams({ action, file, token,
      _csrf: document.querySelector('meta[name="h5ai-csrf"]')?.content || '' });
    const ready = fetch(endpoint, { method: 'POST', credentials: 'same-origin', body: body('open'), keepalive: true })
      .then(async response => response.ok ? (await response.json()).id : null).catch(() => null);
    return { ready, body };
  }
  window.FileKeepFileJournal = {
    openFile(href, nonMediaOnly = false) {
      const file = source(href);
      if (!file || (nonMediaOnly && mediaPattern.test(decodeURIComponent(file)))) return;
      open(href);
    },
    track(media, href, signal) {
      const session = open(href);
      if (!session) return;
      let watched = 0, previousPosition = media.currentTime || 0, previousClock = performance.now();
      let sequence = 0, closed = false, lastSent = performance.now();
      function reset() { previousPosition = media.currentTime || 0; previousClock = performance.now(); }
      function sample() {
        const now = performance.now(), elapsed = (now - previousClock) / 1000;
        const advance = media.currentTime - previousPosition, rate = Math.max(.1, media.playbackRate || 1);
        if (!media.seeking && elapsed > 0 && elapsed <= 30 && advance > 0 && advance <= elapsed * rate + .75) {
          watched += Math.min(elapsed, advance / rate);
        }
        reset();
      }
      function send(state) {
        if (closed) return;
        sample(); lastSent = performance.now();
        const body = session.body('update');
        body.set('watched', String(watched)); body.set('position', String(Math.max(0, media.currentTime || 0)));
        body.set('duration', String(Number.isFinite(media.duration) ? Math.max(0, media.duration) : 0));
        body.set('state', state); body.set('sequence', String(++sequence));
        session.ready.then(id => {
          if (!id) return;
          body.set('id', id);
          fetch(endpoint, { method: 'POST', credentials: 'same-origin', body, keepalive: true }).catch(() => {});
        });
      }
      const on = (event, handler) => media.addEventListener(event, handler, { signal });
      on('playing', () => { reset(); send('playing'); });
      on('seeking', reset);
      on('seeked', () => { reset(); send(media.paused ? 'paused' : 'playing'); });
      on('ratechange', reset);
      on('pause', () => send(media.ended ? 'ended' : 'paused'));
      on('ended', () => send('ended'));
      on('timeupdate', sample);
      const timer = setInterval(() => {
        if (!media.paused) sample();
        if (!media.paused && performance.now() - lastSent >= 10000) send('playing');
      }, 1000);
      const stop = () => { if (closed) return; send(media.ended ? 'ended' : 'closed'); closed = true; clearInterval(timer); };
      window.addEventListener('pagehide', stop, { signal });
      document.addEventListener('visibilitychange', () => { if (document.hidden) send(media.ended ? 'ended' : media.paused ? 'paused' : 'playing'); }, { signal });
      signal.addEventListener('abort', stop, { once: true });
      return { stop };
    }
  };
  // Native previews prevent navigation and record their own opening. Other file
  // links are recorded once on the actual click, never for image/Range requests.
  document.addEventListener('click', event => {
    const anchor = event.target.closest?.('#items .item > a');
    const item = anchor?.closest('.item')?._item;
    if (!item || item.isFolder()) return;
    queueMicrotask(() => { if (!event.defaultPrevented) window.FileKeepFileJournal.openFile(item.absHref); });
  }, true);
})();
