# 禅道 Markdown 文档插件（mdeditor）实现方案

版本：**v1.2**（第二轮 review + 源码复核修订：提交链路重做为 Path B'；新增 R7 存储对齐、R8 向后兼容、§6.8 框架合规与安全机制）
目标环境：禅道开源版 20.6（ZIN 框架，PHP 8.1）；兼容目标：更新版本（21.x/22.x）以"让路"策略处理（§6.7）
工程根目录：`D:\AI-Coding\zentao_ws\markdown_for_zentao`（独立工程，与钉钉插件无关）
选型依据：`docs/EDITOR_COMPARE.md`；证据链：`.planning/md-doc-editor/findings.md` §1–§11

## 1. 需求与目标

| # | 需求 | 验收标准 |
|---|------|---------|
| R1 | 新建文档时有 Markdown 文档选项 | 新建页出现"富文本 / Markdown"切换；选 Markdown 后用 Vditor 编写，保存后 `zt_doccontent.type='markdown'`，content 为 md 原文**无失真** |
| R2 | 查看时 markdown 内容自动渲染 | 打开 md 文档见渲染效果（代码高亮/表格/mermaid），非源码 |
| R3 | 编辑时 md 文档自动用 markdown 编辑器 | 编辑 contentType=markdown 文档自动挂载 Vditor，保存仍是原文 |
| R4 | 支持粘贴/拖入外部 markdown 文件内容 | 本地 `.md/.txt` 文件拖入编辑器或粘贴其内容 → 原文插入编辑区 |
| R5 | 支持上传附件（含 md 文件） | md 文档可挂附件，复用禅道既有附件体系（object_type=doc） |
| R6 | 查看时附件可下载；md 附件可选"查看" | 附件列表 `.md` 条目有"查看"入口 → Vditor 渲染该附件内容；下载沿用核心 |
| R7 | 存储机制与禅道官方文档体系一致 | 不新增表/字段/私有格式；md 文档 = 官方既有 `zt_doccontent.type='markdown'` + 原文（§6.6） |
| R8 | 向后兼容：更新版本禅道仍可使用/不致坏 | 21.x/22.x 上原生能力存在时自动让路、不破坏页面；卸载无数据锁定（§6.7） |

约束：零修改禅道核心文件；纯 HTML 文档行为不变；前端资源自托管；主兼容范围 ZIN 模式。
图片策略分步（已定）：v1 仅 URL 图片；v1.1 增补站内上传——**复用核心 `file-ajaxUpload` + uid，不自建端点**。

## 2. 选型结论（详见 EDITOR_COMPARE.md）

- 编辑/渲染库：**Vditor**（MIT，自托管，ir 编辑模式 + `Vditor.preview()` 渲染，一个库覆盖 R2/R3/R4）。旁证：官方市场同类插件 viewext-209 亦选 Vditor；官方自 18.x 起内置编辑器 markdown 能力不可配置、不可扩展（findings §6）。
- 查看渲染：Vditor.preview（与编辑端同引擎），失败回退核心渲染。
- 提交链路：**Path A / 旧 Path B 均已被第二轮 review 推翻，v1.2 默认 Path B'**（§6.0，M0 实验终验）。

## 3. 核心机制事实（两轮 review 修订，均经 20.6 源码核实）

### 3.1 存储与读取（R7 基线）
- 正文在 `zt_doccontent(content longtext, type)`，type=contentType：html/markdown/text（`db/zentao.sql:785-795`）；写入 `module/doc/model.php:1038-1056`，读取 `model.php:880-888`。
- `zt_doc.type`：text/word/ppt/excel/url/article/attachment（`module/doc/config.php:20`）；contentType 取值 html/markdown/text（`config.php:19`）。
- 核心 edit/view 页已识别 markdown（`edit.text.html.php:177`、`view.html.php:226-239`），仅 create 页无入口（`create.text.html.php:191-201` 硬编码 html）。

