<?php
// 单文件备忘录应用（修订版）
// 说明：此文件是原始单文件备忘录的完整替换版本。
// 修订内容：
//   1. 引入思维导图库与编辑器，可通过 ?view=maps / ?view=map_edit 访问。
//   2. 在侧边栏添加“思维导图”按钮，方便访问导图模块。
//   3. CSP 增加 'unsafe-inline'，修复无法执行内联脚本的问题。
//   4. 修复搜索框颜色变量 bug（color:var(--text)）。

declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
mb_internal_encoding('UTF-8');

// 移除默认 X-Powered-By 头
header_remove('X-Powered-By');

// —— 安全响应头 ——
// 注意：由于本应用使用了大量内联脚本，为确保功能正常需允许 'unsafe-inline'。
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self' cdn.jsdelivr.net; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline' cdn.jsdelivr.net; font-src cdn.jsdelivr.net; script-src 'self' 'unsafe-inline' cdn.jsdelivr.net; base-uri 'self'; form-action 'self'; frame-ancestors 'self'");

$cdnResources = [
  'fonts' => [
    ['href' => 'https://cdn.jsdelivr.net/npm/@fontsource/cinzel@5.2.8/500.css', 'integrity' => 'sha384-7v1bWR/nP3kDOX0ewP+ZV/TdyDB1LMi6swZIHUv+/ihIN+yo/tIKCXkVJk1WaZ2b', 'crossorigin' => 'anonymous'],
    ['href' => 'https://cdn.jsdelivr.net/npm/@fontsource/cinzel@5.2.8/600.css', 'integrity' => 'sha384-QNu+JjOkpmxtvAoZEuUZ6tqGhPZLY3ygt9NB3nGm5kX3TG9gZtEi3VTRvAf65bMb', 'crossorigin' => 'anonymous'],
    ['href' => 'https://cdn.jsdelivr.net/npm/@fontsource/cinzel@5.2.8/700.css', 'integrity' => 'sha384-O/oLJBLGGldMs7HxAYRnSN50LYc4P4P3qiJbqSV358VWDpuJKiErXj9iWy8+R3LZ', 'crossorigin' => 'anonymous'],
    ['href' => 'https://cdn.jsdelivr.net/npm/@fontsource/inter@5.2.8/400.css', 'integrity' => 'sha384-xIqhvN2Z/Umq7Ox8y6t3uFHhjyQo0knjgs1wcj13JjTuHOGIaXBHO7eal9RhNMdd', 'crossorigin' => 'anonymous'],
    ['href' => 'https://cdn.jsdelivr.net/npm/@fontsource/inter@5.2.8/500.css', 'integrity' => 'sha384-V0O4X1tN0UwvdYzAEJQFmI91MWdJS28KYainePiRQIAtDyKH5cZxjVuoOPp/0v9g', 'crossorigin' => 'anonymous'],
    ['href' => 'https://cdn.jsdelivr.net/npm/@fontsource/inter@5.2.8/600.css', 'integrity' => 'sha384-zZdQWz6lweHJ9ZIJxqSYDpVJzhjcCKCxfftjlgIC/g3Ok/FynI4BfyMWG5/0mJt9', 'crossorigin' => 'anonymous'],
    ['href' => 'https://cdn.jsdelivr.net/npm/@fontsource/noto-sans-sc@5.2.8/400.css', 'integrity' => 'sha384-C62fXKqqwZ9MFRLsB6RTUMQIde5FFdkxndflVMwd9/dlzqT5fZ1okVPrXregodNt', 'crossorigin' => 'anonymous'],
    ['href' => 'https://cdn.jsdelivr.net/npm/@fontsource/noto-sans-sc@5.2.8/500.css', 'integrity' => 'sha384-dBYV6PZWk3vZv32AIGWsVnZ0mDANQX/56OezkEY2ajdJK3sLcvE/+D/zSgisRjwh', 'crossorigin' => 'anonymous'],
    ['href' => 'https://cdn.jsdelivr.net/npm/@fontsource/noto-sans-sc@5.2.8/600.css', 'integrity' => 'sha384-sqtOrv0sKfqV0UBt7XCsR2BYkw60mUJFI4GU8938hHadWxnt54td7dRTSb5Z5EW5', 'crossorigin' => 'anonymous'],
    ['href' => 'https://cdn.jsdelivr.net/npm/@fontsource/noto-sans-sc@5.2.8/700.css', 'integrity' => 'sha384-KP/YW0fs+LA7Lr1J11CHvrKs+U773BQXOSyMclL0/xjjg3H0c6lR/pC2DJdwPuZZ', 'crossorigin' => 'anonymous'],
    ['href' => 'https://cdn.jsdelivr.net/npm/@fontsource/noto-serif-sc@5.2.8/500.css', 'integrity' => 'sha384-3AnTn7NCG8m/qcO50AsiUsz8gcEiswj9GyPHlwUkvYNmWPuowSqhefcCVsuY3jGj', 'crossorigin' => 'anonymous'],
    ['href' => 'https://cdn.jsdelivr.net/npm/@fontsource/noto-serif-sc@5.2.8/600.css', 'integrity' => 'sha384-I5he03PyqIXz7dU+fRsdxKQgLr9XC8WYe4vBhPBGJzZFMmlTmRRcvMgTXzvFxmFZ', 'crossorigin' => 'anonymous'],
    ['href' => 'https://cdn.jsdelivr.net/npm/@fontsource/noto-serif-sc@5.2.8/700.css', 'integrity' => 'sha384-+VJ42QNJY5J0fJiq6GZfRN9yTBORo6g6f0X3RGTQ4Uz1HYnxpbjNIqfu6oLQ5/jF', 'crossorigin' => 'anonymous'],
  ],
  'scripts' => [
    'marked' => ['src' => 'https://cdn.jsdelivr.net/npm/marked@12.0.2/marked.min.js', 'integrity' => 'sha384-/TQbtLCAerC3jgaim+N78RZSDYV7ryeoBCVqTuzRrFec2akfBkHS7ACQ3PQhvMVi', 'crossorigin' => 'anonymous'],
    'dompurify' => ['src' => 'https://cdn.jsdelivr.net/npm/dompurify@3.1.6/dist/purify.min.js', 'integrity' => 'sha384-+VfUPEb0PdtChMwmBcBmykRMDd+v6D/oFmB3rZM/puCMDYcIvF968OimRh4KQY9a', 'crossorigin' => 'anonymous'],
    'zip.js' => ['src' => 'https://cdn.jsdelivr.net/npm/@zip.js/zip.js@2.7.32/dist/zip.min.js', 'integrity' => 'sha384-fWYnD1jIJVL3pVW4BM6uK4qLodzNKqokRtUEAwAYPVQPb65mU4XgueXcVM4k0ZrH', 'crossorigin' => 'anonymous'],
    'unrar.js' => ['src' => 'https://cdn.jsdelivr.net/npm/unrar.js@0.2.5/unrar.js', 'integrity' => 'sha384-jvOQWFL9oHRavzJxU2L70XQ838g4APCuQEbOZqZtPNgh2MlVerKtLmBqpbv2RbRH', 'crossorigin' => 'anonymous'],
    'mammoth' => ['src' => 'https://cdn.jsdelivr.net/npm/mammoth@1.6.0/mammoth.browser.min.js', 'integrity' => 'sha384-nFoSjZIoH3CCp8W639jJyQkuPHinJ2NHe7on1xvlUA7SuGfJAfvMldrsoAVm6ECz', 'crossorigin' => 'anonymous'],
    'xlsx' => ['src' => 'https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js', 'integrity' => 'sha384-vtjasyidUo0kW94K5MXDXntzOJpQgBKXmE7e2Ga4LG0skTTLeBi97eFAXsqewJjw', 'crossorigin' => 'anonymous'],
    'html-to-image' => ['src' => 'https://cdn.jsdelivr.net/npm/html-to-image@1.11.11/dist/html-to-image.min.js', 'integrity' => 'sha384-lELZMVO0WZSKHeOrm8rKLCq9ZXjY1+2I9uL/QyDxIXSd83b3/z5xBXb1uDoFi3P/', 'crossorigin' => 'anonymous'],
    'jspdf' => ['src' => 'https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js', 'integrity' => 'sha384-JcnsjUPPylna1s1fvi1u12X5qjY5OL56iySh75FdtrwhO/SWXgMjoVqcKyIIWOLk', 'crossorigin' => 'anonymous'],
  ],
];

// —— 配置 ——
const DB_FILE = __DIR__ . '/memo.sqlite';
const UPLOAD_DIR = __DIR__ . '/storage/uploads';
const MAX_UPLOAD_BYTES = 15 * 1024 * 1024; // 15MB
const ALLOWED_UPLOAD_MIME_MAP = [
  'image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp','image/gif'=>'gif','image/svg+xml'=>'svg','image/avif'=>'avif','image/bmp'=>'bmp','image/x-icon'=>'ico',
  'application/pdf'=>'pdf','application/zip'=>'zip','application/x-zip-compressed'=>'zip','application/x-rar-compressed'=>'rar','application/vnd.rar'=>'rar',
  'application/msword'=>'doc','application/vnd.openxmlformats-officedocument.wordprocessingml.document'=>'docx','application/vnd.ms-word.document.macroenabled.12'=>'docm','application/vnd.openxmlformats-officedocument.wordprocessingml.template'=>'dotx','application/vnd.ms-word.template.macroenabled.12'=>'dotm',
  'application/vnd.ms-excel'=>'xls','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'=>'xlsx','application/vnd.ms-excel.sheet.macroenabled.12'=>'xlsm','application/vnd.ms-excel.sheet.binary.macroenabled.12'=>'xlsb','application/vnd.openxmlformats-officedocument.spreadsheetml.template'=>'xltx','application/vnd.ms-excel.template.macroenabled.12'=>'xltm','text/csv'=>'csv',
  'text/plain'=>'txt','text/markdown'=>'md','text/x-markdown'=>'md','application/json'=>'json','text/json'=>'json','text/yaml'=>'yaml','application/yaml'=>'yaml','text/x-yaml'=>'yaml','text/tab-separated-values'=>'tsv','text/x-log'=>'log',
  'audio/mpeg'=>'mp3','audio/mp3'=>'mp3','audio/ogg'=>'ogg','audio/opus'=>'opus','audio/wav'=>'wav','audio/x-wav'=>'wav','audio/webm'=>'weba','audio/mp4'=>'m4a','audio/aac'=>'aac','audio/flac'=>'flac',
  'video/mp4'=>'mp4','video/quicktime'=>'mov','video/x-matroska'=>'mkv','video/webm'=>'webm','video/x-msvideo'=>'avi','video/mpeg'=>'mpeg','video/ogg'=>'ogv',
];
date_default_timezone_set('Asia/Shanghai');

