# 选型对比分析：Markdown 编辑库 与 查看渲染方案

状态：v1.2（第二轮 review 修订：候选 C 进一步降级、共同风险升级为"过滤三层模型"、Path 结论改 Path B'；证据见 findings §10/§11）
关联：`docs/DESIGN.md`（总体方案）、`.planning/md-doc-editor/findings.md`（源码与外部调研依据）

## Part 1：Markdown 编辑/显示库三选一

### 候选 A：Vditor（vanessa219/vditor，MIT）

定位：浏览器端 Markdown 编辑器组件，为集成而生。三模式：所见即所得（wysiwyg）、即时渲染（ir，类 Typora）、分屏预览。

与本项目需求的匹配度：
- **编辑**：ir 模式下用户书写的就是 markdown 源码（ Typora 式），编辑器 API `getValue()` 直接返回 md 原文 → 与 `zt_doccontent.content`（md 原文）零转换。
- **显示**：同一库自带 `Vditor.preview(el, mdText, options)` 独立渲染器（markdown 排版引擎 Lute），支持 GFM 表格、任务列表、代码高亮（hljs）、数学公式（KaTeX/MathJax）、mermaid/流程图/甘特图/时序图 → 一个依赖覆盖 R2+R3，无版本配对问题。
- **集成方式**：纯 DOM 组件（`new Vditor(el, {...})`），非框架绑定，适合在 ZIN hook 里用原生 JS 挂载到禅道隐藏 `textarea[name=content]` 旁；`cdn` 选项可指向本地自托管目录 `/js/mdeditor/vditor`，不依赖外部 CDN。
- **中文**：i18n 内置 zh_CN，工具栏/右键菜单全中文；社区（B3log）中文讨论活跃，国内被思源笔记、Halo、Sym 等大型项目采用。
- **维护现状**（截至检索，见 findings §7）：3.10.x 系列，npm/gitee 镜像活跃。
- **体积/风险**：完整 dist（含 hljs、mermaid、math 懒加载资源）自托管约 5–15MB 级，可选裁剪；Lute 引擎为 WASM/TS 混合产物，深改渲染逻辑成本高，但我们只做配置级集成；作者一人主导（bus factor 低），需**锁版本入库**，出问题可整体换库（数据面无耦合，存的就是 md 原文）。

### 候选 B：TOAST UI Editor（nhn/tui.editor，MIT）

定位：Markdown + WYSIWYG 双模编辑器，韩国 NHN 出品。

- 优点：架构成熟（ProseMirror 内核），插件体系好，Viewer 组件渲染质量高，i18n 含 zh_CN。
- 维护风险：镜像仓库最后提交停在 2023-02（v3.2.x wrapper）；2025-02 有社区 issue 公开宣称停维护，但该 issue 正文推广替代包、动机存疑，仅作旁证不作主据。即便按"仍在维护"评估，其下方集成成本劣势依然成立。
- 集成成本高于 Vditor（这是与 A 拉开差距的主因）：编辑组件与 Viewer 是两个 bundle（`toastui-editor` / `toastui-editor-viewer`），样式/主题需分别对齐；官方推荐 npm+webpack 打包，纯 script 标签自托管要手工整理 dist；查看端与编辑端是两套渲染代码路径，一致性维护成本翻倍。
- 结论：**排除**（集成成本 + 双 bundle 一致性负担 + 维护停滞旁证，且没有 Vditor 之外的独特必需能力）。

### 候选 C：禅道内置 zen-editor 增强（不引外部库）

定位：核心 `edit.text.html.php:177` / `view.html.php:233` 已有 `markdown` 属性支持，插件只在 `create.text` 注入切换入口 + 把 `contentType=markdown` 落库，编辑/查看完全走核心。

- 优点：零新增资源、零体积、零供应链风险；改动面最小（约 1 个 hook + 1 个 JS）；升级禅道版本时无需跟随外部库。
- 缺点（源码实测，findings §6）：
  - 实现是**无 sourcemap 的压缩分包产物**（monaco/tiptap 混合，约百个 chunk），markdown 模式的确切能力、语法覆盖面、渲染质量无法从源码确认；
  - 不可配置：无代码高亮主题、mermaid、公式、工具栏定制入口；发现渲染 bug 只能绕行，无法修复；
  - 编辑体验未知（zen-editor 的 markdown 属性更像"渲染开关"而非"markdown 源码编辑器"，Typora 式体验大概率没有）；
  - 若实际效果差，返工时插件架构仍需重做；
  - **v1.2 追加降级依据**：官方演进显示 20.8+ 新建文档已原生提供 Markdown 类型入口（走新编辑器），21.6 起历史文档无感升级——核心 markdown 是"渲染开关"形态且方向上将由原生新编辑器接管，候选 C 的"注入切换入口"价值会随版本升级被官方实现覆盖甚至冲突（DESIGN §6.7 让路策略即为此预留）。
