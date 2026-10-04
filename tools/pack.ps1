# 打包 mdeditor 插件 zip（自检通过的才能上传安装）
# 禅道解包语义（extensionModel::extractPackage + PCLZIP_OPT_REMOVE_PATH）：
#   取 zip 第一个条目的父目录作为包根整体剥离（PHP pathinfo：'mdeditor/doc/' → dirname 'mdeditor'）。
# 因此：所有内容必须包在单一顶层目录（插件代号 mdeditor/）下；第一个条目必须是该结构内的
# 目录条目（如 mdeditor/doc/）；条目名一律正斜杠。钉钉插件可用包结构相同（已比对）。
# 用法：在 markdown_for_zentao 目录执行  powershell -ExecutionPolicy Bypass -File tools\pack.ps1
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression.FileSystem

$root = Split-Path -Parent $PSScriptRoot
$yaml = Get-Content -Raw -Encoding UTF8 -LiteralPath (Join-Path $root 'doc\zh-cn.yaml')
if ($yaml -match '(?m)^code:\s*(\S+)')    { $code = $Matches[1] } else { throw 'code not found in doc/zh-cn.yaml' }
if ($yaml -match '(?m)^version:\s*(\S+)') { $ver  = $Matches[1] } else { throw 'version not found in doc/zh-cn.yaml' }
$dist = Join-Path $root 'dist'
New-Item -ItemType Directory -Force -Path $dist | Out-Null
$zip  = Join-Path $dist "mdeditor-v$ver.zip"
if (Test-Path $zip) { Remove-Item $zip -Force }

function New-Relative([string]$prefix, [System.IO.DirectoryInfo]$base, [System.IO.FileSystemInfo]$item) {
    $rel = $item.FullName.Substring($base.FullName.Length).TrimStart('\')
    if ($rel) { return "$prefix/" + $rel.Replace('\', '/') } else { return $prefix }
}

$archive = [System.IO.Compression.ZipFile]::Open($zip, 'Create')
try {
    # 关键：第一个条目 = 顶层目录（PCLZip 据此 removePath = "mdeditor"）
    [void]$archive.CreateEntry("$code/")
    foreach ($top in @('doc', 'config', 'extension', 'www', 'hook')) {
        $dir = Get-Item -LiteralPath (Join-Path $root $top)
        [void]$archive.CreateEntry("$code/$top/")
        Get-ChildItem -LiteralPath $dir.FullName -Recurse | ForEach-Object {
            $rel = New-Relative "$code/$top" $dir $_
            if ($_.PSIsContainer) { [void]$archive.CreateEntry("$rel/") }
            else { [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($archive, $_.FullName, $rel) | Out-Null }
        }
    }
} finally { $archive.Dispose() }

$size = [math]::Round((Get-Item $zip).Length / 1MB, 2)

# 出厂自检（PCLZip 语义）：第一个条目必须是目录条目且其父目录 == 插件代号目录
Add-Type -AssemblyName System.IO.Compression.FileSystem
$z = [System.IO.Compression.ZipFile]::OpenRead($zip)
try {
    $first = $z.Entries[0].FullName
    if (-not $first.EndsWith('/')) { throw "SELF-CHECK FAIL: first entry '$first' is not a directory entry" }
    $segs = ($first.TrimEnd('/')) -split '/'
    # PHP pathinfo dirname：'mdeditor/' → 'mdeditor'（单段即根）；'mdeditor/doc/' → 'mdeditor'
    $parent = if ($segs.Count -le 1) { $segs[0] } else { ($segs[0..($segs.Count - 2)]) -join '/' }
    if ($parent -ne $code) { throw "SELF-CHECK FAIL: first entry parent '$parent' != '$code' (PCLZip would strip the wrong prefix)" }
    $count = $z.Entries.Count
} finally { $z.Dispose() }

Write-Host "PACKED $zip ($size MB, $count entries, first=$first) - self-check OK"