// —— 思维导图默认内容 ——
const DEFAULT_MINDMAP = [
  'meta' => [
    'name' => 'memo-mindmap',
    'author' => 'memo.php',
    'version' => '0.3'
  ],
  'format' => 'node_tree',
  'relations' => [],
  'data' => [
    'id' => 'root',
    'topic' => '未命名导图',
    'expanded' => true,
    'children' => [
      [
        'id' => 'view-outline',
        'topic' => '📋 大纲视图',
        'direction' => 'right',
        'expanded' => true,
        'children' => [
          ['id' => 'view-outline-focus', 'topic' => '聚焦中心主题', 'children' => []],
          ['id' => 'view-outline-ideas', 'topic' => '层级梳理想法', 'children' => []],
          ['id' => 'view-outline-next', 'topic' => '下一步行动', 'children' => []],
        ],
      ],
      [
        'id' => 'view-kanban',
        'topic' => '🗂 看板视图',
        'direction' => 'right',
        'expanded' => true,
        'children' => [
          ['id' => 'view-kanban-todo', 'topic' => '待处理', 'children' => []],
          ['id' => 'view-kanban-doing', 'topic' => '进行中', 'children' => []],
          ['id' => 'view-kanban-done', 'topic' => '已完成', 'children' => []],
        ],
      ],
      [
        'id' => 'view-timeline',
        'topic' => '🕒 时间线视图',
        'direction' => 'right',
        'expanded' => true,
        'children' => [
          ['id' => 'view-timeline-upcoming', 'topic' => '近期计划', 'children' => []],
          ['id' => 'view-timeline-milestone', 'topic' => '关键里程碑', 'children' => []],
          ['id' => 'view-timeline-review', 'topic' => '复盘总结', 'children' => []],
        ],
      ],
      [
        'id' => 'view-resources',
        'topic' => '📎 资料与附件',
        'direction' => 'right',
        'expanded' => true,
        'children' => [
          ['id' => 'view-resources-attach', 'topic' => '上传附件（≤15MB 图片/音视频/PDF/Word/Excel/ZIP·RAR/文本）', 'children' => []],
          ['id' => 'view-resources-link', 'topic' => '新增链接素材', 'children' => []],
        ],
      ],
    ],
  ],
];

