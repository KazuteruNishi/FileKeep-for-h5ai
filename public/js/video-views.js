(() => {
  'use strict';
  const endpoint = document.currentScript.dataset.endpoint;
  const pending = new Map(), labels = new Map();
  const counts = new Map();
  let infoView = null;
  let timer;
  function showInfo(count) {
    if (!infoView) return;
    infoView.label.textContent = `${Number(count).toLocaleString('fr-FR')} ${Number(count) === 1 ? 'vue' : 'vues'}`;
    infoView.label.hidden = false;
  }
  function path(href) {
    try {
      const url = new URL(href, location.href);
      return url.origin === location.origin && /\.(mp4|m4v|mkv|webm|mov|avi|flv|mpg|mpeg)$/i.test(decodeURIComponent(url.pathname)) ? url.pathname : null;
    } catch (_) { return null; }
  }
  async function request(body) {
    body.set('_csrf', document.querySelector('meta[name="h5ai-csrf"]')?.content || '');
    const response = await fetch(endpoint, { method: 'POST', credentials: 'same-origin', body, keepalive: true });
    if (!response.ok) throw new Error('Compteur indisponible');
    const data = await response.json();
    for (const [file, count] of Object.entries(data.views || {})) {
      counts.delete(file); counts.set(file, Number(count));
      while (counts.size > 500) counts.delete(counts.keys().next().value);
      if (infoView?.file === file) showInfo(count);
      for (const label of labels.get(file) || []) {
        label.textContent = Number(count).toLocaleString('fr-FR');
        const item = label.closest('.item')?._item;
        if (item) item.views = Number(count);
      }
    }
    document.dispatchEvent(new Event('filekeep-views-updated'));
  }
  async function flush() {
    const files = Array.from(pending.keys()).slice(0, 100);
    files.forEach(file => pending.delete(file));
    if (pending.size) timer = setTimeout(flush, 0);
    const body = new URLSearchParams({ action: 'list' });
    files.forEach(file => body.append('files[]', file));
    try { await request(body); } catch (_) { /* Playback remains independent of counters. */ }
  }
  window.FileKeepViews = {
    info(target, href, folder = false) {
      const panel = target.closest('#info');
      if (!panel) return;
      const date = panel.querySelector('.time');
      if (!date) return;
      let label = panel.querySelector('.fk-info-views');
      if (!label) {
        label = document.createElement('div'); label.className = 'fk-info-views';
        date.before(label);
      }
      infoView = null; label.hidden = true; label.textContent = '';
      const file = folder ? null : path(href);
      if (!file) return;
      infoView = { file, label };
      label.title = 'Vues globales · une minute de lecture minimum';
      if (counts.has(file)) { showInfo(counts.get(file)); return; }
      pending.set(file, true); clearTimeout(timer); timer = setTimeout(flush, 0);
    },
    observe(images, href, folder = false) {
      const file = folder ? null : path(href);
      const row = Array.from(images)[0]?.closest('.item');
      const name = row?.querySelector('.label');
      if (!name || row.querySelector('.fk-view-count')) return;
      const label = document.createElement('span'); label.className = 'views fk-view-count';
      label.textContent = '–';
      label.title = file ? 'Vues globales · une minute de lecture minimum' : 'Non applicable';
      name.parentElement.append(label);
      if (!file) return;
      const current = (labels.get(file) || []).filter(node => node.isConnected);
      current.push(label); labels.set(file, current);
      pending.set(file, true); clearTimeout(timer); timer = setTimeout(flush, 0);
    },
    track(video, href, signal) {
      const file = path(href); if (!file) return;
      const token = Array.from(crypto.getRandomValues(new Uint8Array(16)), n => n.toString(16).padStart(2, '0')).join('');
      let watched = 0, previousTime = video.currentTime, previousClock = performance.now(), submitted = false;
      const reset = () => { previousTime = video.currentTime; previousClock = performance.now(); };
      for (const event of ['playing', 'pause', 'seeking', 'seeked', 'ratechange']) video.addEventListener(event, reset, { signal });
      video.addEventListener('timeupdate', () => {
        const now = performance.now(), elapsed = (now - previousClock) / 1000, advance = video.currentTime - previousTime;
        if (!video.paused && !video.seeking && elapsed > 0 && elapsed < 2 && advance > 0 && advance <= elapsed * video.playbackRate + .5) {
          watched += Math.min(elapsed, advance / Math.max(.1, video.playbackRate));
        }
        previousTime = video.currentTime; previousClock = now;
        if (watched < 60 || submitted) return;
        submitted = true;
        const body = new URLSearchParams({ action: 'view', file, token });
        request(body).catch(() => { submitted = false; });
      }, { signal });
    }
  };
})();
