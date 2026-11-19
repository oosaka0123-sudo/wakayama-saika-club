(function () {
  'use strict';

  // 人物が写る写真の一般公開許可は未確認。公開前に false にすると data-people の写真を取り除く。
  var SHOW_PEOPLE_PHOTOS = true;

  var reduce = window.matchMedia('(prefers-reduced-motion: reduce)');

  if (!SHOW_PEOPLE_PHOTOS) {
    document.querySelectorAll('[data-people]').forEach(function (el) { el.remove(); });
  }

  // Small, icon-only share control shared across all WSC page headers.
  (function initWscShare() {
    var bar = document.querySelector('.site-header .in');
    if (!bar || bar.querySelector('.wsc-share-btn')) return;

    var button = document.createElement('button');
    button.type = 'button';
    button.className = 'wsc-share-btn';
    button.setAttribute('aria-label', 'このページを共有');
    button.innerHTML =
      '<svg class="wsc-share-symbol" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
        '<circle cx="18" cy="5" r="2.2"/><circle cx="6" cy="12" r="2.2"/><circle cx="18" cy="19" r="2.2"/>' +
        '<path d="m8.1 10.8 7.8-4.6M8.1 13.2l7.8 4.6"/>' +
      '</svg>' +
      '<svg class="wsc-share-copied" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
        '<path d="m5 12.4 4.5 4.5L19 7.5"/>' +
      '</svg>';

    var status = document.createElement('span');
    status.className = 'wsc-share-sr';
    status.setAttribute('role', 'status');
    bar.appendChild(status);
    var menu = bar.querySelector('.menu-btn');
    if (menu) bar.insertBefore(button, menu);
    else bar.appendChild(button);

    var resetTimer = null;
    function copied() {
      button.classList.add('is-copied');
      button.setAttribute('aria-label', 'リンクをコピーしました');
      status.textContent = 'リンクをコピーしました';
      if (resetTimer) clearTimeout(resetTimer);
      resetTimer = setTimeout(function () {
        button.classList.remove('is-copied');
        button.setAttribute('aria-label', 'このページを共有');
        status.textContent = '';
      }, 2000);
    }
    async function copyUrl(url) {
      if (navigator.clipboard && navigator.clipboard.writeText) {
        await navigator.clipboard.writeText(url);
        return true;
      }
      var input = document.createElement('textarea');
      input.value = url;
      input.style.position = 'fixed';
      input.style.opacity = '0';
      document.body.appendChild(input);
      input.select();
      var ok = false;
      try { ok = document.execCommand('copy'); } finally { input.remove(); }
      return ok;
    }
    button.addEventListener('click', async function () {
      var canonical = document.querySelector('link[rel="canonical"]');
      var url = (canonical && canonical.href) || (location.origin + location.pathname);
      if (typeof navigator.share === 'function') {
        try {
          await navigator.share({title: document.title, url: url});
          return;
        } catch (err) {
          if (err && err.name === 'AbortError') return;
          // Unsupported share context: fall back to copying the same clean URL.
        }
      }
      try {
        if (await copyUrl(url)) copied();
        else status.textContent = 'リンクのコピーに対応していません';
      } catch (err) {
        status.textContent = 'リンクのコピーに対応していません';
      }
    });
  })();

  // ---- mobile menu ----
  var btn = document.getElementById('menu-btn');
  var drawer = document.getElementById('drawer');
  function setMenu(open) {
    btn.setAttribute('aria-expanded', String(open));
    btn.setAttribute('aria-label', open ? 'メニューを閉じる' : 'メニューを開く');
    drawer.classList.toggle('open', open);
    if (open) { drawer.removeAttribute('inert'); } else { drawer.setAttribute('inert', ''); }
  }
  if (btn && drawer) {
    setMenu(false);
    btn.addEventListener('click', function () { setMenu(btn.getAttribute('aria-expanded') !== 'true'); });
    drawer.addEventListener('click', function (e) { if (e.target.closest('a')) setMenu(false); });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && btn.getAttribute('aria-expanded') === 'true') { setMenu(false); btn.focus(); }
    });
  }

  // ---- sliders (scroll-snap: native swipe + buttons + keys + dots) ----
  document.querySelectorAll('[data-slider]').forEach(function (root) {
    var track = root.querySelector('.track');
    var slides = Array.prototype.slice.call(track.children);
    var prev = root.querySelector('.prev');
    var next = root.querySelector('.next');
    var dotsBox = root.querySelector('.dots');
    var pause = root.querySelector('.pause');
    var autoMs = Number(root.getAttribute('data-autoplay')) || 0;
    var timer = null;
    var userPaused = false;
    var dots = [];
    var index = 0;

    function behavior() { return reduce.matches ? 'auto' : 'smooth'; }
    function go(i) {
      var n = slides.length;
      index = (i + n) % n;
      track.scrollTo({ left: slides[index].offsetLeft - track.offsetLeft, behavior: behavior() });
    }
    function mark(i) {
      index = i;
      dots.forEach(function (d, k) { d.setAttribute('aria-current', k === i ? 'true' : 'false'); });
      slides.forEach(function (s, k) { s.setAttribute('aria-label', (k + 1) + ' / ' + slides.length); });
    }

    if (dotsBox) {
      slides.forEach(function (_, i) {
        var d = document.createElement('button');
        d.type = 'button';
        d.setAttribute('aria-label', (i + 1) + '枚目を表示');
        d.addEventListener('click', function () { go(i); });
        dotsBox.appendChild(d);
        dots.push(d);
      });
    }
    mark(0);

    if (prev) prev.addEventListener('click', function () { go(index - 1); });
    if (next) next.addEventListener('click', function () { go(index + 1); });

    var raf = 0;
    track.addEventListener('scroll', function () {
      cancelAnimationFrame(raf);
      raf = requestAnimationFrame(function () {
        var w = slides[0].getBoundingClientRect().width || 1;
        var i = Math.round(track.scrollLeft / w);
        i = Math.max(0, Math.min(slides.length - 1, i));
        if (i !== index) mark(i);
      });
    }, { passive: true });

    track.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowLeft') { e.preventDefault(); go(index - 1); }
      else if (e.key === 'ArrowRight') { e.preventDefault(); go(index + 1); }
      else if (e.key === 'Home') { e.preventDefault(); go(0); }
      else if (e.key === 'End') { e.preventDefault(); go(slides.length - 1); }
    });

    // autoplay (hero only). Never runs under prefers-reduced-motion.
    function stop() { if (timer) { clearInterval(timer); timer = null; } }
    function start() {
      stop();
      if (!autoMs || userPaused || reduce.matches || document.hidden) return;
      timer = setInterval(function () { go(index + 1); }, autoMs);
    }
    function syncPause() {
      if (!pause) return;
      var running = !!timer;
      pause.textContent = running ? '一時停止' : '再生';
      pause.setAttribute('aria-label', running ? 'スライドの自動切替を一時停止' : 'スライドの自動切替を再生');
    }
    if (autoMs) {
      if (pause) {
        if (reduce.matches) { pause.hidden = true; }
        pause.addEventListener('click', function () { userPaused = !userPaused; start(); syncPause(); });
      }
      root.addEventListener('focusin', stop);
      root.addEventListener('focusout', function () { start(); syncPause(); });
      root.addEventListener('pointerdown', stop);
      root.addEventListener('pointerup', function () { start(); syncPause(); });
      root.addEventListener('pointercancel', function () { start(); syncPause(); });
      document.addEventListener('visibilitychange', function () { start(); syncPause(); });
      var onMq = function () { if (pause) pause.hidden = reduce.matches; start(); syncPause(); };
      if (reduce.addEventListener) reduce.addEventListener('change', onMq);
      start();
      syncPause();
    }
  });

  // WSC expressive scroll-reveal: alternating drift, lift and gentle scale across every page.
  // Progressive enhancement: content is visible without JS / IntersectionObserver.
  (function () {
    if (!('IntersectionObserver' in window) || reduce.matches) return;
    var selector = '.cards > .card, .stats > div, .events > li, .fees > .fee, .album-grid > .photo-card, .contact-panel, .contact-trust, .contact-intro, .value-grid > .value-card, .story-grid > figure, .story-copy, .sticker-frame';
    var cards = Array.prototype.slice.call(document.querySelectorAll(selector));
    if (!cards.length) return;

    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        entry.target.classList.add('wsc-reveal-visible');
        observer.unobserve(entry.target);
      });
    }, { threshold: 0.08, rootMargin: '0px 0px -18px 0px' });

    cards.forEach(function (card, index) {
      // Moderate stagger and alternating left/right entrance, including about page cards.
      card.style.setProperty('--wsc-reveal-delay', ((index % 3) * 100) + 'ms');
      card.style.setProperty('--wsc-reveal-x', (index % 2 === 0 ? '-18px' : '18px'));
      card.style.setProperty('--wsc-reveal-rotate', (index % 2 === 0 ? '-1.1deg' : '1.1deg'));
      card.classList.add('wsc-reveal-card');
      observer.observe(card);
    });

    // Respect motion-setting changes while the page remains open.
    if (reduce.addEventListener) reduce.addEventListener('change', function () {
      if (!reduce.matches) return;
      cards.forEach(function (card) {
        card.classList.add('wsc-reveal-visible');
        observer.unobserve(card);
      });
      observer.disconnect();
    });
  })();

})();
