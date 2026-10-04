# AGENTS.md — 禅道 Markdown 文档插件（mdeditor）

## 项目概述

禅道开源版 **20.6**（ZIN 框架、PHP 8.1）的独立插件工程：为文档（doc）模块提供 Markdown 编辑与渲染能力。

- **R1** 新建文档时可切换"Markdown"类型（核心新建页 contentType 硬编码 html，由 hook 注入切换）
- **R2** 查看 contentType=markdown 的文档时自动渲染（Vditor.preview）
- **R3** 编辑 markdown 文档时自动挂载 Vditor 编辑器
- **R4** 支持粘贴/拖入本地 .md/.txt 文件内容进编辑器
- **R5** md 文档可上传附件（含 .md 文件，复用禅道 object_type=doc 附件体系）
- **R6** 查看页附件可下载；.md 附件可"查看"（插件 viewfile 路由渲染）
- **R7** 存储与禅道官方文档体系一致：md 文档即既有 `zt_doccontent.type='markdown'` + md 原文，不新增表/字段/私有格式（DESIGN §6.6）
- **R8** 向后兼容：21.x/22.x 原生 markdown 入口存在时自动"让路"——yaml 版本枚举 + 版本阈值/特性探测 + 防御式挂载（DESIGN §6.7）
- **安全合规**：权限两层（框架 filterPriv + 业务层 doc 权限，不新增开放方法）、沿用框架 CSRF、HTML 出口安全矩阵、config/ext 过滤门控严格限定插件 URI（DESIGN §6.8）

核心原则：**零修改禅道核心文件，纯 ZIN hook + 插件自有模块扩展**；数据面不新增表——markdown 文档即 `zt_doccontent.type='markdown'`、content 存 md 原文。

本工程与同级 `../dingtalklogin`（钉钉登录插件）**完全独立**，互不引用、互不部署依赖。

## 目录结构

```
markdown_for_zentao/
├── AGENTS.md                        # 本文件
├── README.md
├── docs/
│   ├── DESIGN.md                    # 完整实现方案（先读这个）
│   └── USER_GUIDE.md                # 使用说明（M5 后补）
├── .planning/md-doc-editor/         # 规划文件（task_plan/findings/progress）
├── tools/                           # 开发辅助（m0_probe.php/M0.md，不入插件 zip）
├── doc/zh-cn.yaml                   # 插件元数据
├── config/ext/mdeditor.php          # 开关；L0/L1 过滤条件性关闭仅限插件写路由 URI
├── www/js/mdeditor/
│   ├── vditor/                      # Vditor dist 自托管资源（锁版本）
│   ├── mdeditor.js                  # 公共挂载/同步/回退逻辑
│   └── mdeditor.css
├── extension/custom/
│   ├── doc/ext/ui/
│   │   ├── create.text.html.hook.php   # R1
│   │   ├── edit.text.html.hook.php     # R3
│   │   └── view.main.html.hook.php     # R2/R6（中间段不可省略！）
│   └── mdeditor/                       # 插件模块：提交路由(Path B')/viewfile + lang/config
└── hook/postinstall.php
```

打包 zip 时以本目录为标准插件根（`config/ extension/ www/ doc/ hook/`）。

## 关键技术事实（20.6 源码调研 + 独立 review 修正，详见 .planning/.../findings.md）

