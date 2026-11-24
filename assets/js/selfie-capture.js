/*
 * Selfie capture for profile photos.
 *
 * Markup (see clients/index.php and clients/profile.php):
 *   <div class="selfie-box" data-selfie>
 *     <div class="selfie-preview"><img alt="" hidden><i class="fas fa-user"></i></div>
 *     <div class="selfie-body">
 *       <button type="button" class="btn ..." data-selfie-start>Take a selfie</button>
 *       <div class="selfie-help" data-selfie-help>...</div>
 *     </div>
 *     <input type="file" class="selfie-input" name="profile_img" accept="image/jpeg,image/png" capture="user" required>
 *   </div>
 *
 * With camera access (HTTPS, getUserMedia) a live front-camera view opens and
 * the snapshot is put into the file input, so the form uploads it as usual.
 * Otherwise the file input opens; on phones capture="user" opens the camera.
 */
(function () {
    'use strict';

    var SIZE = 720; // saved photo is SIZE x SIZE pixels

    function canUseLiveCamera() {
        return !!(window.isSecureContext && navigator.mediaDevices && navigator.mediaDevices.getUserMedia
            && typeof DataTransfer === 'function' && HTMLCanvasElement.prototype.toBlob);
    }

    function setPreview(box, file) {
        var img = box.querySelector('.selfie-preview img');
        var icon = box.querySelector('.selfie-preview i');
        var start = box.querySelector('[data-selfie-start]');
        var help = box.querySelector('[data-selfie-help]');
        if (!file) return;
        if (img) {
            if (img.dataset.url) URL.revokeObjectURL(img.dataset.url);
            img.dataset.url = URL.createObjectURL(file);
            img.src = img.dataset.url;
            img.hidden = false;
        }
        if (icon) icon.hidden = true;
        box.classList.add('has-selfie');
        if (start) start.innerHTML = '<i class="fas fa-redo"></i> Retake selfie';
        if (help) help.textContent = 'Selfie added. Make sure your face is clear, then continue.';
    }

    /* ---------- Live camera dialog (one, shared) ---------- */
    var cam = null;

    function buildCamera() {
        if (cam) return cam;
        var el = document.createElement('div');
        el.className = 'selfie-cam';
        el.setAttribute('role', 'dialog');
        el.setAttribute('aria-modal', 'true');
        el.setAttribute('aria-label', 'Take a selfie');
        el.hidden = true;
        el.innerHTML =
            '<div class="selfie-cam-box">' +
            '  <div class="selfie-cam-title">Take a selfie</div>' +
            '  <div class="selfie-cam-stage">' +
            '    <video playsinline muted autoplay></video>' +
            '    <canvas hidden></canvas>' +
            '    <div class="selfie-cam-guide" aria-hidden="true"></div>' +
            '  </div>' +
            '  <div class="selfie-cam-msg">Face the camera in good light, with your whole face inside the circle.</div>' +
            '  <div class="selfie-cam-actions">' +
            '    <button type="button" class="btn btn-ghost" data-cam="cancel">Cancel</button>' +
            '    <button type="button" class="btn btn-primary" data-cam="snap"><i class="fas fa-camera"></i> Take photo</button>' +
            '    <button type="button" class="btn btn-ghost" data-cam="retake" hidden><i class="fas fa-redo"></i> Retake</button>' +
            '    <button type="button" class="btn btn-primary" data-cam="use" hidden><i class="fas fa-check"></i> Use this photo</button>' +
            '  </div>' +
            '</div>';
        document.body.appendChild(el);
        cam = {
            el: el,
            video: el.querySelector('video'),
            canvas: el.querySelector('canvas'),
            msg: el.querySelector('.selfie-cam-msg'),
            btn: function (name) { return el.querySelector('[data-cam="' + name + '"]'); },
            stream: null,
            box: null,
            blob: null
        };
        el.addEventListener('click', function (e) {
            var b = e.target.closest('[data-cam]');
            if (!b) return;
            var action = b.getAttribute('data-cam');
            if (action === 'cancel') closeCamera();
            if (action === 'snap') snap();
            if (action === 'retake') showLive();
            if (action === 'use') usePhoto();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && cam && !cam.el.hidden) closeCamera();
        });
        return cam;
    }

    function showLive() {
        cam.blob = null;
        cam.video.hidden = false;
        cam.canvas.hidden = true;
        cam.btn('snap').hidden = false;
        cam.btn('retake').hidden = true;
        cam.btn('use').hidden = true;
        cam.btn('snap').focus();
    }

    function openCamera(box) {
        buildCamera();
        cam.box = box;
        cam.msg.textContent = 'Starting the camera...';
        cam.el.hidden = false;
        document.body.style.overflow = 'hidden';
        showLive();
        navigator.mediaDevices.getUserMedia({
            video: { facingMode: 'user', width: { ideal: 1280 }, height: { ideal: 1280 } },
            audio: false
        }).then(function (stream) {
            cam.stream = stream;
            cam.video.srcObject = stream;
            cam.msg.textContent = 'Face the camera in good light, with your whole face inside the circle.';
        }).catch(function () {
            // No camera or permission refused: use the device's own picker/camera.
            closeCamera();
            var input = box.querySelector('.selfie-input');
            if (input) input.click();
        });
    }

    function stopStream() {
        if (cam && cam.stream) {
            cam.stream.getTracks().forEach(function (t) { t.stop(); });
            cam.stream = null;
        }
    }

    function closeCamera() {
        if (!cam) return;
        stopStream();
        cam.video.srcObject = null;
        cam.el.hidden = true;
        document.body.style.overflow = '';
        var start = cam.box && cam.box.querySelector('[data-selfie-start]');
        if (start) start.focus();
    }

    function snap() {
        var v = cam.video;
        if (!v.videoWidth) {
            cam.msg.textContent = 'The camera is not ready yet. Please wait a moment.';
            return;
        }
        // Centre square crop.
        var side = Math.min(v.videoWidth, v.videoHeight);
        var sx = (v.videoWidth - side) / 2;
        var sy = (v.videoHeight - side) / 2;
        var c = cam.canvas;
        c.width = SIZE;
        c.height = SIZE;
        // Saved the right way round (not mirrored) so it can be used to
        // identify the client; only the live view is mirrored (CSS).
        c.getContext('2d').drawImage(v, sx, sy, side, side, 0, 0, SIZE, SIZE);
        c.toBlob(function (blob) {
            if (!blob) {
                cam.msg.textContent = 'Could not take the photo. Please try again.';
                return;
            }
            cam.blob = blob;
            v.hidden = true;
            c.hidden = false;
            cam.btn('snap').hidden = true;
            cam.btn('retake').hidden = false;
            cam.btn('use').hidden = false;
            cam.msg.textContent = 'Is your face clear? Use this photo or take another.';
            cam.btn('use').focus();
        }, 'image/jpeg', 0.88);
    }

    function usePhoto() {
        if (!cam.blob || !cam.box) return;
        var input = cam.box.querySelector('.selfie-input');
        var file = new File([cam.blob], 'selfie.jpg', { type: 'image/jpeg', lastModified: Date.now() });
        var dt = new DataTransfer();
        dt.items.add(file);
        input.files = dt.files;
        input.dispatchEvent(new Event('change', { bubbles: true }));
        closeCamera();
    }

    /* ---------- Wire up each selfie box ---------- */
    function init(box) {
        if (box.dataset.selfieReady) return;
        box.dataset.selfieReady = '1';
        var input = box.querySelector('.selfie-input');
        var start = box.querySelector('[data-selfie-start]');
        if (!input || !start) return;

        start.addEventListener('click', function () {
            if (canUseLiveCamera()) {
                openCamera(box);
            } else {
                input.click();
            }
        });

        input.addEventListener('change', function () {
            var file = input.files && input.files[0];
            if (!file) return;
            if (!/^image\/(jpeg|png)$/.test(file.type)) {
                var help = box.querySelector('[data-selfie-help]');
                if (help) help.textContent = 'Please use a JPG or PNG photo.';
                input.value = '';
                return;
            }
            input.classList.remove('is-invalid');
            setPreview(box, file);
            box.dispatchEvent(new CustomEvent('selfie:change', { bubbles: true }));
        });
    }

    function initAll() {
        document.querySelectorAll('[data-selfie]').forEach(init);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }
    window.SelfieCapture = { init: initAll };
})();
