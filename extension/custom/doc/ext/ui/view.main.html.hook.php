<?php
/**
 * R2/R6 文档浏览页 hook（中间段必须存在：view.main.html.hook.php，
 * view.html.hook.php 永不命中 glob，findings §10）。
 * M5：contentType=markdown 时用 Vditor.preview 接管 #docEditor；其余页面静默。
 * ZIN 渲染时 hook 在 context::includeHooks() 方法作用域 include（lib/zin/core/context.class.php:366），
 * 视图变量须从 $this->data 取；非 ZIN parseDefault 路径可直接用 $doc。
 * 严禁在 script 元素内出现任何 HTML 标签形态的字符串字面量或注释（zin 序列化会在第一个标签闭合处截断，findings §16）。
 */
global $config;

if(empty($config->mdeditor) || empty($config->mdeditor->enable)) return;
if(!empty($config->version) && version_compare($config->version, $config->mdeditor->stepAsideVersion, '>=')) return;
if(($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return;

if(!isset($doc) || !is_object($doc)) $doc = (isset($this) && property_exists($this, 'data') && is_array($this->data)) ? ($this->data['doc'] ?? null) : null;
if(empty($doc) || !is_object($doc)) return;
if(($doc->contentType ?? '') !== 'markdown') return;

$cdn    = (string)$config->mdeditor->vditorCdn;
$mdJson = json_encode((string)($doc->content ?? ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<script>
/* mdeditor view.main：动态加载保证资源顺序；JSON_HEX_TAG 保证文档内容不逃逸 script 上下文；加载失败走纯 DOM 兜底。 */
(function()
{
    var CDN = <?php echo json_encode($cdn, JSON_UNESCAPED_SLASHES); ?>;
    var MD  = <?php echo $mdJson; ?>;

    function render()
    {
        var host = document.getElementById('docEditor');
        if(!host || host.dataset.mdRendered) return;
        host.dataset.mdRendered = '1';
        if(!window.Vditor) return ensure(function() { paint(host); });
        paint(host);
    }

    function paint(host)
    {
        while(host.firstChild) host.removeChild(host.firstChild);
        try
        {
            window.Vditor.preview(host, MD, {mode: 'both', cdn: CDN, lang: (document.documentElement.lang || '').indexOf('zh') === 0 ? 'zh_CN' : 'en_US'});
        }
        catch(e) { fallback(); }
    }

    function ensure(cb)
    {
        if(!document.getElementById('mdeditor-vditor-css'))
        {
            var css = document.createElement('link');
            css.id = 'mdeditor-vditor-css';
            css.rel = 'stylesheet';
            css.href = CDN + '/dist/index.css';
            (document.head || document.documentElement).appendChild(css);
        }
        var s = document.createElement('script');
        s.src = CDN + '/dist/index.min.js';
        s.onload = function() { window.Vditor ? cb() : fallback(); };
        s.onerror = fallback;
        (document.head || document.documentElement).appendChild(s);
    }

    function fallback()
    {
        var host = document.getElementById('docEditor');
        if(!host) return;
        while(host.firstChild) host.removeChild(host.firstChild);
        var pre = document.createElement('pre');
        pre.className = 'whitespace-pre-wrap';
        pre.textContent = MD;
        host.appendChild(pre);
    }

    if(document.readyState === 'loading') document.addEventListener('DOMContentLoaded', render);
    else render();
    if(window.jQuery) jQuery(document).on('pagerender.mdeditor', render);
})();
</script>
<?php
