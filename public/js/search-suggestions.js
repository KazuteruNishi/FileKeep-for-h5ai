(() => {
  'use strict';
  const script = document.currentScript, endpoint = script.dataset.endpoint;
  const assets = new URL(script.dataset.assets, location.href);
  const icon = (path, className = '') => {
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', '0 0 24 24'); svg.setAttribute('aria-hidden', 'true'); svg.classList.add('fk-search-icon');
    if (className) svg.classList.add(className);
    const shape = document.createElementNS('http://www.w3.org/2000/svg', 'path'); shape.setAttribute('d', path); svg.append(shape); return svg;
  };
  const magnifier = 'M21 21l-5-5M17 10a7 7 0 1 1-14 0 7 7 0 0 1 14 0';
  const form = document.createElement('form'); form.id = 'fk-site-search'; form.setAttribute('role', 'search');
  const field = document.createElement('div'); field.className = 'fk-search-field';
  const input = document.createElement('input'); input.type = 'search'; input.placeholder = 'Rechercher'; input.maxLength = 128;
  input.autocomplete = 'off'; input.setAttribute('aria-label', 'Rechercher sur le serveur'); input.setAttribute('role', 'combobox');
  input.setAttribute('aria-autocomplete', 'list'); input.setAttribute('aria-expanded', 'false'); input.setAttribute('aria-controls', 'fk-search-results');
  const clear = document.createElement('button'); clear.type = 'button'; clear.className = 'fk-search-clear';
  clear.setAttribute('aria-label', 'Effacer la recherche'); clear.hidden = true; clear.append(icon('M6 6l12 12M18 6 6 18'));
  field.append(icon(magnifier, 'fk-search-leading'), input, clear);
  const submit = document.createElement('button'); submit.type = 'submit'; submit.className = 'fk-search-submit';
  submit.setAttribute('aria-label', 'Rechercher'); submit.append(icon(magnifier)); form.append(field, submit);
  const popup = document.createElement('div'); popup.id = 'fk-search-popup'; popup.hidden = true;
  const results = document.createElement('div'); results.id = 'fk-search-results'; results.setAttribute('role', 'listbox');
  const status = document.createElement('p'); status.className = 'fk-search-status'; status.setAttribute('role', 'status');
  popup.append(results, status);
  let timer, poll, request, revision = 0, items = [], active = -1;
  let compact = false, expanded = false;
  function close() { clearTimeout(timer); clearTimeout(poll); request?.abort(); revision++; popup.hidden = true; input.setAttribute('aria-expanded', 'false'); input.removeAttribute('aria-activedescendant'); active = -1; }
  function position() {
    const bar = document.getElementById('topbar'); if (!bar) return;
    const box = bar.getBoundingClientRect(), toolbar = document.getElementById('toolbar')?.getBoundingClientRect();
    const account = document.getElementById('h5ai-auth-actions')?.getBoundingClientRect();
    const left = Math.max(box.left + 12, (toolbar?.right || box.left) + 12), right = Math.min(box.right - 12, (account?.left || box.right) - 8);
    compact = right - left < 180; form.classList.toggle('fk-search-compact', compact && !expanded);
    form.classList.toggle('fk-search-expanded', compact && expanded);
    const width = compact ? (expanded ? Math.max(0, box.width - 16) : 40) : Math.min(600, Math.max(0, right - left));
    form.style.width = width + 'px';
    form.style.left = (compact ? (expanded ? box.left + 8 : Math.max(box.left + 8, right - 40)) : right - width) + 'px';
    form.style.top = box.top + Math.max(0, (box.height - 36) / 2) + 'px';
    const rect = form.getBoundingClientRect();
    popup.style.left = Math.max(8, Math.min(rect.left, innerWidth - Math.max(280, rect.width) - 8)) + 'px';
    popup.style.top = rect.bottom + 6 + 'px'; popup.style.width = Math.min(innerWidth - 16, Math.max(280, rect.width)) + 'px';
    popup.style.maxHeight = Math.max(80, innerHeight - rect.bottom - 18) + 'px';
  }
  function mount() {
    const bar = document.getElementById('topbar'); if (!bar) return;
    if (form.parentElement !== bar) bar.append(form);
    if (popup.parentElement !== document.body) document.body.append(popup);
    position();
  }
  function select(index) {
    active = index;
    Array.from(results.children).forEach((row, i) => row.setAttribute('aria-selected', String(i === active)));
    if (active >= 0) { input.setAttribute('aria-activedescendant', 'fk-search-option-' + active); results.children[active]?.scrollIntoView({ block: 'nearest' }); }
    else input.removeAttribute('aria-activedescendant');
  }
  function navigate(item) {
    if (!item) return;
    const url = new URL(item.href, location.href);
    if (url.origin !== location.origin) return;
    close(); location.assign(url.href);
  }
  async function search() {
    clearTimeout(poll); request?.abort();
    const query = input.value.trim(), current = ++revision;
    if (query.length < 2) { close(); return; }
    request = new AbortController();
    status.textContent = 'Recherche…'; popup.hidden = false; input.setAttribute('aria-expanded', 'true'); position();
    const url = new URL(endpoint, location.href); url.searchParams.set('q', query);
    try {
      const response = await fetch(url, { signal: request.signal, credentials: 'same-origin', headers: { Accept: 'application/json' } });
      if (!response.ok) throw new Error();
      const data = await response.json();
      if (current !== revision || input.value.trim() !== query) return;
      items = data.results || []; results.replaceChildren(); active = -1; input.removeAttribute('aria-activedescendant');
      for (const [index, item] of items.entries()) {
        const row = document.createElement('div'); row.className = 'fk-search-option'; row.id = 'fk-search-option-' + index;
        row.setAttribute('role', 'option'); row.setAttribute('aria-selected', 'false');
        const image = document.createElement('img'); image.alt = ''; image.src = new URL(`images/themes/default/${({ folder: 'folder', video: 'vid', audio: 'aud', file: 'file' })[item.kind] || 'file'}.svg`, assets).href;
        const text = document.createElement('span'), title = document.createElement('strong'), subtitle = document.createElement('small');
        title.textContent = item.name; subtitle.textContent = item.parent || ({ folder: 'Dossier', video: 'Vidéo', audio: 'Musique', file: 'Fichier' })[item.kind];
        text.append(title, subtitle); row.append(image, text); row.addEventListener('pointerdown', event => event.preventDefault());
        row.addEventListener('pointerenter', () => select(index)); row.addEventListener('click', () => navigate(item)); results.append(row);
      }
      status.textContent = data.indexing ? 'Recherche dans les dossiers en cours…' : data.limited ? 'Catalogue limité : affinez votre recherche.' : items.length ? '' : 'Aucun résultat.';
      if (data.indexing && document.activeElement === input) poll = setTimeout(search, 500);
    } catch (error) { if (error.name !== 'AbortError' && current === revision) { items = []; results.replaceChildren(); status.textContent = 'Recherche indisponible. Réessayez.'; } }
  }
  input.addEventListener('input', () => {
    revision++; request?.abort(); clearTimeout(timer); clearTimeout(poll); clear.hidden = !input.value;
    items = []; results.replaceChildren(); close();
    if (input.value.trim().length >= 2) timer = setTimeout(search, 200);
  });
  input.addEventListener('focus', () => { expanded = true; position(); if (input.value.trim().length >= 2) search(); });
  input.addEventListener('keydown', event => {
    if (event.key === 'Escape') { event.preventDefault(); close(); expanded = false; position(); input.blur(); }
    else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault(); if (!items.length) return;
      popup.hidden = false; input.setAttribute('aria-expanded', 'true');
      select(event.key === 'ArrowDown' ? (active + 1) % items.length : (active <= 0 ? items.length - 1 : active - 1));
    } else if (event.key === 'Enter' && active >= 0) { event.preventDefault(); navigate(items[active]); }
  });
  clear.addEventListener('click', () => { input.value = ''; input.dispatchEvent(new Event('input')); input.focus(); });
  form.addEventListener('submit', event => { event.preventDefault(); if (compact && !expanded) { expanded = true; position(); input.focus(); } else if (items.length) navigate(items[Math.max(0, active)]); else { input.focus(); search(); } });
  document.addEventListener('pointerdown', event => { if (!form.contains(event.target) && !popup.contains(event.target)) { close(); expanded = false; position(); clearTimeout(poll); } });
  document.addEventListener('focusin', event => { if (!form.contains(event.target) && !popup.contains(event.target)) close(); });
  new MutationObserver(mount).observe(document.body, { childList: true });
  // h5ai and its account menu mount asynchronously; watch header changes too.
  let observedBar;
  const resize = new ResizeObserver(position);
  new MutationObserver(() => {
    mount(); const bar = document.getElementById('topbar');
    if (bar && observedBar !== bar) { observedBar = bar; resize.observe(bar); const toolbar = document.getElementById('toolbar'); if (toolbar) resize.observe(toolbar); }
  }).observe(document.body, { childList: true, subtree: true });
  window.addEventListener('resize', position); window.addEventListener('scroll', position, { passive: true });
  window.addEventListener('pagehide', () => { revision++; request?.abort(); clearTimeout(timer); clearTimeout(poll); resize.disconnect(); }, { once: true });
  mount();
})();
