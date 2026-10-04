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
var CDN    = CONF.cdn    || '/js/mdeditor/vditor';
var WEBR   = CONF.webRoot || '/';
var vditor = null;
var loadPromise = null;

window.MDEDITOR_BOOT = {mounted: false, version: '0.2.4'};

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
    if(form.dataset.mdMounted) return;
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
        value:       initial,
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

/* 扫描挂载：SPA 片段渲染后复用。找不到目标即静默退出（R8 防御式）。 */
function scan()
{
    var form = document.querySelector('#docForm');
    if(!form || form.dataset.mdMounted) return;
    var need = isMarkdownCreate(form) || (form.querySelector('zen-editor') && isMarkdownEdit(form));
    if(!need) return;
    loadVditor().then(function()
    {
        var f = document.querySelector('#docForm');
        if(!f || f.dataset.mdMounted) return;
        if(isMarkdownCreate(f)) mount(f, 'create');
        else if(isMarkdownEdit(f)) mount(f, 'edit');
    }).catch(function() {});
}

if(document.readyState === 'loading') document.addEventListener('DOMContentLoaded', scan);
else scan();

if(window.jQuery) jQuery(document).on('pagerender.app', scan);

})();
