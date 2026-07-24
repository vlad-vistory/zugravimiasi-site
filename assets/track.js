/* Atribuire + palnie formular (first-party, pentru CRM) */
(function () {
  function ss(k, v) {
    try { if (v === undefined) return sessionStorage.getItem(k) || ''; sessionStorage.setItem(k, v); }
    catch (e) { return ''; }
  }
  var sid = ss('zi-sid');
  if (!sid) {
    sid = Date.now().toString(36) + Math.random().toString(36).slice(2, 8);
    ss('zi-sid', sid);
    var ref = document.referrer || '', src = 'direct';
    if (ref) {
      try {
        var h = new URL(ref).hostname.replace(/^www\./, '');
        if (/zugravimiasi\.ro$/.test(h)) src = 'intern';
        else if (/google\./.test(h)) src = 'google';
        else if (/facebook|instagram|fb\./.test(h)) src = 'facebook';
        else if (/bing\./.test(h)) src = 'bing';
        else src = h;
      } catch (e) {}
    }
    ss('zi-entry', location.pathname);
    ss('zi-src', src);
    ss('zi-dev', (window.screen && screen.width && screen.width <= 768) ? 'mobil' : 'desktop');
    ss('zi-btn', '');
  }
  function beacon(type) {
    try {
      var d = new URLSearchParams();
      d.set('sid', ss('zi-sid')); d.set('type', type); d.set('page', location.pathname);
      if (navigator.sendBeacon) navigator.sendBeacon('/track.php', d);
      else fetch('/track.php', { method: 'POST', body: d, keepalive: true });
    } catch (e) {}
  }
  document.addEventListener('click', function (e) {
    var a = e.target.closest ? e.target.closest('a,button') : null; if (!a) return;
    var href = a.getAttribute('href') || '', txt = (a.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 60);
    if (/#contact|#calculator|contact\.php/.test(href) || /cere ofert|calculeaz|deviz|sun[aă]/i.test(txt)) {
      ss('zi-btn', (txt || 'buton') + ' · ' + location.pathname);
    }
  }, true);
  var form = document.querySelector('form[action="contact.php"]');
  if (form) {
    if ('IntersectionObserver' in window) {
      var io = new IntersectionObserver(function (en) {
        en.forEach(function (x) { if (x.isIntersecting) { beacon('form_view'); io.disconnect(); } });
      }, { threshold: 0.25 });
      io.observe(form);
    } else { beacon('form_view'); }
    var started = false;
    form.addEventListener('focusin', function () { if (!started) { started = true; beacon('form_start'); } });
    form.addEventListener('submit', function () {
      set('f_sid', ss('zi-sid')); set('f_entry', ss('zi-entry')); set('f_submit', location.pathname);
      set('f_btn', ss('zi-btn')); set('f_src', ss('zi-src')); set('f_dev', ss('zi-dev'));
    });
    function set(n, v) {
      var i = form.querySelector('input[name="' + n + '"]');
      if (!i) { i = document.createElement('input'); i.type = 'hidden'; i.name = n; form.appendChild(i); }
      i.value = v || '';
    }
  }
})();
