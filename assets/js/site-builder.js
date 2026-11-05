/* Public site behaviour: mobile menu, hero slider, newsletter forms. */
(function () {
  'use strict';

  var toggle = document.querySelector('.menu-toggle');
  if (toggle) {
    toggle.addEventListener('click', function () {
      var nav = document.querySelector('.public-header nav');
      if (nav) nav.classList.toggle('open');
    });
  }

  document.querySelectorAll('[data-slider]').forEach(function (slider) {
    var slides = Array.prototype.slice.call(slider.querySelectorAll('.hs-slide'));
    var dots = Array.prototype.slice.call(slider.querySelectorAll('.hs-dots button'));
    if (slides.length < 2) return;
    var current = 0;
    var timer = null;
    var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function show(index) {
      current = (index + slides.length) % slides.length;
      slides.forEach(function (slide, i) {
        var active = i === current;
        slide.classList.toggle('is-active', active);
        slide.setAttribute('aria-hidden', active ? 'false' : 'true');
        slide.querySelectorAll('a,button').forEach(function (el) { el.tabIndex = active ? 0 : -1; });
        var video = slide.querySelector('video');
        if (video) { if (active) { var p = video.play(); if (p && p.catch) p.catch(function () {}); } else { video.pause(); } }
      });
      dots.forEach(function (dot, i) { dot.setAttribute('aria-current', i === current ? 'true' : 'false'); });
    }
    function start() { if (!reduced) { stop(); timer = setInterval(function () { show(current + 1); }, 7000); } }
    function stop() { if (timer) { clearInterval(timer); timer = null; } }

    var prev = slider.querySelector('.hs-prev');
    var next = slider.querySelector('.hs-next');
    if (prev) prev.addEventListener('click', function () { show(current - 1); start(); });
    if (next) next.addEventListener('click', function () { show(current + 1); start(); });
    dots.forEach(function (dot, i) { dot.addEventListener('click', function () { show(i); start(); }); });
    slider.addEventListener('mouseenter', stop);
    slider.addEventListener('mouseleave', start);
    slider.addEventListener('focusin', stop);
    slider.addEventListener('focusout', start);
    show(0);
    start();
  });

  document.querySelectorAll('form.site-newsletter').forEach(function (form) {
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      var message = form.querySelector('[aria-live]');
      fetch('/includes/newsletter.php', { method: 'POST', body: new FormData(form) })
        .then(function (response) { return response.json(); })
        .then(function (result) {
          if (message) message.textContent = result.message || 'Thank you.';
          if (result.success) form.reset();
        })
        .catch(function () { if (message) message.textContent = 'Unable to subscribe right now. Please try again later.'; });
    });
  });
  document.querySelectorAll('form[data-enquiry-form]').forEach(function (form) {
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      var status = form.querySelector('.ef-status');
      var button = form.querySelector('button[type="submit"]');
      if (!form.checkValidity()) { form.reportValidity(); return; }
      status.textContent = 'Sending…';
      status.className = 'ef-status';
      button.disabled = true;
      fetch('/includes/site_enquiry.php', { method: 'POST', body: new FormData(form) })
        .then(function (response) { return response.json(); })
        .then(function (result) {
          status.textContent = result.message || 'Thank you.';
          status.className = 'ef-status ' + (result.success ? 'is-success' : 'is-error');
          if (result.success) form.reset();
        })
        .catch(function () {
          status.textContent = 'We could not send your message right now. Please email us instead.';
          status.className = 'ef-status is-error';
        })
        .then(function () { button.disabled = false; });
    });
  });
})();
