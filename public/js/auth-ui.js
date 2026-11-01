(function () {
    'use strict';
    var template = document.getElementById('h5ai-auth-template');
    if (!template) { return; }
    var actions = document.getElementById('h5ai-auth-actions');
    if (!actions) { actions = template.content.firstElementChild.cloneNode(true); }

    function mount() {
        // h5ai clears and rebuilds the body during initialization. Keep the form
        // outside its lifecycle and restore it into the completed toolbar.
        var target = document.getElementById('h5ai-account-slot') || document.getElementById('topbar') || document.body;
        if (target && actions.parentNode !== target) { target.appendChild(actions); }
    }
    mount();
    new MutationObserver(mount).observe(document.body, { childList: true, subtree: true });
    document.addEventListener('click', function (event) {
        if (actions.open && !actions.contains(event.target)) { actions.open = false; }
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && actions.open) {
            actions.open = false;
            actions.querySelector('summary').focus();
        }
    });
    actions.addEventListener('focusout', function (event) {
        if (event.relatedTarget && !actions.contains(event.relatedTarget)) { actions.open = false; }
    });
}());
