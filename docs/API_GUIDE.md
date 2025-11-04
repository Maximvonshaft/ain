# 自适应备忘录（`memo.php`）API 与组件文档

本文档覆盖单文件应用 `memo.php` 中对外可见的所有 Web 接口、PHP 函数及前端组件，帮助你在不修改源码的情况下正确调用、扩展或集成该系统。

## 1. 系统概览
- **应用形态**：单文件 PHP 应用，提供备忘录、流程子任务、附件上传与思维导图管理等能力。
- **运行依赖**：PHP 8.1+（开启 SQLite、PDO、finfo 扩展），写权限目录需包含 `memo.sqlite` 与 `uploads/`。
- **默认安全策略**：设置多项响应头（CSP、HSTS 同源框架、Referrer-Policy 等），内置 Session 处理闪存消息。
- **界面入口**：通过查询参数 `view` 切换模块，默认进入备忘录首页；通过 POST `action` 指定状态变更。

## 2. 数据存储与文件
- **数据库文件**：`DB_FILE = /workspace/memo.sqlite`，首次访问自动初始化。
- **附件目录**：`UPLOAD_DIR = /workspace/uploads`，不存在时自动创建（权限 `0775`）。
- **表结构**：
  - `categories(id, name, created_at)`
  - `items(id, title, description, done, category_id, order_index, created_at, updated_at)`
  - `steps(id, item_id, title, notes, done, order_index, created_at, updated_at)`
  - `attachments(id, item_id, step_id, orig_name, stored_name, mime, size, created_at)`
  - `mindmaps(id, title, content, created_at, updated_at)`
- **默认数据**：首次运行会插入分类「备忘录 / 流程 / 其他」及一份默认思维导图。

## 3. HTTP 接口

### 3.1 GET 请求

| 路径/参数 | 描述 | 关键查询参数 | 返回 | 说明 |
| --- | --- | --- | --- | --- |
| `/memo.php` | 备忘录首页 | `cat`（分类 ID 或 `all`）、`q`（模糊搜索）、`export`（`json`/`csv`） | HTML 或下载 | `export` 存在时触发导出，包含 steps 数据；否则渲染卡片列表。 |
| `/memo.php?view=new` | 新建备忘录界面 | — | HTML | 页面加载后即发起 `POST action=create_draft` 创建草稿。 |
| `/memo.php?view=item&id={id}` | 条目详情页 | `id`（数字） | HTML | 展示并可编辑条目、流程步骤与附件。缺失会返回 404。 |
| `/memo.php?view=maps` | 思维导图库 | — | HTML | 支持搜索、删除与跳转编辑。 |
| `/memo.php?view=map_edit[&id={id}]` | 导图编辑器 | `id` 可选 | HTML | 无 `id` 时以默认模板初始化。 |
| `/memo.php?download={attachmentId}` | 附件下载 | `attachmentId` | 二进制文件 | 图片内联显示，其余类型强制下载；找不到返回 404。 |
| `/memo.php?export=json|csv` | 数据导出 | `cat`、`q` 可选筛选 | 文件下载 | `json` 包含 steps 没有附件；`csv` 采用 UTF-8 BOM。 |

> 错误处理：GET 请求出现资源缺失时返回 404；不支持的导出类型返回 400 与纯文本错误。

### 3.2 POST `action` 一览

所有 POST 接口均提交到 `memo.php`，通过表单或 `fetch` 发送。`X-Requested-With` 头存在时视为 AJAX，请求成功后通常返回 JSON；否则回退到重定向 `redirect()`。

