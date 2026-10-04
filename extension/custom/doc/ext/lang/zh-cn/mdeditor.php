<?php
/**
 * mdeditor 插件对 doc 语言文件的扩展：在文档新建下拉中追加 Markdown 入口（R1）。
 * createbutton.html.php 遍历 $lang->doc->createList 生成菜单项；
 * $config->doc->iconList['markdown'] 核心已存在（doc/config.php:37）。
 */
global $lang;

if(!isset($lang->doc)) $lang->doc = new stdclass();
if(!isset($lang->doc->createList)) $lang->doc->createList = array();

$lang->doc->createList['markdown'] = 'Markdown';
