// Server-generated contact sheets. Generation continues after this view closes.
export function createStoryboards({ endpoint, href, signal, onUpdate = () => {} }) {
  const source = new URL(href, location.href).pathname;
  const manifestUrl = new URL(endpoint, location.href); manifestUrl.searchParams.set('file', source);
  const sheets = new Map(), pending = new Set(), failures = new Map();
  let manifest = null, timer = null, destroyed = false, active = 0, wantedSheet = null, readErrors = 0;
  const queue = [];
  const disposeImage = image => { if (typeof image?.close === 'function') image.close(); };
  const notify = () => { if (!destroyed) onUpdate(); };
  const schedule = () => {
    clearTimeout(timer);
    if (!destroyed && manifest?.status !== 'ready' && manifest?.status !== 'error') timer = setTimeout(() => read(false), 1500);
  };
  const read = async start => {
    try {
      const options = { credentials: 'same-origin', signal, headers: { Accept: 'application/json' } };
      if (start) {
        // Enqueuing must outlive closing the player or navigating away.
        // Only manifest polling and image downloads follow the view's signal.
        delete options.signal;
        options.keepalive = true;
        options.method = 'POST'; options.body = new URLSearchParams({ file: source,
          _csrf: document.querySelector('meta[name="h5ai-csrf"]')?.content || '' });
      }
      const response = await fetch(start ? endpoint : manifestUrl.href, options);
      if (!response.ok) throw new Error('Planches indisponibles.');
      const data = await response.json();
      if (destroyed) return;
      if (data.id && data.id !== manifest?.id) {
        sheets.forEach(disposeImage); sheets.clear(); failures.clear();
      }
      readErrors = 0; manifest = data; notify(); schedule();
    } catch (error) {
      if (!destroyed && error.name !== 'AbortError') {
        if (++readErrors < 4) timer = setTimeout(() => read(start), 3000);
        else { manifest = { status: 'error' }; notify(); }
      }
    }
  };
  const frameAt = time => {
    if (!manifest?.id || !Number.isFinite(manifest.interval) || manifest.interval <= 0 || !Number.isInteger(manifest.count) || manifest.count <= 0) return null;
    const frame = Math.max(0, Math.min(manifest.count - 1, Math.floor(time / manifest.interval)));
    const perSheet = manifest.columns * manifest.rows;
    return { frame, sheet: Math.floor(frame / perSheet), cell: frame % perSheet };
  };
  const available = index => Array.isArray(manifest?.sheets) && manifest.sheets.includes(index)
    && (!failures.has(index) || Date.now() - failures.get(index) > 5000);
  const decode = async blob => {
    if (typeof createImageBitmap === 'function') return createImageBitmap(blob);
    const url = URL.createObjectURL(blob), image = new Image();
    try {
      await new Promise((resolve, reject) => { image.onload = resolve; image.onerror = reject; image.src = url; });
      return image;
    } finally { URL.revokeObjectURL(url); }
  };
  const pump = () => {
    while (!destroyed && active < 2 && queue.length) {
      const index = queue.shift();
      if (!pending.has(index)) continue;
      active++;
      const id = manifest.id, url = new URL(manifest.url.replace('{sheet}', String(index)), location.href);
      // Keep protected image requests within this site's authenticated origin.
      const task = url.origin === location.origin
        ? fetch(url, { credentials: 'same-origin', signal }).then(response => {
          if (!response.ok) throw new Error('Planche indisponible.'); return response.blob();
        }).then(decode) : Promise.reject(new Error('Adresse de planche invalide.'));
      task.then(image => {
        if (destroyed || manifest.id !== id) { disposeImage(image); return; }
        sheets.set(index, image);
        while (sheets.size > 6) { const oldest = sheets.keys().next().value; disposeImage(sheets.get(oldest)); sheets.delete(oldest); }
      }).catch(error => { if (!destroyed && error.name !== 'AbortError') failures.set(index, Date.now()); })
        .finally(() => { active--; pending.delete(index); notify(); pump(); });
    }
  };
  const requestSheet = (index, priority = false) => {
    if (destroyed || sheets.has(index) || !available(index)) return;
    if (pending.has(index)) {
      if (priority) { const position = queue.indexOf(index); if (position >= 0) { queue.splice(position, 1); queue.unshift(index); } }
      return;
    }
    // At most the current sheet and its two neighbors need queuing.
    if (!priority && queue.length >= 3) return;
    pending.add(index);
    if (priority) queue.unshift(index); else queue.push(index);
    pump();
  };
  const controller = {
    get status() { return manifest?.status || 'loading'; },
    covers(time) { const frame = frameAt(time); return !!frame && available(frame.sheet); },
    request(time) {
      const frame = frameAt(time); if (!frame || destroyed) return;
      wantedSheet = frame.sheet;
      // Drop speculative queued work when the pointer jumps elsewhere.
      for (let index = queue.length - 1; index >= 0; index--) {
        if (Math.abs(queue[index] - wantedSheet) > 1) { pending.delete(queue[index]); queue.splice(index, 1); }
      }
      requestSheet(frame.sheet, true); requestSheet(frame.sheet + 1); requestSheet(frame.sheet - 1);
    },
    draw(time, canvas) {
      const frame = frameAt(time), image = frame && sheets.get(frame.sheet);
      if (!image || destroyed) return false;
      const width = manifest.width, height = manifest.height;
      const x = frame.cell % manifest.columns * width, y = Math.floor(frame.cell / manifest.columns) * height;
      const context = canvas.getContext('2d');
      if (!context || image.width < x + width || image.height < y + height) return false;
      sheets.delete(frame.sheet); sheets.set(frame.sheet, image);
      context.drawImage(image, x, y, width, height, 0, 0, canvas.width, canvas.height);
      return true;
    },
    destroy() {
      if (destroyed) return;
      destroyed = true; clearTimeout(timer); queue.length = 0; pending.clear(); sheets.forEach(disposeImage); sheets.clear();
    }
  };
  signal?.addEventListener('abort', () => controller.destroy(), { once: true });
  if (signal?.aborted) controller.destroy(); else read(true);
  return controller;
}
