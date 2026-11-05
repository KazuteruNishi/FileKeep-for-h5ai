(() => {
  const host = document.getElementById('preview-lists');
  if (!host) return;
  const states = [
    ['active', 'En cours'], ['queued', 'En attente'], ['ready', 'Terminés'], ['stopped', 'Annulées'], ['error', 'En erreur']
  ];
  const labels = { hashing: 'Calcul de l’empreinte', generating: 'Génération', paused: 'En pause', resuming: 'Reprise…', queued: 'En attente', ready: 'Terminé', stopped: 'Annulée', error: 'Échec' };
  const node = (tag, text) => { const el = document.createElement(tag); if (text !== undefined) el.textContent = text; return el; };
  let timer, refreshing = false, closed = false;
  let draggedPath = null, queueSaving = false, queueMutation = 0, priorityEditing = null;
  let priorityPopup = null;
  let priorityHighlight = null, priorityHighlightUntil = 0;
  const selected = new Set();
  const knownChildren = new Map();
  let selectionBusy = false;
  const tableViews = new Map();
  const historyDateFormat = new Intl.DateTimeFormat('fr-FR', { timeZone: 'Europe/Paris', day: '2-digit', month: '2-digit', year: 'numeric' });
  const historyTimeFormat = new Intl.DateTimeFormat('fr-FR', { timeZone: 'Europe/Paris', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false });
  let folder = '', searchTimer, catalogRequest, catalogBusy = false, visibleChoices = new Map();
  const expandedFolders = new Set();
  let visibleBranches = new Map();
  let treeRequest = new AbortController();
  const applyCompletion = (entry, checkbox) => {
    checkbox.disabled = !!entry.complete;
    checkbox.previewRow.dataset.complete = entry.complete ? 'true' : 'false';
    checkbox.previewSubtitle.textContent = entry.complete ? 'Aperçus terminés'
      : entry.folder ? `Inclure ce dossier et ses sous-dossiers${entry.total ? ` · ${entry.ready}/${entry.total} vidéos terminées` : ''}` : entry.parent;
    if (entry.complete) selected.delete(entry.path);
  };
  const picker = document.getElementById('preview-picker');
  const message = document.getElementById('picker-message');
  const priorityLabel = value => ({ '-100': 'Basse', 0: 'Normale', 100: 'Haute', 1000: 'Urgente' })[value] || 'Normale';
  const queueNotice = document.getElementById('queue-notice');
  function openPriorityMenu(trigger, row, focusIndex = null) {
    if (queueSaving) return;
    if (priorityPopup?.trigger === trigger) { priorityPopup.close(false); return; }
    priorityPopup?.close(false);
    const menu = node('div'); menu.className = 'preview-priority-menu'; menu.setAttribute('role', 'menu');
    menu.setAttribute('aria-label', `Priorité de ${row.path}`);
    const abort = new AbortController(), items = [], values = [-100, 0, 100, 1000];
    const close = restoreFocus => {
      abort.abort(); menu.remove(); trigger.setAttribute('aria-expanded', 'false');
      priorityEditing = null; priorityPopup = null;
      if (restoreFocus && trigger.isConnected) trigger.focus();
    };
    priorityEditing = row.path; trigger.setAttribute('aria-expanded', 'true');
    priorityPopup = { trigger, close };
    for (const value of values) {
      const option = node('button'), check = node('span', value === row.priority ? '✓' : '');
      option.type = 'button'; option.tabIndex = -1; option.setAttribute('role', 'menuitemradio');
      option.setAttribute('aria-checked', String(value === row.priority));
      check.className = 'preview-priority-check'; check.setAttribute('aria-hidden', 'true');
      option.append(check, node('span', priorityLabel(value)));
      option.addEventListener('click', () => {
        close(true);
        if (value === row.priority || queueSaving) return;
        trigger.disabled = true;
        saveQueue({ action: 'queue-priority', source: row.path, priority: String(value) });
      });
      items.push(option); menu.append(option);
    }
    document.body.append(menu);
    const position = () => {
      const rect = trigger.getBoundingClientRect(), box = menu.getBoundingClientRect();
      menu.style.left = Math.max(8, Math.min(rect.left, window.innerWidth - box.width - 8)) + 'px';
      const top = rect.bottom + 6 + box.height <= window.innerHeight - 8 ? rect.bottom + 6 : rect.top - box.height - 6;
      menu.style.top = Math.max(8, Math.min(top, window.innerHeight - box.height - 8)) + 'px';
    };
    position();
    document.addEventListener('pointerdown', event => {
      if (!menu.contains(event.target) && !trigger.contains(event.target)) close(false);
    }, { signal: abort.signal });
    window.addEventListener('resize', position, { signal: abort.signal });
    window.addEventListener('scroll', position, { signal: abort.signal, capture: true });
    menu.addEventListener('keydown', event => {
      const index = items.indexOf(document.activeElement);
      let next = null;
      if (event.key === 'ArrowDown') next = (index + 1) % items.length;
      if (event.key === 'ArrowUp') next = (index + items.length - 1) % items.length;
      if (event.key === 'Home') next = 0;
      if (event.key === 'End') next = items.length - 1;
      if (next !== null) { event.preventDefault(); items[next].focus(); }
      if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); items[index]?.click(); }
      if (event.key === 'Escape') { event.preventDefault(); close(true); }
      if (event.key === 'Tab') close(true);
    });
    items[focusIndex ?? Math.max(0, values.indexOf(row.priority))].focus();
  }
  async function saveQueue(fields) {
    if (queueSaving) return;
    queueSaving = true; queueMutation++;
    queueNotice.textContent = fields.action === 'clear-history' ? 'Nettoyage de l’historique de cette catégorie…'
      : fields.action === 'clear-history-entry' ? 'Suppression de cette entrée de l’historique…'
      : fields.action === 'cancel-job' ? 'Annulation de ce traitement…'
      : fields.action === 'clear-cancelled-entry' ? 'Suppression de cette entrée de l’historique…'
      : fields.action === 'clear-cancelled' ? 'Nettoyage de l’historique des annulations…'
      : fields.action === 'pause-job' ? 'Mise en pause du traitement…'
      : fields.action === 'resume-job' ? 'Reprise du traitement…'
      : fields.action === 'queue-priority' ? 'Enregistrement de la priorité…' : 'Enregistrement de l’ordre…';
    const body = new URLSearchParams({ ...fields, _csrf: document.querySelector('meta[name="h5ai-csrf"]').content });
    try {
      const response = await fetch(host.dataset.endpoint, { method: 'POST', credentials: 'same-origin', body, headers: { Accept: 'application/json' } });
      if (!response.ok || !(await response.json()).ok) throw new Error();
      if (fields.action === 'queue-priority') {
        priorityHighlight = fields.source; priorityHighlightUntil = Date.now() + 5000;
      }
      queueNotice.textContent = fields.action === 'clear-history' ? 'Historique de cette catégorie vidé. Les vidéos et leurs aperçus sont conservés.'
        : fields.action === 'clear-history-entry' ? 'Entrée retirée de l’historique. Les vidéos et leurs aperçus sont conservés.'
        : fields.action === 'cancel-job' ? 'Traitement annulé. La vidéo et ses aperçus sont conservés.'
        : fields.action === 'clear-cancelled-entry' ? 'Entrée retirée de l’historique. Les aperçus sont conservés.'
        : fields.action === 'clear-cancelled' ? 'Historique des annulations vidé. Les aperçus sont conservés.'
        : fields.action === 'pause-job' ? 'Cette vidéo est en pause. Les autres traitements continuent.'
        : fields.action === 'resume-job' ? 'Reprise de cette vidéo sur sa place réservée. Si le générateur est en pause globale, cliquez aussi sur Reprendre.'
        : fields.action === 'queue-priority' ? `Priorité ${priorityLabel(Number(fields.priority))} enregistrée pour « ${fields.source.split('/').pop()} ». La page courante est conservée.`
        : fields.action === 'reset-order' ? 'Tri automatique rétabli.' : 'Ordre enregistré. Il s’applique à la prochaine vidéo.';
    } catch (_) { queueNotice.textContent = fields.action.startsWith('clear-') ? 'Impossible de modifier l’historique. Actualisez puis réessayez.' : 'Modification impossible. La vidéo a peut-être déjà démarré ; la liste va être actualisée.'; }
    finally { queueSaving = false; clearTimeout(timer); refresh(); }
  }
  function clearDropHints() {
    host.querySelectorAll('[data-drop]').forEach(item => item.removeAttribute('data-drop'));
  }
  function addQueueControls(item, row, rows, index) {
    item.className = 'preview-queued-row';
    const controls = node('div'), grip = node('button', '⠿'), arrows = node('div');
    controls.className = 'queue-row-controls'; grip.type = 'button'; grip.className = 'queue-grip'; grip.draggable = true;
    grip.title = 'Glisser pour déplacer'; grip.setAttribute('aria-label', `Déplacer ${row.path} par glisser-déposer`);
    grip.addEventListener('dragstart', event => {
      if (queueSaving) { event.preventDefault(); return; }
      draggedPath = row.path; item.dataset.dragging = 'true';
      event.dataTransfer.effectAllowed = 'move'; event.dataTransfer.setData('text/plain', row.path);
      event.dataTransfer.setDragImage(item, 20, item.offsetHeight / 2);
    });
    grip.addEventListener('dragend', () => { draggedPath = null; delete item.dataset.dragging; clearDropHints(); clearTimeout(timer); refresh(); });
    arrows.className = 'queue-arrows';
    for (const [direction, symbol, offset] of [['Monter', '⌃', -1], ['Descendre', '⌄', 1]]) {
      const button = node('button', symbol); button.type = 'button';
      button.title = direction; button.setAttribute('aria-label', `${direction} ${row.path}`);
      button.disabled = index + offset < 0 || index + offset >= rows.length;
      button.addEventListener('click', () => {
        const target = rows[index + offset];
        if (target) saveQueue({ action: 'reorder', source: row.path, target: target.path, placement: offset < 0 ? 'before' : 'after' });
      });
      arrows.append(button);
    }
    const position = node('span', String(row.queuePosition)); position.className = 'queue-position';
    controls.append(grip, arrows, position);
    const cell = node('td'); cell.className = 'queue-controls-cell'; cell.append(controls); item.prepend(cell);
    item.addEventListener('dragover', event => {
      if (!draggedPath || draggedPath === row.path || queueSaving) return;
      event.preventDefault(); event.dataTransfer.dropEffect = 'move'; clearDropHints();
      const rect = item.getBoundingClientRect(); item.dataset.drop = event.clientY < rect.top + rect.height / 2 ? 'before' : 'after';
    });
    item.addEventListener('dragleave', event => { if (!item.contains(event.relatedTarget)) item.removeAttribute('data-drop'); });
    item.addEventListener('drop', event => {
      if (!draggedPath || draggedPath === row.path || queueSaving) return;
      event.preventDefault();
      const source = draggedPath, placement = item.dataset.drop || 'before';
      draggedPath = null; clearDropHints();
      saveQueue({ action: 'reorder', source, target: row.path, placement });
    });
  }
  const selectionChanged = () => {
    // Collapse fully selected children back into their parent, deepest first.
    const parents = [...knownChildren.keys()].filter(Boolean).sort((a, b) => b.split('/').length - a.split('/').length);
    for (const parent of parents) {
      const data = knownChildren.get(parent);
      const entries = data.entries.filter(entry => !entry.complete && (!entry.folder || entry.total !== 0 || entry.unknown));
      if (data.truncated || !entries.length || !entries.every(entry => [...selected].some(path => entry.path === path || entry.path.startsWith(path + '/')))) continue;
      if ([...selected].some(path => parent === path || parent.startsWith(path + '/'))) continue;
      for (const path of [...selected]) { if (path.startsWith(parent + '/')) selected.delete(path); }
      selected.add(parent);
    }
    visibleChoices.forEach((checkboxes, path) => {
      const covered = [...selected].some(parent => path === parent || path.startsWith(parent + '/'));
      checkboxes.forEach(checkbox => {
        const complete = checkbox.previewRow.dataset.complete === 'true';
        checkbox.checked = !complete && covered; checkbox.disabled = complete || selectionBusy;
      });
    });
    document.getElementById('picker-count').textContent = `${selected.size} sélection${selected.size > 1 ? 's' : ''}`;
    document.getElementById('picker-summary').textContent = selected.size ? `${selected.size} fichier${selected.size > 1 ? 's' : ''} ou dossier${selected.size > 1 ? 's' : ''} sélectionné${selected.size > 1 ? 's' : ''}` : 'Choisir des fichiers ou des dossiers…';
    document.getElementById('picker-queue').disabled = !selected.size || selectionBusy;
    document.getElementById('picker-clear').disabled = selectionBusy;
  };
  async function fetchCatalog(path, search, signal) {
    const url = new URL(host.dataset.endpoint, location.href);
    url.searchParams.set('catalog', '1'); url.searchParams.set('path', path); url.searchParams.set('search', search);
    const response = await fetch(url, { credentials: 'same-origin', signal, headers: { Accept: 'application/json' } });
    if (!response.ok) throw new Error('Catalogue indisponible');
    const data = await response.json();
    if (!search) knownChildren.set(path, data);
    return data;
  }
  function renderChoices(entries, destination, signal, grouped = false) {
    const groups = new Map();
    for (const entry of entries) {
      const group = grouped ? (entry.folder ? 'Dossiers' : entry.parent) : '';
      if (!groups.has(group)) groups.set(group, []);
      groups.get(group).push(entry);
    }
    for (const [title, items] of groups) {
      if (title) { const heading = node('h3', title); heading.className = 'picker-group'; destination.append(heading); }
      for (const entry of items) {
        const wrapper = node('div'), row = node('div'), label = node('label'), checkbox = node('input'), text = node('span');
        wrapper.className = 'picker-tree-node'; row.className = 'picker-entry';
        checkbox.type = 'checkbox'; checkbox.checked = selected.has(entry.path);
        if (!visibleChoices.has(entry.path)) visibleChoices.set(entry.path, new Set());
        visibleChoices.get(entry.path).add(checkbox);
        checkbox.addEventListener('change', async () => {
          if (checkbox.disabled || selectionBusy) return;
          if (checkbox.checked) {
            const overlapping = [...selected].filter(path => path !== entry.path
              && (entry.path.startsWith(path + '/') || path.startsWith(entry.path + '/')));
            if (!selected.has(entry.path) && selected.size - overlapping.length >= 5000) {
              checkbox.checked = false; message.textContent = 'La sélection est limitée à 5 000 éléments.'; return;
            }
            overlapping.forEach(path => selected.delete(path)); selected.add(entry.path);
          } else {
            const inherited = [...selected].some(path => entry.path.startsWith(path + '/'));
            if (inherited) {
              selectionBusy = true; selectionChanged();
              try {
                const body = new URLSearchParams({ action: 'selection-exclude', excluded: entry.path,
                  paths_json: JSON.stringify([...selected]), _csrf: document.querySelector('meta[name="h5ai-csrf"]').content });
                const response = await fetch(host.dataset.endpoint, { method: 'POST', credentials: 'same-origin', body,
                  headers: { Accept: 'application/json' } });
                if (!response.ok) throw new Error();
                const data = await response.json();
                if (!Array.isArray(data.paths)) throw new Error();
                selected.clear(); data.paths.forEach(path => selected.add(path));
              } catch (_) { message.textContent = 'Impossible de modifier la sélection. Réessayez.'; }
              finally { selectionBusy = false; }
            } else {
              for (const path of [...selected]) {
                if (path === entry.path || path.startsWith(entry.path + '/')) selected.delete(path);
              }
            }
          }
          selectionChanged();
        });
        const subtitle = node('small');
        checkbox.previewRow = row; checkbox.previewSubtitle = subtitle; applyCompletion(entry, checkbox);
        text.append(node('strong', entry.name), subtitle); label.append(text, checkbox); row.append(label);
        wrapper.append(row); destination.append(wrapper);
        if (!entry.folder) continue;
        const toggle = node('button'), children = node('div');
        const chevron = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        chevron.setAttribute('viewBox', '0 0 24 24'); chevron.setAttribute('aria-hidden', 'true');
        const chevronPath = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        chevronPath.setAttribute('d', 'm9 5 7 7-7 7'); chevron.append(chevronPath); toggle.append(chevron);
        toggle.type = 'button'; toggle.className = 'picker-tree-toggle';
        children.className = 'picker-tree-children'; children.hidden = true;
        children.id = 'picker-branch-' + (++branchId);
        children.setAttribute('role', 'group'); children.setAttribute('aria-label', entry.name);
        toggle.setAttribute('aria-controls', children.id);
        const branch = { children, toggle, loaded: false, loading: false };
        if (!visibleBranches.has(entry.path)) visibleBranches.set(entry.path, new Set());
        visibleBranches.get(entry.path).add(branch); row.prepend(toggle); wrapper.append(children);
        const syncToggle = () => {
          const expanded = expandedFolders.has(entry.path);
          children.hidden = !expanded;
          toggle.setAttribute('aria-expanded', String(expanded));
          toggle.setAttribute('aria-label', `${expanded ? 'Réduire' : 'Développer'} ${entry.path}`);
        };
        const expand = async () => {
          syncToggle();
          if (!expandedFolders.has(entry.path) || branch.loaded || branch.loading) return;
          branch.loading = true; toggle.setAttribute('aria-busy', 'true');
          children.replaceChildren(node('p', 'Chargement…'));
          try {
            const data = await fetchCatalog(entry.path, '', signal);
            if (signal.aborted || !visibleBranches.get(entry.path)?.has(branch)) return;
            children.replaceChildren(); branch.loaded = true;
            renderChoices(data.entries, children, signal);
            if (!data.entries.length) children.append(node('p', 'Aucune vidéo ni sous-dossier.'));
            if (data.truncated) children.append(node('p', '300 résultats affichés. Utilisez la recherche pour affiner.'));
            selectionChanged();
          } catch (error) {
            if (error.name !== 'AbortError' && visibleBranches.get(entry.path)?.has(branch)) {
              expandedFolders.delete(entry.path); syncToggle();
              message.textContent = 'Ce dossier est indisponible. Réessayez de le développer.';
            }
          } finally { branch.loading = false; toggle.removeAttribute('aria-busy'); }
        };
        toggle.addEventListener('click', () => {
          if (expandedFolders.has(entry.path)) { expandedFolders.delete(entry.path); syncToggle(); }
          else { expandedFolders.add(entry.path); expand(); }
        });
        syncToggle();
        if (expandedFolders.has(entry.path)) expand();
      }
    }
  }
  let branchId = 0;
  async function catalog(updateOnly = false) {
    if (updateOnly && catalogBusy) return;
    if (!updateOnly) { treeRequest.abort(); treeRequest = new AbortController(); }
    catalogBusy = true;
    catalogRequest?.abort(); catalogRequest = new AbortController();
    const signal = catalogRequest.signal;
    if (!updateOnly) message.textContent = 'Recherche…';
    try {
      const data = await fetchCatalog(folder, document.getElementById('preview-search').value.trim(), signal);
      if (updateOnly) {
        const entries = [...data.entries];
        for (const [path, branches] of visibleBranches) {
          if (!expandedFolders.has(path) || ![...branches].some(branch => !branch.children.closest('[hidden]') && branch.loaded)) continue;
          try { entries.push(...(await fetchCatalog(path, '', signal)).entries); }
          catch (error) { if (error.name === 'AbortError') throw error; }
        }
        for (const entry of entries) {
          visibleChoices.get(entry.path)?.forEach(checkbox => applyCompletion(entry, checkbox));
        }
        selectionChanged(); return;
      }
      visibleChoices = new Map(); visibleBranches = new Map();
      const results = document.getElementById('picker-results'); results.replaceChildren();
      renderChoices(data.entries, results, treeRequest.signal, true); selectionChanged();
      message.textContent = data.truncated ? '300 résultats affichés. Affinez la recherche ou développez un dossier.' : data.entries.length ? '' : 'Aucun fichier vidéo ni dossier trouvé.';
    } catch (error) { if (!updateOnly && error.name !== 'AbortError') message.textContent = 'La recherche est indisponible. Réessayez.'; }
    finally { if (catalogRequest.signal === signal) catalogBusy = false; }
  }
  picker.addEventListener('toggle', () => { if (picker.open) catalog(); });
  picker.addEventListener('keydown', event => { if (event.key === 'Escape') { picker.open = false; picker.querySelector('summary').focus(); } });
  document.addEventListener('click', event => { if (!picker.contains(event.target)) picker.open = false; });
  document.getElementById('preview-search').addEventListener('input', () => { clearTimeout(searchTimer); searchTimer = setTimeout(catalog, 250); });
  document.getElementById('picker-clear').addEventListener('click', () => { selected.clear(); selectionChanged(); catalog(); });
  async function applySelection(action) {
    if (!selected.size) return;
    const body = new URLSearchParams({ action, priority: document.getElementById('picker-priority').value,
      _csrf: document.querySelector('meta[name="h5ai-csrf"]').content });
    body.set('paths_json', JSON.stringify([...selected]));
    document.getElementById('picker-queue').disabled = true;
    try {
      const response = await fetch(host.dataset.endpoint, { method: 'POST', credentials: 'same-origin', body, headers: { Accept: 'application/json' } });
      if (!response.ok || !(await response.json()).ok) throw new Error();
      message.textContent = action === 'select' ? 'Préchargement demandé. Si le traitement est suspendu, cliquez sur Reprendre.' : 'Priorités enregistrées pour les prochaines vidéos.';
      clearTimeout(timer); refresh();
    } catch (_) { message.textContent = 'Impossible de valider la sélection. Réessayez.'; }
    finally { selectionChanged(); }
  }
  document.getElementById('picker-queue').addEventListener('click', () => applySelection('select'));
  selectionChanged();
  const searchText = text => text.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('fr');
  function renderTables(data) {
    if (!tableViews.size) host.replaceChildren();
    for (const [key, title] of states) {
      let view = tableViews.get(key);
      if (!view) {
        const section = node('section'), toolbar = node('div'), heading = node('h2'), searchLabel = node('label'), search = node('input');
        section.className = 'preview-table-card'; section.dataset.category = key;
        toolbar.className = 'preview-table-toolbar'; heading.id = `preview-table-${key}`;
        search.type = 'search'; search.placeholder = 'Rechercher un fichier ou un dossier…';
        search.setAttribute('aria-label', `Rechercher — ${title}`); search.className = 'preview-table-search';
        searchLabel.append(search); toolbar.append(heading, searchLabel);
        const hint = node('div'), scroll = node('div'), table = node('table'), head = node('thead'), headRow = node('tr'), body = node('tbody'), footer = node('div');
        hint.className = 'preview-table-hint'; scroll.className = 'preview-table-scroll'; footer.className = 'preview-table-footer';
        table.setAttribute('aria-labelledby', heading.id);
        const history = ['ready', 'stopped', 'error'].includes(key);
        const columns = [...(key === 'queued' ? ['Ordre'] : key === 'active' ? ['Action'] : []), 'Fichier / dossier', 'État', 'Priorité', 'Progression', ...(history ? ['Date et heure'] : []), ...(key !== 'active' ? ['Action'] : [])];
        const widths = [...(key === 'queued' ? [84] : key === 'active' ? [58] : []), null, 150, 130, 150, ...(history ? [136] : []), ...(key !== 'active' ? [58] : [])];
        const colgroup = node('colgroup');
        columns.forEach((column, index) => {
          const col = node('col');
          if (widths[index] !== null) col.setAttribute('width', String(widths[index]));
          colgroup.append(col);
          const th = node('th', column); th.scope = 'col'; headRow.append(th);
        });
        head.append(headRow); table.append(colgroup, head, body); scroll.append(table); section.append(toolbar, hint, scroll, footer); host.append(section);
        view = { section, heading, search, hint, body, footer, page: 1, rows: [], manualOrder: false };
        tableViews.set(key, view);
        search.addEventListener('input', () => { view.page = 1; renderTable(view, key, title); });
      }
      view.rows = data.videos.filter(row => (['hashing', 'generating', 'paused', 'resuming'].includes(row.status) ? 'active' : row.status) === key);
      if (key === 'queued') view.rows.sort((a, b) => a.queuePosition - b.queuePosition);
      if (['ready', 'stopped', 'error'].includes(key)) {
        view.rows.sort((a, b) => (b.historyAt || 0) - (a.historyAt || 0) || a.path.localeCompare(b.path, 'fr', { numeric: true }));
      }
      view.manualOrder = data.manualOrder;
      renderTable(view, key, title);
    }
  }
  function renderTable(view, key, title) {
    // Leave the open priority menu intact. Other categories
    // continue updating normally; the server revalidates the state on change.
    if (key === 'queued' && priorityEditing) return;
    const query = searchText(view.search.value.trim());
    const filtered = query ? view.rows.filter(row => searchText(row.path).includes(query)) : view.rows;
    const pages = Math.max(1, Math.ceil(filtered.length / 10)); view.page = Math.min(view.page, pages);
    const start = (view.page - 1) * 10;
    view.heading.textContent = `${title} (${view.rows.length})`;
    if (key === 'active') {
      const paused = view.rows.filter(row => row.status === 'paused').length;
      const resuming = view.rows.filter(row => row.status === 'resuming').length;
      view.heading.textContent = `${title} (${view.rows.length - paused - resuming})${paused ? ` · En pause (${paused})` : ''}${resuming ? ` · Reprise (${resuming})` : ''}`;
    }
    view.hint.replaceChildren();
    if (['stopped', 'ready', 'error'].includes(key) && view.rows.length) {
      view.hint.append(node('span', 'Les vidéos et leurs aperçus sont conservés.'));
      const clear = node('button', 'Vider l’historique'); clear.type = 'button'; clear.className = 'preview-clear-history';
      clear.setAttribute('aria-label', `Vider tout l’historique — ${title}`);
      clear.addEventListener('click', () => { if (!queueSaving) { clear.disabled = true; saveQueue({ action: 'clear-history', category: key }); } });
      view.hint.append(clear);
    }
    if (key === 'queued' && view.rows.length) {
      view.hint.append(node('span', view.manualOrder ? 'Ordre manuel · Déplacez les vidéos avec la poignée ou les flèches.' : 'Ordre automatique · Priorités puis ordre alphabétique.'));
      if (view.manualOrder) {
        const reset = node('button', 'Rétablir le tri automatique'); reset.type = 'button';
        reset.addEventListener('click', () => saveQueue({ action: 'reset-order' })); view.hint.append(reset);
      }
    }
    view.hint.hidden = !view.hint.childNodes.length;
    view.body.replaceChildren();
    for (const row of filtered.slice(start, start + 10)) {
      const tr = node('tr'), file = node('td'), state = node('td'), priority = node('td'), progression = node('td');
      const parts = row.path.split('/'), filename = parts.pop();
      file.className = 'preview-file-cell'; file.title = row.path;
      const playerLink = node('a');
      const home = new URL(host.dataset.home || '/', location.href);
      const playerUrl = new URL(parts.length ? parts.map(encodeURIComponent).join('/') + '/' : '', home);
      playerUrl.searchParams.set('lecture', filename);
      playerLink.href = playerUrl.href; playerLink.target = '_blank'; playerLink.rel = 'noopener noreferrer';
      playerLink.setAttribute('aria-label', `Lire ${filename} dans un nouvel onglet`);
      playerLink.append(node('strong', filename));
      file.append(playerLink, node('small', parts.join('/') || 'Racine'));
      const badge = node('span', labels[row.status] || row.status); badge.className = `preview-state preview-state-${row.status === 'paused' ? 'paused' : key}`;
      state.append(badge);
      const priorityBadge = node(key === 'queued' ? 'button' : 'span', priorityLabel(row.priority));
      priorityBadge.className = `preview-priority preview-priority-${row.priority >= 1000 ? 'urgent' : row.priority > 0 ? 'high' : row.priority < 0 ? 'low' : 'normal'}`;
      if (key === 'queued') {
        priorityBadge.className += ' preview-priority-trigger'; priorityBadge.type = 'button';
        priorityBadge.setAttribute('aria-label', `Priorité de ${row.path}`);
        priorityBadge.setAttribute('aria-haspopup', 'menu'); priorityBadge.setAttribute('aria-expanded', 'false');
        const chevron = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        chevron.setAttribute('viewBox', '0 0 24 24'); chevron.setAttribute('width', '14'); chevron.setAttribute('height', '14'); chevron.setAttribute('aria-hidden', 'true');
        const line = document.createElementNS('http://www.w3.org/2000/svg', 'polyline'); line.setAttribute('points', '6 9 12 15 18 9'); chevron.append(line); priorityBadge.append(chevron);
        priorityBadge.addEventListener('click', () => openPriorityMenu(priorityBadge, row));
        priorityBadge.addEventListener('keydown', event => {
          if (['ArrowDown', 'ArrowUp'].includes(event.key)) {
            event.preventDefault(); openPriorityMenu(priorityBadge, row, event.key === 'ArrowDown' ? 0 : 3);
          }
        });
      }
      priority.append(priorityBadge);
      progression.className = 'preview-progress-cell';
      if (['generating', 'hashing', 'paused', 'resuming', 'ready'].includes(row.status)) {
        const progress = node('progress'); progress.max = 100;
        progress.setAttribute('aria-label', `${row.path} — ${labels[row.status]}`);
        if (row.percent !== null) progress.value = row.percent;
        else if (['paused', 'resuming'].includes(row.status)) progress.value = 0;
        progression.append(node('span', row.percent === null ? ['paused', 'resuming'].includes(row.status) ? labels[row.status] : 'Calcul…' : `${row.percent} %`), progress);
      } else progression.append(node('span', '—'));
      tr.append(file, state, priority, progression);
      if (['ready', 'stopped', 'error'].includes(key)) {
        const cell = node('td'); cell.className = 'preview-history-date';
        const date = new Date(Number(row.historyAt) * 1000);
        if (Number(row.historyAt) > 0 && Number.isFinite(date.getTime())) {
          const time = node('time'); time.dateTime = date.toISOString(); time.title = 'Heure de Paris';
          time.append(node('span', historyDateFormat.format(date)), node('small', historyTimeFormat.format(date)));
          cell.append(time);
        } else cell.append(node('span', '—'));
        tr.append(cell);
      }
      if (key === 'active') {
        const cell = node('td'), button = node('button'), paused = row.status === 'paused';
        cell.className = 'preview-job-control'; button.type = 'button'; button.className = 'preview-job-toggle';
        button.disabled = row.status === 'resuming';
        const label = `${paused ? 'Reprendre' : 'Mettre en pause'} le traitement de ${filename}`;
        button.title = label; button.setAttribute('aria-label', label);
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('viewBox', '0 0 24 24'); svg.setAttribute('aria-hidden', 'true');
        svg.setAttribute('width', '17'); svg.setAttribute('height', '17'); svg.setAttribute('fill', 'currentColor');
        const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        path.setAttribute('d', paused ? 'M8 5v14l11-7Z' : 'M6 5h4v14H6zM14 5h4v14h-4z'); svg.append(path); button.append(svg);
        button.addEventListener('click', () => {
          if (queueSaving) return;
          button.disabled = true; saveQueue({ action: paused ? 'resume-job' : 'pause-job', source: row.path });
        });
        cell.append(button); tr.prepend(cell);
      }
      if (key === 'queued') addQueueControls(tr, row, view.rows, view.rows.indexOf(row));
      if (key !== 'active') {
        const cell = node('td'), button = node('button'); cell.className = 'preview-row-delete-cell';
        button.type = 'button'; button.className = 'preview-row-delete';
        const label = key === 'queued' ? `Annuler le traitement de ${filename}` : `Retirer de l’historique : ${filename}`;
        button.title = label; button.setAttribute('aria-label', label);
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('viewBox', '0 0 24 24'); svg.setAttribute('width', '17'); svg.setAttribute('height', '17'); svg.setAttribute('aria-hidden', 'true');
        const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        path.setAttribute('d', 'M3 6h18M9 6V4h6v2M5 6l1 14h12l1-14M10 10v6M14 10v6'); svg.append(path); button.append(svg);
        button.addEventListener('click', () => {
          if (queueSaving) return;
          button.disabled = true; saveQueue(key === 'queued' ? { action: 'cancel-job', source: row.path }
            : { action: 'clear-history-entry', category: key, source: row.path });
        });
        cell.append(button); tr.append(cell);
      }
      if (key === 'queued' && row.path === priorityHighlight && Date.now() < priorityHighlightUntil) tr.dataset.priorityUpdated = 'true';
      view.body.append(tr);
    }
    if (!filtered.length) {
      const tr = node('tr'), td = node('td', query ? 'Aucune vidéo ne correspond à cette recherche.' : 'Aucune vidéo.');
      td.colSpan = key === 'active' ? 5 : 6; td.className = 'preview-table-empty'; tr.append(td); view.body.append(tr);
    }
    const count = node('span', `${filtered.length ? start + 1 : 0}–${Math.min(start + 10, filtered.length)} sur ${filtered.length}`);
    const controls = node('div'), previous = node('button'), next = node('button');
    for (const [button, points] of [[previous, '14 6 8 12 14 18'], [next, '10 6 16 12 10 18']]) {
      const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
      svg.setAttribute('viewBox', '0 0 24 24'); svg.setAttribute('aria-hidden', 'true');
      const line = document.createElementNS('http://www.w3.org/2000/svg', 'polyline');
      line.setAttribute('points', points); svg.append(line); button.append(svg);
    }
    controls.className = 'preview-table-pagination';
    previous.type = next.type = 'button'; previous.disabled = view.page === 1; next.disabled = view.page === pages;
    previous.setAttribute('aria-label', `Page précédente — ${title}`); next.setAttribute('aria-label', `Page suivante — ${title}`);
    previous.addEventListener('click', () => { if (view.page > 1) { view.page--; renderTable(view, key, title); } });
    next.addEventListener('click', () => { if (view.page < pages) { view.page++; renderTable(view, key, title); } });
    controls.append(previous, node('span', `Page ${view.page} / ${pages}`), next);
    view.footer.replaceChildren(count, controls);
  }
  async function refresh() {
    if (refreshing || closed || draggedPath || queueSaving) return;
    const mutation = queueMutation;
    refreshing = true; clearTimeout(timer);
    try {
      const url = new URL(host.dataset.endpoint, location.href); url.searchParams.set('status', '1');
      const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } });
      if (!response.ok) throw new Error();
      const data = await response.json();
      if (mutation !== queueMutation || draggedPath || queueSaving) return;
      document.getElementById('generator-state').textContent = `Traitement : ${({ running: 'actif', paused: 'en pause — la file est conservée', stopped: 'arrêté — cliquez sur Reprendre après avoir choisi les vidéos à relancer' })[data.control] || 'inconnu'}`;
      document.getElementById('generator-state').textContent += ` · ${data.workerConcurrency || 1} vidéo${data.workerConcurrency > 1 ? 's' : ''} simultanée${data.workerConcurrency > 1 ? 's' : ''} maximum`;
      const versionWarning = document.getElementById('worker-version-warning');
      versionWarning.hidden = data.workerNeedsRestart === false;
      versionWarning.textContent = data.workerNeedsRestart === true
        ? 'Le générateur utilise une ancienne version du code. Le nouvel ordre sera pris en compte après son redémarrage. Il se relancera après les générations en cours avec la nouvelle surveillance installée ; sinon exécutez docker-compose restart storyboards.'
        : data.workerNeedsRestart === null ? 'Version du générateur non identifiée. Après transfert des fichiers, exécutez docker-compose restart storyboards pour charger le même code de file que cette page.' : '';
      const units = ['o', 'Kio', 'Mio', 'Gio', 'Tio'];
      let size = data.storageBytes, unit = 0;
      if (Number.isFinite(size) && size >= 0) {
        while (size >= 1024 && unit < units.length - 1) { size /= 1024; unit++; }
        document.getElementById('preview-storage').textContent = `Stockage total des aperçus : ${new Intl.NumberFormat('fr-FR', { maximumFractionDigits: unit ? 2 : 0 }).format(size)} ${units[unit]}`;
      } else {
        document.getElementById('preview-storage').textContent = 'Stockage total des aperçus : indisponible';
      }
      renderTables(data);

      document.getElementById('scan-state').textContent = ({ queued: 'Recherche demandée. En attente du générateur.', scanning: 'Recherche des vidéos en cours…', done: 'Recherche terminée. Les vidéos trouvées ont été mises en file.', paused: 'Recherche suspendue. Elle reprendra avec le traitement.', stopped: 'Recherche arrêtée.', error: 'Recherche terminée avec des erreurs. Consultez les journaux du générateur.' })[data.scan] || '';
      document.getElementById('preview-error').hidden = true;
      if (picker.open) catalog(true);
    } catch (_) { document.getElementById('preview-error').hidden = false; }
    finally {
      refreshing = false;
      if (!closed) timer = setTimeout(refresh, mutation !== queueMutation ? 0 : 3000);
    }
  }
  window.addEventListener('pagehide', () => { closed = true; priorityPopup?.close(false); clearTimeout(timer); clearTimeout(searchTimer); catalogRequest?.abort(); treeRequest.abort(); });
  window.addEventListener('pageshow', () => { if (closed) { closed = false; refresh(); } });
  refresh();
})();