| `action` | 目的 | 关键字段 | 成功响应 | 备注 |
| --- | --- | --- | --- | --- |
| `create_draft` | 初始化新条目草稿 | — | `{"ok":1,"id":<int>}` | 新建页面加载时自动触发。 |
| `add_item` | 新增条目 | `title`、`description`、`category_id` 可选 | AJAX: `{"ok":1,"id":<int>}`；表单：重定向到详情 | 标题必填。 |
| `toggle_done` | 切换条目完成状态 | `id`、`done`（0/1） | AJAX: `{"ok":1,"id":...,"updated_at":<ts>}` | 同时更新 `updated_at`。 |
| `edit_item` | 更新条目 | `id`、`title`、`description`、`category_id` | AJAX: `{"ok":1}` | 标题必填。 |
| `delete_item` | 删除条目 | `id` | 无正文（非 AJAX） | AJAX 未特判，调用将得到 302。 |
| `add_category` | 新建分类 | `name` | `json_cats()` 结构 | 分类名必填，重复忽略。 |
| `edit_category` | 重命名分类 | `id`、`name` | `json_cats()` | |
| `delete_category` | 删除分类 | `id` | `json_cats()` | 删除后条目迁移到「其他」。 |
| `add_step` | 新增流程步骤 | `item_id`、`title` | `{"ok":1,"step":{...}}` | 返回完整步骤数据。 |
| `toggle_step` | 切换步骤完成 | `id`、`done` | `{"ok":1,"item_id":...,"updated_at":<ts>}` | 同步父条目更新时间。 |
| `edit_step` | 修改步骤标题 | `id`、`title` | `{"ok":1}` | 标题必填。 |
| `edit_step_notes` | 修改步骤备注 | `id`、`notes` | `{"ok":1}` | 备注 Markdown 保存。 |
| `delete_step` | 删除步骤 | `id` | 非 AJAX：重定向 | 无单独 AJAX 响应。 |
| `reorder_items` | 重排首页条目 | `order`（逗号分隔 ID） | `204 No Content` | 仅桌面拖拽/移动端按钮后调用。 |
| `reorder_steps` | 重排步骤顺序 | `order`、`item_id` | `204 No Content` | 详情页/新建页拖拽后调用。 |
| `upload_attachment` | 上传附件 | `target`（`item`/`step`）、`target_id`、`file` | `{"ok":1,"id":...,"url":"?download=...","mime":"","markdown":"","size":<bytes>}` | 限制 20 MB，只接受图片与 zip。 |
| `ping_cats` | 拉取分类与计数 | — | `{"ok":1,"cats":[],"counts":{},"total":<int>,"uncat":<int>}` | 用于分类管理弹窗刷新侧边栏。 |
| `delete_attachment` | 删除附件 | `id` | `{"ok":1}` | 自动更新条目更新时间。 |
| `save_mindmap` | 保存导图 | `id`（可为 0）、`title`、`content`（JSON 字符串） | `{"ok":1,"id":...,"updated_at":<ts>}` | 会在成功后替换浏览器地址。 |
| `delete_mindmap` | 删除导图 | `id` | AJAX: `{"ok":1}`；非 AJAX：重定向 | |

> 错误处理：若抛出异常且为 AJAX 请求，返回 `400` 与 `{"ok":0,"error":"..."}`；否则将错误写入 `$_SESSION['flash']` 并回到来源页。

### 3.3 示例流程

```bash
# 1. 创建草稿（返回新条目 ID）
curl -X POST http://localhost/memo.php \
  -H 'X-Requested-With: fetch' \
  -d 'action=create_draft'

# 2. 更新草稿内容
curl -X POST http://localhost/memo.php \
  -H 'X-Requested-With: fetch' \
  -d 'action=edit_item' \
  -d 'id=1' \
  --data-urlencode 'title=示例条目' \
  --data-urlencode 'category_id=' \
  --data-urlencode 'description=## 计划\n- [ ] 子任务1'

# 3. 添加流程步骤
curl -X POST http://localhost/memo.php \
  -H 'X-Requested-With: fetch' \
  -d 'action=add_step' \
  -d 'item_id=1' \
  --data-urlencode 'title=准备资料'

# 4. 导出 JSON（包含步骤列表）
curl -L 'http://localhost/memo.php?export=json' -o memo_export.json
```

附件上传示例（上传 PNG 到条目 1）：

```bash
curl -X POST http://localhost/memo.php \
  -H 'X-Requested-With: fetch' \
  -F 'action=upload_attachment' \
  -F 'target=item' \
  -F 'target_id=1' \
  -F 'file=@/path/to/demo.png'
```