### 3.2 提交链路（前端）
- 核心 `editor()` 渲染的 textarea **没有 name、不提交**（`lib/zin/wg/editor/v1.php:128-133`）；真实提交是 **form-associated 自定义元素** `<zen-editor>`（`attachInternals + internals.setFormValue`，`www/js/zui3/zen-editor/p-c16b5d92.js`）。
- 推论：接管 content 提交必须让 `<zen-editor>` **脱离 DOM**（移除而非隐藏——隐藏仍参与 FormData，会重复提交并覆盖），由插件自备提交控件。

### 3.3 过滤器三层模型（第二轮 review 修订，决定 Path 选型）
服务端对提交内容存在三层独立过滤，v1.1 的描述不完整：

| 层 | 位置 | 行为 | 开关（默认） |
|----|------|------|-------------|
| **L0 启动期** | `setSuperVars()` → `validater::filterSuper($_POST)`（`framework/base/router.class.php:773`，早于路由分发） | `filterTrojan`：值含 `<?` 时替换 eval/exec/include/require/assert/$$ 等（`lib/base/filter/filter.class.php:655-667`）；`filterXSS`：值含 `<script`/`<iframe`（含实体形式）时全角化约 20 词（:677-694）。**影响所有 POST，与路由/控件无关** | `framework->filterTrojan/filterXSS`（`config/config.php:122-123`，均 true；调用时读 global $config） |
| **L1 表单控件链** | `baseValidater::get()`（filter.class.php:1360-1386）：`control=editor` 字段无条件 `stripTags(field, allowedTags)`（:1368）→ `stripDataTags` 在 purifier=true 时走 **HTMLPurifier->purify()**（:1086、:1122）；`control=textarea/richtext` 在 `form::setSingle` 即 `skipSpecial`（`lib/form/form.class.php:169、251`）→ **原文直通**（不 stripTags、不 purify、不 htmlspecialchars，:1010-1022） | `framework->purifier`（`config.php:125`，true）。**purifier 路径下 allowedTags 白名单不参与**（仅喂 strip_tags 分支） |
| **L2 显示期转义** | 输出侧 | 视图层 htmlspecialchars 惯例，本插件按既有规范执行 | — |

补充事实：`$filter`/`filterParam` 规则仅作用 GET/COOKIE（router:779-785、:1819-1823）→ **给 POST 字段配 `reg::any` 不执行**（v1.1 Path B 的机制假设作废）。
时序杠杆：`config/ext/*.php` 在 `loadMainConfig()` include `config/config.php` 时即加载（`config.php:250-251`；router:464），**早于 L0** → 插件可按请求 URI 条件性置 L0/L1 开关（§6.0）。

### 3.4 视图渲染现状
- 新建 `create.text.html.php`：editor + 隐藏域 `contentType='html'` 硬编码；form 提交为 `set::morph()` AJAX 页内重渲染（:165-170）——**失败重渲染会摧毁插件已挂载的 Vditor，需重挂载策略**。
- 编辑 `edit.text.html.php:177`：`set::markdown(...)` → DOM 特征 `<zen-editor markdown>`。
- 查看 `view.html.php:226-239`：readonly+hideUI 的 zen-editor 渲染 markdown 原文。
- dirty 离开提示：监听 `'#showTitle,zen-editor'` 的 change（:172）→ 对 `<zen-editor>` 元素本身派发冒泡 change 复用机制。

### 3.5 hook 机制
- glob：`{method}.*.{viewType}.hook.php`（`framework/control.class.php:320-333`）。view 页须命名 `view.main.html.hook.php`（`view.html.hook.php` 永不加载）。
- hook 作用域在 `zin\context::includeHooks()`（`lib/zin/core/context.class.php:363-375`），视图变量须经 `$this->data` / `$this->control->view`。
- **`create.text.html.hook.php` 对 method=create 的全部 docType（word/ppt/excel…）页面都会触发** → hook 内必须按 docType 门控（§6.2）。

