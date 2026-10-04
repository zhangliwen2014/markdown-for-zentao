<?php
/**
 * M0 保真探针 v2（只读，不写库、不写文件）
 * 用途：在真实禅道 20.6 环境验证 Path B' 的机制断言：
 *   A. L0 默认配置：filterTrojan 按 '<?' 门控 + evil 词表替换生效；filterXSS 按 <script/<iframe 门控生效；无触发形态逐字节直通。
 *   B. L0 开关置 false：全部样本逐字节直通。
 *   C. L1 editor 链（baseFixer::stripTags 等价实现 = stripDataTags + replaceSpace2Tag）：
 *      purifier=true 时走 HTMLPurifier，allowedTags 实参被忽略（扩白名单无效）。
 * v1 修正记录：
 *   - stripDataTags 属 baseFixer 非 baseValidater（v1 C 段 fatal）。
 *   - img_onerror 无 <script/<iframe 门控 → L0 预期改为 identical（防线在渲染侧非 L0）。
 *   - php_code_fence 仅 `<?php echo` 不命中 evil 词表 → 拆为两个样本：trojan_fence（含 include/system/$$/assert，预期 CHANGED）+ php_fence_echo（预期 identical）。
 * 运行：docker exec zentao20.6 php /tmp/m0_probe.php /apps/zentao
 * 期望：全部行输出 PASS；任何 FAIL 即 M0 门槛不过，方案回炉。
 */

$zentaoRoot = isset($argv[1]) ? rtrim($argv[1], '/') : dirname(dirname(__DIR__));
if(!is_file($zentaoRoot . '/framework/router.class.php'))
{
    echo "FAIL: 未找到禅道根目录（参数应传 /apps/zentao 这类路径）\n";
    exit(1);
}

chdir($zentaoRoot);
include $zentaoRoot . '/framework/router.class.php';
include $zentaoRoot . '/framework/control.class.php';
include $zentaoRoot . '/framework/model.class.php';
include $zentaoRoot . '/framework/helper.class.php';

$app    = router::createApp('pms', $zentaoRoot, 'router');
$config = $app->config;

echo "== Zentao M0 probe v2 (read-only) ==\n";
printf("runtime flags: filterTrojan=%s filterXSS=%s purifier=%s\n\n",
    var_export(!empty($config->framework->filterTrojan), true),
    var_export(!empty($config->framework->filterXSS), true),
    var_export(!empty($config->framework->purifier), true));

/* 测试样本：合法 markdown 文档常见形态（nowdoc 原样字面） */
$samples = array(
    'trojan_fence'    => "```php\n<?php include 'x.php'; system('ls'); \$\$var; assert('1'); ?>\n```\n",
    'php_fence_echo'  => "示例：\n```php\n<?php echo 'hello'; ?>\n```\n",
    'script_fence'    => "前端示例：\n```html\n<script>alert(1)</script>\n```\n",
    'autolink'        => "见链接 <https://example.com/a?b=1&c=2> 。\n",
    'lt_operator'     => "当 a < b 且 b > c 时成立。\n",
    'details_raw'     => "<details><summary>展开</summary>内容</details>\n",
    'table_emoji'     => "| 列A | 列B |\n|:---|---:|\n| 中文✓ | 😀 |\n",
    'img_onerror'     => '<img src=x onerror=alert(1)>' . "\n",
);

/* A 段预期：命中 L0 门控+词表的被改写，其余直通 */
$expectA = array('trojan_fence' => false, 'script_fence' => false);

function cmp($name, $label, $in, $out, $expectSame)
{
    $same    = $in === $out;
    $verdict = ($same === $expectSame) ? 'PASS' : 'FAIL';
    echo sprintf("[%s] %-15s %s  in=%s out=%s%s\n", $verdict, $name, $label, md5($in), md5($out), $same ? ' (identical)' : ' (CHANGED)');
    if($verdict === 'FAIL') echo "      out>>> " . str_replace("\n", "\\n", $out) . "\n";
    return $verdict === 'PASS';
}

$allOk = true;

/* --- A. L0 默认配置 --- */
foreach($samples as $name => $text)
{
    $filtered = validater::filterSuper(array('content' => $text));
    $allOk &= cmp($name, 'L0-default', $text, $filtered['content'], !isset($expectA[$name]));
}

/* --- B. L0 开关置 false：全部逐字节直通 --- */
$config->framework->filterTrojan = false;
$config->framework->filterXSS    = false;
foreach($samples as $name => $text)
{
    $filtered = validater::filterSuper(array('content' => $text));
    $allOk &= cmp($name, 'L0-disabled', $text, $filtered['content'], true);
}
$config->framework->filterTrojan = true;
$config->framework->filterXSS    = true;

/* --- C. L1 editor 链（镜像 baseFixer::stripTags 的两步） --- */
$usePurifier = !empty($config->framework->purifier);
foreach(array('autolink', 'details_raw', 'script_fence', 'trojan_fence', 'php_fence_echo') as $name)
{
    $text = $samples[$name];
    $out2 = baseValidater::replaceSpace2Tag(baseFixer::stripDataTags($text, ''));   // 默认路径（allowedTags 空→config 默认）
    $allOk &= cmp($name, 'L1-editor', $text, $out2, false);                          // 预期被改写
    if($usePurifier)
    {
        $out1 = baseValidater::replaceSpace2Tag(baseFixer::stripDataTags($text, '<code><pre><a><details><summary>'));
        $ok1  = ($out1 === $out2);
        $allOk &= $ok1;
        echo sprintf("[%s] %-15s L1-whitelist-noop  扩白名单实参输出%s默认路径\n", $ok1 ? 'PASS' : 'FAIL', $name, $ok1 ? '==(无效，purifier 分支不看它)' : '!=(意外有效!)');
    }
}

echo $allOk ? "\nM0 RESULT: ALL PASS —— Path B' 机制断言成立（textarea 绕 L1 已由源码证实，见 findings §10）\n"
            : "\nM0 RESULT: 存在 FAIL —— 门槛不过，回 DESIGN §6.0 重设计\n";
exit($allOk ? 0 : 2);
