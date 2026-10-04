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
$webRoot = $app->getWebRoot();
$cdn     = $webRoot . ltrim($config->mdeditor->vditorCdn, '/');
$js      = $webRoot . 'js/mdeditor/mdeditor.js';
?>
<script>/* mdeditor hook mounted: edit.text */
window.MDEDITOR_CONFIG = {cdn: '<?php echo $cdn; ?>', webRoot: '<?php echo $webRoot; ?>'};</script>
<script src="<?php echo $js; ?>"></script>
<?php
