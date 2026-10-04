<?php
declare(strict_types=1);
/**
 * The lang file of mdeditor module (zh-cn).
 */
$lang->mdeditor = new stdclass();

$lang->mdeditor->name        = 'Markdown';
$lang->mdeditor->create      = '新建 Markdown 文档';
$lang->mdeditor->edit        = '编辑 Markdown 文档';
$lang->mdeditor->viewFile    = '查看';
$lang->mdeditor->download    = '下载';
$lang->mdeditor->toggleRaw   = '查看原文';
$lang->mdeditor->toggleRender= '渲染视图';
$lang->mdeditor->backToDoc   = '返回文档';

$lang->mdeditor->error = new stdclass();
$lang->mdeditor->error->noPriv      = '无权访问该文档。';
$lang->mdeditor->error->fileNotInDoc= '附件归属校验失败。';
$lang->mdeditor->error->notMarkdown = '该文件不是 Markdown 文本。';