### 3.6 图片/附件既有能力
- `module/file/model.php:724 processImgURL()`：markdown 类型跳过图片地址重写 → md 内图片用绝对 web 路径。
- 站内上传（v1.1）：复用核心 `file-ajaxUpload` + uid 临时归属机制，不自建端点。
- 服务端渲染工具（备用）：`commonModel::processMarkdown()`（`module/common/model.php:2248`），默认不用。

## 4. 总体架构

```
doc 新建/编辑/查看页（核心渲染 <zen-editor> + 隐藏域 contentType）
 │
 ├─ create.text hook ─ [docType 门控 + 原生让路检测] 注入"富文本|Markdown"切换
 │     选 Markdown: 摘除 <zen-editor>，挂载 Vditor(ir)，插件自备 textarea[name=content]
 │     form action → Path B': 插件 mdeditor-create/edit（control=textarea 绕 L1）
 │
 ├─ edit.text hook ── 双判定（DOM <zen-editor markdown> + contentType）⇒ 接管；否则零干预
 │
 ├─ view.main hook ── PHP 侧经 $this->data 取 doc.contentType；markdown → Vditor.preview
 │                    附件列表 .md 加"查看"按钮（R6）
 │
 ├─ config/ext/mdeditor.php ─ L0/L1 开关按 URI 精准门控（仅插件写路由，§6.0）
 ├─ mdeditor 模块 ─── Path B' 提交路由 / viewfile 附件渲染页
 │                    （v1.1 图片走核心 file-ajaxUpload，无自建上传端点）
 └─ 静态资源 www/js/mdeditor/vditor/（Vditor dist 自托管锁版本）
```

数据面（R7）：不新增表；md 文档 = `zt_doccontent.type='markdown'` + 原文 content。

## 5. 工程目录结构（zip 即此结构）

```
markdown_for_zentao/
├── doc/zh-cn.yaml                    # 元数据；"适用版本"枚举 20.6.x（R8，§6.7）
├── config/ext/mdeditor.php           # 总开关；L0/L1 过滤条件性关闭门控；预览选项
├── www/js/mdeditor/
│   ├── vditor/                       # 自托管 Vditor dist（锁版本）
│   ├── mdeditor.js                   # 挂载/同步/回退/morph 重挂载/拖入文件/让路检测
│   └── mdeditor.css
├── extension/custom/
│   ├── doc/ext/ui/
│   │   ├── create.text.html.hook.php     # R1（docType 门控）
│   │   ├── edit.text.html.hook.php       # R3
│   │   └── view.main.html.hook.php       # R2/R6（中间段不可省略）
│   └── mdeditor/
│       ├── config/config.php        # 表单定义：content control=textarea
│       ├── control.php              # create/edit 提交（Path B'）、viewfile 渲染
│       ├── model.php                # 复用 docModel 落库；权限逐一校验
│       └── lang/zh-cn.php
├── hook/postinstall.php
├── docs/  DESIGN.md · EDITOR_COMPARE.md · USER_GUIDE.md
└── .planning/md-doc-editor/
```

## 6. 关键实现细节

### 6.0 提交链路设计（v1.2 核心重写）

**已作废方案**：
- ~~Path A（扩 allowedTags 走核心路由）~~：purifier 默认开，editor 字段走 HTMLPurifier，白名单不参与（§3.3）；且核心路由的 content 必经 `form::data()->get()`（`module/doc/control.php:404-407`），L0 破坏依旧 → 无解。
- ~~v1.1 Path B（插件路由 + `reg::any`）~~：POST 不走 `$filter` 规则（§3.3）→ 机制假设不成立；且即便表单层干净，L0 `filterSuper($_POST)` 仍会破坏含 `<?`/`<script>` 的 md 原文。

