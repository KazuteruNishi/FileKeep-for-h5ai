(function () {
    'use strict';
    var role = document.getElementById('role');
    var folders = document.getElementById('folder-permissions');
    if (!role || !folders) { return; }
    function update() {
        var administrator = role.value === 'admin';
        folders.hidden = administrator;
        folders.disabled = administrator;
    }
    role.addEventListener('change', update);
    window.addEventListener('pageshow', update);
    update();
}());
