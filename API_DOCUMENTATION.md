# Memo.php API 文档

## 目录

- [概述](#概述)
- [数据库函数](#数据库函数)
- [分类管理 API](#分类管理-api)
- [项目管理 API](#项目管理-api)
- [步骤管理 API](#步骤管理-api)
- [附件管理 API](#附件管理-api)
- [思维导图管理 API](#思维导图管理-api)
- [HTTP API 端点](#http-api-端点)
- [前端 JavaScript API](#前端-javascript-api)
- [使用示例](#使用示例)

---

## 概述

`memo.php` 是一个单文件 PHP 备忘录应用，提供完整的任务管理、分类管理、步骤跟踪、附件上传和思维导图功能。

### 技术栈
- **后端**: PHP 7.4+ (严格模式)
- **数据库**: SQLite
- **前端**: 原生 JavaScript + Markdown 编辑器 (EasyMDE)
- **思维导图**: jsMind

### 核心功能
- 任务/备忘录管理（创建、编辑、删除、标记完成）
- 分类管理
- 子任务步骤管理
- 附件上传（图片、ZIP）
- 思维导图编辑
- 数据导出（JSON/CSV）

---

## 数据库函数

### `db(): PDO`

获取数据库连接实例（单例模式）。

**返回值**: `PDO` - 数据库连接对象

**示例**:
```php
$pdo = db();
$stmt = $pdo->query('SELECT * FROM items');
```

**说明**:
- 首次调用时自动初始化数据库
- 自动创建所需的数据表（categories, items, steps, attachments, mindmaps）
- 使用 WAL 模式和外键约束

---

## 分类管理 API

### `get_categories(): array`

获取所有分类及其对应的项目数量。

**返回值**: `array` - `[分类数组, 数量映射]`
- 第一个元素：分类数组，每个分类包含 `id` 和 `name`
- 第二个元素：数量映射数组，键为分类 ID，值为该分类下的项目数量

**示例**:
```php
[$categories, $counts] = get_categories();
// $categories = [['id' => 1, 'name' => '备忘录'], ...]
// $counts = [1 => 5, 2 => 3, ...]
```

### `ensure_other_category(): int`

确保"其他"分类存在，如果不存在则创建。

**返回值**: `int` - 分类 ID

**示例**:
```php
$otherCatId = ensure_other_category();
```

---

## 项目管理 API

### `get_item(int $id): ?array`

根据 ID 获取单个项目（包含分类名称）。

**参数**:
- `$id` (int): 项目 ID

**返回值**: `?array` - 项目数据，包含所有字段及 `cat_name`（分类名称），如果不存在返回 `null`

**返回字段**:
- `id`: 项目 ID
- `title`: 标题
- `description`: 描述（Markdown）
- `done`: 是否完成（0/1）
- `category_id`: 分类 ID
- `order_index`: 排序索引
- `created_at`: 创建时间戳
- `updated_at`: 更新时间戳
- `cat_name`: 分类名称

**示例**:
```php
$item = get_item(1);
if ($item) {
    echo $item['title'];
}
```

### `get_steps(int $item_id): array`

获取项目的所有步骤（按排序索引排序）。

**参数**:
- `$item_id` (int): 项目 ID

**返回值**: `array` - 步骤数组

**示例**:
```php
$steps = get_steps(1);
foreach ($steps as $step) {
    echo $step['title'];
}
```

### `get_steps_by_time(int $item_id): array`

获取项目的所有步骤（按创建时间排序）。

**参数**:
- `$item_id` (int): 项目 ID

**返回值**: `array` - 步骤数组（按时间排序）

**示例**:
```php
$steps = get_steps_by_time(1);
```

---

## 附件管理 API

### `get_attachment(int $id): ?array`

根据 ID 获取附件信息。

**参数**:
- `$id` (int): 附件 ID

**返回值**: `?array` - 附件数据，包含以下字段：
- `id`: 附件 ID
- `item_id`: 关联的项目 ID
- `step_id`: 关联的步骤 ID（可选）
- `orig_name`: 原始文件名
- `stored_name`: 存储文件名
- `mime`: MIME 类型
- `size`: 文件大小（字节）
- `created_at`: 创建时间戳

**示例**:
```php
$attachment = get_attachment(1);
if ($attachment) {
    echo $attachment['orig_name'];
}
```

### `attachments_for_item(int $item_id): array`

获取项目的所有附件。

**参数**:
- `$item_id` (int): 项目 ID

**返回值**: `array` - 附件数组（按 ID 降序）

**示例**:
```php
$attachments = attachments_for_item(1);
foreach ($attachments as $att) {
    echo $att['orig_name'];
}
```

---

## 思维导图管理 API

### `get_mindmaps(): array`

获取所有思维导图列表。

**返回值**: `array` - 思维导图数组，按更新时间降序排列

**返回字段**:
- `id`: 导图 ID
- `title`: 标题
- `content`: JSON 内容
- `created_at`: 创建时间戳
- `updated_at`: 更新时间戳

**示例**:
```php
$mindmaps = get_mindmaps();
foreach ($mindmaps as $map) {
    echo $map['title'];
}
```

### `get_mindmap(int $id): ?array`

根据 ID 获取单个思维导图。

**参数**:
- `$id` (int): 思维导图 ID

**返回值**: `?array` - 思维导图数据，不存在返回 `null`

**示例**:
```php
$mindmap = get_mindmap(1);
if ($mindmap) {
    $data = json_decode($mindmap['content'], true);
}
```

### `create_mindmap(string $title, string $content): array`

创建新的思维导图。

**参数**:
- `$title` (string): 标题
- `$content` (string): JSON 格式的导图内容

**返回值**: `array` - 包含 `id` 和 `updated_at`

**示例**:
```php
$json = json_encode(['data' => ['topic' => '根节点']]);
$result = create_mindmap('我的导图', $json);
echo $result['id'];
```

### `update_mindmap(int $id, string $title, string $content): array`

更新现有思维导图。

**参数**:
- `$id` (int): 思维导图 ID
- `$title` (string): 标题
- `$content` (string): JSON 格式的导图内容

**返回值**: `array` - 包含 `id` 和 `updated_at`

**示例**:
```php
$json = json_encode(['data' => ['topic' => '更新的节点']]);
$result = update_mindmap(1, '更新的标题', $json);
```

### `delete_mindmap(int $id): void`

删除思维导图。

**参数**:
- `$id` (int): 思维导图 ID

**示例**:
```php
delete_mindmap(1);
```

### `mindmap_outline_preview(string $json, int $limit = 8): string`

生成思维导图的文本预览（大纲）。

**参数**:
- `$json` (string): JSON 格式的导图内容
- `$limit` (int): 最大显示行数，默认 8

**返回值**: `string` - Markdown 格式的大纲文本

**示例**:
```php
$preview = mindmap_outline_preview($jsonContent, 10);
echo $preview;
```

### `default_mindmap_payload(): string`

获取默认思维导图的 JSON 字符串。

**返回值**: `string` - JSON 字符串

**示例**:
```php
$defaultJson = default_mindmap_payload();
```

---

## HTTP API 端点

### GET 端点

#### 下载附件
```
GET ?download={attachment_id}
```

**参数**:
- `download` (int): 附件 ID

**响应**:
- 成功：返回文件内容（图片内联显示，其他文件下载）
- 404：文件不存在

**示例**:
```
GET /memo.php?download=123
```

#### 导出数据
```
GET ?export={type}&cat={category_id}&q={query}
```

**参数**:
- `export` (string): 导出类型，`json` 或 `csv`
- `cat` (string, 可选): 分类 ID，`all` 表示全部
- `q` (string, 可选): 搜索关键词

**响应**:
- JSON 导出：包含项目及步骤的完整数据
- CSV 导出：包含项目基本信息和步骤数量

**示例**:
```
GET /memo.php?export=json&cat=1&q=重要
GET /memo.php?export=csv
```

#### 视图页面
```
GET ?view={view_name}&id={id}
```

**支持的视图**:
- `new`: 新建项目页面
- `item`: 编辑项目页面（需要 `id`）
- `maps`: 思维导图列表
- `map_edit`: 编辑思维导图（需要 `id`）

**示例**:
```
GET /memo.php?view=new
GET /memo.php?view=item&id=1
GET /memo.php?view=maps
GET /memo.php?view=map_edit&id=1
```

#### 获取分类（AJAX）
```
GET ?action=ping_cats
```

**要求**: 需要 `X-Requested-With` 头

**响应**: JSON 格式
```json
{
  "ok": 1,
  "cats": [{"id": 1, "name": "备忘录"}, ...],
  "counts": {1: 5, 2: 3, ...},
  "total": 10,
  "uncat": 2
}
```

---

### POST 端点

所有 POST 请求都需要 `action` 参数指定操作类型。支持 AJAX 请求（需要 `X-Requested-With` 头）。

#### 创建草稿
```
POST action=create_draft
```

**响应** (JSON):
```json
{"ok": 1, "id": 123}
```

**示例**:
```javascript
fetch('memo.php', {
  method: 'POST',
  headers: {'Content-Type': 'application/x-www-form-urlencoded'},
  body: 'action=create_draft'
});
```

#### 添加项目
```
POST action=add_item
```

**参数**:
- `title` (string, 必需): 标题
- `description` (string, 可选): 描述（Markdown）
- `category_id` (int, 可选): 分类 ID

**响应** (AJAX):
```json
{"ok": 1, "id": 123}
```

**示例**:
```javascript
const formData = new FormData();
formData.append('action', 'add_item');
formData.append('title', '新任务');
formData.append('description', '这是描述');
formData.append('category_id', '1');

fetch('memo.php', {
  method: 'POST',
  body: formData
});
```

#### 切换完成状态
```
POST action=toggle_done
```

**参数**:
- `id` (int, 必需): 项目 ID
- `done` (int, 可选): 1 为完成，0 为未完成

**响应** (AJAX):
```json
{"ok": 1, "id": 123, "updated_at": 1234567890}
```

#### 编辑项目
```
POST action=edit_item
```

**参数**:
- `id` (int, 必需): 项目 ID
- `title` (string, 必需): 标题
- `description` (string, 可选): 描述
- `category_id` (int, 可选): 分类 ID

**响应** (AJAX):
```json
{"ok": 1}
```

#### 删除项目
```
POST action=delete_item
```

**参数**:
- `id` (int, 必需): 项目 ID

**响应**: 无（非 AJAX 请求会重定向）

#### 添加分类
```
POST action=add_category
```

**参数**:
- `name` (string, 必需): 分类名称

**响应** (AJAX): 返回分类列表 JSON（同 `ping_cats`）

#### 编辑分类
```
POST action=edit_category
```

**参数**:
- `id` (int, 必需): 分类 ID
- `name` (string, 必需): 新名称

**响应** (AJAX): 返回分类列表 JSON

#### 删除分类
```
POST action=delete_category
```

**参数**:
- `id` (int, 必需): 分类 ID

**说明**: 删除分类时，该分类下的项目会自动移动到"其他"分类

**响应** (AJAX): 返回分类列表 JSON

#### 添加步骤
```
POST action=add_step
```

**参数**:
- `item_id` (int, 必需): 项目 ID
- `title` (string, 必需): 步骤标题

**响应** (AJAX):
```json
{"ok": 1, "step": {...}}
```

#### 切换步骤完成状态
```
POST action=toggle_step
```

**参数**:
- `id` (int, 必需): 步骤 ID
- `done` (int, 必需): 1 为完成，0 为未完成

**响应** (AJAX):
```json
{"ok": 1, "item_id": 123, "updated_at": 1234567890}
```

#### 编辑步骤标题
```
POST action=edit_step
```

**参数**:
- `id` (int, 必需): 步骤 ID
- `title` (string, 必需): 新标题

**响应** (AJAX):
```json
{"ok": 1}
```

#### 编辑步骤备注
```
POST action=edit_step_notes
```

**参数**:
- `id` (int, 必需): 步骤 ID
- `notes` (string, 可选): 备注内容（Markdown）

**响应** (AJAX):
```json
{"ok": 1}
```

#### 删除步骤
```
POST action=delete_step
```

**参数**:
- `id` (int, 必需): 步骤 ID

**响应**: 无

#### 重新排序项目
```
POST action=reorder_items
```

**参数**:
- `order` (string, 必需): 逗号分隔的项目 ID 列表

**示例**:
```
order=3,1,5,2
```

**响应** (AJAX): HTTP 204

#### 重新排序步骤
```
POST action=reorder_steps
```

**参数**:
- `item_id` (int, 必需): 项目 ID
- `order` (string, 必需): 逗号分隔的步骤 ID 列表

**响应** (AJAX): HTTP 204

#### 上传附件
```
POST action=upload_attachment
```

**参数**:
- `target` (string, 必需): 目标类型，`item` 或 `step`
- `target_id` (int, 必需): 目标 ID
- `file` (file, 必需): 文件（multipart/form-data）

**限制**:
- 最大文件大小：20MB
- 允许类型：图片（png, jpeg, webp, gif, svg）和 ZIP

**响应** (AJAX):
```json
{
  "ok": 1,
  "id": 123,
  "url": "?download=123",
  "mime": "image/png",
  "markdown": "![filename](?download=123)",
  "size": 102400
}
```

**示例**:
```javascript
const formData = new FormData();
formData.append('action', 'upload_attachment');
formData.append('target', 'item');
formData.append('target_id', '1');
formData.append('file', fileInput.files[0]);

fetch('memo.php', {
  method: 'POST',
  body: formData
});
```

#### 删除附件
```
POST action=delete_attachment
```

**参数**:
- `id` (int, 必需): 附件 ID

**响应** (AJAX):
```json
{"ok": 1}
```

#### 保存思维导图
```
POST action=save_mindmap
```

**参数**:
- `id` (int, 可选): 思维导图 ID（0 或未提供则创建新导图）
- `title` (string, 必需): 标题
- `content` (string, 必需): JSON 格式的导图内容

**响应** (AJAX):
```json
{"ok": 1, "id": 123, "updated_at": 1234567890}
```

#### 删除思维导图
```
POST action=delete_mindmap
```

**参数**:
- `id` (int, 必需): 思维导图 ID

**响应** (AJAX):
```json
{"ok": 1}
```

---

## 前端 JavaScript API

### 全局函数

#### `fetchCats(): Promise<Object>`

获取分类列表。

**返回值**: Promise，解析为：
```javascript
{
  cats: [{id: 1, name: "备忘录"}, ...],
  counts: {1: 5, 2: 3, ...},
  total: 10,
  uncat: 2
}
```

**示例**:
```javascript
const {cats, counts, total} = await fetchCats();
```

#### `renderCatRows(cats, counts): void`

渲染分类行到分类模态框。

**参数**:
- `cats` (Array): 分类数组
- `counts` (Object): 数量映射

#### `refreshSidebarCats(cats, counts, total): void`

刷新侧边栏分类列表。

**参数**:
- `cats` (Array): 分类数组
- `counts` (Object): 数量映射
- `total` (number): 总项目数

#### `moveCard(id, dir): void`

移动卡片（用于拖拽排序）。

**参数**:
- `id` (number): 项目 ID
- `dir` (string): 方向，`'up'` 或 `'down'`

#### `sendOrder(): void`

发送项目排序到服务器。

#### `openCatModal(): void`

打开分类管理模态框。

#### `closeCatModal(): void`

关闭分类管理模态框。

#### `escapeHtml(s): string`

转义 HTML 特殊字符。

**参数**:
- `s` (string): 要转义的字符串

**返回值**: 转义后的字符串

#### `fmt(ts): string`

格式化时间戳为日期时间字符串。

**参数**:
- `ts` (number): Unix 时间戳（秒）

**返回值**: `YYYY-MM-DD HH:mm` 格式的字符串

**示例**:
```javascript
fmt(1234567890); // "2009-02-13 23:31"
```

---

## 辅助函数

### PHP 辅助函数

#### `h(?string $s): string`

HTML 转义函数。

**参数**:
- `$s` (?string): 要转义的字符串

**返回值**: 转义后的字符串

**示例**:
```php
echo h('<script>alert("xss")</script>');
// 输出: &lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;
```

#### `now(): int`

获取当前时间戳。

**返回值**: Unix 时间戳（秒）

#### `is_post(): bool`

检查是否为 POST 请求。

**返回值**: `true` 如果是 POST，否则 `false`

#### `is_ajax(): bool`

检查是否为 AJAX 请求（通过 `X-Requested-With` 头判断）。

**返回值**: `true` 如果是 AJAX，否则 `false`

#### `redirect(string $url = ''): void`

重定向到指定 URL。

**参数**:
- `$url` (string, 可选): 目标 URL，为空则重定向到当前页面（移除查询参数）

#### `bytes_h(int $b): string`

格式化字节数为人类可读格式。

**参数**:
- `$b` (int): 字节数

**返回值**: 格式化后的字符串（如 "1.5 MB"）

**示例**:
```php
echo bytes_h(1536000); // "1.5 MB"
```

#### `dt(int $ts): string`

格式化时间戳为日期时间字符串。

**参数**:
- `$ts` (int): Unix 时间戳（秒）

**返回值**: `Y-m-d H:i` 格式的字符串

**示例**:
```php
echo dt(1234567890); // "2009-02-13 23:31"
```

#### `json_cats(): void`

输出分类列表的 JSON（用于 AJAX 响应）。

**输出**: JSON 格式的分类数据

---

## 使用示例

### 示例 1: 创建新项目

**PHP 方式**:
```php
$pdo = db();
$now = now();
$pdo->prepare('INSERT INTO items(title,description,done,category_id,order_index,created_at,updated_at) VALUES(?,?,?,?,0,?,?)')
    ->execute(['新任务', '这是描述', 0, 1, $now, $now]);
$id = (int)$pdo->lastInsertId();
```

**HTTP API 方式**:
```javascript
const formData = new FormData();
formData.append('action', 'add_item');
formData.append('title', '新任务');
formData.append('description', '这是描述');
formData.append('category_id', '1');

const response = await fetch('memo.php', {
  method: 'POST',
  body: formData
});
const result = await response.json();
console.log('创建的项目 ID:', result.id);
```

### 示例 2: 获取项目及其步骤

```php
$item = get_item(1);
if ($item) {
    echo "标题: " . $item['title'] . "\n";
    echo "分类: " . ($item['cat_name'] ?? '未分类') . "\n";
    
    $steps = get_steps($item['id']);
    echo "步骤数量: " . count($steps) . "\n";
    foreach ($steps as $step) {
        echo "  - " . $step['title'] . ($step['done'] ? " ✓" : "") . "\n";
    }
}
```

### 示例 3: 上传附件

```javascript
const fileInput = document.getElementById('file-input');
const file = fileInput.files[0];

const formData = new FormData();
formData.append('action', 'upload_attachment');
formData.append('target', 'item');
formData.append('target_id', '1');
formData.append('file', file);

const response = await fetch('memo.php', {
  method: 'POST',
  body: formData
});

const result = await response.json();
if (result.ok) {
    console.log('附件 URL:', result.url);
    console.log('Markdown:', result.markdown);
}
```

### 示例 4: 创建和更新思维导图

```php
// 创建新导图
$title = '项目规划';
$content = json_encode([
    'meta' => ['name' => 'project-plan', 'version' => '0.2'],
    'format' => 'node_tree',
    'data' => [
        'id' => 'root',
        'topic' => '项目规划',
        'children' => [
            ['id' => 'phase1', 'topic' => '第一阶段', 'children' => []],
            ['id' => 'phase2', 'topic' => '第二阶段', 'children' => []]
        ]
    ]
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$result = create_mindmap($title, $content);
echo "导图 ID: " . $result['id'] . "\n";

// 更新导图
$updatedContent = json_encode([...], JSON_UNESCAPED_UNICODE);
update_mindmap($result['id'], '更新的标题', $updatedContent);
```

### 示例 5: 导出数据

```javascript
// 导出为 JSON
const response = await fetch('memo.php?export=json&cat=1');
const blob = await response.blob();
const url = URL.createObjectURL(blob);
const a = document.createElement('a');
a.href = url;
a.download = 'memo_export.json';
a.click();

// 导出为 CSV
const csvResponse = await fetch('memo.php?export=csv&q=重要');
const csvBlob = await csvResponse.blob();
// ... 下载 CSV
```

### 示例 6: 批量操作

```php
// 获取所有未完成的项目
$pdo = db();
$stmt = $pdo->query('SELECT * FROM items WHERE done = 0 ORDER BY updated_at DESC');
$items = $stmt->fetchAll();

foreach ($items as $item) {
    $steps = get_steps($item['id']);
    $doneSteps = array_filter($steps, fn($s) => $s['done']);
    
    // 如果所有步骤都完成，标记项目为完成
    if (count($steps) > 0 && count($doneSteps) === count($steps)) {
        $pdo->prepare('UPDATE items SET done=1, updated_at=? WHERE id=?')
            ->execute([now(), $item['id']]);
    }
}
```

---

## 错误处理

### API 错误响应格式

当发生错误时（AJAX 请求），服务器返回：

```json
{
  "ok": 0,
  "error": "错误消息"
}
```

HTTP 状态码：400

### 常见错误

1. **标题必填**: 创建或编辑项目/步骤时标题为空
2. **文件过大**: 上传文件超过 20MB
3. **不支持的文件类型**: 上传了不允许的文件类型
4. **目标无效**: 上传附件时目标类型或 ID 无效
5. **思维导图数据格式不正确**: JSON 格式错误

---

## 配置常量

```php
const DB_FILE = __DIR__ . '/memo.sqlite';           // 数据库文件路径
const UPLOAD_DIR = __DIR__ . '/uploads';            // 上传目录
const MAX_UPLOAD_BYTES = 20 * 1024 * 1024;          // 最大上传大小（20MB）
```

---

## 数据库结构

### categories 表
- `id` (INTEGER PRIMARY KEY)
- `name` (TEXT UNIQUE NOT NULL)
- `created_at` (INTEGER NOT NULL)

### items 表
- `id` (INTEGER PRIMARY KEY)
- `title` (TEXT NOT NULL)
- `description` (TEXT)
- `done` (INTEGER NOT NULL DEFAULT 0)
- `category_id` (INTEGER, FOREIGN KEY)
- `order_index` (INTEGER NOT NULL DEFAULT 0)
- `created_at` (INTEGER NOT NULL)
- `updated_at` (INTEGER NOT NULL)

### steps 表
- `id` (INTEGER PRIMARY KEY)
- `item_id` (INTEGER NOT NULL, FOREIGN KEY)
- `title` (TEXT NOT NULL)
- `notes` (TEXT)
- `done` (INTEGER NOT NULL DEFAULT 0)
- `order_index` (INTEGER NOT NULL DEFAULT 0)
- `created_at` (INTEGER NOT NULL)
- `updated_at` (INTEGER NOT NULL)

### attachments 表
- `id` (INTEGER PRIMARY KEY)
- `item_id` (INTEGER, FOREIGN KEY)
- `step_id` (INTEGER, FOREIGN KEY)
- `orig_name` (TEXT NOT NULL)
- `stored_name` (TEXT NOT NULL)
- `mime` (TEXT NOT NULL)
- `size` (INTEGER NOT NULL)
- `created_at` (INTEGER NOT NULL)

### mindmaps 表
- `id` (INTEGER PRIMARY KEY)
- `title` (TEXT NOT NULL)
- `content` (TEXT NOT NULL)
- `created_at` (INTEGER NOT NULL)
- `updated_at` (INTEGER NOT NULL)

---

## 安全特性

1. **XSS 防护**: 使用 `h()` 函数转义输出
2. **SQL 注入防护**: 使用 PDO 预处理语句
3. **文件上传验证**: 检查文件类型和大小
4. **CSP 头**: 内容安全策略
5. **X-Frame-Options**: 防止点击劫持
6. **Referrer-Policy**: 控制引用来源信息

---

## 许可证

Apache License 2.0

---

## 更新日志

### 修订版特性
1. 引入思维导图库与编辑器
2. 侧边栏添加"思维导图"按钮
3. CSP 增加 'unsafe-inline' 支持
4. 修复搜索框颜色变量 bug

---

**文档版本**: 1.0  
**最后更新**: 2024
