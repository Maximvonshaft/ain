# Memo.php - 完整 API 文档

## 项目概述

**Memo.php** 是一个功能完整的单文件备忘录应用，支持备忘录管理、任务步骤跟踪、附件上传、思维导图编辑等功能。使用 SQLite 数据库存储，支持 Markdown 编辑，具有现代化的响应式界面。

### 主要特性

- ✅ 备忘录管理（创建、编辑、删除、完成标记）
- 📁 分类管理（自定义分类、统计）
- 🔄 流程子任务（时间轴展示、拖拽排序）
- 📎 附件上传（图片、ZIP 文件，最大 20MB）
- 🗺️ 思维导图（创建、编辑、导入导出）
- 🔍 搜索功能（标题、内容搜索）
- 📤 数据导出（JSON、CSV 格式）
- 🎨 Markdown 支持（编辑器、预览）
- 📱 响应式设计（适配移动端）

---

## 目录

1. [配置常量](#配置常量)
2. [数据库架构](#数据库架构)
3. [辅助函数](#辅助函数)
4. [核心API函数](#核心api函数)
5. [POST API操作](#post-api操作)
6. [GET操作和视图](#get操作和视图)
7. [前端JavaScript API](#前端javascript-api)
8. [使用示例](#使用示例)

---

## 配置常量

### 应用配置

```php
const DB_FILE = __DIR__ . '/memo.sqlite';      // SQLite 数据库文件路径
const UPLOAD_DIR = __DIR__ . '/uploads';       // 附件上传目录
const MAX_UPLOAD_BYTES = 20 * 1024 * 1024;     // 最大上传大小：20MB
date_default_timezone_set('Asia/Shanghai');     // 时区设置
```

### 安全响应头

应用设置了以下安全响应头：
- `X-Frame-Options: SAMEORIGIN`
- `X-Content-Type-Options: nosniff`
- `Referrer-Policy: strict-origin-when-cross-origin`
- `Content-Security-Policy`（允许 'unsafe-inline' 以支持内联脚本）

---

## 数据库架构

### 表结构

#### 1. `categories` - 分类表

| 字段 | 类型 | 说明 |
|------|------|------|
| id | INTEGER PRIMARY KEY | 分类ID（自增） |
| name | TEXT UNIQUE NOT NULL | 分类名称（唯一） |
| created_at | INTEGER NOT NULL | 创建时间戳 |

#### 2. `items` - 备忘录项表

| 字段 | 类型 | 说明 |
|------|------|------|
| id | INTEGER PRIMARY KEY | 项目ID（自增） |
| title | TEXT NOT NULL | 标题 |
| description | TEXT | 描述内容（支持Markdown） |
| done | INTEGER DEFAULT 0 | 完成状态（0=未完成，1=已完成） |
| category_id | INTEGER | 分类ID（外键） |
| order_index | INTEGER DEFAULT 0 | 排序索引 |
| created_at | INTEGER NOT NULL | 创建时间戳 |
| updated_at | INTEGER NOT NULL | 更新时间戳 |

**外键约束**：`category_id` → `categories(id)` ON DELETE SET NULL

#### 3. `steps` - 流程步骤表

| 字段 | 类型 | 说明 |
|------|------|------|
| id | INTEGER PRIMARY KEY | 步骤ID（自增） |
| item_id | INTEGER NOT NULL | 所属项目ID（外键） |
| title | TEXT NOT NULL | 步骤标题 |
| notes | TEXT | 步骤备注（支持Markdown） |
| done | INTEGER DEFAULT 0 | 完成状态 |
| order_index | INTEGER DEFAULT 0 | 排序索引 |
| created_at | INTEGER NOT NULL | 创建时间戳 |
| updated_at | INTEGER NOT NULL | 更新时间戳 |

**外键约束**：`item_id` → `items(id)` ON DELETE CASCADE

#### 4. `attachments` - 附件表

| 字段 | 类型 | 说明 |
|------|------|------|
| id | INTEGER PRIMARY KEY | 附件ID（自增） |
| item_id | INTEGER | 关联项目ID（外键，可为空） |
| step_id | INTEGER | 关联步骤ID（外键，可为空） |
| orig_name | TEXT NOT NULL | 原始文件名 |
| stored_name | TEXT NOT NULL | 存储文件名 |
| mime | TEXT NOT NULL | MIME类型 |
| size | INTEGER NOT NULL | 文件大小（字节） |
| created_at | INTEGER NOT NULL | 创建时间戳 |

**外键约束**：
- `item_id` → `items(id)` ON DELETE CASCADE
- `step_id` → `steps(id)` ON DELETE CASCADE

**索引**：
- `idx_att_item` ON `attachments(item_id)`
- `idx_att_step` ON `attachments(step_id)`

#### 5. `mindmaps` - 思维导图表

| 字段 | 类型 | 说明 |
|------|------|------|
| id | INTEGER PRIMARY KEY | 导图ID（自增） |
| title | TEXT NOT NULL | 导图标题 |
| content | TEXT NOT NULL | 导图内容（JSON格式） |
| created_at | INTEGER NOT NULL | 创建时间戳 |
| updated_at | INTEGER NOT NULL | 更新时间戳 |

### 默认数据

应用初始化时会创建以下默认分类：
- 备忘录
- 流程
- 其他

以及一个默认思维导图。

---

## 辅助函数

### 通用辅助函数

#### `h(?string $s): string`
HTML转义函数，防止XSS攻击。

**参数**：
- `$s`：需要转义的字符串（可为null）

**返回**：转义后的安全字符串

**示例**：
```php
echo h('<script>alert("XSS")</script>');
// 输出: &lt;script&gt;alert(&quot;XSS&quot;)&lt;/script&gt;
```

---

#### `now(): int`
获取当前Unix时间戳。

**返回**：当前时间戳（整数）

**示例**：
```php
$timestamp = now();
```

---

#### `is_post(): bool`
检查当前请求是否为POST方法。

**返回**：布尔值

**示例**：
```php
if (is_post()) {
    // 处理POST请求
}
```

---

#### `is_ajax(): bool`
检查当前请求是否为AJAX请求（通过 `X-Requested-With` 头判断）。

**返回**：布尔值

**示例**：
```php
if (is_ajax()) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => 1]);
}
```

---

#### `redirect(string $url = ''): void`
页面重定向。

**参数**：
- `$url`：目标URL（可选，默认为当前页面）

**示例**：
```php
redirect('?view=item&id=123');
```

---

#### `bytes_h(int $b): string`
将字节数转换为人类可读格式。

**参数**：
- `$b`：字节数

**返回**：格式化后的字符串（如 "1.5 MB"）

**示例**：
```php
echo bytes_h(1572864);  // 输出: "1.5 MB"
```

---

#### `dt(int $ts): string`
格式化时间戳为日期时间字符串。

**参数**：
- `$ts`：Unix时间戳

**返回**：格式化的日期时间字符串（格式：Y-m-d H:i）

**示例**：
```php
echo dt(1699200000);  // 输出: "2023-11-05 20:00"
```

---

## 核心API函数

### 数据库操作

#### `db(): PDO`
获取数据库连接实例（单例模式）。

**返回**：PDO连接对象

**功能**：
- 自动初始化数据库和表结构
- 启用 WAL 模式
- 启用外键约束
- 创建上传目录

**示例**：
```php
$pdo = db();
$stmt = $pdo->query('SELECT * FROM items');
```

---

### 分类管理

#### `get_categories(): array`
获取所有分类及其包含的项目数量。

**返回**：数组 `[$categories, $counts]`
- `$categories`：分类列表（二维数组）
- `$counts`：分类计数数组（关联数组，key为分类ID，value为数量）

**示例**：
```php
[$cats, $counts] = get_categories();
foreach ($cats as $cat) {
    echo $cat['name'] . ': ' . ($counts[$cat['id']] ?? 0) . ' 项';
}
```

---

#### `ensure_other_category(): int`
确保"其他"分类存在，如不存在则创建。

**返回**：分类ID

**示例**：
```php
$otherId = ensure_other_category();
```

---

### 项目管理

#### `get_item(int $id): ?array`
根据ID获取单个备忘录项。

**参数**：
- `$id`：项目ID

**返回**：项目数组（包含分类名称），不存在返回null

**示例**：
```php
$item = get_item(123);
if ($item) {
    echo $item['title'];
    echo $item['cat_name'];  // 分类名称
}
```

---

#### `get_steps(int $item_id): array`
获取指定项目的所有步骤（按 order_index 排序）。

**参数**：
- `$item_id`：项目ID

**返回**：步骤数组列表

**示例**：
```php
$steps = get_steps(123);
foreach ($steps as $step) {
    echo $step['title'];
}
```

---

#### `get_steps_by_time(int $item_id): array`
获取指定项目的所有步骤（按创建时间排序）。

**参数**：
- `$item_id`：项目ID

**返回**：步骤数组列表

**示例**：
```php
$steps = get_steps_by_time(123);
```

---

### 附件管理

#### `get_attachment(int $id): ?array`
根据ID获取单个附件信息。

**参数**：
- `$id`：附件ID

**返回**：附件数组，不存在返回null

**示例**：
```php
$att = get_attachment(456);
if ($att) {
    echo $att['orig_name'];
    echo bytes_h($att['size']);
}
```

---

#### `attachments_for_item(int $item_id): array`
获取指定项目的所有附件。

**参数**：
- `$item_id`：项目ID

**返回**：附件数组列表

**示例**：
```php
$attachments = attachments_for_item(123);
```

---

### 思维导图管理

#### `get_mindmaps(): array`
获取所有思维导图（按更新时间倒序）。

**返回**：导图数组列表

**示例**：
```php
$maps = get_mindmaps();
foreach ($maps as $map) {
    echo $map['title'];
}
```

---

#### `get_mindmap(int $id): ?array`
根据ID获取单个思维导图。

**参数**：
- `$id`：导图ID

**返回**：导图数组，不存在返回null

**示例**：
```php
$map = get_mindmap(789);
if ($map) {
    $data = json_decode($map['content'], true);
}
```

---

#### `create_mindmap(string $title, string $content): array`
创建新思维导图。

**参数**：
- `$title`：导图标题
- `$content`：导图内容（JSON格式字符串）

**返回**：包含 `id` 和 `updated_at` 的数组

**示例**：
```php
$result = create_mindmap('我的导图', json_encode($mindmap_data));
$newId = $result['id'];
```

---

#### `update_mindmap(int $id, string $title, string $content): array`
更新思维导图。

**参数**：
- `$id`：导图ID
- `$title`：导图标题
- `$content`：导图内容（JSON格式字符串）

**返回**：包含 `id` 和 `updated_at` 的数组

**示例**：
```php
$result = update_mindmap(789, '更新的标题', json_encode($new_data));
```

---

#### `delete_mindmap(int $id): void`
删除思维导图。

**参数**：
- `$id`：导图ID

**示例**：
```php
delete_mindmap(789);
```

---

#### `mindmap_outline_preview(string $json, int $limit = 8): string`
生成思维导图的文本大纲预览。

**参数**：
- `$json`：导图JSON内容
- `$limit`：最大行数（默认8）

**返回**：格式化的大纲字符串

**示例**：
```php
$preview = mindmap_outline_preview($map['content']);
echo "<pre>" . h($preview) . "</pre>";
```

---

#### `default_mindmap_payload(): string`
获取默认思维导图的JSON字符串。

**返回**：JSON字符串

**示例**：
```php
$defaultMap = default_mindmap_payload();
```

---

#### `json_cats(): void`
返回JSON格式的分类列表（用于AJAX请求）。

**响应格式**：
```json
{
  "ok": 1,
  "cats": [{"id": 1, "name": "分类名"}],
  "counts": {"1": 5},
  "total": 100,
  "uncat": 10
}
```

**示例**：
```php
// 通过POST调用
// action=ping_cats
json_cats();
```

---

## POST API操作

所有POST操作通过 `action` 参数指定操作类型。AJAX请求需要设置 `X-Requested-With` 头。

### 1. 创建草稿

**Action**: `create_draft`

**参数**：无

**响应**（JSON）：
```json
{
  "ok": 1,
  "id": 123
}
```

**说明**：创建一个标题为"未命名"的空白备忘录项。

**使用场景**：新建页面自动创建草稿，用户编辑后保存。

---

### 2. 添加备忘录项

**Action**: `add_item`

**参数**：
- `title` (必填)：标题
- `description` (可选)：描述内容（Markdown）
- `category_id` (可选)：分类ID

**响应**（AJAX）：
```json
{
  "ok": 1,
  "id": 123
}
```

**响应**（非AJAX）：重定向到详情页

**示例**：
```javascript
const fd = new FormData();
fd.append('action', 'add_item');
fd.append('title', '新任务');
fd.append('description', '这是描述');
fd.append('category_id', '1');
fetch('/', {method: 'POST', body: fd});
```

---

### 3. 切换完成状态

**Action**: `toggle_done`

**参数**：
- `id` (必填)：项目ID
- `done` (必填)：完成状态（0或1）

**响应**（JSON）：
```json
{
  "ok": 1,
  "id": 123,
  "updated_at": 1699200000
}
```

**示例**：
```javascript
const fd = new FormData();
fd.append('action', 'toggle_done');
fd.append('id', '123');
fd.append('done', '1');
fetch('/', {method: 'POST', body: fd, headers: {'X-Requested-With': 'fetch'}});
```

---

### 4. 编辑备忘录项

**Action**: `edit_item`

**参数**：
- `id` (必填)：项目ID
- `title` (必填)：标题
- `description` (可选)：描述内容
- `category_id` (可选)：分类ID

**响应**（JSON）：
```json
{
  "ok": 1
}
```

**示例**：
```javascript
const fd = new FormData();
fd.append('action', 'edit_item');
fd.append('id', '123');
fd.append('title', '更新的标题');
fd.append('description', '更新的内容');
fetch('/', {method: 'POST', body: fd, headers: {'X-Requested-With': 'fetch'}});
```

---

### 5. 删除备忘录项

**Action**: `delete_item`

**参数**：
- `id` (必填)：项目ID

**响应**：重定向到首页

**说明**：会级联删除关联的步骤和附件。

---

### 6. 添加分类

**Action**: `add_category`

**参数**：
- `name` (必填)：分类名称

**响应**（JSON）：返回完整分类列表（调用 `json_cats()`）

**示例**：
```javascript
const fd = new FormData();
fd.append('action', 'add_category');
fd.append('name', '新分类');
fetch('/', {method: 'POST', body: fd, headers: {'X-Requested-With': 'fetch'}});
```

---

### 7. 编辑分类

**Action**: `edit_category`

**参数**：
- `id` (必填)：分类ID
- `name` (必填)：新分类名称

**响应**（JSON）：返回完整分类列表

---

### 8. 删除分类

**Action**: `delete_category`

**参数**：
- `id` (必填)：分类ID

**响应**（JSON）：返回完整分类列表

**说明**：该分类下的所有项目会被移到"其他"分类。

---

### 9. 添加步骤

**Action**: `add_step`

**参数**：
- `item_id` (必填)：项目ID
- `title` (必填)：步骤标题

**响应**（JSON）：
```json
{
  "ok": 1,
  "step": {
    "id": 456,
    "item_id": 123,
    "title": "新步骤",
    "notes": "",
    "done": 0,
    "order_index": 0,
    "created_at": 1699200000,
    "updated_at": 1699200000
  }
}
```

**示例**：
```javascript
const fd = new FormData();
fd.append('action', 'add_step');
fd.append('item_id', '123');
fd.append('title', '第一步');
fetch('/', {method: 'POST', body: fd, headers: {'X-Requested-With': 'fetch'}});
```

---

### 10. 切换步骤完成状态

**Action**: `toggle_step`

**参数**：
- `id` (必填)：步骤ID
- `done` (必填)：完成状态（0或1）

**响应**（JSON）：
```json
{
  "ok": 1,
  "item_id": 123,
  "updated_at": 1699200000
}
```

---

### 11. 编辑步骤标题

**Action**: `edit_step`

**参数**：
- `id` (必填)：步骤ID
- `title` (必填)：新标题

**响应**（JSON）：
```json
{
  "ok": 1
}
```

---

### 12. 编辑步骤备注

**Action**: `edit_step_notes`

**参数**：
- `id` (必填)：步骤ID
- `notes` (必填)：备注内容（Markdown）

**响应**（JSON）：
```json
{
  "ok": 1
}
```

---

### 13. 删除步骤

**Action**: `delete_step`

**参数**：
- `id` (必填)：步骤ID

**响应**：重定向到当前页面

---

### 14. 重新排序项目

**Action**: `reorder_items`

**参数**：
- `order` (必填)：逗号分隔的项目ID列表（如 "3,1,5,2"）

**响应**：HTTP 204（无内容）

**说明**：用于拖拽排序后保存顺序。

**示例**：
```javascript
const ids = ['3', '1', '5', '2'].join(',');
const fd = new FormData();
fd.append('action', 'reorder_items');
fd.append('order', ids);
fetch('/', {method: 'POST', body: fd, headers: {'X-Requested-With': 'fetch'}});
```

---

### 15. 重新排序步骤

**Action**: `reorder_steps`

**参数**：
- `item_id` (必填)：项目ID
- `order` (必填)：逗号分隔的步骤ID列表

**响应**：HTTP 204

**说明**：用于步骤拖拽排序。

---

### 16. 上传附件

**Action**: `upload_attachment`

**参数**：
- `target` (必填)：目标类型（"item" 或 "step"）
- `target_id` (必填)：目标ID
- `file` (必填)：文件对象（multipart/form-data）

**响应**（JSON）：
```json
{
  "ok": 1,
  "id": 789,
  "url": "?download=789",
  "mime": "image/png",
  "markdown": "![filename](?download=789)",
  "size": 123456
}
```

**限制**：
- 最大文件大小：20MB
- 允许的文件类型：图片（png/jpeg/webp/gif/svg）、ZIP

**示例**：
```javascript
const fd = new FormData();
fd.append('action', 'upload_attachment');
fd.append('target', 'item');
fd.append('target_id', '123');
fd.append('file', fileInput.files[0]);
fetch('/', {method: 'POST', body: fd, headers: {'X-Requested-With': 'fetch'}})
  .then(r => r.json())
  .then(j => {
    if (j.ok) {
      console.log('上传成功:', j.url);
      // 可直接插入 Markdown: j.markdown
    }
  });
```

---

### 17. 删除附件

**Action**: `delete_attachment`

**参数**：
- `id` (必填)：附件ID

**响应**（JSON）：
```json
{
  "ok": 1
}
```

**说明**：删除数据库记录和文件系统中的文件。

---

### 18. 保存思维导图

**Action**: `save_mindmap`

**参数**：
- `id` (可选)：导图ID（0或不传表示新建）
- `title` (必填)：导图标题
- `content` (必填)：导图JSON内容

**响应**（JSON）：
```json
{
  "ok": 1,
  "id": 123,
  "updated_at": 1699200000
}
```

**示例**：
```javascript
const data = jsmind.get_data('node_tree');
const fd = new FormData();
fd.append('action', 'save_mindmap');
fd.append('id', '123');
fd.append('title', '我的导图');
fd.append('content', JSON.stringify(data));
fetch('/', {method: 'POST', body: fd, headers: {'X-Requested-With': 'fetch'}});
```

---

### 19. 删除思维导图

**Action**: `delete_mindmap`

**参数**：
- `id` (必填)：导图ID

**响应**（JSON）：
```json
{
  "ok": 1
}
```

---

### 20. 获取分类列表

**Action**: `ping_cats`

**参数**：无

**响应**（JSON）：与 `json_cats()` 相同

**说明**：用于刷新分类列表。

---

## GET操作和视图

### 查询参数

#### `view` 参数

控制显示的视图类型：

| 值 | 说明 |
|----|------|
| `new` | 新建备忘录页面 |
| `item` | 备忘录详情页（需配合 `id` 参数） |
| `maps` | 思维导图库 |
| `map_edit` | 思维导图编辑器（可选 `id` 参数） |
| (空) | 首页（备忘录列表） |

#### `cat` 参数

筛选分类：
- `all`：显示所有分类
- `{分类ID}`：显示指定分类

#### `q` 参数

搜索关键词（搜索标题和描述）。

#### `export` 参数

导出数据：
- `json`：导出为JSON格式
- `csv`：导出为CSV格式

#### `download` 参数

下载附件（需提供附件ID）。

---

### 视图详解

#### 1. 首页（备忘录列表）

**URL**：`/` 或 `/?view=`

**功能**：
- 展示备忘录项目列表
- 侧边栏显示分类
- 搜索功能
- 拖拽排序（桌面端）
- 导出功能

**示例URL**：
```
/?cat=1&q=搜索词
/?cat=all
```

---

#### 2. 新建备忘录页面

**URL**：`/?view=new`

**功能**：
- 自动创建草稿
- Markdown编辑器
- 实时预览
- 附件上传
- 流程步骤管理（时间轴）

---

#### 3. 备忘录详情页

**URL**：`/?view=item&id={项目ID}`

**功能**：
- 查看和编辑备忘录
- 管理步骤
- 管理附件
- 删除项目

**示例URL**：
```
/?view=item&id=123
```

---

#### 4. 思维导图库

**URL**：`/?view=maps`

**功能**：
- 展示所有思维导图
- 搜索导图
- 创建、编辑、删除导图

---

#### 5. 思维导图编辑器

**URL**：`/?view=map_edit` 或 `/?view=map_edit&id={导图ID}`

**功能**：
- 可视化思维导图编辑
- 节点拖拽、连接
- 导入/导出JSON
- 主题切换
- 快捷键支持

**示例URL**：
```
/?view=map_edit&id=789
/?view=map_edit  // 新建导图
```

---

#### 6. 下载附件

**URL**：`/?download={附件ID}`

**功能**：
- 图片类型：内联显示
- 其他类型：下载文件

**示例URL**：
```
/?download=456
```

---

#### 7. 导出数据

**URL**：`/?export={格式}&cat={分类}&q={搜索词}`

**格式**：
- `json`：包含步骤的完整JSON
- `csv`：包含基本信息的CSV表格

**示例URL**：
```
/?export=json&cat=all
/?export=csv&cat=1&q=重要
```

**JSON导出格式**：
```json
[
  {
    "id": 123,
    "title": "任务标题",
    "description": "任务描述",
    "done": 0,
    "category_id": 1,
    "cat_name": "备忘录",
    "order_index": 0,
    "created_at": 1699200000,
    "updated_at": 1699200000,
    "steps": [
      {
        "id": 456,
        "item_id": 123,
        "title": "步骤1",
        "notes": "",
        "done": 0,
        "order_index": 0,
        "created_at": 1699200000,
        "updated_at": 1699200000
      }
    ]
  }
]
```

---

## 前端JavaScript API

### 全局函数（首页）

#### `moveCard(id, dir)`
移动卡片位置（移动端）。

**参数**：
- `id`：项目ID
- `dir`：方向（-1=上移，1=下移）

**示例**：
```javascript
moveCard(123, -1);  // 上移
moveCard(123, 1);   // 下移
```

---

#### `sendOrder()`
提交当前拖拽排序。

**说明**：自动收集所有卡片的ID顺序并提交。

---

#### `openCatModal()`
打开分类管理模态框。

---

#### `closeCatModal()`
关闭分类管理模态框。

---

#### `addCat(event)`
添加新分类。

**参数**：
- `event`：表单提交事件

**返回**：Promise

---

#### `saveCat(event, id)`
保存分类名称。

**参数**：
- `event`：表单提交事件
- `id`：分类ID

**返回**：Promise

---

#### `delCat(id, name)`
删除分类。

**参数**：
- `id`：分类ID
- `name`：分类名称（用于确认提示）

**返回**：Promise

---

### 思维导图编辑器函数

#### `executeCreateNodeCommand(input)`
创建新节点。

**参数**：
```javascript
{
  parentId: '父节点ID',
  id: '节点ID（可选）',
  topic: '节点标题',
  data: {},  // 附加数据
  style: {
    background: '#颜色',
    foreground: '#颜色'
  },
  position: {x: 100, y: 200},  // 位置
  meta: {}  // 元数据
}
```

**返回**：新创建的节点对象

---

#### `saveMindmap()`
保存思维导图。

**返回**：Promise

**说明**：自动收集导图数据并提交到服务器。

---

## 使用示例

### 示例1：创建备忘录并添加步骤

```javascript
// 1. 创建草稿
const createDraft = await fetch('/', {
  method: 'POST',
  headers: {'X-Requested-With': 'fetch'},
  body: new URLSearchParams([['action', 'create_draft']])
});
const draft = await createDraft.json();
const itemId = draft.id;

// 2. 更新内容
const fd = new FormData();
fd.append('action', 'edit_item');
fd.append('id', itemId);
fd.append('title', '我的任务');
fd.append('description', '# 任务详情\n\n这是一个重要任务。');
fd.append('category_id', '1');
await fetch('/', {method: 'POST', body: fd, headers: {'X-Requested-With': 'fetch'}});

// 3. 添加步骤
const step1 = new FormData();
step1.append('action', 'add_step');
step1.append('item_id', itemId);
step1.append('title', '第一步：准备工作');
await fetch('/', {method: 'POST', body: step1, headers: {'X-Requested-With': 'fetch'}});

const step2 = new FormData();
step2.append('action', 'add_step');
step2.append('item_id', itemId);
step2.append('title', '第二步：执行任务');
await fetch('/', {method: 'POST', body: step2, headers: {'X-Requested-With': 'fetch'}});

// 4. 跳转到详情页
window.location.href = `/?view=item&id=${itemId}`;
```

---

### 示例2：上传图片并插入到编辑器

```javascript
// 监听文件选择
document.getElementById('file-input').addEventListener('change', async (e) => {
  const file = e.target.files[0];
  if (!file) return;
  
  // 上传文件
  const fd = new FormData();
  fd.append('action', 'upload_attachment');
  fd.append('target', 'item');
  fd.append('target_id', '123');
  fd.append('file', file);
  
  const response = await fetch('/', {
    method: 'POST',
    body: fd,
    headers: {'X-Requested-With': 'fetch'}
  });
  
  const result = await response.json();
  
  if (result.ok) {
    // 插入Markdown
    editor.value += '\n' + result.markdown + '\n';
    
    // 如果是图片，显示缩略图
    if (result.mime.startsWith('image/')) {
      const img = document.createElement('img');
      img.src = result.url;
      img.style.maxWidth = '200px';
      document.getElementById('thumbnails').appendChild(img);
    }
  } else {
    alert(result.error || '上传失败');
  }
});
```

---

### 示例3：导出数据

```javascript
// 导出当前分类的所有项目为JSON
const exportUrl = `/?export=json&cat=${currentCategoryId}`;
window.location.href = exportUrl;

// 或使用fetch下载
const response = await fetch(exportUrl);
const blob = await response.blob();
const url = URL.createObjectURL(blob);
const a = document.createElement('a');
a.href = url;
a.download = 'memo_export.json';
a.click();
URL.revokeObjectURL(url);
```

---

### 示例4：搜索和筛选

```php
// PHP端处理搜索
$cat = $_GET['cat'] ?? 'all';
$q = trim($_GET['q'] ?? '');
$params = [];
$where = [];

if ($cat !== 'all' && ctype_digit($cat)) {
    $where[] = 'category_id = :cat';
    $params[':cat'] = (int)$cat;
}

if ($q !== '') {
    $where[] = '(title LIKE :q OR description LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}

$sql = 'SELECT * FROM items';
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY order_index ASC, updated_at DESC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll();
```

---

### 示例5：创建和保存思维导图

```javascript
// 初始化思维导图
const jm = new jsMind({
  container: 'jsmind-container',
  editable: true,
  theme: 'fresh-blue'
});

// 显示默认数据或加载现有数据
jm.show(initialData);

// 添加节点
const selectedNode = jm.get_selected_node();
jm.add_node(selectedNode, 'new-node-id', '新节点');

// 保存导图
async function saveMindmap() {
  const data = jm.get_data('node_tree');
  const fd = new FormData();
  fd.append('action', 'save_mindmap');
  fd.append('id', currentMapId || '0');
  fd.append('title', document.getElementById('map-title').value);
  fd.append('content', JSON.stringify(data));
  
  const response = await fetch('/', {
    method: 'POST',
    body: fd,
    headers: {'X-Requested-With': 'fetch'}
  });
  
  const result = await response.json();
  if (result.ok) {
    console.log('保存成功，ID:', result.id);
  }
}
```

---

## 错误处理

### 服务端错误响应

当操作失败时，响应格式：

```json
{
  "ok": 0,
  "error": "错误消息"
}
```

HTTP状态码：400（Bad Request）

### 常见错误类型

1. **标题必填**：创建或编辑项目/步骤时未提供标题
2. **分类名必填**：创建或编辑分类时未提供名称
3. **上传失败**：文件上传错误（文件过大、格式不支持等）
4. **目标无效**：附件上传时目标类型或ID无效
5. **仅允许图片与 zip**：上传了不支持的文件类型
6. **文件过大，最大 20MB**：文件超过大小限制
7. **思维导图数据格式不正确**：JSON格式错误

### 前端错误处理示例

```javascript
try {
  const response = await fetch('/', {
    method: 'POST',
    body: formData,
    headers: {'X-Requested-With': 'fetch'}
  });
  
  const result = await response.json();
  
  if (!result.ok) {
    alert('错误：' + (result.error || '操作失败'));
    return;
  }
  
  // 成功处理
  console.log('操作成功');
  
} catch (error) {
  console.error('网络错误:', error);
  alert('网络连接失败，请稍后重试');
}
```

---

## 安全性说明

### 已实施的安全措施

1. **SQL注入防护**：所有数据库操作使用预处理语句（PDO prepared statements）
2. **XSS防护**：使用 `htmlspecialchars()` 函数转义输出
3. **CSRF防护**：通过 session 验证（建议生产环境增加token验证）
4. **文件上传验证**：
   - MIME类型检查
   - 文件大小限制
   - 白名单限制（仅图片和ZIP）
   - 随机文件名存储
5. **Content Security Policy**：限制资源加载来源
6. **安全响应头**：X-Frame-Options、X-Content-Type-Options等

### 建议的额外安全措施

1. **添加用户认证**：当前无用户系统，建议添加登录功能
2. **CSRF Token**：在表单中添加token验证
3. **Rate Limiting**：限制API请求频率
4. **HTTPS**：生产环境使用HTTPS协议
5. **定期备份**：备份SQLite数据库文件

---

## 性能优化建议

1. **数据库索引**：已在 `attachments` 表上创建索引
2. **分页加载**：当项目数量较多时，建议添加分页
3. **图片缩略图**：自动生成缩略图以减少带宽
4. **延迟加载**：对非首屏内容使用懒加载
5. **缓存策略**：对静态资源添加缓存头

---

## 浏览器兼容性

### 最低要求

- Chrome 90+
- Firefox 88+
- Safari 14+
- Edge 90+

### 依赖的现代特性

- ES6+ JavaScript
- Fetch API
- FormData API
- CSS Grid Layout
- CSS Custom Properties (CSS变量)

---

## 依赖库

### 后端

- PHP 7.4+ (建议 8.0+)
- SQLite 3
- PDO扩展
- Fileinfo扩展

### 前端（CDN）

1. **Marked.js**：Markdown解析器
   - URL: `https://cdn.jsdelivr.net/npm/marked/marked.min.js`
   
2. **DOMPurify**：HTML净化库（防XSS）
   - URL: `https://cdn.jsdelivr.net/npm/dompurify/dist/purify.min.js`
   
3. **EasyMDE**：Markdown编辑器
   - URL: `https://cdn.jsdelivr.net/npm/easymde/dist/easymde.min.js`
   - CSS: `https://cdn.jsdelivr.net/npm/easymde/dist/easymde.min.css`
   
4. **jsMind**：思维导图库
   - URL: `https://cdn.jsdelivr.net/npm/jsmind@0.5.7/es6/jsmind.js`
   - CSS: `https://cdn.jsdelivr.net/npm/jsmind@0.5.7/style/jsmind.css`

---

## 部署说明

### 基本部署

1. 上传 `memo.php` 到Web服务器
2. 确保PHP有写入权限（用于创建数据库和上传目录）
3. 访问 `http://yourdomain.com/memo.php`

### Apache配置

```apache
<FilesMatch "\.sqlite$">
    Order allow,deny
    Deny from all
</FilesMatch>

<Directory "/path/to/uploads">
    Options -Indexes
</Directory>
```

### Nginx配置

```nginx
location ~ \.sqlite$ {
    deny all;
}

location /uploads/ {
    autoindex off;
}
```

### 权限设置

```bash
chmod 755 memo.php
chmod 755 ./  # 当前目录需要写入权限（创建数据库和uploads目录）
```

---

## 故障排查

### 问题1：数据库无法创建

**症状**：访问页面时出现"Permission denied"错误

**解决方案**：
```bash
# 确保目录有写入权限
chmod 755 /path/to/memo-directory
```

---

### 问题2：文件上传失败

**症状**：上传文件时提示"保存失败"

**解决方案**：
```bash
# 检查uploads目录权限
chmod 755 /path/to/uploads
```

---

### 问题3：思维导图无法保存

**症状**：点击保存按钮后无反应或报错

**解决方案**：
- 检查浏览器控制台错误
- 确认网络请求是否成功
- 检查JSON数据格式是否正确

---

### 问题4：CDN资源加载失败

**症状**：编辑器或思维导图无法显示

**解决方案**：
- 检查网络连接
- 尝试更换CDN源
- 考虑本地化依赖库

---

## 许可证

请查看项目的 LICENSE 文件以了解许可信息。

---

## 更新日志

### 当前版本特性

- ✅ 单文件应用，无需复杂部署
- ✅ 完整的备忘录管理功能
- ✅ 流程步骤跟踪（时间轴）
- ✅ 附件上传（图片、ZIP）
- ✅ 思维导图编辑器
- ✅ Markdown支持
- ✅ 响应式设计
- ✅ 拖拽排序
- ✅ 数据导出（JSON、CSV）
- ✅ 搜索和筛选

---

## 联系方式

如有问题或建议，请通过以下方式联系：

- GitHub Issues
- 项目文档
- 技术支持

---

## 附录

### A. 思维导图数据格式

```json
{
  "meta": {
    "name": "memo-mindmap",
    "author": "memo.php",
    "version": "0.2"
  },
  "format": "node_tree",
  "data": {
    "id": "root",
    "topic": "根节点",
    "expanded": true,
    "children": [
      {
        "id": "child1",
        "topic": "子节点1",
        "direction": "left",
        "children": []
      },
      {
        "id": "child2",
        "topic": "子节点2",
        "direction": "right",
        "children": []
      }
    ]
  }
}
```

### B. 数据库文件位置

- 数据库：`./memo.sqlite`
- 附件目录：`./uploads/`
- WAL日志：`./memo.sqlite-wal`
- SHM文件：`./memo.sqlite-shm`

### C. 快捷键列表

#### 首页
- `/`：聚焦搜索框

#### 思维导图编辑器
- `Enter`：创建同级节点
- `Tab`：创建子级节点
- `Shift + Tab`：将节点升级
- `Delete`：删除节点
- `F2`：重命名节点
- `Ctrl/Cmd + Z`：撤销
- 鼠标中键/空格：拖拽画布
- 滚轮：缩放
- `Alt + 拖动`：复制节点

---

**文档版本**：1.0  
**最后更新**：2025-11-04  
**适用于**：Memo.php 单文件备忘录应用

---

*本文档由AI自动生成，涵盖了所有公共API、函数和组件的详细说明及使用示例。*
