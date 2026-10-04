# Markdown 文档编辑插件 (mdeditor)

为禅道 (ZenTao) 文档模块提供 Markdown 编辑与渲染能力的扩展插件。

- 新建文档时可选 **Markdown** 类型；查看页自动用 Vditor 渲染；编辑页自动挂载 Vditor。
- Markdown 原文**逐字节入库**（不被核心过滤器破坏），支持粘贴 / 拖入 `.md`。
- 查看页与编辑页均接管，非 Markdown 文档零开销、完全不受影响。
- Vditor 资源**本地自托管**（锁版本随包入库），不依赖任何外部 CDN。

当前版本：**0.2.7** ｜ 作者：Liwen.Zhang ｜ 许可证：MIT

---

## 1. 运行环境要求

| 项目 | 要求 |
|------|------|
| 禅道版本 | 开源版 **20.6**（ZIN 框架）。当禅道升级到 ≥ `21.7.6` 时插件会**自动静默让路**（见配置 `stepAsideVersion`），避免与原生 Markdown 入口冲突。 |
| PHP | 8.x（本项目实测 PHP 8.1） |
| 部署形态 | Apache mod_php 或 nginx + PHP-FPM 均可；见「安装」中的缓存刷新说明。 |

> 兼容性判定基于「特性 + 版本」双重探测：`config/ext/mdeditor.php` 的 `stepAsideVersion` 是硬阈值。

---

## 2. 安装

### 2.1 打包