function default_mindmap_payload(): string {
  return json_encode(DEFAULT_MINDMAP, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function mindmap_force_right_orientation(&$node, int $depth = 0): void {
  if (!is_array($node)) {
    return;
  }
  $node['direction'] = $depth === 0 ? 'center' : 'right';
  if (!isset($node['children']) || !is_array($node['children'])) {
    $node['children'] = [];
    return;
  }
  $normalized = [];
  foreach ($node['children'] as $child) {
    if (!is_array($child)) {
      continue;
    }
    mindmap_force_right_orientation($child, $depth + 1);
    $normalized[] = $child;
  }
  $node['children'] = $normalized;
}

// —— 基础辅助函数 ——
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function now(): int { return time(); }
function is_post(): bool { return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'; }
function is_ajax(): bool { return !empty($_SERVER['HTTP_X_REQUESTED_WITH']); }
function redirect(string $url = ''): void { header('Location: '. ($url ?: strtok($_SERVER['REQUEST_URI'], '?'))); exit; }
function bytes_h(int $b): string { $u=['B','KB','MB','GB'];$i=0;$v=(float)$b;while($v>=1024&&$i<count($u)-1){$v/=1024;$i++;}return sprintf(($v>=10||$i===0)?'%.0f %s':'%.1f %s',$v,$u[$i]); }
function dt(int $ts): string { return date('Y-m-d H:i', $ts); }

if(!function_exists('array_is_list')){
  function array_is_list(array $array): bool {
    $i=0;
    foreach($array as $k=>$_){ if($k!==$i++) return false; }
    return true;
  }
}

function boolish(mixed $value): bool {
  if(is_bool($value)) return $value;
  if(is_int($value)) return $value !== 0;
  if(is_float($value)) return $value != 0.0;
  if(is_string($value)){
    $normalized=strtolower(trim($value));
    if($normalized==='' || in_array($normalized,['0','false','no','off','null'],true)) return false;
    return true;
  }
  return (bool)$value;
}

function cdn_attributes(array $entry): string {
  $integrity = $entry['integrity'] ?? '';
  if ($integrity === '') {
    return '';
  }

  $attributes = [
    sprintf(' integrity="%s"', htmlspecialchars($integrity, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')),
    sprintf(' crossorigin="%s"', htmlspecialchars($entry['crossorigin'] ?? 'anonymous', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')),
  ];

  if (!empty($entry['referrerpolicy'])) {
    $attributes[] = sprintf(' referrerpolicy="%s"', htmlspecialchars($entry['referrerpolicy'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
  }

  return implode('', $attributes);
}

function normalize_timestamp(mixed $value, ?int $fallback=null): int {
  if(is_int($value)) return $value;
  if(is_string($value)){
    if(ctype_digit($value)) return (int)$value;
    $ts=strtotime($value);
    if($ts!==false) return $ts;
  }
  if(is_float($value)) return (int)$value;
  return $fallback ?? now();
}

function highlight_text(string $text, string $term): string {
  $term=trim($term);
  if($term==='') return h($text);
  $pattern='/(?:'.preg_quote($term,'/').')/iu';
  $parts=preg_split($pattern,$text);
  if($parts===false || count($parts)===1){
    return h($text);
  }
  $matches=[];
  preg_match_all($pattern,$text,$matches);
  $out='';
  foreach($parts as $idx=>$part){
    $out.=h($part);
    if(isset($matches[0][$idx])){
      $out.='<mark>'.h($matches[0][$idx]).'</mark>';
    }
  }
  return $out;
}


// —— 数据库 ——
function db(): PDO {
  static $pdo;
  if ($pdo) return $pdo;
  $init = !file_exists(DB_FILE);
  $pdo = new PDO('sqlite:' . DB_FILE, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  $pdo->exec('PRAGMA journal_mode = WAL;');
  $pdo->exec('PRAGMA foreign_keys = ON;');
  if ($init) {
    // 创建基础表
    $pdo->exec('CREATE TABLE IF NOT EXISTS categories(
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      name TEXT UNIQUE NOT NULL,
      created_at INTEGER NOT NULL
    );');
    $pdo->exec('CREATE TABLE IF NOT EXISTS items(
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      title TEXT NOT NULL,
      description TEXT,
      done INTEGER NOT NULL DEFAULT 0,
      category_id INTEGER,
      order_index INTEGER NOT NULL DEFAULT 0,
      created_at INTEGER NOT NULL,
      updated_at INTEGER NOT NULL,
      previous_category_id INTEGER,
      FOREIGN KEY(category_id) REFERENCES categories(id) ON DELETE SET NULL,
      FOREIGN KEY(previous_category_id) REFERENCES categories(id) ON DELETE SET NULL
    );');
    $pdo->exec('CREATE TABLE IF NOT EXISTS steps(
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      item_id INTEGER NOT NULL,
      title TEXT NOT NULL,
      notes TEXT,
      done INTEGER NOT NULL DEFAULT 0,
      order_index INTEGER NOT NULL DEFAULT 0,
      created_at INTEGER NOT NULL,
      updated_at INTEGER NOT NULL,
      FOREIGN KEY(item_id) REFERENCES items(id) ON DELETE CASCADE
    );');
    $pdo->exec('CREATE TABLE IF NOT EXISTS attachments(
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      item_id INTEGER,
      step_id INTEGER,
      orig_name TEXT NOT NULL,
      stored_name TEXT NOT NULL,
      mime TEXT NOT NULL,
      size INTEGER NOT NULL,
      created_at INTEGER NOT NULL,
      FOREIGN KEY(item_id) REFERENCES items(id) ON DELETE CASCADE,
      FOREIGN KEY(step_id) REFERENCES steps(id) ON DELETE CASCADE
    );');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_att_item ON attachments(item_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_att_step ON attachments(step_id)');
    // 插入默认分类
    $stmt=$pdo->prepare('INSERT INTO categories(name,created_at) VALUES(?,?)');
    foreach(['备忘录','流程','其他'] as $n){ $stmt->execute([$n, now()]); }
  }
  $pdo->exec('CREATE TABLE IF NOT EXISTS mindmaps(
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    content TEXT NOT NULL,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
  );');
  $pdo->exec('CREATE TABLE IF NOT EXISTS mindmap_assets(
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    mindmap_id INTEGER,
    node_uid TEXT,
    session_key TEXT,
    orig_name TEXT NOT NULL,
    stored_name TEXT NOT NULL,
    mime TEXT NOT NULL,
    size INTEGER NOT NULL,
    created_at INTEGER NOT NULL,
    FOREIGN KEY(mindmap_id) REFERENCES mindmaps(id) ON DELETE CASCADE
  );');
  $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mindmap_assets_map ON mindmap_assets(mindmap_id)');
  $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mindmap_assets_session ON mindmap_assets(session_key)');
  $hasMap = (int)$pdo->query('SELECT COUNT(*) FROM mindmaps')->fetchColumn();
  if ($hasMap === 0) {
    $nowTs = now();
    $defaultPayload = default_mindmap_payload();
    $decoded = json_decode($defaultPayload, true);
    if (is_array($decoded) && isset($decoded['data']) && is_array($decoded['data'])) {
      $decoded['data']['topic'] = '默认导图';
      $defaultPayload = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $pdo->prepare('INSERT INTO mindmaps(title, content, created_at, updated_at) VALUES(?,?,?,?)')
        ->execute(['默认导图', $defaultPayload, $nowTs, $nowTs]);
  }
  // 创建上传目录
  if(!is_dir(UPLOAD_DIR)) @mkdir(UPLOAD_DIR,0775,true);

  $cols=$pdo->query('PRAGMA table_info(items)')->fetchAll();
  $names=array_map(fn($col)=>$col['name']??'', $cols);
  if(!in_array('previous_category_id',$names,true)){
    $pdo->exec('ALTER TABLE items ADD COLUMN previous_category_id INTEGER');
  }

  return $pdo;
}

// —— 获取分类及计数 ——
function get_categories(): array {
  $pdo=db();
  ensure_done_category();
  $cats=$pdo->query('SELECT id,name FROM categories ORDER BY name COLLATE NOCASE')->fetchAll();
  $map=[]; foreach($cats as $c) $map[$c['id']] = 0;
  $rows=$pdo->query('SELECT category_id, COUNT(*) AS c FROM items GROUP BY category_id')->fetchAll();
  foreach($rows as $r){ $cid=$r['category_id']; if($cid!==null&&isset($map[$cid])) $map[$cid]=(int)$r['c']; }
  $statsRow=$pdo->query('SELECT
    (SELECT COUNT(*) FROM items WHERE done = 0) AS active_total,
    (SELECT COUNT(*) FROM items WHERE done = 0 AND category_id IS NULL) AS active_uncategorized,
    (SELECT COUNT(*) FROM mindmaps) AS mindmap_total
  ')->fetch() ?: [];
  $stats=[
    'active_total'=>(int)($statsRow['active_total'] ?? 0),
    'active_uncategorized'=>(int)($statsRow['active_uncategorized'] ?? 0),
    'mindmap_total'=>(int)($statsRow['mindmap_total'] ?? 0),
  ];
  return [$cats,$map,$stats];
}

function ensure_done_category(): int {
  static $cachedId=null;
  if($cachedId!==null) return $cachedId;
  $pdo=db();
  $row=$pdo->query("SELECT id FROM categories WHERE name='已完成' LIMIT 1")->fetch();
  if($row){
    $cachedId=(int)$row['id'];
    return $cachedId;
  }
  $pdo->prepare('INSERT INTO categories(name, created_at) VALUES(?,?)')->execute(['已完成', now()]);
  $cachedId=(int)$pdo->lastInsertId();
  return $cachedId;
}

function ensure_other_category(): int {
  $pdo=db();
  $row=$pdo->query("SELECT id FROM categories WHERE name='其他' LIMIT 1")->fetch();
  if($row) return (int)$row['id'];
  $pdo->prepare('INSERT INTO categories(name, created_at) VALUES(?,?)')->execute(['其他', now()]);
  return (int)$pdo->lastInsertId();
}

// —— 项及步骤、附件 ——
function get_item(int $id): ?array {
  $pdo=db(); $st=$pdo->prepare('SELECT items.*, categories.name AS cat_name FROM items LEFT JOIN categories ON categories.id=items.category_id WHERE items.id=? LIMIT 1');
  $st->execute([$id]); return $st->fetch() ?: null;
}

function get_steps(int $item_id): array {
  $pdo=db(); $st=$pdo->prepare('SELECT * FROM steps WHERE item_id=? ORDER BY order_index ASC, id ASC');
  $st->execute([$item_id]); return $st->fetchAll();
}

function get_steps_by_time(int $item_id): array {
  $pdo=db(); $st=$pdo->prepare('SELECT * FROM steps WHERE item_id=? ORDER BY created_at ASC, id ASC');
  $st->execute([$item_id]); return $st->fetchAll();
}

function get_steps_grouped(array $item_ids): array {
  $ids=array_values(array_unique(array_map('intval',$item_ids)));
  if(!$ids){ return []; }
  $pdo=db();
  $placeholders=implode(',', array_fill(0,count($ids),'?'));
  $st=$pdo->prepare("SELECT * FROM steps WHERE item_id IN ($placeholders) ORDER BY item_id ASC, order_index ASC, id ASC");
  $st->execute($ids);
  $group=[];
  foreach($st->fetchAll() as $row){
    $iid=(int)$row['item_id'];
    if(!isset($group[$iid])) $group[$iid]=[];
    $group[$iid][]=$row;
  }
  return $group;
}

function get_steps_by_time_grouped(array $item_ids): array {
  $ids=array_values(array_unique(array_map('intval',$item_ids)));
  if(!$ids){ return []; }
  $pdo=db();
  $placeholders=implode(',', array_fill(0,count($ids),'?'));
  $st=$pdo->prepare("SELECT * FROM steps WHERE item_id IN ($placeholders) ORDER BY item_id ASC, created_at ASC, id ASC");
  $st->execute($ids);
  $group=[];
  foreach($st->fetchAll() as $row){
    $iid=(int)$row['item_id'];
    if(!isset($group[$iid])) $group[$iid]=[];
    $group[$iid][]=$row;
  }
  return $group;
}

function get_step_counts(array $item_ids): array {
  $ids=array_values(array_unique(array_map('intval',$item_ids)));
  if(!$ids){ return []; }
  $pdo=db();
  $placeholders=implode(',', array_fill(0,count($ids),'?'));
  $st=$pdo->prepare("SELECT item_id, COUNT(*) AS cnt FROM steps WHERE item_id IN ($placeholders) GROUP BY item_id");
  $st->execute($ids);
  $counts=[];
  foreach($st->fetchAll() as $row){ $counts[(int)$row['item_id']]=(int)$row['cnt']; }
  return $counts;
}

function get_attachment(int $id): ?array {
  $pdo=db(); $st=$pdo->prepare('SELECT * FROM attachments WHERE id=? LIMIT 1'); $st->execute([$id]); return $st->fetch() ?: null;
}

function attachments_for_item(int $item_id): array {
  $pdo=db(); $st=$pdo->prepare('SELECT * FROM attachments WHERE item_id=? ORDER BY id DESC'); $st->execute([$item_id]); return $st->fetchAll();
}

function attachments_for_items(array $item_ids): array {
  $ids=array_values(array_unique(array_map('intval',$item_ids)));
  if(!$ids){ return []; }
  $pdo=db();
  $placeholders=implode(',', array_fill(0,count($ids),'?'));
  $st=$pdo->prepare("SELECT * FROM attachments WHERE item_id IN ($placeholders) ORDER BY item_id ASC, id DESC");
  $st->execute($ids);
  $group=[];
  foreach($st->fetchAll() as $row){
    $iid=(int)$row['item_id'];
    if(!isset($group[$iid])) $group[$iid]=[];
    $group[$iid][]=$row;
  }
  return $group;
}

function get_mindmap_asset(int $id): ?array {
  $pdo=db();
  $st=$pdo->prepare('SELECT * FROM mindmap_assets WHERE id=? LIMIT 1');
  $st->execute([$id]);
  return $st->fetch() ?: null;
}

function mindmap_assets_for_map(int $map_id): array {
  $pdo=db();
  $st=$pdo->prepare('SELECT * FROM mindmap_assets WHERE mindmap_id=?');
  $st->execute([$map_id]);
  return $st->fetchAll();
}

function mindmap_assets_for_session(string $session_key): array {
  if($session_key==='') return [];
  $pdo=db();
  $st=$pdo->prepare('SELECT * FROM mindmap_assets WHERE session_key=?');
  $st->execute([$session_key]);
  return $st->fetchAll();
}

function delete_mindmap_asset(int $id): void {
  $asset=get_mindmap_asset($id);
  if(!$asset) return;
  $path=UPLOAD_DIR.DIRECTORY_SEPARATOR.$asset['stored_name'];
  if(is_file($path)) @unlink($path);
  $pdo=db();
  $pdo->prepare('DELETE FROM mindmap_assets WHERE id=?')->execute([$id]);
}

function create_mindmap_asset(?int $map_id, ?string $node_uid, string $orig_name, string $stored_name, string $mime, int $size, string $session_key): array {
  $pdo=db();
  $pdo->prepare('INSERT INTO mindmap_assets(mindmap_id,node_uid,session_key,orig_name,stored_name,mime,size,created_at) VALUES(?,?,?,?,?,?,?,?)')
    ->execute([
      $map_id>0?$map_id:null,
      $node_uid,
      $map_id>0?null:$session_key,
      $orig_name,
      $stored_name,
      $mime,
      $size,
      now()
    ]);
  $id=(int)$pdo->lastInsertId();
  return get_mindmap_asset($id) ?? ['id'=>$id,'mindmap_id'=>$map_id,'node_uid'=>$node_uid,'session_key'=>$session_key,'orig_name'=>$orig_name,'stored_name'=>$stored_name,'mime'=>$mime,'size'=>$size,'created_at'=>now()];
}

function prune_mindmap_assets(int $map_id, array $keep_ids): void {
  $pdo=db();
  $ids=array_values(array_unique(array_map('intval',$keep_ids)));
  $placeholders=$ids?implode(',',array_fill(0,count($ids),'?')):'';
  $params=$ids;
  array_unshift($params,$map_id);
  $sql=$ids?
    "SELECT id FROM mindmap_assets WHERE mindmap_id=? AND id NOT IN ($placeholders)" :
    "SELECT id FROM mindmap_assets WHERE mindmap_id=?";
  $st=$pdo->prepare($sql);
  $st->execute($params);
  $rows=$st->fetchAll();
  foreach($rows as $row){ delete_mindmap_asset((int)$row['id']); }
}

// —— 思维导图 ——
function get_mindmaps(): array {
  $pdo=db();
  return $pdo->query('SELECT * FROM mindmaps ORDER BY updated_at DESC, id DESC')->fetchAll();
}

function get_mindmap(int $id): ?array {
  $pdo=db();
  $st=$pdo->prepare('SELECT * FROM mindmaps WHERE id=? LIMIT 1');
  $st->execute([$id]);
  return $st->fetch() ?: null;
}

function create_mindmap(string $title, string $content): array {
  $pdo=db();
  $nowTs=now();
  $pdo->prepare('INSERT INTO mindmaps(title, content, created_at, updated_at) VALUES(?,?,?,?)')
      ->execute([$title,$content,$nowTs,$nowTs]);
  $id=(int)$pdo->lastInsertId();
  return ['id'=>$id,'updated_at'=>$nowTs];
}

function update_mindmap(int $id, string $title, string $content): array {
  $pdo=db();
  $nowTs=now();
  $pdo->prepare('UPDATE mindmaps SET title=?, content=?, updated_at=? WHERE id=?')
      ->execute([$title,$content,$nowTs,$id]);
  return ['id'=>$id,'updated_at'=>$nowTs];
}

function delete_mindmap(int $id): void {
  $pdo=db();
  $assets=mindmap_assets_for_map($id);
  foreach($assets as $asset){ delete_mindmap_asset((int)$asset['id']); }
  $pdo->prepare('DELETE FROM mindmaps WHERE id=?')->execute([$id]);
}

function mindmap_outline_preview(string $json, int $limit = 8): string {
  $data=json_decode($json,true);
  if(!is_array($data) || !isset($data['data'])) return '';
  $root=$data['data'];
  $lines=[];
  $stack=[[$root,0]];
  while($stack && count($lines)<$limit){
    [$node,$depth]=array_pop($stack);
    if(!is_array($node) || !isset($node['topic'])) continue;
    $indent=str_repeat('  ',$depth);
    $lines[]=$indent.'- '.$node['topic'];
    if(!empty($node['children']) && is_array($node['children'])){
      for($i=count($node['children'])-1; $i>=0; $i--){
        $child=$node['children'][$i];
        $stack[]=[$child,$depth+1];
      }
    }
  }
  return implode("\n", $lines);
}

function create_mindmap_asset_from_dataurl(string $data_url, string $name, ?int $map_id, string $node_uid, string $session_key): ?array {
  if(!preg_match('#^data:(.*?);base64,(.*)$#',$data_url,$m)) return null;
  $mime=strtolower(trim($m[1] !== '' ? $m[1] : 'application/octet-stream'));
  $binary=base64_decode(strtr($m[2],' ','+'), true);
  if($binary===false) return null;
  $size=strlen($binary);
  if($size>MAX_UPLOAD_BYTES) throw new RuntimeException('附件超过 15MB 上限，无法保存。');
  $ext=ALLOWED_UPLOAD_MIME_MAP[$mime] ?? null;
  if(!$ext) return null;
  if(!is_dir(UPLOAD_DIR)) @mkdir(UPLOAD_DIR,0775,true);
  $stored=bin2hex(random_bytes(8)).'.'.$ext;
  $dest=UPLOAD_DIR.DIRECTORY_SEPARATOR.$stored;
  if(file_put_contents($dest,$binary)===false) return null;
  $safeName=$name !== '' ? $name : ('附件.'.$ext);
  return create_mindmap_asset($map_id,$node_uid,$safeName,$stored,$mime,$size,$session_key);
}

function sanitize_mindmap_payload(array &$payload, array &$asset_refs, ?int $map_id, string $session_key): void {
  $asset_refs=[];
  if(!isset($payload['data']) || !is_array($payload['data'])) return;
  $payload['format']='node_tree';
  $sanitize=function (&$node, int $depth = 0) use (&$sanitize,&$asset_refs,$map_id,$session_key){
    if(!is_array($node)) return;
    $node['direction']=$depth===0?'center':'right';
    if(empty($node['id']) || !is_string($node['id'])){
      $node['id']='node-'.bin2hex(random_bytes(4));
    }
    if(isset($node['data']) && is_array($node['data'])){
      $processAttachment=function($raw) use (&$asset_refs,$map_id,$session_key,$node){
        if(!is_array($raw)) return null;
        if(isset($raw['content']) && is_string($raw['content']) && str_starts_with($raw['content'],'data:')){
          $asset=create_mindmap_asset_from_dataurl($raw['content'],$raw['name'] ?? ($node['topic'] ?? '附件'),$map_id,$node['id'],$session_key);
          if(!$asset) return null;
          $asset_id=(int)$asset['id'];
          $asset_refs[$asset_id]=$node['id'];
          return [
            'assetId'=>$asset_id,
            'name'=>$asset['orig_name'],
            'size'=>(int)$asset['size'],
            'mime'=>$asset['mime'],
            'url'=>'?mindmap_asset='.$asset_id,
            'createdAt'=>(int)($asset['created_at'] ?? now()),
          ];
        }
        $asset_id=(int)($raw['assetId'] ?? ($raw['id'] ?? 0));
        if($asset_id<=0) return null;
        $created=(int)($raw['createdAt'] ?? ($raw['created_at'] ?? ($raw['uploadedAt'] ?? 0)));
        $normalized=[
          'assetId'=>$asset_id,
          'name'=>$raw['name'] ?? ($node['topic'] ?? '附件'),
          'size'=>(int)($raw['size'] ?? 0),
          'mime'=>$raw['mime'] ?? ($raw['type'] ?? 'application/octet-stream'),
          'url'=>$raw['url'] ?? ('?mindmap_asset='.$asset_id),
        ];
        if($created>0){ $normalized['createdAt']=$created; }
        $asset_refs[$asset_id]=$node['id'];
        return $normalized;
      };
      $collected=[];
      if(isset($node['data']['attachments']) && is_array($node['data']['attachments'])){
        foreach($node['data']['attachments'] as $att){
          $normalized=$processAttachment($att);
          if($normalized){
            $collected[$normalized['assetId']]=$normalized;
          }
        }
      }
      if(isset($node['data']['attachment']) && is_array($node['data']['attachment'])){
        $normalized=$processAttachment($node['data']['attachment']);
        if($normalized){
          $collected[$normalized['assetId']]=$normalized;
        }
      }
      if($collected){
        $attachments=array_values($collected);
        $node['data']['attachments']=$attachments;
        $node['data']['attachment']=$attachments[0];
      } else {
        unset($node['data']['attachments'],$node['data']['attachment']);
      }
      if(isset($node['data']['attachments'])){
        foreach($node['data']['attachments'] as &$att){
          unset($att['content'],$att['id'],$att['type']);
          if(!isset($att['mime'])){
            $att['mime']='application/octet-stream';
          }
        }
        unset($att);
      }
      if(isset($node['data']['url']) && !is_string($node['data']['url'])){
        unset($node['data']['url']);
      }
      if(isset($node['data']) && is_array($node['data']) && count(array_filter($node['data'],fn($v)=>$v!==null && $v!=='' && $v!==[] && $v!==false))===0){
        unset($node['data']);
      }
    }
    if(isset($node['children']) && is_array($node['children'])){
      $normalized=[];
      foreach($node['children'] as $child){
        if(!is_array($child)) continue;
        $sanitize($child,$depth+1);
        $normalized[]=$child;
      }
      $node['children']=$normalized;
    } else {
      $node['children']=[];
    }
  };
  $sanitize($payload['data'],0);

  $nodeIds=[];
  $collect=function($node) use (&$collect,&$nodeIds){
    if(!is_array($node)) return;
    if(!empty($node['id']) && is_string($node['id'])){
      $nodeIds[$node['id']]=true;
    }
    if(isset($node['children']) && is_array($node['children'])){
      foreach($node['children'] as $child){
        $collect($child);
      }
    }
  };
  $collect($payload['data']);

  $normalizedRelations=[];
  $usedRelationIds=[];
  $sourceRelations=is_array($payload['relations'] ?? null) ? $payload['relations'] : [];
  foreach($sourceRelations as $relation){
    if(!is_array($relation)) continue;
    $from=isset($relation['from']) && is_string($relation['from']) ? trim($relation['from']) : '';
    $to=isset($relation['to']) && is_string($relation['to']) ? trim($relation['to']) : '';
    if($from==='' || $to==='' || $from===$to) continue;
    if(!isset($nodeIds[$from]) || !isset($nodeIds[$to])) continue;
    $relId=isset($relation['id']) && is_string($relation['id']) && $relation['id']!=='' ? $relation['id'] : null;
    if($relId===null){
      $relId='rel-'.bin2hex(random_bytes(5));
    }
    if(isset($usedRelationIds[$relId])){
      $relId.='-'.bin2hex(random_bytes(2));
    }
    $usedRelationIds[$relId]=true;
    $entry=['id'=>$relId,'from'=>$from,'to'=>$to];
    if(isset($relation['label']) && is_string($relation['label'])){
      $label=trim($relation['label']);
      if($label!==''){ $entry['label']=$label; }
    }
    if(isset($relation['bidirectional']) && boolish($relation['bidirectional'])){
      $entry['bidirectional']=true;
    }
    $normalizedRelations[]=$entry;
  }
  $payload['relations']=$normalizedRelations;
}

function sync_mindmap_assets(int $map_id, array $asset_refs, string $session_key): void {
  $pdo=db();
  $keep_ids=[];
  foreach($asset_refs as $asset_id=>$node_uid){
    $aid=(int)$asset_id;
    if($aid<=0) continue;
    $keep_ids[]=$aid;
    $pdo->prepare('UPDATE mindmap_assets SET mindmap_id=?, node_uid=?, session_key=NULL WHERE id=?')
      ->execute([$map_id,$node_uid,$aid]);
  }
  prune_mindmap_assets($map_id,$keep_ids);
  if($session_key!==''){
    $ids=array_values(array_unique($keep_ids));
    $placeholders=$ids?implode(',',array_fill(0,count($ids),'?')):'';
    $params=$ids;
    array_unshift($params,$session_key);
    $sql=$ids?
      "SELECT id FROM mindmap_assets WHERE session_key=? AND id NOT IN ($placeholders)" :
      "SELECT id FROM mindmap_assets WHERE session_key=?";
    $st=$pdo->prepare($sql);
    $st->execute($params);
    foreach($st->fetchAll() as $row){ delete_mindmap_asset((int)$row['id']); }
  }
}

// —— JSON 帮助：返回分类 ——
function json_cats(): void {
  [$cats,$counts,$stats] = get_categories();
  $total = (int)($stats['active_total'] ?? 0);
  $uncat = (int)($stats['active_uncategorized'] ?? 0);
  $mindmapTotal = (int)($stats['mindmap_total'] ?? 0);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode([
    'ok'=>1,
    'cats'=>$cats,
    'counts'=>$counts,
    'total'=>$total,
    'uncat'=>$uncat,
    'mindmap_total'=>$mindmapTotal,
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

// —— 下载附件 ——
if (isset($_GET['download']) && ctype_digit((string)$_GET['download'])) {
  $att=get_attachment((int)$_GET['download']); if(!$att){ http_response_code(404); echo 'Not Found'; exit; }
  $path=UPLOAD_DIR.DIRECTORY_SEPARATOR.$att['stored_name']; if(!is_file($path)){ http_response_code(404); echo 'File Missing'; exit; }
  $mime=strtolower((string)$att['mime']);
  $filename=$att['orig_name'];
  $inlineTypes=['application/pdf','application/zip','application/x-zip-compressed','application/x-rar-compressed','application/vnd.rar'];
  $isInline=str_starts_with($mime,'image/') || str_starts_with($mime,'video/') || str_starts_with($mime,'audio/') || in_array($mime,$inlineTypes,true);
  $contentType=$att['mime']!=='' ? $att['mime'] : 'application/octet-stream';
  header('Content-Length: '.$att['size']);
  header('X-Content-Type-Options: nosniff');
  header('Content-Type: '.$contentType);
  header('Content-Disposition: '.($isInline?'inline':'attachment').'; filename="'.rawurlencode($filename).'"');
  readfile($path); exit;
}

if (isset($_GET['mindmap_asset']) && ctype_digit((string)$_GET['mindmap_asset'])) {
  $asset=get_mindmap_asset((int)$_GET['mindmap_asset']); if(!$asset){ http_response_code(404); echo 'Not Found'; exit; }
  $path=UPLOAD_DIR.DIRECTORY_SEPARATOR.$asset['stored_name']; if(!is_file($path)){ http_response_code(404); echo 'File Missing'; exit; }
  $mime=$asset['mime'];
  $filename=$asset['orig_name'];
  $inline=str_starts_with($mime,'image/') || str_starts_with($mime,'video/') || str_starts_with($mime,'audio/') || $mime==='application/pdf';
  header('Content-Length: '.$asset['size']); header('Content-Type: '.$mime); header('X-Content-Type-Options: nosniff');
  if($inline){ header('Content-Disposition: inline; filename="'.rawurlencode($filename).'"'); }
  else { header('Content-Disposition: attachment; filename="'.rawurlencode($filename).'"'); }
  readfile($path); exit;
}

// —— 导出数据（JSON/CSV） ——
if (isset($_GET['export'])) {
  $pdo=db(); $cat=$_GET['cat']??'all'; $q=trim((string)($_GET['q']??'')); $params=[]; $where=[];
  if($cat!=='all' && ctype_digit((string)$cat)){ $where[]='category_id = :cat'; $params[':cat']=(int)$cat; }
  if($q!==''){ $where[]='(title LIKE :q OR description LIKE :q)'; $params[':q']='%'.$q.'%'; }
  $sql='SELECT items.*, categories.name AS cat_name FROM items LEFT JOIN categories ON categories.id=items.category_id';
  if($where) $sql.=' WHERE '.implode(' AND ',$where);
  $sql.=' ORDER BY order_index ASC, updated_at DESC, id DESC';
  $st=$pdo->prepare($sql); $st->execute($params); $rows=$st->fetchAll();
  $type=strtolower((string)$_GET['export']);
  if($type==='json'){
    $itemIds=array_column($rows,'id');
    $stepsGrouped=$itemIds?get_steps_grouped($itemIds):[];
    foreach($rows as &$r){
      $id=(int)$r['id'];
      $r['steps']=$stepsGrouped[$id] ?? [];
    }
    unset($r);
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="memo_export_'.date('Ymd_His').'.json"');
    echo json_encode($rows, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT); exit;
  }
  if($type==='csv'){
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="memo_export_'.date('Ymd_His').'.csv"');
    $out=fopen('php://output','w'); fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out,['id','title','description(MD)','done','category','created_at','updated_at','steps(count)']);
    $itemIds=array_column($rows,'id');
    $stepCounts=$itemIds?get_step_counts($itemIds):[];
    foreach($rows as $r){ $cnt=$stepCounts[(int)$r['id']] ?? 0;
      fputcsv($out,[$r['id'],$r['title'],$r['description'],$r['done']?'1':'0',$r['cat_name']??'',dt((int)$r['created_at']),dt((int)$r['updated_at']),$cnt]);
    }
    fclose($out); exit;
  }
  http_response_code(400); echo 'Unsupported export type'; exit;
}

// —— 处理 POST 动作 ——
if (is_post()) {
  $pdo=db(); $action=$_POST['action'] ?? '';
  try{
    switch($action){
      case 'import_items': {
        if(empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK){
          throw new RuntimeException('请选择要导入的 JSON 文件');
        }
        $raw=file_get_contents($_FILES['file']['tmp_name']);
        if($raw===false) throw new RuntimeException('无法读取导入文件');
        $decoded=json_decode($raw,true);
        if($decoded===null && json_last_error() !== JSON_ERROR_NONE){
          throw new RuntimeException('JSON 解析失败：'.json_last_error_msg());
        }
        if(!is_array($decoded)){
          throw new RuntimeException('导入数据格式不正确');
        }
        $itemsPayload=[];
        if(isset($decoded['items']) && is_array($decoded['items'])){
          $itemsPayload=$decoded['items'];
        } elseif(array_is_list($decoded)){
          $itemsPayload=$decoded;
        } else {
          throw new RuntimeException('未找到可导入的条目数据');
        }
        if(!$itemsPayload){
          throw new RuntimeException('导入文件没有任何备忘录条目');
        }
        $pdo->beginTransaction();
        $createdCategoryNames=[];
        $existingCats=$pdo->query('SELECT id,name FROM categories')->fetchAll();
        $catMap=[];
        foreach($existingCats as $row){
          $catMap[$row['name']]=(int)$row['id'];
        }
        $maxOrder=(int)$pdo->query('SELECT COALESCE(MAX(order_index), -1) FROM items')->fetchColumn();
        $insertItem=$pdo->prepare('INSERT INTO items(title,description,done,category_id,order_index,created_at,updated_at) VALUES(?,?,?,?,?,?,?)');
        $insertStep=$pdo->prepare('INSERT INTO steps(item_id,title,notes,done,order_index,created_at,updated_at) VALUES(?,?,?,?,?,?,?)');
        $insertCat=$pdo->prepare('INSERT OR IGNORE INTO categories(name, created_at) VALUES(?,?)');
        $fetchCatId=$pdo->prepare('SELECT id FROM categories WHERE name=? LIMIT 1');
        $imported=0; $skipped=0;
        try {
          foreach($itemsPayload as $item){
            if(!is_array($item)){ $skipped++; continue; }
            $title=trim((string)($item['title'] ?? ''));
            if($title===''){ $skipped++; continue; }
            $desc=(string)($item['description'] ?? ($item['desc'] ?? ''));
            $done=boolish($item['done'] ?? false) ? 1 : 0;
            $catId=null;
            $catName='';
            foreach(['cat_name','category_name','category'] as $key){
              if(isset($item[$key]) && is_string($item[$key])){
                $candidate=trim($item[$key]);
                if($candidate!==''){ $catName=$candidate; break; }
              }
            }
            if($catName!==''){
              if(isset($catMap[$catName])){
                $catId=$catMap[$catName];
              } else {
                $createdAt=now();
                $insertCat->execute([$catName, $createdAt]);
                $fetchCatId->execute([$catName]);
                $newId=(int)($fetchCatId->fetchColumn() ?: 0);
                if($newId>0){
                  $catMap[$catName]=$newId;
                  $createdCategoryNames[$catName]=true;
                  $catId=$newId;
                }
              }
            }
            $nowTs=now();
            $createdTs=normalize_timestamp($item['created_at'] ?? null, $nowTs);
            $updatedTs=normalize_timestamp($item['updated_at'] ?? null, $createdTs);
            $orderIndex=++$maxOrder;
            $insertItem->execute([$title,$desc,$done,$catId,$orderIndex,$createdTs,$updatedTs]);
            $itemId=(int)$pdo->lastInsertId();
            if($itemId<=0){ $skipped++; continue; }
            $stepsData=is_array($item['steps'] ?? null) ? $item['steps'] : [];
            $stepIndex=0;
            foreach($stepsData as $step){
              if(!is_array($step)) continue;
              $stepTitle=trim((string)($step['title'] ?? ''));
              if($stepTitle==='') continue;
              $stepNotes=(string)($step['notes'] ?? ($step['description'] ?? ''));
              $stepDone=boolish($step['done'] ?? false) ? 1 : 0;
              $stepCreated=normalize_timestamp($step['created_at'] ?? null, $createdTs);
              $stepUpdated=normalize_timestamp($step['updated_at'] ?? null, $stepCreated);
              $stepOrder=isset($step['order_index']) && is_numeric($step['order_index']) ? (int)$step['order_index'] : $stepIndex;
              $insertStep->execute([$itemId,$stepTitle,$stepNotes,$stepDone,$stepOrder,$stepCreated,$stepUpdated]);
              $stepIndex++;
            }
            $imported++;
          }
          $pdo->commit();
        } catch(Throwable $inner){
          $pdo->rollBack();
          throw $inner;
        }
        $createdList=array_keys($createdCategoryNames);
        $result=['ok'=>1,'imported'=>$imported,'skipped'=>$skipped,'created_categories'=>$createdList];
        if(is_ajax()){
          header('Content-Type: application/json');
          echo json_encode($result, JSON_UNESCAPED_UNICODE);
          exit;
        }
        $_SESSION['flash']='成功导入 '.$imported.' 条备忘录';
        if($skipped){ $_SESSION['flash'].='，跳过 '.$skipped.' 条'; }
        if($createdList){ $_SESSION['flash'].='；新增分类：'.implode('、',$createdList); }
        break;
      }
      case 'create_draft': {
        $title='未命名'; $nowt=now();
        $pdo->prepare('INSERT INTO items(title,description,done,category_id,order_index,created_at,updated_at) VALUES(?,?,?,?,0,?,?)')
            ->execute([$title,'',0,null,$nowt,$nowt]);
        $id=(int)$pdo->lastInsertId();
        header('Content-Type: application/json'); echo json_encode(['ok'=>1,'id'=>$id]); exit;
      }
      case 'add_item': {
        $title=trim((string)($_POST['title']??'')); if($title==='') throw new RuntimeException('标题必填');
        $desc=(string)($_POST['description']??''); $catId=(isset($_POST['category_id'])&&ctype_digit((string)$_POST['category_id']))?(int)$_POST['category_id']:null;
        $nowt=now(); $pdo->prepare('INSERT INTO items(title,description,done,category_id,order_index,created_at,updated_at) VALUES(?,?,?,?,0,?,?)')->execute([$title,$desc,0,$catId,$nowt,$nowt]);
        $newId=(int)$pdo->lastInsertId(); if(is_ajax()){ header('Content-Type: application/json'); echo json_encode(['ok'=>1,'id'=>$newId]); exit; }
        redirect('?view=item&id='.$newId); break;
      }
      case 'toggle_done': {
        $id=(int)$_POST['id'];
        $done=boolish($_POST['done'] ?? 0) ? 1 : 0;
        $nowt=now();
        $pdo->beginTransaction();
        try {
          $st=$pdo->prepare('SELECT items.done, items.category_id, items.previous_category_id, cat.name AS category_name, prev.name AS previous_category_name FROM items LEFT JOIN categories AS cat ON cat.id=items.category_id LEFT JOIN categories AS prev ON prev.id=items.previous_category_id WHERE items.id=? LIMIT 1');
          $st->execute([$id]);
          $row=$st->fetch();
          if(!$row){
            throw new RuntimeException('指定的备忘录不存在');
          }
          $newCategoryId=null;
          $categoryLabel='未分类';
          if($done){
            $doneCatId=ensure_done_category();
            $prevCatId=(int)($row['done'] ? ($row['previous_category_id'] ?? $row['category_id']) : ($row['category_id'] ?? 0));
            $prevCatId=$prevCatId>0 ? $prevCatId : null;
            $pdo->prepare('UPDATE items SET previous_category_id=?, category_id=?, done=1, updated_at=? WHERE id=?')->execute([$prevCatId,$doneCatId,$nowt,$id]);
            $newCategoryId=$doneCatId;
            $categoryLabel='已完成';
          } else {
            $restoreCat=$row['previous_category_id']!==null ? (int)$row['previous_category_id'] : null;
            $restoreName=$row['previous_category_name'] ?? null;
            if($restoreCat!==null && !$restoreName){
              $nameStmt=$pdo->prepare('SELECT name FROM categories WHERE id=? LIMIT 1');
              $nameStmt->execute([$restoreCat]);
              $restoreName=$nameStmt->fetchColumn() ?: null;
            }
            $pdo->prepare('UPDATE items SET previous_category_id=NULL, category_id=?, done=0, updated_at=? WHERE id=?')->execute([$restoreCat,$nowt,$id]);
            $newCategoryId=$restoreCat;
            if($restoreName){ $categoryLabel=$restoreName; }
          }
          $pdo->commit();
        } catch(Throwable $err){
          $pdo->rollBack();
          throw $err;
        }
        if(is_ajax()){
          header('Content-Type: application/json');
          echo json_encode([
            'ok'=>1,
            'id'=>$id,
            'updated_at'=>$nowt,
            'category_id'=>$newCategoryId,
            'category_label'=>$categoryLabel,
            'done'=>$done,
          ]);
          exit;
        }
        break;
      }
      case 'edit_item': {
        $id=(int)$_POST['id']; $title=trim((string)($_POST['title']??'')); if($title==='') throw new RuntimeException('标题必填');
        $desc=(string)($_POST['description']??''); $catId=(isset($_POST['category_id'])&&ctype_digit((string)$_POST['category_id']))?(int)$_POST['category_id']:null;
        $pdo->prepare('UPDATE items SET title=?, description=?, category_id=?, updated_at=? WHERE id=?')->execute([$title,$desc,$catId,now(),$id]);
        if(is_ajax()){ header('Content-Type: application/json'); echo json_encode(['ok'=>1]); exit; } break;
      }
      case 'delete_item': {
        $id=(int)($_POST['id'] ?? 0);
        if($id<=0){ throw new RuntimeException('未指定要删除的备忘录'); }
        $item=$pdo->prepare('SELECT * FROM items WHERE id=? LIMIT 1');
        $item->execute([$id]);
        $row=$item->fetch();
        if(!$row){ throw new RuntimeException('指定的备忘录不存在'); }
        $steps=get_steps($id);
        $attachments=attachments_for_item($id);
        $pdo->beginTransaction();
        try {
          $pdo->prepare('DELETE FROM items WHERE id=?')->execute([$id]);
          $pdo->commit();
        } catch(Throwable $err){
          $pdo->rollBack();
          throw $err;
        }
        $token=bin2hex(random_bytes(10));
        $expires=now()+180;
        if(!isset($_SESSION['undo_deleted']) || !is_array($_SESSION['undo_deleted'])){
          $_SESSION['undo_deleted']=[];
        }
        foreach($_SESSION['undo_deleted'] as $key=>$payload){
          if(!is_array($payload) || ($payload['expires'] ?? 0) < now()){
            unset($_SESSION['undo_deleted'][$key]);
          }
        }
        $_SESSION['undo_deleted'][$token]=[
          'expires'=>$expires,
          'item'=>$row,
          'steps'=>$steps,
          'attachments'=>$attachments,
        ];
        if(is_ajax()){
          header('Content-Type: application/json');
          echo json_encode([
            'ok'=>1,
            'undo_token'=>$token,
            'title'=>$row['title'],
            'expires'=>$expires,
          ], JSON_UNESCAPED_UNICODE);
          exit;
        }
        $_SESSION['flash']='已删除 “'.$row['title'].'”';
        break;
      }
      case 'undo_delete_item': {
        $token=(string)($_POST['token'] ?? '');
        if($token===''){ throw new RuntimeException('未提供撤销凭据'); }
        $stack=$_SESSION['undo_deleted'] ?? [];
        if(!isset($stack[$token]) || !is_array($stack[$token])){
          throw new RuntimeException('没有可撤销的备忘录');
        }
        $payload=$stack[$token];
        if(($payload['expires'] ?? 0) < now()){
          unset($_SESSION['undo_deleted'][$token]);
          throw new RuntimeException('撤销请求已过期');
        }
        unset($_SESSION['undo_deleted'][$token]);
        $item=$payload['item'] ?? null;
        if(!$item){ throw new RuntimeException('无效的撤销数据'); }
        $stepsData=is_array($payload['steps'] ?? null) ? $payload['steps'] : [];
        $attachmentsData=is_array($payload['attachments'] ?? null) ? $payload['attachments'] : [];
        $pdo->beginTransaction();
        try {
          $pdo->prepare('INSERT INTO items(id,title,description,done,category_id,order_index,created_at,updated_at,previous_category_id) VALUES(?,?,?,?,?,?,?,?,?)')
            ->execute([
              (int)$item['id'],
              $item['title'],
              $item['description'],
              (int)$item['done'],
              $item['category_id'] !== null ? (int)$item['category_id'] : null,
              (int)$item['order_index'],
              (int)$item['created_at'],
              (int)$item['updated_at'],
              $item['previous_category_id'] !== null ? (int)$item['previous_category_id'] : null,
            ]);
          if($stepsData){
            $insertStep=$pdo->prepare('INSERT INTO steps(id,item_id,title,notes,done,order_index,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)');
            foreach($stepsData as $step){
              $insertStep->execute([
                (int)$step['id'],
                (int)$step['item_id'],
                $step['title'],
                $step['notes'],
                (int)$step['done'],
                (int)$step['order_index'],
                (int)$step['created_at'],
                (int)$step['updated_at'],
              ]);
            }
          }
          if($attachmentsData){
            $insertAttachment=$pdo->prepare('INSERT INTO attachments(id,item_id,step_id,orig_name,stored_name,mime,size,created_at) VALUES(?,?,?,?,?,?,?,?)');
            foreach($attachmentsData as $att){
              $insertAttachment->execute([
                (int)$att['id'],
                $att['item_id'] !== null ? (int)$att['item_id'] : null,
                $att['step_id'] !== null ? (int)$att['step_id'] : null,
                $att['orig_name'],
                $att['stored_name'],
                $att['mime'],
                (int)$att['size'],
                (int)$att['created_at'],
              ]);
            }
          }
          $pdo->commit();
        } catch(Throwable $err){
          $pdo->rollBack();
          throw $err;
        }
        if(is_ajax()){
          header('Content-Type: application/json');
          echo json_encode(['ok'=>1], JSON_UNESCAPED_UNICODE);
          exit;
        }
        $_SESSION['flash']='已恢复 “'.$item['title'].'”';
        break;
      }
      case 'add_category': {
        $name=trim((string)($_POST['name']??'')); if($name==='') throw new RuntimeException('分类名必填');
        $pdo->prepare('INSERT OR IGNORE INTO categories(name, created_at) VALUES(?,?)')->execute([$name,now()]);
        if(is_ajax()) json_cats(); break;
      }
      case 'edit_category': {
        $id=(int)$_POST['id']; $name=trim((string)($_POST['name']??'')); if($name==='') throw new RuntimeException('分类名必填');
        $pdo->prepare('UPDATE categories SET name=? WHERE id=?')->execute([$name,$id]);
        if(is_ajax()) json_cats(); break;
      }
      case 'delete_category': {
        $id=(int)$_POST['id']; $other=ensure_other_category();
        $fallback=$other===$id ? null : $other;
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE items SET category_id=? WHERE category_id=?')->execute([$fallback,$id]);
        $pdo->prepare('UPDATE items SET previous_category_id=? WHERE previous_category_id=?')->execute([$fallback,$id]);
        $pdo->prepare('DELETE FROM categories WHERE id=?')->execute([$id]);
        $pdo->commit();
        if($fallback===null){ ensure_other_category(); }
        if(is_ajax()) json_cats();
        break;
      }
      case 'add_step': {
        $item_id=(int)$_POST['item_id']; $title=trim((string)($_POST['title']??'')); if($title==='') throw new RuntimeException('步骤标题必填');
        $nowt=now(); $pdo->prepare('INSERT INTO steps(item_id,title,notes,done,order_index,created_at,updated_at) VALUES(?,?,?,?,0,?,?)')->execute([$item_id,$title,'',0,$nowt,$nowt]);
        $sid=(int)$pdo->lastInsertId(); $row=$pdo->query('SELECT * FROM steps WHERE id='.$sid)->fetch();
        $pdo->prepare('UPDATE items SET updated_at=? WHERE id=?')->execute([now(),$item_id]);
        if(is_ajax()){ header('Content-Type: application/json'); echo json_encode(['ok'=>1,'step'=>$row]); exit; }
        break;
      }
      case 'toggle_step': {
        $id=(int)$_POST['id']; $done=(int)$_POST['done']; $nowt=now();
        $pdo->prepare('UPDATE steps SET done=?, updated_at=? WHERE id=?')->execute([$done?1:0,$nowt,$id]);
        $row=$pdo->query('SELECT item_id FROM steps WHERE id='.(int)$id)->fetch();
        $itemId = $row ? (int)$row['item_id'] : null;
        if($itemId){ $pdo->prepare('UPDATE items SET updated_at=? WHERE id=?')->execute([$nowt,$itemId]); }
        if(is_ajax()){ header('Content-Type: application/json'); echo json_encode(['ok'=>1,'item_id'=>$itemId,'updated_at'=>$nowt]); exit; }
        break;
      }
      case 'edit_step': {
        $id=(int)$_POST['id']; $title=trim((string)($_POST['title']??'')); if($title==='') throw new RuntimeException('步骤标题必填');
        $pdo->prepare('UPDATE steps SET title=?, updated_at=? WHERE id=?')->execute([$title,now(),$id]);
        $row=$pdo->query('SELECT item_id FROM steps WHERE id='.(int)$id)->fetch(); if($row){ $pdo->prepare('UPDATE items SET updated_at=? WHERE id=?')->execute([now(),(int)$row['item_id']]); }
        if(is_ajax()){ header('Content-Type: application/json'); echo json_encode(['ok'=>1]); exit; } break;
      }
      case 'edit_step_notes': {
        $id=(int)$_POST['id']; $notes=(string)($_POST['notes']??''); $pdo->prepare('UPDATE steps SET notes=?, updated_at=? WHERE id=?')->execute([$notes,now(),$id]);
        $row=$pdo->query('SELECT item_id FROM steps WHERE id='.(int)$id)->fetch(); if($row){ $pdo->prepare('UPDATE items SET updated_at=? WHERE id=?')->execute([now(),(int)$row['item_id']]); }
        if(is_ajax()){ header('Content-Type: application/json'); echo json_encode(['ok'=>1]); exit; } break;
      }
      case 'delete_step': {
        $id=(int)$_POST['id']; $row=$pdo->query('SELECT item_id FROM steps WHERE id='.(int)$id)->fetch();
        $pdo->prepare('DELETE FROM steps WHERE id=?')->execute([$id]);
        if($row){ $pdo->prepare('UPDATE items SET updated_at=? WHERE id=?')->execute([now(),(int)$row['item_id']]); }
        if(is_ajax()){
          header('Content-Type: application/json');
          echo json_encode(['ok'=>1,'item_id'=>$row ? (int)$row['item_id'] : null]);
          exit;
        }
        break;
      }
      case 'reorder_items': {
        $order=trim((string)$_POST['order']??''); if($order!==''){ $ids=array_values(array_filter(array_map('intval',explode(',',$order))));
          $pdo->beginTransaction(); $i=0; $st=$pdo->prepare('UPDATE items SET order_index=?, updated_at=? WHERE id=?'); foreach($ids as $id){ $st->execute([$i++,now(),$id]); } $pdo->commit();
        } if(is_ajax()){ http_response_code(204); exit; } break;
      }
      case 'reorder_steps': {
        $order=trim((string)$_POST['order']??''); $item_id=(int)($_POST['item_id']??0);
        if($order!=='' && $item_id){ $ids=array_values(array_filter(array_map('intval',explode(',',$order))));
          $pdo->beginTransaction(); $i=0; $st=$pdo->prepare('UPDATE steps SET order_index=?, updated_at=?, item_id=item_id WHERE id=? AND item_id=?'); foreach($ids as $id){ $st->execute([$i++,now(),$id,$item_id]); } $pdo->commit();
          $pdo->prepare('UPDATE items SET updated_at=? WHERE id=?')->execute([now(),$item_id]);
        } if(is_ajax()){ http_response_code(204); exit; } break;
      }
      case 'upload_attachment': {
        if(empty($_FILES['file']) || ($_FILES['file']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) throw new RuntimeException('上传失败');
        $kind=$_POST['target'] ?? ''; $targetId=(int)($_POST['target_id'] ?? 0); if(!in_array($kind,['item','step'],true)||$targetId<=0) throw new RuntimeException('目标无效');
        $f=$_FILES['file']; if($f['size']>MAX_UPLOAD_BYTES) throw new RuntimeException('文件过大，最大 15MB');
        $finfo=new finfo(FILEINFO_MIME_TYPE); $mime=$finfo->file($f['tmp_name']) ?: 'application/octet-stream';
        $ext = ALLOWED_UPLOAD_MIME_MAP[$mime] ?? null;
        if(!$ext) throw new RuntimeException('仅允许图片、音视频、PDF、Word/Excel、ZIP/RAR 或文本文件');
        $stored=bin2hex(random_bytes(8)).'.'.$ext; $dest=UPLOAD_DIR.DIRECTORY_SEPARATOR.$stored; if(!move_uploaded_file($f['tmp_name'],$dest)) throw new RuntimeException('保存失败');
        $orig=$f['name']; $itemIdForTouch=null;
        if($kind==='item'){ $itemIdForTouch=$targetId; db()->prepare('INSERT INTO attachments(item_id,step_id,orig_name,stored_name,mime,size,created_at) VALUES(?,?,?,?,?,?,?)')->execute([$targetId,null,$orig,$stored,$mime,(int)$f['size'],now()]); }
        else { $rs=db()->prepare('SELECT item_id FROM steps WHERE id=?'); $rs->execute([$targetId]); $itid=($r=$rs->fetch())?(int)$r['item_id']:null; $itemIdForTouch=$itid; db()->prepare('INSERT INTO attachments(item_id,step_id,orig_name,stored_name,mime,size,created_at) VALUES(?,?,?,?,?,?,?)')->execute([$itid,$targetId,$orig,$stored,$mime,(int)$f['size'],now()]); }
        if($itemIdForTouch) db()->prepare('UPDATE items SET updated_at=? WHERE id=?')->execute([now(),$itemIdForTouch]);
        if(is_ajax()){
          $attId=(int)db()->lastInsertId(); $url='?download='.$attId; $isImg=str_starts_with($mime,'image/'); $md=$isImg ? '!['.preg_replace('/\.(zip|png|jpe?g|gif|webp|svg)$/i','',$orig).']('.$url.')' : '['.$orig.']('.$url.')';
          header('Content-Type: application/json'); echo json_encode(['ok'=>1,'id'=>$attId,'url'=>$url,'mime'=>$mime,'markdown'=>$md,'size'=>$f['size']]); exit;
        }
        break;
      }
      case 'upload_mindmap_asset': {
        if(empty($_FILES['file']) || ($_FILES['file']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) throw new RuntimeException('上传失败');
        $f=$_FILES['file']; if($f['size']>MAX_UPLOAD_BYTES) throw new RuntimeException('文件过大，最大 15MB');
        $mapId=(int)($_POST['map_id'] ?? 0);
        $nodeUid=trim((string)($_POST['node_id'] ?? ''));
        if($nodeUid==='') throw new RuntimeException('节点信息缺失');
        $finfo=new finfo(FILEINFO_MIME_TYPE); $mime=$finfo->file($f['tmp_name']) ?: 'application/octet-stream';
        $ext = ALLOWED_UPLOAD_MIME_MAP[$mime] ?? null;
        if(!$ext) throw new RuntimeException('仅允许图片、音视频、PDF、Word/Excel、ZIP/RAR 或文本文件');
        if(!is_dir(UPLOAD_DIR)) @mkdir(UPLOAD_DIR,0775,true);
        $stored=bin2hex(random_bytes(8)).'.'.$ext; $dest=UPLOAD_DIR.DIRECTORY_SEPARATOR.$stored; if(!move_uploaded_file($f['tmp_name'],$dest)) throw new RuntimeException('保存失败');
        $asset=create_mindmap_asset($mapId>0?$mapId:null,$nodeUid,$f['name'],$stored,$mime,(int)$f['size'],session_id());
        if(is_ajax()){
          header('Content-Type: application/json'); echo json_encode([
            'ok'=>1,
            'id'=>(int)$asset['id'],
            'name'=>$asset['orig_name'],
            'size'=>(int)$asset['size'],
            'mime'=>$asset['mime'],
            'url'=>'?mindmap_asset='.(int)$asset['id'],
            'created_at'=>(int)($asset['created_at'] ?? now()),
          ]); exit;
        }
        break;
      }
      case 'ping_cats': { if(is_ajax()) json_cats(); break; }
      case 'delete_attachment': {
        $id=(int)($_POST['id'] ?? 0);
        if($id>0){
          $att=get_attachment($id);
          if($att){
            $path=UPLOAD_DIR.DIRECTORY_SEPARATOR.$att['stored_name']; if(is_file($path)) @unlink($path);
            $pdo->prepare('DELETE FROM attachments WHERE id=?')->execute([$id]);
            $itemId=$att['item_id'] ? (int)$att['item_id'] : null;
            if(!$itemId && $att['step_id']){
              $st=$pdo->prepare('SELECT item_id FROM steps WHERE id=? LIMIT 1');
              $st->execute([(int)$att['step_id']]); if($row=$st->fetch()) $itemId=(int)$row['item_id'];
            }
            if($itemId){ $pdo->prepare('UPDATE items SET updated_at=? WHERE id=?')->execute([now(),$itemId]); }
          }
        }
        if(is_ajax()){ header('Content-Type: application/json'); echo json_encode(['ok'=>1]); exit; }
        break;
      }
      case 'delete_mindmap_assets': {
        $raw=$_POST['ids'] ?? [];
        $ids=[];
        if(is_string($raw)){
          $decoded=json_decode($raw,true);
          if(is_array($decoded)) $raw=$decoded;
          elseif(trim($raw)!=='') $ids=array_map('intval',explode(',', $raw));
        }
        if(is_array($raw)){
          foreach($raw as $val){
            if(is_int($val) || ctype_digit((string)$val)){
              $ids[]=(int)$val;
            }
          }
        }
        $ids=array_values(array_unique(array_filter($ids,fn($v)=>$v>0)));
        $deleted=0;
        foreach($ids as $assetId){
          delete_mindmap_asset($assetId);
          $deleted++;
        }
        if(is_ajax()){
          header('Content-Type: application/json');
          echo json_encode(['ok'=>1,'deleted'=>$deleted]);
          exit;
        }
        break;
      }
      case 'save_mindmap': {
        $title=trim((string)($_POST['title'] ?? ''));
        if($title==='') throw new RuntimeException('标题必填');
        $contentRaw=(string)($_POST['content'] ?? '');
        if($contentRaw==='') throw new RuntimeException('思维导图内容不能为空');
        $decoded=json_decode($contentRaw, true);
        if($decoded===null) throw new RuntimeException('思维导图数据格式不正确');
        $mapIdHint=(int)($_POST['id'] ?? 0);
        $assetRefs=[];
        sanitize_mindmap_payload($decoded,$assetRefs,$mapIdHint>0?$mapIdHint:null,session_id());
        $normalized=json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $id=(int)($_POST['id'] ?? 0);
        $res = $id>0 ? update_mindmap($id,$title,$normalized) : create_mindmap($title,$normalized);
        $finalId=(int)$res['id'];
        sync_mindmap_assets($finalId,$assetRefs,session_id());
        if(is_ajax()){ header('Content-Type: application/json'); echo json_encode(['ok'=>1,'id'=>$res['id'],'updated_at'=>$res['updated_at']]); exit; }
        redirect('?view=map_edit&id='.$res['id']);
        break;
      }
      case 'delete_mindmap': {
        $id=(int)($_POST['id'] ?? 0);
        if($id>0) delete_mindmap($id);
        if(is_ajax()){ header('Content-Type: application/json'); echo json_encode(['ok'=>1]); exit; }
        redirect('?cat=mindmaps');
        break;
      }
    }
  } catch(Throwable $e){
    if(is_ajax()){ header('Content-Type: application/json',true,400); echo json_encode(['ok'=>0,'error'=>$e->getMessage()]); exit; }
    $_SESSION['flash']=$e->getMessage();
  }
  redirect();
}

// —— 视图逻辑 ——
$view = $_GET['view'] ?? '';

// 新建页面
if ($view === 'new') {
  [$cats,$_counts]=get_categories();
  require __DIR__ . '/resources/views/memo/new.phtml';
  exit;
}

// 详情页
if ($view === 'item' && isset($_GET['id']) && ctype_digit((string)$_GET['id'])) {
  $it=get_item((int)$_GET['id']); if(!$it){ http_response_code(404); echo 'Not Found'; exit; }
  $steps=get_steps((int)$it['id']); $itemAtts=attachments_for_item((int)$it['id']); [$cats,$_counts]=get_categories();
  $doneView = $it['done'] ? 'done-view' : '';
  require __DIR__ . '/resources/views/memo/item.phtml';
  exit;
}

// —— 思维导图视图 ——
if ($view === 'map') {
  redirect('?cat=mindmaps');
}

if ($view === 'map_edit') {
  $id = isset($_GET['id']) && ctype_digit((string)$_GET['id']) ? (int)$_GET['id'] : 0;
  if ($id > 0) {
    $mind = get_mindmap($id);
    if (!$mind) { http_response_code(404); echo 'Mindmap not found'; exit; }
  } else {
    $mind = [
      'id' => 0,
      'title' => '未命名导图',
      'content' => default_mindmap_payload(),
      'created_at' => now(),
      'updated_at' => now(),
    ];
  }
  $initialPayload = $mind['content'] ?: default_mindmap_payload();
  $defaultData = json_decode(default_mindmap_payload(), true);
  if (isset($defaultData['data'])) {
    mindmap_force_right_orientation($defaultData['data']);
  }
  $initialDataDecoded = json_decode($initialPayload, true);
  if (!is_array($initialDataDecoded) || empty($initialDataDecoded['data'])) {
    $initialDataDecoded = $defaultData;
  }
  if (isset($initialDataDecoded['data'])) {
    mindmap_force_right_orientation($initialDataDecoded['data']);
  }
  $sessionKey = session_id();
  $assetPool = [];
  if ($mind['id']) {
    foreach (mindmap_assets_for_map((int)$mind['id']) as $asset) {
      if (!isset($asset['id'])) { continue; }
      $assetPool[(int)$asset['id']] = $asset;
    }
  }
  if ($sessionKey !== '') {
    foreach (mindmap_assets_for_session($sessionKey) as $asset) {
      if (!isset($asset['id'])) { continue; }
      $assetPool[(int)$asset['id']] = $asset;
    }
  }
  $mindmapAssetsMeta = array_values(array_map(function ($asset) {
    $id = (int)($asset['id'] ?? 0);
    return [
      'id' => $id,
      'node_uid' => $asset['node_uid'] ?? null,
      'mindmap_id' => isset($asset['mindmap_id']) ? (int)$asset['mindmap_id'] : null,
      'session_key' => $asset['session_key'] ?? null,
      'mime' => $asset['mime'] ?? 'application/octet-stream',
      'size' => (int)($asset['size'] ?? 0),
      'created_at' => (int)($asset['created_at'] ?? 0),
      'name' => $asset['orig_name'] ?? ('附件#' . $id),
      'url' => '?mindmap_asset=' . $id,
    ];
  }, $assetPool));
  require __DIR__ . '/resources/views/memo/map_edit.phtml';
  exit;
}

if ($view === 'maps') {
  redirect('?cat=mindmaps');
}

// —— 首页 ——
$pdo=db(); [$cats,$counts,$stats]=get_categories();
$cat=$_GET['cat'] ?? 'all';
$q=trim((string)($_GET['q'] ?? ''));
$mindmap_total = (int)($stats['mindmap_total'] ?? 0);
$isMindmapCategory = $cat === 'mindmaps';
$items=[];
$mindmapsAll=[];
if($isMindmapCategory){
  $mindmapsAll=get_mindmaps();
  foreach($mindmapsAll as &$map){
    $map['outline']=mindmap_outline_preview($map['content']);
  }
  unset($map);
} else {
  $params=[]; $where=[];
  if($cat==='all'){
    $where[]='done = 0';
  }
  if($cat!=='all' && ctype_digit((string)$cat)){ $where[]='category_id = :cat'; $params[':cat']=(int)$cat; }
  if($q!==''){ $where[]='(title LIKE :q OR description LIKE :q)'; $params[':q']='%'.$q.'%'; }
  $sql='SELECT * FROM items';
  if($where) $sql.=' WHERE '.implode(' AND ',$where);
  $sql.=' ORDER BY order_index ASC, updated_at DESC, id DESC';
  $st=$pdo->prepare($sql); $st->execute($params); $items=$st->fetchAll();
}
$all_total = (int)($stats['active_total'] ?? 0);
$categoryNames=[];
foreach($cats as $c){ $categoryNames[(int)$c['id']]=$c['name']; }
require __DIR__ . '/resources/views/memo/index.phtml';
exit;
