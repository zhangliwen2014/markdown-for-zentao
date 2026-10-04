<?php
/**
 * R1 hook of doc create page (ZIN viewType text): markdown 场景下加载接管脚本。
 * 页面级 docType 判定在 JS 层完成（hook 作用域无视图变量，findings §13）；
 * Vditor 资源由 mdeditor.js 按需懒加载，非 markdown 新建零开销。
 */
global $config, $app;

if(empty($config->mdeditor) || empty($config->mdeditor->enable)) return;
if(!empty($config->version) && version_compare($config->version, $config->mdeditor->stepAsideVersion, '>=')) return;

$webRoot = $app->getWebRoot();
$cdn     = $webRoot . ltrim($config->mdeditor->vditorCdn, '/');
$js      = $webRoot . 'js/mdeditor/mdeditor.js';
?>
<script>/* mdeditor hook mounted: create.text */
window.MDEDITOR_CONFIG = {cdn: '<?php echo $cdn; ?>', webRoot: '<?php echo $webRoot; ?>'};</script>
<script src="<?php echo $js; ?>"></script>
<?php
