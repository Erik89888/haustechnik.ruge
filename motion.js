/* ════════════════════════════════════════════════════════════════════
   motion.js · Animationen & Mobil-Verhalten für die Unterseiten
   (leistungen, energien, bereiche, referenzen, ueber-uns).
   Wird mit "defer" geladen und läuft damit NACH dem Inline-Script der
   Seite. Menü, Reveal, Sprungmarken und Lightbox der Seite bleiben
   unangetastet, dieses Script ergänzt sie nur. Gegenstück: motion.css.
   ════════════════════════════════════════════════════════════════════ */
(function () {
  var root = document.documentElement;
  var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var finePointer = window.matchMedia('(hover: hover) and (pointer: fine)').matches;
  var touchLike = window.matchMedia('(hover: none)').matches;
  var mqMobile = window.matchMedia('(max-width: 768px)');
  var hasIO = 'IntersectionObserver' in window;
  var each = function (sel, fn, ctx) { Array.prototype.forEach.call((ctx || document).querySelectorAll(sel), fn); };
  root.classList.add('js');
  if (!reduce) root.classList.add('js-parallax');

  var header = document.getElementById('header');
  var mobileNav = document.getElementById('mobileNav');

  // Scroll-Fortschrittsbalken
  var progress = document.createElement('div');
  progress.className = 'scroll-progress';
  progress.setAttribute('aria-hidden', 'true');
  document.body.appendChild(progress);

  // Offenes Menü: Seite dahinter nicht mitscrollen, Header einblenden
  if (mobileNav && window.MutationObserver) {
    new MutationObserver(function () {
      var open = mobileNav.classList.contains('open');
      document.body.classList.toggle('nav-open', open);
      if (open && header) { header.classList.remove('hide'); root.classList.remove('header-hidden'); }
    }).observe(mobileNav, { attributes: true, attributeFilter: ['class'] });
  }

  /* ── Überschriften in Wörter zerlegen ──
     Textknoten werden pro Wort in <span class="split-word"> gepackt, Elemente
     (z. B. <em>, .word-accent) bleiben als Ganzes erhalten, <br> bleibt stehen.
     aria-label hält den Satz für Screenreader zusammen. */
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
        el.replaceChild(w, n); w.appendChild(n);
      }
    });
    el.classList.add('is-split');
  }
  if (!reduce && hasIO) {
    each('.svc-hero-content h1, .svc-head h2, .bereich-head h2, .section-header h2, .cta-section h2, .nibe-copy h2, .funding-inner h2', splitWords);
  }

  // Galerie-Kacheln: Reihenfolge für das gestaffelte Einblenden
  each('.svc-gallery', function (g) {
    each('.gal-item', function (item, i) { item.style.setProperty('--gi', Math.min(i, 10)); }, g);
  });

  // Karten nach dem Einblenden von den Reveal-Klassen befreien, damit ihre
  // eigenen Hover-Übergänge (Lift, Neigung) wieder greifen
  if (window.MutationObserver) {
    each('.b-card.reveal, .q-card.reveal', function (card) {
      var cleanup = function () {
        setTimeout(function () { card.classList.remove('reveal', 'reveal-d1', 'reveal-d2', 'reveal-d3', 'visible'); }, 1500);
      };
      if (card.classList.contains('visible')) { cleanup(); return; }
      var mo = new MutationObserver(function () {
        if (!card.classList.contains('visible')) return;
        mo.disconnect();
        cleanup();
      });
      mo.observe(card, { attributes: true, attributeFilter: ['class'] });
    });
  }

  // Gestaffelte Einzel-Elemente: was im selben Moment ins Bild kommt, erscheint nacheinander
  if (hasIO && !reduce) {
    var items = document.querySelectorAll('.svc-check-list li, .nibe-list li, .svc-facts li, .bereich-detail, .info-band .info-item, .footer-top > div');
    each('.svc-check-list li, .nibe-list li, .svc-facts li, .bereich-detail, .info-band .info-item, .footer-top > div', function (el) { el.classList.add('reveal-item'); });
    var itemObs = new IntersectionObserver(function (entries) {
      var k = 0;
      entries.forEach(function (e) {
        if (!e.isIntersecting) return;
        var el = e.target, d = k++ * 90;
        el.style.transitionDelay = d + 'ms';
        el.classList.add('visible');
        itemObs.unobserve(el);
        setTimeout(function () { el.classList.remove('reveal-item', 'visible'); el.style.transitionDelay = ''; }, d + 900);
      });
    }, { threshold: 0.15, rootMargin: '0px 0px -6% 0px' });
    Array.prototype.forEach.call(items, function (el) { itemObs.observe(el); });
  }

  // Bereiche: Titelbild blendet beim Scrollen ein
  if (hasIO) {
    var mediaObs = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) { if (e.isIntersecting) { e.target.classList.add('m-in'); mediaObs.unobserve(e.target); } });
    }, { threshold: 0.2 });
    each('.bereich-media', function (el) { mediaObs.observe(el); });
  } else {
    each('.bereich-media', function (el) { el.classList.add('m-in'); });
  }

  // FAQ: aria-expanded mit dem Zustand der Seite synchron halten
  var faqBtns = document.querySelectorAll('.faq-q');
  if (faqBtns.length) {
    var syncFaq = function () {
      Array.prototype.forEach.call(faqBtns, function (b) {
        b.setAttribute('aria-expanded', b.closest('.faq-item').classList.contains('open') ? 'true' : 'false');
      });
    };
    syncFaq();
    Array.prototype.forEach.call(faqBtns, function (b) { b.addEventListener('click', function () { setTimeout(syncFaq, 0); }); });
  }

  // Sprungmarken: aktive Marke bleibt in der wischbaren Leiste sichtbar
  var subnavInner = document.querySelector('.svc-subnav-inner');
  if (subnavInner && window.MutationObserver) {
    each('.svc-pill', function (pill) {
      new MutationObserver(function () {
        if (!pill.classList.contains('active') || subnavInner.scrollWidth <= subnavInner.clientWidth) return;
        var left = pill.offsetLeft - subnavInner.offsetLeft - (subnavInner.clientWidth - pill.offsetWidth) / 2;
        subnavInner.scrollTo({ left: Math.max(0, left), behavior: reduce ? 'auto' : 'smooth' });
      }).observe(pill, { attributes: true, attributeFilter: ['class'] });
    }, subnavInner);
  }

  // Lightbox: Bild zoomt bei jedem Wechsel neu herein, auf Touch wischen zum Blättern
  var lb = document.getElementById('lightbox');
  var lbImg = document.getElementById('lbImg');
  if (lb && lbImg) {
    if (!reduce && window.MutationObserver) {
      new MutationObserver(function () {
        lbImg.classList.remove('lb-anim');
        void lbImg.offsetWidth;
        lbImg.classList.add('lb-anim');
      }).observe(lbImg, { attributes: true, attributeFilter: ['src'] });
    }
    var sx = 0, sy = 0;
    lb.addEventListener('touchstart', function (e) { sx = e.touches[0].clientX; sy = e.touches[0].clientY; }, { passive: true });
    lb.addEventListener('touchend', function (e) {
      var dx = e.changedTouches[0].clientX - sx, dy = e.changedTouches[0].clientY - sy;
      if (Math.abs(dx) < 50 || Math.abs(dx) < Math.abs(dy) * 1.5) return;
      var btn = document.getElementById(dx < 0 ? 'lbNext' : 'lbPrev');
      if (btn && btn.style.display !== 'none') btn.click();
    }, { passive: true });
  }

  // Desktop: Karten neigen sich zum Cursor, Haupt-Buttons sind leicht „magnetisch“
  if (finePointer && !reduce) {
    each('.q-card', function (card) {
      card.addEventListener('pointermove', function (e) {
        var r = card.getBoundingClientRect();
        var px = (e.clientX - r.left) / r.width, py = (e.clientY - r.top) / r.height;
        card.style.setProperty('--ry', ((px - 0.5) * 7).toFixed(2) + 'deg');
        card.style.setProperty('--rx', ((0.5 - py) * 7).toFixed(2) + 'deg');
        card.style.setProperty('--gx', (px * 100).toFixed(1) + '%');
        card.style.setProperty('--gy', (py * 100).toFixed(1) + '%');
      });
      card.addEventListener('pointerleave', function () {
        card.style.setProperty('--rx', '0deg'); card.style.setProperty('--ry', '0deg');
      });
    });
    each('.svc-hero-actions a, .cta-actions a, .nibe-copy .btn-primary', function (btn) {
      btn.addEventListener('pointermove', function (e) {
        var r = btn.getBoundingClientRect();
        var dx = (e.clientX - (r.left + r.width / 2)) * 0.22, dy = (e.clientY - (r.top + r.height / 2)) * 0.35;
        btn.style.translate = dx.toFixed(1) + 'px ' + dy.toFixed(1) + 'px';
      });
      btn.addEventListener('pointerleave', function () { btn.style.translate = ''; });
    });
  }

  // Tap-/Klick-Welle
  if (!reduce) {
    each('.btn-primary, .btn-outline, .action-bar a', function (btn) {
      btn.addEventListener('pointerdown', function (e) {
        var r = btn.getBoundingClientRect(), size = Math.max(r.width, r.height) * 2.2;
        var s = document.createElement('span');
        s.className = 'ripple';
        s.style.width = s.style.height = size + 'px';
        s.style.left = (e.clientX - r.left - size / 2) + 'px';
        s.style.top = (e.clientY - r.top - size / 2) + 'px';
        btn.appendChild(s);
        s.addEventListener('animationend', function () { s.remove(); });
      });
    });
  }

  // Touch: Karte / Galeriebild in der Bildschirmmitte zeigt seinen Hover-Zustand
  if (touchLike && hasIO) {
    var centerObs = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) { e.target.classList.toggle('in-view', e.isIntersecting); });
    }, { rootMargin: '-38% 0px -38% 0px' });
    each('.b-card, .q-card, .gal-item', function (el) { centerObs.observe(el); });
  }

  // Mobile Aktionsleiste: kurz nach dem Laden einfahren, am Kontaktbereich weg
  var bar = document.querySelector('.action-bar');
  if (bar) {
    setTimeout(function () { bar.classList.add('show'); }, 900);
    if (hasIO) {
      var awayState = [];
      var awayTargets = Array.prototype.slice.call(document.querySelectorAll('.cta-section, footer.site-footer'));
      var awayObs = new IntersectionObserver(function (entries) {
        entries.forEach(function (e) { awayState[awayTargets.indexOf(e.target)] = e.isIntersecting; });
        bar.classList.toggle('away', awayState.some(Boolean));
      }, { threshold: 0.25 });
      awayTargets.forEach(function (el) { awayObs.observe(el); });
    }
  }

  /* ── Ein Scroll-Handler für alles, was am Scroll hängt ──
     Fortschrittsbalken, Header-Autohide (mobil) und Parallax. */
  var hero = document.querySelector('.svc-hero');
  var heroMedia = hero && hero.querySelector('.svc-hero-media');
  var heroContent = hero && hero.querySelector('.svc-hero-content');
  var bands = [];
  [['.nibe-band', '.nibe-media'], ['.funding', '.funding-media'], ['.ov-section', '.ov-media']].forEach(function (p) {
    each(p[0], function (sec) { var m = sec.querySelector(p[1]); if (m) bands.push([sec, m]); });
  });
  var cta = document.querySelector('.cta-section');
  var lastY = window.pageYOffset, ticking = false;

  function sectionProgress(el, vh) {
    var r = el.getBoundingClientRect();
    if (r.bottom < 0 || r.top > vh) return null;
    return (r.top + r.height / 2 - vh / 2) / (vh + r.height); // ca. -0.5 … 0.5
  }

  function frame() {
    ticking = false;
    var y = window.pageYOffset, vh = window.innerHeight;
    var max = document.documentElement.scrollHeight - vh;
    progress.style.transform = 'scaleX(' + (max > 0 ? Math.min(1, y / max) : 0).toFixed(4) + ')';

    if (header) {
      var navOpen = mobileNav && mobileNav.classList.contains('open');
      var hide = header.classList.contains('hide');
      if (mqMobile.matches && !navOpen) {
        if (y > lastY + 4 && y > 320) hide = true;
        else if (y < lastY - 4 || y <= 320) hide = false;
      } else {
        hide = false;
      }
      header.classList.toggle('hide', hide);
      root.classList.toggle('header-hidden', hide);
    }
    lastY = y;
    if (reduce) return;

    if (hero && heroMedia && heroContent) {
      var hh = hero.offsetHeight;
      if (y < hh) {
        heroMedia.style.transform = 'translate3d(0,' + (y * 0.35).toFixed(1) + 'px,0)';
        heroContent.style.transform = 'translate3d(0,' + (y * 0.12).toFixed(1) + 'px,0)';
        heroContent.style.opacity = Math.max(0, 1 - y / (hh * 0.85)).toFixed(3);
      }
    }
    bands.forEach(function (b) {
      var p = sectionProgress(b[0], vh);
      if (p !== null) b[1].style.transform = 'translate3d(0,' + (-p * b[0].offsetHeight * 0.26).toFixed(1) + 'px,0)';
    });
    if (cta) {
      var pc = sectionProgress(cta, vh);
      if (pc !== null) cta.style.setProperty('--cta-x', (pc * -260).toFixed(1) + 'px');
    }
  }
  function requestFrame() { if (!ticking) { ticking = true; requestAnimationFrame(frame); } }
  window.addEventListener('scroll', requestFrame, { passive: true });
  window.addEventListener('resize', requestFrame);
  frame();
})();
