<?php
/**
 * mdeditor 插件全局配置（config/ext 会在 loadMainConfig 阶段被加载，早于 setSuperVars 的 L0 过滤，
 * 因此下面的 URI 门控可以精准影响本请求的 L0 开关。严禁全局无条件关闭过滤。）
 */
global $config;

$config->mdeditor = new stdclass();
$config->mdeditor->enable       = true;   // 总开关：false 时所有 hook 直接 return，等价未安装
$config->mdeditor->rawHtml      = false;  // 渲染侧 raw HTML 开关，默认禁（DESIGN §7 XSS）
$config->mdeditor->vditorCdn    = '/js/mdeditor/vditor'; // 本地自托管，禁止外部 CDN（锁版本入库）
$config->mdeditor->stepAsideVersion = '21.7.6'; // R8 让路版本阈值：当前禅道 >= 此版本时插件静默让路（与原生入口特性探测取并集）

/* 权限委派：mdeditor 路由复用 doc 权限，零 SQL、零 hook（commonModel::hasPriv 一手确认，findings §13）。 */
$config->mdeditor->groupPrivs = array(
    'create'   => 'doc|create',
    'edit'     => 'doc|edit',
    'viewfile' => 'doc|view',
);

/* Path B'：对插件自有写路由（POST）精准关闭 L0 破坏性过滤，保证 markdown 原文逐字节入库。
 * 门控条件：POST + URI 精确命中 mdeditor 的 create/edit 写路由（pathinfo 与 index.php?m= 两种形态）。
 * 任一条件不满足即保持核心默认过滤（DESIGN §9 测试 #14 覆盖伪造形态）。 */
if(!empty($config->mdeditor->enable) && !empty($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST')
{
    $isMdeditorWrite = false;
    $uriPath = parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '', PHP_URL_PATH);
    if($uriPath !== null && $uriPath !== false)
    {
        /* pathinfo 形态：.../mdeditor-create.html、.../mdeditor-edit-5.html 等 */
        if(preg_match('#(?:^|/)mdeditor-(?:create|edit)(?:-[^/]*)?\.html$#', $uriPath)) $isMdeditorWrite = true;

        /* query 形态：/index.php?m=mdeditor&f=create|edit */
        if(!$isMdeditorWrite && (substr($uriPath, -9) === 'index.php' || $uriPath === '/'))
        {
            $query = array();
            parse_str((string)parse_url($_SERVER['REQUEST_URI'])['query'], $query);
            if(isset($query['m']) && $query['m'] === 'mdeditor' && isset($query['f']) && in_array($query['f'], array('create', 'edit'), true)) $isMdeditorWrite = true;
        }
    }
    if($isMdeditorWrite)
    {
        $config->framework->filterTrojan = false;
        $config->framework->filterXSS    = false;
    }
}
