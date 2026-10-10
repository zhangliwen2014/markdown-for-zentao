/* mdeditor 前端接管：核心 doc 新建/编辑页在 markdown 场景下摘除 zen-editor、挂载 Vditor，
 * 并将表单提交劫持到 mdeditor 自有写路由（Path B'，findings §13）。
 * 判定：create 页 form action 含 -markdown（SPA 下 pathname 不可用）；edit 页 input[name=contentType]==markdown。
 * 约定：#docForm 为核心表单；e.submitter 区分存稿按钮；window.loadPage / $.createLink 由 zin.js 提供。 */
(function()
{
'use strict';

if(window.__MDEDITOR_INIT) return;
window.__MDEDITOR_INIT = true;

var CONF   = window.MDEDITOR_CONFIG || {};
/* SPA 下 config 由 pageJS 通道在加载前同步注入；仍兜底 window.config.webRoot（configJS 通道 SPA 亦维护）。 */
var BASE   = (window.config && window.config.webRoot) || '/';
var CDN    = CONF.cdn      || BASE + 'js/mdeditor/vditor';
var WEBR   = CONF.webRoot  || BASE;
var vditor = null;
var loadPromise = null;

window.MDEDITOR_BOOT = {mounted: false, version: '0.2.13'};

/* 轻量诊断：仅当 window.MDEDITOR_DEBUG 为真时输出，默认静默（不污染生产控制台）。
 * 复现 SPA 编辑/新建接管问题时，控制台执行 window.MDEDITOR_DEBUG=1 再站内跳转即可看到判定链路。 */
function dbg()
{
    if(window.MDEDITOR_DEBUG && window.console && console.debug) console.debug.apply(console, ['[mdeditor]'].concat([].slice.call(arguments)));
}

/* 服务端失败回调白名单：仅允许重新打开基础信息弹窗，其他值忽略（防任意代码执行）。 */
var CALLBACK_ALLOWLIST = [
    {re: /modalBasicInfo/, fn: function() { if(window.zui && zui.Modal) zui.Modal.open({id: 'modalBasicInfo'}); }}
];

/* 懒加载 Vditor：仅 markdown 页面注入 css+js，非 markdown 文档零开销。 */
function loadVditor()
{
    if(loadPromise) return loadPromise;
    loadPromise = new Promise(function(resolve, reject)
    {
        if(window.Vditor) return resolve();
        var css = document.createElement('link');
        css.rel  = 'stylesheet';
        css.href = CDN + '/dist/index.css';
        document.head.appendChild(css);
        var s = document.createElement('script');
        s.src     = CDN + '/dist/index.min.js';
        s.onload  = function() { window.Vditor ? resolve() : reject(new Error('Vditor missing')); };
        s.onerror = function() { reject(new Error('Vditor load failed')); };
        document.head.appendChild(s);
    });
    return loadPromise;
}

function linkTo(module, action, vars)
{
    if(window.$ && $.createLink)
    {
        try { return $.createLink(module, action, vars || ''); } catch(e) {}
    }
    return WEBR + 'index.php?m=' + encodeURIComponent(module) + '&f=' + encodeURIComponent(action) + (vars ? '&' + vars : '');
}

/* SPA（index.html?open=…）下 location.pathname 恒为 /index.html，判定必须用表单自身的 action。 */
function isMarkdownCreate(form)
{
    var action = (form && (form.getAttribute('action') || form.action)) || location.pathname;
    if(/\/doc-create[^/]*-markdown(\.html|\/|$)/.test(action)) return true;
    if(/\/doc-create-.*-markdown\.html/.test(location.pathname)) return true;
    if(/(^|\/)doc-create\.html/.test(location.pathname) || /[?&]f=create(&|$)/.test(location.search)) return /[?&]type=markdown(&|$)/.test(location.search);
    return false;
}

function isMarkdownEdit(form)
{
    var ct = form.querySelector('input[name=contentType]');
    return !!(ct && ct.value === 'markdown');
}

/* 核心 ajaxUpload 响应 {error:0,url} → Vditor 期望格式适配。 */
function uploadFormat(files, response)
{
    var parsed = response;
    if(typeof response === 'string') { try { parsed = JSON.parse(response); } catch(e) { parsed = null; } }
    if(parsed && parsed.error === 0 && parsed.url)
    {
        var name = files && files[0] ? files[0].name : '';
        return {code: 0, data: {errMap: [], fileList: [{originalName: name, name: name, url: parsed.url}]}};
    }
    return {code: 1, msg: (parsed && parsed.message) || 'upload failed', data: {errMap: [], fileList: []}};
}

function getDocID(form)
{
    var action = (form && (form.getAttribute('action') || form.action)) || '';
    var m = action.match(/\/doc-edit-(\d+)/);
    if(m) return m[1];
    m = location.pathname.match(/\/doc-edit-(\d+)(?:\.html|\/)/);
    if(m) return m[1];
    m = location.search.match(/[?&]docID=(\d+)/);
    return m ? m[1] : '';
}

function mount(form, mode)
{
    /* zin morph 会复用 #docForm DOM 节点跨文档导航：dataset.mdMounted 粘滞而形态可能已重注入新 zen-editor。
     * 若已被复用（mounted=1 但当前仍有原生编辑器或已缺失 #md-vditor-box），必须先销毁旧实例与残留节点再重建，
     * 否则 SPA 站内进入新 doc 的编辑/新建页时接管会静默失败，用户看到 wiki 工具栏。 */
    var remount = form.dataset.mdMounted === '1';
    if(remount)
    {
        dbg('mount: remounting', mode);
        if(window.MDEDITOR_BOOT && window.MDEDITOR_BOOT.vditor) { try { window.MDEDITOR_BOOT.vditor.destroy(); } catch(e) {} window.MDEDITOR_BOOT.vditor = null; }
        var oldBox = form.querySelector('#md-vditor-box'); if(oldBox && oldBox.parentNode) oldBox.parentNode.removeChild(oldBox);
        var oldTa  = form.querySelector('textarea[name=content]'); if(oldTa && oldTa.parentNode) oldTa.parentNode.removeChild(oldTa);
    }
    form.dataset.mdMounted = '1';

    var container = form.querySelector('.editor-container');
    var zenEl     = form.querySelector('zen-editor');
    var uidInput  = container ? container.querySelector('input[name=uid]') : form.querySelector('input[name=uid]');
    var uid       = uidInput ? uidInput.value : '';

    /* 初始原文：优先核心 widget 的无名 textarea（.value 自动解码实体，逐字节还原）；
     * 兜底 zen-editor 内 article[slot=content]（raw 输出，md 含内嵌 HTML 时可能有损，M5 视图侧统一处理）。 */
    var initial = '';
    if(container)
    {
        var plainTas = container.querySelectorAll('textarea');
        for(var t = 0; t < plainTas.length; t++) if(!plainTas[t].name) { initial = plainTas[t].value; break; }
    }
    if(!initial && zenEl)
    {
        var art = zenEl.querySelector('article[slot=content]');
        if(art) initial = art.textContent;
    }

    /* 摘除 zen-editor 自定义元素与其无名 textarea；保留 uid 隐藏域。 */
    if(zenEl)
    {
        if(container)
        {
            var staleTa = container.querySelectorAll('textarea');
            for(var i = 0; i < staleTa.length; i++) if(!staleTa[i].name) staleTa[i].parentNode.removeChild(staleTa[i]);
        }
        if(zenEl.parentNode) zenEl.parentNode.removeChild(zenEl);
    }

    var box = document.createElement('div');
    box.id  = 'md-vditor-box';
    if(container) container.insertBefore(box, container.firstChild);

    /* 提交载体：核心 widget 的 textarea 无 name，自造隐藏 textarea[name=content]。 */
    var contentField = form.querySelector('textarea[name=content]');
    if(!contentField)
    {
        contentField = document.createElement('textarea');
        contentField.name          = 'content';
        contentField.style.position = 'absolute';
        contentField.style.left     = '-9999px';
        contentField.style.opacity  = '0';
        (container || form).appendChild(contentField);
    }

    var height = Math.max(420, window.innerHeight - 260);
    vditor = new window.Vditor(box, {
        cdn:         CDN,
        mode:        'ir',
        'edit-mode': 'both',
        height:      height,
        value:       normalizeTables(initial),
        placeholder: 'Markdown ...',
        lang:        (document.documentElement.lang || '').indexOf('zh') === 0 ? 'zh_CN' : 'en_US',
        toolbar:     ['edit-mode','|','bold','italic','strike','headings','|','list','ordered-list','check','outdent','indent','|','quote','line','code','inline-code','insert-before','insert-after','|','link','image','table','|','upload','emoji','math','|','outline','preview','|','fullscreen','devtools','info'],
        preview:     {delay: 300},
        upload:      uid ? {url: linkTo('file', 'ajaxUpload', 'uid=' + uid), fieldName: 'imgFile', format: uploadFormat, linkToImg: true} : undefined,
        cache:       {enable: false}
    });

    form.__mdMode = mode;
    window.MDEDITOR_BOOT.vditor  = vditor;
    window.MDEDITOR_BOOT.mounted = true;
}

/* document 层 capture 拦截 submit：抢在 zui 表单组件（绑在 form 上）之前截断。 */
document.addEventListener('submit', function(e)
{
    var form = e.target;
    if(!form || !form.dataset || form.dataset.mdMounted !== '1') return;
    if(!vditor) return;
    e.preventDefault();
    e.stopImmediatePropagation();
    submitToPlugin(form, e.submitter);
}, true);

function submitToPlugin(form, submitter)
{
    var isDraft   = !!(submitter && submitter.classList && submitter.classList.contains('save-draft'));
    var showTitle = form.querySelector('#showTitle');
    var title     = form.querySelector('#title');
    var titleVal  = ((title && title.value) || (showTitle && showTitle.value) || '').trim();

    if(!titleVal)
    {
        var hint = (showTitle && showTitle.dataset.titleHint) ? showTitle.dataset.titleHint : '';
        if(hint && window.zui && zui.Modal && zui.Modal.alert) zui.Modal.alert(hint);
        else if(title && title.closest('.form-group')) title.closest('.form-group').classList.add('has-error');
        if(title) title.focus();
        return;
    }
    if(title) title.value = titleVal;
    if(showTitle && !showTitle.value) showTitle.value = titleVal;

    /* 草稿放行：标题已有即可保存，跳过发布弹窗其余必填；发布路径的字段校验交给服务端 form::data。 */
    var statusInput = form.querySelector('input[name=status]');
    if(statusInput) statusInput.value = isDraft ? 'draft' : 'normal';
    var ct = form.querySelector('input[name=contentType]');
    if(ct) ct.value = 'markdown';

    var contentField = form.querySelector('textarea[name=content]');
    if(contentField && vditor.getValue) contentField.value = vditor.getValue();

    var url = form.__mdMode === 'create'
        ? linkTo('mdeditor', 'create')
        : linkTo('mdeditor', 'edit', 'docID=' + getDocID(form));
    if(form.__mdMode === 'edit' && !getDocID(form)) { fail('mdeditor: docID not found'); return; }

    var fd = new FormData(form);

    fetch(url, {method: 'POST', body: fd, credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest'}})
        .then(function(r) { return r.json(); })
        .then(function(data)
        {
            if(data.result === 'success')
            {
                form.classList.remove('has-changed');
                if(window.MDEDITOR_BOOT.vditor) { try { window.MDEDITOR_BOOT.vditor.destroy(); } catch(e) {} window.MDEDITOR_BOOT.vditor = null; }
                if(data.load && typeof window.loadPage === 'function') window.loadPage(data.load);
                else if(data.load) location.href = data.load;
            }
            else
            {
                fail(data.message);
                if(data.callback)
                {
                    for(var i = 0; i < CALLBACK_ALLOWLIST.length; i++)
                    {
                        if(CALLBACK_ALLOWLIST[i].re.test(data.callback)) { CALLBACK_ALLOWLIST[i].fn(); break; }
                    }
                }
            }
        })
        .catch(function(err) { fail(String(err)); });
}

function fail(message)
{
    var msg = typeof message === 'string' ? message : JSON.stringify(message);
    if(window.zui && zui.Messager) zui.Messager.show({content: msg, type: 'danger', className: 'bg-danger text-canvas gap-2 messager-fail'});
}

/* 查看页渲染（R2）：与新建/编辑一致，必须走「外部脚本 + pagerender.app」路径，
 * 而非 hook 内联 <script> IIFE——zin morph 注入片段时不会执行内联脚本，SPA 下（含新建后
 * loadPage(doc-view) 跳转）必然不渲染，正是本 bug 根因。markdown 原文由 hook 落到 #md-doc-src
 * 节点的文本内容（textContent，普通 DOM 节点，morph 注入后即可读，不依赖脚本执行；
 * 且保留真实换行，避免 json 属性转义成 \n 导致前端不解码、Vditor 收到单行串的渲染 bug）。 */
function renderMarkdownView()
{
    var src = document.getElementById('md-doc-src');
    if(!src) return;
    var host = document.getElementById('docEditor');
    if(!host) return;
    var docId = src.getAttribute('data-doc') || '';
    if(host.getAttribute('data-md-doc') === docId) return;
    var md = src.textContent;                 /* 原文经 htmlspecialchars 转义，textContent 自动还原且保留真实换行 */
    if(!md) return;
    host.setAttribute('data-md-doc', docId);
    loadVditor().then(function() { paintView(host, md); }, function() { fallbackView(host, md); });
}

/* 渲染侧表格容错：粘贴/导入的 Markdown 常在每个表格数据行之间混入空行，
 * 导致 GFM 表格失效、预览显示为竖线原文（findings §15，doc-276 现象）。
 * 仅在送给 Vditor 预览/编辑的副本上折叠「位于两行表格行之间的空行」，
 * 绝不改动存储原文（逐字节原则保留）。表格与段落之间的空行不受影响。 */
function normalizeTables(s)
{
    var lines = s.replace(/\r\n?/g, '\n').split('\n');
    var isBlank = function(x) { return x.replace(/\s/g, '') === ''; };
    var isRow   = function(x) { return /^\s*\|.*\|\s*$/.test(x); };
    var out = [];
    for(var i = 0; i < lines.length; i++)
    {
        if(isBlank(lines[i]))
        {
            var prev = out.length ? out[out.length - 1] : '';
            var k = i + 1;
            while(k < lines.length && isBlank(lines[k])) k++;
            var next = k < lines.length ? lines[k] : '';
            if(isRow(prev) && isRow(next)) continue;
        }
        out.push(lines[i]);
    }
    return out.join('\n');
}

function paintView(host, md)
{
    while(host.firstChild) host.removeChild(host.firstChild);
    try
    {
        window.Vditor.preview(host, normalizeTables(md), {mode: 'both', cdn: CDN, lang: (document.documentElement.lang || '').indexOf('zh') === 0 ? 'zh_CN' : 'en_US'});
    }
    catch(e) { fallbackView(host, md); }
}

function fallbackView(host, md)
{
    while(host.firstChild) host.removeChild(host.firstChild);
    var pre = document.createElement('pre');
    pre.className = 'whitespace-pre-wrap';
    pre.textContent = md;
    host.appendChild(pre);
}

/* 扫描挂载：SPA 片段渲染后复用。找不到目标即静默退出（R8 防御式）。
 * 支持 morph 复用：#docForm 可能带着上一文档的 dataset.mdMounted 出现，但 morph 会在其中
 * 重注入新的 <zen-editor>。判定必须看"是否仍有原生编辑器"，而不是仅看 mounted 粘滞标记。 */
function scan()
{
    renderMarkdownView();

    var form = document.querySelector('#docForm');
    if(!form) { dbg('scan: no #docForm', location.pathname); return; }
    var hasNative = !!form.querySelector('zen-editor');
    var hasBox    = !!form.querySelector('#md-vditor-box');
    var ctEl      = form.querySelector('input[name=contentType]');
    dbg('scan', {path: location.pathname + location.search, mounted: form.dataset.mdMounted || '0', hasNative: hasNative, hasBox: hasBox, ct: ctEl ? ctEl.value : ''});

    /* 已经接管完成且 morph 未重注入原生编辑器：幂等早退，避免重复 mount 打断用户输入。 */
    if(form.dataset.mdMounted === '1' && hasBox && !hasNative) return;

    var need = isMarkdownCreate(form) || (hasNative && isMarkdownEdit(form));
    if(!need)
    {
        /* morph 复用节点的另一种情况：从 markdown 表单跳到 HTML 表单，mdMounted 粘滞但新表单不该被接管。
         * 卸载残留、清理标记，避免 document 层 submit 捕获错劫 HTML 文档保存。 */
        if(form.dataset.mdMounted === '1')
        {
            dbg('scan: morph switched to non-markdown form, unmounting');
            if(window.MDEDITOR_BOOT && window.MDEDITOR_BOOT.vditor) { try { window.MDEDITOR_BOOT.vditor.destroy(); } catch(e) {} window.MDEDITOR_BOOT.vditor = null; }
            var staleBox = form.querySelector('#md-vditor-box'); if(staleBox && staleBox.parentNode) staleBox.parentNode.removeChild(staleBox);
            var staleCf  = form.querySelector('textarea[name=content]'); if(staleCf && staleCf.parentNode) staleCf.parentNode.removeChild(staleCf);
            delete form.dataset.mdMounted;
            delete form.__mdMode;
        }
        return;
    }

    loadVditor().then(function()
    {
        var f = document.querySelector('#docForm');
        if(!f) { dbg('scan async: form gone'); return; }
        var mode = isMarkdownCreate(f) ? 'create' : (isMarkdownEdit(f) ? 'edit' : '');
        if(!mode) { dbg('scan async: no mode'); return; }
        mount(f, mode);
    }).catch(function(e) { dbg('loadVditor failed', String(e)); });
}

if(document.readyState === 'loading') document.addEventListener('DOMContentLoaded', scan);
else scan();

/* zin.js 有两条 renderPageData 出口：
 *   网络拉取路径 → 发 pagerender.app（zin.js:644）
 *   IndexedDB 本地缓存路径 → 只发 pagecaheload.app（zin.js:772），不发 pagerender.app
 * config.clientCache 默认为 true，第二次站内进入同一 URL 会走缓存路径；必须同时监听两个事件。 */
if(window.jQuery) jQuery(document).on('pagerender.app pagecaheload.app pageload.app', scan);

})();