在工程根目录执行（产物为 `dist/mdeditor-v<version>.zip`，版本号取自 `doc/zh-cn.yaml`）：

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File tools\pack.ps1
```

打包脚本遵循禅道解包语义：所有内容包在单一顶层目录 `mdeditor/` 下，首个条目为目录条目、条目名用正斜杠。

### 2.2 网页安装

禅道后台 → **二次开发 → 应用（扩展）** → 上传 `mdeditor-vX.Y.Z.zip` → 安装。

### 2.3 安装后必做：刷新运行态缓存 ⚠️

20.6 常运行在 **Apache mod_php 常驻 worker** 下，`helper::import()` 的静态缓存会保留旧的 hook 文件——**只改文件不重启，新逻辑不会生效**（钉钉插件曾因此踩坑）。安装 / 卸载后请执行：

```bash
rm -rf /apps/zentao/tmp/cache/*
docker exec zentao20.6 /opt/zbox/bin/apachectl restart   # nginx+php-fpm 则重启对应服务
```

此步骤无法由插件 web 进程自身代做，需由运维执行。详见 `hook/postinstall.php` 注释。

### 2.4 卸载

后台扩展页卸载后，同样需按 2.3 刷新缓存；卸载即等价关闭（所有 hook 在无插件文件时自然不命中）。

---

## 3. 配置

主配置在 `config/ext/mdeditor.php`（在 `loadMainConfig` 阶段加载，早于框架 L0 过滤，因此可对本请求精准调整开关）：

| 键 | 默认 | 说明 |
|----|------|------|
| `enable` | `true` | 总开关。为 `false` 时所有 hook 直接 `return`，等价未安装。 |
| `rawHtml` | `false` | 渲染侧原始 HTML 开关，默认禁用（防 XSS）。 |
| `vditorCdn` | `/js/mdeditor/vditor` | Vditor 资源前缀，本地自托管。 |
| `stepAsideVersion` | `'21.7.6'` | 禅道 ≥ 此版本时插件静默让路。 |
| `groupPrivs` | create/edit/viewfile 复用 `doc` 权限 | 权限委派，零 SQL、零额外 hook。 |

---

## 4. 工作原理（架构）

```
config/ext/mdeditor.php        全局配置 + Path B' 写路由精准关过滤
extension/custom/mdeditor/     插件自有模块：control.php(create/edit 写路由) / config.php / lang
extension/custom/doc/ext/ui/   doc 模块视图 hook：
  ├─ create.text.html.hook.php 新建页注入 MDEDITOR_CONFIG + mdeditor.js
  ├─ edit.text.html.hook.php   编辑页注入
  └─ view.main.html.hook.php   查看页用 Vditor.preview 接管 #docEditor
www/js/mdeditor/mdeditor.js    前端接管：摘除 zen-editor、按需懒加载 Vditor、劫持表单提交到插件写路由
www/js/mdeditor/vditor/        本地自托管的 Vditor（含 dist）
doc/zh-cn.yaml                 插件元信息（name/code/version/author…），打包脚本读它
hook/postinstall.php           安装后提示（缓存刷新由运维执行）
```

关键设计取舍（详见 `docs/DESIGN.md`、`.planning/md-doc-editor/findings.md`）：

- **Path B' 写路由**：Markdown 新建/编辑经插件自有的 `mdeditor-create` / `mdeditor-edit` 路由提交。仅当 `POST` 且 URI 精确命中这两条写路由时，才对**本次请求**关闭 `filterTrojan/filterXSS`，保证原文逐字节入库；其余请求保持核心默认过滤。伪造 URI 形态不触发关过滤。
- **SPA 安全检测**：禅道 SPA 页面 `location.pathname` 恒为 `/index.html`，前端判定必须用表单自身的 `action` 属性（`/doc-create…-markdown`、`/doc-edit-(\d+)`），`location` 判断仅作直连模式兜底。
- **查看页渲染**：核心 `view.html.php` 只 `set::markdown()`，只读 zen-editor 仍显示原文；插件清空 `#docEditor` 后用 `Vditor.preview` 渲染，资源动态按序注入，加载失败回退纯 `<pre>` 展示原文（防御式，不阻断页面）。
- **ZIN hook 作用域**：ZIN 渲染时 hook 在 `context::includeHooks()` 方法作用域内 include，`$this` 是 context，视图变量须经 `$this->data['doc']` 获取；非 ZIN 路径可直接用 `$doc`。hook 须兼容两种作用域。

---

## 5. 已知约束 / 注意事项

- **mod_php 静态缓存**：安装、卸载、更新后必须刷新 `tmp/cache` 并重启 web 服务（见 2.3），否则旧 hook 仍在服务。
- **反向代理整页缓存**：若经 nginx `proxy_cache` 反代（如本项目的 `zbox.aiaocheng.com`），`doc-view` 这类 GET 页会被整页缓存。直接改库或保存文档后，用户在缓存窗口内可能仍看到旧版；需等缓存过期或用变化 URL 触发 MISS。生产环境建议对动态页收敛缓存或对齐 `Cache-Control: no-store`。
- **zin 内联脚本标签截断**：hook 的内联 `<script>` 里**禁止出现任何 HTML 标签形态的字面量或注释**（zin 序列化会在第一个标签闭合处截断脚本，余下泄漏成页面文本）；构造元素一律用 `createElement`。
- **contentType 不可经编辑路由变更**：核心 `docModel::update` 保留原 `type`。历史遗留的 HTML 型文档若要转 Markdown，需通过 SQL 或重建文档。
- **`mdeditor.js` 引用无版本参数**：浏览器可能缓存旧 JS，验证前请 `Ctrl+F5`；发版验证依赖强刷。

---

## 6. 目录说明

| 路径 | 内容 |
|------|------|
| `config/ext/mdeditor.php` | 插件全局配置与写路由门控 |
| `extension/custom/mdeditor/` | 插件模块（control / config / lang） |
| `extension/custom/doc/ext/` | doc 模块视图 hook 与语言增量 |
| `www/js/mdeditor/` | 前端接管脚本 + 自托管 Vditor |
| `doc/zh-cn.yaml` | 插件元信息（打包脚本读取版本/代号） |
| `docs/` | 设计文档 `DESIGN.md`、编辑器对比 `EDITOR_COMPARE.md` |
| `tools/` | 打包脚本 `pack.ps1`、M0 探测 |
| `dist/` | 打包产物 zip（`.gitignore` 已忽略，勿提交） |
| `hook/` | 安装钩子 |

---

## 7. 许可

MIT。详见 `doc/zh-cn.yaml` 头部版权声明与 `www/js/mdeditor/vditor/LICENSE`（第三方 Vditor 各自许可）。
