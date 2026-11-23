(function (root) {
  'use strict';
  var languages = {js:'javascript',mjs:'javascript',jsx:'javascript',ts:'typescript',tsx:'typescript',vue:'xml',html:'xml',htm:'xml',svg:'xml',xml:'xml',json:'json',css:'css',less:'less',scss:'scss',sql:'sql',yaml:'yaml',yml:'yaml',ini:'ini',conf:'ini',config:'ini',md:'markdown',markdown:'markdown'};
  var maxRender = 200000;

  function safeLink(href) {
    if (typeof href !== 'string' || /[\u0000-\u0020\u007f\\]/.test(href)) return false;
    return /^https?:\/\/[^/]/i.test(href) || /^mailto:[^@]+@[^@]+$/i.test(href) || /^#[A-Za-z0-9_.:-]*$/.test(href);
  }

  function highlight(text, language, hljs) {
    if (!hljs || text.length > maxRender || !language || !hljs.getLanguage(language)) return null;
    try { return hljs.highlight(text, {language:language,ignoreIllegals:true}).value; }
    catch (e) { return null; }
  }

  function markdownEngine(markdownit, hljs) {
    var md = markdownit({html:false,linkify:false,typographer:false,maxNesting:20,highlight:function (text, lang) {
      return highlight(text, languages[lang] || lang, hljs) || '';
    }});
    md.validateLink = safeLink;
    // 连相对图片也不发请求，避免内容作者利用访问预览来跟踪访客。
    md.renderer.rules.image = function (tokens, index) {
      return md.utils.escapeHtml('[图片：' + (tokens[index].content || '未加载') + ']');
    };
    return md;
  }

  // 防御性白名单：即使依赖升级改变输出，也不允许文件内容创造可执行元素或属性。
  function cleanMarkup(html, document) {
    var template = document.createElement('template');
    template.innerHTML = html;
    var tags = /^(P|BR|HR|H[1-6]|UL|OL|LI|BLOCKQUOTE|PRE|CODE|EM|STRONG|S|DEL|A|TABLE|THEAD|TBODY|TR|TH|TD|SPAN)$/;
    function clean(parent) {
      Array.prototype.slice.call(parent.childNodes).forEach(function (node) {
        if (node.nodeType === 3) return;
        if (node.nodeType !== 1) { parent.removeChild(node); return; }
        if (!tags.test(node.tagName)) { parent.replaceChild(document.createTextNode(node.textContent), node); return; }
        Array.prototype.slice.call(node.attributes).forEach(function (attr) {
          var allowed = node.tagName === 'A' && attr.name === 'href' && safeLink(attr.value);
          allowed = allowed || node.tagName === 'A' && attr.name === 'title';
          allowed = allowed || (node.tagName === 'CODE' || node.tagName === 'SPAN') && attr.name === 'class' && /^(?:hljs[-\w]*|language-[\w-]+)(?: (?:hljs[-\w]*|language-[\w-]+))*$/.test(attr.value);
          allowed = allowed || node.tagName === 'OL' && attr.name === 'start' && /^\d{1,6}$/.test(attr.value);
          if (!allowed) node.removeAttribute(attr.name);
        });
        if (node.tagName === 'A') { node.setAttribute('target', '_blank'); node.setAttribute('rel','noopener noreferrer'); }
        clean(node);
      });
    }
    clean(template.content);
    return template.content;
  }

  // 高亮可跨行嵌套 span；逐行复制安全的节点路径，保留多行注释的高亮状态。
  function sourceLines(text, marked, document) {
    var container = document.createElement('div');
    if (marked === null) container.textContent = text;
    else container.appendChild(cleanMarkup(marked, document));
    var fragment = document.createDocumentFragment();
    var line = document.createElement('li');
    var code = document.createElement('code');
    line.appendChild(code); fragment.appendChild(line);
    function walk(node, path) {
      if (node.nodeType === 3) {
        node.nodeValue.split('\n').forEach(function (part, index) {
          if (index) {
            line = document.createElement('li'); code = document.createElement('code');
            line.appendChild(code); fragment.appendChild(line);
          }
          var target = code;
          path.forEach(function (ancestor) { var clone = ancestor.cloneNode(false); target.appendChild(clone); target = clone; });
          target.appendChild(document.createTextNode(part));
        });
      } else if (node.nodeType === 1) {
        Array.prototype.forEach.call(node.childNodes, function (child) { walk(child, path.concat([node])); });
      }
    }
    Array.prototype.forEach.call(container.childNodes, function (node) { walk(node, []); });
    return fragment;
  }

  function init(document) {
    var panel = document.querySelector('[data-text-preview]');
    if (!panel) return;
    var source = document.getElementById('tp-content');
    var plain = document.getElementById('tp-plain');
    var code = document.getElementById('tp-code');
    var sourcePanel = document.getElementById('tp-source');
    var rendered = document.getElementById('tp-render');
    var renderTab = document.getElementById('tp-render-tab');
    var sourceTab = document.getElementById('tp-source-tab');
    var linesToggle = document.getElementById('tp-lines');
    var wrapToggle = document.getElementById('tp-wrap');
    var status = document.getElementById('tp-status');
    var text = source.textContent;
    var type = panel.getAttribute('data-type');
    var isMarkdown = type === 'md' || type === 'markdown';
    var manageable = text.length <= maxRender && (text.match(/\n/g) || []).length < 10000;
    code.replaceChildren();
    rendered.replaceChildren();
    code.hidden = true; plain.hidden = false; renderTab.hidden = true;
    status.textContent = '';

    function view(showRender) {
      rendered.hidden = !showRender; sourcePanel.hidden = showRender;
      renderTab.setAttribute('aria-selected', String(showRender));
      sourceTab.setAttribute('aria-selected', String(!showRender));
      linesToggle.disabled = showRender || !manageable;
      wrapToggle.disabled = showRender;
    }
    view(false);
    if (manageable) {
      code.appendChild(sourceLines(text, highlight(text, languages[type], root.hljs), document));
      plain.hidden = true; code.hidden = false;
    } else {
      linesToggle.disabled = true;
      status.textContent = '文件较大，已使用纯文本模式，避免高亮或排版使浏览器卡顿。';
    }
    if (isMarkdown && manageable && root.markdownit) {
      try {
        rendered.appendChild(cleanMarkup(markdownEngine(root.markdownit, root.hljs).render(text), document));
        renderTab.hidden = false;
        view(true);
      } catch (e) { status.textContent = '排版失败，已显示原始文本。'; }
    }
    renderTab.addEventListener('click', function () { view(true); });
    sourceTab.addEventListener('click', function () { view(false); });
    linesToggle.addEventListener('change', function () { sourcePanel.classList.toggle('tp-line-numbers', linesToggle.checked); });
    wrapToggle.addEventListener('change', function () { sourcePanel.classList.toggle('tp-wrap', wrapToggle.checked); });
  }
  var api = {safeLink:safeLink,markdownEngine:markdownEngine,cleanMarkup:cleanMarkup,sourceLines:sourceLines,init:init};
  if (typeof module === 'object' && module.exports) module.exports = api;
  root.PanTextPreview = api;
  if (typeof document !== 'undefined') init(document);
})(typeof window !== 'undefined' ? window : globalThis);