**Path B'（v1.2 默认方案）= 插件自有路由 + textarea 控件 + URI 门控关 L0**：
1. **绕 L1**：插件自有表单定义 `content => control=textarea` → `setSingle` 即 skipSpecial（form.class.php:169）→ `form::data()->get()` 原文直通。
2. **绕 L0**：`config/ext/mdeditor.php`（加载早于过滤，§3.3）解析 `$_SERVER['REQUEST_URI']` / `$_GET`，**仅当** 本次请求命中 `mdeditor-create|mdeditor-edit`（两种 URL 形态：PATH_INFO 与 `index.php?m=mdeditor&f=create` 都要匹配）时置 `$config->framework->filterTrojan = $config->framework->filterXSS = $config->framework->purifier = false;`。其余请求不受影响。
3. **安全模型（详见 §6.8）**：Path B' 偏离了核心不变式"入库 editor 内容必经 HTMLPurifier"，防护采取**渲染/消费侧消毒 + 消费面审计 + 可选入口严格模式**：
   - **存储语义**：markdown 原文中 `<script>` 出现在代码围栏内是**数据不是标签**，默认拒绝会破坏合法技术文档 → 入口默认保真直通；危险形态防护放在**所有 HTML 出口**（Vditor sanitizer + 兜底 DOMPurify，§6.8 出口矩阵）。
   - **入口护栏**（mdeditor model，保存前）：长度上限（如 ≤2MB）；`config` 提供可选 strict 模式（对含**裸** `<script`/`<iframe` 标签体即非围栏内的内容拒收，默认 off，部署方按内网/外网自择）。
   - **URI 门控面**：仅当命中 `mdeditor-create|mdeditor-edit` 写路由时临时关 L0/L1 开关，两种 URL 形态精确匹配；非插件请求过滤原样。
   - 权限与 CSRF 沿用核心机制（§6.8），写入门槛 = doc 创建/编辑权。
4. 落库复用 `docModel::create()/update()`（model 层对传入数据原样写库），镜像核心 create/edit 的参数面（title/keywords/lib/module/draft/version/labels…）。
5. **共同前端逻辑**（不变）：Markdown 模式下摘除 `<zen-editor>`（存引用以便切回）、插件插入隐藏 `<textarea name=content>`（Vditor change + submit 前双同步）、`contentType` 隐藏域置 `markdown`、form action 改写为插件路由。

**M0 决策实验（开工前置门槛，半天）**：
- 样本 payload：`<?php echo 1; ?>` 代码块、`<script>alert(1)</script>` 代码块、`<https://a.com>` autolink、`a < b`、`<details><summary>x</summary>y</details>`、表格对齐符、中文 + emoji。
- 三路径各提交一次：① 核心 html 富文本；② 核心 markdown 属性路径（zen-editor markdown 提交）；③ Path B' 原型（插件路由 + config/ext 门控）。
- 断言：`zt_doccontent.content` 与源文本逐字节一致（`SELECT` 原样比对）；③ 必须全等，否则方案否决重设计；同时验证两种 URL 形态门控均生效、其他模块 POST 过滤不受影响（回归）。

### 6.1 公共脚本 mdeditor.js

- `mountAsMd(hostEl, text)`：Vditor（mode ir，`cdn:'/js/mdeditor/vditor'`，lang zh_CN）；toolbar 精简（v1 去图片上传钮）。
- 同步：`input/change/blur` + `form submit` capture 双写 `<textarea name=content>`。
- **morph 重挂载**：MutationObserver 监听表单容器，`<zen-editor>` 重现且 contentType 仍 markdown → 重挂 Vditor 并回填（源：提交前缓存，不依赖被摘元素）。
- 回退：`vditor.min.js` 加载失败 → 不摘 zen-editor、不挂 Vditor、contentType 还原 html，页面等价未装插件。
- **让路检测（R8）**：入口先跑 `isNativeMd()`（§6.7），真则整脚本 no-op。
- 防御式：所有 `querySelector` 结果判空，找不到目标静默退出，绝不抛错阻断核心页面（viewext-209 的失败模式即反面教材）。

