<?php
/**
 * R2/R6 文档浏览页 hook（中间段必须存在：view.main.html.hook.php，
 * view.html.hook.php 永不命中 glob，findings §10）。
 * M5：contentType=markdown 时接管 #docEditor 渲染；其余页面静默。
 *
 * 渲染逻辑不在本 hook 内联执行：zin SPA 用 morph 注入 html 片段，updatePageWithHtml 会剥掉其中的 <script>
 * （仅保留 window.config={），故无论内联 IIFE 还是 <script src>，写在 html 通道都在 SPA 导航
 * （含新建后 loadPage(doc-view) 跳转）下失效。正确做法与新建/编辑一致：
 *   1) 把 markdown 原文放进普通 DOM 节点的文本内容（#md-doc-src 的 textContent，走 html 通道，morph 注入后可读）；
 *      原文经 htmlspecialchars(ENT_QUOTES) 转义，textContent 自动还原，逐字节保真且保留真实换行；
 *   2) 接管脚本的加载改走 pageJS 通道（\zin\pageJS）：updatePageJS 用 replaceWith 注入并同步执行，
 *      SPA 与整页加载都会跑；mdeditor.js 的即时 scan + pagerender.app 监听在每次渲染后接管。
 *
 * ZIN 渲染时 hook 在 context::includeHooks() 方法作用域 include（lib/zin/core/context.class.php:366），
 * 视图变量须从 $this->data 取；非 ZIN parseDefault 路径可直接用 $doc。
 * 曾用 data-md 属性 + json_encode 承载原文：但 json 把真实换行转义成 \n，而前端 getAttribute 不做
 * JSON 解码，导致 Vditor 收到单行转义串、markdown 完全不渲染（本 bug 根因，findings §16）。
 */
global $config, $app;

if(empty($config->mdeditor) || empty($config->mdeditor->enable)) return;
if(!empty($config->version) && version_compare($config->version, $config->mdeditor->stepAsideVersion, '>=')) return;
if(($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return;

if(!isset($doc) || !is_object($doc)) $doc = (isset($this) && property_exists($this, 'data') && is_array($this->data)) ? ($this->data['doc'] ?? null) : null;
if(empty($doc) || !is_object($doc)) return;
if(($doc->contentType ?? '') !== 'markdown') return;

$webRoot = $app->getWebRoot();
$cdn     = $webRoot . ltrim($config->mdeditor->vditorCdn, '/');
$version = $config->mdeditor->version ?? '0.2.11';
$docID   = (int)($doc->id ?? 0);
$js      = $webRoot . 'js/mdeditor/mdeditor.js?v=' . $version;
$confJson = json_encode(array('cdn' => $cdn, 'webRoot' => $webRoot), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

/* SPA 下 zin.js updatePageWithHtml 会剥掉 html 片段里的 <script>（仅保留 window.config={），
 * 历史版本把接管脚本写成 html 通道的 <script src>，站内导航（含新建后 loadPage(doc-view) 跳转）
 * 必然不加载 mdeditor.js、不渲染。正确通道是 pageJS：updatePageJS 用 replaceWith 注入并同步执行，
 * SPA 与整页加载都会跑。加载器幂等：window.__MDEDITOR_SCRIPT 已存在则不重复注入；config 缺省时补齐。
 * markdown 原文仍由下方 <div> 文本承载（非 script，morph 注入后 textContent 可读）。 */
$loader = '(function(){if(!window.MDEDITOR_CONFIG){window.MDEDITOR_CONFIG=' . $confJson . ';}'
        . 'if(!window.__MDEDITOR_SCRIPT){var s=document.createElement("script");s.async=true;s.src=' . json_encode($js, JSON_UNESCAPED_SLASHES) . ';window.__MDEDITOR_SCRIPT=s;document.head.appendChild(s);}})();';
\zin\pageJS($loader);
?>
<div id="md-doc-src" hidden data-doc="<?php echo $docID; ?>"><?php echo htmlspecialchars((string)($doc->content ?? ''), ENT_QUOTES); ?></div>
<?php
