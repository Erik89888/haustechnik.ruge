/* ════════════════════════════════════════════════════════════════════
   mobile.js · Handy-Verhalten für alle Seiten (Gegenstück zu mobile.css)
   Wird mit "defer" als letztes Script geladen. Alles hier wirkt nur auf
   Smartphones (≤ 768 px); auf dem Desktop fügt es nichts ein.
   ════════════════════════════════════════════════════════════════════ */
(function () {
  var mqMobile = window.matchMedia('(max-width: 768px)');
  var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var hasIO = 'IntersectionObserver' in window;

  /* ── Aktionsleiste erst einblenden, wenn die Hero-Buttons aus dem Bild sind ──
     (vorher lagen Leiste und Hero-Buttons doppelt übereinander) */
  var bar = document.querySelector('.action-bar');
  var heroActions = document.querySelector('.hero-actions, .svc-hero-actions');
  if (bar && heroActions && hasIO) {
    new IntersectionObserver(function (entries) {
      bar.classList.toggle('hero-vis', entries[0].isIntersecting && mqMobile.matches);
    }).observe(heroActions);
  }

  /* ── Handy-Menü: aktuelle Seite orange markieren (nicht jede Seite hatte das) ── */
  var mqNav = window.matchMedia('(max-width: 768px), (max-width: 1024px) and (hover: none) and (pointer: coarse)');
  var mobileNav = document.getElementById('mobileNav');
  if (mobileNav && mqNav.matches && !mobileNav.querySelector('a[aria-current]')) {
    var page = (location.pathname.split('/').pop() || 'index.html').toLowerCase();
    Array.prototype.forEach.call(mobileNav.querySelectorAll(':scope > a'), function (a) {
      var target = (a.getAttribute('href') || '').split('#')[0].toLowerCase();
      if (target && target === page) a.setAttribute('aria-current', 'page');
    });
  }

  /* ── Startseite: Leistungs-Karussell mit Punkten (wie bei den Referenzen) ── */
  var grid = document.querySelector('.leistungen-grid');
  if (!grid) return;
  var cards = grid.querySelectorAll('.l-card');
  var dots = null, built = false;

  function current() {
    if (cards.length < 2) return 0;
    var step = cards[1].offsetLeft - cards[0].offsetLeft;
    var max = grid.scrollWidth - grid.clientWidth;
    return grid.scrollLeft >= max - 4 ? cards.length - 1 : Math.round(grid.scrollLeft / step);
  }
  function update() {
    if (!built) return;
    var idx = current();
    Array.prototype.forEach.call(dots.children, function (b, i) { b.classList.toggle('active', i === idx); });
    Array.prototype.forEach.call(cards, function (c, i) { c.classList.toggle('is-cur', i === idx); });
  }

  function build() {
    if (built || !mqMobile.matches) return;
    built = true;
    dots = document.createElement('div');
    dots.className = 'ref-dots';
    Array.prototype.forEach.call(cards, function (card, i) {
      var b = document.createElement('button');
      b.type = 'button';
      b.setAttribute('aria-label', 'Leistung ' + (i + 1) + ' anzeigen');
      b.addEventListener('click', function () {
        grid.scrollTo({ left: card.offsetLeft - cards[0].offsetLeft, behavior: reduce ? 'auto' : 'smooth' });
      });
      dots.appendChild(b);
    });
    grid.parentNode.insertBefore(dots, grid.nextSibling);
    update();

    // Einmaliger „Wisch mich“-Hinweis, sobald das Karussell im Bild ist
    if (hasIO && !reduce) {
      var nudge = new IntersectionObserver(function (entries) {
        if (!entries[0].isIntersecting || !mqMobile.matches || grid.scrollLeft > 0) return;
        nudge.disconnect();
        grid.style.scrollSnapType = 'none';
        setTimeout(function () { grid.scrollBy({ left: 70, behavior: 'smooth' }); }, 500);
        setTimeout(function () { grid.scrollTo({ left: 0, behavior: 'smooth' }); }, 1150);
        setTimeout(function () { grid.style.scrollSnapType = ''; }, 1900);
      }, { threshold: 0.6 });
      nudge.observe(grid);
    }
  }

  function teardown() {
    if (!built) return;
    built = false;
    if (dots && dots.parentNode) dots.parentNode.removeChild(dots);
    dots = null;
    Array.prototype.forEach.call(cards, function (c) { c.classList.remove('is-cur'); });
  }

  build();
  grid.addEventListener('scroll', function () { requestAnimationFrame(update); }, { passive: true });
  window.addEventListener('resize', update);
  var onChange = function () { if (mqMobile.matches) build(); else teardown(); };
  if (mqMobile.addEventListener) mqMobile.addEventListener('change', onChange);
  else if (mqMobile.addListener) mqMobile.addListener(onChange);
})();
