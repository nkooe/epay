(function () {
  'use strict';
  var body = document.body;
  var menu = document.getElementById('docs-menu-toggle');
  var sidebar = document.getElementById('docs-sidebar');
  var main = document.querySelector('.docs-workspace');
  var backdrop = document.getElementById('docs-backdrop');
  var search = document.getElementById('docs-search');
  var mobile = window.matchMedia('(max-width: 767px)');
  var groups = Array.from(document.querySelectorAll('.docs-nav-group'));
  var originalGroups = groups.map(function (group) { return group.open; });
  var toast = document.getElementById('docs-toast');
  var toastTimer;

  function updateMenu() {
    var expanded = mobile.matches ? body.classList.contains('docs-menu-open') : !body.classList.contains('docs-nav-hidden');
    menu.setAttribute('aria-expanded', String(expanded));
    menu.setAttribute('aria-label', expanded ? '收起文档目录' : '展开文档目录');
    sidebar.inert = !expanded;
    sidebar.setAttribute('aria-hidden', String(!expanded));
    main.inert = mobile.matches && expanded;
    backdrop.hidden = !(mobile.matches && expanded);
  }
  function closeMenu() {
    body.classList.remove('docs-menu-open');
    updateMenu();
    menu.focus();
  }
  menu.addEventListener('click', function () {
    body.classList.toggle(mobile.matches ? 'docs-menu-open' : 'docs-nav-hidden');
    updateMenu();
    if (mobile.matches && body.classList.contains('docs-menu-open')) search.focus();
  });
  backdrop.addEventListener('click', closeMenu);
  function resized() { body.classList.remove('docs-menu-open'); updateMenu(); }
  if (mobile.addEventListener) mobile.addEventListener('change', resized);
  else mobile.addListener(resized);
  updateMenu();

  search.addEventListener('input', function () {
    var keyword = search.value.trim().toLowerCase();
    var found = 0;
    groups.forEach(function (group, index) {
      var groupMatches = group.querySelector('summary').textContent.toLowerCase().includes(keyword);
      var matches = 0;
      group.querySelectorAll('li').forEach(function (item) {
        var link = item.querySelector('a');
        var match = !keyword || groupMatches || (link.textContent + ' ' + link.dataset.docKey).toLowerCase().includes(keyword);
        item.hidden = !match;
        if (match) matches++;
      });
      group.hidden = matches === 0;
      group.open = keyword ? matches > 0 : originalGroups[index];
      found += matches;
    });
    document.getElementById('docs-search-empty').hidden = found > 0;
  });

  document.addEventListener('keydown', function (event) {
    var typing = event.target.matches('input,textarea,[contenteditable="true"]');
    if (event.key === '/' && !typing && !event.ctrlKey && !event.metaKey && !event.altKey) {
      event.preventDefault();
      if (mobile.matches) body.classList.add('docs-menu-open');
      else body.classList.remove('docs-nav-hidden');
      updateMenu(); search.focus();
    }
    if (event.key === 'Escape' && body.classList.contains('docs-menu-open')) { event.preventDefault(); closeMenu(); }
    if (event.key === 'Tab' && mobile.matches && body.classList.contains('docs-menu-open')) {
      var items = [menu].concat(Array.from(sidebar.querySelectorAll('input,summary,a')).filter(function (item) { return item.getClientRects().length; }));
      if (event.shiftKey && document.activeElement === items[0]) { event.preventDefault(); items[items.length - 1].focus(); }
      else if (!event.shiftKey && document.activeElement === items[items.length - 1]) { event.preventDefault(); items[0].focus(); }
    }
  });

  function notify(message) {
    clearTimeout(toastTimer);
    toast.textContent = message;
    toast.hidden = false;
    toastTimer = setTimeout(function () { toast.hidden = true; }, 2600);
  }
  function legacyCopy(text) {
    var field = document.createElement('textarea');
    field.value = text;
    field.setAttribute('readonly', '');
    field.style.cssText = 'position:fixed;left:-9999px;top:0;';
    document.body.appendChild(field);
    field.select();
    var copied = document.execCommand('copy');
    field.remove();
    if (!copied) throw new Error('Clipboard unavailable');
  }
  function copyButton(text, label) {
    var button = document.createElement('button');
    button.type = 'button'; button.className = 'docs-copy-button'; button.textContent = '复制';
    button.setAttribute('aria-label', label);
    button.addEventListener('click', async function () {
      try {
        if (navigator.clipboard && window.isSecureContext) {
          try { await navigator.clipboard.writeText(text); }
          catch (error) { legacyCopy(text); }
        } else legacyCopy(text);
        button.textContent = '已复制'; notify('已复制到剪贴板');
        setTimeout(function () { button.textContent = '复制'; }, 1800);
      } catch (error) { notify('复制失败，请选中内容后手动复制'); }
      button.focus();
    });
    return button;
  }

  var article = document.getElementById('article-content');
  article.querySelectorAll('table').forEach(function (table, index) {
    var wrap = document.createElement('div');
    wrap.className = 'docs-table-scroll'; wrap.tabIndex = 0;
    wrap.setAttribute('role', 'region'); wrap.setAttribute('aria-label', '参数表格 ' + (index + 1) + '，可横向滚动');
    table.before(wrap); wrap.appendChild(table);
    if (table.querySelectorAll('thead tr:first-child th').length >= 5) table.classList.add('docs-table-wide');
    table.querySelectorAll('thead th').forEach(function (cell) { cell.setAttribute('scope', 'col'); });
    table.querySelectorAll('tbody tr').forEach(function (row) {
      var headers = Array.from(table.querySelectorAll('thead th'));
      row.querySelectorAll('td').forEach(function (cell, index) {
        if (headers[index] && headers[index].textContent.trim() === '必填' && cell.textContent.trim() === '是') cell.classList.add('docs-required');
      });
    });
  });
  article.querySelectorAll('pre').forEach(function (pre) {
    var code = pre.querySelector('code');
    var text = (code || pre).textContent;
    var wrap = document.createElement('div'); wrap.className = 'docs-code-block';
    var toolbar = document.createElement('div'); toolbar.className = 'docs-code-toolbar';
    var language = document.createElement('span'); language.textContent = code && code.className ? code.className.toUpperCase() : '示例代码';
    toolbar.append(language, copyButton(text, '复制代码示例'));
    pre.before(wrap); wrap.append(toolbar, pre);
    pre.tabIndex = 0;
  });
  article.querySelectorAll('p').forEach(function (paragraph) {
    var previous = paragraph.previousElementSibling;
    var endpoint = previous && /^H[234]$/.test(previous.tagName) && previous.textContent.includes('请求地址');
    if (endpoint && paragraph.querySelector('code')) {
      var code = paragraph.querySelector('code');
      paragraph.classList.add('docs-endpoint');
      paragraph.appendChild(copyButton(code.textContent, '复制接口地址'));
    }
  });

  var headings = Array.from(article.querySelectorAll('h2,h3,h4'));
  if (!headings.length) headings = Array.from(article.querySelectorAll('.api_block'));
  var toc = document.getElementById('docs-toc-links');
  var mobileToc = document.getElementById('docs-mobile-toc-links');
  headings.forEach(function (heading, index) {
    if (!heading.id) heading.id = 'doc-section-' + (index + 1);
    var link = document.createElement('a');
    link.href = '#' + encodeURIComponent(heading.id);
    link.textContent = heading.matches('.api_block') ? (heading.querySelector('h3') || heading).textContent.trim() : heading.textContent.trim();
    toc.appendChild(link); mobileToc.appendChild(link.cloneNode(true));
  });
  if (!headings.length) document.getElementById('docs-mobile-toc').hidden = true;
  if ('IntersectionObserver' in window) {
    var headingObserver = new IntersectionObserver(function (entries) {
      var visible = entries.filter(function (entry) { return entry.isIntersecting; });
      if (!visible.length) return;
      var current = '#' + encodeURIComponent(visible[0].target.id);
      toc.querySelectorAll('a').forEach(function (link) {
        if (link.getAttribute('href') === current) link.setAttribute('aria-current', 'location');
        else link.removeAttribute('aria-current');
      });
    }, {rootMargin: '-80px 0px -65% 0px'});
    headings.forEach(function (heading) { headingObserver.observe(heading); });
  }
  mobileToc.addEventListener('click', function (event) {
    if (event.target.closest('a')) document.getElementById('docs-mobile-toc').open = false;
  });

  var dialog = document.getElementById('docs-download-dialog');
  var pendingDownload;
  article.querySelectorAll('a[href]').forEach(function (link) {
    var url = new URL(link.href, location.href);
    if (url.origin !== location.origin) { link.rel = 'noopener noreferrer'; }
    if (!/\.(zip|7z|tar|gz|rar)$/i.test(url.pathname)) return;
    link.addEventListener('click', function (event) {
      event.preventDefault();
      if (!dialog.showModal) {
        if (window.confirm('确认下载 ' + link.textContent.trim() + '？')) location.assign(link.href);
        return;
      }
      pendingDownload = link.href;
      document.getElementById('docs-download-name').textContent = link.textContent.trim();
      dialog.returnValue = '';
      dialog.showModal();
    });
  });
  dialog.addEventListener('close', function () {
    var download = pendingDownload;
    pendingDownload = null;
    if (dialog.returnValue === 'download' && download) location.assign(download);
  });
}());
