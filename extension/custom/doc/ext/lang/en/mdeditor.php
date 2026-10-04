<?php
/**
 * mdeditor 插件对 doc 语言文件的扩展（英文）：新建下拉追加 Markdown 入口（R1）。
 */
global $lang;

if(!isset($lang->doc)) $lang->doc = new stdclass();
if(!isset($lang->doc->createList)) $lang->doc->createList = array();

$lang->doc->createList['markdown'] = 'Markdown';