- 正文存储：`zt_doccontent(content longtext, type)`，type=contentType：html/markdown/text（`db/zentao.sql:785-795`）；写入 `module/doc/model.php:1038-1056`，读取 `model.php:880-888`。
- 新建页 `module/doc/ui/create.text.html.php:191-201`：`editor()` + 隐藏域 `contentType='html'` 硬编码 → hook 注入切换按钮；formBase 为 `set::morph()` AJAX 页内重渲染（:165-170）→ **提交失败会摧毁插件挂载，需 MutationObserver 重挂载**。
- 编辑页 `ui/edit.text.html.php:177` 已有 `set::markdown($doc->contentType=='markdown')`；查看页 `ui/view.html.php:226-239` readonly+markdown 属性。**判定 markdown 看 DOM：`<zen-editor markdown>` 属性**。
- **提交链路（review 修正）**：editor 组件的 textarea **无 name、不提交**（`lib/zin/wg/editor/v1.php:128-133`）；真实机制是 `<zen-editor>` 为 form-associated 自定义元素，经 `internals.setFormValue` 参与 FormData（`www/js/zui3/zen-editor/p-c16b5d92.js`），markdown 模式提交 `getMarkdown()` 原文。→ 插件接管 content 必须**摘除**（非隐藏）zen-editor 元素并自备提交控件。
- **后端过滤三层模型（二轮 review + 源码复核，决定提交路径选型）**：**L0** 启动期 `filterSuper($_POST)` 无条件执行 filterTrojan（含 `<?` 替换 eval/include 等）/filterXSS（含 `<script`/`<iframe` 全角化），与路由无关（`framework/base/router.class.php:773`；`lib/base/filter/filter.class.php:655-694`）；**L1** `control=editor` 字段在 **purifier 默认开**（`config/config.php:125`）下走 HTMLPurifier（filter.class.php:1086/1122，**allowedTags 白名单不参与**→扩白名单作废）；`control=textarea/richtext` 在 setSingle 即 skipSpecial 原文直通（`lib/form/form.class.php:169`）。`$filter` 规则对 POST 不执行（router:1819-1823）。→ **Path B'**：插件路由 + textarea 控件 + `config/ext` 按 URI 精准关 L0/L1 开关（config/ext 加载早于过滤：config.php:250 ← router:464）+ 渲染侧防护；M0 逐字节实验终验，见 DESIGN.md §6.0/§6.8。
- ZIN hook glob：`{method}.*.{viewType}.hook.php`（`framework/control.class.php:320-333`）→ create/edit 可用 `create.text.html.hook.php`；**view 页必须 `view.<段>.html.hook.php`**，`view.html.hook.php` 永不命中；**create.text hook 对所有 docType 触发，hook 内须按 docType 门控**。
- **hook 变量作用域**：hook 在 `zin\context::includeHooks()` 中执行（`lib/zin/core/context.class.php:363-375`），视图变量须经 `$this->data` / `$this->control->view` 读取，不能直接 `$doc`。
- dirty 离开提示监听 `'#showTitle,zen-editor'` 的 change（create.text.html.php:172）→ 对 **`<zen-editor>` 元素**派发冒泡 change 事件（对 textarea 派发无效）。
- markdown 类型下 `module/file/model.php:724 processImgURL()` 不重写图片地址 → md 内图片需绝对 web 路径。
- 服务端渲染工具（仅备用）：`commonModel::processMarkdown()`（`module/common/model.php:2248`）。

## 开发规范

- ZIN 视图 hook：原生 `<script>` 注入，不依赖 jQuery；所有输出字符串 `htmlspecialchars`。
- `ui/*.html.php` 若含 `namespace zin;`，必须紧跟 `<?php`（无空行/BOM）；纯 hook 文件不需要。
- Vditor 以 `cdn` 选项指向本地 `/js/mdeditor/vditor`，禁止外部 CDN；锁版本入库。
- 前端资源加载失败必须静默回退 zen-editor 原行为，表单永远可提交。
- XSS：Vditor(Lute) XSSProtect 默认行为**上线前实测**（`<img onerror>` 样本），不足则叠 DOMPurify；raw HTML 开关在 `config/ext/mdeditor.php`，默认禁。

## 部署（服务器侧由用户操作）

主推荐：**标准插件包**——工程根打 zip（`doc/zh-cn.yaml`+`config/`+`extension/`+`www/`+`hook/`），后台"插件管理→本地上传"安装（与钉钉插件同方式）；备选：直接同步文件到 `/apps/zentao/` 对应路径。两种方式使用后都必须：

```bash
rm -rf /apps/zentao/tmp/cache/*
docker exec zentao20.6 /opt/zbox/bin/apachectl restart   # worker 静态缓存，必须重启（postinstall 无法代做）
```

详见 docs/DESIGN.md §9（含 zip 体积/PHP 上传限制、Docker 挂载可写性注意项）。

## 操作约束（继承工作区根 AGENTS.md）

- **禁止**修改服务器任何文件、kill/启动/安装任何应用；服务器仅允许看日志、诊断。
- 部署动作只输出命令由用户执行。
- 禅道参考源码在 `../zentao-src`（只读，勿改）。

## 调试参考

- 验证 contentType 落库：后台 SQL 或 `zt_doccontent` 查 `type`。
- 页面判定：浏览器控制台 `document.querySelector('zen-editor[markdown]')`。
- 若 hook 不生效：确认模块已安装、`tmp/cache/model` 已清、Apache 已重启、文件名符合 `{method}.*.html.hook.php`。

## 当前状态

**M1 骨架完成（2026-10-04，待真机挂载验证）**：M0 服务器实测 ALL PASS；DESIGN §11.6 立项。已产出 yaml/config/ext（URI 门控矩阵 13/13 PASS）/mdeditor 模块占位/三个 doc hook/占位前端 js/css/**Vditor 锁版本 3.10.9 自托管入库**（裁剪后 16MB，公式走 KaTeX）/tools/pack.ps1（正斜杠条目 zip，当前 dist/mdeditor-v0.1.0.zip ≈4.3MB）。注意：zbox 容器 `php -l` 自身 segfault（环境问题），语法校验用 token_get_all。
真机验证步骤：插件管理上传 zip → 清缓存 + 重启 Apache → doc 新建/编辑/查看页源码搜 `mdeditor hook mounted`。
