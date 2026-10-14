<?php
/**
 * mdeditor 插件对 doc 列表页 hook：向每行 actions 单元格注入下载入口。
 *
 * 为什么走 JS 而不是 config 层扩展 $config->doc->dtable->fieldList['actions']['menu']：
 *   doclist.html.php:14 与 mydoclist.html.php:20 在视图内 hardcode 覆盖整个 menu 数组
 *   （$config->doc->dtable->fieldList['actions']['menu'] = array('edit','movedoc','delete')），
 *   config/ext 阶段的 append 会被完全冲掉。dtable menu 覆盖没有 hook 点，只能后置到 DOM 层。
 *
 * 真实渲染结构（headless Edge + CDP 抓取 iframe 内 DOM 实测，非推测）：
 *   - 列表内容在 SPA 里加载于 iframe #appIframe-doc；本 hook 的 pageJS 由 zin 在该 iframe 文档内执行，document 即 iframe。
 *   - zui3 DTable 为列式布局，无 <tbody>/<tr>：每个单元格是 div.dtable-cell，带 data-row=<docID>、data-col=<列名>。
 *     动作单元格选择器：.dtable-cell[data-col="actions"]；docID 直接取 data-row（表头行 data-row="HEADER" 跳过）。
 *   - 动作单元格内是 <div class="dtable-cell-content"><nav class="toolbar">…按钮…</nav></div>，向 nav 前置插入下载按钮。
 *
 * 通道：\zin\pageJS（同步执行，SPA 与整页加载都跑；html 通道会被 updatePageWithHtml 剥 <script>）。
 * 重渲染：DTable 排序/翻页会重建 cell（丢弃 data-mdexp 标记），故监听 pagerender/pagecaheload/pageload + MutationObserver + 轮询兜底。
 * 幂等：cell 级 data-mdexp 标记；target=_blank 避开 SPA _self 拦截（同 view 页修复）。
 */
global $config, $app;

if(empty($config->mdeditor) || empty($config->mdeditor->enable)) return;
if(!empty($config->version) && version_compare($config->version, $config->mdeditor->stepAsideVersion, '>=')) return;

$webRoot   = $app->getWebRoot();
$hint      = (string)($lang->mdeditor->download ?? '下载');
$confJson  = json_encode(array('webRoot' => $webRoot, 'hint' => $hint), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

$js = '(function(){var C=' . $confJson . ';'
    . 'function url(id){var w=C.webRoot||"/";return w.replace(/\\/+$/,"")+"/mdeditor-export-"+id+".html?_single=1";}'
    . 'function ins(){var cells=document.querySelectorAll(".dtable-cell[data-col=\\"actions\\"]");'
    . 'for(var i=0;i<cells.length;i++){var cell=cells[i];if(cell.getAttribute("data-mdexp")==="1")continue;'
    . 'var id=cell.getAttribute("data-row");if(!id||!/^[0-9]+$/.test(id))continue;'
    . 'var host=cell.querySelector(".dtable-cell-content")||cell;var nav=host.querySelector("nav.toolbar")||host;'
    . 'var btn=document.createElement("a");btn.href=url(id);btn.className="btn toolbar-item square size-sm ghost mdexport-btn";'
    . 'btn.target="_blank";btn.rel="noopener";btn.title=C.hint;btn.setAttribute("aria-label",C.hint);'
    . 'btn.innerHTML="<i class=\\"icon icon-download\\"></i>";'
    . 'nav.insertBefore(btn,nav.firstChild);cell.setAttribute("data-mdexp","1");}}'
    . 'ins();'
    . 'if(window.jQuery)jQuery(document).on("pagerender.app pagecaheload.app pageload.app dtablerender.app",ins);'
    . 'if(window.MutationObserver){var t=null;var fire=function(){if(t)return;t=setTimeout(function(){t=null;ins();},120);};'
    . 'var mo=new MutationObserver(fire);mo.observe(document.body,{childList:true,subtree:true});}'
    . 'var k=0;var poll=setInterval(function(){ins();if(++k>=15)clearInterval(poll);},300);})();';

\zin\pageJS($js);
