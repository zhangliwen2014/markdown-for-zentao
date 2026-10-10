<?php
/**
 * R2/R6 文档浏览页 hook（中间段必须存在：view.main.html.hook.php，
 * view.html.hook.php 永不命中 glob，findings §10）。
 *
 * 两类输出：
 *   A. 所有 contentType 通用：注入 #docToolbar 的下载按钮（走 pageJS，SPA + 整页都跑）。
 *   B. 仅 contentType=markdown：#md-doc-src 承载原文 + mdeditor.js 接管加载。
 *
 * 下载按钮不能靠 html 通道：zin 用 morph 注入 html 片段，updatePageWithHtml 剥 <script>；且 html
 * 通道内容进的是 main 容器而不是 docToolbar。改走 pageJS 通道（updatePageJS 用 replaceWith 注入
 * 并同步执行），在 mount 时用原生 DOM API 把 <a> 插到 #docToolbar 内 dropdown 之前。幂等：已存在
 * .mdexport-btn 则跳过；每次渲染都会重跑，覆盖 SPA 导航后 morph 重建 toolar 的情况。
 *
 * markdown 渲染逻辑不变，仍在 html 通道放隐藏的 #md-doc-src（textContent 承载原文，逐字节保真），
 * 加载器走 pageJS。历史 <script src> 写法在 SPA 下剥 script，findings §16。
 *
 * ZIN 渲染时 hook 在 context::includeHooks() 方法作用域 include（lib/zin/core/context.class.php:366），
 * 视图变量须从 $this->data 取；非 ZIN parseDefault 路径可直接用 $doc。
 */
global $config, $app;

if(empty($config->mdeditor) || empty($config->mdeditor->enable)) return;
if(!empty($config->version) && version_compare($config->version, $config->mdeditor->stepAsideVersion, '>=')) return;
if(($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return;

if(!isset($doc) || !is_object($doc)) $doc = (isset($this) && property_exists($this, 'data') && is_array($this->data)) ? ($this->data['doc'] ?? null) : null;
if(empty($doc) || !is_object($doc)) return;

$docID       = (int)($doc->id ?? 0);
$webRoot     = $app->getWebRoot();
$cdn         = $webRoot . ltrim($config->mdeditor->vditorCdn, '/');
$version     = $config->mdeditor->version ?? '0.2.15';
$js          = $webRoot . 'js/mdeditor/mdeditor.js?v=' . $version;
$confJson    = json_encode(array('cdn' => $cdn, 'webRoot' => $webRoot), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$isMarkdown  = (($doc->contentType ?? '') === 'markdown');
$exportURL   = helper::createLink('mdeditor', 'export', "docID={$docID}&_single=1");
$exportText  = (string)($lang->mdeditor->download ?? '下载');
$exportHint  = (string)($lang->mdeditor->download ?? '下载');

/* 下载按钮注入（所有 contentType）。 */
$exportJson = json_encode(array('url' => $exportURL, 'text' => $exportText, 'hint' => $exportHint), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$exportJs   = '(function(){var C=' . $exportJson . ';'
            . 'function ins(){var tb=document.querySelector("#docToolbar");if(!tb)return;'
            . 'if(tb.querySelector(".mdexport-btn"))return;'
            . 'var a=document.createElement("a");a.href=C.url;a.className="btn btn-link square mdexport-btn";a.setAttribute("title",C.hint);a.setAttribute("aria-label",C.hint);'
            . 'a.innerHTML="\\u4e0b\\u8f7d";'
            . 'var last=tb.lastElementChild;last?tb.insertBefore(a,last):tb.appendChild(a);}'
            . 'ins();'
            . 'if(window.jQuery){jQuery(document).on("pagerender.app pagecaheload.app pageload.app",ins);}'
            . 'if(window.MutationObserver){var t=null;var fire=function(){if(t)return;t=setTimeout(function(){t=null;ins();},80);};var mo=new MutationObserver(fire);mo.observe(document.body,{childList:true,subtree:true});}})();';
\zin\pageJS($exportJs);

/* 以下 markdown 特有：#md-doc-src + 加载器。 */
if(!$isMarkdown) return;

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
