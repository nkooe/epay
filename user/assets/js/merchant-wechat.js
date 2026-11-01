(function () {
  'use strict';
  function ready() {
    var body = document.body;
    var menu = document.getElementById('merchantMenuToggle');
    var aside = document.getElementById('aside');
    var backdrop = document.getElementById('merchantNavBackdrop');
    var mobile = window.matchMedia('(max-width: 767px)');
    var content = document.getElementById('content');
    var previousFocus;
    function updateMenu() {
      if (!menu) return;
      var visible = mobile.matches ? body.classList.contains('merchant-nav-open') : !body.classList.contains('merchant-nav-hidden');
      menu.setAttribute('aria-expanded', String(visible));
      menu.setAttribute('aria-label', visible ? '收起导航菜单' : '展开导航菜单');
      aside.inert = !visible;
      aside.setAttribute('aria-hidden', String(!visible));
      if (content) content.inert = mobile.matches && visible;
    }
    function closeMenu() {
      body.classList.remove('merchant-nav-open');
      updateMenu();
      if (previousFocus) previousFocus.focus();
    }
    if (menu && aside) {
      menu.addEventListener('click', function () {
        if (mobile.matches) {
          previousFocus = menu;
          body.classList.toggle('merchant-nav-open');
          updateMenu();
          if (body.classList.contains('merchant-nav-open')) aside.querySelector('a').focus();
        } else {
          body.classList.toggle('merchant-nav-hidden');
          updateMenu();
        }
      });
      backdrop.addEventListener('click', closeMenu);
      document.addEventListener('keydown', function (event) {
        if (!mobile.matches || !body.classList.contains('merchant-nav-open')) return;
        if (event.key === 'Escape') { event.preventDefault(); closeMenu(); }
        if (event.key === 'Tab') {
          var links = Array.prototype.filter.call(aside.querySelectorAll('a'), function (a) { return a.getClientRects().length; });
          if (!links.length) return;
          if (event.shiftKey && document.activeElement === links[0]) { event.preventDefault(); links[links.length - 1].focus(); }
          else if (!event.shiftKey && document.activeElement === links[links.length - 1]) { event.preventDefault(); links[0].focus(); }
        }
      });
      function resized() { body.classList.remove('merchant-nav-open'); updateMenu(); }
      if (mobile.addEventListener) mobile.addEventListener('change', resized);
      else mobile.addListener(resized);
      var current = new URL(window.location.href);
      aside.querySelectorAll('a').forEach(function (link) {
        if (link.nextElementSibling && link.nextElementSibling.classList.contains('nav-sub')) {
          link.href = '#';
          link.setAttribute('aria-expanded', String(link.parentNode.classList.contains('active')));
          link.addEventListener('click', function (event) {
            event.preventDefault();
            var expanded = link.parentNode.classList.toggle('active');
            link.setAttribute('aria-expanded', String(expanded));
          });
          return;
        }
        var target = new URL(link.href, current);
        var path = function (value) { return value.replace(/\/index\.php$/, '/'); };
        if (target.origin === current.origin && path(target.pathname) === path(current.pathname) &&
            (target.searchParams.get('mod') || '') === (current.searchParams.get('mod') || (current.pathname.endsWith('/userinfo.php') ? 'api' : ''))) {
          link.setAttribute('aria-current', 'page');
          var parent = link.closest('.nav-sub');
          if (parent) { parent.parentNode.classList.add('active'); parent.previousElementSibling.setAttribute('aria-expanded', 'true'); }
        }
      });
      updateMenu();
    }
    if (content) {
      content.querySelectorAll('.form-group').forEach(function (group, index) {
        var label = group.querySelector('label.control-label');
        var field = group.querySelector('.form-control:not([type="hidden"])');
        if (!label || !field || label.htmlFor) return;
        if (!field.id) field.id = 'merchant-control-' + index;
        label.htmlFor = field.id;
      });
      var heading = content.querySelector('h1');
      if (heading) content.querySelectorAll('.panel-heading').forEach(function (item) {
        if (!item.querySelector('a,button,input,select') && item.textContent.trim() === heading.textContent.trim()) item.classList.add('merchant-duplicate-heading');
      });
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ready);
  else ready();
}());
