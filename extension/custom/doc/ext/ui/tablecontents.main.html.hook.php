<?php
/**
 * mdeditor 插件对 doc 列表页 hook：向每行 actions 单元格注入下载入口。
 *
 * 为什么走 JS 而不是 config 层扩展 $config->doc->dtable->fieldList['actions']['menu']：
 *   doclist.html.php:14 与 mydoclist.html.php:20 在视图内 hardcode 覆盖整个 menu 数组
 *   （$config->doc->dtable->fieldList['actions']['menu'] = array('edit','movedoc','delete')），
 *   config/ext 阶段的 append 会被完全冲掉。dtable menu 覆盖没有 hook 点，只能后置到 DOM 层。
 *
 * 通道：\zin\pageJS（updatePageJS 同步执行，SPA 与整页加载都跑；html 通道会被 updatePageWithHtml 剥 <script>）。
 * 事件：首次注入 + 监听 pagerender/pagecaheload/pageload + MutationObserver 兜 dtable 分页/排序后行重建。
 * 目标行：#docTable tbody tr；docID 从行内标题链接 a[href*="/doc-view-"] 的 href 提取（dtable 无 doc-name class）。
 * 幂等：tr.dataset.mdexp 标记；已注入跳过。target=_blank 避开 SPA _self 拦截（同 view 页修复）。
 */
global $config, $app;

if(empty($config->mdeditor) || empty($config->mdeditor->enable)) return;
if(!empty($config->version) && version_compare($config->version, $config->mdeditor->stepAsideVersion, '>=')) return;

$webRoot   = $app->getWebRoot();
$hint      = (string)($lang->mdeditor->download ?? '下载');
$confJson  = json_encode(array('webRoot' => $webRoot, 'hint' => $hint), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

$js = '(function(){var C=' . $confJson . ';'
    . 'function mk(id){var w=C.webRoot||"/";return w.replace(/\/+$/,"")+"/mdeditor-export-"+id+".html?_single=1";}'
    . 'function pid(a){var h=a&&a.getAttribute&&a.getAttribute("href")||"";var m=h.match(/(?:doc-view-|docID=)([0-9]+)/);return m?m[1]:"";}'
    . 'function ins(){var rows=document.querySelectorAll("#docTable tbody tr");'
    . 'for(var i=0;i<rows.length;i++){var tr=rows[i];if(tr.dataset.mdexp==="1")continue;'
    . 'var a=tr.querySelector("a[href*=\"/doc-view-\"]")||tr.querySelector("a[href*=\"docID=\"]");var id=a?pid(a):"";if(!id)continue;'
    . 'var tds=tr.querySelectorAll("td");if(!tds.length)continue;var last=tds[tds.length-1];'
    . 'var btn=document.createElement("a");btn.href=mk(id);btn.className="btn btn-link size-sm square mdexport-btn";'
    . 'btn.target="_blank";btn.rel="noopener";btn.title=C.hint;btn.setAttribute("aria-label",C.hint);'
    . 'btn.innerHTML="\\u4e0b\\u8f7d";'
    . 'last.insertBefore(btn,last.firstChild);tr.dataset.mdexp="1";}}'
    . 'ins();'
    . 'if(window.jQuery)jQuery(document).on("pagerender.app pagecaheload.app pageload.app",ins);'
    . 'if(window.MutationObserver){var t=null;var fire=function(){if(t)return;t=setTimeout(function(){t=null;ins();},120);};'
    . 'var host=document.querySelector("#docTable")||document.body;'
    . 'var mo=new MutationObserver(fire);mo.observe(host,{childList:true,subtree:true});}})();';

\zin\pageJS($js);
