// Open a shared video in the existing h5ai player, retaining its folder navigation.
(() => {
  const name = new URL(location.href).searchParams.get('lecture');
  if (!name || /[\/\\]/.test(name) || !/\.(mp4|m4v|mkv|webm|mov)$/i.test(name)) return;
  const target = new URL(encodeURIComponent(name), location.href);
  let opened = false;
  const observer = new MutationObserver(findVideo);
  const timeout = setTimeout(() => observer.disconnect(), 60000);
  function findVideo() {
    if (opened) return;
    for (const row of document.querySelectorAll('#items .item')) {
      const href = row._item?.absHref, link = row.querySelector('a');
      if (!href || !link) continue;
      try {
        const candidate = new URL(href, location.href);
        if (candidate.origin !== target.origin || decodeURIComponent(candidate.pathname) !== decodeURIComponent(target.pathname)) continue;
      } catch (_) { continue; }
      opened = true; observer.disconnect(); clearTimeout(timeout);
      // h5ai registers the preview handlers at the end of its view update.
      setTimeout(() => { if (link.isConnected) link.click(); }, 0);
      break;
    }
  }
  observer.observe(document.body, { childList: true, subtree: true });
  findVideo();
  window.addEventListener('pagehide', () => { observer.disconnect(); clearTimeout(timeout); }, { once: true });
})();
