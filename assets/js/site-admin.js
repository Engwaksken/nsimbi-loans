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

/* Tabs, colour pickers and range sliders for the CMS forms. */
(function () {
  'use strict';

  function tabRoot(el) { return el.closest('[data-tabs-root]') || el.closest('form'); }

  function activateTab(root, name, focus) {
    if (!root) return;
    root.querySelectorAll('.cms-tabs [role="tab"]').forEach(function (tab) {
      var on = tab.dataset.tab === name;
      tab.setAttribute('aria-selected', on ? 'true' : 'false');
      tab.tabIndex = on ? 0 : -1;
      if (on && focus) tab.focus();
    });
    root.querySelectorAll('.cms-tab-panel').forEach(function (panel) {
      panel.hidden = panel.dataset.tabPanel !== name;
    });
  }

  function firstVisibleTab(root) {
    var tab = Array.prototype.find.call(root.querySelectorAll('.cms-tabs [role="tab"]'), function (t) { return !t.hidden; });
    return tab ? tab.dataset.tab : null;
  }

  // Hide a tab when every type-dependent field in its panel is hidden.
  function syncTabVisibility(root) {
    root.querySelectorAll('.cms-tab-panel[data-auto-hide]').forEach(function (panel) {
      var groups = panel.querySelectorAll('[data-field]');
      var anyVisible = Array.prototype.some.call(groups, function (g) { return !g.hidden; });
      var tab = root.querySelector('.cms-tabs [data-tab="' + panel.dataset.tabPanel + '"]');
      if (tab) tab.hidden = !anyVisible;
    });
    var selected = root.querySelector('.cms-tabs [aria-selected="true"]');
    if (selected && selected.hidden) activateTab(root, firstVisibleTab(root));
  }

  document.addEventListener('click', function (event) {
    var tab = event.target.closest('.cms-tabs [role="tab"]');
    if (tab) { activateTab(tabRoot(tab), tab.dataset.tab); return; }

    // Runs after the modal is filled: always start on the first tab.
    var opener = event.target.closest('[data-modal-open]');
    if (opener) {
      var modal = document.getElementById(opener.dataset.modalOpen);
      var form = modal && modal.querySelector('form');
      if (form && form.querySelector('.cms-tabs')) {
        form.querySelectorAll('.cms-tabs .has-error').forEach(function (t) { t.classList.remove('has-error'); });
        syncTabVisibility(form);
        activateTab(form, firstVisibleTab(form));
      }
    }
  });

  document.addEventListener('keydown', function (event) {
    var tab = event.target.closest && event.target.closest('.cms-tabs [role="tab"]');
    if (!tab || (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft')) return;
    var tabs = Array.prototype.filter.call(tab.parentNode.querySelectorAll('[role="tab"]'), function (t) { return !t.hidden; });
    var i = tabs.indexOf(tab) + (event.key === 'ArrowRight' ? 1 : -1);
    var next = tabs[(i + tabs.length) % tabs.length];
    activateTab(tabRoot(tab), next.dataset.tab, true);
    event.preventDefault();
  });

  // A required field on a hidden tab: jump to it so the browser can show the message.
  document.addEventListener('invalid', function (event) {
    var panel = event.target.closest('.cms-tab-panel');
    if (!panel || !panel.hidden) return;
    var root = tabRoot(panel);
    activateTab(root, panel.dataset.tabPanel);
    var tab = root.querySelector('.cms-tabs [data-tab="' + panel.dataset.tabPanel + '"]');
    if (tab) tab.classList.add('has-error');
  }, true);

  document.addEventListener('change', function (event) {
    if (event.target.matches('[data-type-select]') && event.target.form) syncTabVisibility(event.target.form);
  });

  // Colour picker <-> hex text, plus live preview variables.
  function applyColor(key, value) {
    document.querySelectorAll('[data-theme-preview]').forEach(function (el) { el.style.setProperty('--tp-' + key, value); });
  }
  document.addEventListener('input', function (event) {
    var el = event.target;
    if (el.matches('[data-color-picker]')) {
      var text = document.querySelector('[data-color-text="' + el.dataset.colorPicker + '"]');
      if (text) text.value = el.value;
      applyColor(el.dataset.colorPicker, el.value);
    } else if (el.matches('[data-color-text]')) {
      var value = el.value.trim();
      if (/^#[0-9a-f]{6}$/i.test(value)) {
        var picker = document.querySelector('[data-color-picker="' + el.dataset.colorText + '"]');
        if (picker) picker.value = value.toLowerCase();
        applyColor(el.dataset.colorText, value);
      }
    } else if (el.matches('input[type="range"][data-unit]')) {
      var out = document.querySelector('output[for="' + el.id + '"]');
      if (out) out.textContent = el.value + el.dataset.unit;
      document.querySelectorAll('[data-size-sample="' + el.name + '"]').forEach(function (sample) {
        sample.style.fontSize = el.dataset.unit === '%' ? (el.value / 100 * parseFloat(sample.dataset.base)) + 'px' : el.value + 'px';
      });
    }
  });

  document.querySelectorAll('[data-tabs-root]').forEach(function (root) {
    var wanted = (location.hash || '').replace('#', '');
    var exists = wanted && root.querySelector('.cms-tabs [data-tab="' + wanted + '"]');
    activateTab(root, exists ? wanted : firstVisibleTab(root));
  });
})();

/* Header & footer style preview. */
document.addEventListener('change', function (event) {
  var el = event.target;
  if (!el.matches || !el.matches('[data-style-preview]')) return;
  var parts = el.dataset.stylePreview.split('_');
  var target = document.querySelector('[data-hf-preview="' + parts[0] + '"]');
  if (!target) return;
  if (parts[1] === 'weight') target.style.fontWeight = el.value;
  if (parts[1] === 'case') target.style.textTransform = el.value;
  if (parts[1] === 'font') target.style.fontFamily = el.value === 'heading' ? target.dataset.headingStack : target.dataset.bodyStack;
});
