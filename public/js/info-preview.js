(() => {
  const script = document.currentScript;
  const endpoint = script.dataset.storyboards;
  const states = new WeakMap(), cache = new Map();
  function remember(key, value) {
    cache.delete(key); cache.set(key, value);
    while (cache.size > 24) cache.delete(cache.keys().next().value);
    return value;
  }
  function loadImage(src, signal) {
    return new Promise((resolve, reject) => {
      const image = new Image();
      const cleanup = () => { image.onload = image.onerror = null; signal.removeEventListener('abort', cancel); };
      const cancel = () => { cleanup(); image.src = ''; reject(new DOMException('Aborted', 'AbortError')); };
      image.onload = () => { cleanup(); resolve(image); };
      image.onerror = () => { cleanup(); reject(new Error('Image indisponible')); };
      signal.addEventListener('abort', cancel, { once: true }); image.src = src;
    });
  }
  window.FileKeepInfoPreview = async (target, href, folder) => {
    if (!target) return;
    window.FileKeepViews?.info(target, href, folder);
    states.get(target)?.abort();
    const request = new AbortController(); states.set(target, request);
    const { signal } = request;
    target.onerror = null;
    target.classList.remove('fk-info-preview');
    target.parentElement?.classList.remove('fk-video-preview');
    if (folder || !href) return;
    const source = new URL(href, location.href);
    if (source.origin !== location.origin) return;
    const path = decodeURIComponent(source.pathname);
    const video = /\.(mp4|m4v|mkv|webm|mov)$/i.test(path);
    const image = /\.(jpe?g|png|gif|webp|avif|bmp|ico)$/i.test(path);
    if (!video && !image) return;
    const original = target.getAttribute('src'), wasThumb = target.classList.contains('thumb');
    if (target.closest('#view')) {
      const iconSize = target.getBoundingClientRect().width || 20;
      const slot = getComputedStyle(target.parentElement);
      const center = (parseFloat(slot.left) || 0) + (parseFloat(slot.paddingLeft) || 0) + iconSize / 2;
      target.parentElement.style.setProperty('--fk-play-size', iconSize + 'px');
      target.parentElement.style.setProperty('--fk-preview-left', (center - 20) + 'px');
    }
    const show = src => {
      if (signal.aborted) return;
      target.onerror = () => {
        if (signal.aborted) return;
        target.onerror = null; target.classList.remove('fk-info-preview'); target.parentElement?.classList.remove('fk-video-preview');
        target.classList.toggle('thumb', wasThumb); target.src = original;
      };
      target.classList.add('thumb', 'fk-info-preview'); target.src = src;
      if (video && target.closest('#view')) target.parentElement.classList.add('fk-video-preview');
    };
    try {
      if (image) { await loadImage(source.href, signal); show(source.href); return; }
      const key = source.pathname;
      if (cache.has(key)) { show(remember(key, cache.get(key))); return; }
      // Read existing sheets only: hovering a file never queues generation.
      const url = new URL(endpoint, location.href); url.searchParams.set('file', key);
      const response = await fetch(url, { credentials: 'same-origin', signal, headers: { Accept: 'application/json' } });
      if (!response.ok) return;
      const data = await response.json();
      if (!data.url || !data.sheets?.length || !(data.width > 0 && data.height > 0 && data.columns > 0 && data.rows > 0)) return;
      const frame = Math.min(Math.max(0, (data.count || 1) - 1), Math.floor(Math.min(30, (data.duration || 0) * .1) / (data.interval || 2)));
      const perSheet = data.columns * data.rows;
      const page = data.sheets.includes(Math.floor(frame / perSheet)) ? Math.floor(frame / perSheet) : data.sheets[0];
      const cell = page === Math.floor(frame / perSheet) ? frame % perSheet : 0;
      const sheetUrl = new URL(data.url.replace('{sheet}', String(page)), location.href);
      if (sheetUrl.origin !== location.origin) return;
      const sheet = await loadImage(sheetUrl.href, signal);
      if (signal.aborted) return;
      const canvas = document.createElement('canvas'); canvas.width = data.width; canvas.height = data.height;
      const context = canvas.getContext('2d'); if (!context) return;
      context.drawImage(sheet, cell % data.columns * data.width, Math.floor(cell / data.columns) * data.height,
        data.width, data.height, 0, 0, data.width, data.height);
      show(remember(key, canvas.toDataURL('image/png')));
    } catch (_) { /* Keep the original icon when no protected preview is available. */ }
  };
  const observer = new IntersectionObserver(entries => {
    for (const entry of entries) {
      if (!entry.isIntersecting) continue;
      observer.unobserve(entry.target);
      window.FileKeepInfoPreview(entry.target, entry.target.dataset.previewHref, false);
    }
  }, { rootMargin: '100px' });
  window.FileKeepObservePreview = (images, href, folder) => {
    window.FileKeepViews?.observe(images, href, folder);
    if (folder) return;
    for (const image of Array.from(images)) {
      image.dataset.previewHref = href; observer.observe(image);
    }
  };
})();
