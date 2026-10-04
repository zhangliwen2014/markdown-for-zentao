<?php
/**
 * R2/R6 hook of doc view page（中间段必须存在：view.main.html.hook.php，
 * view.html.hook.php 永不命中 glob，findings §10）。
 * M1 骨架：仅验证挂载 + 门控框架；Vditor.preview 渲染与附件区增强在 M5 实现。
 */
global $config;

if(empty($config->mdeditor) || empty($config->mdeditor->enable)) return;
if(!empty($config->version) && version_compare($config->version, $config->mdeditor->stepAsideVersion, '>=')) return;
?>
<script>/* mdeditor hook mounted: view.main */</script>
<?php
