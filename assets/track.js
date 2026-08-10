/* Atribuire + palnie formular (first-party, pentru CRM) */
(function () {
  var mem = {};

  function ss(k, v) {
    if (v === undefined) {
      try { var x = sessionStorage.getItem(k); if (x !== null) return x; } catch (e) {}
      return mem[k] || '';
    }
    mem[k] = String(v);
    try { sessionStorage.setItem(k, v); } catch (e) {}
    return '';
  }

  // true doar prima data intr-o sesiune, pentru cheia data
  function odata(k) {
    if (ss(k) === '1') return false;
    ss(k, '1');
    return true;
  }

  var pag = (location.pathname || '/').slice(0, 200);

  function param(n) {
    try {
      var m = new RegExp('[?&]' + n + '=([^&#]*)').exec(location.search || '');
      return m ? decodeURIComponent(m[1].replace(/\+/g, ' ')).toLowerCase() : '';
    } catch (e) { return ''; }
  }

  function canalCurent() {
    var src = param('utm_source'), med = param('utm_medium');
    var platit = /cpc|ppc|paid|cpm|display|retarget|ads/.test(med);
    if (param('gclid') || param('gbraid') || param('wbraid') || (/google/.test(src) && platit)) return 'google-ads';
    if (param('fbclid') || (/facebook|instagram|meta|^fb$|^ig$/.test(src) && platit)) return 'meta-ads';
    if (param('ttclid')) return 'tiktok-ads';
    if (src) return 'utm:' + src.replace(/[^a-z0-9._-]/g, '').slice(0, 30);
    var ref = document.referrer || '';
    if (!ref) return 'direct';
    try {
      var h = new URL(ref).hostname.replace(/^www\./, '').toLowerCase();
      if (/(^|\.)zugravimiasi\.ro$/.test(h)) return 'direct'; // navigare interna, nu schimba atribuirea
      if (/google\./.test(h)) return 'google';
      if (/facebook|instagram|fb\./.test(h)) return 'facebook';
      if (/bing\./.test(h)) return 'bing';
      if (/tiktok/.test(h)) return 'tiktok';
      if (/olx\.|publi24/.test(h)) return 'anunturi';
      return h.slice(0, 50);
    } catch (e) { return 'direct'; }
  }

  // ultimul click ne-direct castiga, cu memorie de 7 zile
  var canal = (function () {
    var acum = Date.now(), cur = canalCurent(), salvat = '';
    try {
      var raw = localStorage.getItem('zi-attr');
      if (raw) {
        var o = JSON.parse(raw);
        if (o && o.canal && o.ts && (acum - o.ts) < 604800000) salvat = String(o.canal).slice(0, 60);
      }
    } catch (e) {}
    if (cur && cur !== 'direct') {
      try { localStorage.setItem('zi-attr', JSON.stringify({ canal: cur, ts: acum })); } catch (e) {}
      return cur;
    }
    return salvat || cur || 'direct';
  })();
  ss('zi-chan', canal);

  function disp() {
    var d = ss('zi-dev');
    if (d) return d;
    return (window.screen && screen.width && screen.width <= 768) ? 'mobil' : 'desktop';
  }

  var sid = ss('zi-sid');
  if (!sid) {
    sid = Date.now().toString(36) + Math.random().toString(36).slice(2, 8);
    ss('zi-sid', sid);
    var ref0 = document.referrer || '', src0 = 'direct';
    if (ref0) {
      try {
        var h0 = new URL(ref0).hostname.replace(/^www\./, '');
        if (/zugravimiasi\.ro$/.test(h0)) src0 = 'intern';
        else if (/google\./.test(h0)) src0 = 'google';
        else if (/facebook|instagram|fb\./.test(h0)) src0 = 'facebook';
        else if (/bing\./.test(h0)) src0 = 'bing';
        else src0 = h0;
      } catch (e) {}
    }
    ss('zi-entry', location.pathname);
    ss('zi-src', src0);
    ss('zi-dev', (window.screen && screen.width && screen.width <= 768) ? 'mobil' : 'desktop');
    ss('zi-btn', '');
  }

  function beacon(type, extra) {
    try {
      var d = new URLSearchParams();
      d.set('sid', ss('zi-sid'));
      d.set('type', type);
      d.set('page', pag);
      d.set('ch', canal);
      d.set('dev', disp());
      if (extra) {
        for (var k in extra) {
          if (Object.prototype.hasOwnProperty.call(extra, k)) d.set(k, extra[k]);
        }
      }
      if (navigator.sendBeacon && navigator.sendBeacon('/track.php', d)) return;
      fetch('/track.php', { method: 'POST', body: d, keepalive: true });
    } catch (e) {}
  }

  beacon('page_view');

  document.addEventListener('click', function (e) {
    var a = (e.target && e.target.closest) ? e.target.closest('a,button') : null;
    if (!a) return;
    var href = a.getAttribute('href') || '';
    var txt = (a.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 60);

    // WhatsApp / email: pagina pleaca imediat, deci doar sendBeacon prinde click-ul
    var kind = '';
    if (/^https?:\/\/(api\.|web\.)?whatsapp\.com/i.test(href) || /wa\.me/i.test(href)) kind = 'whatsapp';
    else if (/^mailto:/i.test(href)) kind = 'email';
    if (kind) {
      if (odata('zi-cc-' + kind + '-' + pag)) beacon('contact_click', { kind: kind });
      return;
    }

    if (/#contact|#calculator|contact\.php/.test(href) || /cere ofert|calculeaz|deviz|sun[aă]/i.test(txt)) {
      ss('zi-btn', (txt || 'buton') + ' · ' + location.pathname);
    }
  }, true);

  // cine a VAZUT butonul de oferta, nu doar cine l-a apasat
  if ('IntersectionObserver' in window) {
    var cta = [];
    try {
      var noduri = document.querySelectorAll('a[href*="#contact"], a[href*="contact.php"], .btn-primary');
      for (var i = 0; i < noduri.length; i++) {
        var n = noduri[i], h = n.getAttribute ? (n.getAttribute('href') || '') : '';
        if (h) {
          if (/#contact|contact\.php/.test(h)) cta.push(n);
        } else if (n.classList && n.classList.contains('btn-primary') && n.closest && n.closest('form[action*="contact.php"]')) {
          cta.push(n);
        }
      }
    } catch (e) {}
    if (cta.length) {
      var ioc = new IntersectionObserver(function (en) {
        for (var j = 0; j < en.length; j++) {
          if (en[j].isIntersecting) {
            ioc.disconnect();
            if (odata('zi-cta-' + pag)) beacon('cta_view');
            return;
          }
        }
      }, { threshold: 0.5 });
      for (var c = 0; c < cta.length; c++) { try { ioc.observe(cta[c]); } catch (e) {} }
    }
  }

  var form = document.querySelector('form[action*="contact.php"]');
  if (form) {
    var set = function (n, v) {
      var i = form.querySelector('input[name="' + n + '"]');
      if (!i) { i = document.createElement('input'); i.type = 'hidden'; i.name = n; form.appendChild(i); }
      i.value = v || '';
    };

    if ('IntersectionObserver' in window) {
      var io = new IntersectionObserver(function (en) {
        en.forEach(function (x) { if (x.isIntersecting) { beacon('form_view'); io.disconnect(); } });
      }, { threshold: 0.25 });
      io.observe(form);
    } else { beacon('form_view'); }

    var started = false, trimis = false, ultim = '';

    form.addEventListener('focusin', function () {
      if (!started) { started = true; beacon('form_start'); }
    });

    // unde s-a oprit omul din completat
    form.addEventListener('focusout', function (e) {
      var el = e.target;
      if (!el || !el.name) return;
      var n = String(el.name);
      if (n === 'website' || n.indexOf('f_') === 0) return;
      ultim = n.replace(/[^a-zA-Z0-9_-]/g, '').slice(0, 40);
    });

    form.addEventListener('submit', function () {
      trimis = true;
      set('f_sid', ss('zi-sid')); set('f_entry', ss('zi-entry')); set('f_submit', location.pathname);
      set('f_btn', ss('zi-btn')); set('f_src', ss('zi-src')); set('f_dev', ss('zi-dev'));
      set('f_channel', canal);
    });

    var parasit = function () {
      if (trimis || !ultim) return;
      if (odata('zi-ff')) beacon('form_field', { field: ultim });
    };
    document.addEventListener('visibilitychange', function () {
      if (document.visibilityState === 'hidden') parasit();
    });
    window.addEventListener('pagehide', parasit);
  }
})();