- 结论：**作为兜底层保留，不作为主方案**（v1.2：评分维中"随禅道走"的维护分不再构成优势，反而是不确定性）。

### 横向打分（权重面向本项目诉求）

| 维度（权重） | A Vditor | B TOAST UI | C 内置增强 |
|---|---|---|---|
| md 源码编辑体验 (25%) | 5 ir 模式即 Typora 体验 | 4 | 2 未知/黑盒 |
| 查看渲染质量 (20%) | 5 preview 一体化，高亮/图表/公式可配 | 4 Viewer 好但需另配 | 2 不可控 |
| 集成成本 (15%) | 4 纯 DOM，一次挂载 | 3 双 bundle+打包 | 5 几乎为零 |
| 维护与安全 (15%) | 4 活跃但单人主导 | 1 停维护旁证（见上，非主据） | 3 随禅道走 |
| 本地化/中文 (10%) | 5 | 4 | 5 |
| 体积/自托管 (10%) | 3 资源较重可裁剪 | 3 | 5 |
| **加权总分（实算）** | **4.25** | **3.10** | **3.10** |

A 领先 B/C 约 1.15 分，主要来自编辑体验+渲染质量+维护三项；B、C 打平——C 靠零成本追平 B，若 Part 1 的"不可控"实测后表现尚可，C 仍是低成本的次优解。

### 共同关键风险（v1.2 升级为"过滤三层模型"，任何候选都绕不开）

第二轮 review + 源码复核确认（findings §10）：后端对提交内容的破坏点不止一处，且 v1.1 认知有误——

- **L0 启动期**：`filterSuper($_POST)` 对所有 POST 无条件执行 `filterTrojan`（含 `<?` 时替换 eval/include 等）/`filterXSS`（含 `<script`/`<iframe` 时全角化约 20 词）（`framework/base/router.class.php:773`），与路由、控件无关；
- **L1 表单层**：`control=editor` 字段在 purifier **默认开启**（`config/config.php:125`）下走 `HTMLPurifier->purify()`（`lib/base/filter/filter.class.php:1086/1122`）——**allowedTags 白名单不参与该分支**，v1.1 的"扩白名单"（Path A）因此作废；`control=textarea/richtext` 则 skipSpecial 原文直通（`lib/form/form.class.php:169`）；
- `$filter` 规则对 POST 不执行（router:1819-1823），v1.1 Path B 的 `reg::any` 假设亦作废。

这不是选库问题，是提交链路设计问题 → 解法为 **Path B'**（插件路由 + textarea 控件绕 L1 + config/ext 按 URI 精准关 L0 三开关 + 渲染侧防护），详见 DESIGN.md §6.0/§6.8，M0 实验逐字节终验。无论选 A 还是 C，"纯前端 hook、后端零改动"的原设想都不成立。

### 推荐（Part 1）

**主选 A（Vditor），C 作为资源加载失败时的静默回退层**（DESIGN.md §6.1 的回退逻辑即为此设计：外部 JS 异常时不劫持表单，核心编辑器原样工作）。B 排除。

理由要点：数据面与库完全解耦（存的永远是 md 原文），A 的锁定风险是"体验风险"而非"数据风险"，未来可整库替换；而其提供的 ir 编辑 + preview 渲染一体化，恰好是 R1–R3 全链路一次满足。旁证（v1.2）：官方市场同类插件 viewext-209"增强Markdown编辑器"同样选择 Vditor（其失败在**无版本门控**，教训已并入 DESIGN §6.7）。

---

## Part 2：查看 markdown 文档的渲染方式三选一

前提事实：`zt_doccontent.content`（markdown 类型）存 md 原文；查看页 `view.html.php:226-239` 由核心把原文塞进 `<zen-editor markdown readonly hideUI>` 在前端渲染。

### 方案 V1：前端 Vditor.preview 替换渲染（与 Part1-A 配套）

