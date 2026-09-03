/* R. Ruge Haustechnik – gemeinsame Interaktionen (index / kontakt / impressum / datenschutz) */
(function () {
  'use strict';

  /* ── Header: Schatten beim Scrollen ─────────────────────────────────── */
  var header = document.getElementById('header');
  if (header) {
    var onScroll = function () {
      header.classList.toggle('scrolled', window.scrollY > 20);
    };
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
  }

  /* ── Mobile Navigation ──────────────────────────────────────────────── */
  var burger = document.getElementById('hamburger');
  var mobileNav = document.getElementById('mobileNav');

  function setNav(open) {
    if (!burger || !mobileNav) return;
    mobileNav.classList.toggle('open', open);
    burger.setAttribute('aria-expanded', open ? 'true' : 'false');
    burger.setAttribute('aria-label', open ? 'Menü schließen' : 'Menü öffnen');
    document.body.classList.toggle('nav-open', open);
  }

  if (burger && mobileNav) {
    burger.addEventListener('click', function () {
      setNav(!mobileNav.classList.contains('open'));
    });
    mobileNav.addEventListener('click', function (e) {
      if (e.target.closest('a')) setNav(false);
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') setNav(false);
    });
    // Beim Wechsel auf Desktop-Breite sauber zurücksetzen
    var mq = window.matchMedia('(min-width: 901px)');
    var reset = function (e) { if (e.matches) setNav(false); };
    mq.addEventListener ? mq.addEventListener('change', reset) : mq.addListener(reset);
  }

  /* ── Scroll-Reveal ──────────────────────────────────────────────────── */
  var reveals = document.querySelectorAll('.reveal');
  if (reveals.length) {
    if (!('IntersectionObserver' in window)) {
      reveals.forEach(function (el) { el.classList.add('visible'); });
    } else {
      var obs = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) {
            entry.target.classList.add('visible');
            obs.unobserve(entry.target);
          }
        });
      }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });
      reveals.forEach(function (el) { obs.observe(el); });
      // Sicherheitsnetz: falls der Observer aus irgendeinem Grund nicht auslöst,
      // wird nach 4 Sekunden alles sichtbar gemacht.
      setTimeout(function () {
        reveals.forEach(function (el) { el.classList.add('visible'); });
      }, 4000);
    }
  }

  /* ── FAQ-Akkordeon ──────────────────────────────────────────────────── */
  document.querySelectorAll('.faq-q').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var item = btn.closest('.faq-item');
      var wasOpen = item.classList.contains('open');
      item.closest('.faq-list').querySelectorAll('.faq-item.open').forEach(function (i) {
        i.classList.remove('open');
        i.querySelector('.faq-q').setAttribute('aria-expanded', 'false');
      });
      if (!wasOpen) {
        item.classList.add('open');
        btn.setAttribute('aria-expanded', 'true');
      }
    });
  });

  /* ── Sanftes Scrollen zu Ankern (mit Header-Offset) ─────────────────── */
  document.querySelectorAll('a[href^="#"]').forEach(function (a) {
    a.addEventListener('click', function (e) {
      var id = a.getAttribute('href');
      if (!id || id === '#') return;
      var target = document.querySelector(id);
      if (!target) return;
      e.preventDefault();
      var top = target.getBoundingClientRect().top + window.pageYOffset -
                (parseInt(getComputedStyle(document.documentElement)
                  .getPropertyValue('--header-h'), 10) || 76) - 12;
      var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      window.scrollTo({ top: top, behavior: reduce ? 'auto' : 'smooth' });
      history.replaceState(null, '', id);
    });
  });

  /* ── Kontaktformular ────────────────────────────────────────────────
     Der Versand läuft serverseitig über kontakt.php. Hier passiert nur
     die Feinarbeit: Fehler beim ersten fehlerhaften Feld sichtbar machen
     und den Button während des Absendens sperren, damit niemand doppelt
     klickt.                                                              */
  var form = document.getElementById('kontaktForm');
  if (form) {
    form.addEventListener('submit', function (e) {
      // Browser-Validierung selbst auslösen (das Formular hat novalidate,
      // damit wir die Meldung am richtigen Feld platzieren können).
      if (!form.checkValidity()) {
        e.preventDefault();
        var ersteLuecke = form.querySelector(':invalid');
        if (ersteLuecke) {
          ersteLuecke.focus();
          ersteLuecke.reportValidity();
        }
        return;
      }
      var btn = form.querySelector('button[type="submit"]');
      if (btn) {
        btn.setAttribute('aria-busy', 'true');
        btn.textContent = 'Wird gesendet …';
      }
    });

    // Nach einem Fehlversuch direkt zur ersten Meldung springen
    var ersterFehler = document.querySelector('.form-status.err, .field-error');
    if (ersterFehler && !window.location.hash) {
      ersterFehler.scrollIntoView({ block: 'center' });
    }
  }

  /* ── Aktuelles Jahr im Footer ───────────────────────────────────────── */
  document.querySelectorAll('[data-year]').forEach(function (el) {
    el.textContent = new Date().getFullYear();
  });
})();