### 6.2 create.text hook（R1）

- **门控**：PHP 侧取 docType（`$this->data` 视图变量，键名 M1 首日核实；兜底用页面隐藏域 `type` 值），非 `text` 直接 return——word/ppt/excel/url 等新建页零干预。
- **让路**：若检测出该版本新建页已有原生 Markdown 入口（§6.7）→ return。
- 注入"富文本 | Markdown"按钮组；默认富文本（行为同旧版）。
- 切 Markdown：摘 `<zen-editor>` → 挂载 → `contentType=markdown` + form action 改插件路由；切回：confirm（md→富文本仅保留纯文本），还原节点与 action。
- dirty：对 `<zen-editor>`（已摘除则表单容器）派发冒泡 change。

### 6.3 edit.text hook（R3）

- **双判定**：DOM `<zen-editor markdown]` 存在 **且** 视图变量 `doc.contentType=='markdown'`；任一不成立 → 零干预（防 21.x 迁移/改版误判，§6.7）。
- 是 → 取原文挂载 Vditor；接管提交同 §6.0。

### 6.4 view.main hook（R2 + R6）

- PHP 侧判定：`$this->data` / `$this->control->view` 取 doc；`contentType=='markdown'` 才输出脚本。
- 前端：取原文 → `Vditor.preview(el, text, {cdn, hljs, mermaid, math})` 替换 readonly 编辑器区。
- **R6**：附件列表 `.md/.markdown` 条目追加"查看" → 插件路由 `mdeditor-viewfile-<fileID>.html`：校验 file 所属 doc 查看权限 → 读内容 → 渲染页（Vditor.preview + 原文切换 + 下载按钮）。下载沿用核心链接。
- 已知限制：打印页（view.print）v1 不处理。

### 6.5 R4 粘贴/拖入 md 文件

- Vditor 原生支持粘贴纯文本；插件监听编辑区 `dragover/drop`：`.md/.markdown/.txt` → `FileReader.readAsText` → `vditor.insertValue(text)`（多文件按名序拼接）；其余文件不改写。
- 大文件保护：>1MB 提示确认。

### 6.6 R7 存储对齐官方文档体系

- **同库同表同语义**：md 文档就是官方数据模型里已存在的 `zt_doccontent.type='markdown'`（md 原文）。不新增表/列、不引入私有 JSON/包装格式。禅道 20.6 核心的 edit/view 页本就读写该 type（§3.1），插件只是补齐 create 入口与编辑体验——这正是官方 20.8+ 原生演进的同一交互模式（新建时选"富文本/Markdown"，手册 485）。
- 旗舰版/企业版：与开源版同基线（6.6/11.6 ↔ 21.6），未检索到独立 markdown 存储方案，同结构为推测级（findings §11）；即便旗舰新编辑器为块式存储，其**存量 markdown 文档**仍是本约定，且本插件"原文 md 可被任何编辑器再编辑、无厂商锁定"与官方 21.6 迁移口径（"内容格式完全兼容"）方向一致。
- 附件（R5/R6）复用官方 `object_type=doc` 附件体系，零扩展。

### 6.7 R8 向后兼容与"让路"策略

官方事实（findings §11）：20.8+ 新建文档原生提供 Markdown 类型；21.6 起历史文档（含 md）被"无感升级"为新编辑器内容（转换会发生，官方 bug 记录证实）；插件机制 16.x→20.x 曾断裂（hellozentao）；官方 AI 插件采用"版本阈值内置即停用"先例；市场插件 viewext-209 无门控导致新版上体验破损。

