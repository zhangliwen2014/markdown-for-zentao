<?php
/**
 * mdeditor 插件对 doc 模块 config 的扩展（router.class.php:1907 的 custom 路径）。
 * 加载时机：$app->loadConfig('doc') 时，core 的 module/doc/config.php + config/dtable.php 已合并进
 * $config->doc，且 $lang->doc 可用；因此可以安全追加 actionList 项，把下载入口挂到 doclist dtable
 * 的 actions 菜单里，覆盖 browse / custom / mydoc 等使用同一 dtable 的行操作列。
 *
 * 权限委派：mdeditor::export 走 groupPrivs.export => doc|view（config/ext/mdeditor.php），
 * 与 doc::view 等价。dtable 会依据 hasPriv 自动渲染/隐藏此项。
 */
global $config, $lang;

if(empty($config->mdeditor) || empty($config->mdeditor->enable)) return;
if(!isset($config->doc) || !isset($config->doc->actionList) || !isset($config->doc->dtable->fieldList['actions']['menu'])) return;

$hint = (isset($lang->doc->download) && $lang->doc->download !== '') ? (string)$lang->doc->download : '下载';

$config->doc->actionList['mdexport'] = array(
    'icon'   => 'download',
    'hint'   => $hint,
    'text'   => $hint,
    'url'    => helper::createLink('mdeditor', 'export', 'docID={id}') . '?_single=1',
    'target' => '_blank',
    'rel'    => 'noopener',
);

$menu = &$config->doc->dtable->fieldList['actions']['menu'];
if(is_array($menu) && !in_array('mdexport', $menu, true)) $menu[] = 'mdexport';
unset($menu);
