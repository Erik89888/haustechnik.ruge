/* ════════════════════════════════════════════════════════════════════
   motion.v2.js · Ergänzung zu motion.js für die v2-Unterseiten.
   Läuft (defer) direkt NACH motion.js und kümmert sich nur um die
   v2-Bausteine: Fotobanner mit Titel, Umriss-Nummer, Kachel-Spalte.
   Gegenstück: motion.v2.css.
   ════════════════════════════════════════════════════════════════════ */
(function () {
  var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var touchLike = window.matchMedia('(hover: none)').matches;
  var hasIO = 'IntersectionObserver' in window;
  var each = function (sel, fn, ctx) { Array.prototype.forEach.call((ctx || document).querySelectorAll(sel), fn); };

  // Überschriften im Banner Wort für Wort (gleiche Technik wie in motion.js)
  function splitWords(el) {
    if (el.classList.contains('is-split')) return;
    var i = 0;
    el.setAttribute('aria-label', el.textContent.replace(/\s+/g, ' ').trim());
    Array.prototype.slice.call(el.childNodes).forEach(function (n) {
      if (n.nodeType === 3) {
        var frag = document.createDocumentFragment();
        n.textContent.split(/(\s+)/).forEach(function (part) {
          if (!part) return;
          if (/^\s+$/.test(part)) { frag.appendChild(document.createTextNode(' ')); return; }
          var s = document.createElement('span');
          s.className = 'split-word'; s.style.setProperty('--wi', i++); s.textContent = part;
          frag.appendChild(s);
        });
        el.replaceChild(frag, n);
      } else if (n.nodeType === 1 && n.tagName !== 'BR') {
        var w = document.createElement('span');
        w.className = 'split-word'; w.style.setProperty('--wi', i++);
        // Untertitel (<small>) bleibt eine eigene Zeile unter dem Titel
        if (n.tagName === 'SMALL') w.style.display = 'block';
        el.replaceChild(w, n); w.appendChild(n);
      }
    });
    el.classList.add('is-split');
  }
  if (!reduce && hasIO) each('.svc-title h2, .bereich-title h2', splitWords);

  // Banner: Bild zoomt zurück, Umriss-Nummer gleitet herein (Klasse m-in)
  var banners = document.querySelectorAll('.svc-banner, .bereich-media');
  if (hasIO) {
    var bannerObs = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) { if (e.isIntersecting) { e.target.classList.add('m-in'); bannerObs.unobserve(e.target); } });
    }, { threshold: 0.2 });
    Array.prototype.forEach.call(banners, function (b) { bannerObs.observe(b); });
  } else {
    Array.prototype.forEach.call(banners, function (b) { b.classList.add('m-in'); });
  }

  // Kacheln: was gleichzeitig ins Bild kommt, gleitet nacheinander herein
  if (hasIO && !reduce) {
    var tiles = document.querySelectorAll('.svc-tile, .bereich-tile');
    Array.prototype.forEach.call(tiles, function (t) { t.classList.add('reveal-item'); });
    var tileObs = new IntersectionObserver(function (entries) {
      var k = 0;
      entries.forEach(function (e) {
        if (!e.isIntersecting) return;
        var el = e.target, d = k++ * 90;
        el.style.transitionDelay = d + 'ms';
        el.classList.add('visible');
        tileObs.unobserve(el);
        setTimeout(function () { el.classList.remove('reveal-item', 'visible'); el.style.transitionDelay = ''; }, d + 1100);
      });
    }, { threshold: 0.15, rootMargin: '0px 0px -6% 0px' });
    Array.prototype.forEach.call(tiles, function (t) { tileObs.observe(t); });
  }

  // Touch: Kachel in der Bildschirmmitte zeigt ihren Hover-Zustand
  if (touchLike && hasIO) {
    var centerObs = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) { e.target.classList.toggle('in-view', e.isIntersecting); });
    }, { rootMargin: '-38% 0px -38% 0px' });
    each('.svc-tile, .bereich-tile', function (el) { centerObs.observe(el); });
  }

  // Parallax der Bannerbilder (über "translate", damit Zoom/Hover-"transform" unberührt bleibt)
  if (reduce || !banners.length) return;
  var imgs = [];
  Array.prototype.forEach.call(banners, function (b) {
    var m = b.querySelector(':scope > img, :scope > video');
    if (m) imgs.push([b, m]);
  });
  var ticking = false;
  function frame() {
    ticking = false;
    var vh = window.innerHeight;
    imgs.forEach(function (pair) {
      var r = pair[0].getBoundingClientRect();
      if (r.bottom < 0 || r.top > vh) return;
      var p = (r.top + r.height / 2 - vh / 2) / (vh + r.height); // ca. -0.5 … 0.5
      pair[1].style.translate = '0 ' + (-p * r.height * 0.18).toFixed(1) + 'px';
    });
  }
  function requestFrame() { if (!ticking) { ticking = true; requestAnimationFrame(frame); } }
  window.addEventListener('scroll', requestFrame, { passive: true });
  window.addEventListener('resize', requestFrame);
  frame();
})();