三层防御：
1. **安装面**：`doc/zh-cn.yaml` "适用版本"枚举 20.6.x；README 明示"21.x+ 未适配，允许强制安装但按本节让路机制运行"。
2. **运行时让路（PHP hook 层）**：`isNativeMd()` = 版本号阈值（比对 `$config->version`，≥20.8 时新建页原生已有 Markdown 入口 → create hook 让路）∨ 特性探测（view/edit 页 DOM 已具备核心 markdown 渲染且非本插件所加）。让路 = hook 直接 return、脚本 no-op，页面完全交还原生实现。
3. **防御式前端**：所有选择器判空静默；morph/重挂载逻辑仅在自家挂载标记存在时触发；不依赖 21.x 可能变动的内部实现（仅依赖公开 DOM 特征与 ZIN hook 文件约定）。
- **升级 21.6+ 的既有 md 文档**：会被无感升级改写形态（type/内容表示可能变化）→ 插件双判定（§6.3）保证不误接管；迁移后文档由原生编辑器负责渲染。升级前建议（USER_GUIDE 明示）：插件数据无锁定，md 原文可随时导出备份。
- 卸载：删文件 + 清缓存即完全回退。

### 6.8 框架合规与安全机制（用户要求：符合禅道框架/权限体系，不引入漏洞）

**框架面（沿用禅道既有机制，不自造轮子）**：
- **权限两层，全部走核心 API**：① 框架调度层——插件 `mdeditor` 模块方法**不**加入 `$config->openMethods`、不做匿名放行（与钉钉插件场景不同，本插件全部功能仅登录用户可用），未授权账号被框架 filterPriv 直接拒绝；install hook 将 `mdeditor-create/edit/viewfile` 归入与 doc 对应方法相同的权限组语义（zt_grouppriv），卸载时清理。② 业务层——插件 control 逐一调用核心空间/文档权限检查（复用 `docZen::checkPrivForCreate`、`doc` 查看权等既有 model 方法），viewfile 校验 file→doc 归属链权限，防横向越权（IDOR）。
- **CSRF**：沿用框架 `filterCSRF`（referer 校验，router:761-770）+ ZIN 表单提交机制内建防护；插件路由以标准 ZIN form POST 进入，**不新增 JSON/裸端点**。具体 token 校验代码路径 M2 首日核实并纳入测试。
- **数据访问**：全部经 `dao` 参数绑定（禅道规范写法），零字符串拼 SQL。
- **输出**：插件自渲染页面（viewfile 等）所有动态值 `htmlspecialchars`，遵循本工程开发规范。
- **过滤门控合规**：`config/ext` 条件性关闭 L0/L1 仅限插件写路由 URI 精确匹配（含 PATH_INFO 与 query 两种形态），实现为白名单函数 + 单测；**任何情况下不做全局关闭**，其他模块/路由的过滤行为与未装插件时逐字节一致（纳入回归测试 #14）。

**消费/出口安全矩阵（原文 md 入库后，所有会把它变成 HTML 的地方）**：

| 出口 | 处置 | 阶段 |
|------|------|------|
| 插件 view/edit 页（Vditor.preview） | Lute sanitizer 开启；`<img src=1 onerror>` 等样本实测；不足则 DOMPurify 兜底；raw HTML 开关默认禁 | M4 |
| 核心 view 页初始渲染（readonly zen-editor） | 接管即替换；JS 禁用/加载失败时由核心渲染——zen-editor markdown 模式的转义行为列入 M4 实测项，若不安全则回退层改为"仅显示进 textarea 语义的纯文本" | M4 |
| 打印页 view.print | v1 补最小防护：打印 hook 对 markdown 文档做纯文本等宽转义输出（不做富渲染） | M5 |
| 邮件/webhook 通知摘要 | 审计 doc 通知是否携带正文摘要；`processMarkdown`(Parsedown) 放行内联 HTML → 若涉内容，插件路由的通知数据源经 purify 通道 | M2 审计 |
| API（doc api get） | 返回 JSON 原文，不渲染 HTML，无注入面；客户端责任，文档说明 | — |
| viewfile 附件渲染页 | 同 Vditor.preview sanitizer 策略；读文件后仅渲染，不落地执行 | M5 |
| 列表/摘要（标题、keywords 等） | 非 content 字段全部沿用核心 L1/L2 过滤，无豁免 | 天然 |

