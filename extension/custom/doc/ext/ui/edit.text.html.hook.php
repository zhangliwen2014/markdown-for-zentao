<?php
/**
 * R3 hook of doc edit page (ZIN viewType text): contentType=markdown 时加载接管脚本。
 * contentType 判定在 JS 层读取隐藏域完成（hook 作用域无视图变量，findings §13）。
 */
global $config, $app;

if(empty($config->mdeditor) || empty($config->mdeditor->enable)) return;
if(!empty($config->version) && version_compare($config->version, $config->mdeditor->stepAsideVersion, '>=')) return;

/* hook glob 会命中 edit 的所有视图，无法在 PHP 侧拿 contentType（hook 无视图变量），
 * 故对所有 text 编辑页输出加载标签；mdeditor.js 自带 DOM 判定，非 markdown 文档静默退出。 */
$webRoot  = $app->getWebRoot();
$cdn      = $webRoot . ltrim($config->mdeditor->vditorCdn, '/');
$version  = $config->mdeditor->version ?? '0.2.11';
$js       = $webRoot . 'js/mdeditor/mdeditor.js?v=' . $version;
$confJson = json_encode(array('cdn' => $cdn, 'webRoot' => $webRoot), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

/* SPA 下 zin.js updatePageWithHtml 会剥掉 html 片段里的 <script>，历史版本写成 html 通道的
 * <script src>，站内进编辑页不加载 mdeditor.js：工具栏仍是原生 wiki、内容显示错乱（刷新才对）。
 * 改走 pageJS 通道（updatePageJS 注入并同步执行），SPA 与整页加载都会挂载 Vditor。加载器幂等。
 * hook glob 命中 edit 全部视图，contentType 判定仍在 mdeditor.js 的 DOM 层读隐藏域完成，非 markdown 静默退出。 */
$loader = '(function(){if(!window.MDEDITOR_CONFIG){window.MDEDITOR_CONFIG=' . $confJson . ';}'
        . 'if(!window.__MDEDITOR_SCRIPT){var s=document.createElement("script");s.async=true;s.src=' . json_encode($js, JSON_UNESCAPED_SLASHES) . ';window.__MDEDITOR_SCRIPT=s;document.head.appendChild(s);}})();';
\zin\pageJS($loader);