分类拉取示例：

```bash
curl -X POST http://localhost/memo.php \
  -H 'X-Requested-With: fetch' \
  -d 'action=ping_cats'
```

## 4. PHP 函数参考

| 函数 | 说明 | 主要参数 | 返回 |
| --- | --- | --- | --- |
| `default_mindmap_payload(): string` | 生成默认导图 JSON 字符串。 | — | JSON 字符串 |
| `h(?string $s): string` | HTML 转义助手。 | `$s` 可空 | 转义后的字符串 |
| `now(): int` | 当前 Unix 时间戳。 | — | 秒级时间戳 |
| `is_post(): bool` | 判断请求方法是否 POST。 | — | 布尔 |
| `is_ajax(): bool` | 检查 `HTTP_X_REQUESTED_WITH` 是否存在。 | — | 布尔 |
| `redirect(string $url=''): void` | 重定向到指定或当前 URL。 | `$url` 可空 | 无返回（终止执行） |
| `bytes_h(int $b): string` | 以 B/KB/MB/GB 格式化字节数。 | `$b` 字节数 | 友好字符串 |
| `dt(int $ts): string` | 按 `Y-m-d H:i` 格式化时间。 | `$ts` 时间戳 | 字符串 |
| `db(): PDO` | 返回单例 PDO 连接，负责初始化数据库和导图数据。 | — | PDO 实例 |
| `get_categories(): array` | 获取分类及条目计数映射。 | — | `[array $cats, array $counts]` |
| `ensure_other_category(): int` | 确保存在名为「其他」的分类并返回其 ID。 | — | 分类 ID |
| `get_item(int $id): ?array` | 载入带分类名的条目。 | `$id` | 条目数组 / `null` |
| `get_steps(int $item_id): array` | 取步骤（按自定义排序）。 | `$item_id` | 步骤数组 |
| `get_steps_by_time(int $item_id): array` | 取步骤（按创建时间）。 | `$item_id` | 步骤数组 |
| `get_attachment(int $id): ?array` | 获取附件信息。 | `$id` | 附件数组 / `null` |
| `attachments_for_item(int $item_id): array` | 列出条目所有附件。 | `$item_id` | 附件数组 |
| `get_mindmaps(): array` | 获取全部导图（按更新时间倒序）。 | — | 导图数组 |
| `get_mindmap(int $id): ?array` | 获取单个导图。 | `$id` | 导图数组 / `null` |
| `create_mindmap(string $title,string $content): array` | 新建导图并返回 ID 与时间戳。 | `title`、`content` | `['id'=>int,'updated_at'=>int]` |
| `update_mindmap(int $id,string $title,string $content): array` | 更新导图。 | 同上 | 同上 |
| `delete_mindmap(int $id): void` | 删除导图。 | `$id` | — |
| `mindmap_outline_preview(string $json,int $limit=8): string` | 生成导图大纲预览文本。 | `$json`、`$limit` | 多行字符串 |
| `json_cats(): void` | 输出分类 JSON 并终止。 | — | — |

上述函数均在 `memo.php` 全局作用域中定义，可在扩展该文件时调用。

## 5. 前端组件与 JavaScript API

### 5.1 新建备忘录视图（`?view=new`）
- **核心状态**：`state.id` 保存草稿 ID；第一次加载立即调用 `create_draft`。
- **主要函数**：
  - `safeHTML(md)`：使用 Marked + DOMPurify 渲染 Markdown。
  - `renderMD()`：将编辑器内容渲染到预览区。
  - `saveAJAX(ev)`：将标题、分类、描述提交到 `edit_item`，成功后提示已保存。
  - `addStepAJAX(ev)` / `saveStepTitleAJAX` / `saveStepNotesAJAX` / `toggleStep(stepId, done)`：对应步骤 CRUD 操作。
  - `insertAttachmentToStep(stepId)`：为指定步骤打开文件选择并调用 `upload_attachment`。
  - 拖拽排序通过自调用函数监听 `.tl-item` 的 `dragstart/dragover/drop`，落点后触发 `reorder_steps`。

