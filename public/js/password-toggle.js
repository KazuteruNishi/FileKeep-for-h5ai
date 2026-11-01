(function () {
    'use strict';
    var namespace = 'http://www.w3.org/2000/svg';
    document.querySelectorAll('input[type="password"]').forEach(function (input) {
        if (input.closest('.h5ai-password-field')) { return; }
        var wrapper = document.createElement('span');
        wrapper.className = 'h5ai-password-field';
        input.parentNode.insertBefore(wrapper, input);
        wrapper.appendChild(input);
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'h5ai-password-toggle';
        button.setAttribute('aria-pressed', 'false');
        button.setAttribute('aria-label', 'Afficher le mot de passe');
        button.title = 'Afficher le mot de passe';
        if (input.id) { button.setAttribute('aria-controls', input.id); }
        var icon = document.createElementNS(namespace, 'svg');
        icon.setAttribute('viewBox', '0 0 24 24');
        icon.setAttribute('aria-hidden', 'true');
        var outline = document.createElementNS(namespace, 'path');
        outline.setAttribute('d', 'M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z');
        var pupil = document.createElementNS(namespace, 'circle');
        pupil.setAttribute('cx', '12'); pupil.setAttribute('cy', '12'); pupil.setAttribute('r', '3');
        var slash = document.createElementNS(namespace, 'path');
        slash.setAttribute('d', 'm3 3 18 18');
        slash.setAttribute('class', 'h5ai-eye-slash');
        icon.appendChild(outline); icon.appendChild(pupil); icon.appendChild(slash);
        button.appendChild(icon);
        wrapper.appendChild(button);
        function setVisible(visible) {
            input.type = visible ? 'text' : 'password';
            button.setAttribute('aria-pressed', visible ? 'true' : 'false');
            var label = visible ? 'Masquer le mot de passe' : 'Afficher le mot de passe';
            button.setAttribute('aria-label', label);
            button.title = label;
        }
        button.addEventListener('click', function () { setVisible(input.type === 'password'); });
        if (input.form) { input.form.addEventListener('submit', function () { setVisible(false); }); }
        window.addEventListener('pagehide', function () { setVisible(false); });
    });
}());
