/* Run with Node: node tests/avatar-crop.test.js. No external dependencies. */
function runAvatarCropTests(source) {
    'use strict';
    var passed = [];
    function check(condition, label) {
        if (!condition) { throw new Error(label); }
        passed.push(label);
    }
    function element(value) {
        return {
            value: value || '', hidden: false, disabled: false, checked: false,
            handlers: {}, attributes: {},
            addEventListener: function (name, handler) { this.handlers[name] = handler; },
            emit: function (name, extra) {
                var event = Object.assign({ prevented: false, preventDefault: function () { this.prevented = true; } }, extra);
                if (this.handlers[name]) { this.handlers[name](event); }
                return event;
            },
            setAttribute: function (name, content) { this.attributes[name] = content; },
            removeAttribute: function (name) { delete this.attributes[name]; },
            focus: function () {}, setPointerCapture: function () {},
            getBoundingClientRect: function () { return { width: 256, height: 256 }; }
        };
    }
    var nodes = {};
    ['avatar', 'avatar-crop', 'avatar-canvas', 'avatar-zoom', 'avatar-horizontal', 'avatar-vertical', 'avatar-apply', 'avatar-cancel', 'avatar-status'].forEach(function (name) { nodes[name] = element(); });
    var input = nodes.avatar;
    input.files = [];
    Object.defineProperty(input, 'value', { get: function () { return ''; }, set: function () { this.files = []; } });
    var form = element();
    var remove = element();
    form.querySelector = function () { return remove; };
    input.form = form;
    var lastDraw;
    var conversions = [];
    nodes['avatar-canvas'].getContext = function () {
        return { fillRect: function () {}, drawImage: function () { lastDraw = Array.from(arguments); } };
    };
    nodes['avatar-canvas'].toBlob = function (callback, type) { conversions.push(function () { callback({ size: 30000, type: type }); }); };
    var images = [];
    function ImageMock() { this.naturalWidth = 1600; this.naturalHeight = 900; images.push(this); }
    function TransferMock() {
        var transfer = this; transfer.files = [];
        transfer.items = { add: function (file) { transfer.files.push(file); } };
    }
    function FileMock(parts, name, options) { this.name = name; this.type = options.type; this.size = parts[0].size; }
    var released = 0;
    var URLMock = { createObjectURL: function () { return 'blob:test'; }, revokeObjectURL: function () { released++; } };
    var windowMock = { DataTransfer: TransferMock, addEventListener: function () {} };
    var CanvasMock = function () {};
    CanvasMock.prototype.toBlob = function () {};
    new Function('document', 'window', 'HTMLCanvasElement', 'URL', 'Image', 'DataTransfer', 'File', source)(
        { getElementById: function (id) { return nodes[id]; } }, windowMock, CanvasMock, URLMock, ImageMock, TransferMock, FileMock);
    input.files = [{ size: 2000000, type: 'image/jpeg' }];
    input.emit('change');
    check(form.emit('submit').prevented, 'Submitting during image loading is blocked');
    images[0].onload();
    check(!nodes['avatar-crop'].hidden && lastDraw[1] === 350 && lastDraw[2] === 0 && lastDraw[3] === 900, 'Landscape image starts with a centered square crop');
    check(lastDraw[7] === 512 && lastDraw[8] === 512, 'Crop targets exactly 512 by 512 pixels');
    nodes['avatar-zoom'].value = 2;
    nodes['avatar-zoom'].emit('input');
    check(lastDraw[3] === 450 && lastDraw[4] === 450, 'Zoom changes the source crop size');
    nodes['avatar-horizontal'].value = 100;
    nodes['avatar-horizontal'].emit('input');
    check(lastDraw[1] + lastDraw[3] === 1600, 'Horizontal crop remains within image boundaries');
    nodes['avatar-canvas'].emit('keydown', { key: 'ArrowLeft', shiftKey: true });
    check(Number(nodes['avatar-horizontal'].value) === 90, 'Keyboard can reposition the crop');
    nodes['avatar-apply'].emit('click');
    check(form.emit('submit').prevented, 'Submitting during export is blocked');
    conversions.shift()();
    check(input.files[0].name === 'photo-profil.jpg' && input.files[0].type === 'image/jpeg', 'Only the exported JPEG is assigned to the upload field');
    check(!form.emit('submit').prevented, 'Validated crop allows normal account submission');
    nodes['avatar-vertical'].value = 10;
    nodes['avatar-vertical'].emit('input');
    check(form.emit('submit').prevented, 'Adjusting a validated crop requires revalidation');
    nodes['avatar-apply'].emit('click');
    nodes['avatar-horizontal'].emit('input');
    conversions.shift()();
    check(form.emit('submit').prevented, 'An outdated export cannot validate a newer crop');
    nodes['avatar-cancel'].emit('click');
    check(input.files.length === 0 && nodes['avatar-crop'].hidden && !form.emit('submit').prevented, 'Cancel clears the pending upload without blocking other account changes');
    input.files = [{ size: 100, type: 'image/svg+xml' }];
    input.emit('change');
    check(input.files.length === 0 && images.length === 1, 'SVG is rejected before image decoding');
    input.files = [{ size: 11 * 1024 * 1024, type: 'image/jpeg' }];
    input.emit('change');
    check(input.files.length === 0 && images.length === 1, 'Oversized source files are rejected');
    input.files = [{ size: 100, type: 'image/png' }];
    input.emit('change');
    images[1].naturalWidth = images[1].naturalHeight = 10000;
    images[1].onload();
    check(nodes['avatar-crop'].hidden && input.files.length === 0, 'Excessive image dimensions are rejected');
    check(released === 2, 'Image object URLs are released after decoding');
    return passed;
}
if (typeof module !== 'undefined') {
    module.exports = runAvatarCropTests;
    if (require.main === module) {
        var source = require('fs').readFileSync(require('path').join(__dirname, '../public/js/avatar-crop.js'), 'utf8');
        runAvatarCropTests(source).forEach(function (name) { console.log('OK ' + name); });
    }
}