### 5.2 条目详情视图（`?view=item&id=`）
- **功能**：在同一页内编辑标题、分类、Markdown 描述、步骤与附件。
- **函数**：与新建页类似，但作用对象为现有条目，此外还提供：
  - `bindTimeline()`（匿名自执行）处理步骤拖拽；
  - `deleteAttachment(id)`（按钮通过表单触发）删除附件；
  - `uploadForItem()` 将附件插入 Markdown 文本。

### 5.3 思维导图编辑器（`?view=map_edit`）
- **依赖**：`jsMind`、`DOMPurify`、`marked`。初始数据来自服务器，如果为新建则加载 `DEFAULT_MINDMAP` 模板。
- **关键函数与对象**：
  - `safeParse(jsonStr, fallback)`：安全解析 JSON。
  - `ensureNode()`：获取当前选中的节点（若无则返回根节点）。
  - `executeCreateNodeCommand(payload)`：封装节点创建命令，记录到 `commandLog` 并刷新视图。
  - `applyTemplateToParent(template, parent, dropPoint)`：根据模板批量添加子节点；拖拽 palette 时调用。
  - `handleDroppedText/files`：处理拖放文本、URL 或文件。
  - `callView(method, ...)`：安全地调用 jsMind 可选的视图方法。
  - `saveMindmap()`：组装 `save_mindmap` 请求，成功后更新地址栏并清理脏标记。
  - `markDirty()/markSaved()`：更新 UI 状态与 `beforeunload` 提示。
  - 将常用命令记录在 `window.__mindmapCommands` 便于调试。

### 5.4 导图库视图（`?view=maps`）
- 搜索框监听输入事件，按标题或大纲预览过滤卡片。
- 删除按钮提交 `delete_mindmap` 表单；新建按钮跳转至编辑器。

### 5.5 首页视图（默认）
- **拖拽排序**：桌面端监听 `.item` 拖拽，完成后调用 `sendOrder()`。
- **移动端排序**：`moveCard(id, dir)` 通过插入前后兄弟节点完成重排。
- **分类管理**：
  - `openCatModal()/closeCatModal()`：控制模态；
  - `renderCatRows()`/`renderCatRowsFromDOM()`：渲染分类表单；
  - `fetchCats()`：请求 `ping_cats`；
  - `addCat()/saveCat()/delCat()`：通过相应 `action` 同步服务端；
  - `refreshSidebarCats()`：重新绘制侧边栏分类。
- **状态同步**：
  - `fmt(ts)`：格式化更新时间；
  - `items` 容器 `change` 事件统一处理条目和步骤勾选，成功返回后更新 DOM 状态与时间戳。
- **快捷键**：监听 `/` 聚焦搜索框。

## 6. 使用与扩展建议
- 自定义功能时尽量复用现有 `action` 或 PHP 函数；新建接口请保持与 `is_ajax()` 逻辑兼容。
- 附件上传严格校验 MIME 类型，如需支持更多格式需同步扩展 `$allowed` 列表。
- Mindmap 内容保存时会对 JSON 做二次编码，扩展前请确认客户端同样遵循 `node_tree` 格式。
- 若在 CLI 或集成环境使用，记得设置 `$_SERVER['REQUEST_URI']`、`REQUEST_METHOD` 等变量以匹配函数判断。

## 7. 常见问题
- **AJAX 请求为什么收到 302？** 某些 `action`（如 `delete_step`）未内置 JSON 响应，若需 Ajax 删除请在客户端接受 302 或扩展 PHP 逻辑。
- **如何重建数据库？** 删除 `memo.sqlite` 后刷新页面即可重新创建默认结构；注意会丢失数据。
- **CSP 阻止了自定义脚本？** 当前策略允许 `'unsafe-inline'`，外部资源需来自 `cdn.jsdelivr.net`、`fonts.googleapis.com` 等白名单，如需增加域名请修改头部配置。

---

如需进一步自动化操作，可结合上述 API 列表编写脚本或集成到 CI/CD 流程中完成备份、导入导出及导图批量管理。
