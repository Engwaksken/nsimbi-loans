/* Website CMS admin: modal forms, section field toggling, media and font previews. */
(function () {
  'use strict';

  function openModal(modal) {
    if (window.Modal && typeof window.Modal.open === 'function') window.Modal.open(modal.id);
    else { modal.classList.add('open'); document.body.style.overflow = 'hidden'; }
    modal.setAttribute('aria-hidden', 'false');
    var first = modal.querySelector('.modal-body input:not([type=hidden]):not([readonly]), .modal-body select, .modal-body textarea');
    if (first) setTimeout(function () { first.focus(); }, 60);
  }

  function closeModal(modal) {
    if (window.Modal && typeof window.Modal.close === 'function') window.Modal.close(modal.id);
    else { modal.classList.remove('open'); document.body.style.overflow = ''; }
    modal.setAttribute('aria-hidden', 'true');
  }

  function fillForm(form, record) {
    form.reset();
    Object.keys(record || {}).forEach(function (name) {
      var field = form.elements[name];
      if (!field || (field.type === 'file')) return;
      var value = record[name] === null || record[name] === undefined ? '' : String(record[name]);
      if (field.type === 'checkbox') field.checked = value === '1' || value === 'true';
      else field.value = value;
    });
    // Unchecked boxes default to checked only for new records.
    if (record && record.is_active !== undefined && form.elements.is_active) {
      form.elements.is_active.checked = String(record.is_active) === '1' || record.is_active === true;
    }
    var slug = form.elements.slug;
    if (slug) {
      slug.readOnly = slug.value === 'home';
      slug.dataset.touched = slug.value ? '1' : '';
    }
    form.querySelectorAll('[data-type-select],[data-media-kind]').forEach(function (el) {
      el.dispatchEvent(new Event('change', { bubbles: true }));
    });
    form.querySelectorAll('[data-media-preview]').forEach(function (box) {
      var field = form.elements[box.dataset.mediaPreview];
      renderPreview(box, field ? field.value : '');
    });
    updateSlugPreview(form);
  }

  function renderPreview(box, url) {
    box.innerHTML = '';
    if (!url) return;
    var wrap = document.createElement('div');
    wrap.className = 'cms-media-current';
    if (/\.(mp4|webm)(\?|$)/i.test(url)) {
      var video = document.createElement('video');
      video.src = url; video.muted = true; video.preload = 'metadata';
      wrap.appendChild(video);
    } else if (/youtube\.com|youtu\.be|vimeo\.com/i.test(url)) {
      var icon = document.createElement('i'); icon.className = 'fas fa-film'; wrap.appendChild(icon);
    } else {
      var img = document.createElement('img'); img.src = url; img.alt = ''; wrap.appendChild(img);
    }
    var label = document.createElement('span');
    label.textContent = 'Current: ' + url.split('/').pop();
    wrap.appendChild(label);
    box.appendChild(wrap);
  }

  function updateSlugPreview(form) {
    var slug = form.elements.slug;
    var preview = form.querySelector('[data-slug-preview]');
    if (slug && preview) preview.textContent = slug.value === 'home' ? '' : (slug.value || 'about-us');
  }

  function slugify(text) {
    return text.toLowerCase().normalize('NFKD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
  }

  document.addEventListener('click', function (event) {
    var opener = event.target.closest('[data-modal-open]');
    if (opener) {
      var modal = document.getElementById(opener.dataset.modalOpen);
      if (!modal) return;
      var form = modal.querySelector('form');
      var record = {};
      try { record = JSON.parse(opener.dataset.record || '{}'); } catch (e) { record = {}; }
      if (form) fillForm(form, record);
      var title = modal.querySelector('.modal-title-text');
      if (title && opener.dataset.modalTitle) title.textContent = opener.dataset.modalTitle;
      openModal(modal);
      return;
    }
    var closer = event.target.closest('[data-modal-close]');
    if (closer) {
      var parent = closer.closest('.modal-overlay');
      if (parent) closeModal(parent);
      return;
    }
    if (event.target.classList && event.target.classList.contains('modal-overlay')) closeModal(event.target);
  });

  document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape') return;
    document.querySelectorAll('.modal-overlay.open').forEach(closeModal);
  });

  document.addEventListener('input', function (event) {
    var form = event.target.form;
    if (!form) return;
    if (event.target.matches('[data-slug-source]') && form.elements.slug && !form.elements.slug.dataset.touched && !form.elements.slug.readOnly) {
      form.elements.slug.value = slugify(event.target.value);
      updateSlugPreview(form);
    }
    if (event.target.matches('[data-slug-target]')) {
      event.target.dataset.touched = event.target.value ? '1' : '';
      updateSlugPreview(form);
    }
  });

  document.addEventListener('change', function (event) {
    var el = event.target;
    var form = el.form;

    if (el.matches('[data-type-select]') && form) {
      var option = el.options[el.selectedIndex];
      var fields = (option.dataset.fields || '').split(' ');
      form.querySelectorAll('[data-field]').forEach(function (group) {
        group.hidden = fields.indexOf(group.dataset.field) === -1;
      });
      form.querySelectorAll('[data-for-type]').forEach(function (help) {
        help.hidden = help.dataset.forType !== el.value;
      });
      var help = form.querySelector('[data-type-help]');
      if (help) help.textContent = option.dataset.help || '';
    }

    if (el.matches('[data-media-kind]') && form && el.checked) {
      var inputs = form.querySelector('[data-media-inputs]');
      if (inputs) inputs.hidden = el.value === 'none';
      var file = form.querySelector('input[type=file][data-accept-image]');
      if (file) file.accept = el.value === 'video' ? file.dataset.acceptVideo : file.dataset.acceptImage;
    }

    if (el.matches('input[type=file]')) {
      var target = document.querySelector('[data-file-preview="' + el.name + '"]');
      if (target && el.files && el.files[0] && /^image\//.test(el.files[0].type)) {
        target.src = URL.createObjectURL(el.files[0]);
      }
    }

    if (el.matches('[data-font-preview]')) {
      var sample = document.querySelector('[data-font-sample="' + el.dataset.fontPreview + '"]');
      if (sample) sample.style.fontFamily = el.options[el.selectedIndex].dataset.stack;
    }
  });
})();
