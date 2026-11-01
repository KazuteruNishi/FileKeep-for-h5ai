(function () {
    'use strict';
    var input = document.getElementById('avatar');
    if (!input) { return; }
    if (!window.DataTransfer || !HTMLCanvasElement.prototype.toBlob) {
        document.getElementById('avatar-status').textContent = 'Recadrage indisponible dans ce navigateur : choisissez une photo de 512 Ko et 2048 × 2048 pixels maximum.';
        return;
    }
    var form = input.form;
    var editor = document.getElementById('avatar-crop');
    var canvas = document.getElementById('avatar-canvas');
    var context = canvas.getContext('2d');
    if (!context) { return; }
    var zoom = document.getElementById('avatar-zoom');
    var horizontal = document.getElementById('avatar-horizontal');
    var vertical = document.getElementById('avatar-vertical');
    var apply = document.getElementById('avatar-apply');
    var cancel = document.getElementById('avatar-cancel');
    var status = document.getElementById('avatar-status');
    var remove = form.querySelector('[name="remove_avatar"]');
    var image = null;
    var imageUrl = null;
    var pending = false;
    var generation = 0;
    var drag = null;
    var busy = false;
    var conversion = 0;

    function message(text, error) {
        status.textContent = text;
        if (error) { status.setAttribute('data-error', ''); }
        else { status.removeAttribute('data-error'); }
    }
    function releaseUrl() {
        if (imageUrl) { URL.revokeObjectURL(imageUrl); imageUrl = null; }
    }
    function reset() {
        generation++;
        conversion++;
        releaseUrl();
        image = null;
        pending = false;
        busy = false;
        apply.disabled = false;
        input.value = '';
        editor.hidden = true;
        message('');
    }
    function rectangle() {
        var side = Math.min(image.naturalWidth, image.naturalHeight) / Number(zoom.value);
        return { side: side, x: (image.naturalWidth - side) * Number(horizontal.value) / 100,
            y: (image.naturalHeight - side) * Number(vertical.value) / 100 };
    }
    function draw() {
        if (!image) { return; }
        var crop = rectangle();
        context.fillStyle = '#ffffff';
        context.fillRect(0, 0, 512, 512);
        context.drawImage(image, crop.x, crop.y, crop.side, crop.side, 0, 0, 512, 512);
    }
    function changed() {
        if (!image) { return; }
        generation++;
        pending = true;
        draw();
        message('Cliquez sur « Utiliser ce cadrage » pour valider la photo.');
    }
    input.addEventListener('change', function () {
        var file = input.files[0];
        reset();
        if (!file) { return; }
        if (!/^image\/(jpeg|png|webp)$/.test(file.type) || file.size > 10 * 1024 * 1024) {
            message('Choisissez une image JPEG, PNG ou WebP de 10 Mo maximum.', true);
            return;
        }
        var current = generation;
        pending = true;
        message('Chargement de la photo…');
        var candidate = new Image();
        imageUrl = URL.createObjectURL(file);
        candidate.onload = function () {
            if (current !== generation) { return; }
            releaseUrl();
            if (candidate.naturalWidth > 8192 || candidate.naturalHeight > 8192
                || candidate.naturalWidth * candidate.naturalHeight > 24000000) {
                reset();
                message('Cette image est trop grande : 24 mégapixels et 8192 pixels par côté maximum.', true);
                return;
            }
            image = candidate;
            zoom.value = 1;
            horizontal.value = vertical.value = 50;
            if (remove) { remove.checked = false; }
            editor.hidden = false;
            draw();
            message('Ajustez la position et le zoom, puis validez le cadrage.');
        };
        candidate.onerror = function () {
            if (current !== generation) { return; }
            reset();
            message('Impossible de lire cette image.', true);
        };
        candidate.src = imageUrl;
    });
    [zoom, horizontal, vertical].forEach(function (control) { control.addEventListener('input', changed); });
    canvas.addEventListener('pointerdown', function (event) {
        if (!image || (event.pointerType === 'mouse' && event.button !== 0)) { return; }
        drag = { x: event.clientX, y: event.clientY, horizontal: Number(horizontal.value), vertical: Number(vertical.value) };
        canvas.setPointerCapture(event.pointerId);
        canvas.focus();
    });
    canvas.addEventListener('pointermove', function (event) {
        if (!drag || !image) { return; }
        var crop = rectangle();
        var size = canvas.getBoundingClientRect();
        var width = image.naturalWidth - crop.side;
        var height = image.naturalHeight - crop.side;
        if (width > 0) { horizontal.value = Math.max(0, Math.min(100, drag.horizontal - (event.clientX - drag.x) * crop.side / size.width / width * 100)); }
        if (height > 0) { vertical.value = Math.max(0, Math.min(100, drag.vertical - (event.clientY - drag.y) * crop.side / size.height / height * 100)); }
        changed();
    });
    ['pointerup', 'pointercancel', 'lostpointercapture'].forEach(function (name) {
        canvas.addEventListener(name, function () { drag = null; });
    });
    canvas.addEventListener('keydown', function (event) {
        if (!image || ['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].indexOf(event.key) === -1) { return; }
        event.preventDefault();
        var control = event.key === 'ArrowLeft' || event.key === 'ArrowRight' ? horizontal : vertical;
        var direction = event.key === 'ArrowLeft' || event.key === 'ArrowUp' ? -1 : 1;
        control.value = Math.max(0, Math.min(100, Number(control.value) + direction * (event.shiftKey ? 10 : 2)));
        changed();
    });
    apply.addEventListener('click', function () {
        if (!image || busy) { return; }
        var current = generation;
        var job = ++conversion;
        busy = true;
        apply.disabled = true;
        draw();
        canvas.toBlob(function (blob) {
            if (job !== conversion) { return; }
            busy = false;
            apply.disabled = false;
            if (current !== generation) { return; }
            if (!blob || blob.size > 524288) { message('Impossible de préparer cette photo. Essayez une autre image.', true); return; }
            try {
                var transfer = new DataTransfer();
                transfer.items.add(new File([blob], 'photo-profil.jpg', { type: 'image/jpeg' }));
                input.files = transfer.files;
                pending = false;
                message('Cadrage validé : 512 × 512 pixels. Enregistrez les modifications pour mettre à jour votre photo.');
            } catch (error) {
                pending = true;
                message('Votre navigateur ne permet pas de préparer le fichier. Essayez un navigateur plus récent.', true);
            }
        }, 'image/jpeg', 0.9);
    });
    cancel.addEventListener('click', reset);
    if (remove) { remove.addEventListener('change', function () { if (remove.checked) { reset(); } }); }
    form.addEventListener('submit', function (event) {
        if (pending || busy) {
            event.preventDefault();
            message('Validez le cadrage ou annulez la sélection de photo avant d’enregistrer.', true);
            if (!editor.hidden) { apply.focus(); }
        }
    });
    window.addEventListener('pagehide', releaseUrl);
}());