## 7. 安全设计

| 面 | 措施 |
|----|------|
| md 原文完整性 | Path B'：textarea 绕 L1 + config/ext **URI 精准**关 L0；M0 实验逐字节终验；其他模块过滤行为回归测试（#14） |
| 过滤门控面 | 门控只匹配插件写路由 URI 白名单（两种 URL 形态）；匹配失败即保持默认过滤；严禁全局无条件关闭 |
| XSS（渲染） | 出口矩阵 §6.8：Vditor/Lute XSSProtect **默认行为上线前实测**（`<img src=1 onerror=alert(1)>`）；不足则前端再套 DOMPurify；raw HTML 开关默认禁 |
| XSS（存储面） | 原文入库=偏离核心 purifier 不变式的代价，防护后移至全部 HTML 出口；入口可选 strict 模式（§6.0）供外网部署收紧 |
| 权限 | 全部登录态 + 框架 filterPriv + 业务层 doc 权限双检；viewfile 校验 file→doc 归属，防 IDOR；不新增开放方法（§6.8） |
| CSRF | 沿用框架 filterCSRF + ZIN 表单机制，无自造端点（§6.8） |
| 供应链 | Vditor 自托管、锁版本、入库审计；无外部 CDN |

## 8. 兼容性与回退

- 历史 md 文档（核心 markdown 属性创建的）：编辑/查看自动接管（双判定通过即接管）。
- 卸载插件：文件删除即回退，content 均为 md 原文，可被任何编辑器再编辑，无数据锁定。
- 21.x/22.x：§6.7 三层让路；传统视图模式 v1 不做；打印页仅做最小防护转义（§6.8，M5）。

## 9. 部署与验证

**主推荐：标准插件包安装**（与钉钉插件同方式）——以后端"后台→二次开发→插件管理→本地上传"安装 zip（`extensionModel::install` 链路，`control.php:114/180` 已核实支持上传安装与 `ignoreCompatible` 强装）。zip 以工程根为标准结构：`doc/zh-cn.yaml`（含适用版本枚举 20.6，升级版本时追加）+ `config/` + `extension/` + `www/` + `hook/`。yaml 版本不匹配时安装页会提示，可勾"忽略版本安装"。

安装/卸载后**仍需**：`rm -rf /apps/zentao/tmp/cache/*` → `docker exec zentao20.6 /opt/zbox/bin/apachectl restart`（worker 静态缓存 `helper::import $includedFiles`，钉钉插件已踩实证；postinstall hook 无法代做这步，README 必须写明）。

注意点：
- 整包含 Vditor 自托管资源（5–15MB 级），受 PHP `upload_max_filesize/post_max_size` 限制；上传报错先查这两项，备选=资源目录 scp 直放到 `/apps/zentao/www/js/mdeditor`，主体仍走插件包。
- Docker 挂载目录需 web 进程可写（zbox 镜像默认可写；若宿主机 uid/只读挂载导致解包失败，回退备选：**文件同步部署**——rsync/scp 按 §9 目录映射同步后清缓存重启）。
- 卸载走插件管理即可：文件按清单删除，数据面仅 `zt_doccontent` 的 md 原文（22.0 核心仍原生查看），无锁定残留。