- 做法：view hook 检测到 `$doc->contentType=='markdown'` → 取原文 → `Vditor.preview()` 渲染进内容区。
- 优：与编辑端同源同配置（高亮主题、mermaid、公式在两端表现一致）；效果可定制可调试。XSS 防护：Vditor 底层 Lute 引擎有 XSSProtect（**默认行为需在上线前实测确认**，实测用例 `<img src=1 onerror=...>`；配置开关规划在 `config/ext/mdeditor.php`）。
- 劣：查看页也要加载 vditor 资源（首屏 ~百 KB 级 + 懒加载图表面）；打印页不做富渲染（v1.2：按 DESIGN §6.8 出口矩阵，打印页对 md 文档做纯文本转义最小防护）；若资源加载失败需回退核心渲染（已在设计中，核心渲染转义行为列入 M4 实测）。

### 方案 V2：保留核心 zen-editor 只读渲染（与 Part1-C 配套）

- 做法：view 不做任何事，依赖核心 markdown 属性渲染；插件仅保证 contentType 正确落库。
- 优：零额外改动、零额外资源、打印/既有样式天然一致。
- 劣：质量黑盒（见 Part1-C）；无代码高亮定制、无 mermaid/公式；两端（编辑 Vditor / 查看 zen-editor）渲染引擎不同 → 同一文档效果可能不一致，排查困难。

### 方案 V3：服务端 Parsedown 预渲染（core 已有 `commonModel::processMarkdown()`，`module/common/model.php:2248`）

- 做法：hook 中 PHP 侧把 md 转 HTML 直接输出（需模板注入点配合，可能要在 hook 里 replace DOM，或由 `processMarkdown` 生成后再交给页面）。
- 优：无 JS 也可见（爬虫/打印友好）；资源最省；服务端渲染一致性强。
- 劣：Parsedown Extra 语法覆盖有限（任务列表不稳、无高亮/公式/mermaid，需再叠 JS 库 → 比 V1 多一套链路）；禅道核心把它用于 mail/gitlab 通知，非页面渲染管线，hook 里改造输出侵入模板结构的风险高；两端引擎不一致问题比 V2 更严重（服务端 Extra vs 前端编辑器）。此外服务端拿到的 content 同样已经历保存时的 `stripTags` 过滤（见"共同关键风险"），若提交链路处理不当，V3 只是把已破损的内容更早渲染出来。
- 结论：**排除**，仅在"未来做 PDF 导出/邮件推送"时作为服务端渲染工具复用。

### 推荐（Part 2）

**V1**，并保留"资源失败 → 不替换、由核心渲染兜底"的回退（即 V2 自动成为 fallback，不冲突）。V3 排除。

理由：编辑与查看同一渲染引擎是"查看效果与编辑预览一致"的最直接保障，也是三处需求体验统一的关键；额外代价（查看页引一份静态资源）在自托管+懒加载下可控。

---

## 已定事项（用户确认）

- 图片方案分两步：v1 仅 URL 图片引用 → v1.1 增补禅道内上传端点（Vditor upload 配置对接）。
- 兼容范围：仅 ZIN 模式。
- 新增需求（2026-10-04）：
  - **R4** 编辑器支持粘贴/拖入外部 markdown 文件内容（读文本插入编辑器）；
  - **R5** md 文档支持上传附件（含 .md 文件），复用禅道既有附件体系；
  - **R6** 查看页附件可下载；.md 附件提供"查看"（Vditor 渲染）或"下载"两种操作；
  - **R7** 存储机制与禅道官方文档体系一致（同表同 type 值，无私有格式，DESIGN §6.6）；
  - **R8** 向后兼容：21.x/22.x 原生能力存在时自动让路，防御式挂载（DESIGN §6.7）；
  - **安全合规**：权限两层/CSRF/出口矩阵按禅道框架机制执行，不新增开放方法（DESIGN §6.8）。

## 待用户评审

1. Part 1 主选 **Vditor**（+ 内置回退层）是否通过？
2. Part 2 查看渲染 **V1（Vditor.preview）** 是否通过？
3. （v1.2）提交链路 **Path B'**：原文直通以"偏离核心 purifier 不变式 + 渲染侧防护 + URI 精准门控"为代价，M0 实验为硬门槛——是否接受该安全交换？（Path A/B 均已被源码事实作废。）
4. R6 "查看 md 附件"独立页面 vs 弹窗内嵌（DESIGN §11）。
