document.getElementById('error-retry')?.addEventListener('click', () => {
  // Navigate with GET: never replay a password, logout or account modification POST.
  window.location.replace(window.location.href);
});
