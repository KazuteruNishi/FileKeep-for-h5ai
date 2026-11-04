(() => {
  'use strict';
  const endpoint = document.currentScript.dataset.endpoint;
  window.FileKeepProgress = {
    track(video, href, signal) {
      const url = new URL(href, location.href);
      let armed = false, lastSave = 0;
      const body = action => new URLSearchParams({ action, file: url.pathname,
        _csrf: document.querySelector('meta[name="h5ai-csrf"]')?.content || '' });
      const ready = (async () => {
        try {
          const response = await fetch(endpoint, { method: 'POST', credentials: 'same-origin',
            body: body('load'), signal: AbortSignal.timeout(3000) });
          if (!response.ok) return null;
          const { progress } = await response.json();
          return progress && !progress.finished && Number.isFinite(progress.position) && progress.position > 0
            ? progress.position : null;
        } catch (_) { return null; }
      })();
      function save() {
        if (!armed || !Number.isFinite(video.duration) || video.duration <= 0 || !Number.isFinite(video.currentTime)) return;
        lastSave = performance.now();
        const data = body('save');
        data.set('position', String(Math.max(0, video.currentTime)));
        data.set('duration', String(video.duration)); data.set('finished', video.ended ? '1' : '0');
        data.set('sentAt', String(Date.now()));
        fetch(endpoint, { method: 'POST', credentials: 'same-origin', body: data, keepalive: true }).catch(() => {});
      }
      for (const event of ['pause', 'seeked', 'ended']) video.addEventListener(event, save, { signal });
      video.addEventListener('timeupdate', () => { if (!video.paused && performance.now() - lastSave >= 10000) save(); }, { signal });
      document.addEventListener('visibilitychange', () => { if (document.hidden) save(); }, { signal });
      window.addEventListener('pagehide', save, { signal });
      signal.addEventListener('abort', () => { save(); armed = false; }, { once: true });
      return { ready, start() { if (!signal.aborted) { armed = true; lastSave = performance.now(); } } };
    }
  };
})();