测试清单（v1.2）：
1. **M0 失真实验**（§6.0 三路径 + 双 URL 形态 + 非插件路由过滤回归）；
2. 新建 md：切换→编辑→保存→`zt_doccontent.type='markdown'`、逐字节无失真；
3. 破坏样本入库往返（payload 清单同 §6.0）；
4. XSS 样本：`<img onerror>` 存储→查看渲染不执行；
5. 查看渲染（高亮/表格/mermaid 开关）；
6. 编辑接管 / 非 md 文档零干预双回归；word/ppt/excel 新建页零干预（docType 门控）；
7. morph 失败重渲染后 Vditor 自动重挂载、内容不丢；
8. 富文本默认路径回归（不用插件功能时与旧版逐像素一致）；
9. 拖入 .md / 粘贴大文本；
10. 附件：上传→下载→.md 附件查看→无权限访问 viewfile 被拒；
11. JS 资源失败回退；
12. 富文本↔Markdown 切换 confirm 与内容保全；
13. （R8）模拟让路：临时置版本阈值/原生入口标记，验证 hook return、页面等价未装；
14. （§6.8）门控回归：doc 之外任意模块（如 story 描述）POST 含同类破坏样本 → 过滤行为与未装插件逐字节一致；URI 伪造（`/index.php?m=mdeditor&f=create` 拼缀其他路径段）不触发关过滤；
15. （§6.8）越权与 CSRF：无权限账号 POST create/edit、访问他人文档 viewfile → 拒绝；无 token/非法 referer 直 POST 插件路由 → 被框架拦截。

## 10. 里程碑

| 里程碑 | 内容 | 预估 |
|--------|------|------|
| M0 | 失真实验三路径终验 → 锁定 Path B'（或否决重设计） | 0.5d |
| M1 | 骨架：目录/元数据/lang/Vditor 资源/空 hook（view.main 命名 + docType 门控 + 让路检测）挂载验证 | 0.5d |
| M2 | Path B' 提交接管：摘 zen-editor、textarea 控件、config/ext URI 门控、docModel 复用、morph/dirty/回退 | 2–2.5d |
| M3 | create/edit hook 完整体验：切换、confirm、重挂载 | 1d |
| M4 | view hook：R2 渲染 + sanitizer 实测加固 | 0.5d |
| M5 | R4 拖入粘贴 + R5/R6 附件链路（viewfile；核 create.text 附件区 DOM） | 1–1.5d |
| M6 | 部署验证（§9 全清单）+ USER_GUIDE（含升级/卸载/让路说明） | 0.5–1d |

## 11. 评审结论（2026-10-04 用户确认）

1. **主选库 Vditor（+ 核心渲染静默回退层）——采纳**。
2. **查看渲染 V1（Vditor.preview）——采纳**。
3. **提交链路 Path B'——采纳**，M0 逐字节失真实验为**硬门槛**：B' 原型三路径实验任一失真 → 方案回炉重设计，不带病开工。
4. **R6 md 附件"查看"= 独立页面**（`mdeditor-viewfile-<fileID>.html`，新标签打开；页内含渲染/原文切换、下载、返回文档链接）。理由：不依赖查看页已有资源加载状态、权限校验边界清晰、与 morph/打印页无交互冲突。
5. **R8 让路条件 = 版本阈值 ∨ 原生入口特性探测（取并集）——采纳默认实现**。探测条件按 findings §12 明确为两点：(a) `createList` 出现原生 markdown 新建入口；(b) 新建流被 pageEditor/contentType='doc' 主导。命中任一即 hook 静默让路。
6. **立项结论——开发本插件**（2026-10-04 确认）。依据（开源版 markdown 支持一手核实，findings §12）：
   - 需求缺口成立：20.6 与 22.0 开源版 ZIN UI 均无 markdown 新建入口；官方方向为块编辑器 + md 导入导出，"持续以 md 源码为编写形态"的工作流仍是空白，等官方补齐不可行。
   - 数据零锁定：插件产出即官方约定（`zt_doccontent.type='markdown'` + md 原文），22.0 核心仍原生查看；卸载删文件即回退。
   - 风险受控：过滤失真风险由 Path B' + M0 硬门槛兜住；官方未来补齐原生支持时 R8 三层让路自动隐身（避免 viewext-209 式"新版本变砖"）。
   - 放弃条件：若工作流退化为"偶尔贴入 md、能看即可"，22.0 官方 md 导入 + 块编辑器足够，插件无继续维护价值 → 届时按 §8 卸载回退，无沉没数据。
