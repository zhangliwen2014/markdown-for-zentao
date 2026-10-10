<?php
/**
 * R1 hook of doc create page (ZIN viewType text): markdown 场景下加载接管脚本。
 * 页面级 docType 判定在 JS 层完成（hook 作用域无视图变量，findings §13）；
 * Vditor 资源由 mdeditor.js 按需懒加载，非 markdown 新建零开销。
 */
global $config, $app;

if(empty($config->mdeditor) || empty($config->mdeditor->enable)) return;
if(!empty($config->version) && version_compare($config->version, $config->mdeditor->stepAsideVersion, '>=')) return;

$webRoot  = $app->getWebRoot();
$cdn      = $webRoot . ltrim($config->mdeditor->vditorCdn, '/');
$version  = $config->mdeditor->version ?? '0.2.11';
$js       = $webRoot . 'js/mdeditor/mdeditor.js?v=' . $version;
$confJson = json_encode(array('cdn' => $cdn, 'webRoot' => $webRoot), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

/* SPA 下 zin.js updatePageWithHtml 会剥掉 html 片段里的 <script>（仅保留 window.config={），
 * 历史版本写成 html 通道的 <script src>，站内进新建页不加载 mdeditor.js、Vditor 不挂载，
 * 只显示原生 wiki 工具栏（刷新才对）。改走 pageJS 通道：updatePageJS 注入并同步执行，SPA/整页皆有效。
 * 页面级 docType 判定仍在 mdeditor.js 的 DOM 层完成（hook 作用域无视图变量）；加载器幂等。 */
$loader = '(function(){if(!window.MDEDITOR_CONFIG){window.MDEDITOR_CONFIG=' . $confJson . ';}'
        . 'if(!window.__MDEDITOR_SCRIPT){var s=document.createElement("script");s.async=true;s.src=' . json_encode($js, JSON_UNESCAPED_SLASHES) . ';window.__MDEDITOR_SCRIPT=s;document.head.appendChild(s);}})();';
\zin\pageJS($loader);
