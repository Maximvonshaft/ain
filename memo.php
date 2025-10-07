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
header("Content-Security-Policy: default-src 'self' cdn.jsdelivr.net; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline' cdn.jsdelivr.net fonts.googleapis.com; font-src fonts.gstatic.com; script-src 'self' 'unsafe-inline' cdn.jsdelivr.net; base-uri 'self'; form-action 'self'; frame-ancestors 'self'");

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
  return [$cats,$map];
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

function get_attachment(int $id): ?array {
  $pdo=db(); $st=$pdo->prepare('SELECT * FROM attachments WHERE id=? LIMIT 1'); $st->execute([$id]); return $st->fetch() ?: null;
}

function attachments_for_item(int $item_id): array {
  $pdo=db(); $st=$pdo->prepare('SELECT * FROM attachments WHERE item_id=? ORDER BY id DESC'); $st->execute([$item_id]); return $st->fetchAll();
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
          ];
        }
        $asset_id=(int)($raw['assetId'] ?? ($raw['id'] ?? 0));
        if($asset_id<=0) return null;
        $normalized=[
          'assetId'=>$asset_id,
          'name'=>$raw['name'] ?? ($node['topic'] ?? '附件'),
          'size'=>(int)($raw['size'] ?? 0),
          'mime'=>$raw['mime'] ?? ($raw['type'] ?? 'application/octet-stream'),
          'url'=>$raw['url'] ?? ('?mindmap_asset='.$asset_id),
        ];
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
  [$cats,$counts] = get_categories();
  $pdo=db();
  $total = (int)$pdo->query('SELECT COUNT(*) FROM items WHERE done = 0')->fetchColumn();
  $uncat = (int)$pdo->query('SELECT COUNT(*) FROM items WHERE category_id IS NULL AND done = 0')->fetchColumn();
  $mindmapTotal = (int)$pdo->query('SELECT COUNT(*) FROM mindmaps')->fetchColumn();
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
    foreach($rows as &$r){ $r['steps']=get_steps((int)$r['id']); }
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="memo_export_'.date('Ymd_His').'.json"');
    echo json_encode($rows, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT); exit;
  }
  if($type==='csv'){
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="memo_export_'.date('Ymd_His').'.csv"');
    $out=fopen('php://output','w'); fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out,['id','title','description(MD)','done','category','created_at','updated_at','steps(count)']);
    foreach($rows as $r){ $cnt=count(get_steps((int)$r['id']));
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
          header('Content-Type: application/json'); echo json_encode(['ok'=>1,'id'=>(int)$asset['id'],'name'=>$asset['orig_name'],'size'=>(int)$asset['size'],'mime'=>$asset['mime'],'url'=>'?mindmap_asset='.(int)$asset['id']]); exit;
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
  ?>
  <!doctype html>
  <html lang="zh-Hans">
  <head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <title>新建备忘录</title>
    <meta name="color-scheme" content="dark"/>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@500;600;700&family=Inter:wght@400;500;600&family=Noto+Sans+SC:wght@400;500;600;700&family=Noto+Serif+SC:wght@500;600;700&display=swap" rel="stylesheet">
    <style>
      :root{
        --bg-void:#0A0C0E;
        --bg-elev-1:#0F1316;
        --bg-elev-2:#151A1E;
        --gold-700:#AA8C54;
        --gold-600:#C9A86A;
        --gold-500:#D1B274;
        --gold-400:#E3C68B;
        --accent-emerald:#24C2A0;
        --accent-crimson:#D14B4B;
        --accent-cyan:#4BC3D1;
        --text-strong:#E8E5DF;
        --text-dim:#A7A39A;
        --text-muted:#7A766E;
        --divider:rgba(201,168,106,.18);
        --r-xs:6px;
        --r-sm:10px;
        --r-md:14px;
        --r-lg:18px;
        --shadow-1:0 8px 24px rgba(0,0,0,.5),0 0 24px rgba(227,198,139,.12);
        --shadow-2:0 16px 48px rgba(0,0,0,.6) inset,0 0 1px rgba(201,168,106,.35);
        --t-fast:200ms;
        --t:300ms;
        --t-slow:450ms;
        --ease:cubic-bezier(.22,.61,.36,1);
        /* legacy tokens */
        --bg:var(--bg-void);
        --bg-alt:var(--bg-elev-1);
        --panel:rgba(15,19,22,.86);
        --panel-strong:rgba(21,26,30,.92);
        --glow:var(--gold-500);
        --glow-soft:rgba(227,198,139,.22);
        --glow-strong:rgba(227,198,139,.4);
        --text:var(--text-strong);
        --border:rgba(201,168,106,.36);
        --border-soft:rgba(201,168,106,.18);
        --danger:var(--accent-crimson);
        --danger-soft:rgba(209,75,75,.35);
        --grid-size:64px;
        --transition:var(--t) var(--ease);
      }
      *,*::before,*::after{box-sizing:border-box}
      html,body{margin:0;min-height:100vh;color:var(--text-strong);background:var(--bg-void);font:16px/1.65 'Source Han Sans','Noto Sans SC','Inter','Microsoft YaHei',sans-serif;letter-spacing:.01em;position:relative;overflow-x:hidden}
      body{background:
        radial-gradient(1200px 700px at 70% -10%,rgba(227,198,139,.06),transparent 60%),
        linear-gradient(120deg,rgba(201,168,106,.08),transparent 30%,rgba(201,168,106,.06) 70%,transparent 90%),
        #0A0C0E;
      }
      body::before{content:"";position:fixed;inset:0;background:
        linear-gradient(180deg,rgba(10,12,14,.45),rgba(10,12,14,.8)),
        url('data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" width="160" height="160" viewBox="0 0 160 160"%3E%3Cpath fill="rgba(201,168,106,0.05)" d="M0 79h160v2H0zm79-79h2v160h-2z"/%3E%3C/svg%3E');
        background-size:cover,160px 160px;opacity:.55;pointer-events:none;z-index:-2;
      }
      body::after{content:"";position:fixed;inset:0;background:
        repeating-linear-gradient(0deg,rgba(75,195,209,.08) 0,rgba(75,195,209,.08) 1px,transparent 1px,transparent var(--grid-size)),
        repeating-linear-gradient(90deg,rgba(201,168,106,.12) 0,rgba(201,168,106,.12) 1px,transparent 1px,transparent var(--grid-size));
        background-size:var(--grid-size) var(--grid-size);
        mix-blend-mode:screen;opacity:.28;pointer-events:none;z-index:-3;background-attachment:fixed;
      }
      .scanlines{position:fixed;inset:0;pointer-events:none;z-index:-1;background:linear-gradient(to bottom,rgba(75,195,209,.14) 0,transparent 4px);background-size:100% 6px;opacity:.18}
      a{color:inherit;text-decoration:none}
      h1,h2,h3,h4{font-family:'Cinzel','Noto Serif SC',serif;color:var(--gold-400);letter-spacing:-0.5px;text-transform:uppercase}
      .wrap{max-width:1180px;margin:0 auto;padding:32px 24px 64px;position:relative;z-index:0}
      .wrap::before{content:"";position:absolute;inset:16px;border-radius:var(--r-lg);border:1px dashed rgba(201,168,106,.18);opacity:.65;pointer-events:none}
      .card{position:relative;background:linear-gradient(180deg,rgba(21,26,30,.9),rgba(15,19,22,.92));border:1px solid rgba(201,168,106,.28);border-radius:var(--r-lg);padding:24px;box-shadow:var(--shadow-1);backdrop-filter:blur(14px)}
      .card::before{content:"";position:absolute;inset:10px;border-radius:calc(var(--r-lg) - 4px);box-shadow:inset 0 0 0 1px rgba(227,198,139,.18),inset 0 0 34px rgba(227,198,139,.08);pointer-events:none;opacity:.9}
      .btn{position:relative;display:inline-flex;align-items:center;justify-content:center;padding:10px 18px;border-radius:var(--r-sm);border:1px solid rgba(227,198,139,.65);background:linear-gradient(135deg,rgba(227,198,139,.82),rgba(170,140,84,.62));color:#1b1306;cursor:pointer;text-transform:uppercase;font:600 13px/1.2 'Inter','Noto Sans SC',sans-serif;letter-spacing:.12em;transition:transform var(--transition),box-shadow var(--transition),border-color var(--transition);box-shadow:0 14px 32px rgba(227,198,139,.25),0 0 24px rgba(227,198,139,.18);overflow:hidden}
      .btn::after{content:none}
      .btn:hover{transform:translateY(-2px);box-shadow:0 18px 36px rgba(227,198,139,.3),0 0 28px rgba(227,198,139,.24)}
      .btn:active{transform:translateY(0);box-shadow:0 8px 20px rgba(227,198,139,.22)}
      .btn.acc{background:linear-gradient(135deg,rgba(227,198,139,.92),rgba(201,168,106,.72));color:#120d05}
      .btn:focus-visible{outline:2px solid var(--accent-cyan);outline-offset:3px;box-shadow:0 0 0 2px rgba(227,198,139,.25)}
      .row{display:grid;grid-template-columns:2fr 1fr auto;gap:16px;margin-bottom:20px;align-items:center}
      .row input,.row select{padding:12px 14px;border-radius:var(--r-sm);border:1px solid rgba(201,168,106,.28);background:rgba(12,16,18,.7);color:var(--text-strong);font:500 15px/1.4 'Noto Sans SC','Inter',sans-serif;letter-spacing:.02em;transition:border-color var(--transition),box-shadow var(--transition)}
      .row input::placeholder{color:var(--text-muted)}
      .row input:focus,.row select:focus{border-color:var(--gold-500);box-shadow:0 0 0 3px rgba(227,198,139,.16),inset 0 0 0 1px rgba(227,198,139,.22);outline:none}
      .split{display:grid;grid-template-columns:minmax(320px,1fr) minmax(320px,1fr);gap:24px;align-items:start}
      @media (max-width:920px){
        .row{grid-template-columns:1fr;gap:12px}
        .split{grid-template-columns:1fr}
      }
      .editbox,.preview{position:relative;background:linear-gradient(180deg,rgba(21,26,30,.88),rgba(15,19,22,.92));border:1px solid rgba(201,168,106,.28);border-radius:var(--r-md);padding:18px;min-height:280px;box-shadow:var(--shadow-1)}
      .editbox::before,.preview::before{content:"";position:absolute;inset:10px;border-radius:calc(var(--r-md) - 4px);border:1px solid rgba(201,168,106,.18);opacity:.6;pointer-events:none}
      .editbox textarea{width:100%;min-height:260px;padding:14px;border-radius:var(--r-md);border:1px solid rgba(201,168,106,.28);background:rgba(12,16,18,.82);color:var(--text-strong);font:500 15px/1.6 'Noto Sans SC','Inter',sans-serif;letter-spacing:.03em;resize:vertical;transition:border-color var(--transition),box-shadow var(--transition)}
      .editbox textarea::placeholder{color:var(--text-muted)}
      .editbox textarea:focus{outline:none;border-color:var(--gold-500);box-shadow:0 0 0 3px rgba(227,198,139,.16),inset 0 0 0 1px rgba(227,198,139,.24)}
      .preview{max-height:60vh;overflow:auto;background-image:linear-gradient(120deg,rgba(201,168,106,.06),transparent 55%)}
      .preview::-webkit-scrollbar{width:8px}
      .preview::-webkit-scrollbar-thumb{background:rgba(201,168,106,.28);border-radius:999px}
      .md-body{color:var(--text-dim);font:400 15px/1.75 'Noto Sans SC','Inter',sans-serif}
      .md-body img{max-width:100%;height:auto;border:1px solid rgba(201,168,106,.32);border-radius:var(--r-sm);box-shadow:0 16px 34px rgba(0,0,0,.55),0 0 24px rgba(227,198,139,.12)}
      .attachment-panel{display:flex;flex-direction:column;gap:16px}
      .attachment-panel-header{display:flex;align-items:center;justify-content:space-between;gap:12px}
      .attachment-panel-title{font:600 14px/1.2 'Inter','Noto Sans SC',sans-serif;letter-spacing:.16em;text-transform:uppercase;color:var(--gold-400)}
      .attachment-panel-meta{color:var(--text-muted);font:12px/1.4 'Inter','Noto Sans SC',sans-serif;letter-spacing:.1em}
      .attachment-empty{padding:18px;border:1px dashed rgba(201,168,106,.28);border-radius:16px;background:rgba(12,16,18,.66);color:var(--text-muted);text-align:center;font:500 13px/1.6 'Inter','Noto Sans SC',sans-serif;letter-spacing:.06em}
      .attachment-list{display:grid;gap:12px}
      .attachment-row{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:14px 16px;border-radius:16px;border:1px solid rgba(201,168,106,.26);background:rgba(12,16,18,.78);box-shadow:var(--shadow-1)}
      .attachment-main{display:flex;align-items:center;gap:12px;min-width:0;flex:1}
      .attachment-icon{font-size:26px;line-height:1}
      .attachment-info{display:grid;gap:4px;min-width:0}
      .attachment-name{font:600 14px/1.4 'Inter','Noto Sans SC',sans-serif;color:var(--text-strong);letter-spacing:.04em;word-break:break-all}
      .attachment-detail{color:var(--text-muted);font:12px/1.4 'Inter','Noto Sans SC',sans-serif;letter-spacing:.08em}
      .attachment-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
      .attachment-actions form{margin:0}
      .att-meta{color:var(--text-muted);font:12px/1 'Inter','Noto Sans SC',sans-serif;letter-spacing:.12em}
      .timeline{position:relative;margin-top:20px;margin-left:16px;padding-left:32px;color:var(--text-muted)}
      .timeline::before{content:"";position:absolute;left:12px;top:0;bottom:0;width:2px;background:linear-gradient(180deg,rgba(201,168,106,.6),rgba(201,168,106,.1));box-shadow:0 0 18px rgba(227,198,139,.28)}
      .tl-item{position:relative;margin:14px 0;padding:16px 18px 18px 24px;border:1px solid rgba(201,168,106,.32);border-radius:var(--r-md);background:linear-gradient(180deg,rgba(21,26,30,.9),rgba(15,19,22,.92));box-shadow:var(--shadow-1)}
      .tl-item::before{content:"";position:absolute;inset:8px;border-radius:calc(var(--r-md) - 4px);border:1px dashed rgba(201,168,106,.16);opacity:.6;pointer-events:none}
      .tl-item .tl-dot{position:absolute;left:-30px;top:20px;width:16px;height:16px;border-radius:50%;background:linear-gradient(135deg,rgba(201,168,106,.92),rgba(170,140,84,.9));box-shadow:0 0 24px rgba(227,198,139,.32)}
      .tl-item .tl-dot::after{content:"";position:absolute;inset:-6px;border-radius:inherit;border:1px dashed rgba(201,168,106,.45);opacity:.85;box-shadow:0 0 16px rgba(227,198,139,.28)}
      .tl-head{display:flex;gap:12px;align-items:center;color:var(--text-strong);font-family:'Inter','Noto Sans SC',sans-serif}
      .tl-item.done .tl-head div,.tl-item.done .md-body{opacity:.72;text-decoration:line-through}
      .tl-item.done{background:linear-gradient(160deg,rgba(36,194,160,.18),rgba(15,22,20,.92));border-color:rgba(36,194,160,.42);box-shadow:0 0 28px rgba(36,194,160,.28)}
      .drag{cursor:grab;color:var(--text-muted);user-select:none;font-size:18px}
      .ts{color:var(--text-dim);font:12px/1 'Inter','Noto Sans SC',sans-serif;margin-left:6px;letter-spacing:.12em}
      details summary{cursor:pointer;color:var(--text-muted);text-transform:uppercase;letter-spacing:.16em;font:500 12px/1.4 'Inter','Noto Sans SC',sans-serif}
      .save-tip{color:var(--gold-400);font:12px/1 'Inter','Noto Sans SC',sans-serif;display:none;margin-left:10px;text-transform:uppercase;letter-spacing:.18em}
      .save-tip.dirty{color:var(--accent-crimson)}
      .save-tip.show{display:inline-block}
      .placeholder-muted{color:var(--text-muted)}
    </style>
  </head>
  <body data-density="comfortable">
    <div class="scanlines" aria-hidden="true"></div>
    <div class="wrap">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <a class="btn btn-ghost" href="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>">← 返回首页</a>
      </div>
      <div class="card">
        <form id="new-form" onsubmit="return saveAJAX(event)">
          <div class="row">
            <input id="title" name="title" placeholder="标题 · Title" required>
            <select id="cat" name="category_id">
              <option value="">未分类 · Unassigned</option>
              <?php foreach ($cats as $c): ?><option value="<?php echo $c['id']; ?>"><?php echo h($c['name']); ?></option><?php endforeach; ?>
            </select>
            <div style="display:flex;gap:8px;align-items:center;justify-content:flex-end">
              <button class="btn btn-primary" type="submit">保存</button>
              <span id="save-tip" class="save-tip">保存成功</span>
            </div>
          </div>
          <div class="split" id="split">
            <div class="editbox">
              <textarea id="md-editor" name="description" placeholder="描述 · 支持 Markdown"></textarea>
              <div style="display:flex;gap:10px;justify-content:space-between;align-items:center;margin-top:10px">
                <div>
                  <input id="att-file-item" type="file" accept="image/*,video/*,audio/*,application/pdf,application/zip,application/x-zip-compressed,application/x-rar-compressed,application/vnd.rar,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.ms-word.document.macroenabled.12,application/vnd.openxmlformats-officedocument.wordprocessingml.template,application/vnd.ms-word.template.macroenabled.12,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel.sheet.macroenabled.12,application/vnd.ms-excel.sheet.binary.macroenabled.12,application/vnd.openxmlformats-officedocument.spreadsheetml.template,application/vnd.ms-excel.template.macroenabled.12,text/plain,text/markdown,text/csv,text/tab-separated-values,application/json,text/json" style="display:none">
                  <button class="btn btn-ghost" type="button" id="btn-insert-att-item">插入附件到备注</button>
                  <span class="att-meta">图片、音视频、PDF、Word/Excel、ZIP/RAR 或文本 ≤ 15MB</span>
                </div>
                <button class="btn btn-ghost" type="button" id="btn-preview-toggle">预览置顶/置底</button>
              </div>
            </div>
            <div class="preview attachment-panel">
              <div class="attachment-panel-header">
                <div class="attachment-panel-title">附件列表</div>
                <div class="attachment-panel-meta" id="attachment-summary">暂无附件</div>
              </div>
              <div class="attachment-empty" id="attachment-empty">暂未上传附件。插入文件后可在此快速预览与下载。</div>
              <div class="attachment-list" id="attachment-list"></div>
            </div>
          </div>
        </form>
        <div style="display:flex;justify-content:space-between;align-items:center;margin:12px 0 6px">
          <div style="font-weight:800">流程子任务（时间轴）</div>
          <form id="add-step-form" onsubmit="return addStepAJAX(event)" style="display:flex;gap:6px;flex-wrap:wrap">
            <input id="new-step-title" placeholder="新增步骤 · Add step" style="flex:1;min-width:200px;padding:12px;border:1px solid var(--border);border-radius:12px;background:rgba(6,25,14,.82);color:var(--text);letter-spacing:.08em">
            <button class="btn btn-primary" type="submit">添加</button>
          </form>
        </div>
        <div class="timeline" id="timeline" data-item="0"></div>
      </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/dompurify/dist/purify.min.js"></script>
    <script>
      (function(){
        if(window.AttachmentPreview) return;
        const readyQueue=window.__attachmentPreviewReadyCallbacks = window.__attachmentPreviewReadyCallbacks || [];
        const scriptCache=new Map();
        let elements=null;
        let objectUrl=null;
        let abortController=null;
        let lastActive=null;

        const formatBytes=value=>{
          if(!Number.isFinite(value) || value<=0) return '';
          const units=['B','KB','MB','GB','TB'];
          let idx=0; let num=value;
          while(num>=1024 && idx<units.length-1){ num/=1024; idx++; }
          const decimals=num>=100 || idx===0 ? 0 : 1;
          return num.toFixed(decimals)+' '+units[idx];
        };
        const ensureElements=()=>{
          if(elements) return elements;
          const backdrop=document.createElement('div');
          backdrop.className='attachment-preview-backdrop';
          const panel=document.createElement('div');
          panel.className='attachment-preview-panel';
          panel.setAttribute('role','dialog');
          panel.setAttribute('aria-modal','true');
          panel.setAttribute('aria-labelledby','attachment-preview-title');
          panel.tabIndex=-1;
          const header=document.createElement('div');
          header.className='attachment-preview-header';
          const titleWrap=document.createElement('div');
          const titleEl=document.createElement('div');
          titleEl.className='attachment-preview-title';
          titleEl.id='attachment-preview-title';
          const metaEl=document.createElement('div');
          metaEl.className='attachment-preview-meta';
          titleWrap.appendChild(titleEl);
          titleWrap.appendChild(metaEl);
          const closeBtn=document.createElement('button');
          closeBtn.className='attachment-preview-close';
          closeBtn.type='button';
          closeBtn.setAttribute('aria-label','关闭预览');
          closeBtn.textContent='×';
          header.appendChild(titleWrap);
          header.appendChild(closeBtn);
          const bodyEl=document.createElement('div');
          bodyEl.className='attachment-preview-body';
          const loadingEl=document.createElement('div');
          loadingEl.className='attachment-preview-loading';
          loadingEl.textContent='载入中…';
          bodyEl.appendChild(loadingEl);
          const footer=document.createElement('div');
          footer.className='attachment-preview-footer';
          const downloadBtn=document.createElement('a');
          downloadBtn.className='attachment-preview-download';
          downloadBtn.href='';
          downloadBtn.target='_blank';
          downloadBtn.rel='noopener';
          downloadBtn.textContent='下载附件';
          footer.appendChild(downloadBtn);
          panel.appendChild(header);
          panel.appendChild(bodyEl);
          panel.appendChild(footer);
          backdrop.appendChild(panel);
          document.body.appendChild(backdrop);
          const closePreview=()=>{
            backdrop.dataset.open='false';
            if(lastActive && typeof lastActive.focus==='function'){
              try{ lastActive.focus(); }catch(_){ /* ignore */ }
            }
            lastActive=null;
            if(abortController){ abortController.abort(); abortController=null; }
            if(objectUrl){ URL.revokeObjectURL(objectUrl); objectUrl=null; }
          };
          closeBtn.addEventListener('click',closePreview);
          backdrop.addEventListener('click',evt=>{ if(evt.target===backdrop) closePreview(); });
          document.addEventListener('keydown',evt=>{ if(evt.key==='Escape' && backdrop.dataset.open==='true'){ closePreview(); } });
          elements={backdrop,panel,titleEl,metaEl,closeBtn,bodyEl,loadingEl,downloadBtn,closePreview};
          return elements;
        };
        const cleanupObjectUrl=()=>{ if(objectUrl){ URL.revokeObjectURL(objectUrl); objectUrl=null; } };
        const showContent=content=>{
          const {bodyEl,loadingEl}=ensureElements();
          if(loadingEl && loadingEl.parentNode===bodyEl){ loadingEl.remove(); }
          bodyEl.innerHTML='';
          if(content) bodyEl.appendChild(content);
        };
        const showError=message=>{
          const div=document.createElement('div');
          div.className='attachment-preview-error';
          div.textContent=message||'预览失败';
          showContent(div);
        };
        const showList=entries=>{
          const list=document.createElement('ul');
          list.className='attachment-preview-list';
          entries.forEach(entry=>{
            const li=document.createElement('li');
            const label=document.createElement('span');
            label.textContent=entry.label;
            li.appendChild(label);
            if(entry.size){
              const sizeSpan=document.createElement('span');
              sizeSpan.className='entry-size';
              sizeSpan.textContent=entry.size;
              li.appendChild(sizeSpan);
            }
            list.appendChild(li);
          });
          showContent(list);
        };
        const renderMedia=(type,url,mime)=>{
          let el;
          if(type==='video'){
            el=document.createElement('video');
            el.controls=true;
            el.preload='metadata';
            el.playsInline=true;
            el.classList.add('attachment-preview-video');
          }else if(type==='audio'){
            el=document.createElement('audio');
            el.controls=true;
            el.preload='metadata';
            el.style.width='100%';
          }else if(type==='pdf'){
            el=document.createElement('iframe');
            el.type=mime||'application/pdf';
            el.setAttribute('title','PDF 预览');
          }else{
            el=document.createElement('img');
          }
          el.src=url;
          showContent(el);
        };
        const detectType=source=>{
          const mime=(source.mime||'').toLowerCase();
          const name=(source.name||'').toLowerCase();
          const ext=name.includes('.') ? name.split('.').pop() : '';
          if(mime.startsWith('image/') || ['png','jpg','jpeg','gif','webp','bmp','svg','avif','heic','heif'].includes(ext)) return 'image';
          if(mime.startsWith('video/') || ['mp4','mov','m4v','webm','ogv','mkv','avi'].includes(ext)) return 'video';
          if(mime.startsWith('audio/') || ['mp3','wav','ogg','oga','m4a','aac','flac','opus','weba'].includes(ext)) return 'audio';
          if(mime==='application/pdf' || ext==='pdf') return 'pdf';
          if(mime.includes('zip') || ext==='zip') return 'zip';
          if(mime.includes('rar') || ext==='rar') return 'rar';
          if(mime.includes('spreadsheet') || ['xlsx','xls','xlsm','xlsb','csv','xltx','xltm','tsv'].includes(ext)) return 'excel';
          if(mime.includes('word') || ['docx','docm','doc','dotx','dotm'].includes(ext)) return 'word';
          return 'other';
        };
        const normalizeSource=input=>{
          if(!input) return {kind:'url',url:'',name:'附件',mime:'',size:null,downloadName:null};
          if(typeof input==='string') return {kind:'url',url:input,name:'附件',mime:'',size:null,downloadName:null};
          const kind=input.kind ? String(input.kind) : (input.blob ? 'blob' : 'url');
          const sizeRaw = typeof input.size==='number' ? input.size : parseInt(input.size,10);
          const size=Number.isFinite(sizeRaw) && sizeRaw>0 ? sizeRaw : null;
          const name=typeof input.name==='string' && input.name.trim() ? input.name.trim() : '附件';
          return {
            kind,
            url:typeof input.url==='string'?input.url:'',
            blob:input.blob instanceof Blob ? input.blob : null,
            name,
            mime:typeof input.mime==='string'?input.mime:'',
            size,
            downloadName:typeof input.downloadName==='string' && input.downloadName.trim()?input.downloadName.trim():name
          };
        };
        const setDownloadLink=(btn,source)=>{
          let href='';
          if(source.kind==='blob' && source.blob){
            cleanupObjectUrl();
            objectUrl=URL.createObjectURL(source.blob);
            href=objectUrl;
          }else if(source.url){
            href=source.url;
          }
          if(btn){
            if(href){
              btn.href=href;
              btn.download=source.downloadName || source.name || 'attachment';
              btn.hidden=false;
            }else{
              btn.removeAttribute('href');
              btn.hidden=true;
            }
          }
          return href;
        };
        const loadBuffer=async source=>{
          if(source.kind==='blob' && source.blob){
            return await source.blob.arrayBuffer();
          }
          if(!source.url) throw new Error('缺少文件地址');
          abortController=new AbortController();
          try{
            const res=await fetch(source.url,{signal:abortController.signal});
            if(!res.ok) throw new Error('网络错误');
            return await res.arrayBuffer();
          }finally{
            abortController=null;
          }
        };
        const ensureScript=url=>{
          if(scriptCache.has(url)) return scriptCache.get(url);
          const promise=new Promise((resolve,reject)=>{
            const el=document.createElement('script');
            el.src=url;
            el.async=true;
            el.onload=()=>resolve();
            el.onerror=()=>reject(new Error('脚本加载失败'));
            document.head.appendChild(el);
          });
          scriptCache.set(url,promise);
          return promise;
        };
        const buildArchiveTree=entries=>{
          const root={name:'',type:'dir',children:new Map()};
          entries.forEach(entry=>{
            const rawPath=String(entry.path||'').replace(/\\/g,'/');
            const normalized=rawPath.replace(/^\/+/, '').trim();
            if(!normalized) return;
            const parts=normalized.split('/').filter(Boolean);
            let node=root;
            parts.forEach((part,index)=>{
              const isLast=index===parts.length-1;
              if(isLast && !entry.dir){
                node.children.set(part,{type:'file',name:part,size:entry.size||''});
              }else{
                let next=node.children.get(part);
                if(!next || next.type!=='dir'){
                  next={type:'dir',name:part,children:new Map()};
                  node.children.set(part,next);
                }
                node=next;
              }
            });
            if(entry.dir && parts.length){
              let dirNode=root;
              parts.forEach(part=>{
                let next=dirNode.children.get(part);
                if(!next || next.type!=='dir'){
                  next={type:'dir',name:part,children:new Map()};
                  dirNode.children.set(part,next);
                }
                dirNode=next;
              });
            }
          });
          return root;
        };
        const createTreeElement=(node,depth=0)=>{
          const ul=document.createElement('ul');
          ul.className='attachment-preview-tree';
          const items=Array.from(node.children.values());
          items.sort((a,b)=>{
            if(a.type!==b.type) return a.type==='dir'?-1:1;
            return a.name.localeCompare(b.name,'zh-Hans');
          });
          items.forEach(child=>{
            const li=document.createElement('li');
            if(child.type==='dir'){
              const details=document.createElement('details');
              if(depth<1) details.open=true;
              const summary=document.createElement('summary');
              const label=document.createElement('span');
              label.className='entry-label';
              label.textContent='📁 '+child.name;
              summary.appendChild(label);
              if(child.size){
                const sizeSpan=document.createElement('span');
                sizeSpan.className='entry-size';
                sizeSpan.textContent=child.size;
                summary.appendChild(sizeSpan);
              }
              details.appendChild(summary);
              details.appendChild(createTreeElement(child, depth+1));
              li.appendChild(details);
            }else{
              const row=document.createElement('div');
              row.className='tree-entry';
              const label=document.createElement('span');
              label.className='entry-label';
              label.textContent='📄 '+child.name;
              row.appendChild(label);
              if(child.size){
                const sizeSpan=document.createElement('span');
                sizeSpan.className='entry-size';
                sizeSpan.textContent=child.size;
                row.appendChild(sizeSpan);
              }
              li.appendChild(row);
            }
            ul.appendChild(li);
          });
          return ul;
        };
        const showArchiveTree=(entries,kind)=>{
          if(!entries || !entries.length){
            showError((kind||'压缩包')+'为空。');
            return;
          }
          const tree=buildArchiveTree(entries);
          const view=createTreeElement(tree);
          showContent(view);
        };
        const isPasswordError=err=>{
          const msg=(err && err.message ? String(err.message) : '').toLowerCase();
          return msg.includes('password') || msg.includes('decrypt') || msg.includes('encrypted');
        };
        const loadZipPreview=async source=>{
          const {loadingEl}=ensureElements();
          const attempt=async password=>{
            loadingEl.textContent=password?'验证密码…':'解析压缩包…';
            const buffer=await loadBuffer(source);
            await ensureScript('https://cdn.jsdelivr.net/npm/@zip.js/zip.js@2.7.32/dist/zip.min.js');
            const zipLib=window.zip;
            if(!zipLib || !zipLib.ZipReader || !zipLib.Uint8ArrayReader) throw new Error('解析库未加载');
            const reader=new zipLib.ZipReader(new zipLib.Uint8ArrayReader(new Uint8Array(buffer)), password?{password}:{});
            try{
              const entries=await reader.getEntries();
              const mapped=(entries||[]).map(entry=>({
                path:entry.filename,
                dir:!!entry.directory,
                size:entry.uncompressedSize?formatBytes(entry.uncompressedSize):''
              }));
              if(!mapped.length){
                showError('压缩包为空。');
                return;
              }
              showArchiveTree(mapped,'ZIP 压缩包');
            }finally{
              if(reader && typeof reader.close==='function'){
                try{ await reader.close(); }catch(_){ /* ignore */ }
              }
            }
          };
          try{
            await attempt('');
          }catch(err){
            if(isPasswordError(err)){
              let retry=false;
              while(true){
                const input=window.prompt(retry?'密码不正确，请重新输入 ZIP 密码：':'该 ZIP 文件已加密，请输入密码以预览：','');
                if(input===null){ showError('已取消预览。'); return; }
                try{
                  await attempt(input);
                  return;
                }catch(inner){
                  if(!isPasswordError(inner)) throw inner;
                  retry=true;
                }
              }
            }
            throw err;
          }
        };
        const loadRarPreview=async source=>{
          const {loadingEl}=ensureElements();
          const attempt=async password=>{
            loadingEl.textContent=password?'验证密码…':'解析压缩包…';
            const buffer=await loadBuffer(source);
            await ensureScript('https://cdn.jsdelivr.net/npm/unrar-js@0.2.19/dist/unrar.js');
            const api=window.UNRAR || window.unrar;
            if(!api || typeof api.createExtractorFromData!=='function') throw new Error('解析库未加载');
            const extractor=await api.createExtractorFromData({data:buffer,password:password||undefined});
            try{
              const list=extractor && typeof extractor.getFileList==='function' ? extractor.getFileList() : null;
              const headers=list && Array.isArray(list.fileHeaders) ? list.fileHeaders : [];
              const entries=headers.map(header=>{
                const name=header && typeof header.name==='string' && header.name ? header.name : (header && typeof header.fileName==='string' ? header.fileName : '未知文件');
                const dirFlag=header && header.flags ? (header.flags.directory || header.flags.DIRECTORY || header.flags.folder) : false;
                const isDir=!!dirFlag || /[\\/]$/.test(name);
                const sizeValue=header && typeof header.uncompressedSize==='number'?header.uncompressedSize:(header && typeof header.size==='number'?header.size:null);
                return {path:name,dir:isDir,size:sizeValue?formatBytes(sizeValue):''};
              });
              if(!entries.length){
                showError('压缩包为空。');
                return;
              }
              showArchiveTree(entries,'RAR 压缩包');
            }finally{
              if(extractor){
                if(typeof extractor.free==='function') extractor.free();
                else if(typeof extractor.close==='function') extractor.close();
                else if(typeof extractor.delete==='function') extractor.delete();
              }
            }
          };
          try{
            await attempt('');
          }catch(err){
            if(isPasswordError(err)){
              let retry=false;
              while(true){
                const input=window.prompt(retry?'密码不正确，请重新输入 RAR 密码：':'该 RAR 文件已加密，请输入密码以预览：','');
                if(input===null){ showError('已取消预览。'); return; }
                try{
                  await attempt(input);
                  return;
                }catch(inner){
                  if(!isPasswordError(inner)) throw inner;
                  retry=true;
                }
              }
            }
            throw err;
          }
        };
        const loadDocxPreview=async source=>{
          const {loadingEl}=ensureElements();
          loadingEl.textContent='解析文档…';
          const buffer=await loadBuffer(source);
          await ensureScript('https://cdn.jsdelivr.net/npm/mammoth@1.6.0/mammoth.browser.min.js');
          if(!window.mammoth || typeof window.mammoth.convertToHtml!=='function') throw new Error('转换库未加载');
          const result=await window.mammoth.convertToHtml({arrayBuffer:buffer}).catch(err=>{ throw err; });
          const html=result && typeof result.value==='string' ? result.value : '';
          const wrapper=document.createElement('div');
          wrapper.className='attachment-docx';
          const content=html && html.trim() ? html : '<p>（文档为空）</p>';
          wrapper.innerHTML=window.DOMPurify ? window.DOMPurify.sanitize(content) : content;
          showContent(wrapper);
        };
        const loadExcelPreview=async source=>{
          const {loadingEl}=ensureElements();
          loadingEl.textContent='解析表格…';
          const buffer=await loadBuffer(source);
          await ensureScript('https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js');
          if(!window.XLSX || typeof window.XLSX.read!=='function') throw new Error('解析库未加载');
          const workbook=window.XLSX.read(buffer,{type:'array'});
          const sheetNames=Array.isArray(workbook.SheetNames)?workbook.SheetNames:[];
          if(!sheetNames.length){ showError('工作簿为空。'); return; }
          const container=document.createElement('div');
          container.className='attachment-excel';
          sheetNames.forEach(name=>{
            const sheet=workbook.Sheets[name];
            if(!sheet) return;
            const section=document.createElement('section');
            section.className='excel-sheet';
            const heading=document.createElement('h3');
            heading.textContent='工作表：'+name;
            section.appendChild(heading);
            const tableHtml=window.XLSX.utils && typeof window.XLSX.utils.sheet_to_html==='function'
              ? window.XLSX.utils.sheet_to_html(sheet,{header:'',footer:''})
              : '';
            const sanitized=window.DOMPurify ? window.DOMPurify.sanitize(tableHtml||'<table><tbody><tr><td>（无法预览该表格）</td></tr></tbody></table>') : (tableHtml||'<table><tbody><tr><td>（无法预览该表格）</td></tr></tbody></table>');
            const tableWrap=document.createElement('div');
            tableWrap.className='excel-table';
            tableWrap.innerHTML=sanitized;
            section.appendChild(tableWrap);
            container.appendChild(section);
          });
          showContent(container);
        };
        const openPreview=input=>{
          const source=normalizeSource(input);
          const {backdrop,panel,titleEl,metaEl,loadingEl,downloadBtn}=ensureElements();
          if(abortController){ abortController.abort(); abortController=null; }
          cleanupObjectUrl();
          lastActive=document.activeElement instanceof HTMLElement ? document.activeElement : null;
          backdrop.dataset.open='true';
          try{ panel.focus({preventScroll:true}); }catch(_){ /* ignore */ }
          titleEl.textContent=source.name || '附件预览';
          const metaParts=[];
          if(source.mime) metaParts.push(source.mime);
          if(Number.isFinite(source.size) && source.size>0) metaParts.push(formatBytes(source.size));
          metaEl.textContent=metaParts.join(' · ');
          const downloadHref=setDownloadLink(downloadBtn, source);
          loadingEl.textContent='载入中…';
          const type=detectType(source);
          if(type==='image' || type==='video' || type==='pdf' || type==='audio'){
            const url=(type==='image' && source.kind==='url') ? source.url : (source.kind==='blob' ? downloadHref : source.url || downloadHref);
            if(!url){ showError('附件缺少可用地址'); return; }
            renderMedia(type,url,source.mime);
            return;
          }
          const labelMap={zip:'ZIP 压缩包',rar:'RAR 压缩包',word:'文档',excel:'表格'};
          const handleError=err=>{
            console.error(err);
            if(labelMap[type]){
              showError('无法解析'+labelMap[type]+'：'+(err && err.message?err.message:''));
            }else{
              showError('暂不支持该文件类型的在线预览，请使用下载按钮。');
            }
          };
          if(type==='zip'){ loadZipPreview(source).then(()=>{}).catch(handleError); return; }
          if(type==='rar'){ loadRarPreview(source).then(()=>{}).catch(handleError); return; }
          if(type==='word'){ loadDocxPreview(source).then(()=>{}).catch(handleError); return; }
          if(type==='excel'){ loadExcelPreview(source).then(()=>{}).catch(handleError); return; }
          showError('暂不支持该文件类型的在线预览，请使用下载按钮。');
        };
        window.AttachmentPreview={
          open:openPreview,
          openFromUrl(url,meta){ openPreview(Object.assign({}, meta||{}, {url:url||''})); },
          openFromBlob(blob,meta){ openPreview(Object.assign({}, meta||{}, {blob})); },
          close(){ const els=ensureElements(); els.closePreview(); }
        };
        const callbacks=readyQueue.splice(0);
        callbacks.forEach(fn=>{ try{ fn(); }catch(err){ console.error(err); } });
      })();

      function registerAttachmentPreviewReady(fn){
        if(typeof fn!=='function') return;
        if(window.AttachmentPreview){ fn(); return; }
        (window.__attachmentPreviewReadyCallbacks = window.__attachmentPreviewReadyCallbacks || []).push(fn);
      }
      function sanitizeAttachmentName(name){
        if(typeof name!=='string') return '附件';
        const trimmed=name.replace(/[\r\n]+/g,' ').trim();
        return trimmed || '附件';
      }
      function humanBytes(size){
        if(!Number.isFinite(size) || size<=0) return '';
        const units=['B','KB','MB','GB','TB'];
        let idx=0; let value=size;
        while(value>=1024 && idx<units.length-1){ value/=1024; idx++; }
        const decimals=value>=100 || idx===0 ? 0 : 1;
        return value.toFixed(decimals)+' '+units[idx];
      }
      function attachmentIconForMime(mime,name){
        const lower=(mime||'').toLowerCase();
        const ext=(typeof name==='string' && name.includes('.')) ? name.split('.').pop().toLowerCase() : '';
        if(lower.startsWith('image/')) return '🖼';
        if(lower.startsWith('video/')) return '🎬';
        if(lower.startsWith('audio/')) return '🎧';
        if(lower==='application/pdf' || ext==='pdf') return '📄';
        if(lower.includes('spreadsheet') || ['xlsx','xls','xlsm','xlsb','csv','xltx','xltm','tsv'].includes(ext)) return '📊';
        if(lower.includes('word') || ['docx','docm','doc','dotx','dotm'].includes(ext)) return '📝';
        if(lower.includes('zip') || ext==='zip') return '🗜';
        if(lower.includes('rar') || ext==='rar') return '📦';
        return '📎';
      }
      function applyPreviewDataset(el,data){
        if(!el || !data) return;
        el.dataset.attachmentPreview='true';
        if(data.url) el.dataset.url=data.url;
        if(data.name) el.dataset.name=data.name;
        if(data.mime) el.dataset.mime=data.mime;
        if(data.size) el.dataset.size=data.size;
      }
      function bindAttachmentPreviewTargets(scope){
        const root=scope || document;
        root.querySelectorAll('[data-attachment-preview]').forEach(el=>{
          if(el.dataset.previewBound==='true') return;
          el.dataset.previewBound='true';
          el.addEventListener('click',event=>{
            if(event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
            if(!window.AttachmentPreview) return;
            const dataset=el.dataset || {};
            const url=dataset.url || el.getAttribute('href') || '';
            if(!url) return;
            event.preventDefault();
            const name=dataset.name || sanitizeAttachmentName(el.getAttribute('title') || '附件');
            const mime=dataset.mime || '';
            const sizeRaw=dataset.size ? parseInt(dataset.size,10) : NaN;
            const payload={kind:'url',url,name,mime};
            if(Number.isFinite(sizeRaw)) payload.size=sizeRaw;
            window.AttachmentPreview.open(payload);
          });
        });
      }
      function updateAttachmentPanelSummary(){
        const list=document.getElementById('attachment-list');
        const summary=document.getElementById('attachment-summary');
        const empty=document.getElementById('attachment-empty');
        if(!list) return;
        const rows=Array.from(list.querySelectorAll('.attachment-row'));
        const count=rows.length;
        let total=0;
        rows.forEach(row=>{
          const sizeRaw=row.dataset && row.dataset.size ? parseInt(row.dataset.size,10) : NaN;
          if(Number.isFinite(sizeRaw) && sizeRaw>0){ total+=sizeRaw; }
        });
        if(summary){
          if(count===0){ summary.textContent='暂无附件'; }
          else{
            const parts=['共 '+count+' 个附件'];
            if(total>0){ parts.push('总计 '+humanBytes(total)); }
            summary.textContent=parts.join(' · ');
          }
        }
        if(empty){ empty.hidden=count>0; }
      }
      function addAttachmentEntry(info){
        if(!info || !info.url) return;
        const list=document.getElementById('attachment-list');
        if(!list) return;
        const name=sanitizeAttachmentName(info.name || info.originalName || '附件');
        const mime=info.mime || '';
        const sizeValue=typeof info.size==='number'?info.size:parseInt(info.size,10);
        const row=document.createElement('div');
        row.className='attachment-row';
        if(info.id!=null) row.dataset.attachmentId=String(info.id);
        if(!Number.isNaN(sizeValue) && sizeValue>=0) row.dataset.size=String(sizeValue);
        if(mime) row.dataset.mime=mime;
        const main=document.createElement('div');
        main.className='attachment-main';
        const iconSpan=document.createElement('span');
        iconSpan.className='attachment-icon';
        iconSpan.textContent=attachmentIconForMime(mime, name);
        const infoBox=document.createElement('div');
        infoBox.className='attachment-info';
        const nameEl=document.createElement('div');
        nameEl.className='attachment-name';
        nameEl.textContent=name;
        const detailEl=document.createElement('div');
        detailEl.className='attachment-detail';
        const sizeText=!Number.isNaN(sizeValue) && sizeValue>0 ? humanBytes(sizeValue) : '';
        const detailParts=[];
        if(sizeText) detailParts.push(sizeText);
        if(mime) detailParts.push(mime);
        detailEl.textContent=detailParts.length?detailParts.join(' · '):'—';
        infoBox.appendChild(nameEl);
        infoBox.appendChild(detailEl);
        main.appendChild(iconSpan);
        main.appendChild(infoBox);
        const actions=document.createElement('div');
        actions.className='attachment-actions';
        const previewBtn=document.createElement('button');
        previewBtn.type='button';
        previewBtn.className='btn btn-primary btn-small attachment-preview-button';
        previewBtn.textContent='预览';
        const previewData={url:info.url,name,mime,size:!Number.isNaN(sizeValue)&&sizeValue>0?String(sizeValue):''};
        applyPreviewDataset(previewBtn,previewData);
        actions.appendChild(previewBtn);
        const downloadLink=document.createElement('a');
        downloadLink.className='btn btn-outline btn-small';
        downloadLink.href=info.url;
        downloadLink.setAttribute('download','');
        downloadLink.textContent='下载';
        actions.appendChild(downloadLink);
        row.appendChild(main);
        row.appendChild(actions);
        list.prepend(row);
        bindAttachmentPreviewTargets(row);
        updateAttachmentPanelSummary();
      }
      registerAttachmentPreviewReady(()=>bindAttachmentPreviewTargets());
      updateAttachmentPanelSummary();

      const state = { id: 0 };
      const saveTip = document.getElementById('save-tip');
      const saveButtonEl = document.querySelector('#new-form button[type="submit"]');
      const saveFeedback = createSaveFeedbackController(saveTip, saveButtonEl);
      const $ = s=>document.querySelector(s);
      const $$ = s=>Array.from(document.querySelectorAll(s));
      const throttle=(fn,ms)=>{let t=0;return (...a)=>{const n=Date.now();if(n-t>ms){t=n;fn(...a);} }};
      function createSaveFeedbackController(tipEl, buttonEl){
        const defaultLabel = buttonEl ? (buttonEl.dataset.defaultLabel || buttonEl.textContent || '保存') : '保存';
        if(buttonEl){ buttonEl.dataset.defaultLabel = defaultLabel; }
        let timer=null;
        const cleanupTimer=()=>{ if(timer){ clearTimeout(timer); timer=null; } };
        const showTip=(text,{dirty,show}={})=>{
          if(!tipEl) return;
          if(typeof text==='string'){ tipEl.textContent=text; }
          if(show===false){ tipEl.classList.remove('show'); }
          else if(show!==undefined || text){ tipEl.classList.add('show'); }
          if(dirty===true){ tipEl.classList.add('dirty'); }
          else if(dirty===false){ tipEl.classList.remove('dirty'); }
        };
        return {
          saving(message='保存中...', buttonLabel='⏳ 保存中...'){
            cleanupTimer();
            if(buttonEl){ buttonEl.disabled=true; buttonEl.textContent=buttonLabel || '⏳ 保存中...'; }
            showTip(message,{dirty:false,show:true});
          },
          success(message='保存成功', buttonLabel='✅ 保存成功'){
            cleanupTimer();
            if(buttonEl){ buttonEl.disabled=false; buttonEl.textContent=buttonLabel || '✅ 保存成功'; }
            showTip(message,{dirty:false,show:true});
            timer=setTimeout(()=>{
              if(buttonEl){ buttonEl.disabled=false; buttonEl.textContent=defaultLabel; }
              showTip('',{show:false,dirty:false});
              timer=null;
            },1500);
          },
          error(message='未保存', buttonLabel){
            cleanupTimer();
            if(buttonEl){ buttonEl.disabled=false; buttonEl.textContent=buttonLabel || defaultLabel; }
            showTip(message,{dirty:true,show:true});
          },
          dirty(message='未保存'){
            cleanupTimer();
            showTip(message,{dirty:true,show:true});
          },
          reset(){
            cleanupTimer();
            if(buttonEl){ buttonEl.disabled=false; buttonEl.textContent=defaultLabel; }
            showTip('',{show:false,dirty:false});
          }
        };
      }
      function safeHTML(md){ return DOMPurify.sanitize(marked.parse(md||'')); }
      const editorEl=document.getElementById('md-editor');
      function renderMD(){
        const view=document.getElementById('md-view');
        if(view){ view.innerHTML = safeHTML(editorEl?editorEl.value:''); }
      }
      function markDirty(){ saveFeedback.dirty('未保存'); }
      function insertTextAtCursor(textarea, text){
        if(!textarea) return;
        const start=textarea.selectionStart ?? textarea.value.length;
        const end=textarea.selectionEnd ?? textarea.value.length;
        const before=textarea.value.slice(0,start);
        const after=textarea.value.slice(end);
        textarea.value=before+text+after;
        const pos=start+text.length;
        if(typeof textarea.setSelectionRange==='function'){ textarea.setSelectionRange(pos,pos); }
        textarea.dispatchEvent(new Event('input', {bubbles:true}));
      }
      if(editorEl){
        editorEl.addEventListener('input', ()=>{ renderMD(); markDirty(); });
        editorEl.addEventListener('change', markDirty);
        renderMD();
      }
      ['#title','#cat'].forEach(sel=>{
        const el=$(sel);
        if(el){
          el.addEventListener('input', markDirty);
          el.addEventListener('change', markDirty);
        }
      });
      (async function bootstrap(){
        const res=await fetch(location.href,{method:'POST',headers:{'X-Requested-With':'fetch'},body:new URLSearchParams([['action','create_draft']])});
        const j=await res.json(); state.id=j.id; $('#timeline').dataset.item=String(state.id);
      })();
      async function saveAJAX(ev){
        ev.preventDefault();
        const fd=new FormData();
        fd.append('action','edit_item');
        fd.append('id', String(state.id));
        fd.append('title',$('#title').value.trim()||'未命名');
        fd.append('category_id',$('#cat').value);
        fd.append('description', editorEl ? editorEl.value : '');
        saveFeedback.saving();
        try{
          const r=await fetch(location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'fetch'}});
          if(!r.ok) throw new Error('保存失败');
          saveFeedback.success();
        }catch(err){
          alert(err.message||'保存失败');
          saveFeedback.error('未保存');
        }
        return false;
      }
      $('#btn-insert-att-item').addEventListener('click',()=>$('#att-file-item').click());
      $('#att-file-item').addEventListener('change', async (e)=>{
        const f=e.target.files[0]; if(!f) return;
        const fd=new FormData(); fd.append('action','upload_attachment'); fd.append('target','item'); fd.append('target_id', String(state.id)); fd.append('file', f);
        const res=await fetch(location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'fetch'}});
        const j=await res.json(); if(!j.ok){ alert(j.error||'上传失败'); return; }
        insertTextAtCursor(editorEl, j.markdown+"\n"); renderMD();
        addAttachmentEntry({id:j.id,url:j.url,mime:j.mime,name:f.name,size:j.size});
        e.target.value='';
      });
      $('#btn-preview-toggle').onclick=()=>{ const split=$('#split'); split.insertBefore(split.lastElementChild,split.firstElementChild); };
      function escapeHTML(s){ return (s||'').replace(/[&<>"']/g,m=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"'"}[m])); }
      function stepNodeHTML(s){
        const ts=new Date(s.created_at*1000).toISOString().slice(0,16).replace('T',' ');
        const checked=s.done? 'checked' : '';
        const safeTitle=escapeHTML(s.title||'');
        const notesHTML=s.notes?DOMPurify.sanitize(marked.parse(s.notes)):'<span class="placeholder-muted">无备注</span>';
        return `\
          <div class="tl-item ${s.done?'done':''}" draggable="true" data-id="${s.id}">\
            <span class="tl-dot"></span>\
            <div class="tl-head">\
              <span class="drag">⬍</span>\
              <label style="display:flex;align-items:center;margin:0">\
                <input type="checkbox" ${checked} onchange="toggleStep(${s.id}, this.checked)" title="完成">\
              </label>\
              <div class="tl-title" style="font-weight:700;flex:1">\
                <span class="js-step-title">${safeTitle}</span> <span class="ts">${ts}</span>\
              </div>\
              <details>\
                <summary>编辑</summary>\
                <div style="margin-top:6px;display:grid;gap:6px">\
                  <form onsubmit="return saveStepTitleAJAX(event, ${s.id}, this)" style="display:flex;gap:6px;flex-wrap:wrap">\
                    <input type="hidden" name="action" value="edit_step">\
                    <input type="hidden" name="id" value="${s.id}">\
                    <input name="title" value="${safeTitle}" style="padding:8px;border:1px solid var(--border);border-radius:8px;flex:1;min-width:180px">\
                    <button class="btn btn-outline" data-step-button="title-${s.id}">保存标题</button>\
                    <span class="save-tip" data-step-tip="title-${s.id}">保存成功</span>\
                  </form>\
                  <form onsubmit="return saveStepNotesAJAX(event, ${s.id})" id="form-notes-${s.id}">\
                    <input type="hidden" name="action" value="edit_step_notes">\
                    <input type="hidden" name="id" value="${s.id}">\
                    <textarea id="md-step-${s.id}" name="notes" style="min-height:120px">${escapeHTML(s.notes||'')}</textarea>\
                    <div style="display:flex;gap:8px;justify-content:space-between;align-items:center;margin-top:6px;flex-wrap:wrap">\
                      <div>\
                        <input id="att-file-step-${s.id}" type="file" accept="image/*,video/*,audio/*,application/pdf,application/zip,application/x-zip-compressed,application/x-rar-compressed,application/vnd.rar,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.ms-word.document.macroenabled.12,application/vnd.openxmlformats-officedocument.wordprocessingml.template,application/vnd.ms-word.template.macroenabled.12,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel.sheet.macroenabled.12,application/vnd.ms-excel.sheet.binary.macroenabled.12,application/vnd.openxmlformats-officedocument.spreadsheetml.template,application/vnd.ms-excel.template.macroenabled.12,text/plain,text/markdown,text/csv,text/tab-separated-values,application/json,text/json" style="display:none">\
                        <button class="btn btn-outline" type="button" onclick="insertAttachmentToStep(${s.id})">插入附件到备注</button>\
                        <span class="att-meta">图片、音视频、PDF、Word/Excel、ZIP/RAR 或文本 ≤ 15MB</span>\
                      </div>\
                      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;justify-content:flex-end">\
                        <button class="btn btn-primary" type="submit" data-step-button="notes-${s.id}">保存备注</button>\
                        <span class="save-tip" data-step-tip="notes-${s.id}">保存成功</span>\
                        <button class="btn btn-danger" type="button" data-step-button="delete-${s.id}" onclick="return deleteStep(${s.id}, this)">删除子任务</button>\
                        <span class="save-tip" data-step-tip="delete-${s.id}">已删除</span>\
                      </div>\
                    </div>\
                  </form>\
                </div>\
              </details>\
            </div>\
            <div class="md-body" id="step-md-view-${s.id}">${notesHTML}</div>\
          </div>\
        `;
      }
      async function addStepAJAX(ev){
        ev.preventDefault();
        const title=$('#new-step-title').value.trim(); if(!title) return false;
        const fd=new FormData(); fd.append('action','add_step'); fd.append('item_id', String(state.id)); fd.append('title', title);
        const r=await fetch(location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'fetch'}});
        const j=await r.json(); if(j.ok){ $('#new-step-title').value=''; $('#timeline').insertAdjacentHTML('beforeend', stepNodeHTML(j.step)); }
        return false;
      }
      function getStepFeedback(stepId, kind){
        const tip=document.querySelector(`[data-step-tip="${kind}-${stepId}"]`);
        const btn=document.querySelector(`[data-step-button="${kind}-${stepId}"]`);
        return createSaveFeedbackController(tip, btn);
      }
      async function saveStepTitleAJAX(ev, stepId, form){
        ev.preventDefault();
        const fd=new FormData(form);
        const feedback=getStepFeedback(stepId,'title');
        const titleInput=form.querySelector('input[name="title"]');
        const titleValue=titleInput ? titleInput.value.trim() : '';
        feedback.saving();
        try{
          const res=await fetch(location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'fetch'}});
          if(!res.ok) throw new Error('保存失败');
          const json=await res.json().catch(()=>({}));
          if(json && (json.ok===0 || json.ok==='0' || json.ok===false)){ throw new Error(json.error||'保存失败'); }
          const display=document.querySelector(`.tl-item[data-id="${stepId}"] .js-step-title`);
          if(display){ display.textContent=titleValue || '未命名'; }
          feedback.success();
        }catch(err){
          alert(err.message||'保存失败');
          feedback.error('未保存');
        }
        return false;
      }
      async function saveStepNotesAJAX(ev, stepId){
        ev.preventDefault();
        const f=$('#form-notes-'+stepId);
        if(!f) return false;
        const fd=new FormData(f);
        const ta=f.querySelector('textarea[name="notes"]');
        const feedback=getStepFeedback(stepId,'notes');
        feedback.saving();
        try{
          const r=await fetch(location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'fetch'}});
          if(!r.ok) throw new Error('保存失败');
          const json=await r.json().catch(()=>({}));
          if(json && (json.ok===0 || json.ok==='0' || json.ok===false)){ throw new Error(json.error||'保存失败'); }
          const val=ta?ta.value:'';
          $('#step-md-view-'+stepId).innerHTML = DOMPurify.sanitize(marked.parse(val));
          feedback.success();
        }catch(err){
          alert(err.message||'保存失败');
          feedback.error('未保存');
        }
        return false;
      }
      async function deleteStep(stepId, buttonEl){
        if(!confirm('确认删除该流程子任务？')) return false;
        const fd=new FormData(); fd.append('action','delete_step'); fd.append('id', stepId);
        const feedback=createSaveFeedbackController(
          document.querySelector(`[data-step-tip="delete-${stepId}"]`),
          buttonEl || document.querySelector(`[data-step-button="delete-${stepId}"]`)
        );
        feedback.saving('删除中...','⏳ 删除中...');
        try{
          const res=await fetch(location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'fetch'}});
          if(!res.ok) throw new Error('删除失败');
          const json=await res.json().catch(()=>({ok:1}));
          if(json && (json.ok===0 || json.ok==='0' || json.ok===false)){ throw new Error(json.error||'删除失败'); }
          feedback.success('已删除','✅ 已删除');
          const node=document.querySelector(`.tl-item[data-id="${stepId}"]`);
          if(node){ setTimeout(()=>{ node.remove(); }, 350); }
        }catch(err){
          alert(err.message||'删除失败');
          feedback.error('删除失败');
        }
        return false;
      }
      async function toggleStep(stepId, done){
        const fd=new FormData(); fd.append('action','toggle_step'); fd.append('id', stepId); fd.append('done', done?1:0);
        const r=await fetch(location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'fetch'}});
        if(r.ok){ const el=document.querySelector(`.tl-item[data-id="${stepId}"]`); if(el){ el.classList.toggle('done', !!done); } }
      }
      window.insertAttachmentToStep = async function(stepId){
        const inp=$('#att-file-step-'+stepId);
        if(!inp) return;
        inp.onchange=async e=>{
          const f=e.target.files[0]; if(!f) return;
          const fd=new FormData(); fd.append('action','upload_attachment'); fd.append('target','step'); fd.append('target_id', String(stepId)); fd.append('file', f);
          const r=await fetch(location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'fetch'}});
          const j=await r.json(); if(!j.ok){ alert(j.error||'上传失败'); return; }
          const textarea=document.getElementById('md-step-'+stepId);
          if(textarea){
            insertTextAtCursor(textarea, j.markdown+"\n");
            $('#step-md-view-'+stepId).innerHTML = DOMPurify.sanitize(marked.parse(textarea.value));
          }
          e.target.value='';
        };
        inp.click();
      };
      const timelineEl=document.getElementById('timeline');
      if(timelineEl){
        timelineEl.addEventListener('input', e=>{
          if(e.target && e.target.matches('textarea[name="notes"]')){
            const textarea=e.target;
            const id=textarea.id ? textarea.id.replace('md-step-','') : '';
            if(id){
              $('#step-md-view-'+id).innerHTML = DOMPurify.sanitize(marked.parse(textarea.value));
            }
            markDirty();
          }
        });
      }
      (function(){ // DnD steps
        const box=$('#timeline'); let dragging=null;
        box.addEventListener('dragstart',e=>{ const t=e.target.closest('.tl-item[draggable]'); if(!t) return; dragging=t; e.dataTransfer.effectAllowed='move'; });
        box.addEventListener('dragover', throttle(e=>{
          if(!dragging) return; e.preventDefault();
          const cand=$$('.tl-item[draggable]').filter(n=>n!==dragging); if(!cand.length) return;
          let best=null, dmin=1e9;
          for(const n of cand){ const r=n.getBoundingClientRect(); const cx=r.left+r.width/2, cy=r.top+r.height/2; const d=Math.hypot(e.clientX-cx,e.clientY-cy); if(d<dmin){ dmin=d; best=n; } }
          if(!best) return; const r=best.getBoundingClientRect(); const after=e.clientY > r.top + r.height/2; best.parentNode.insertBefore(dragging, after?best.nextSibling:best);
        }, 30));
        box.addEventListener('drop',e=>{
          e.preventDefault(); if(!dragging) return; dragging=null;
          const ids=$$('.tl-item[draggable]').map(x=>x.dataset.id).join(','); if(!ids) return;
          const fd=new FormData(); fd.append('action','reorder_steps'); fd.append('item_id', box.dataset.item); fd.append('order', ids);
          fetch(location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'fetch'}});
        });
      })();
    </script>
  </body>
  </html>
  <?php
  exit;
}

// 详情页
if ($view === 'item' && isset($_GET['id']) && ctype_digit((string)$_GET['id'])) {
  $it=get_item((int)$_GET['id']); if(!$it){ http_response_code(404); echo 'Not Found'; exit; }
  $steps=get_steps((int)$it['id']); $itemAtts=attachments_for_item((int)$it['id']); [$cats,$_counts]=get_categories();
  $doneView = $it['done'] ? 'done-view' : '';
  ?>
  <!doctype html>
  <html lang="zh-Hans">
  <head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <title>详情 · <?php echo h($it['title']); ?></title>
    <meta name="color-scheme" content="dark"/>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@500;600;700&family=Inter:wght@400;500;600&family=Noto+Sans+SC:wght@400;500;600;700&family=Noto+Serif+SC:wght@500;600;700&display=swap" rel="stylesheet">
    <style>
      :root{
        --bg-void:#0A0C0E;
        --bg-elev-1:#0F1316;
        --bg-elev-2:#151A1E;
        --gold-700:#AA8C54;
        --gold-600:#C9A86A;
        --gold-500:#D1B274;
        --gold-400:#E3C68B;
        --accent-emerald:#24C2A0;
        --accent-crimson:#D14B4B;
        --accent-cyan:#4BC3D1;
        --text-strong:#E8E5DF;
        --text-dim:#A7A39A;
        --text-muted:#7A766E;
        --divider:rgba(201,168,106,.18);
        --r-xs:6px;
        --r-sm:10px;
        --r-md:14px;
        --r-lg:18px;
        --shadow-1:0 8px 24px rgba(0,0,0,.5),0 0 24px rgba(227,198,139,.12);
        --shadow-2:0 16px 48px rgba(0,0,0,.6) inset,0 0 1px rgba(201,168,106,.35);
        --t-fast:200ms;
        --t:300ms;
        --t-slow:450ms;
        --ease:cubic-bezier(.22,.61,.36,1);
        --bg:var(--bg-void);
        --panel:rgba(21,26,30,.9);
        --panel-strong:rgba(15,19,22,.94);
        --glow:var(--gold-500);
        --glow-soft:rgba(227,198,139,.2);
        --glow-strong:rgba(227,198,139,.38);
        --text:var(--text-strong);
        --border:rgba(201,168,106,.36);
        --border-soft:rgba(201,168,106,.18);
        --danger:var(--accent-crimson);
        --danger-soft:rgba(209,75,75,.32);
        --grid-size:64px;
        --transition:var(--t) var(--ease);
      }
      *,*::before,*::after{box-sizing:border-box}
      html,body{margin:0;min-height:100vh;color:var(--text-strong);background:var(--bg-void);font:16px/1.65 'Source Han Sans','Noto Sans SC','Inter','Microsoft YaHei',sans-serif;letter-spacing:.01em;position:relative;overflow-x:hidden}
      body{background:
        radial-gradient(1200px 700px at 70% -10%,rgba(227,198,139,.06),transparent 60%),
        linear-gradient(120deg,rgba(201,168,106,.08),transparent 30%,rgba(201,168,106,.06) 70%,transparent 90%),
        #0A0C0E;
      }
      body::before{content:"";position:fixed;inset:0;background:
        linear-gradient(180deg,rgba(10,12,14,.45),rgba(10,12,14,.85)),
        url('data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" width="160" height="160" viewBox="0 0 160 160"%3E%3Cpath fill="rgba(201,168,106,0.05)" d="M0 79h160v2H0zm79-79h2v160h-2z"/%3E%3C/svg%3E');
        background-size:cover,160px 160px;opacity:.55;pointer-events:none;z-index:-2;
      }
      body::after{content:"";position:fixed;inset:0;background:
        repeating-linear-gradient(0deg,rgba(75,195,209,.08) 0,rgba(75,195,209,.08) 1px,transparent 1px,transparent var(--grid-size)),
        repeating-linear-gradient(90deg,rgba(201,168,106,.12) 0,rgba(201,168,106,.12) 1px,transparent 1px,transparent var(--grid-size));
        background-size:var(--grid-size) var(--grid-size);
        mix-blend-mode:screen;opacity:.28;pointer-events:none;z-index:-3;background-attachment:fixed;
      }
      .scanlines{position:fixed;inset:0;pointer-events:none;z-index:-1;background:linear-gradient(to bottom,rgba(75,195,209,.14) 0,transparent 4px);background-size:100% 6px;opacity:.18}
      a{color:inherit;text-decoration:none}
      h1,h2,h3,h4{font-family:'Cinzel','Noto Serif SC',serif;color:var(--gold-400);letter-spacing:-0.5px;text-transform:uppercase}
      .wrap{max-width:1180px;margin:0 auto;padding:32px 24px 64px;position:relative;z-index:0}
      .wrap::before{content:"";position:absolute;inset:16px;border-radius:var(--r-lg);border:1px dashed rgba(201,168,106,.2);opacity:.6;pointer-events:none}
      .btn{position:relative;display:inline-flex;align-items:center;justify-content:center;padding:10px 18px;border-radius:var(--r-sm);border:1px solid rgba(227,198,139,.65);background:linear-gradient(135deg,rgba(227,198,139,.82),rgba(170,140,84,.62));color:#1b1306;cursor:pointer;text-transform:uppercase;font:600 13px/1.2 'Inter','Noto Sans SC',sans-serif;letter-spacing:.12em;transition:transform var(--transition),box-shadow var(--transition),border-color var(--transition);box-shadow:0 14px 32px rgba(227,198,139,.25),0 0 24px rgba(227,198,139,.18);overflow:hidden}
      .btn::after{content:none}
      .btn:hover{transform:translateY(-2px);box-shadow:0 18px 36px rgba(227,198,139,.3),0 0 28px rgba(227,198,139,.24)}
      .btn:active{transform:translateY(0);box-shadow:0 8px 20px rgba(227,198,139,.22)}
      .btn.acc{background:linear-gradient(135deg,rgba(227,198,139,.92),rgba(201,168,106,.72));color:#120d05}
      .btn.danger{color:#2b0909;background:linear-gradient(135deg,rgba(255,156,156,.85),rgba(209,75,75,.75));border-color:rgba(255,156,156,.8);box-shadow:0 14px 30px rgba(209,75,75,.25)}
      .btn:focus-visible{outline:2px solid var(--accent-cyan);outline-offset:3px;box-shadow:0 0 0 2px rgba(227,198,139,.25)}
      .card{position:relative;background:linear-gradient(180deg,rgba(21,26,30,.9),rgba(15,19,22,.94));border:1px solid rgba(201,168,106,.32);border-radius:24px;padding:28px;box-shadow:var(--shadow-1);backdrop-filter:blur(16px)}
      .card::before{content:"";position:absolute;inset:12px;border-radius:calc(24px - 6px);box-shadow:inset 0 0 0 1px rgba(227,198,139,.18),inset 0 0 40px rgba(227,198,139,.1);pointer-events:none}
      .title{font:600 26px/1.35 'Cinzel','Noto Serif SC',serif;margin:0 0 10px;color:var(--gold-400);letter-spacing:.02em;text-transform:uppercase}
      .meta{color:var(--text-muted);font:500 13px/1.6 'Inter','Noto Sans SC',sans-serif;letter-spacing:.14em;text-transform:uppercase}
      .split{display:grid;grid-template-columns:minmax(320px,1fr) minmax(320px,1fr);gap:24px;align-items:start}
      @media (max-width:920px){
        .split{grid-template-columns:1fr}
        .btn{padding:12px 18px}
      }
      .editbox,.preview{position:relative;background:linear-gradient(180deg,rgba(21,26,30,.9),rgba(15,19,22,.94));border:1px solid rgba(201,168,106,.3);border-radius:var(--r-md);padding:20px;min-height:280px;box-shadow:var(--shadow-1)}
      .editbox::before,.preview::before{content:"";position:absolute;inset:10px;border-radius:calc(var(--r-md) - 4px);border:1px solid rgba(201,168,106,.2);opacity:.6;pointer-events:none}
      .editbox input,
      .editbox select,
      .editbox textarea{width:100%;padding:12px 14px;border-radius:var(--r-sm);border:1px solid rgba(201,168,106,.32);background:rgba(12,16,18,.72);color:var(--text-strong);font:500 15px/1.5 'Noto Sans SC','Inter',sans-serif;letter-spacing:.03em;transition:border-color var(--transition),box-shadow var(--transition)}
      .editbox input::placeholder,
      .editbox textarea::placeholder{color:var(--text-muted)}
      .editbox input:focus,
      .editbox select:focus,
      .editbox textarea:focus{border-color:var(--gold-500);box-shadow:0 0 0 3px rgba(227,198,139,.16),inset 0 0 0 1px rgba(227,198,139,.22);outline:none}
      .editbox textarea{min-height:260px;border-radius:var(--r-md)}
      .preview{max-height:60vh;overflow:auto;background-image:linear-gradient(120deg,rgba(201,168,106,.06),transparent 55%)}
      .preview::-webkit-scrollbar{width:8px}
      .preview::-webkit-scrollbar-thumb{background:rgba(201,168,106,.28);border-radius:999px}
      .md-body{color:var(--text-dim)}
      .md-body img{max-width:100%;height:auto;border:1px solid rgba(201,168,106,.32);border-radius:var(--r-sm);box-shadow:0 18px 36px rgba(0,0,0,.55),0 0 28px rgba(227,198,139,.12)}
      .section{margin-top:28px;padding-top:20px;border-top:1px solid var(--divider)}
      .section h2{margin:0 0 12px;font:600 18px/1.4 'Cinzel','Noto Serif SC',serif;color:var(--gold-400);letter-spacing:.08em}
      .section h3{margin:12px 0 10px;font:600 15px/1.4 'Inter','Noto Sans SC',sans-serif;color:var(--text-strong);text-transform:uppercase;letter-spacing:.14em}
      .section label{display:block;font:600 12px/1.4 'Inter','Noto Sans SC',sans-serif;letter-spacing:.16em;margin-bottom:8px;color:var(--text-muted);text-transform:uppercase}
      .status-pill{display:inline-flex;align-items:center;gap:6px;border-radius:999px;border:1px solid rgba(201,168,106,.36);background:rgba(12,16,18,.82);padding:4px 14px;color:var(--text-dim);font:600 12px/1 'Inter','Noto Sans SC',sans-serif;text-transform:uppercase;letter-spacing:.2em}
      .status-pill::before{content:"";width:6px;height:6px;border-radius:50%;background:var(--gold-500);box-shadow:0 0 8px rgba(227,198,139,.4)}
      .attachment-panel{display:flex;flex-direction:column;gap:16px}
      .attachment-panel-header{display:flex;align-items:center;justify-content:space-between;gap:12px}
      .attachment-panel-title{font:600 14px/1.2 'Inter','Noto Sans SC',sans-serif;letter-spacing:.16em;text-transform:uppercase;color:var(--gold-400)}
      .attachment-panel-meta{color:var(--text-muted);font:12px/1.4 'Inter','Noto Sans SC',sans-serif;letter-spacing:.1em}
      .attachment-empty{padding:18px;border:1px dashed rgba(201,168,106,.28);border-radius:16px;background:rgba(12,16,18,.66);color:var(--text-muted);text-align:center;font:500 13px/1.6 'Inter','Noto Sans SC',sans-serif;letter-spacing:.06em}
      .attachment-list{display:grid;gap:12px}
      .attachment-row{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:14px 16px;border-radius:16px;border:1px solid rgba(201,168,106,.26);background:rgba(12,16,18,.78);box-shadow:var(--shadow-1)}
      .attachment-main{display:flex;align-items:center;gap:12px;min-width:0;flex:1}
      .attachment-icon{font-size:26px;line-height:1}
      .attachment-info{display:grid;gap:4px;min-width:0}
      .attachment-name{font:600 14px/1.4 'Inter','Noto Sans SC',sans-serif;color:var(--text-strong);letter-spacing:.04em;word-break:break-all}
      .attachment-detail{color:var(--text-muted);font:12px/1.4 'Inter','Noto Sans SC',sans-serif;letter-spacing:.08em}
      .attachment-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
      .attachment-actions form{margin:0}
      .timeline{position:relative;margin-top:22px;margin-left:18px;padding-left:34px;color:var(--text-muted)}
      .timeline::before{content:"";position:absolute;left:12px;top:0;bottom:0;width:2px;background:linear-gradient(180deg,rgba(201,168,106,.58),rgba(201,168,106,.12));box-shadow:0 0 18px rgba(227,198,139,.24)}
      .tl-item{position:relative;margin:14px 0;padding:16px 20px 20px 26px;border:1px solid rgba(201,168,106,.34);border-radius:var(--r-md);background:linear-gradient(180deg,rgba(21,26,30,.9),rgba(15,19,22,.94));box-shadow:var(--shadow-1)}
      .tl-item::before{content:"";position:absolute;inset:8px;border-radius:calc(var(--r-md) - 4px);border:1px dashed rgba(201,168,106,.2);pointer-events:none;opacity:.7}
      .tl-item .tl-dot{position:absolute;left:-28px;top:22px;width:16px;height:16px;border-radius:50%;background:linear-gradient(135deg,rgba(201,168,106,.92),rgba(170,140,84,.9));box-shadow:0 0 24px rgba(227,198,139,.32)}
      .tl-item .tl-dot::after{content:"";position:absolute;inset:-6px;border-radius:inherit;border:1px dashed rgba(201,168,106,.45);opacity:.85;box-shadow:0 0 18px rgba(227,198,139,.24)}
      .tl-head{display:flex;gap:12px;align-items:center;color:var(--text-strong);font-family:'Inter','Noto Sans SC',sans-serif}
      .tl-item.done .tl-head div,.tl-item.done .md-body{opacity:.72;text-decoration:line-through}
      .tl-item.done{background:linear-gradient(160deg,rgba(36,194,160,.18),rgba(15,22,20,.92));border-color:rgba(36,194,160,.42);box-shadow:0 0 28px rgba(36,194,160,.28)}
      .drag{cursor:grab;color:var(--text-muted);user-select:none;font-size:18px}
      .ts{color:var(--text-dim);font:12px/1 'Inter','Noto Sans SC',sans-serif;margin-left:6px;letter-spacing:.12em}
      details summary{cursor:pointer;color:var(--text-muted);text-transform:uppercase;letter-spacing:.16em;font:500 12px/1.4 'Inter','Noto Sans SC',sans-serif}
      .save-tip{color:var(--gold-400);font:12px/1 'Inter','Noto Sans SC',sans-serif;display:none;margin-left:10px;text-transform:uppercase;letter-spacing:.18em}
      .save-tip.dirty{color:var(--accent-crimson)}
      .save-tip.show{display:inline-block}
      .placeholder-muted{color:var(--text-muted)}
      .toast{position:fixed;top:18px;right:18px;min-width:220px;padding:14px 18px;border-radius:var(--r-sm);border:1px solid rgba(201,168,106,.34);background:rgba(12,16,18,.9);color:var(--text-strong);box-shadow:0 20px 44px rgba(0,0,0,.58);display:flex;gap:12px;align-items:flex-start;backdrop-filter:blur(18px);z-index:10}
      .toast.error{border-color:rgba(209,75,75,.52);color:#F6D6D6;box-shadow:0 22px 46px rgba(209,75,75,.28)}
      .toast .icon{font-size:18px;color:var(--gold-400)}
      .toast.error .icon{color:var(--accent-crimson)}
      .toast .body{flex:1;font:14px/1.6 'Inter','Noto Sans SC',sans-serif;letter-spacing:.08em}
      .toast button{background:none;border:0;color:inherit;cursor:pointer}
      .attachment-preview-backdrop{position:fixed;inset:0;display:none;align-items:center;justify-content:center;padding:20px;background:rgba(5,7,10,.78);backdrop-filter:blur(18px);z-index:180}
      .attachment-preview-backdrop[data-open="true"]{display:flex}
      .attachment-preview-panel{width:min(920px,calc(100vw - 32px));max-height:min(90vh,96svh);background:linear-gradient(165deg,rgba(21,26,30,.95),rgba(12,16,18,.92));border:1px solid rgba(201,168,106,.34);border-radius:24px;box-shadow:0 32px 64px rgba(0,0,0,.68),0 0 34px rgba(227,198,139,.2);padding:20px;display:flex;flex-direction:column;gap:16px;position:relative}
      .attachment-preview-header{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}
      .attachment-preview-title{margin:0;font:600 18px/1.2 'Cinzel','Noto Serif SC',serif;color:var(--gold-400);letter-spacing:.16em;text-transform:uppercase}
      .attachment-preview-meta{color:var(--text-muted);font:12px/1.5 'Inter','Noto Sans SC',sans-serif;letter-spacing:.1em}
      .attachment-preview-close{border:1px solid rgba(201,168,106,.32);background:rgba(15,19,22,.8);color:var(--gold-400);border-radius:50%;width:36px;height:36px;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:18px;transition:border-color var(--transition),transform var(--transition)}
      .attachment-preview-close:hover{border-color:rgba(227,198,139,.6);transform:translateY(-1px)}
      .attachment-preview-body{position:relative;flex:1 1 auto;min-height:220px;padding:16px;border-radius:18px;border:1px solid rgba(201,168,106,.26);background:rgba(12,16,18,.82);box-shadow:inset 0 0 22px rgba(0,0,0,.45);overflow:auto}
      .attachment-preview-body img,.attachment-preview-body video,.attachment-preview-body iframe{display:block;width:100%;height:100%;max-height:70vh;border-radius:14px;background:#050607}
      .attachment-preview-body video{background:#000}
      .attachment-preview-body iframe{border:0;min-height:60vh}
      .attachment-preview-loading{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:var(--text-muted);font:14px/1.6 'Inter','Noto Sans SC',sans-serif;letter-spacing:.08em}
      .attachment-preview-error{color:#fca5a5;font:14px/1.6 'Inter','Noto Sans SC',sans-serif;padding:18px;text-align:center}
      .attachment-preview-list{list-style:none;margin:0;padding:0;display:grid;gap:8px}
      .attachment-preview-list li{padding:10px 12px;border-radius:12px;border:1px solid rgba(201,168,106,.28);background:rgba(12,16,18,.78);font:13px/1.5 'Inter','Noto Sans SC',sans-serif;letter-spacing:.04em;color:var(--text-strong);display:flex;justify-content:space-between;gap:12px;align-items:center}
      .attachment-preview-list li .entry-size{color:var(--text-muted);font-size:12px;letter-spacing:.08em}
      .attachment-preview-tree{list-style:none;margin:0;padding:0;display:grid;gap:6px}
      .attachment-preview-tree ul{list-style:none;margin:6px 0 0;padding-left:18px;display:grid;gap:6px}
      .attachment-preview-tree summary{cursor:pointer;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 12px;border-radius:12px;border:1px solid rgba(201,168,106,.28);background:rgba(12,16,18,.78);font:600 13px/1.4 'Inter','Noto Sans SC',sans-serif;letter-spacing:.08em;color:var(--text-strong)}
      .attachment-preview-tree summary::-webkit-details-marker{display:none}
      .attachment-preview-tree .tree-entry{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 12px;border-radius:12px;border:1px solid rgba(201,168,106,.26);background:rgba(12,16,18,.78);font:13px/1.5 'Inter','Noto Sans SC',sans-serif;letter-spacing:.04em;color:var(--text-strong)}
      .attachment-preview-tree .entry-label{display:flex;align-items:center;gap:8px;min-width:0}
      .attachment-preview-tree .entry-size{color:var(--text-muted);font-size:12px;letter-spacing:.08em}
      .attachment-preview-tree summary .entry-size{font-weight:500}
      .attachment-docx{color:var(--text-strong);font:400 14px/1.75 'Noto Sans SC','Inter',sans-serif;display:grid;gap:14px}
      .attachment-docx h1,.attachment-docx h2,.attachment-docx h3,.attachment-docx h4{color:var(--gold-400);margin:12px 0 6px;font-weight:600}
      .attachment-docx table{width:100%;border-collapse:collapse;margin:12px 0;border:1px solid rgba(201,168,106,.24)}
      .attachment-docx th,.attachment-docx td{border:1px solid rgba(201,168,106,.24);padding:6px 8px}
      .attachment-docx a{color:var(--gold-400)}
      .attachment-excel{display:grid;gap:16px}
      .attachment-excel .excel-sheet{border:1px solid rgba(201,168,106,.28);border-radius:14px;background:rgba(12,16,18,.78);padding:14px;box-shadow:inset 0 0 18px rgba(0,0,0,.36)}
      .attachment-excel .excel-sheet h3{margin:0 0 10px;font:600 14px/1.4 'Inter','Noto Sans SC',sans-serif;color:var(--gold-400);letter-spacing:.1em;text-transform:uppercase}
      .attachment-excel .excel-table{overflow:auto;border-radius:10px;border:1px solid rgba(201,168,106,.24)}
      .attachment-excel table{width:100%;border-collapse:collapse;min-width:320px;font:13px/1.45 'Inter','Noto Sans SC',sans-serif;color:var(--text-strong);background:rgba(12,16,18,.88)}
      .attachment-excel th,.attachment-excel td{border:1px solid rgba(201,168,106,.2);padding:6px 8px;text-align:left}
      .attachment-excel tbody tr:nth-child(even){background:rgba(201,168,106,.08)}
      .attachment-preview-tree{list-style:none;margin:0;padding:0;display:grid;gap:6px}
      .attachment-preview-tree ul{list-style:none;margin:6px 0 0;padding-left:18px;display:grid;gap:6px}
      .attachment-preview-tree summary{cursor:pointer;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 12px;border-radius:12px;border:1px solid rgba(201,168,106,.28);background:rgba(12,16,18,.78);font:600 13px/1.4 'Inter','Noto Sans SC',sans-serif;letter-spacing:.08em;color:var(--text-strong)}
      .attachment-preview-tree summary::-webkit-details-marker{display:none}
      .attachment-preview-tree .tree-entry{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 12px;border-radius:12px;border:1px solid rgba(201,168,106,.26);background:rgba(12,16,18,.78);font:13px/1.5 'Inter','Noto Sans SC',sans-serif;letter-spacing:.04em;color:var(--text-strong)}
      .attachment-preview-tree .entry-label{display:flex;align-items:center;gap:8px;min-width:0}
      .attachment-preview-tree .entry-size{color:var(--text-muted);font-size:12px;letter-spacing:.08em}
      .attachment-preview-tree summary .entry-size{font-weight:500}
      .attachment-docx{color:var(--text-strong);font:400 14px/1.75 'Noto Sans SC','Inter',sans-serif;display:grid;gap:14px}
      .attachment-docx h1,.attachment-docx h2,.attachment-docx h3,.attachment-docx h4{color:var(--gold-400);margin:12px 0 6px;font-weight:600}
      .attachment-docx table{width:100%;border-collapse:collapse;margin:12px 0;border:1px solid rgba(201,168,106,.24)}
      .attachment-docx th,.attachment-docx td{border:1px solid rgba(201,168,106,.24);padding:6px 8px}
      .attachment-docx a{color:var(--gold-400)}
      .attachment-excel{display:grid;gap:16px}
      .attachment-excel .excel-sheet{border:1px solid rgba(201,168,106,.28);border-radius:14px;background:rgba(12,16,18,.78);padding:14px;box-shadow:inset 0 0 18px rgba(0,0,0,.36)}
      .attachment-excel .excel-sheet h3{margin:0 0 10px;font:600 14px/1.4 'Inter','Noto Sans SC',sans-serif;color:var(--gold-400);letter-spacing:.1em;text-transform:uppercase}
      .attachment-excel .excel-table{overflow:auto;border-radius:10px;border:1px solid rgba(201,168,106,.24)}
      .attachment-excel table{width:100%;border-collapse:collapse;min-width:320px;font:13px/1.45 'Inter','Noto Sans SC',sans-serif;color:var(--text-strong);background:rgba(12,16,18,.88)}
      .attachment-excel th,.attachment-excel td{border:1px solid rgba(201,168,106,.2);padding:6px 8px;text-align:left}
      .attachment-excel tbody tr:nth-child(even){background:rgba(201,168,106,.08)}
      .attachment-preview-footer{display:flex;justify-content:flex-end;gap:10px;flex-wrap:wrap}
      .attachment-preview-download{display:inline-flex;align-items:center;justify-content:center;padding:10px 16px;border-radius:12px;border:1px solid rgba(201,168,106,.36);background:rgba(15,19,22,.85);color:var(--gold-400);font:600 12px/1 'Inter','Noto Sans SC',sans-serif;letter-spacing:.14em;text-transform:uppercase;cursor:pointer;text-decoration:none;transition:transform var(--transition),box-shadow var(--transition)}
      .attachment-preview-download:hover{transform:translateY(-1px);box-shadow:0 16px 32px rgba(227,198,139,.25)}
    </style>

  </head>
  <body>
    <div class="scanlines" aria-hidden="true"></div>
    <div class="wrap">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;gap:8px;flex-wrap:wrap">
        <a class="btn btn-ghost" href="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>">← 返回首页</a>
        <form method="post" onsubmit="return confirm('确认删除？');">
          <input type="hidden" name="action" value="delete_item"><input type="hidden" name="id" value="<?php echo $it['id']; ?>">
          <button class="btn btn-danger">删除</button>
        </form>
      </div>
      <div class="card <?php echo $doneView; ?>">
        <h1 class="title"><?php echo $it['done']?'✅ ':''; echo h($it['title']); ?></h1>
        <div class="meta">
          分类：<?php echo h($it['cat_name'] ?? '未分类'); ?> ·
          创建：<?php echo dt((int)$it['created_at']); ?> ·
          更新：<?php echo dt((int)$it['updated_at']); ?>
        </div>
        <div class="split" style="margin-top:12px">
          <div class="editbox">
            <form method="post" id="item-form" onsubmit="return saveItemAJAX(event,this)">
              <input type="hidden" name="action" value="edit_item">
              <input type="hidden" name="id" value="<?php echo $it['id']; ?>">
              <div style="display:grid;grid-template-columns:2fr 1fr auto;gap:12px;margin-bottom:16px;align-items:center">
                <input name="title" value="<?php echo h($it['title']); ?>" required>
                <select name="category_id">
                  <option value="">未分类 · Unassigned</option>
                  <?php foreach ($cats as $c): ?>
                    <option value="<?php echo $c['id']; ?>" <?php echo ($it['category_id']==$c['id']?'selected':''); ?>><?php echo h($c['name']); ?></option>
                  <?php endforeach; ?>
                </select>
                <div style="display:flex;gap:8px;align-items:center">
                  <button class="btn btn-primary" type="submit">保存</button><span id="save-tip" class="save-tip">保存成功</span>
                </div>
              </div>
              <textarea id="md-editor" name="description"><?php echo h($it['description']); ?></textarea>
                <div style="display:flex;gap:12px;justify-content:space-between;align-items:center;margin-top:14px;flex-wrap:wrap">
                <div>
                  <input id="att-file-item" type="file" accept="image/*,video/*,audio/*,application/pdf,application/zip,application/x-zip-compressed,application/x-rar-compressed,application/vnd.rar,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.ms-word.document.macroenabled.12,application/vnd.openxmlformats-officedocument.wordprocessingml.template,application/vnd.ms-word.template.macroenabled.12,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel.sheet.macroenabled.12,application/vnd.ms-excel.sheet.binary.macroenabled.12,application/vnd.openxmlformats-officedocument.spreadsheetml.template,application/vnd.ms-excel.template.macroenabled.12,text/plain,text/markdown,text/csv,text/tab-separated-values,application/json,text/json" style="display:none">
                  <button class="btn btn-ghost" type="button" id="btn-insert-att-item">插入附件到备注</button>
                  <span class="att-meta">图片、音视频、PDF、Word/Excel、ZIP/RAR 或文本 ≤ 15MB</span>
                </div>
              </div>
            </form>
          </div>
          <?php
            $attachmentCount=count($itemAtts);
            $attachmentTotalSize=0;
            foreach($itemAtts as $attMeta){ $attachmentTotalSize+=(int)($attMeta['size'] ?? 0); }
            $attachmentSummaryText=$attachmentCount
              ? ('共 '.$attachmentCount.' 个附件'.($attachmentTotalSize>0 ? ' · 总计 '.bytes_h($attachmentTotalSize) : ''))
              : '暂无附件';
          ?>
          <div class="preview attachment-panel">
            <div class="attachment-panel-header">
              <div class="attachment-panel-title">附件列表</div>
              <div class="attachment-panel-meta" id="attachment-summary"><?php echo h($attachmentSummaryText); ?></div>
            </div>
            <div class="attachment-empty" id="attachment-empty" <?php echo $attachmentCount ? 'hidden' : ''; ?>>暂无附件。上传后将在此管理与预览。</div>
            <div class="attachment-list" id="attachment-list">
              <?php if ($itemAtts): foreach ($itemAtts as $a):
                $mime=(string)$a['mime'];
                $ext=strtolower(pathinfo((string)$a['orig_name'], PATHINFO_EXTENSION));
                $isImg=str_starts_with($mime,'image/');
                $isVideo=str_starts_with($mime,'video/');
                $isAudio=str_starts_with($mime,'audio/');
                $isPdf=$mime==='application/pdf' || $ext==='pdf';
                $isZip=str_contains($mime,'zip') || $ext==='zip';
                $isRar=str_contains($mime,'rar') || $ext==='rar';
                $isWord=in_array($ext,['docx','docm','doc','dotx','dotm'],true) || str_contains($mime,'word');
                $isExcel=in_array($ext,['xlsx','xls','xlsm','xlsb','csv','xltx','xltm','tsv'],true) || str_contains($mime,'spreadsheet');
                $icon=$isImg?'🖼':($isVideo?'🎬':($isAudio?'🎧':($isPdf?'📄':($isExcel?'📊':($isWord?'📝':($isZip?'🗜':($isRar?'📦':'📎')))))));
                $downloadUrl='?download='.$a['id'];
                $sizeBytes=(int)$a['size'];
                $sizeLabel=$sizeBytes>0?bytes_h($sizeBytes):'';
                $detailParts=[];
                if($sizeLabel!=='') $detailParts[]=$sizeLabel;
                if($mime!=='') $detailParts[]=$mime;
                $detailText=$detailParts?implode(' · ',$detailParts):'—';
                $previewAttrs='data-attachment-preview="true" data-url="'.h($downloadUrl).'" data-name="'.h($a['orig_name']).'" data-mime="'.h($mime).'"';
                if($sizeBytes>0){ $previewAttrs.=' data-size="'.$sizeBytes.'"'; }
              ?>
              <div class="attachment-row" data-attachment-id="<?php echo $a['id']; ?>" data-size="<?php echo $sizeBytes; ?>" data-mime="<?php echo h($mime); ?>">
                <div class="attachment-main">
                  <span class="attachment-icon"><?php echo $icon; ?></span>
                  <div class="attachment-info">
                    <div class="attachment-name"><?php echo h($a['orig_name']); ?></div>
                    <div class="attachment-detail"><?php echo h($detailText); ?></div>
                  </div>
                </div>
                <div class="attachment-actions">
                  <button class="btn btn-primary btn-small attachment-preview-button" type="button" <?php echo $previewAttrs; ?>>预览</button>
                  <a class="btn btn-outline btn-small" href="<?php echo $downloadUrl; ?>" download>下载</a>
                  <form method="post">
                    <input type="hidden" name="action" value="delete_attachment">
                    <input type="hidden" name="id" value="<?php echo $a['id']; ?>">
                    <button class="btn btn-danger btn-small" style="font-size:12px">删除</button>
                  </form>
                </div>
              </div>
              <?php endforeach; endif; ?>
            </div>
          </div>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;margin:12px 0 6px">
          <div style="font-weight:800">流程子任务（时间轴）</div>
          <form method="post" style="display:flex;gap:6px;flex-wrap:wrap" onsubmit="return addStepForm(event)">
            <input type="hidden" name="action" value="add_step">
            <input type="hidden" name="item_id" value="<?php echo $it['id']; ?>">
            <input id="new-step-title" name="title" placeholder="新增步骤 · Add step" style="padding:12px;border:1px solid var(--border);border-radius:12px;background:rgba(6,25,14,.82);color:var(--text);letter-spacing:.08em;flex:1;min-width:220px">
            <button class="btn btn-primary">添加</button>
          </form>
        </div>
        <div class="timeline" id="timeline" data-item="<?php echo $it['id']; ?>">
          <?php foreach ($steps as $s): ?>
            <div class="tl-item <?php echo $s['done']?'done':''; ?>" draggable="true" data-id="<?php echo $s['id']; ?>">
              <span class="tl-dot"></span>
              <div class="tl-head">
                <span class="drag">⬍</span>
                <label style="display:flex;align-items:center;margin:0">
                  <input type="checkbox" <?php echo $s['done']?'checked':''; ?> onchange="toggleStep(<?php echo $s['id']; ?>, this.checked)" title="完成">
                </label>
                <div class="tl-title" style="font-weight:700;flex:1">
                  <span class="js-step-title"><?php echo h($s['title']); ?></span> <span class="ts"><?php echo dt((int)$s['created_at']); ?></span>
                </div>
                <details>
                  <summary>编辑</summary>
                  <div style="margin-top:6px;display:grid;gap:6px">
                    <form method="post" onsubmit="return saveStepTitleAJAX(event, <?php echo $s['id']; ?>, this)" style="display:flex;gap:6px;flex-wrap:wrap">
                      <input type="hidden" name="action" value="edit_step">
                      <input type="hidden" name="id" value="<?php echo $s['id']; ?>">
                      <input name="title" value="<?php echo h($s['title']); ?>" style="padding:8px;border:1px solid var(--border);border-radius:8px;flex:1;min-width:180px">
                      <button class="btn btn-outline" data-step-button="title-<?php echo $s['id']; ?>">保存标题</button>
                      <span class="save-tip" data-step-tip="title-<?php echo $s['id']; ?>">保存成功</span>
                    </form>
                    <form method="post" onsubmit="return saveStepNotesAJAX(event, <?php echo $s['id']; ?>)" id="form-notes-<?php echo $s['id']; ?>">
                      <input type="hidden" name="action" value="edit_step_notes">
                      <input type="hidden" name="id" value="<?php echo $s['id']; ?>">
                      <textarea id="md-step-<?php echo $s['id']; ?>" name="notes" style="min-height:120px"><?php echo h($s['notes'] ?? ''); ?></textarea>
                      <div style="display:flex;gap:8px;justify-content:space-between;align-items:center;margin-top:6px;flex-wrap:wrap">
                        <div>
                          <input id="att-file-step-<?php echo $s['id']; ?>" type="file" accept="image/*,video/*,audio/*,application/pdf,application/zip,application/x-zip-compressed,application/x-rar-compressed,application/vnd.rar,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.ms-word.document.macroenabled.12,application/vnd.openxmlformats-officedocument.wordprocessingml.template,application/vnd.ms-word.template.macroenabled.12,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel.sheet.macroenabled.12,application/vnd.ms-excel.sheet.binary.macroenabled.12,application/vnd.openxmlformats-officedocument.spreadsheetml.template,application/vnd.ms-excel.template.macroenabled.12,text/plain,text/markdown,text/csv,text/tab-separated-values,application/json,text/json" style="display:none">
                          <button class="btn btn-outline" type="button" onclick="insertAttachmentToStep(<?php echo $s['id']; ?>)">插入附件到备注</button>
                          <span class="att-meta">图片、音视频、PDF、Word/Excel、ZIP/RAR 或文本 ≤ 15MB</span>
                        </div>
                        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;justify-content:flex-end">
                          <button class="btn btn-primary" type="submit" data-step-button="notes-<?php echo $s['id']; ?>">保存备注</button>
                          <span class="save-tip" data-step-tip="notes-<?php echo $s['id']; ?>">保存成功</span>
                          <button class="btn btn-danger" type="button" data-step-button="delete-<?php echo $s['id']; ?>" onclick="return deleteStep(<?php echo $s['id']; ?>, this)">删除子任务</button>
                          <span class="save-tip" data-step-tip="delete-<?php echo $s['id']; ?>">已删除</span>
                        </div>
                      </div>
                    </form>
                  </div>
                </details>
              </div>
              <div class="md-body" id="step-md-view-<?php echo $s['id']; ?>"></div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/dompurify/dist/purify.min.js"></script>
    <script>
      (function(){
        if(window.AttachmentPreview) return;
        const readyQueue=window.__attachmentPreviewReadyCallbacks = window.__attachmentPreviewReadyCallbacks || [];
        const scriptCache=new Map();
        let elements=null;
        let objectUrl=null;
        let abortController=null;
        let lastActive=null;

        const formatBytes=value=>{
          if(!Number.isFinite(value) || value<=0) return '';
          const units=['B','KB','MB','GB','TB'];
          let idx=0; let num=value;
          while(num>=1024 && idx<units.length-1){ num/=1024; idx++; }
          const decimals=num>=100 || idx===0 ? 0 : 1;
          return num.toFixed(decimals)+' '+units[idx];
        };
        const ensureElements=()=>{
          if(elements) return elements;
          const backdrop=document.createElement('div');
          backdrop.className='attachment-preview-backdrop';
          const panel=document.createElement('div');
          panel.className='attachment-preview-panel';
          panel.setAttribute('role','dialog');
          panel.setAttribute('aria-modal','true');
          panel.setAttribute('aria-labelledby','attachment-preview-title');
          panel.tabIndex=-1;
          const header=document.createElement('div');
          header.className='attachment-preview-header';
          const titleWrap=document.createElement('div');
          const titleEl=document.createElement('div');
          titleEl.className='attachment-preview-title';
          titleEl.id='attachment-preview-title';
          const metaEl=document.createElement('div');
          metaEl.className='attachment-preview-meta';
          titleWrap.appendChild(titleEl);
          titleWrap.appendChild(metaEl);
          const closeBtn=document.createElement('button');
          closeBtn.className='attachment-preview-close';
          closeBtn.type='button';
          closeBtn.setAttribute('aria-label','关闭预览');
          closeBtn.textContent='×';
          header.appendChild(titleWrap);
          header.appendChild(closeBtn);
          const bodyEl=document.createElement('div');
          bodyEl.className='attachment-preview-body';
          const loadingEl=document.createElement('div');
          loadingEl.className='attachment-preview-loading';
          loadingEl.textContent='载入中…';
          bodyEl.appendChild(loadingEl);
          const footer=document.createElement('div');
          footer.className='attachment-preview-footer';
          const downloadBtn=document.createElement('a');
          downloadBtn.className='attachment-preview-download';
          downloadBtn.href='';
          downloadBtn.target='_blank';
          downloadBtn.rel='noopener';
          downloadBtn.textContent='下载附件';
          footer.appendChild(downloadBtn);
          panel.appendChild(header);
          panel.appendChild(bodyEl);
          panel.appendChild(footer);
          backdrop.appendChild(panel);
          document.body.appendChild(backdrop);
          const closePreview=()=>{
            backdrop.dataset.open='false';
            if(lastActive && typeof lastActive.focus==='function'){
              try{ lastActive.focus(); }catch(_){ /* ignore */ }
            }
            lastActive=null;
            if(abortController){ abortController.abort(); abortController=null; }
            if(objectUrl){ URL.revokeObjectURL(objectUrl); objectUrl=null; }
          };
          closeBtn.addEventListener('click',closePreview);
          backdrop.addEventListener('click',evt=>{ if(evt.target===backdrop) closePreview(); });
          document.addEventListener('keydown',evt=>{ if(evt.key==='Escape' && backdrop.dataset.open==='true'){ closePreview(); } });
          elements={backdrop,panel,titleEl,metaEl,closeBtn,bodyEl,loadingEl,downloadBtn,closePreview};
          return elements;
        };
        const cleanupObjectUrl=()=>{ if(objectUrl){ URL.revokeObjectURL(objectUrl); objectUrl=null; } };
        const showContent=content=>{
          const {bodyEl,loadingEl}=ensureElements();
          if(loadingEl && loadingEl.parentNode===bodyEl){ loadingEl.remove(); }
          bodyEl.innerHTML='';
          if(content) bodyEl.appendChild(content);
        };
        const showError=message=>{
          const div=document.createElement('div');
          div.className='attachment-preview-error';
          div.textContent=message||'预览失败';
          showContent(div);
        };
        const showList=entries=>{
          const list=document.createElement('ul');
          list.className='attachment-preview-list';
          entries.forEach(entry=>{
            const li=document.createElement('li');
            const label=document.createElement('span');
            label.textContent=entry.label;
            li.appendChild(label);
            if(entry.size){
              const sizeSpan=document.createElement('span');
              sizeSpan.className='entry-size';
              sizeSpan.textContent=entry.size;
              li.appendChild(sizeSpan);
            }
            list.appendChild(li);
          });
          showContent(list);
        };
        const renderMedia=(type,url,mime)=>{
          let el;
          if(type==='video'){
            el=document.createElement('video');
            el.controls=true;
            el.preload='metadata';
            el.playsInline=true;
            el.classList.add('attachment-preview-video');
          }else if(type==='audio'){
            el=document.createElement('audio');
            el.controls=true;
            el.preload='metadata';
            el.style.width='100%';
          }else if(type==='pdf'){
            el=document.createElement('iframe');
            el.type=mime||'application/pdf';
            el.setAttribute('title','PDF 预览');
          }else{
            el=document.createElement('img');
          }
          el.src=url;
          showContent(el);
        };
        const detectType=source=>{
          const mime=(source.mime||'').toLowerCase();
          const name=(source.name||'').toLowerCase();
          const ext=name.includes('.') ? name.split('.').pop() : '';
          if(mime.startsWith('image/') || ['png','jpg','jpeg','gif','webp','bmp','svg','avif','heic','heif'].includes(ext)) return 'image';
          if(mime.startsWith('video/') || ['mp4','mov','m4v','webm','ogv','mkv','avi'].includes(ext)) return 'video';
          if(mime.startsWith('audio/') || ['mp3','wav','ogg','oga','m4a','aac','flac','opus','weba'].includes(ext)) return 'audio';
          if(mime==='application/pdf' || ext==='pdf') return 'pdf';
          if(mime.includes('zip') || ext==='zip') return 'zip';
          if(mime.includes('rar') || ext==='rar') return 'rar';
          if(mime.includes('spreadsheet') || ['xlsx','xls','xlsm','xlsb','csv','xltx','xltm','tsv'].includes(ext)) return 'excel';
          if(mime.includes('word') || ['docx','docm','doc','dotx','dotm'].includes(ext)) return 'word';
          return 'other';
        };
        const normalizeSource=input=>{
          if(!input) return {kind:'url',url:'',name:'附件',mime:'',size:null,downloadName:null};
          if(typeof input==='string') return {kind:'url',url:input,name:'附件',mime:'',size:null,downloadName:null};
          const kind=input.kind ? String(input.kind) : (input.blob ? 'blob' : 'url');
          const sizeRaw = typeof input.size==='number' ? input.size : parseInt(input.size,10);
          const size=Number.isFinite(sizeRaw) && sizeRaw>0 ? sizeRaw : null;
          const name=typeof input.name==='string' && input.name.trim() ? input.name.trim() : '附件';
          return {
            kind,
            url:typeof input.url==='string'?input.url:'',
            blob:input.blob instanceof Blob ? input.blob : null,
            name,
            mime:typeof input.mime==='string'?input.mime:'',
            size,
            downloadName:typeof input.downloadName==='string' && input.downloadName.trim()?input.downloadName.trim():name
          };
        };
        const setDownloadLink=(btn,source)=>{
          let href='';
          if(source.kind==='blob' && source.blob){
            cleanupObjectUrl();
            objectUrl=URL.createObjectURL(source.blob);
            href=objectUrl;
          }else if(source.url){
            href=source.url;
          }
          if(btn){
            if(href){
              btn.href=href;
              btn.download=source.downloadName || source.name || 'attachment';
              btn.hidden=false;
            }else{
              btn.removeAttribute('href');
              btn.hidden=true;
            }
          }
          return href;
        };
        const loadBuffer=async source=>{
          if(source.kind==='blob' && source.blob){
            return await source.blob.arrayBuffer();
          }
          if(!source.url) throw new Error('缺少文件地址');
          abortController=new AbortController();
          try{
            const res=await fetch(source.url,{signal:abortController.signal});
            if(!res.ok) throw new Error('网络错误');
            return await res.arrayBuffer();
          }finally{
            abortController=null;
          }
        };
        const ensureScript=url=>{
          if(scriptCache.has(url)) return scriptCache.get(url);
          const promise=new Promise((resolve,reject)=>{
            const el=document.createElement('script');
            el.src=url;
            el.async=true;
            el.onload=()=>resolve();
            el.onerror=()=>reject(new Error('脚本加载失败'));
            document.head.appendChild(el);
          });
          scriptCache.set(url,promise);
          return promise;
        };
        const buildArchiveTree=entries=>{
          const root={name:'',type:'dir',children:new Map()};
          entries.forEach(entry=>{
            const rawPath=String(entry.path||'').replace(/\\/g,'/');
            const normalized=rawPath.replace(/^\/+/, '').trim();
            if(!normalized) return;
            const parts=normalized.split('/').filter(Boolean);
            let node=root;
            parts.forEach((part,index)=>{
              const isLast=index===parts.length-1;
              if(isLast && !entry.dir){
                node.children.set(part,{type:'file',name:part,size:entry.size||''});
              }else{
                let next=node.children.get(part);
                if(!next || next.type!=='dir'){
                  next={type:'dir',name:part,children:new Map()};
                  node.children.set(part,next);
                }
                node=next;
              }
            });
            if(entry.dir && parts.length){
              let dirNode=root;
              parts.forEach(part=>{
                let next=dirNode.children.get(part);
                if(!next || next.type!=='dir'){
                  next={type:'dir',name:part,children:new Map()};
                  dirNode.children.set(part,next);
                }
                dirNode=next;
              });
            }
          });
          return root;
        };
        const createTreeElement=(node,depth=0)=>{
          const ul=document.createElement('ul');
          ul.className='attachment-preview-tree';
          const items=Array.from(node.children.values());
          items.sort((a,b)=>{
            if(a.type!==b.type) return a.type==='dir'?-1:1;
            return a.name.localeCompare(b.name,'zh-Hans');
          });
          items.forEach(child=>{
            const li=document.createElement('li');
            if(child.type==='dir'){
              const details=document.createElement('details');
              if(depth<1) details.open=true;
              const summary=document.createElement('summary');
              const label=document.createElement('span');
              label.className='entry-label';
              label.textContent='📁 '+child.name;
              summary.appendChild(label);
              if(child.size){
                const sizeSpan=document.createElement('span');
                sizeSpan.className='entry-size';
                sizeSpan.textContent=child.size;
                summary.appendChild(sizeSpan);
              }
              details.appendChild(summary);
              details.appendChild(createTreeElement(child, depth+1));
              li.appendChild(details);
            }else{
              const row=document.createElement('div');
              row.className='tree-entry';
              const label=document.createElement('span');
              label.className='entry-label';
              label.textContent='📄 '+child.name;
              row.appendChild(label);
              if(child.size){
                const sizeSpan=document.createElement('span');
                sizeSpan.className='entry-size';
                sizeSpan.textContent=child.size;
                row.appendChild(sizeSpan);
              }
              li.appendChild(row);
            }
            ul.appendChild(li);
          });
          return ul;
        };
        const showArchiveTree=(entries,kind)=>{
          if(!entries || !entries.length){
            showError((kind||'压缩包')+'为空。');
            return;
          }
          const tree=buildArchiveTree(entries);
          const view=createTreeElement(tree);
          showContent(view);
        };
        const isPasswordError=err=>{
          const msg=(err && err.message ? String(err.message) : '').toLowerCase();
          return msg.includes('password') || msg.includes('decrypt') || msg.includes('encrypted');
        };
        const loadZipPreview=async source=>{
          const {loadingEl}=ensureElements();
          const attempt=async password=>{
            loadingEl.textContent=password?'验证密码…':'解析压缩包…';
            const buffer=await loadBuffer(source);
            await ensureScript('https://cdn.jsdelivr.net/npm/@zip.js/zip.js@2.7.32/dist/zip.min.js');
            const zipLib=window.zip;
            if(!zipLib || !zipLib.ZipReader || !zipLib.Uint8ArrayReader) throw new Error('解析库未加载');
            const reader=new zipLib.ZipReader(new zipLib.Uint8ArrayReader(new Uint8Array(buffer)), password?{password}:{});
            try{
              const entries=await reader.getEntries();
              const mapped=(entries||[]).map(entry=>({
                path:entry.filename,
                dir:!!entry.directory,
                size:entry.uncompressedSize?formatBytes(entry.uncompressedSize):''
              }));
              if(!mapped.length){
                showError('压缩包为空。');
                return;
              }
              showArchiveTree(mapped,'ZIP 压缩包');
            }finally{
              if(reader && typeof reader.close==='function'){
                try{ await reader.close(); }catch(_){ /* ignore */ }
              }
            }
          };
          try{
            await attempt('');
          }catch(err){
            if(isPasswordError(err)){
              let retry=false;
              while(true){
                const input=window.prompt(retry?'密码不正确，请重新输入 ZIP 密码：':'该 ZIP 文件已加密，请输入密码以预览：','');
                if(input===null){ showError('已取消预览。'); return; }
                try{
                  await attempt(input);
                  return;
                }catch(inner){
                  if(!isPasswordError(inner)) throw inner;
                  retry=true;
                }
              }
            }
            throw err;
          }
        };
        const loadRarPreview=async source=>{
          const {loadingEl}=ensureElements();
          const attempt=async password=>{
            loadingEl.textContent=password?'验证密码…':'解析压缩包…';
            const buffer=await loadBuffer(source);
            await ensureScript('https://cdn.jsdelivr.net/npm/unrar-js@0.2.19/dist/unrar.js');
            const api=window.UNRAR || window.unrar;
            if(!api || typeof api.createExtractorFromData!=='function') throw new Error('解析库未加载');
            const extractor=await api.createExtractorFromData({data:buffer,password:password||undefined});
            try{
              const list=extractor && typeof extractor.getFileList==='function' ? extractor.getFileList() : null;
              const headers=list && Array.isArray(list.fileHeaders) ? list.fileHeaders : [];
              const entries=headers.map(header=>{
                const name=header && typeof header.name==='string' && header.name ? header.name : (header && typeof header.fileName==='string' ? header.fileName : '未知文件');
                const dirFlag=header && header.flags ? (header.flags.directory || header.flags.DIRECTORY || header.flags.folder) : false;
                const isDir=!!dirFlag || /[\\/]$/.test(name);
                const sizeValue=header && typeof header.uncompressedSize==='number'?header.uncompressedSize:(header && typeof header.size==='number'?header.size:null);
                return {path:name,dir:isDir,size:sizeValue?formatBytes(sizeValue):''};
              });
              if(!entries.length){
                showError('压缩包为空。');
                return;
              }
              showArchiveTree(entries,'RAR 压缩包');
            }finally{
              if(extractor){
                if(typeof extractor.free==='function') extractor.free();
                else if(typeof extractor.close==='function') extractor.close();
                else if(typeof extractor.delete==='function') extractor.delete();
              }
            }
          };
          try{
            await attempt('');
          }catch(err){
            if(isPasswordError(err)){
              let retry=false;
              while(true){
                const input=window.prompt(retry?'密码不正确，请重新输入 RAR 密码：':'该 RAR 文件已加密，请输入密码以预览：','');
                if(input===null){ showError('已取消预览。'); return; }
                try{
                  await attempt(input);
                  return;
                }catch(inner){
                  if(!isPasswordError(inner)) throw inner;
                  retry=true;
                }
              }
            }
            throw err;
          }
        };
        const loadDocxPreview=async source=>{
          const {loadingEl}=ensureElements();
          loadingEl.textContent='解析文档…';
          const buffer=await loadBuffer(source);
          await ensureScript('https://cdn.jsdelivr.net/npm/mammoth@1.6.0/mammoth.browser.min.js');
          if(!window.mammoth || typeof window.mammoth.convertToHtml!=='function') throw new Error('转换库未加载');
          const result=await window.mammoth.convertToHtml({arrayBuffer:buffer}).catch(err=>{ throw err; });
          const html=result && typeof result.value==='string' ? result.value : '';
          const wrapper=document.createElement('div');
          wrapper.className='attachment-docx';
          const content=html && html.trim() ? html : '<p>（文档为空）</p>';
          wrapper.innerHTML=window.DOMPurify ? window.DOMPurify.sanitize(content) : content;
          showContent(wrapper);
        };
        const loadExcelPreview=async source=>{
          const {loadingEl}=ensureElements();
          loadingEl.textContent='解析表格…';
          const buffer=await loadBuffer(source);
          await ensureScript('https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js');
          if(!window.XLSX || typeof window.XLSX.read!=='function') throw new Error('解析库未加载');
          const workbook=window.XLSX.read(buffer,{type:'array'});
          const sheetNames=Array.isArray(workbook.SheetNames)?workbook.SheetNames:[];
          if(!sheetNames.length){ showError('工作簿为空。'); return; }
          const container=document.createElement('div');
          container.className='attachment-excel';
          sheetNames.forEach(name=>{
            const sheet=workbook.Sheets[name];
            if(!sheet) return;
            const section=document.createElement('section');
            section.className='excel-sheet';
            const heading=document.createElement('h3');
            heading.textContent='工作表：'+name;
            section.appendChild(heading);
            const tableHtml=window.XLSX.utils && typeof window.XLSX.utils.sheet_to_html==='function'
              ? window.XLSX.utils.sheet_to_html(sheet,{header:'',footer:''})
              : '';
            const sanitized=window.DOMPurify ? window.DOMPurify.sanitize(tableHtml||'<table><tbody><tr><td>（无法预览该表格）</td></tr></tbody></table>') : (tableHtml||'<table><tbody><tr><td>（无法预览该表格）</td></tr></tbody></table>');
            const tableWrap=document.createElement('div');
            tableWrap.className='excel-table';
            tableWrap.innerHTML=sanitized;
            section.appendChild(tableWrap);
            container.appendChild(section);
          });
          showContent(container);
        };
        const openPreview=input=>{
          const source=normalizeSource(input);
          const {backdrop,panel,titleEl,metaEl,loadingEl,downloadBtn}=ensureElements();
          if(abortController){ abortController.abort(); abortController=null; }
          cleanupObjectUrl();
          lastActive=document.activeElement instanceof HTMLElement ? document.activeElement : null;
          backdrop.dataset.open='true';
          try{ panel.focus({preventScroll:true}); }catch(_){ /* ignore */ }
          titleEl.textContent=source.name || '附件预览';
          const metaParts=[];
          if(source.mime) metaParts.push(source.mime);
          if(Number.isFinite(source.size) && source.size>0) metaParts.push(formatBytes(source.size));
          metaEl.textContent=metaParts.join(' · ');
          const downloadHref=setDownloadLink(downloadBtn, source);
          loadingEl.textContent='载入中…';
          const type=detectType(source);
          if(type==='image' || type==='video' || type==='pdf' || type==='audio'){
            const url=(type==='image' && source.kind==='url') ? source.url : (source.kind==='blob' ? downloadHref : source.url || downloadHref);
            if(!url){ showError('附件缺少可用地址'); return; }
            renderMedia(type,url,source.mime);
            return;
          }
          const labelMap={zip:'ZIP 压缩包',rar:'RAR 压缩包',word:'文档',excel:'表格'};
          const handleError=err=>{
            console.error(err);
            if(labelMap[type]){
              showError('无法解析'+labelMap[type]+'：'+(err && err.message?err.message:''));
            }else{
              showError('暂不支持该文件类型的在线预览，请使用下载按钮。');
            }
          };
          if(type==='zip'){ loadZipPreview(source).then(()=>{}).catch(handleError); return; }
          if(type==='rar'){ loadRarPreview(source).then(()=>{}).catch(handleError); return; }
          if(type==='word'){ loadDocxPreview(source).then(()=>{}).catch(handleError); return; }
          if(type==='excel'){ loadExcelPreview(source).then(()=>{}).catch(handleError); return; }
          showError('暂不支持该文件类型的在线预览，请使用下载按钮。');
        };
        window.AttachmentPreview={
          open:openPreview,
          openFromUrl(url,meta){ openPreview(Object.assign({}, meta||{}, {url:url||''})); },
          openFromBlob(blob,meta){ openPreview(Object.assign({}, meta||{}, {blob})); },
          close(){ const els=ensureElements(); els.closePreview(); }
        };
        const callbacks=readyQueue.splice(0);
        callbacks.forEach(fn=>{ try{ fn(); }catch(err){ console.error(err); } });
      })();

      function registerAttachmentPreviewReady(fn){
        if(typeof fn!=='function') return;
        if(window.AttachmentPreview){ fn(); return; }
        (window.__attachmentPreviewReadyCallbacks = window.__attachmentPreviewReadyCallbacks || []).push(fn);
      }
      function sanitizeAttachmentName(name){
        if(typeof name!=='string') return '附件';
        const trimmed=name.replace(/[\r\n]+/g,' ').trim();
        return trimmed || '附件';
      }
      function humanBytes(size){
        if(!Number.isFinite(size) || size<=0) return '';
        const units=['B','KB','MB','GB','TB'];
        let idx=0; let value=size;
        while(value>=1024 && idx<units.length-1){ value/=1024; idx++; }
        const decimals=value>=100 || idx===0 ? 0 : 1;
        return value.toFixed(decimals)+' '+units[idx];
      }
      function attachmentIconForMime(mime,name){
        const lower=(mime||'').toLowerCase();
        const ext=(typeof name==='string' && name.includes('.')) ? name.split('.').pop().toLowerCase() : '';
        if(lower.startsWith('image/')) return '🖼';
        if(lower.startsWith('video/')) return '🎬';
        if(lower.startsWith('audio/')) return '🎧';
        if(lower==='application/pdf' || ext==='pdf') return '📄';
        if(lower.includes('spreadsheet') || ['xlsx','xls','xlsm','xlsb','csv','xltx','xltm','tsv'].includes(ext)) return '📊';
        if(lower.includes('word') || ['docx','docm','doc','dotx','dotm'].includes(ext)) return '📝';
        if(lower.includes('zip') || ext==='zip') return '🗜';
        if(lower.includes('rar') || ext==='rar') return '📦';
        return '📎';
      }
      function applyPreviewDataset(el,data){
        if(!el || !data) return;
        el.dataset.attachmentPreview='true';
        if(data.url) el.dataset.url=data.url;
        if(data.name) el.dataset.name=data.name;
        if(data.mime) el.dataset.mime=data.mime;
        if(data.size) el.dataset.size=data.size;
      }
      function bindAttachmentPreviewTargets(scope){
        const root=scope || document;
        root.querySelectorAll('[data-attachment-preview]').forEach(el=>{
          if(el.dataset.previewBound==='true') return;
          el.dataset.previewBound='true';
          el.addEventListener('click',event=>{
            if(event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
            if(!window.AttachmentPreview) return;
            const dataset=el.dataset || {};
            const url=dataset.url || el.getAttribute('href') || '';
            if(!url) return;
            event.preventDefault();
            const name=dataset.name || sanitizeAttachmentName(el.getAttribute('title') || '附件');
            const mime=dataset.mime || '';
            const sizeRaw=dataset.size ? parseInt(dataset.size,10) : NaN;
            const payload={kind:'url',url,name,mime};
            if(Number.isFinite(sizeRaw)) payload.size=sizeRaw;
            window.AttachmentPreview.open(payload);
          });
        });
      }
      function updateAttachmentPanelSummary(){
        const list=document.getElementById('attachment-list');
        const summary=document.getElementById('attachment-summary');
        const empty=document.getElementById('attachment-empty');
        if(!list) return;
        const rows=Array.from(list.querySelectorAll('.attachment-row'));
        const count=rows.length;
        let total=0;
        rows.forEach(row=>{
          const sizeRaw=row.dataset && row.dataset.size ? parseInt(row.dataset.size,10) : NaN;
          if(Number.isFinite(sizeRaw) && sizeRaw>0){ total+=sizeRaw; }
        });
        if(summary){
          if(count===0){ summary.textContent='暂无附件'; }
          else{
            const parts=['共 '+count+' 个附件'];
            if(total>0){ parts.push('总计 '+humanBytes(total)); }
            summary.textContent=parts.join(' · ');
          }
        }
        if(empty){ empty.hidden=count>0; }
      }
      function addAttachmentEntry(info){
        if(!info || !info.url) return;
        const list=document.getElementById('attachment-list');
        if(!list) return;
        const name=sanitizeAttachmentName(info.name || info.originalName || '附件');
        const mime=info.mime || '';
        const sizeValue=typeof info.size==='number'?info.size:parseInt(info.size,10);
        const row=document.createElement('div');
        row.className='attachment-row';
        if(info.id!=null) row.dataset.attachmentId=String(info.id);
        if(!Number.isNaN(sizeValue) && sizeValue>=0) row.dataset.size=String(sizeValue);
        if(mime) row.dataset.mime=mime;
        const main=document.createElement('div');
        main.className='attachment-main';
        const iconSpan=document.createElement('span');
        iconSpan.className='attachment-icon';
        iconSpan.textContent=attachmentIconForMime(mime, name);
        const infoBox=document.createElement('div');
        infoBox.className='attachment-info';
        const nameEl=document.createElement('div');
        nameEl.className='attachment-name';
        nameEl.textContent=name;
        const detailEl=document.createElement('div');
        detailEl.className='attachment-detail';
        const sizeText=!Number.isNaN(sizeValue) && sizeValue>0 ? humanBytes(sizeValue) : '';
        const detailParts=[];
        if(sizeText) detailParts.push(sizeText);
        if(mime) detailParts.push(mime);
        detailEl.textContent=detailParts.length?detailParts.join(' · '):'—';
        infoBox.appendChild(nameEl);
        infoBox.appendChild(detailEl);
        main.appendChild(iconSpan);
        main.appendChild(infoBox);
        const actions=document.createElement('div');
        actions.className='attachment-actions';
        const previewBtn=document.createElement('button');
        previewBtn.type='button';
        previewBtn.className='btn btn-primary btn-small attachment-preview-button';
        previewBtn.textContent='预览';
        const previewData={url:info.url,name,mime,size:!Number.isNaN(sizeValue)&&sizeValue>0?String(sizeValue):''};
        applyPreviewDataset(previewBtn,previewData);
        actions.appendChild(previewBtn);
        const downloadLink=document.createElement('a');
        downloadLink.className='btn btn-outline btn-small';
        downloadLink.href=info.url;
        downloadLink.setAttribute('download','');
        downloadLink.textContent='下载';
        actions.appendChild(downloadLink);
        row.appendChild(main);
        row.appendChild(actions);
        list.prepend(row);
        bindAttachmentPreviewTargets(row);
        updateAttachmentPanelSummary();
      }
      registerAttachmentPreviewReady(()=>bindAttachmentPreviewTargets());
      updateAttachmentPanelSummary();
      const $=s=>document.querySelector(s); const $$=s=>Array.from(document.querySelectorAll(s));
      const throttle=(fn,ms)=>{let t=0;return (...a)=>{const n=Date.now();if(n-t>ms){t=n;fn(...a);} }};
      function createSaveFeedbackController(tipEl, buttonEl){
        const defaultLabel = buttonEl ? (buttonEl.dataset.defaultLabel || buttonEl.textContent || '保存') : '保存';
        if(buttonEl){ buttonEl.dataset.defaultLabel = defaultLabel; }
        let timer=null;
        const cleanupTimer=()=>{ if(timer){ clearTimeout(timer); timer=null; } };
        const showTip=(text,{dirty,show}={})=>{
          if(!tipEl) return;
          if(typeof text==='string'){ tipEl.textContent=text; }
          if(show===false){ tipEl.classList.remove('show'); }
          else if(show!==undefined || text){ tipEl.classList.add('show'); }
          if(dirty===true){ tipEl.classList.add('dirty'); }
          else if(dirty===false){ tipEl.classList.remove('dirty'); }
        };
        return {
          saving(message='保存中...', buttonLabel='⏳ 保存中...'){
            cleanupTimer();
            if(buttonEl){ buttonEl.disabled=true; buttonEl.textContent=buttonLabel || '⏳ 保存中...'; }
            showTip(message,{dirty:false,show:true});
          },
          success(message='保存成功', buttonLabel='✅ 保存成功'){
            cleanupTimer();
            if(buttonEl){ buttonEl.disabled=false; buttonEl.textContent=buttonLabel || '✅ 保存成功'; }
            showTip(message,{dirty:false,show:true});
            timer=setTimeout(()=>{
              if(buttonEl){ buttonEl.disabled=false; buttonEl.textContent=defaultLabel; }
              showTip('',{show:false,dirty:false});
              timer=null;
            },1500);
          },
          error(message='未保存', buttonLabel){
            cleanupTimer();
            if(buttonEl){ buttonEl.disabled=false; buttonEl.textContent=buttonLabel || defaultLabel; }
            showTip(message,{dirty:true,show:true});
          },
          dirty(message='未保存'){
            cleanupTimer();
            showTip(message,{dirty:true,show:true});
          },
          reset(){
            cleanupTimer();
            if(buttonEl){ buttonEl.disabled=false; buttonEl.textContent=defaultLabel; }
            showTip('',{show:false,dirty:false});
          }
        };
      }
      function safeHTML(md){ return DOMPurify.sanitize(marked.parse(md||'')); }
      const editorEl=document.getElementById('md-editor');
      const mainTip=document.getElementById('save-tip');
      const mainButton=document.querySelector('#item-form button[type="submit"]');
      const mainFeedback=(mainTip && mainButton)?createSaveFeedbackController(mainTip, mainButton):null;
      function renderEditorPreview(){
        const view=document.getElementById('md-view');
        if(view){ view.innerHTML=safeHTML(editorEl?editorEl.value:''); }
      }
      function markItemDirty(){
        if(mainFeedback){ mainFeedback.dirty('未保存'); }
        else if(mainTip){
          mainTip.textContent='未保存';
          mainTip.classList.add('show','dirty');
        }
      }
      function insertTextAtCursor(textarea, text){
        if(!textarea) return;
        const start=textarea.selectionStart ?? textarea.value.length;
        const end=textarea.selectionEnd ?? textarea.value.length;
        const before=textarea.value.slice(0,start);
        const after=textarea.value.slice(end);
        textarea.value=before+text+after;
        const pos=start+text.length;
        if(typeof textarea.setSelectionRange==='function'){ textarea.setSelectionRange(pos,pos); }
        textarea.dispatchEvent(new Event('input', {bubbles:true}));
      }
      if(editorEl){
        editorEl.addEventListener('input', ()=>{ renderEditorPreview(); markItemDirty(); });
        editorEl.addEventListener('change', markItemDirty);
        renderEditorPreview();
      }
      const titleInput=document.querySelector('#item-form input[name="title"]');
      const catSelect=document.querySelector('#item-form select[name="category_id"]');
      [titleInput,catSelect].forEach(el=>{
        if(el){
          el.addEventListener('input', markItemDirty);
          el.addEventListener('change', markItemDirty);
        }
      });
      async function saveItemAJAX(ev, form){
        ev.preventDefault();
        const fd = new FormData(form);
        const fallbackTip=form.querySelector('.save-tip') || mainTip;
        const fallbackBtn=form.querySelector('button[type="submit"]') || mainButton;
        const feedback=mainFeedback ?? createSaveFeedbackController(fallbackTip, fallbackBtn);
        feedback.saving();
        try{
          const res = await fetch(location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'fetch'}});
          if(!res.ok) throw new Error('保存失败');
          feedback.success();
        }catch(err){
          alert(err.message||'保存失败');
          feedback.error('未保存');
        }
        return false;
      }
      const insertButton=document.getElementById('btn-insert-att-item');
      const itemFileInput=document.getElementById('att-file-item');
      if(insertButton && itemFileInput){ insertButton.addEventListener('click',()=>itemFileInput.click()); }
      if(itemFileInput){
        itemFileInput.addEventListener('change', async (e)=>{
          const f=e.target.files[0]; if(!f) return;
          const fd=new FormData(); fd.append('action','upload_attachment'); fd.append('target','item'); fd.append('target_id','<?php echo $it['id']; ?>'); fd.append('file', f);
          const j=await (await fetch(location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'fetch'}})).json();
          if(!j.ok){ alert(j.error||'上传失败'); return; }
          insertTextAtCursor(editorEl, j.markdown+"\n");
          renderEditorPreview();
          markItemDirty();
          addAttachmentEntry({id:j.id,url:j.url,mime:j.mime,name:f.name,size:j.size});
          e.target.value='';
        });
      }
      function getStepFeedback(stepId, kind){
        const tip=document.querySelector(`[data-step-tip="${kind}-${stepId}"]`);
        const btn=document.querySelector(`[data-step-button="${kind}-${stepId}"]`);
        return createSaveFeedbackController(tip, btn);
      }
      async function saveStepTitleAJAX(ev, stepId, form){
        ev.preventDefault();
        const fd=new FormData(form);
        const feedback=getStepFeedback(stepId,'title');
        const titleInput=form.querySelector('input[name="title"]');
        const titleValue=titleInput ? titleInput.value.trim() : '';
        feedback.saving();
        try{
          const res=await fetch(location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'fetch'}});
          if(!res.ok) throw new Error('保存失败');
          const json=await res.json().catch(()=>({}));
          if(json && (json.ok===0 || json.ok==='0' || json.ok===false)){ throw new Error(json.error||'保存失败'); }
          const display=document.querySelector(`.tl-item[data-id="${stepId}"] .js-step-title`);
          if(display){ display.textContent=titleValue || '未命名'; }
          feedback.success();
        }catch(err){
          alert(err.message||'保存失败');
          feedback.error('未保存');
        }
        return false;
      }
      async function saveStepNotesAJAX(ev, stepId){
        ev.preventDefault();
        const form=document.getElementById('form-notes-'+stepId);
        if(!form) return false;
        const fd=new FormData(form);
        const ta=form.querySelector('textarea[name="notes"]');
        const feedback=getStepFeedback(stepId,'notes');
        feedback.saving();
        try{
          const res=await fetch(location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'fetch'}});
          if(!res.ok) throw new Error('保存失败');
          const json=await res.json().catch(()=>({}));
          if(json && (json.ok===0 || json.ok==='0' || json.ok===false)){ throw new Error(json.error||'保存失败'); }
          const target=document.getElementById('step-md-view-'+stepId);
          if(target && ta){ target.innerHTML=safeHTML(ta.value); }
          feedback.success();
        }catch(err){
          alert(err.message||'保存失败');
          feedback.error('未保存');
        }
        return false;
      }
      async function deleteStep(stepId, buttonEl){
        if(!confirm('确认删除该流程子任务？')) return false;
        const fd=new FormData(); fd.append('action','delete_step'); fd.append('id', stepId);
        const feedback=createSaveFeedbackController(
          document.querySelector(`[data-step-tip="delete-${stepId}"]`),
          buttonEl || document.querySelector(`[data-step-button="delete-${stepId}"]`)
        );
        feedback.saving('删除中...','⏳ 删除中...');
        try{
          const res=await fetch(location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'fetch'}});
          if(!res.ok) throw new Error('删除失败');
          const json=await res.json().catch(()=>({ok:1}));
          if(json && (json.ok===0 || json.ok==='0' || json.ok===false)){ throw new Error(json.error||'删除失败'); }
          feedback.success('已删除','✅ 已删除');
          const node=document.querySelector(`.tl-item[data-id="${stepId}"]`);
          if(node){ setTimeout(()=>{ node.remove(); }, 350); }
        }catch(err){
          alert(err.message||'删除失败');
          feedback.error('删除失败');
        }
        return false;
      }
      async function toggleStep(stepId, done){
        const fd=new FormData(); fd.append('action','toggle_step'); fd.append('id', stepId); fd.append('done', done?1:0);
        try{
          const res=await fetch(location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'fetch'}});
          if(res.ok){ const card=document.querySelector(`.tl-item[data-id="${stepId}"]`); if(card){ card.classList.toggle('done', !!done); } }
        }catch(_){ }
        return false;
      }
      window.insertAttachmentToStep = async function(stepId){
        const input=document.getElementById('att-file-step-'+stepId);
        if(!input) return;
        input.onchange = async (e)=>{
          const f=e.target.files[0]; if(!f) return;
          const fd=new FormData(); fd.append('action','upload_attachment'); fd.append('target','step'); fd.append('target_id', String(stepId)); fd.append('file', f);
          const j=await (await fetch(location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'fetch'}})).json();
          if(!j.ok){ alert(j.error||'上传失败'); return; }
          const textarea=document.getElementById('md-step-'+stepId);
          if(textarea){
            insertTextAtCursor(textarea, j.markdown+"\n");
            const preview=document.getElementById('step-md-view-'+stepId);
            if(preview){ preview.innerHTML=safeHTML(textarea.value); }
          }
          e.target.value='';
        };
        input.click();
      };
      const timelineBox=document.getElementById('timeline');
      if(timelineBox){
        timelineBox.addEventListener('input', e=>{
          if(e.target && e.target.matches('textarea[name="notes"]')){
            const textarea=e.target;
            const id=textarea.id ? textarea.id.replace('md-step-','') : '';
            if(id){
              const preview=document.getElementById('step-md-view-'+id);
              if(preview){ preview.innerHTML=safeHTML(textarea.value); }
              const fb=getStepFeedback(Number(id),'notes');
              if(fb && fb.dirty){ fb.dirty('未保存'); }
            }
          }
        });
      }
      function addStepForm(ev){
        ev.preventDefault(); const f=ev.target; const fd=new FormData(f);
        fetch(location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'fetch'}}).then(()=>location.reload());
        return false;
      }
      (function(){ // DnD steps
        const box=document.getElementById('timeline'); if(!box) return; let dragging=null;
        const isMobile=window.matchMedia('(max-width: 920px)').matches;
        if(isMobile) return;
        box.addEventListener('dragstart',e=>{ const t=e.target.closest('.tl-item[draggable]'); if(!t) return; dragging=t; e.dataTransfer.effectAllowed='move'; });
        box.addEventListener('dragover', (e)=>{ if(!dragging) return; e.preventDefault();
          const cand=[...box.querySelectorAll('.tl-item[draggable]')].filter(n=>n!==dragging); if(!cand.length) return;
          let best=null, dmin=1e9;
          for(const n of cand){ const r=n.getBoundingClientRect(); const cx=r.left+r.width/2, cy=r.top+r.height/2; const d=Math.hypot(e.clientX-cx,e.clientY-cy); if(d<dmin){ dmin=d; best=n; } }
          if(!best) return; const r=best.getBoundingClientRect(); const after=e.clientY > r.top + r.height/2; best.parentNode.insertBefore(dragging, after?best.nextSibling:best);
        });
        box.addEventListener('drop',e=>{
          e.preventDefault(); if(!dragging) return; dragging=null;
          const ids=[...box.querySelectorAll('.tl-item[draggable]')].map(x=>x.dataset.id).join(',');
          const fd=new FormData(); fd.append('action','reorder_steps'); fd.append('item_id', box.dataset.item); fd.append('order', ids);
          fetch(location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'fetch'}});
        });
      })();
      <?php foreach ($steps as $s): $notes = json_encode((string)($s['notes'] ?? ''), JSON_UNESCAPED_UNICODE); ?>
        (function(){
          const el=document.getElementById('step-md-view-<?php echo $s['id']; ?>');
          if(el){ el.innerHTML=safeHTML(<?php echo $notes; ?>); }
        })();
      <?php endforeach; ?>
    </script>
  </body>
  </html>
  <?php
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
  ?>
  <!doctype html>
  <html lang="zh-Hans">
  <head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <title>思维导图编辑器 · <?php echo h($mind['title']); ?></title>
    <meta name="color-scheme" content="dark"/>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@500;600;700&family=Inter:wght@400;500;600&family=Noto+Sans+SC:wght@400;500;600;700&family=Noto+Serif+SC:wght@500;600;700&display=swap" rel="stylesheet">
    <style>
      :root{
        --bg-void:#0A0C0E;
        --bg-elev-1:#0F1316;
        --bg-elev-2:#151A1E;
        --gold-700:#AA8C54;
        --gold-600:#C9A86A;
        --gold-500:#D1B274;
        --gold-400:#E3C68B;
        --accent-emerald:#24C2A0;
        --accent-crimson:#D14B4B;
        --accent-cyan:#4BC3D1;
        --text-strong:#E8E5DF;
        --text-dim:#A7A39A;
        --text-muted:#7A766E;
        --divider:rgba(201,168,106,.18);
        --r-xs:6px;
        --r-sm:10px;
        --r-md:14px;
        --r-lg:18px;
        --shadow-1:0 8px 24px rgba(0,0,0,.5),0 0 24px rgba(227,198,139,.12);
        --shadow-2:0 16px 48px rgba(0,0,0,.6) inset,0 0 1px rgba(201,168,106,.35);
        --t-fast:200ms;
        --t:300ms;
        --t-slow:450ms;
        --ease:cubic-bezier(.22,.61,.36,1);
        --bg:var(--bg-void);
        --panel:rgba(21,26,30,.92);
        --panel-strong:rgba(15,19,22,.95);
        --glow:var(--gold-500);
        --glow-soft:rgba(227,198,139,.26);
        --glow-strong:rgba(227,198,139,.4);
        --text:var(--text-strong);
        --border:rgba(201,168,106,.38);
        --border-soft:rgba(201,168,106,.18);
        --grid:rgba(201,168,106,.18);
        --grid-strong:rgba(201,168,106,.3);
        --fiber:var(--gold-500);
        --fiber-soft:rgba(227,198,139,.32);
        --danger:var(--accent-crimson);
        --grid-size:72px;
        --transition:var(--t) var(--ease);
        --safe-top:env(safe-area-inset-top, 0px);
        --safe-right:env(safe-area-inset-right, 0px);
        --safe-bottom:env(safe-area-inset-bottom, 0px);
        --safe-left:env(safe-area-inset-left, 0px);
      }
      *,*::before,*::after{box-sizing:border-box}
      html,body{margin:0;min-height:100vh;color:var(--text-strong);background:var(--bg-void);font:16px/1.6 'Source Han Sans','Noto Sans SC','Inter','Microsoft YaHei',sans-serif;letter-spacing:.01em;position:relative;overflow:hidden}
      body{background:
        radial-gradient(1200px 700px at 72% -10%,rgba(227,198,139,.06),transparent 60%),
        linear-gradient(120deg,rgba(201,168,106,.08),transparent 30%,rgba(201,168,106,.06) 70%,transparent 90%),
        #0A0C0E;
      }
      body::before{content:"";position:fixed;inset:0;background:
        linear-gradient(180deg,rgba(10,12,14,.45),rgba(10,12,14,.82)),
        url('data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" width="160" height="160" viewBox="0 0 160 160"%3E%3Cpath fill="rgba(201,168,106,0.05)" d="M0 79h160v2H0zm79-79h2v160h-2z"/%3E%3C/svg%3E');
        background-size:cover,160px 160px;opacity:.6;pointer-events:none;z-index:-2;
      }
      body::after{content:"";position:fixed;inset:0;background:
        repeating-linear-gradient(0deg,rgba(75,195,209,.08) 0,rgba(75,195,209,.08) 1px,transparent 1px,transparent var(--grid-size)),
        repeating-linear-gradient(90deg,rgba(201,168,106,.12) 0,rgba(201,168,106,.12) 1px,transparent 1px,transparent var(--grid-size));
        background-size:var(--grid-size) var(--grid-size);
        mix-blend-mode:screen;opacity:.3;pointer-events:none;z-index:-3;background-attachment:fixed;
      }
      .scanlines{position:fixed;inset:0;pointer-events:none;z-index:-1;background:linear-gradient(to bottom,rgba(75,195,209,.14) 0,transparent 4px);background-size:100% 6px;opacity:.18}
      a{color:inherit;text-decoration:none}
      .mind-shell{position:relative;min-height:100vh;min-height:100dvh;height:100vh;height:100dvh;display:flex;flex-direction:column;gap:0;padding:0;overflow:hidden}
      @media (max-width:900px){.mind-shell{padding:0}}
      .mind-info-bar{position:absolute;top:calc(var(--safe-top) + 28px);left:calc(var(--safe-left) + 28px);display:flex;flex-direction:column;gap:12px;padding:14px 18px;border-radius:20px;border:1px solid rgba(201,168,106,.32);background:linear-gradient(180deg,rgba(21,26,30,.92),rgba(12,16,18,.88));box-shadow:0 18px 48px rgba(0,0,0,.55),0 0 28px rgba(227,198,139,.14) inset;backdrop-filter:blur(16px);min-width:260px;max-width:min(460px,calc(100% - 56px));pointer-events:auto;z-index:20;overflow:visible;max-height:320px;transition:max-height var(--t-fast) var(--ease),padding var(--t-fast) var(--ease),gap var(--t-fast) var(--ease),border-color var(--t-fast) var(--ease),background var(--t-fast) var(--ease),box-shadow var(--t-fast) var(--ease)}
      .mind-info-bar[data-collapsed="true"]{max-height:20px;padding:0 12px;gap:0;min-width:auto;width:auto;border-radius:14px;border-color:rgba(201,168,106,.2);background:rgba(15,19,22,.82);box-shadow:0 12px 28px rgba(0,0,0,.45);overflow:hidden}
      .mind-info-content{display:flex;flex-direction:column;gap:12px;transition:opacity var(--t-fast) var(--ease),transform var(--t-fast) var(--ease);transform-origin:top}
      .mind-info-bar[data-collapsed="true"] .mind-info-content{opacity:0;transform:translateY(-6px);pointer-events:none}
      .mind-info-handle{align-self:center;display:flex;align-items:center;justify-content:center;width:36px;height:20px;border-radius:999px;border:1px solid rgba(201,168,106,.32);background:rgba(201,168,106,.12);color:var(--gold-400);font:600 12px/1 'Inter','Noto Sans SC',sans-serif;letter-spacing:.16em;text-transform:uppercase;cursor:pointer;transition:background var(--t-fast) var(--ease),border-color var(--t-fast) var(--ease),transform var(--t-fast) var(--ease);margin-bottom:4px}
      .mind-info-handle .icon{font-size:14px;line-height:1}
      .mind-info-handle:hover{background:rgba(201,168,106,.2);border-color:rgba(227,198,139,.5)}
      .mind-info-handle:focus-visible{outline:3px solid rgba(75,195,209,.35);outline-offset:2px}
      .mind-info-bar[data-collapsed="true"] .mind-info-handle{margin-bottom:0}
      .mind-info-row{display:flex;align-items:center;gap:12px}
      .map-io{position:relative;flex:0 0 auto}
      .map-io-button{padding:10px 16px;border-radius:16px;border:1px solid rgba(201,168,106,.36);background:rgba(201,168,106,.12);color:var(--gold-400);font:600 12px/1 'Inter','Noto Sans SC',sans-serif;letter-spacing:.12em;text-transform:uppercase;cursor:pointer;transition:background var(--t-fast) var(--ease),border-color var(--t-fast) var(--ease),transform var(--t-fast) var(--ease)}
      .map-io-button:hover{background:rgba(201,168,106,.2);border-color:rgba(227,198,139,.5)}
      .map-io-button:focus-visible{outline:3px solid rgba(75,195,209,.35);outline-offset:2px}
      .map-io[aria-expanded="true"] .map-io-button{background:rgba(201,168,106,.24)}
      .map-io-menu{position:absolute;top:calc(100% + 10px);right:0;display:none;flex-direction:column;gap:8px;padding:12px;border-radius:16px;border:1px solid rgba(201,168,106,.32);background:linear-gradient(180deg,rgba(21,26,30,.96),rgba(12,16,18,.92));box-shadow:0 16px 42px rgba(0,0,0,.55);min-width:180px;z-index:30}
      .map-io[aria-expanded="true"] .map-io-menu{display:flex}
      .map-io-menu button{padding:10px 12px;border-radius:12px;border:1px solid rgba(201,168,106,.28);background:rgba(21,26,30,.78);color:var(--text-strong);font:600 13px/1 'Inter','Noto Sans SC',sans-serif;letter-spacing:.12em;text-transform:uppercase;cursor:pointer;transition:border-color var(--transition),background-color var(--transition),color var(--transition);text-align:left}
      .map-io-menu button:hover{border-color:rgba(227,198,139,.6);background:rgba(201,168,106,.12);color:var(--gold-400)}
      .mind-export-overlay{position:fixed;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:18px;padding:32px;background:rgba(6,8,10,.82);backdrop-filter:blur(20px);z-index:400;opacity:0;visibility:hidden;pointer-events:none;transition:opacity var(--transition),visibility var(--transition)}
      .mind-export-overlay[data-active="true"]{opacity:1;visibility:visible;pointer-events:auto}
      .mind-export-overlay .export-spinner{width:74px;height:74px;border-radius:50%;border:3px solid rgba(201,168,106,.18);border-top-color:rgba(227,198,139,.78);animation:exportSpin 1s linear infinite}
      .mind-export-overlay .export-label{font:600 14px/1.6 'Inter','Noto Sans SC',sans-serif;letter-spacing:.18em;text-transform:uppercase;color:var(--gold-400);text-align:center}
      .mind-export-overlay .export-subtext{font:12px/1.6 'Inter','Noto Sans SC',sans-serif;color:var(--text-muted);letter-spacing:.12em;text-align:center;max-width:320px}
      @media (prefers-reduced-motion: reduce){.mind-export-overlay{transition:none}.mind-export-overlay .export-spinner{animation-duration:1.6s}}
      @keyframes exportSpin{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}
      .mind-import-backdrop{position:fixed;inset:0;display:none;align-items:center;justify-content:center;padding:24px;background:rgba(6,8,10,.78);backdrop-filter:blur(18px);z-index:180}
      .mind-import-backdrop[data-open="true"]{display:flex}
      .mind-import-panel{background:linear-gradient(165deg,rgba(21,26,30,.95),rgba(12,16,18,.92));border:1px solid rgba(201,168,106,.36);border-radius:24px;box-shadow:0 32px 68px rgba(0,0,0,.68),0 0 32px rgba(227,198,139,.18);padding:24px;max-width:420px;width:100%;display:grid;gap:16px;position:relative}
      .mind-import-panel::before{content:"";position:absolute;inset:10px;border-radius:18px;border:1px dashed rgba(201,168,106,.26);opacity:.75;pointer-events:none}
      .mind-import-panel h3{margin:0;font:600 18px/1.3 'Cinzel','Noto Serif SC',serif;color:var(--gold-400);letter-spacing:.16em;text-transform:uppercase;text-align:center}
      .mind-import-panel p{margin:0;color:var(--text-muted);font:13px/1.6 'Inter','Noto Sans SC',sans-serif;letter-spacing:.08em;text-align:center}
      .mind-import-options{display:grid;gap:10px}
      .mind-import-options button{padding:12px 16px;border-radius:16px;border:1px solid rgba(227,198,139,.62);background:linear-gradient(135deg,rgba(227,198,139,.82),rgba(170,140,84,.58));color:#1b1306;font:600 13px/1 'Inter','Noto Sans SC',sans-serif;text-transform:uppercase;letter-spacing:.14em;cursor:pointer;transition:transform var(--transition),box-shadow var(--transition)}
      .mind-import-options button:hover{transform:translateY(-2px);box-shadow:0 18px 36px rgba(227,198,139,.32),0 0 28px rgba(227,198,139,.24)}
      .mind-import-footer{display:flex;justify-content:flex-end}
      .mind-import-footer button{padding:10px 16px;border-radius:12px;border:1px solid rgba(201,168,106,.36);background:rgba(21,26,30,.82);color:var(--gold-400);font:600 12px/1 'Inter','Noto Sans SC',sans-serif;text-transform:uppercase;letter-spacing:.14em;cursor:pointer;transition:transform var(--transition),box-shadow var(--transition)}
      .mind-import-footer button:hover{transform:translateY(-2px);box-shadow:0 12px 26px rgba(227,198,139,.22)}
      .attachment-preview-backdrop{position:fixed;inset:0;display:none;align-items:center;justify-content:center;padding:20px;background:rgba(5,7,10,.78);backdrop-filter:blur(18px);z-index:280}
      .attachment-preview-backdrop[data-open="true"]{display:flex}
      .attachment-preview-panel{width:min(960px,calc(100vw - 32px));max-height:min(90vh,96svh);background:linear-gradient(165deg,rgba(21,26,30,.95),rgba(12,16,18,.92));border:1px solid rgba(201,168,106,.34);border-radius:24px;box-shadow:0 32px 64px rgba(0,0,0,.72),0 0 34px rgba(227,198,139,.2);padding:20px;display:flex;flex-direction:column;gap:16px;position:relative}
      .attachment-preview-header{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}
      .attachment-preview-title{margin:0;font:600 18px/1.2 'Cinzel','Noto Serif SC',serif;color:var(--gold-400);letter-spacing:.16em;text-transform:uppercase}
      .attachment-preview-meta{color:var(--text-muted);font:12px/1.5 'Inter','Noto Sans SC',sans-serif;letter-spacing:.1em}
      .attachment-preview-close{border:1px solid rgba(201,168,106,.32);background:rgba(15,19,22,.8);color:var(--gold-400);border-radius:50%;width:36px;height:36px;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:18px;transition:border-color var(--transition),transform var(--transition)}
      .attachment-preview-close:hover{border-color:rgba(227,198,139,.6);transform:translateY(-1px)}
      .attachment-preview-body{position:relative;flex:1 1 auto;min-height:220px;padding:16px;border-radius:18px;border:1px solid rgba(201,168,106,.26);background:rgba(12,16,18,.82);box-shadow:inset 0 0 22px rgba(0,0,0,.45);overflow:auto}
      .attachment-preview-body img,.attachment-preview-body video,.attachment-preview-body iframe{display:block;width:100%;height:100%;max-height:70vh;border-radius:14px;background:#050607}
      .attachment-preview-body video{background:#000}
      .attachment-preview-body iframe{border:0;min-height:60vh}
      .attachment-preview-loading{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:var(--text-muted);font:14px/1.6 'Inter','Noto Sans SC',sans-serif;letter-spacing:.08em}
      .attachment-preview-error{color:#fca5a5;font:14px/1.6 'Inter','Noto Sans SC',sans-serif;padding:18px;text-align:center}
      .attachment-preview-list{list-style:none;margin:0;padding:0;display:grid;gap:8px}
      .attachment-preview-list li{padding:10px 12px;border-radius:12px;border:1px solid rgba(201,168,106,.28);background:rgba(12,16,18,.78);font:13px/1.5 'Inter','Noto Sans SC',sans-serif;letter-spacing:.04em;color:var(--text-strong);display:flex;justify-content:space-between;gap:12px;align-items:center}
      .attachment-preview-list li .entry-size{color:var(--text-muted);font-size:12px;letter-spacing:.08em}
      .attachment-preview-footer{display:flex;justify-content:flex-end;gap:10px;flex-wrap:wrap}
      .attachment-preview-download{display:inline-flex;align-items:center;justify-content:center;padding:10px 16px;border-radius:12px;border:1px solid rgba(201,168,106,.36);background:rgba(15,19,22,.85);color:var(--gold-400);font:600 12px/1 'Inter','Noto Sans SC',sans-serif;letter-spacing:.14em;text-transform:uppercase;cursor:pointer;text-decoration:none;transition:transform var(--transition),box-shadow var(--transition)}
      .attachment-preview-download:hover{transform:translateY(-1px);box-shadow:0 16px 32px rgba(227,198,139,.25)}
      .map-back{display:inline-flex;align-items:center;gap:6px;padding:8px 12px;border-radius:999px;border:1px solid rgba(201,168,106,.32);background:rgba(201,168,106,.08);font:600 12px/1 'Inter','Noto Sans SC',sans-serif;letter-spacing:.16em;text-transform:uppercase;color:var(--gold-400);transition:background var(--transition),border-color var(--transition),transform var(--transition)}
      .map-back:hover{border-color:rgba(227,198,139,.6);background:rgba(201,168,106,.16);transform:translateY(-1px)}
      .mind-info-row .map-title-input{flex:1;min-width:0}
      .map-meta{display:flex;flex-wrap:wrap;gap:10px;align-items:center;font:600 11px/1.4 'Inter','Noto Sans SC',sans-serif;letter-spacing:.16em;text-transform:uppercase;color:var(--text-muted)}
      .map-meta span{white-space:nowrap}
      .map-delete-btn{margin-left:auto;padding:6px 12px;border-radius:12px;border:1px solid rgba(209,75,75,.52);background:rgba(209,75,75,.12);color:#F6D6D6;font:600 11px/1 'Inter','Noto Sans SC',sans-serif;letter-spacing:.14em;text-transform:uppercase;cursor:pointer;transition:border-color var(--transition),background var(--transition)}
      .map-delete-btn:hover{border-color:rgba(209,75,75,.72);background:rgba(209,75,75,.18)}
      .map-delete-btn:focus-visible{outline:3px solid rgba(209,75,75,.35);outline-offset:2px}
      .map-delete-btn[disabled]{opacity:.5;cursor:not-allowed}
      .map-title-input{width:100%;padding:12px 16px;border-radius:16px;border:1px solid rgba(201,168,106,.34);background:rgba(12,16,18,.78);color:var(--text-strong);font:600 17px/1.35 'Cinzel','Noto Serif SC',serif;letter-spacing:.08em;transition:border-color var(--transition),box-shadow var(--transition)}
      .map-title-input:focus{outline:none;border-color:var(--gold-500);box-shadow:0 0 0 3px rgba(227,198,139,.18)}
      .save-state{margin-left:auto;padding:6px 12px;border-radius:999px;border:1px solid rgba(201,168,106,.32);background:rgba(201,168,106,.12);font:600 11px/1 'Inter','Noto Sans SC',sans-serif;letter-spacing:.16em;text-transform:uppercase;color:var(--gold-400);opacity:0;transform:translateY(-6px);transition:opacity var(--t-fast) var(--ease),transform var(--t-fast) var(--ease)}
      .save-state.show{opacity:1;transform:translateY(0)}
      .save-state.dirty{color:var(--accent-crimson);border-color:rgba(209,75,75,.45);background:rgba(209,75,75,.12)}
      @media (max-width:720px){
        .mind-info-bar{left:50%;top:calc(var(--safe-top) + 16px);transform:translateX(-50%);width:calc(100% - 24px);max-width:none;padding:8px 12px 10px;gap:6px;border-radius:18px}
        .mind-info-bar[data-collapsed="true"]{padding:0 10px}
        .mind-info-handle{width:32px;height:20px;margin-bottom:2px}
        .mind-info-content{align-items:center}
        .mind-info-row{gap:8px;flex-direction:column;align-items:center;justify-content:center;text-align:center}
        .mind-info-row .map-title-input{text-align:center;max-width:260px;margin:0 auto}
        .map-back{padding:5px 9px;font-size:11px}
        .map-title-input{padding:8px 12px;font-size:15px}
        .save-state{font-size:10px;padding:4px 10px}
        .map-meta{font-size:10px;gap:8px;letter-spacing:.12em;justify-content:space-between}
        .map-meta span{flex:1 1 auto;min-width:0}
      }
      .mind-stage{position:relative;flex:1 1 auto;min-height:0;border-radius:28px;border:1px solid rgba(201,168,106,.24);background:linear-gradient(160deg,rgba(15,19,22,.9),rgba(10,12,14,.94));box-shadow:inset 0 0 48px rgba(0,0,0,.6),0 18px 38px rgba(0,0,0,.45);overflow:hidden}
      .mind-stage::before{content:"";position:absolute;inset:14px;border-radius:20px;border:1px dashed rgba(201,168,106,.2);opacity:.4;pointer-events:none}
      #jsmind-container{position:absolute;inset:0;overflow:hidden;touch-action:none;background:transparent}
      .mind-background{position:absolute;inset:0;background:radial-gradient(circle at 18% 24%,rgba(227,198,139,.08),transparent 55%),radial-gradient(circle at 68% 12%,rgba(227,198,139,.05),transparent 60%),linear-gradient(120deg,rgba(201,168,106,.06),transparent 65%);pointer-events:none;opacity:.8}
      .mind-viewport,.mind-links{position:absolute;top:0;left:0;transform-origin:0 0}
      .mind-links{pointer-events:auto;overflow:visible}
      .mind-link-controls{position:absolute;top:0;left:0;width:100%;height:100%;pointer-events:none;z-index:12}
      .mind-link-controls .edge-insert-btn{position:absolute;display:flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:14px;border:1px solid rgba(227,198,139,.65);background:linear-gradient(160deg,rgba(28,32,36,.96),rgba(12,16,18,.92));color:var(--gold-400);font:600 18px/1 'Inter','Noto Sans SC',sans-serif;letter-spacing:.08em;pointer-events:auto;cursor:pointer;box-shadow:0 12px 28px rgba(0,0,0,.52),0 0 0 1px rgba(227,198,139,.32) inset,0 0 26px rgba(227,198,139,.22);transform:translate(-50%,-50%);transition:transform var(--transition),background-color var(--transition),border-color var(--transition),box-shadow var(--transition);text-shadow:0 0 10px rgba(227,198,139,.4);isolation:isolate}
      .mind-link-controls .edge-insert-btn::before{content:"";position:absolute;inset:5px;border-radius:12px;background:radial-gradient(circle at 50% 40%,rgba(227,198,139,.32),rgba(227,198,139,.08) 65%,rgba(227,198,139,0) 100%);box-shadow:inset 0 0 18px rgba(227,198,139,.24);z-index:-1;opacity:.9}
      .mind-link-controls .edge-insert-btn::after{content:"";position:absolute;inset:-10px;border-radius:18px;background:radial-gradient(circle,rgba(227,198,139,.18),rgba(227,198,139,0) 70%);filter:blur(4px);opacity:.75;z-index:-2}
      .mind-link-controls .edge-insert-btn:hover{background:linear-gradient(160deg,rgba(36,42,48,.98),rgba(18,22,26,.94));border-color:rgba(227,198,139,.85);box-shadow:0 16px 32px rgba(0,0,0,.55),0 0 0 1px rgba(227,198,139,.4) inset,0 0 34px rgba(227,198,139,.3)}
      .mind-link-controls .edge-insert-btn:focus-visible{outline:2px solid rgba(227,198,139,.85);outline-offset:3px}
      .mind-links .trace-group{pointer-events:none}
      .mind-links .trace{fill:none;stroke-linecap:round;stroke-linejoin:bevel}
      .mind-links .trace.shadow{stroke:rgba(122,94,54,.55);stroke-width:2.1;opacity:.65;filter:url(#mindSoftGlow)}
      .mind-links .trace.core{stroke:url(#mindGoldTrace);stroke-width:1.6;filter:url(#mindSoftGlow)}
      .mind-links .trace.highlight{stroke:rgba(255,242,218,.32);stroke-width:0.8}
      .mind-relations{position:absolute;top:0;left:0;pointer-events:none;overflow:visible}
      .mind-relations .relation-group{pointer-events:none}
      .mind-relations path{fill:none;stroke-linecap:round;stroke-linejoin:round}
      .mind-relations .relation-shadow{stroke:rgba(122,94,54,.55);stroke-width:2.1;opacity:.65;filter:url(#mindSoftGlow)}
      .mind-relations .relation-core{stroke:url(#mindGoldTrace);stroke-width:1.6;filter:url(#mindSoftGlow)}
      .mind-relations .relation-highlight{stroke:rgba(255,242,218,.32);stroke-width:0.8}
      .mind-relations .relation-core[data-bidirectional="true"]{stroke-dasharray:0}
      .mind-nodes{position:absolute;top:0;left:0;pointer-events:none}
      .jsmind-node{position:absolute;display:flex;flex-direction:column;align-items:flex-start;gap:10px;padding:18px 20px;border-radius:var(--r-md);color:var(--text-strong);font:600 14px/1.5 'Inter','Noto Sans SC',sans-serif;min-width:170px;max-width:320px;background:linear-gradient(180deg,rgba(21,26,30,.94),rgba(15,19,22,.96));border:1.6px solid rgba(201,168,106,.32);box-shadow:0 20px 48px rgba(0,0,0,.58),0 0 30px rgba(227,198,139,.12);transition:transform var(--transition),box-shadow var(--transition),border-color var(--transition),filter var(--transition);backdrop-filter:blur(12px);letter-spacing:.04em;pointer-events:auto}
      .jsmind-node::before{content:"";position:absolute;inset:10px;border-radius:calc(var(--r-md) - 4px);border:1px solid rgba(201,168,106,.28);opacity:.85;pointer-events:none;box-shadow:0 0 24px rgba(227,198,139,.18)}
      .jsmind-node::after{content:"";position:absolute;inset:-14px;border-radius:calc(var(--r-md) + 10px);border:1px dashed rgba(201,168,106,.26);opacity:0;pointer-events:none;box-shadow:0 0 24px rgba(227,198,139,.16)}
      .jsmind-node .node-topic{font:600 16px/1.45 'Cinzel','Noto Serif SC',serif;color:var(--gold-400);letter-spacing:.06em;text-transform:uppercase}
      .jsmind-node .node-meta{display:flex;flex-wrap:wrap;gap:8px;color:var(--text-dim);font:500 12px/1.4 'Inter','Noto Sans SC',sans-serif;text-transform:uppercase;letter-spacing:.16em}
      .jsmind-node .node-meta span{padding:2px 8px;border-radius:999px;border:1px solid rgba(201,168,106,.28);background:rgba(21,26,30,.78)}
      .jsmind-node .node-body{color:var(--text-muted);font:400 13px/1.7 'Noto Sans SC','Inter',sans-serif}
      .jsmind-node .node-note{color:var(--text-muted);font:400 13px/1.7 'Noto Sans SC','Inter',sans-serif;white-space:pre-wrap;word-break:break-word}
      .jsmind-node .node-footer{display:flex;gap:8px;flex-wrap:wrap;font:600 11px/1 'Inter','Noto Sans SC',sans-serif;color:var(--text-dim);letter-spacing:.14em;text-transform:uppercase}
      .jsmind-node.isroot{border-width:2px;border-color:rgba(227,198,139,.55);box-shadow:0 0 0 1px rgba(227,198,139,.25),0 30px 60px rgba(0,0,0,.6)}
      .jsmind-node.isroot::after{opacity:.42;transform:scale(.95);box-shadow:0 0 36px rgba(227,198,139,.28)}
      .jsmind-node.selected{border-color:var(--gold-400);box-shadow:0 0 0 1px rgba(227,198,139,.32),0 0 40px rgba(227,198,139,.26);transform:translateY(-2px)}
      .jsmind-node.selected::after{opacity:.6;transform:scale(.98);box-shadow:0 0 40px rgba(75,195,209,.3)}
      .jsmind-node.edge-glow{border-color:rgba(227,198,139,.58);box-shadow:0 20px 48px rgba(0,0,0,.58),0 0 32px rgba(227,198,139,.18),0 0 46px rgba(227,198,139,.24)}
      .jsmind-node.edge-glow::after{opacity:.6;transform:scale(.98);box-shadow:0 0 44px rgba(227,198,139,.28)}
      .jsmind-node.edge-glow.selected{border-color:var(--gold-400)}
      .jsmind-node.edge-glow.selected::after{box-shadow:0 0 44px rgba(75,195,209,.3),0 0 40px rgba(227,198,139,.26)}
      .jsmind-node.relation-source{border-color:rgba(75,195,209,.7);box-shadow:0 0 0 2px rgba(75,195,209,.35),0 0 36px rgba(75,195,209,.28)}
      .jsmind-node.relation-target{border-style:dashed;border-color:rgba(75,195,209,.6)}
      .jsmind-node.is-collapsed{border-style:dashed;border-color:rgba(201,168,106,.4);background:linear-gradient(180deg,rgba(21,26,30,.86),rgba(12,16,18,.9))}
      .jsmind-node:not(.isroot) .node-topic::before{content:"";display:inline-block;width:6px;height:6px;margin-right:8px;border-radius:50%;background:var(--gold-400);box-shadow:0 0 6px rgba(227,198,139,.4);vertical-align:middle}
      .node-collapse-marker{position:absolute;right:18px;bottom:16px;display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:999px;border:1px solid rgba(201,168,106,.32);background:rgba(201,168,106,.12);color:var(--gold-400);font:600 11px/1 'Inter','Noto Sans SC',sans-serif;letter-spacing:.14em;text-transform:uppercase;box-shadow:0 0 12px rgba(227,198,139,.18);cursor:pointer;pointer-events:auto;transition:background var(--transition),border-color var(--transition),transform var(--transition)}
      .node-collapse-marker:hover{transform:translateY(-1px);border-color:rgba(201,168,106,.48)}
      .node-collapse-marker:focus-visible{outline:3px solid rgba(75,195,209,.35);outline-offset:2px}
      .node-collapse-marker .icon{font-size:14px;line-height:1}
      .jsmind-node.is-collapsed .node-collapse-marker{background:rgba(201,168,106,.2);border-color:rgba(201,168,106,.46)}
      .mind-dock-wrap{position:fixed;left:50%;bottom:calc(var(--safe-bottom) + 18px);transform:translateX(-50%);pointer-events:none;z-index:120;width:min(calc(100vw - 32px - var(--safe-left) - var(--safe-right)),860px)}
      .mind-dock{pointer-events:auto;display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:14px 18px;padding:16px 24px;border-radius:32px;background:linear-gradient(180deg,rgba(21,26,30,.9),rgba(12,16,18,.85));border:1px solid rgba(201,168,106,.32);box-shadow:0 18px 40px rgba(0,0,0,.55),0 0 32px rgba(227,198,139,.12) inset;backdrop-filter:blur(12px);position:relative;width:100%;box-sizing:border-box;touch-action:manipulation}
      .dock-btn{position:relative;display:grid;grid-template-rows:auto auto;align-items:center;justify-items:center;width:92px;height:66px;border-radius:18px;padding:8px 6px;background:rgba(201,168,106,.08);border:1px solid rgba(201,168,106,.36);color:var(--gold-400);font:600 13px/1 'Inter','Noto Sans SC',sans-serif;text-transform:uppercase;letter-spacing:.12em;cursor:pointer;transition:transform var(--transition),border-color var(--transition),box-shadow var(--transition),background-color var(--transition);touch-action:manipulation;flex:0 0 92px}
      .dock-btn .icon{font-size:20px}
      .dock-btn .label{font-size:12px}
      @media (hover:hover) and (pointer:fine){
        .dock-btn[data-tip]::after{content:attr(data-tip);position:absolute;bottom:100%;left:50%;transform:translate(-50%,6px);padding:6px 10px;border-radius:12px;border:1px solid rgba(201,168,106,.38);background:rgba(12,16,18,.92);color:var(--gold-400);font:600 11px/1 'Inter','Noto Sans SC',sans-serif;letter-spacing:.12em;text-transform:uppercase;white-space:nowrap;opacity:0;pointer-events:none;transition:opacity var(--t-fast) var(--ease),transform var(--t-fast) var(--ease);box-shadow:0 12px 28px rgba(0,0,0,.45)}
        .dock-btn[data-tip]::before{content:"";position:absolute;bottom:100%;left:50%;transform:translate(-50%,6px);border-width:6px;border-style:solid;border-color:rgba(12,16,18,.92) transparent transparent transparent;opacity:0;transition:opacity var(--t-fast) var(--ease),transform var(--t-fast) var(--ease)}
        .dock-btn[data-tip]:hover::after,.dock-btn[data-tip]:focus-visible::after{opacity:1;transform:translate(-50%,-4px)}
        .dock-btn[data-tip]:hover::before,.dock-btn[data-tip]:focus-visible::before{opacity:1;transform:translate(-50%,-4px)}
      }
      .dock-btn:hover{transform:translateY(-3px);border-color:var(--gold-500);background:rgba(201,168,106,.16);box-shadow:0 0 26px rgba(227,198,139,.18)}
      .dock-btn:active{transform:translateY(-1px)}
      .dock-btn:focus-visible{outline:3px solid rgba(75,195,209,.35);outline-offset:2px}
      .dock-btn[disabled]{opacity:.5;cursor:not-allowed;transform:none}
      .dock-btn.danger{color:#F6D6D6;border-color:rgba(209,75,75,.52);background:rgba(209,75,75,.12)}
      .dock-btn.ghost{background:rgba(201,168,106,.04);border-style:dashed}
      .dock-btn[data-state="dirty"]{color:#F6D6D6;border-color:rgba(209,75,75,.6);background:rgba(209,75,75,.18)}
      .dock-btn[data-state="saving"]{color:var(--gold-500)}
      .dock-btn[data-state="saved"]{color:var(--gold-400)}
      .dock-sep{width:12px;height:44px;border-right:1px solid rgba(201,168,106,.24);opacity:.6}
      .mind-shell[data-fisheye="on"] .dock-btn{transform-origin:50% 65%}
      @media (max-width:960px){.mind-dock-wrap{width:min(calc(100vw - 28px),760px)}.mind-dock{gap:12px 16px;padding:14px 20px;border-radius:30px}.dock-btn{width:88px;height:62px;flex:0 0 88px}}
      @media (max-width:720px){.mind-dock-wrap{width:calc(100vw - 24px)}.mind-dock{padding:12px 18px;border-radius:26px;gap:12px}.dock-btn{width:84px;height:58px;flex:0 0 84px}.dock-btn .label{font-size:11px}}
      @media (max-width:520px){.mind-dock-wrap{width:calc(100vw - 20px)}.mind-dock{padding:12px 16px;gap:10px}.dock-btn{width:78px;height:56px;flex:0 0 78px}.dock-btn .icon{font-size:18px}.dock-sep{display:none}}
      @media (prefers-reduced-motion: reduce){.dock-btn,.dock-btn:hover{transition:none!important;transform:none!important}.mind-shell[data-fisheye="on"] .dock-btn{transform:none!important}}
      .mind-relation-toast{position:absolute;left:50%;top:24px;transform:translateX(-50%) translateY(-8px);padding:10px 16px;border-radius:18px;border:1px solid rgba(75,195,209,.4);background:rgba(10,16,20,.88);color:rgba(191,242,255,.92);font:600 12px/1.4 'Inter','Noto Sans SC',sans-serif;letter-spacing:.12em;text-transform:uppercase;box-shadow:0 18px 40px rgba(0,0,0,.55);opacity:0;pointer-events:none;transition:opacity var(--transition),transform var(--transition);z-index:110}
      .mind-relation-toast[data-visible="true"]{opacity:1;transform:translateX(-50%) translateY(0)}
      .mind-shell[data-relation-mode="pending"] .mind-stage::after{content:"";position:absolute;inset:0;border:1px dashed rgba(75,195,209,.35);border-radius:inherit;pointer-events:none;animation:relationPulse 1.2s infinite ease-in-out}
      @keyframes relationPulse{0%{opacity:.35}50%{opacity:.8}100%{opacity:.35}}
      .node-popover{position:fixed;z-index:140;min-width:320px;max-width:360px;border-radius:20px;border:1px solid rgba(201,168,106,.32);background:linear-gradient(180deg,rgba(21,26,30,.96),rgba(12,16,18,.92));box-shadow:0 24px 60px rgba(0,0,0,.65),0 0 28px rgba(227,198,139,.14) inset;padding:16px;display:grid;gap:14px;backdrop-filter:blur(12px);transition:transform .24s ease,opacity .24s ease}
      .node-popover[hidden]{display:none!important}
      .node-popover[data-mode="sheet"]{left:50%!important;bottom:0!important;top:auto!important;transform:translateX(-50%);width:calc(100% - 24px);max-width:none;border-radius:20px 20px 0 0;padding-bottom:28px;touch-action:pan-y}
      .node-popover.dragging{transition:none!important}
      .node-popover .sheet-handle{display:none;width:56px;height:5px;border-radius:999px;background:rgba(201,168,106,.32);margin:4px auto 8px}
      .node-popover[data-mode="sheet"] .sheet-handle{display:block}
      .node-popover header{display:flex;align-items:center;justify-content:space-between;gap:12px}
      .node-popover h2{margin:0;font:600 16px/1 'Cinzel','Noto Serif SC',serif;color:var(--gold-400);letter-spacing:.14em;text-transform:uppercase}
      .node-popover button.close{background:none;border:1px solid rgba(201,168,106,.3);border-radius:999px;color:var(--gold-400);width:32px;height:32px;cursor:pointer}
      .node-popover button.close:hover{border-color:var(--gold-500)}
      .node-popover form{display:grid;gap:14px}
      .node-popover .field{display:grid;gap:8px}
      .node-popover label{font:600 12px/1.2 'Inter','Noto Sans SC',sans-serif;color:var(--text-muted);letter-spacing:.14em;text-transform:uppercase}
      .node-popover input,.node-popover select,.node-popover textarea{width:100%;padding:10px 12px;border-radius:14px;border:1px solid rgba(201,168,106,.3);background:rgba(12,16,18,.7);color:var(--text-strong);font:500 14px/1.5 'Noto Sans SC','Inter',sans-serif;letter-spacing:.04em;transition:border-color var(--transition),box-shadow var(--transition)}
      .node-popover input:focus,.node-popover select:focus,.node-popover textarea:focus{outline:none;border-color:var(--gold-500);box-shadow:0 0 0 2px rgba(227,198,139,.18)}
      .node-popover textarea{min-height:120px;resize:vertical}
      .node-popover .fold-field{padding-top:6px}
      .node-popover .fold-row{display:flex;align-items:center;justify-content:space-between;gap:12px}
      .toggle-switch{position:relative;display:inline-flex;align-items:center;gap:10px;cursor:pointer;color:var(--text-muted)}
      .toggle-switch input{position:absolute;opacity:0;width:1px;height:1px;overflow:hidden}
      .toggle-switch .track{position:relative;width:48px;height:24px;border-radius:999px;background:rgba(201,168,106,.14);border:1px solid rgba(201,168,106,.3);transition:background var(--transition),border-color var(--transition)}
      .toggle-switch .thumb{position:absolute;top:2px;left:2px;width:20px;height:20px;border-radius:50%;background:var(--gold-500);box-shadow:0 0 12px rgba(227,198,139,.45);transition:transform var(--transition)}
      .toggle-switch input:checked + .track{background:rgba(36,194,160,.2);border-color:rgba(36,194,160,.4)}
      .toggle-switch input:checked + .track .thumb{transform:translateX(24px)}
      .toggle-switch .toggle-text{font:600 11px/1 'Inter','Noto Sans SC',sans-serif;letter-spacing:.14em;text-transform:uppercase;color:var(--text-dim)}
      .fold-hint{margin:8px 0 0;font:500 12px/1.4 'Inter','Noto Sans SC',sans-serif;color:var(--text-muted)}
      .node-popover .popover-actions{display:flex;justify-content:flex-end;gap:10px}
      .node-popover .popover-actions button{padding:10px 16px;border-radius:14px;border:1px solid rgba(201,168,106,.32);background:rgba(21,26,30,.78);color:var(--gold-400);font:600 12px/1 'Inter','Noto Sans SC',sans-serif;letter-spacing:.12em;text-transform:uppercase;cursor:pointer;transition:border-color var(--transition)}
      .node-popover .popover-actions button.accent{background:linear-gradient(135deg,rgba(201,168,106,.24),rgba(170,140,84,.28));color:var(--bg-void)}
      .node-popover .popover-actions button:hover{border-color:rgba(227,198,139,.6)}
      .node-popover.disabled{pointer-events:none;opacity:.6}
      .relation-list{display:flex;flex-wrap:wrap;gap:8px;margin:6px 0 0}
      .relation-pill{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:999px;border:1px solid rgba(75,195,209,.45);background:rgba(75,195,209,.16);color:var(--text-strong);font:600 11px/1 'Inter','Noto Sans SC',sans-serif;letter-spacing:.1em;text-transform:uppercase}
      .relation-pill button{border:0;background:transparent;color:rgba(255,255,255,.7);cursor:pointer;font-size:13px;line-height:1;padding:0 2px}
      .relation-pill button:hover{color:#fff}
      .relation-hint{margin:6px 0 0;font:500 11px/1.4 'Inter','Noto Sans SC',sans-serif;color:var(--text-muted)}
      #node-relations-field.empty .relation-list{display:none}
      .node-context-menu{position:fixed;z-index:150;min-width:180px;padding:12px;margin:0;list-style:none;border-radius:18px;border:1px solid rgba(201,168,106,.32);background:linear-gradient(180deg,rgba(21,26,30,.96),rgba(12,16,18,.92));box-shadow:0 22px 48px rgba(0,0,0,.6);display:grid;gap:8px}
      .node-context-menu[hidden]{display:none!important}
      .node-context-menu button{padding:10px 12px;border-radius:12px;border:1px solid rgba(201,168,106,.28);background:rgba(21,26,30,.78);color:var(--text-strong);font:600 13px/1 'Inter','Noto Sans SC',sans-serif;letter-spacing:.12em;text-transform:uppercase;cursor:pointer;transition:border-color var(--transition),background-color var(--transition)}
      .node-context-menu button:hover{border-color:rgba(201,168,106,.6);background:rgba(201,168,106,.12);color:var(--gold-400)}
      .node-context-menu[data-mode="sheet"]{left:50%!important;bottom:0!important;top:auto!important;transform:translateX(-50%);width:calc(100% - 24px);max-width:none;border-radius:20px 20px 0 0;padding:16px 16px 28px;gap:12px}
      .node-context-menu[data-mode="sheet"] button{width:100%}
      .mind-settings{position:fixed;inset:0;display:none;align-items:center;justify-content:center;padding:20px;z-index:130;background:rgba(5,6,8,.6);backdrop-filter:blur(8px)}
      .mind-settings[aria-hidden="false"]{display:flex}
      .mind-settings .settings-panel{background:linear-gradient(180deg,rgba(21,26,30,.96),rgba(12,16,18,.9));border:1px solid rgba(201,168,106,.32);border-radius:22px;box-shadow:0 32px 60px rgba(0,0,0,.65);padding:20px 22px;display:grid;gap:16px;min-width:280px;max-width:360px}
      .mind-settings h3{margin:0;font:600 16px/1 'Cinzel','Noto Serif SC',serif;color:var(--gold-400);letter-spacing:.14em;text-transform:uppercase}
      .mind-settings label{display:flex;align-items:center;gap:10px;color:var(--text-strong);font:500 14px/1.4 'Noto Sans SC','Inter',sans-serif}
      .mind-settings .settings-panel header{display:flex;align-items:center;justify-content:space-between;gap:12px}
      .mind-settings .settings-panel button.close{width:32px;height:32px;border-radius:999px;border:1px solid rgba(201,168,106,.32);background:none;color:var(--gold-400);cursor:pointer}
      .mind-settings .settings-panel button.close:hover{border-color:var(--gold-500)}
      .mind-settings .settings-actions{display:flex;justify-content:flex-end;gap:10px}
      .mind-settings .settings-actions button{padding:10px 14px;border-radius:14px;border:1px solid rgba(201,168,106,.3);background:rgba(21,26,30,.78);color:var(--gold-400);font:600 12px/1 'Inter','Noto Sans SC',sans-serif;letter-spacing:.12em;text-transform:uppercase;cursor:pointer}
      .sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
      body.grid-off::after{opacity:0!important}
    </style>

  </head>
  <body>
    <div class="scanlines" aria-hidden="true"></div>
    <svg width="0" height="0" aria-hidden="true" focusable="false" style="position:absolute">
      <defs>
        <linearGradient id="mindGoldTrace" x1="0" y1="0" x2="1" y2="0">
          <stop offset="0%" stop-color="#E3C68B" />
          <stop offset="50%" stop-color="#C9A86A" />
          <stop offset="100%" stop-color="#AA8C54" />
        </linearGradient>
        <filter id="mindSoftGlow" x="-50%" y="-50%" width="200%" height="200%">
          <feGaussianBlur in="SourceGraphic" stdDeviation="0.6" result="blur" />
          <feMerge>
            <feMergeNode in="blur" />
            <feMergeNode in="SourceGraphic" />
          </feMerge>
        </filter>
        <marker id="mindRelationArrow" markerWidth="12" markerHeight="12" refX="10" refY="6" orient="auto" markerUnits="strokeWidth">
          <path d="M0 0 L12 6 L0 12 Z" fill="#E3C68B" />
        </marker>
      </defs>
    </svg>
    <div class="mind-shell" data-fisheye="on">
      <div class="mind-stage">
        <header class="mind-info-bar" id="mind-info-bar" data-collapsed="false">
          <button type="button" class="mind-info-handle" id="mind-info-handle" aria-label="收起顶部栏" aria-expanded="true">
            <span class="icon" aria-hidden="true">∧</span>
          </button>
          <div class="mind-info-content" aria-hidden="false">
            <div class="mind-info-row">
              <a class="map-back" href="<?= htmlspecialchars($_SERVER['PHP_SELF'].'?cat=mindmaps') ?>" aria-label="返回导图库">← 导图库</a>
              <label class="sr-only" for="map-title">导图标题</label>
              <input id="map-title" class="map-title-input" value="<?php echo h($mind['title']); ?>" placeholder="输入导图标题">
              <div class="map-io" id="map-io" aria-expanded="false">
                <button type="button" class="map-io-button" id="map-io-button" aria-haspopup="true" aria-expanded="false">导入 / 导出</button>
                <div class="map-io-menu" id="map-io-menu" role="menu">
                  <button type="button" data-action="import" role="menuitem">导入 JSON</button>
                  <button type="button" data-action="export-json" role="menuitem">导出 JSON</button>
                  <button type="button" data-action="export-pdf" role="menuitem">导出 PDF</button>
                  <button type="button" data-action="export-jpg" role="menuitem">导出 JPG</button>
                </div>
              </div>
              <div class="save-state" id="save-state">保存成功</div>
            </div>
            <div class="map-meta">
              <span>导图 ID：<?php echo $mind['id'] ?: '新建'; ?></span>
              <span>最近保存：<?php echo dt((int)$mind['updated_at']); ?></span>
              <?php if ($mind['id']): ?>
              <button type="button" class="map-delete-btn" id="map-delete-btn">删除导图</button>
              <?php endif; ?>
            </div>
          </div>
        </header>
        <div id="jsmind-container" data-map-id="<?php echo $mind['id']; ?>"></div>
      </div>
      <input id="import-input" type="file" accept="application/json" hidden>
      <input id="attach-file-input" type="file" accept="image/*,video/*,audio/*,application/pdf,application/zip,application/x-zip-compressed,application/x-rar-compressed,application/vnd.rar,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.ms-word.document.macroenabled.12,application/vnd.openxmlformats-officedocument.wordprocessingml.template,application/vnd.ms-word.template.macroenabled.12,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel.sheet.macroenabled.12,application/vnd.ms-excel.sheet.binary.macroenabled.12,application/vnd.openxmlformats-officedocument.spreadsheetml.template,application/vnd.ms-excel.template.macroenabled.12,text/plain,text/markdown,text/csv,text/tab-separated-values,application/json,text/json" hidden>
      <?php if ($mind['id']): ?>
        <form id="delete-map-form" method="post" hidden>
          <input type="hidden" name="action" value="delete_mindmap">
          <input type="hidden" name="id" value="<?php echo $mind['id']; ?>">
        </form>
      <?php endif; ?>
      <div class="mind-dock-wrap">
        <nav class="mind-dock" id="mind-dock" role="toolbar" aria-label="思维导图操作工具栏">
          <button class="dock-btn" data-action="save" data-default-label="保存" data-tip="保存（Ctrl+S）" aria-label="保存">
            <span class="icon">💾</span>
            <span class="label">保存</span>
          </button>
          <button class="dock-btn" data-action="undo" data-tip="撤销（Ctrl/⌘+Z）" aria-label="撤销操作">
            <span class="icon">↺</span>
            <span class="label">撤销</span>
          </button>
          <button class="dock-btn" data-action="redo" data-tip="重做（Ctrl+Shift+Z）" aria-label="重做操作">
            <span class="icon">↻</span>
            <span class="label">重做</span>
          </button>
          <button class="dock-btn" data-action="sibling" data-tip="同级节点（Enter）" aria-label="新增同级节点">
            <span class="icon">⧉</span>
            <span class="label">同级</span>
          </button>
          <button class="dock-btn" data-action="child" data-tip="子级节点（Tab）" aria-label="新增子级节点">
            <span class="icon">↳</span>
            <span class="label">子级</span>
          </button>
          <button class="dock-btn" data-action="fold" data-tip="折叠/展开（Space 或 ←/→）" aria-label="折叠或展开节点">
            <span class="icon" data-fold-icon>⇅</span>
            <span class="label" data-fold-label>折叠</span>
          </button>
          <button class="dock-btn" data-action="attach" data-tip="上传附件" aria-label="上传附件">
            <span class="icon">📎</span>
            <span class="label">附件</span>
          </button>
          <button class="dock-btn" data-action="relation" data-tip="建立关联" aria-label="关联节点">
            <span class="icon">🪢</span>
            <span class="label">关联</span>
          </button>
          <button class="dock-btn" data-action="link" data-tip="新增链接" aria-label="新增链接">
            <span class="icon">🔗</span>
            <span class="label">链接</span>
          </button>
          <button class="dock-btn danger" data-action="delete" data-tip="删除（Backspace/Del）" aria-label="删除节点">
            <span class="icon">🗑</span>
            <span class="label">删除</span>
          </button>
        </nav>
      </div>
      <div class="mind-export-overlay" id="mind-export-overlay" role="status" aria-live="polite" aria-hidden="true">
        <div class="export-spinner" aria-hidden="true"></div>
        <div class="export-label">正在导出</div>
        <div class="export-subtext">请稍候，我们正在生成高清导出文件…</div>
      </div>
      <div class="mind-relation-toast" id="mind-relation-toast" role="status" aria-live="polite"></div>
    </div>
    <div class="mind-import-backdrop" id="mind-import-modal" data-open="false" role="dialog" aria-modal="true" aria-labelledby="mind-import-title">
      <div class="mind-import-panel">
        <h3 id="mind-import-title">导入导图</h3>
        <p>文件：<strong data-import-name>未选择文件</strong></p>
        <div class="mind-import-options">
          <button type="button" data-import-mode="replace">覆盖当前导图</button>
          <button type="button" data-import-mode="append-node">导入为新节点</button>
          <button type="button" data-import-mode="new-map">导入为新导图</button>
        </div>
        <div class="mind-import-footer">
          <button type="button" data-import-cancel>取消</button>
        </div>
      </div>
    </div>
    <div class="node-popover" id="node-popover" hidden>
      <div class="sheet-handle" aria-hidden="true"></div>
      <header>
        <h2>节点属性</h2>
        <button type="button" class="close" data-pop-close aria-label="关闭">×</button>
      </header>
      <form id="node-inspector" autocomplete="off">
        <div class="field">
          <label for="node-topic-input">标题</label>
          <input id="node-topic-input" type="text" placeholder="输入节点标题">
        </div>
        <div class="field">
          <label for="node-note">备注</label>
          <textarea id="node-note" rows="4" placeholder="例如：
状态：已完成
优先级：高
负责人：张三
标签：#重要 #任务"></textarea>
        </div>
      <div class="field" id="node-relations-field">
        <label>关联节点</label>
        <div class="relation-list" id="node-relations-list"></div>
        <p class="relation-hint">使用底部“关联”按钮在节点之间建立跨层级连线。</p>
      </div>
      <div class="popover-actions">
        <button type="button" data-pop-close>取消</button>
        <button type="button" class="accent" data-pop-save>完成</button>
      </div>
      </form>
    </div>
    <div class="node-context-menu" id="node-context-menu" hidden>
      <button type="button" data-menu-action="edit">编辑属性</button>
    </div>
    <div class="mind-settings" id="mind-settings" aria-hidden="true">
      <div class="settings-panel">
        <header>
          <h3>设置</h3>
          <button type="button" class="close" data-settings-close aria-label="关闭">×</button>
        </header>
        <label><input type="checkbox" id="setting-grid" checked> 显示背景网格</label>
        <label><input type="checkbox" id="setting-fisheye" checked> Dock 鱼眼放大</label>
        <div class="settings-actions">
          <button type="button" data-settings-close>关闭</button>
        </div>
      </div>
    </div>
    <script>
      (function(){
        if(window.AttachmentPreview) return;
        const readyQueue=window.__attachmentPreviewReadyCallbacks = window.__attachmentPreviewReadyCallbacks || [];
        const scriptCache=new Map();
        let elements=null;
        let objectUrl=null;
        let abortController=null;
        let lastActive=null;

        const formatBytes=value=>{
          if(!Number.isFinite(value) || value<=0) return '';
          const units=['B','KB','MB','GB','TB'];
          let idx=0; let num=value;
          while(num>=1024 && idx<units.length-1){ num/=1024; idx++; }
          const decimals=num>=100 || idx===0 ? 0 : 1;
          return num.toFixed(decimals)+' '+units[idx];
        };
        const ensureElements=()=>{
          if(elements) return elements;
          const backdrop=document.createElement('div');
          backdrop.className='attachment-preview-backdrop';
          const panel=document.createElement('div');
          panel.className='attachment-preview-panel';
          panel.setAttribute('role','dialog');
          panel.setAttribute('aria-modal','true');
          panel.setAttribute('aria-labelledby','attachment-preview-title');
          panel.tabIndex=-1;
          const header=document.createElement('div');
          header.className='attachment-preview-header';
          const titleWrap=document.createElement('div');
          const titleEl=document.createElement('div');
          titleEl.className='attachment-preview-title';
          titleEl.id='attachment-preview-title';
          const metaEl=document.createElement('div');
          metaEl.className='attachment-preview-meta';
          titleWrap.appendChild(titleEl);
          titleWrap.appendChild(metaEl);
          const closeBtn=document.createElement('button');
          closeBtn.className='attachment-preview-close';
          closeBtn.type='button';
          closeBtn.setAttribute('aria-label','关闭预览');
          closeBtn.textContent='×';
          header.appendChild(titleWrap);
          header.appendChild(closeBtn);
          const bodyEl=document.createElement('div');
          bodyEl.className='attachment-preview-body';
          const loadingEl=document.createElement('div');
          loadingEl.className='attachment-preview-loading';
          loadingEl.textContent='载入中…';
          bodyEl.appendChild(loadingEl);
          const footer=document.createElement('div');
          footer.className='attachment-preview-footer';
          const downloadBtn=document.createElement('a');
          downloadBtn.className='attachment-preview-download';
          downloadBtn.href='';
          downloadBtn.target='_blank';
          downloadBtn.rel='noopener';
          downloadBtn.textContent='下载附件';
          footer.appendChild(downloadBtn);
          panel.appendChild(header);
          panel.appendChild(bodyEl);
          panel.appendChild(footer);
          backdrop.appendChild(panel);
          document.body.appendChild(backdrop);
          const closePreview=()=>{
            backdrop.dataset.open='false';
            if(lastActive && typeof lastActive.focus==='function'){
              try{ lastActive.focus(); }catch(_){ /* ignore */ }
            }
            lastActive=null;
            if(abortController){ abortController.abort(); abortController=null; }
            if(objectUrl){ URL.revokeObjectURL(objectUrl); objectUrl=null; }
          };
          closeBtn.addEventListener('click',closePreview);
          backdrop.addEventListener('click',evt=>{ if(evt.target===backdrop) closePreview(); });
          document.addEventListener('keydown',evt=>{ if(evt.key==='Escape' && backdrop.dataset.open==='true'){ closePreview(); } });
          elements={backdrop,panel,titleEl,metaEl,closeBtn,bodyEl,loadingEl,downloadBtn,closePreview};
          return elements;
        };
        const cleanupObjectUrl=()=>{ if(objectUrl){ URL.revokeObjectURL(objectUrl); objectUrl=null; } };
        const showContent=content=>{
          const {bodyEl,loadingEl}=ensureElements();
          if(loadingEl && loadingEl.parentNode===bodyEl){ loadingEl.remove(); }
          bodyEl.innerHTML='';
          if(content) bodyEl.appendChild(content);
        };
        const showError=message=>{
          const div=document.createElement('div');
          div.className='attachment-preview-error';
          div.textContent=message||'预览失败';
          showContent(div);
        };
        const showList=entries=>{
          const list=document.createElement('ul');
          list.className='attachment-preview-list';
          entries.forEach(entry=>{
            const li=document.createElement('li');
            const label=document.createElement('span');
            label.textContent=entry.label;
            li.appendChild(label);
            if(entry.size){
              const sizeSpan=document.createElement('span');
              sizeSpan.className='entry-size';
              sizeSpan.textContent=entry.size;
              li.appendChild(sizeSpan);
            }
            list.appendChild(li);
          });
          showContent(list);
        };
        const renderMedia=(type,url,mime)=>{
          let el;
          if(type==='video'){
            el=document.createElement('video');
            el.controls=true;
            el.preload='metadata';
            el.playsInline=true;
            el.classList.add('attachment-preview-video');
          }else if(type==='audio'){
            el=document.createElement('audio');
            el.controls=true;
            el.preload='metadata';
            el.style.width='100%';
          }else if(type==='pdf'){
            el=document.createElement('iframe');
            el.type=mime||'application/pdf';
            el.setAttribute('title','PDF 预览');
          }else{
            el=document.createElement('img');
          }
          el.src=url;
          showContent(el);
        };
        const detectType=source=>{
          const mime=(source.mime||'').toLowerCase();
          const name=(source.name||'').toLowerCase();
          const ext=name.includes('.') ? name.split('.').pop() : '';
          if(mime.startsWith('image/') || ['png','jpg','jpeg','gif','webp','bmp','svg','avif','heic','heif'].includes(ext)) return 'image';
          if(mime.startsWith('video/') || ['mp4','mov','m4v','webm','ogv','mkv','avi'].includes(ext)) return 'video';
          if(mime.startsWith('audio/') || ['mp3','wav','ogg','oga','m4a','aac','flac','opus','weba'].includes(ext)) return 'audio';
          if(mime==='application/pdf' || ext==='pdf') return 'pdf';
          if(mime.includes('zip') || ext==='zip') return 'zip';
          if(mime.includes('rar') || ext==='rar') return 'rar';
          if(mime.includes('spreadsheet') || ['xlsx','xls','xlsm','xlsb','csv','xltx','xltm','tsv'].includes(ext)) return 'excel';
          if(mime.includes('word') || ['docx','docm','doc','dotx','dotm'].includes(ext)) return 'word';
          return 'other';
        };
        const normalizeSource=input=>{
          if(!input) return {kind:'url',url:'',name:'附件',mime:'',size:null,downloadName:null};
          if(typeof input==='string') return {kind:'url',url:input,name:'附件',mime:'',size:null,downloadName:null};
          const kind=input.kind ? String(input.kind) : (input.blob ? 'blob' : 'url');
          const sizeRaw = typeof input.size==='number' ? input.size : parseInt(input.size,10);
          const size=Number.isFinite(sizeRaw) && sizeRaw>0 ? sizeRaw : null;
          const name=typeof input.name==='string' && input.name.trim() ? input.name.trim() : '附件';
          return {
            kind,
            url:typeof input.url==='string'?input.url:'',
            blob:input.blob instanceof Blob ? input.blob : null,
            name,
            mime:typeof input.mime==='string'?input.mime:'',
            size,
            downloadName:typeof input.downloadName==='string' && input.downloadName.trim()?input.downloadName.trim():name
          };
        };
        const setDownloadLink=(btn,source)=>{
          let href='';
          if(source.kind==='blob' && source.blob){
            cleanupObjectUrl();
            objectUrl=URL.createObjectURL(source.blob);
            href=objectUrl;
          }else if(source.url){
            href=source.url;
          }
          if(btn){
            if(href){
              btn.href=href;
              btn.download=source.downloadName || source.name || 'attachment';
              btn.hidden=false;
            }else{
              btn.removeAttribute('href');
              btn.hidden=true;
            }
          }
          return href;
        };
        const loadBuffer=async source=>{
          if(source.kind==='blob' && source.blob){
            return await source.blob.arrayBuffer();
          }
          if(!source.url) throw new Error('缺少文件地址');
          abortController=new AbortController();
          try{
            const res=await fetch(source.url,{signal:abortController.signal});
            if(!res.ok) throw new Error('网络错误');
            return await res.arrayBuffer();
          }finally{
            abortController=null;
          }
        };
        const ensureScript=url=>{
          if(scriptCache.has(url)) return scriptCache.get(url);
          const promise=new Promise((resolve,reject)=>{
            const el=document.createElement('script');
            el.src=url;
            el.async=true;
            el.onload=()=>resolve();
            el.onerror=()=>reject(new Error('脚本加载失败'));
            document.head.appendChild(el);
          });
          scriptCache.set(url,promise);
          return promise;
        };
        const buildArchiveTree=entries=>{
          const root={name:'',type:'dir',children:new Map()};
          entries.forEach(entry=>{
            const rawPath=String(entry.path||'').replace(/\\/g,'/');
            const normalized=rawPath.replace(/^\/+/, '').trim();
            if(!normalized) return;
            const parts=normalized.split('/').filter(Boolean);
            let node=root;
            parts.forEach((part,index)=>{
              const isLast=index===parts.length-1;
              if(isLast && !entry.dir){
                node.children.set(part,{type:'file',name:part,size:entry.size||''});
              }else{
                let next=node.children.get(part);
                if(!next || next.type!=='dir'){
                  next={type:'dir',name:part,children:new Map()};
                  node.children.set(part,next);
                }
                node=next;
              }
            });
            if(entry.dir && parts.length){
              let dirNode=root;
              parts.forEach(part=>{
                let next=dirNode.children.get(part);
                if(!next || next.type!=='dir'){
                  next={type:'dir',name:part,children:new Map()};
                  dirNode.children.set(part,next);
                }
                dirNode=next;
              });
            }
          });
          return root;
        };
        const createTreeElement=(node,depth=0)=>{
          const ul=document.createElement('ul');
          ul.className='attachment-preview-tree';
          const items=Array.from(node.children.values());
          items.sort((a,b)=>{
            if(a.type!==b.type) return a.type==='dir'?-1:1;
            return a.name.localeCompare(b.name,'zh-Hans');
          });
          items.forEach(child=>{
            const li=document.createElement('li');
            if(child.type==='dir'){
              const details=document.createElement('details');
              if(depth<1) details.open=true;
              const summary=document.createElement('summary');
              const label=document.createElement('span');
              label.className='entry-label';
              label.textContent='📁 '+child.name;
              summary.appendChild(label);
              if(child.size){
                const sizeSpan=document.createElement('span');
                sizeSpan.className='entry-size';
                sizeSpan.textContent=child.size;
                summary.appendChild(sizeSpan);
              }
              details.appendChild(summary);
              details.appendChild(createTreeElement(child, depth+1));
              li.appendChild(details);
            }else{
              const row=document.createElement('div');
              row.className='tree-entry';
              const label=document.createElement('span');
              label.className='entry-label';
              label.textContent='📄 '+child.name;
              row.appendChild(label);
              if(child.size){
                const sizeSpan=document.createElement('span');
                sizeSpan.className='entry-size';
                sizeSpan.textContent=child.size;
                row.appendChild(sizeSpan);
              }
              li.appendChild(row);
            }
            ul.appendChild(li);
          });
          return ul;
        };
        const showArchiveTree=(entries,kind)=>{
          if(!entries || !entries.length){
            showError((kind||'压缩包')+'为空。');
            return;
          }
          const tree=buildArchiveTree(entries);
          const view=createTreeElement(tree);
          showContent(view);
        };
        const isPasswordError=err=>{
          const msg=(err && err.message ? String(err.message) : '').toLowerCase();
          return msg.includes('password') || msg.includes('decrypt') || msg.includes('encrypted');
        };
        const loadZipPreview=async source=>{
          const {loadingEl}=ensureElements();
          const attempt=async password=>{
            loadingEl.textContent=password?'验证密码…':'解析压缩包…';
            const buffer=await loadBuffer(source);
            await ensureScript('https://cdn.jsdelivr.net/npm/@zip.js/zip.js@2.7.32/dist/zip.min.js');
            const zipLib=window.zip;
            if(!zipLib || !zipLib.ZipReader || !zipLib.Uint8ArrayReader) throw new Error('解析库未加载');
            const reader=new zipLib.ZipReader(new zipLib.Uint8ArrayReader(new Uint8Array(buffer)), password?{password}:{});
            try{
              const entries=await reader.getEntries();
              const mapped=(entries||[]).map(entry=>({
                path:entry.filename,
                dir:!!entry.directory,
                size:entry.uncompressedSize?formatBytes(entry.uncompressedSize):''
              }));
              if(!mapped.length){
                showError('压缩包为空。');
                return;
              }
              showArchiveTree(mapped,'ZIP 压缩包');
            }finally{
              if(reader && typeof reader.close==='function'){
                try{ await reader.close(); }catch(_){ /* ignore */ }
              }
            }
          };
          try{
            await attempt('');
          }catch(err){
            if(isPasswordError(err)){
              let retry=false;
              while(true){
                const input=window.prompt(retry?'密码不正确，请重新输入 ZIP 密码：':'该 ZIP 文件已加密，请输入密码以预览：','');
                if(input===null){ showError('已取消预览。'); return; }
                try{
                  await attempt(input);
                  return;
                }catch(inner){
                  if(!isPasswordError(inner)) throw inner;
                  retry=true;
                }
              }
            }
            throw err;
          }
        };
        const loadRarPreview=async source=>{
          const {loadingEl}=ensureElements();
          const attempt=async password=>{
            loadingEl.textContent=password?'验证密码…':'解析压缩包…';
            const buffer=await loadBuffer(source);
            await ensureScript('https://cdn.jsdelivr.net/npm/unrar-js@0.2.19/dist/unrar.js');
            const api=window.UNRAR || window.unrar;
            if(!api || typeof api.createExtractorFromData!=='function') throw new Error('解析库未加载');
            const extractor=await api.createExtractorFromData({data:buffer,password:password||undefined});
            try{
              const list=extractor && typeof extractor.getFileList==='function' ? extractor.getFileList() : null;
              const headers=list && Array.isArray(list.fileHeaders) ? list.fileHeaders : [];
              const entries=headers.map(header=>{
                const name=header && typeof header.name==='string' && header.name ? header.name : (header && typeof header.fileName==='string' ? header.fileName : '未知文件');
                const dirFlag=header && header.flags ? (header.flags.directory || header.flags.DIRECTORY || header.flags.folder) : false;
                const isDir=!!dirFlag || /[\\/]$/.test(name);
                const sizeValue=header && typeof header.uncompressedSize==='number'?header.uncompressedSize:(header && typeof header.size==='number'?header.size:null);
                return {path:name,dir:isDir,size:sizeValue?formatBytes(sizeValue):''};
              });
              if(!entries.length){
                showError('压缩包为空。');
                return;
              }
              showArchiveTree(entries,'RAR 压缩包');
            }finally{
              if(extractor){
                if(typeof extractor.free==='function') extractor.free();
                else if(typeof extractor.close==='function') extractor.close();
                else if(typeof extractor.delete==='function') extractor.delete();
              }
            }
          };
          try{
            await attempt('');
          }catch(err){
            if(isPasswordError(err)){
              let retry=false;
              while(true){
                const input=window.prompt(retry?'密码不正确，请重新输入 RAR 密码：':'该 RAR 文件已加密，请输入密码以预览：','');
                if(input===null){ showError('已取消预览。'); return; }
                try{
                  await attempt(input);
                  return;
                }catch(inner){
                  if(!isPasswordError(inner)) throw inner;
                  retry=true;
                }
              }
            }
            throw err;
          }
        };
        const loadDocxPreview=async source=>{
          const {loadingEl}=ensureElements();
          loadingEl.textContent='解析文档…';
          const buffer=await loadBuffer(source);
          await ensureScript('https://cdn.jsdelivr.net/npm/mammoth@1.6.0/mammoth.browser.min.js');
          if(!window.mammoth || typeof window.mammoth.convertToHtml!=='function') throw new Error('转换库未加载');
          const result=await window.mammoth.convertToHtml({arrayBuffer:buffer}).catch(err=>{ throw err; });
          const html=result && typeof result.value==='string' ? result.value : '';
          const wrapper=document.createElement('div');
          wrapper.className='attachment-docx';
          const content=html && html.trim() ? html : '<p>（文档为空）</p>';
          wrapper.innerHTML=window.DOMPurify ? window.DOMPurify.sanitize(content) : content;
          showContent(wrapper);
        };
        const loadExcelPreview=async source=>{
          const {loadingEl}=ensureElements();
          loadingEl.textContent='解析表格…';
          const buffer=await loadBuffer(source);
          await ensureScript('https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js');
          if(!window.XLSX || typeof window.XLSX.read!=='function') throw new Error('解析库未加载');
          const workbook=window.XLSX.read(buffer,{type:'array'});
          const sheetNames=Array.isArray(workbook.SheetNames)?workbook.SheetNames:[];
          if(!sheetNames.length){ showError('工作簿为空。'); return; }
          const container=document.createElement('div');
          container.className='attachment-excel';
          sheetNames.forEach(name=>{
            const sheet=workbook.Sheets[name];
            if(!sheet) return;
            const section=document.createElement('section');
            section.className='excel-sheet';
            const heading=document.createElement('h3');
            heading.textContent='工作表：'+name;
            section.appendChild(heading);
            const tableHtml=window.XLSX.utils && typeof window.XLSX.utils.sheet_to_html==='function'
              ? window.XLSX.utils.sheet_to_html(sheet,{header:'',footer:''})
              : '';
            const sanitized=window.DOMPurify ? window.DOMPurify.sanitize(tableHtml||'<table><tbody><tr><td>（无法预览该表格）</td></tr></tbody></table>') : (tableHtml||'<table><tbody><tr><td>（无法预览该表格）</td></tr></tbody></table>');
            const tableWrap=document.createElement('div');
            tableWrap.className='excel-table';
            tableWrap.innerHTML=sanitized;
            section.appendChild(tableWrap);
            container.appendChild(section);
          });
          showContent(container);
        };
        const openPreview=input=>{
          const source=normalizeSource(input);
          const {backdrop,panel,titleEl,metaEl,loadingEl,downloadBtn}=ensureElements();
          if(abortController){ abortController.abort(); abortController=null; }
          cleanupObjectUrl();
          lastActive=document.activeElement instanceof HTMLElement ? document.activeElement : null;
          backdrop.dataset.open='true';
          try{ panel.focus({preventScroll:true}); }catch(_){ /* ignore */ }
          titleEl.textContent=source.name || '附件预览';
          const metaParts=[];
          if(source.mime) metaParts.push(source.mime);
          if(Number.isFinite(source.size) && source.size>0) metaParts.push(formatBytes(source.size));
          metaEl.textContent=metaParts.join(' · ');
          const downloadHref=setDownloadLink(downloadBtn, source);
          loadingEl.textContent='载入中…';
          const type=detectType(source);
          if(type==='image' || type==='video' || type==='pdf' || type==='audio'){
            const url=(type==='image' && source.kind==='url') ? source.url : (source.kind==='blob' ? downloadHref : source.url || downloadHref);
            if(!url){ showError('附件缺少可用地址'); return; }
            renderMedia(type,url,source.mime);
            return;
          }
          const labelMap={zip:'ZIP 压缩包',rar:'RAR 压缩包',word:'文档',excel:'表格'};
          const handleError=err=>{
            console.error(err);
            if(labelMap[type]){
              showError('无法解析'+labelMap[type]+'：'+(err && err.message?err.message:''));
            }else{
              showError('暂不支持该文件类型的在线预览，请使用下载按钮。');
            }
          };
          if(type==='zip'){ loadZipPreview(source).then(()=>{}).catch(handleError); return; }
          if(type==='rar'){ loadRarPreview(source).then(()=>{}).catch(handleError); return; }
          if(type==='word'){ loadDocxPreview(source).then(()=>{}).catch(handleError); return; }
          if(type==='excel'){ loadExcelPreview(source).then(()=>{}).catch(handleError); return; }
          showError('暂不支持该文件类型的在线预览，请使用下载按钮。');
        };
        window.AttachmentPreview={
          open:openPreview,
          openFromUrl(url,meta){ openPreview(Object.assign({}, meta||{}, {url:url||''})); },
          openFromBlob(blob,meta){ openPreview(Object.assign({}, meta||{}, {blob})); },
          close(){ const els=ensureElements(); els.closePreview(); }
        };
        const callbacks=readyQueue.splice(0);
        callbacks.forEach(fn=>{ try{ fn(); }catch(err){ console.error(err); } });
      })();

      (function(){
      const DOUBLE_TAP_WINDOW=320;
      const LONG_PRESS_DELAY=550;
      const LONG_PRESS_TOLERANCE=14;
      const DEFAULT_NODE_ICON='🧠';
      const isCompactViewport=()=>window.matchMedia('(max-width: 900px)').matches;
      let lastTapInfo={id:null,time:0};
      const TRACE_GRID=8;
      const TRACE_CHAMFER=3;
      const nearlyEqual=(a,b)=>Math.abs(a-b)<0.5;
      function alignToTraceGrid(value){
        return Math.round(value/TRACE_GRID)*TRACE_GRID;
      }
      function buildTraceRoute(start,end,directionHint=1){
        if(!start || !end){ return []; }
        const points=[{x:start.x,y:start.y}];
        const rawDx=end.x-start.x;
        const absDx=Math.abs(rawDx);
        const direction=(absDx<TRACE_GRID*0.5)
          ? (directionHint>=0?1:-1)
          : (rawDx>=0?1:-1);
        const MIN_LEAD=TRACE_GRID*3;
        let midX;
        if(absDx<TRACE_GRID*0.25){
          midX=start.x + direction*MIN_LEAD;
        }else if(absDx<=TRACE_GRID*3){
          const halfSpan=absDx/2;
          midX=start.x + direction*Math.max(MIN_LEAD, halfSpan);
        }else{
          const maxLead=Math.max(MIN_LEAD, absDx - TRACE_GRID*2);
          let lead=absDx*0.45;
          lead=Math.min(Math.max(MIN_LEAD, lead), maxLead);
          midX=start.x + direction*lead;
        }
        midX=alignToTraceGrid(midX);
        const viaY=alignToTraceGrid(end.y);
        points.push({x:midX,y:start.y});
        if(!nearlyEqual(viaY,start.y)){
          points.push({x:midX,y:viaY});
        }
        points.push({x:end.x,y:end.y});
        const cleaned=[];
        for(const pt of points){
          if(!cleaned.length){ cleaned.push(pt); continue; }
          const prev=cleaned[cleaned.length-1];
          if(nearlyEqual(prev.x, pt.x) && nearlyEqual(prev.y, pt.y)){
            continue;
          }
          cleaned.push(pt);
        }
        return cleaned;
      }
      function buildChamferedPath(points,chamfer){
        if(!Array.isArray(points) || points.length<2) return '';
        let d=`M${points[0].x} ${points[0].y}`;
        for(let i=1;i<points.length;i++){
          const current=points[i];
          const prev=points[i-1];
          const next=i+1<points.length ? points[i+1] : null;
          if(next){
            const prevVec={x:current.x-prev.x,y:current.y-prev.y};
            const nextVec={x:next.x-current.x,y:next.y-current.y};
            const prevLen=Math.hypot(prevVec.x,prevVec.y);
            const nextLen=Math.hypot(nextVec.x,nextVec.y);
            if(prevLen<0.01 || nextLen<0.01){
              d+=` L${current.x} ${current.y}`;
              continue;
            }
            const cut=Math.min(chamfer, prevLen/2, nextLen/2);
            const entry={
              x:current.x - Math.sign(prevVec.x)*cut,
              y:current.y - Math.sign(prevVec.y)*cut
            };
            const exit={
              x:current.x + Math.sign(nextVec.x)*cut,
              y:current.y + Math.sign(nextVec.y)*cut
            };
            d+=` L${entry.x} ${entry.y}`;
            d+=` L${exit.x} ${exit.y}`;
          }else{
            d+=` L${current.x} ${current.y}`;
          }
        }
        return d;
      }
      function normalizeNodeData(value){
        if(!value || typeof value!=='object' || Array.isArray(value)) return {};
        const data=value;
        if(data.attachment && !data.attachments){
          data.attachments=Array.isArray(data.attachment)?data.attachment.slice():[data.attachment];
        }
        if(!Array.isArray(data.attachments)){ data.attachments=[]; }
        else{
          data.attachments=data.attachments.filter(item=>item && typeof item==='object');
        }
        if(data.attachments.length){ data.attachment=data.attachments[0]; }
        else if('attachment' in data){ delete data.attachment; }
        if(typeof data.note!=='string'){ data.note=''; }
        else{ data.note=data.note.replace(/\r\n?/g,'\n'); }
        return data;
      }
      function enforceRightOrientation(node, depth=0){
        if(!node || typeof node!=='object') return;
        node.direction=depth===0?'center':'right';
        if(Array.isArray(node.children)){
          node.children=node.children.filter(child=>child && typeof child==='object');
          node.children.forEach(child=>enforceRightOrientation(child, depth+1));
        }else{
          node.children=[];
        }
      }
      function gatherAttachments(data){
        if(!data) return [];
        if(Array.isArray(data.attachments)){ return data.attachments.map(att=>att && typeof att==='object'?att:null).filter(Boolean); }
        if(data.attachment && typeof data.attachment==='object') return [data.attachment];
        return [];
      }
      class SimpleMind {
        constructor(options){
          this.options=options||{};
          const containerOpt=this.options.container;
          this.container=typeof containerOpt==='string'?document.getElementById(containerOpt):containerOpt;
          if(!this.container) throw new Error('Mind container not found');
          this.listeners=[];
          this.scale=1;
          this.defaultMinScale=0.3;
          this.defaultMaxScale=2.5;
          this.minReadableScale=0.05;
          this.minScale=this.defaultMinScale;
          this.maxScale=this.defaultMaxScale;
          this.scalePadding=200;
          this.offsetX=0;
          this.offsetY=0;
          this.mind=null;
          this.nodes=new Map();
          this.root=null;
          this.selectedId=null;
          this.container.innerHTML='';
          this.container.classList.add('mind-container');
          this.container.style.touchAction='none';
          this.container.addEventListener('gesturestart',evt=>evt.preventDefault());
          this.container.addEventListener('gesturechange',evt=>evt.preventDefault());
          this.container.addEventListener('gestureend',evt=>evt.preventDefault());
          this.container.addEventListener('pointerleave',()=>{ this.clearEdgeHover(); });
          this.background=document.createElement('div');
          this.background.className='mind-background';
          this.container.appendChild(this.background);
          this.viewport=document.createElement('div');
          this.viewport.className='mind-viewport';
          this.linkLayer=document.createElementNS('http://www.w3.org/2000/svg','svg');
          this.linkLayer.classList.add('mind-links');
          this.viewport.appendChild(this.linkLayer);
          this.relationLayer=document.createElementNS('http://www.w3.org/2000/svg','svg');
          this.relationLayer.classList.add('mind-relations');
          this.viewport.appendChild(this.relationLayer);
          this.guideLayer=document.createElement('div');
          this.guideLayer.className='mind-guides';
          this.guideLayer.style.position='absolute';
          this.guideLayer.style.left='0';
          this.guideLayer.style.top='0';
          this.guideLayer.style.width='100%';
          this.guideLayer.style.height='100%';
          this.guideLayer.style.pointerEvents='none';
          this.guideLayer.style.zIndex='1';
          this.guideLayer.style.display='none';
          this.viewport.appendChild(this.guideLayer);
          this.nodeLayer=document.createElement('div');
          this.nodeLayer.className='mind-nodes';
          this.viewport.appendChild(this.nodeLayer);
          this.container.appendChild(this.viewport);
          this.linkControlLayer=document.createElement('div');
          this.linkControlLayer.className='mind-link-controls';
          this.linkControlLayer.style.width='100%';
          this.linkControlLayer.style.height='100%';
          this.container.appendChild(this.linkControlLayer);
          this.sizeCache=new Map();
          this.measureHost=document.querySelector('.mind-measure') || document.createElement('div');
          if(!this.measureHost.classList.contains('mind-measure')){
            this.measureHost.className='mind-measure';
            document.body.appendChild(this.measureHost);
          }else if(!this.measureHost.parentElement){
            document.body.appendChild(this.measureHost);
          }
          this.dragState=null;
          this.activePointers=new Map();
          this.pinchState=null;
          this.relations=[];
          this.linkRegistry=new Map();
          this.relationRegistry=new Map();
          this.edgeHoverState=null;
          this.resizeObserver=typeof ResizeObserver!=='undefined'?new ResizeObserver(entries=>this.handleNodeResize(entries)):null;
          this.setupPan();
          this.setupTouchGuards();
        }
        setupPan(){
          const updatePinchBaseline=()=>{
            if(this.activePointers.size<2){ this.pinchState=null; return; }
            const pointers=Array.from(this.activePointers.values());
            if(pointers.length<2){ this.pinchState=null; return; }
            const [a,b]=pointers;
            const distance=Math.hypot(b.x-a.x, b.y-a.y) || 1;
            const rect=this.container.getBoundingClientRect();
            const centerX=((a.x+b.x)/2)-rect.left;
            const centerY=((a.y+b.y)/2)-rect.top;
            const originX=(centerX - this.offsetX)/this.scale;
            const originY=(centerY - this.offsetY)/this.scale;
            this.pinchState={
              initialDistance:distance,
              baseScale:this.scale,
              originX,
              originY,
            };
          };
          const startPan=(evt)=>{
            this.clearEdgeHover();
            const isTouch=evt.pointerType==='touch';
            const onNode=!!evt.target.closest('.jsmind-node');
            if(!isTouch){
              if(evt.button!==0) return;
              if(onNode) return;
              this.activePointers.set(evt.pointerId,{x:evt.clientX,y:evt.clientY});
              try{ this.container.setPointerCapture(evt.pointerId); }catch(_){ }
              this.dragState={pointerId:evt.pointerId,startX:evt.clientX,startY:evt.clientY,baseX:this.offsetX,baseY:this.offsetY};
              return;
            }
            this.activePointers.set(evt.pointerId,{x:evt.clientX,y:evt.clientY});
            if(isTouch && (this.activePointers.size>=2 || !onNode)){
              evt.preventDefault();
            }
            if(this.activePointers.size>=2){
              for(const id of this.activePointers.keys()){
                try{ this.container.setPointerCapture(id); }catch(_){ }
              }
              this.dragState=null;
              updatePinchBaseline();
              return;
            }
            if(!onNode){
              try{ this.container.setPointerCapture(evt.pointerId); }catch(_){ }
              this.dragState={pointerId:evt.pointerId,startX:evt.clientX,startY:evt.clientY,baseX:this.offsetX,baseY:this.offsetY};
            }else{
              this.dragState=null;
            }
          };
          const movePan=(evt)=>{
            if(!this.activePointers.has(evt.pointerId)) return;
            const isTouch=evt.pointerType==='touch';
            if(isTouch){
              this.activePointers.set(evt.pointerId,{x:evt.clientX,y:evt.clientY});
              if(this.activePointers.size>=2){
                evt.preventDefault();
                if(!this.pinchState){ updatePinchBaseline(); }
                const pinch=this.pinchState;
                if(!pinch) return;
                const pointers=Array.from(this.activePointers.values());
                if(pointers.length<2) return;
                const [a,b]=pointers;
                const distance=Math.hypot(b.x-a.x, b.y-a.y) || 1;
                const rect=this.container.getBoundingClientRect();
                const centerX=((a.x+b.x)/2)-rect.left;
                const centerY=((a.y+b.y)/2)-rect.top;
                const nextScale=this.clampScale(pinch.baseScale * (distance / pinch.initialDistance), rect);
                this.scale=nextScale;
                this.offsetX=centerX - pinch.originX*this.scale;
                this.offsetY=centerY - pinch.originY*this.scale;
                this.applyTransform();
                return;
              }
              if(this.dragState && this.dragState.pointerId===evt.pointerId){
                evt.preventDefault();
              }
            }
            if(!this.dragState || evt.pointerId!==this.dragState.pointerId) return;
            const dx=evt.clientX-this.dragState.startX;
            const dy=evt.clientY-this.dragState.startY;
            this.offsetX=this.dragState.baseX+dx;
            this.offsetY=this.dragState.baseY+dy;
            this.applyTransform();
          };
          const endPan=(evt)=>{
            if(this.activePointers.has(evt.pointerId)){
              this.activePointers.delete(evt.pointerId);
            }
            if(this.dragState && evt.pointerId===this.dragState.pointerId){
              this.dragState=null;
            }
            if(this.activePointers.size<2){
              this.pinchState=null;
            }else{
              updatePinchBaseline();
            }
            try{ this.container.releasePointerCapture(evt.pointerId); }catch(_){ }
          };
          this.container.addEventListener('pointerdown',startPan);
          this.container.addEventListener('pointermove',movePan);
          this.container.addEventListener('pointerup',endPan);
          this.container.addEventListener('pointercancel',endPan);
          this.container.addEventListener('wheel',evt=>this.handleWheel(evt),{passive:false});
        }
        calculateScaleBounds(rect){
          const defaultMin=(typeof this.defaultMinScale==='number' && this.defaultMinScale>0)?this.defaultMinScale:0.3;
          const defaultMax=(typeof this.defaultMaxScale==='number' && this.defaultMaxScale>0)?this.defaultMaxScale:2.5;
          const readableMin=(typeof this.minReadableScale==='number' && this.minReadableScale>0)?this.minReadableScale:0.05;
          let min=defaultMin;
          const containerRect=rect || this.container.getBoundingClientRect();
          const hasRect=containerRect && containerRect.width>0 && containerRect.height>0;
          if(this.bounds && hasRect){
            const padding=(typeof this.scalePadding==='number' && this.scalePadding>=0)?this.scalePadding:0;
            const widthDenom=(this.bounds.width||0)+padding;
            const heightDenom=(this.bounds.height||0)+padding;
            const widthScale=widthDenom>0?containerRect.width/widthDenom:Infinity;
            const heightScale=heightDenom>0?containerRect.height/heightDenom:Infinity;
            const fitScale=Math.min(widthScale,heightScale);
            if(Number.isFinite(fitScale) && fitScale>0){
              if(fitScale<defaultMin){
                min=Math.max(readableMin, fitScale);
              }
            }
          }
          if(min>defaultMax){ min=defaultMax; }
          return {min, max:defaultMax};
        }
        updateScaleBounds(rect){
          const bounds=this.calculateScaleBounds(rect);
          this.minScale=bounds.min;
          this.maxScale=bounds.max;
          return bounds;
        }
        clampScale(value, rect){
          if(typeof value!=='number' || !isFinite(value)) return this.scale;
          const bounds=this.updateScaleBounds(rect);
          if(value<bounds.min) return bounds.min;
          if(value>bounds.max) return bounds.max;
          return value;
        }
        enforceScaleBounds(rect, options={}){
          const prevScale=this.scale;
          const bounds=this.updateScaleBounds(rect);
          const clamped=Math.min(bounds.max, Math.max(bounds.min, prevScale));
          if(Math.abs(clamped-prevScale)>0.0001){
            const adjustOffset=options.adjustOffset!==false;
            if(adjustOffset){
              const containerRect=rect || this.container.getBoundingClientRect();
              if(containerRect && containerRect.width>0 && containerRect.height>0 && prevScale>0){
                const anchorX=(options.anchor && typeof options.anchor.x==='number')?options.anchor.x:containerRect.width/2;
                const anchorY=(options.anchor && typeof options.anchor.y==='number')?options.anchor.y:containerRect.height/2;
                const originX=(anchorX - this.offsetX)/prevScale;
                const originY=(anchorY - this.offsetY)/prevScale;
                this.offsetX=anchorX - originX*clamped;
                this.offsetY=anchorY - originY*clamped;
              }
            }
            this.scale=clamped;
            return true;
          }
          return false;
        }
        handleWheel(evt){
          if(!evt) return;
          if(evt.ctrlKey || evt.metaKey){
            evt.preventDefault();
            const delta=Math.max(-1, Math.min(1, evt.deltaY));
            const factor=delta<0?1.12:0.9;
            const prevScale=this.scale;
            const rect=this.container.getBoundingClientRect();
            const nextScale=this.clampScale(prevScale*factor, rect);
            if(Math.abs(nextScale-prevScale)<0.0001) return;
            const originX=(evt.clientX-rect.left - this.offsetX)/this.scale;
            const originY=(evt.clientY-rect.top - this.offsetY)/this.scale;
            this.scale=nextScale;
            this.offsetX=evt.clientX-rect.left - originX*this.scale;
            this.offsetY=evt.clientY-rect.top - originY*this.scale;
            this.applyTransform();
            return;
          }
          evt.preventDefault();
          const multiplier=evt.deltaMode===1?16:(evt.deltaMode===2?240:1);
          this.offsetX-=evt.deltaX*multiplier;
          this.offsetY-=evt.deltaY*multiplier;
          this.applyTransform();
        }
        add_event_listener(fn){ if(typeof fn==='function'){ this.listeners.push(fn); } }
        emit(type){ this.listeners.forEach(fn=>{ try{ fn(type); }catch(err){ console.warn(err); } }); }
        show(mindData){
          this.mind=(typeof structuredClone==='function')?structuredClone(mindData):JSON.parse(JSON.stringify(mindData));
          this.nodes.clear();
          this.root=null;
          this.selectedId=null;
          if(this.relationRegistry){ this.relationRegistry.clear(); }
          const relationsSource=Array.isArray(this.mind && this.mind.relations ? this.mind.relations : null) ? this.mind.relations : [];
          this.relations=[];
          const relationIds=new Set();
          relationsSource.forEach(rel=>{
            if(!rel || typeof rel!=='object') return;
            const from=typeof rel.from==='string'?rel.from.trim():'';
            const to=typeof rel.to==='string'?rel.to.trim():'';
            if(!from || !to || from===to) return;
            let id=typeof rel.id==='string' && rel.id.trim()!=='' ? rel.id.trim() : null;
            if(!id){
              do { id='rel-'+Math.random().toString(36).slice(2,10); } while(relationIds.has(id));
            }
            if(relationIds.has(id)){
              do { id='rel-'+Math.random().toString(36).slice(2,10); } while(relationIds.has(id));
            }
            relationIds.add(id);
            const entry={id,from,to};
            if(typeof rel.label==='string' && rel.label.trim()!==''){ entry.label=rel.label.trim(); }
            if(rel.bidirectional){ entry.bidirectional=true; }
            this.relations.push(entry);
          });
          const data=this.mind && this.mind.data ? this.mind.data : null;
          if(!data) return;
          const build=(item,parent)=>{
            const normalizedData=normalizeNodeData(item && item.data ? item.data : {});
            item.data=normalizedData;
            const depth=parent ? (parent.depth||0)+1 : 0;
            const node={
              id:item.id || ('node-'+Math.random().toString(36).slice(2,9)),
              topic:item.topic || '',
              data:normalizedData,
              parent:parent,
              children:[],
              direction:parent ? 'right' : 'center',
              expanded:item.expanded!==false,
              isroot:!parent,
              style:item.style || null,
              meta:item.meta || null,
              model:item,
              depth:depth,
            };
            node.model.data=normalizedData;
            node.model.direction=node.direction;
            this.nodes.set(node.id,node);
            if(parent){ parent.children.push(node); node.model.parentId=parent.id; }
            else this.root=node;
            if(Array.isArray(item.children)){
              item.children=item.children.filter(child=>child && typeof child==='object');
              node.children=item.children.map(child=>build(child,node));
            }else{
              item.children=[];
            }
            return node;
          };
          build(data,null);
          if(this.relations.length){
            const filtered=[];
            const seenIds=new Set();
            for(const relation of this.relations){
              if(!relation) continue;
              if(!this.nodes.has(relation.from) || !this.nodes.has(relation.to) || relation.from===relation.to) continue;
              if(seenIds.has(relation.id)){
                let newId;
                do { newId='rel-'+Math.random().toString(36).slice(2,10); } while(seenIds.has(newId));
                relation.id=newId;
              }
              seenIds.add(relation.id);
              filtered.push(relation);
            }
            this.relations.length=0;
            this.relations.push(...filtered);
          }
          this.mind.relations=this.relations;
          this.hasCentered=false;
          this.computeLayout();
          this.render();
          this.emit(SimpleMind.event_type.show);
          if(this.root){ this.select_node(this.root.id); }
        }
        get_root(){ return this.root; }
        get_node(id){ return this.nodes.get(id) || null; }
        get_selected_node(){
          const node=this.nodes.get(this.selectedId);
          if(node){ node.selected=true; }
          return node || null;
        }
        select_node(id){
          if(!id || !this.nodes.has(id)) return;
          if(this.selectedId && this.selectedId!==id){
            const prev=this.nodes.get(this.selectedId);
            if(prev && prev.el){ prev.el.classList.remove('selected'); prev.selected=false; }
          }
          this.selectedId=id;
          const node=this.nodes.get(id);
          if(node && node.el){ node.el.classList.add('selected'); }
          if(node){ node.selected=true; }
          this.emit(SimpleMind.event_type.select);
        }
        ensureModelChildren(node){
          if(!node.model.children) node.model.children=[];
          return node.model.children;
        }
        add_node(parentNode,newId,topic,data){
          const parent=typeof parentNode==='string'?this.get_node(parentNode):parentNode;
          if(!parent) return null;
          if(!newId) newId='node-'+Math.random().toString(36).slice(2,10);
          const normalized=normalizeNodeData(data||{});
          const model={id:newId,topic:topic||'新节点',data:normalized,children:[],direction:'right',expanded:true};
          const children=this.ensureModelChildren(parent);
          children.push(model);
          const node={
            id:newId,
            topic:model.topic,
            data:model.data,
            parent:parent,
            children:[],
            direction:'right',
            expanded:true,
            isroot:false,
            style:model.style || null,
            meta:model.meta || null,
            model:model,
            depth:(parent.depth||0)+1,
          };
          node.model.direction='right';
          parent.children.push(node);
          this.nodes.set(newId,node);
          this.computeLayout();
          this.render();
          this.select_node(newId);
          this.emit(SimpleMind.event_type.update);
          return node;
        }
        insert_node_between(parentId, childId, options){
          const parent=typeof parentId==='string'?this.get_node(parentId):parentId;
          const child=typeof childId==='string'?this.get_node(childId):childId;
          if(!parent || !child || child.parent!==parent) return null;
          const childIndex=parent.children?parent.children.indexOf(child):-1;
          if(childIndex===-1) return null;
          let newId=null;
          if(options && typeof options.id==='string' && options.id.trim()!==''){ newId=options.id.trim(); }
          if(!newId){
            do { newId='node-'+Math.random().toString(36).slice(2,10); }
            while(this.nodes.has(newId));
          }
          const topic=(options && typeof options.topic==='string' && options.topic.trim()!=='')?options.topic.trim():'新节点';
          const normalized=normalizeNodeData(options && options.data ? options.data : {});
          const style=options && options.style ? JSON.parse(JSON.stringify(options.style)) : null;
          const meta=options && options.meta ? JSON.parse(JSON.stringify(options.meta)) : null;
          const direction=child.direction || child.dir || parent.direction || 'right';
          const newModel={
            id:newId,
            topic:topic,
            data:normalized,
            children:[],
            direction:direction,
            expanded:true,
          };
          if(style){ newModel.style=JSON.parse(JSON.stringify(style)); }
          if(meta){ newModel.meta=JSON.parse(JSON.stringify(meta)); }
          const newNode={
            id:newId,
            topic:topic,
            data:normalized,
            parent:parent,
            children:[],
            direction:direction,
            expanded:true,
            isroot:false,
            style:style,
            meta:meta,
            model:newModel,
            depth:(parent.depth||0)+1,
          };
          const parentModelChildren=this.ensureModelChildren(parent);
          const modelIndex=parentModelChildren.findIndex(entry=>entry && entry.id===child.id);
          const insertIndex=modelIndex===-1?parentModelChildren.length:modelIndex;
          parentModelChildren.splice(insertIndex,0,newModel);
          if(!Array.isArray(parent.children)){ parent.children=[]; }
          parent.children.splice(childIndex,0,newNode);
          const removeIndex=parent.children.indexOf(child);
          if(removeIndex!==-1){ parent.children.splice(removeIndex,1); }
          const removeModelIndex=parentModelChildren.findIndex(entry=>entry && entry.id===child.id);
          if(removeModelIndex!==-1){ parentModelChildren.splice(removeModelIndex,1); }
          newModel.children=newModel.children||[];
          if(child.model){ newModel.children.push(child.model); }
          else{
            newModel.children.push({id:child.id,topic:child.topic,data:child.data,children:[]});
          }
          newNode.children.push(child);
          child.parent=newNode;
          const updateDepths=(node, depth)=>{
            if(!node) return;
            node.depth=depth;
            if(node.model){ node.model.depth=depth; }
            if(node.children && node.children.length){
              node.children.forEach(kid=>updateDepths(kid, depth+1));
            }
          };
          updateDepths(newNode, (parent.depth||0)+1);
          updateDepths(child, (newNode.depth||0)+1);
          this.nodes.set(newId,newNode);
          this.computeLayout();
          this.render();
          this.select_node(newId);
          this.emit(SimpleMind.event_type.update);
          return this.nodes.get(newId) || null;
        }
        remove_node(id){
          const node=this.nodes.get(id);
          if(!node || node.isroot) return;
          const parent=node.parent;
          parent.children=parent.children.filter(child=>child!==node);
          if(parent.model && parent.model.children){
            parent.model.children=parent.model.children.filter(child=>child.id!==id);
          }
          const stack=[node];
          const removedIds=new Set();
          while(stack.length){
            const cur=stack.pop();
            removedIds.add(cur.id);
            this.nodes.delete(cur.id);
            if(cur.children && cur.children.length){ stack.push(...cur.children); }
          }
          if(removedIds.size){
            this.pruneRelations(rel=>removedIds.has(rel.from) || removedIds.has(rel.to),{silent:true});
          }
          this.computeLayout();
          this.render();
          this.select_node(parent.id);
          this.emit(SimpleMind.event_type.update);
        }
        setupTouchGuards(){
          let lastTapTime=0;
          let lastTapX=0;
          let lastTapY=0;
          let tapTimer=null;
          const DOUBLE_TAP_DELAY=320;
          const DOUBLE_TAP_DISTANCE=26;
          const reset=()=>{
            if(tapTimer){ clearTimeout(tapTimer); tapTimer=null; }
            lastTapTime=0;
          };
          const isEditableTarget=(target)=>{
            if(!target || !target.tagName) return false;
            const tag=target.tagName.toLowerCase();
            return tag==='input' || tag==='textarea' || tag==='select' || target.isContentEditable===true;
          };
          this.container.addEventListener('touchstart',(evt)=>{
            if(evt.touches.length>1){ reset(); return; }
            const target=evt.target;
            if(isEditableTarget(target)){ reset(); return; }
            const touch=evt.touches[0];
            const now=performance.now();
            if(lastTapTime){
              const delta=now-lastTapTime;
              const dx=touch.clientX-lastTapX;
              const dy=touch.clientY-lastTapY;
              if(delta>0 && delta<=DOUBLE_TAP_DELAY && (dx*dx + dy*dy) <= (DOUBLE_TAP_DISTANCE*DOUBLE_TAP_DISTANCE)){
                evt.preventDefault();
                const dblTarget=target || this.container;
                const dblEvt=new MouseEvent('dblclick',{
                  bubbles:true,
                  cancelable:true,
                  clientX:touch.clientX,
                  clientY:touch.clientY,
                });
                dblTarget.dispatchEvent(dblEvt);
                reset();
                return;
              }
            }
            lastTapTime=now;
            lastTapX=touch.clientX;
            lastTapY=touch.clientY;
            if(tapTimer){ clearTimeout(tapTimer); }
            tapTimer=setTimeout(reset, DOUBLE_TAP_DELAY+60);
          },{passive:false});
          this.container.addEventListener('touchend',(evt)=>{
            if(evt.touches && evt.touches.length>0) return;
            if(isEditableTarget(evt.target)){ reset(); return; }
            if(tapTimer){ clearTimeout(tapTimer); }
            tapTimer=setTimeout(reset, DOUBLE_TAP_DELAY);
          });
          this.container.addEventListener('touchcancel', reset);
        }
        toggle_node(id){
          const node=this.nodes.get(id);
          if(!node) return;
          node.expanded=!node.expanded;
          node.model.expanded=node.expanded;
          this.computeLayout();
          this.render();
          this.emit(SimpleMind.event_type.refresh);
        }
        set_node_expanded(id, expanded){
          const node=this.nodes.get(id);
          if(!node) return false;
          const desired=expanded!==false;
          if(node.expanded===desired) return false;
          node.expanded=desired;
          if(node.model){ node.model.expanded=desired; }
          this.computeLayout();
          this.render();
          this.emit(SimpleMind.event_type.refresh);
          return true;
        }
        set_all_expanded(expanded){
          const desired=expanded!==false;
          let changed=false;
          if(this.root){
            if(this.root.expanded!==true){
              this.root.expanded=true;
              if(this.root.model){ this.root.model.expanded=true; }
              changed=true;
            }
          }
          for(const node of this.nodes.values()){
            if(!node || node.isroot) continue;
            if(!node.children || !node.children.length) continue;
            if(node.expanded===desired) continue;
            node.expanded=desired;
            if(node.model){ node.model.expanded=desired; }
            changed=true;
          }
          if(changed){
            this.computeLayout();
            this.render();
            this.emit(SimpleMind.event_type.refresh);
          }
          return changed;
        }
        has_collapsed_nodes(){
          for(const node of this.nodes.values()){
            if(node && node.children && node.children.length && node.expanded===false){
              return true;
            }
          }
          return false;
        }
        set_node_color(id,bg,fg){
          const node=this.nodes.get(id);
          if(node && node.el){
            node.style=node.style||{};
            if(bg){ node.style.background=bg; node.el.style.background=bg; node.el.dataset.bg=bg; }
            if(fg){ node.style.foreground=fg; node.el.style.color=fg; node.el.dataset.fg=fg; }
          }
        }
        update_node(id,topic){
          const node=this.nodes.get(id);
          if(!node) return;
          node.topic=topic;
          node.model.topic=topic;
          if(node.el){ node.el.querySelector('.node-topic').textContent=topic; }
          this.computeLayout();
          this.render();
          this.emit(SimpleMind.event_type.edit);
          this.emit(SimpleMind.event_type.after_edit);
        }
        get_data(format){
          if(format && format!=='node_tree'){ return null; }
          return this.mind ? ((typeof structuredClone==='function')?structuredClone(this.mind):JSON.parse(JSON.stringify(this.mind))) : null;
        }
        collectNodeSizes(){
          if(!this.root || !this.measureHost) return;
          const VISUAL_MARGIN_Y=24;
          this.sizeCache.clear();
          this.measureHost.innerHTML='';
          const measureNode=(node)=>{
            if(!node) return;
            const el=this.buildNodeElement(node,{forMeasure:true});
            this.measureHost.appendChild(el);
            const rect=el.getBoundingClientRect();
            this.sizeCache.set(node.id,{
              width:rect.width,
              height:rect.height+VISUAL_MARGIN_Y,
            });
            el.remove();
            if(node.expanded!==false && node.children && node.children.length){ node.children.forEach(child=>measureNode(child)); }
          };
          measureNode(this.root);
        }
        computeLayout(){
          if(!this.root) return;
          this.collectNodeSizes();
          const MIN_HEIGHT=88;
          const clamp=(value,min,max)=>Math.min(max, Math.max(min,value));
          const scale=(typeof this.scale==='number' && this.scale>0)?this.scale:1;
          const layoutOptions=this.options && this.options.layout ? this.options.layout : {};
          const verticalSpacingValue=typeof layoutOptions.verticalSpacing==='number'?layoutOptions.verticalSpacing:72;
          const horizontalSpacingValue=typeof layoutOptions.horizontalSpacing==='number'?layoutOptions.horizontalSpacing:160;
          const verticalSpacing=clamp(verticalSpacingValue,56,120);
          const horizontalSpacing=clamp(horizontalSpacingValue,120,220);
          const verticalGap=verticalSpacing/scale;
          const horizontalGap=horizontalSpacing/scale;
          const heightMap=new Map();
          const layers=new Map();
          const registerLayer=(depth,node)=>{
            if(!layers.has(depth)) layers.set(depth,[]);
            layers.get(depth).push(node);
          };
          const getNodeHeight=(node)=>{
            if(!node) return MIN_HEIGHT;
            const cached=this.sizeCache.get(node.id);
            if(cached && cached.height){ return Math.max(MIN_HEIGHT, cached.height); }
            return MIN_HEIGHT;
          };
          const getNodeWidth=(node)=>{
            if(!node) return 0;
            const cached=this.sizeCache.get(node.id);
            if(cached && cached.width){ return cached.width; }
            return 0;
          };
          const columnWidths=new Map();
          const registerColumnWidth=(depth,node)=>{
            if(!node) return;
            const width=Math.max(1,getNodeWidth(node));
            const prev=columnWidths.get(depth)||0;
            if(width>prev){ columnWidths.set(depth,width); }
          };
          const gatherColumnWidths=(node,depth=0)=>{
            if(!node) return;
            registerColumnWidth(depth,node);
            if(node.expanded!==false && node.children && node.children.length){
              node.children.filter(Boolean).forEach(child=>gatherColumnWidths(child, depth+1));
            }
          };
          gatherColumnWidths(this.root,0);
          if(!columnWidths.has(0)){
            columnWidths.set(0,Math.max(1,getNodeWidth(this.root)));
          }
          const columnPositions=new Map();
          const depthKeys=[...columnWidths.keys()].sort((a,b)=>a-b);
          columnPositions.set(0,0);
          for(const depth of depthKeys){
            if(depth===0) continue;
            let anchorDepth=depth-1;
            while(anchorDepth>=0 && !columnPositions.has(anchorDepth)){
              anchorDepth--;
            }
            const anchorPos=anchorDepth>=0?columnPositions.get(anchorDepth):0;
            const anchorWidth=anchorDepth>=0?(columnWidths.get(anchorDepth)||0):0;
            const currentWidth=columnWidths.get(depth)||0;
            const base=anchorPos + anchorWidth/2 + horizontalGap + currentWidth/2;
            columnPositions.set(depth, base);
          }
          const measure=(node,depth=0)=>{
            if(!node) return MIN_HEIGHT;
            if(heightMap.has(node.id)) return heightMap.get(node.id);
            const base=getNodeHeight(node);
            if(!node.expanded || !node.children.length){ heightMap.set(node.id, base); return base; }
            const visible=node.children.filter(Boolean);
            if(!visible.length){ heightMap.set(node.id, base); return base; }
            const childHeights=visible.map(child=>measure(child,depth+1));
            let total=0;
            for(let i=0;i<childHeights.length;i++){
              total+=childHeights[i];
              if(i<childHeights.length-1){ total+=verticalGap; }
            }
            const result=Math.max(base,total);
            heightMap.set(node.id,result);
            return result;
          };
          const subtreeHeight=(nodes,depth)=>{
            if(!nodes || !nodes.length) return 0;
            const visible=nodes.filter(Boolean);
            if(!visible.length) return 0;
            let total=0;
            for(let i=0;i<visible.length;i++){
              const node=visible[i];
              const h=measure(node,depth);
              total+=h;
              if(i<visible.length-1){ total+=verticalGap; }
            }
            return total;
          };
          const right=this.root.children.filter(Boolean);
          const rightHeight=subtreeHeight(right,1);
          const rootHeight=getNodeHeight(this.root);
          const canvasHeight=Math.max(rootHeight, rightHeight);
          this.root.x=0;
          this.root.y=canvasHeight/2;
          this.root.dir=0;
          this.root.direction='center';
          this.root.depth=0;
          this.root._layoutHeight=measure(this.root,0);
          registerLayer(0,this.root);
          if(this.root.model){ this.root.model.direction='center'; }
          const assign=(node,depth,startTop)=>{
            const height=measure(node,depth);
            node.depth=depth;
            node._layoutHeight=height;
            const fallbackAnchor=depth>0 && columnPositions.has(depth-1)?columnPositions.get(depth-1):this.root.x;
            const columnX=columnPositions.has(depth)?columnPositions.get(depth):fallbackAnchor+horizontalGap;
            node.x=columnX;
            node.y=startTop+height/2;
            node.dir=depth===0?0:1;
            node.direction=depth===0?'center':'right';
            if(node.model){ node.model.direction=node.direction; }
            registerLayer(depth,node);
            if(!node.expanded || !node.children.length) return height;
            let cursor=startTop;
            const children=node.children.filter(Boolean);
            for(let i=0;i<children.length;i++){
              const child=children[i];
              const childHeight=assign(child, depth+1, cursor);
              cursor+=childHeight;
              if(i<children.length-1){ cursor+=verticalGap; }
            }
            if(children.length===1){
              node.y=children[0].y;
            }else if(children.length>1){
              const first=children[0];
              const last=children[children.length-1];
              node.y=(first.y + last.y)/2;
            }
            return height;
          };
          let rightTop=this.root.y - rightHeight/2;
          for(let i=0;i<right.length;i++){
            const child=right[i];
            const h=assign(child,1,rightTop);
            rightTop+=h;
            if(i<right.length-1){ rightTop+=verticalGap; }
          }
          if(right.length){
            if(right.length===1){
              this.root.y=right[0].y;
            }else{
              this.root.y=(right[0].y + right[right.length-1].y)/2;
            }
          }
          const shiftSubtree=(node,delta)=>{
            if(!node || !delta) return;
            const stack=[node];
            while(stack.length){
              const current=stack.pop();
              current.y+=delta;
              if(current.children && current.children.length && current.expanded!==false){
                for(const child of current.children){ if(child) stack.push(child); }
              }
            }
          };
          const recenterAncestors=(node)=>{
            let cursor=node;
            while(cursor){
              if(cursor.expanded!==false && cursor.children && cursor.children.length){
                const visible=cursor.children.filter(Boolean);
                if(visible.length===1){
                  cursor.y=visible[0].y;
                }else if(visible.length>1){
                  const first=visible[0];
                  const last=visible[visible.length-1];
                  cursor.y=(first.y + last.y)/2;
                }
              }
              cursor=cursor.parent||null;
            }
          };
          const ensureLayerSpacing=()=>{
            const entries=[...layers.entries()].sort((a,b)=>a[0]-b[0]);
            for(const [depth,nodes] of entries){
              if(depth===0 || nodes.length<2) continue;
              const sorted=[...nodes].sort((a,b)=>a.y-b.y);
              for(let i=1;i<sorted.length;i++){
                const prev=sorted[i-1];
                const current=sorted[i];
                const prevDepth=(typeof prev.depth==='number')?prev.depth:depth;
                const currentDepth=(typeof current.depth==='number')?current.depth:depth;
                const prevHeight=(prev._layoutHeight!=null)?prev._layoutHeight:measure(prev, prevDepth);
                const currentHeight=(current._layoutHeight!=null)?current._layoutHeight:measure(current, currentDepth);
                const prevBottom=prev.y + prevHeight/2;
                const minTop=prevBottom + verticalGap;
                const currentTop=current.y - currentHeight/2;
                if(currentTop<minTop){
                  const delta=minTop-currentTop;
                  shiftSubtree(current,delta);
                  recenterAncestors(current.parent||null);
                }
              }
            }
          };
          ensureLayerSpacing();
          this.bounds={minX:Infinity,maxX:-Infinity,minY:Infinity,maxY:-Infinity};
          for(const node of this.nodes.values()){
            const nodeDepth=(typeof node.depth==='number')?node.depth:0;
            const height=(node._layoutHeight!=null)?node._layoutHeight:measure(node, nodeDepth);
            const top=node.y - height/2;
            const bottom=node.y + height/2;
            if(top<this.bounds.minY) this.bounds.minY=top;
            if(bottom>this.bounds.maxY) this.bounds.maxY=bottom;
            if(node.x<this.bounds.minX) this.bounds.minX=node.x;
            if(node.x>this.bounds.maxX) this.bounds.maxX=node.x;
          }
          if(!isFinite(this.bounds.minY)){ this.bounds={minX:-100,maxX:100,minY:0,maxY:400}; }
          const margin=80;
          const shiftX=-this.bounds.minX+margin;
          const shiftY=-this.bounds.minY+margin;
          this.bounds.width=this.bounds.maxX-this.bounds.minX+margin*2;
          this.bounds.height=this.bounds.maxY-this.bounds.minY+margin*2;
          for(const node of this.nodes.values()){
            node.absX=node.x+shiftX;
            node.absY=node.y+shiftY;
          }
          if(this.guideLayer){
            this.guideLayer.style.width=`${this.bounds.width}px`;
            this.guideLayer.style.height=`${this.bounds.height}px`;
          }
          this.updateGuides(layers,columnPositions,shiftX,shiftY);
          this.linkLayer.setAttribute('viewBox',`0 0 ${this.bounds.width} ${this.bounds.height}`);
          this.linkLayer.setAttribute('width',`${this.bounds.width}`);
          this.linkLayer.setAttribute('height',`${this.bounds.height}`);
          this.linkLayer.style.width=`${this.bounds.width}px`;
          this.linkLayer.style.height=`${this.bounds.height}px`;
          if(this.relationLayer){
            this.relationLayer.setAttribute('viewBox',`0 0 ${this.bounds.width} ${this.bounds.height}`);
            this.relationLayer.setAttribute('width',`${this.bounds.width}`);
            this.relationLayer.setAttribute('height',`${this.bounds.height}`);
            this.relationLayer.style.width=`${this.bounds.width}px`;
            this.relationLayer.style.height=`${this.bounds.height}px`;
          }
          if(this.linkControlLayer){
            this.linkControlLayer.style.width='100%';
            this.linkControlLayer.style.height='100%';
          }
          this.nodeLayer.style.width=`${this.bounds.width}px`;
          this.nodeLayer.style.height=`${this.bounds.height}px`;
          this.viewport.style.width=`${this.bounds.width}px`;
          this.viewport.style.height=`${this.bounds.height}px`;
        }
        updateGuides(layers,columnPositions,shiftX,shiftY){
          if(!this.guideLayer) return;
          const layoutOptions=this.options && this.options.layout ? this.options.layout : {};
          if(!layoutOptions.debugGuides){
            this.guideLayer.style.display='none';
            this.guideLayer.innerHTML='';
            return;
          }
          this.guideLayer.style.display='block';
          this.guideLayer.innerHTML='';
          const columnEntries=[...columnPositions.entries()].sort((a,b)=>a[0]-b[0]);
          const verticalColor='rgba(227,198,139,0.35)';
          const horizontalColor='rgba(98,173,255,0.28)';
          for(const [,x] of columnEntries){
            const line=document.createElement('div');
            line.className='guide-line guide-vertical';
            line.style.position='absolute';
            line.style.top='0';
            line.style.bottom='0';
            line.style.left=`${x+shiftX}px`;
            line.style.width='1px';
            line.style.background=verticalColor;
            this.guideLayer.appendChild(line);
          }
          const seen=new Set();
          for(const nodes of layers.values()){
            for(const node of nodes){
              const y=node.y+shiftY;
              const key=Math.round(y*100)/100;
              if(seen.has(key)) continue;
              seen.add(key);
              const line=document.createElement('div');
              line.className='guide-line guide-horizontal';
              line.style.position='absolute';
              line.style.left='0';
              line.style.right='0';
              line.style.top=`${y}px`;
              line.style.height='1px';
              line.style.background=horizontalColor;
              this.guideLayer.appendChild(line);
            }
          }
        }
        buildNodeElement(node,{forMeasure=false}={}){
          const el=document.createElement('div');
          el.className='jsmind-node';
          if(node.isroot) el.classList.add('isroot');
          if(node.children && node.children.length) el.classList.add('has-children');
          if(node.expanded===false) el.classList.add('is-collapsed');
          if(node.selected && !forMeasure){ el.classList.add('selected'); }
          el.dataset.nodeid=node.id;
          el.setAttribute('nodeid', node.id);
          el.dataset.foldState=node.expanded===false?'collapsed':'expanded';
          let orientation=null;
          if(node.direction==='left' || node.dir===-1) orientation='left';
          else if(node.direction==='right' || node.dir===1) orientation='right';
          else if(node.parent){
            if(node.absX < node.parent.absX) orientation='left';
            else if(node.absX > node.parent.absX) orientation='right';
          }
          if(orientation){ el.dataset.direction=orientation; }
          else{ el.removeAttribute('data-direction'); }
          const span=document.createElement('span');
          span.className='node-topic';
          span.textContent=node.topic || '';
          el.appendChild(span);
          const data=node.data || {};
          el.dataset.icon=DEFAULT_NODE_ICON;
          el.dataset.depth=String(node.depth||0);
          const attachments=gatherAttachments(data);
          if(attachments.length){ el.classList.add('has-attachment'); }
          if(data.url){ el.classList.add('has-link'); }
          const noteText=typeof data.note==='string'?data.note:'';
          if(noteText.trim()){
            const note=document.createElement('div');
            note.className='node-note';
            note.textContent=noteText;
            el.appendChild(note);
          }
          const badges=[];
          if(attachments.length){
            attachments.forEach(att=>{
              const badge=document.createElement('button');
              badge.type='button';
              badge.className='node-badge attachment';
              const label=attachmentLabel(att);
              badge.textContent='📎 '+label;
              badge.title=`打开附件：${label}`;
              if(forMeasure){
                badge.disabled=true;
              }else{
                badge.addEventListener('click',evt=>{ evt.preventDefault(); evt.stopPropagation(); openMindmapAttachment(att); });
              }
              badges.push(badge);
            });
          }
          if(data.url){
            const badge=document.createElement('button');
            badge.type='button';
            badge.className='node-badge link';
            const linkLabel=data.linkTitle || '打开链接';
            badge.textContent='🔗 '+linkLabel;
            badge.title=linkLabel;
            if(forMeasure){
              badge.disabled=true;
            }else{
              badge.addEventListener('click',evt=>{ evt.preventDefault(); evt.stopPropagation(); openMindmapLink(data.url); });
            }
            badges.push(badge);
          }
          if(badges.length){
            const wrap=document.createElement('div');
            wrap.className='node-affordances';
            badges.forEach(btn=>wrap.appendChild(btn));
            el.appendChild(wrap);
          }
          if(!forMeasure && node.children && node.children.length && node.expanded===false){
            const marker=document.createElement('button');
            marker.type='button';
            marker.className='node-collapse-marker';
            marker.innerHTML='<span class="icon">⤴</span><span class="text">展开</span>';
            marker.setAttribute('aria-label','展开节点');
            marker.addEventListener('click',evt=>{
              evt.preventDefault();
              evt.stopPropagation();
              const latest=jm.get_node(node.id);
              if(latest){ setNodeExpandedState(latest,true); }
            });
            el.appendChild(marker);
          }
          if(!forMeasure){
            el.addEventListener('click',(evt)=>{
              const wasSelected=!!node.selected;
              this.select_node(node.id);
              if(isCompactViewport()){
                const now=Date.now();
                if(wasSelected && lastTapInfo.id===node.id && (now-lastTapInfo.time)<=DOUBLE_TAP_WINDOW){
                  evt.preventDefault();
                  this.showNodeDetails(node);
                }
                lastTapInfo={id:node.id,time:now};
              }
            });
            el.addEventListener('dblclick',()=>{ this.showNodeDetails(node); });
          }
          return el;
        }
        render(){
          this.clearEdgeHover();
          this.nodeLayer.innerHTML='';
          while(this.linkLayer.firstChild){ this.linkLayer.removeChild(this.linkLayer.firstChild); }
          if(this.linkControlLayer){ this.linkControlLayer.innerHTML=''; }
          if(this.relationLayer){ while(this.relationLayer.firstChild){ this.relationLayer.removeChild(this.relationLayer.firstChild); } }
          if(this.resizeObserver){ this.resizeObserver.disconnect(); }
          this.linkRegistry.clear();
          if(this.relationRegistry){ this.relationRegistry.clear(); }
          if(!this.root) return;
          const walk=(node)=>{
            node.el=this.buildNodeElement(node,{forMeasure:false});
            if(node.style){
              if(node.style.background){ node.el.style.background=node.style.background; }
              if(node.style.foreground){ node.el.style.color=node.style.foreground; }
            }
            this.nodeLayer.appendChild(node.el);
            node.width=node.el.offsetWidth;
            node.height=node.el.offsetHeight;
            this.sizeCache.set(node.id,{width:node.width,height:node.height});
            this.updateNodePosition(node);
            this.updateAnchors(node);
            if(node.parent){
              const group=document.createElementNS('http://www.w3.org/2000/svg','g');
              group.classList.add('trace-group');
              const shadow=document.createElementNS('http://www.w3.org/2000/svg','path');
              shadow.classList.add('trace','shadow');
              const core=document.createElementNS('http://www.w3.org/2000/svg','path');
              core.classList.add('trace','core');
              core.setAttribute('stroke','url(#mindGoldTrace)');
              const highlight=document.createElementNS('http://www.w3.org/2000/svg','path');
              highlight.classList.add('trace','highlight');
              shadow.style.pointerEvents='none';
              highlight.style.pointerEvents='none';
              core.style.pointerEvents='visibleStroke';
              group.appendChild(shadow);
              group.appendChild(core);
              group.appendChild(highlight);
              group.dataset.from=node.parent.id;
              group.dataset.to=node.id;
              this.linkLayer.appendChild(group);
              node.linkGroup=group;
              node.linkShadow=shadow;
              node.linkPath=core;
              node.linkHighlight=highlight;
              const parentId=node.parent.id;
              const childId=node.id;
              const enterEdge=()=>this.setEdgeHover(parentId, childId);
              const leaveEdge=()=>this.clearEdgeHover(parentId, childId);
              core.addEventListener('pointerenter', enterEdge);
              core.addEventListener('pointerleave', leaveEdge);
              core.addEventListener('pointercancel', leaveEdge);
              this.linkRegistry.set(node.id,{group,shadow,core,highlight});
              this.updateLinkPath(node);
            }
            if(this.resizeObserver){ this.resizeObserver.observe(node.el); }
            if(node.expanded){
              node.children.forEach(child=>walk(child));
            }
          };
          walk(this.root);
          this.renderRelations();
          this.updateEdgeButtonScale();
          this.enforceScaleBounds(null);
          this.applyTransform(true);
        }
        renderRelations(){
          if(!this.relationLayer) return;
          while(this.relationLayer.firstChild){ this.relationLayer.removeChild(this.relationLayer.firstChild); }
          if(this.relationRegistry){ this.relationRegistry.clear(); }
          if(!Array.isArray(this.relations) || !this.relations.length) return;
          for(const relation of this.relations){
            if(!relation) continue;
            const fromNode=this.nodes.get(relation.from);
            const toNode=this.nodes.get(relation.to);
            if(!fromNode || !toNode || fromNode===toNode) continue;
            const group=document.createElementNS('http://www.w3.org/2000/svg','g');
            group.classList.add('relation-group');
            group.dataset.id=relation.id;
            group.dataset.from=relation.from;
            group.dataset.to=relation.to;
            const shadow=document.createElementNS('http://www.w3.org/2000/svg','path');
            shadow.classList.add('relation-shadow');
            const core=document.createElementNS('http://www.w3.org/2000/svg','path');
            core.classList.add('relation-core');
            core.dataset.bidirectional=relation.bidirectional?'true':'false';
            core.setAttribute('marker-end','url(#mindRelationArrow)');
            if(relation.bidirectional){ core.setAttribute('marker-start','url(#mindRelationArrow)'); }
            else{ core.removeAttribute('marker-start'); }
            const highlight=document.createElementNS('http://www.w3.org/2000/svg','path');
            highlight.classList.add('relation-highlight');
            group.appendChild(shadow);
            group.appendChild(core);
            group.appendChild(highlight);
            this.relationLayer.appendChild(group);
            this.relationRegistry.set(relation.id,{group,shadow,core,highlight,relation});
            this.updateRelationPath(relation);
          }
        }
        updateNodePosition(node){
          if(!node || !node.el) return;
          const width=node.width || node.el.offsetWidth || 0;
          const height=node.height || node.el.offsetHeight || 0;
          node.el.style.left=`${node.absX - width/2}px`;
          node.el.style.top=`${node.absY - height/2}px`;
        }
        updateAnchors(node){
          if(!node) return;
          const width=node.width || (node.el?node.el.offsetWidth:0) || 0;
          const height=node.height || (node.el?node.el.offsetHeight:0) || 0;
          node.anchors={
            center:{x:node.absX,y:node.absY},
            left:{x:node.absX - width/2,y:node.absY},
            right:{x:node.absX + width/2,y:node.absY},
            top:{x:node.absX,y:node.absY - height/2},
            bottom:{x:node.absX,y:node.absY + height/2},
          };
        }
        updateLinkPath(node){
          if(!node || !node.parent || !node.linkPath) return;
          if(!node.anchors) this.updateAnchors(node);
          if(!node.parent.anchors) this.updateAnchors(node.parent);
          const parent=node.parent;
          const isLeft=node.dir===-1 || node.direction==='left' || node.absX<=parent.absX;
          const baseStart=isLeft ? parent.anchors.left : parent.anchors.right;
          const end=isLeft ? node.anchors.right : node.anchors.left;
          const siblings=(parent.children||[]).filter(Boolean);
          const scale=(typeof this.scale==='number' && this.scale>0)?this.scale:1;
          let portYOffset=0;
          if(siblings.length>1){
            const index=siblings.indexOf(node);
            if(index!==-1){
              const mid=(siblings.length-1)/2;
              portYOffset=(index-mid)*10/scale;
            }
          }
          const start={x:baseStart.x,y:baseStart.y+portYOffset};
          const route=buildTraceRoute(start,end,isLeft?-1:1);
          let pathData=buildChamferedPath(route, TRACE_CHAMFER);
          if(!pathData){
            pathData=`M${start.x} ${start.y} L${end.x} ${end.y}`;
          }
          node.linkPath.setAttribute('d', pathData);
          if(node.linkShadow){ node.linkShadow.setAttribute('d', pathData); }
          if(node.linkHighlight){ node.linkHighlight.setAttribute('d', pathData); }
          const routePoints=(Array.isArray(route) && route.length>=2)?route:[start,end];
          this.positionEdgeInsertButton(node, routePoints);
        }
        ensureEdgeInsertButton(node){
          if(!node || !node.parent || !this.linkControlLayer) return null;
          if(node.edgeButton && !node.edgeButton.isConnected){ node.edgeButton=null; }
          let btn=node.edgeButton||null;
          if(!btn){
            btn=document.createElement('button');
            btn.type='button';
            btn.className='edge-insert-btn';
            btn.textContent='＋';
            btn.title='在该连线上插入节点';
            btn.setAttribute('aria-label','在该连线上插入节点');
            btn.dataset.parent=node.parent.id;
            btn.dataset.child=node.id;
            const stopPropagation=evt=>{ if(evt){ evt.stopPropagation(); } };
            btn.addEventListener('pointerdown',stopPropagation);
            btn.addEventListener('mousedown',stopPropagation);
            btn.addEventListener('touchstart',stopPropagation,{passive:true});
            btn.addEventListener('click',evt=>{
              evt.preventDefault();
              evt.stopPropagation();
              if(typeof this.options.onInsertBetween==='function'){
                try{ this.options.onInsertBetween(node.parent, node); }
                catch(err){ console.error(err); }
              }
            });
            const handleEnter=()=>{
              const parentId=btn.dataset.parent;
              const childId=btn.dataset.child;
              btn._edgeHoverActivePair={parent:parentId, child:childId};
              this.setEdgeHover(parentId, childId);
            };
            const handleLeave=()=>{
              const pair=btn._edgeHoverActivePair;
              if(pair && pair.parent && pair.child){
                this.clearEdgeHover(pair.parent, pair.child);
              }else{
                this.clearEdgeHover();
              }
              btn._edgeHoverActivePair=null;
            };
            btn.addEventListener('pointerenter',handleEnter);
            btn.addEventListener('pointerleave',handleLeave);
            btn.addEventListener('pointercancel',handleLeave);
            btn.addEventListener('focus',handleEnter);
            btn.addEventListener('blur',handleLeave);
            btn._edgeHoverHandlers={enter:handleEnter,leave:handleLeave};
            this.linkControlLayer.appendChild(btn);
            node.edgeButton=btn;
          }
          btn.dataset.parent=node.parent.id;
          btn.dataset.child=node.id;
          return btn;
        }
        computeRouteMidpoint(points){
          if(!Array.isArray(points) || !points.length) return null;
          let total=0;
          for(let i=1;i<points.length;i++){
            const prev=points[i-1];
            const current=points[i];
            total+=Math.hypot(current.x-prev.x, current.y-prev.y);
          }
          if(total<=0){
            const first=points[0];
            return first?{x:first.x,y:first.y}:null;
          }
          let traversed=0;
          const halfway=total/2;
          for(let i=1;i<points.length;i++){
            const prev=points[i-1];
            const current=points[i];
            const segment=Math.hypot(current.x-prev.x, current.y-prev.y);
            if(segment<=0){
              continue;
            }
            if(traversed+segment>=halfway){
              const ratio=(halfway-traversed)/segment;
              return {
                x:prev.x + (current.x-prev.x)*ratio,
                y:prev.y + (current.y-prev.y)*ratio,
              };
            }
            traversed+=segment;
          }
          const last=points[points.length-1];
          return last?{x:last.x,y:last.y}:null;
        }
        positionEdgeInsertButton(node, points){
          if(!node || !node.parent || !Array.isArray(points) || points.length<2) return;
          const btn=this.ensureEdgeInsertButton(node);
          if(!btn) return;
          if(node.parent && node.parent.expanded===false){
            btn.hidden=true;
            this.clearEdgeHover(node.parent.id, node.id);
            btn._logicalPosition=null;
            delete btn.dataset.logicalX;
            delete btn.dataset.logicalY;
            return;
          }
          const mid=this.computeRouteMidpoint(points);
          if(!mid){
            btn.hidden=true;
            this.clearEdgeHover(node.parent.id, node.id);
            if(btn._edgeHoverActivePair){ btn._edgeHoverActivePair=null; }
            btn._logicalPosition=null;
            delete btn.dataset.logicalX;
            delete btn.dataset.logicalY;
            return;
          }
          if(!Number.isFinite(mid.x) || !Number.isFinite(mid.y)){
            btn.hidden=true;
            btn._logicalPosition=null;
            delete btn.dataset.logicalX;
            delete btn.dataset.logicalY;
            return;
          }
          btn.hidden=false;
          const logical={x:mid.x, y:mid.y};
          btn._logicalPosition=logical;
          btn.dataset.logicalX=String(logical.x);
          btn.dataset.logicalY=String(logical.y);
          const scale=(typeof this.scale==='number' && this.scale>0)?this.scale:1;
          const offsetX=Number.isFinite(this.offsetX)?this.offsetX:0;
          const offsetY=Number.isFinite(this.offsetY)?this.offsetY:0;
          const screenX=logical.x*scale + offsetX;
          const screenY=logical.y*scale + offsetY;
          btn.style.left=`${screenX}px`;
          btn.style.top=`${screenY}px`;
        }
        setEdgeHover(fromId,toId){
          if(!fromId || !toId) return;
          if(this.edgeHoverState && this.edgeHoverState.from===fromId && this.edgeHoverState.to===toId){
            return;
          }
          this.clearEdgeHover();
          this.edgeHoverState={from:fromId,to:toId};
          const parent=this.nodes.get(fromId);
          if(parent && parent.el){ parent.el.classList.add('edge-glow'); }
          const child=this.nodes.get(toId);
          if(child && child.el){ child.el.classList.add('edge-glow'); }
        }
        clearEdgeHover(expectedFrom, expectedTo){
          if(!this.edgeHoverState) return;
          if(expectedFrom && expectedTo){
            if(this.edgeHoverState.from!==expectedFrom || this.edgeHoverState.to!==expectedTo){
              return;
            }
          }
          const {from,to}=this.edgeHoverState;
          this.edgeHoverState=null;
          const parent=this.nodes.get(from);
          if(parent && parent.el){ parent.el.classList.remove('edge-glow'); }
          const child=this.nodes.get(to);
          if(child && child.el){ child.el.classList.remove('edge-glow'); }
        }
        updateEdgeButtonScale(){
          if(!this.linkControlLayer || !this.nodes) return;
          const scale=(typeof this.scale==='number' && this.scale>0)?this.scale:1;
          const offsetX=Number.isFinite(this.offsetX)?this.offsetX:0;
          const offsetY=Number.isFinite(this.offsetY)?this.offsetY:0;
          for(const node of this.nodes.values()){
            if(!node) continue;
            const btn=node.edgeButton;
            if(!btn || !btn.isConnected || btn.hidden) continue;
            let logical=btn._logicalPosition || null;
            if((!logical || !Number.isFinite(logical.x) || !Number.isFinite(logical.y)) && btn.dataset){
              const parsedX=parseFloat(btn.dataset.logicalX || '');
              const parsedY=parseFloat(btn.dataset.logicalY || '');
              if(Number.isFinite(parsedX) && Number.isFinite(parsedY)){
                logical={x:parsedX,y:parsedY};
                btn._logicalPosition=logical;
              }
            }
            if(!logical || !Number.isFinite(logical.x) || !Number.isFinite(logical.y)) continue;
            const screenX=logical.x*scale + offsetX;
            const screenY=logical.y*scale + offsetY;
            btn.style.left=`${screenX}px`;
            btn.style.top=`${screenY}px`;
          }
        }
        updateRelationPath(relation){
          if(!relation) return;
          const entry=this.relationRegistry ? this.relationRegistry.get(relation.id) : null;
          if(!entry) return;
          const fromNode=this.nodes.get(relation.from);
          const toNode=this.nodes.get(relation.to);
          if(!fromNode || !toNode) return;
          if(!fromNode.anchors) this.updateAnchors(fromNode);
          if(!toNode.anchors) this.updateAnchors(toNode);
          const startCenter=fromNode.anchors ? fromNode.anchors.center : null;
          const endCenter=toNode.anchors ? toNode.anchors.center : null;
          if(!startCenter || !endCenter) return;
          const startInner=this.computeNodeBoundaryPoint(fromNode, {x:endCenter.x-startCenter.x,y:endCenter.y-startCenter.y}, 6);
          const endInner=this.computeNodeBoundaryPoint(toNode, {x:startCenter.x-endCenter.x,y:startCenter.y-endCenter.y}, 6);
          if(!startInner || !endInner) return;
          let vector={x:endInner.x-startInner.x,y:endInner.y-startInner.y};
          let segmentLength=Math.hypot(vector.x, vector.y);
          if(segmentLength<0.001){
            vector={x:endCenter.x-startCenter.x,y:endCenter.y-startCenter.y};
            segmentLength=Math.hypot(vector.x, vector.y);
            if(segmentLength<0.001) return;
            startInner={x:startCenter.x,y:startCenter.y};
            endInner={x:endCenter.x,y:endCenter.y};
          }
          const norm={x:vector.x/segmentLength,y:vector.y/segmentLength};
          const arrowBase=Math.min(22, Math.max(10, segmentLength*0.18));
          const halfDistance=segmentLength/2;
          const clearanceLimit=Math.max(0, halfDistance - 6);
          const effectiveClearance=Math.max(0, Math.min(arrowBase, clearanceLimit, segmentLength - 8));
          const endClearance=effectiveClearance;
          const startClearance=relation.bidirectional ? effectiveClearance : 0;
          const startPoint={x:startInner.x - norm.x*startClearance,y:startInner.y - norm.y*startClearance};
          const endPoint={x:endInner.x - norm.x*endClearance,y:endInner.y - norm.y*endClearance};
          const dx=endPoint.x-startPoint.x;
          const dy=endPoint.y-startPoint.y;
          const distance=Math.hypot(dx,dy) || 1;
          const normalX=distance?-dy/distance:0;
          const normalY=distance?dx/distance:0;
          const offset=Math.min(140, Math.max(30, distance*0.2));
          const ctrl1x=startPoint.x + dx*0.25 + normalX*offset;
          const ctrl1y=startPoint.y + dy*0.25 + normalY*offset;
          const ctrl2x=startPoint.x + dx*0.75 - normalX*offset;
          const ctrl2y=startPoint.y + dy*0.75 - normalY*offset;
          const pathData=`M${startPoint.x} ${startPoint.y} C ${ctrl1x} ${ctrl1y}, ${ctrl2x} ${ctrl2y}, ${endPoint.x} ${endPoint.y}`;
          entry.shadow.setAttribute('d', pathData);
          entry.core.setAttribute('d', pathData);
          entry.highlight.setAttribute('d', pathData);
          entry.core.dataset.bidirectional=relation && relation.bidirectional?'true':'false';
          if(relation.bidirectional){ entry.core.setAttribute('marker-start','url(#mindRelationArrow)'); }
          else{ entry.core.removeAttribute('marker-start'); }
          entry.relation=relation;
        }
        computeNodeBoundaryPoint(node, directionVector, padding){
          if(!node || !directionVector) return null;
          const width=Math.max(1, node.width || (node.el?node.el.offsetWidth:0) || 0);
          const height=Math.max(1, node.height || (node.el?node.el.offsetHeight:0) || 0);
          const halfW=width/2 + (padding||0);
          const halfH=height/2 + (padding||0);
          let dx=typeof directionVector.x==='number'?directionVector.x:0;
          let dy=typeof directionVector.y==='number'?directionVector.y:0;
          const tiny=1e-6;
          if(Math.abs(dx)<tiny && Math.abs(dy)<tiny){
            return {x:node.absX,y:node.absY};
          }
          if(Math.abs(dx)<tiny){ dx=dx>=0?tiny:-tiny; }
          if(Math.abs(dy)<tiny){ dy=dy>=0?tiny:-tiny; }
          const absDx=Math.abs(dx);
          const absDy=Math.abs(dy);
          let scale;
          if(absDx<tiny){ scale=halfH/absDy; }
          else if(absDy<tiny){ scale=halfW/absDx; }
          else{ scale=Math.min(halfW/absDx, halfH/absDy); }
          return {
            x:node.absX + dx*scale,
            y:node.absY + dy*scale,
          };
        }
        updateRelationsForNode(node){
          if(!node || !this.relationRegistry || !this.relationRegistry.size) return;
          for(const entry of this.relationRegistry.values()){
            if(!entry || !entry.relation) continue;
            if(entry.relation.from===node.id || entry.relation.to===node.id){
              this.updateRelationPath(entry.relation);
            }
          }
        }
        ensureRelationArray(){
          if(!Array.isArray(this.relations)){ this.relations=[]; }
          if(this.mind){ this.mind.relations=this.relations; }
          return this.relations;
        }
        generateRelationId(){
          const relations=this.ensureRelationArray();
          let id;
          do { id='rel-'+Math.random().toString(36).slice(2,10); }
          while((this.relationRegistry && this.relationRegistry.has(id)) || relations.some(rel=>rel && rel.id===id));
          return id;
        }
        add_relation(fromId,toId,options){
          if(!fromId || !toId || fromId===toId) return null;
          const source=this.get_node(fromId);
          const target=this.get_node(toId);
          if(!source || !target) return null;
          const relations=this.ensureRelationArray();
          const bidirectional=options && options.bidirectional===true;
          const conflict=relations.some(rel=>rel && rel.from===fromId && rel.to===toId && (!!rel.bidirectional)===bidirectional);
          if(conflict) return null;
          const relation={
            id:this.generateRelationId(),
            from:fromId,
            to:toId
          };
          if(options && options.label && typeof options.label==='string' && options.label.trim()!==''){
            relation.label=options.label.trim();
          }
          if(bidirectional){ relation.bidirectional=true; }
          relations.push(relation);
          this.mind.relations=relations;
          this.renderRelations();
          this.emit(SimpleMind.event_type.update);
          return relation;
        }
        remove_relation(id){
          if(!id) return false;
          const relations=this.ensureRelationArray();
          const idx=relations.findIndex(rel=>rel && rel.id===id);
          if(idx===-1) return false;
          const [removed]=relations.splice(idx,1);
          if(this.relationRegistry && this.relationRegistry.has(id)){
            const entry=this.relationRegistry.get(id);
            if(entry && entry.group && entry.group.parentNode){ entry.group.parentNode.removeChild(entry.group); }
            this.relationRegistry.delete(id);
          }
          this.mind.relations=relations;
          if(removed){ this.renderRelations(); this.emit(SimpleMind.event_type.update); }
          return true;
        }
        pruneRelations(predicate, options={}){
          if(typeof predicate!=='function') return false;
          const relations=this.ensureRelationArray();
          let changed=false;
          for(let i=relations.length-1;i>=0;i--){
            const rel=relations[i];
            if(!rel) continue;
            if(predicate(rel)){
              relations.splice(i,1);
              changed=true;
              if(this.relationRegistry){
                const entry=this.relationRegistry.get(rel.id);
                if(entry && entry.group && entry.group.parentNode){ entry.group.parentNode.removeChild(entry.group); }
                this.relationRegistry.delete(rel.id);
              }
            }
          }
          if(changed){
            this.mind.relations=relations;
            if(!options || options.silent!==true){
              this.renderRelations();
              this.emit(SimpleMind.event_type.update);
            }
          }
          return changed;
        }
        get_relations(nodeId){
          const relations=this.ensureRelationArray();
          const clone=input=>JSON.parse(JSON.stringify(input));
          if(!nodeId) return clone(relations);
          return clone(relations.filter(rel=>rel && (rel.from===nodeId || rel.to===nodeId)));
        }
        handleNodeResize(entries){
          if(!entries || !entries.length) return;
          const impacted=new Set();
          entries.forEach(entry=>{
            const target=entry.target;
            if(!target) return;
            const nodeId=target.getAttribute('nodeid');
            if(!nodeId) return;
            const node=this.nodes.get(nodeId);
            if(!node) return;
            const rect=entry.contentRect;
            node.width=rect.width;
            node.height=rect.height;
            this.updateNodePosition(node);
            this.updateAnchors(node);
            impacted.add(node);
          });
          if(!impacted.size) return;
          impacted.forEach(node=>{
            this.updateLinkPath(node);
            if(node.children && node.children.length){
              node.children.forEach(child=>this.updateLinkPath(child));
            }
            this.updateRelationsForNode(node);
            if(node.children && node.children.length){
              node.children.forEach(child=>this.updateRelationsForNode(child));
            }
          });
        }
        applyTransform(initial){
          if(!this.bounds){ return; }
          if(initial && !this.hasCentered){ this.center_root(); this.hasCentered=true; return; }
          const transform=`translate(${this.offsetX}px, ${this.offsetY}px) scale(${this.scale})`;
          this.viewport.style.transform=transform;
          this.updateEdgeButtonScale();
        }
        zoom(step){
          const prev=this.scale;
          const next=this.clampScale(this.scale*step);
          this.scale=next;
          if(Math.abs(prev-this.scale)>0.001){ this.applyTransform(); }
        }
        viewCommand(cmd){
          switch(cmd){
            case 'zoomToFit': return this.zoomToFit();
            case 'zoom_to_fit': return this.zoomToFit();
            case 'set_zoom': return this.set_zoom(arguments[1]);
            case 'move_to_center': return this.center_root();
            case 'center_root': return this.center_root();
            case 'zoomIn': this.zoom(1.15); return true;
            case 'zoom_in': this.zoom(1.15); return true;
            case 'zoomOut': this.zoom(1/1.15); return true;
            case 'zoom_out': this.zoom(1/1.15); return true;
            default: return false;
          }
        }
        get view(){
          return {
            zoomToFit:()=>this.zoomToFit(),
            zoom_to_fit:()=>this.zoomToFit(),
            set_zoom:(v)=>this.set_zoom(v),
            move_to_center:()=>this.center_root(),
            center_root:()=>this.center_root(),
            zoomIn:()=>{ this.zoom(1.15); return true; },
            zoom_in:()=>{ this.zoom(1.15); return true; },
            zoomOut:()=>{ this.zoom(1/1.15); return true; },
            zoom_out:()=>{ this.zoom(1/1.15); return true; },
          };
        }
        zoomToFit(){
          if(!this.bounds) return false;
          const rect=this.container.getBoundingClientRect();
          if(rect.width<=0 || rect.height<=0) return false;
          const padding=(typeof this.scalePadding==='number' && this.scalePadding>=0)?this.scalePadding:0;
          const scale=Math.min(rect.width/(this.bounds.width+padding), rect.height/(this.bounds.height+padding));
          this.scale=this.clampScale(scale, rect);
          this.center_root();
          return true;
        }
        set_zoom(z){
          if(typeof z!=='number' || !isFinite(z)) return false;
          this.scale=this.clampScale(z);
          this.applyTransform();
          return true;
        }
        center_root(){
          if(!this.root || !this.bounds) return false;
          const rect=this.container.getBoundingClientRect();
          this.enforceScaleBounds(rect,{adjustOffset:false});
          const centerX=this.root.absX + (this.root.el?this.root.el.offsetWidth/2:0);
          const centerY=this.root.absY + (this.root.el?this.root.el.offsetHeight/2:0);
          this.offsetX=rect.width/2 - centerX*this.scale;
          this.offsetY=rect.height/2 - centerY*this.scale;
          this.applyTransform();
          return true;
        }
        center_node(target){
          if(!target || !this.bounds) return false;
          const node=typeof target==='string'?this.get_node(target):target;
          if(!node) return false;
          const rect=this.container.getBoundingClientRect();
          if(rect.width<=0 || rect.height<=0) return false;
          this.enforceScaleBounds(rect,{adjustOffset:false});
          if(!node.anchors || !node.anchors.center){ this.updateAnchors(node); }
          let anchor=null;
          if(node.anchors && node.anchors.center){ anchor=node.anchors.center; }
          if(!anchor){
            const cached=this.sizeCache.get(node.id)||{};
            const width=node.el?node.el.offsetWidth:(cached.width||0);
            const height=node.el?node.el.offsetHeight:(cached.height||0);
            const baseX=(Number.isFinite(node.absX)?node.absX:0);
            const baseY=(Number.isFinite(node.absY)?node.absY:0);
            anchor={
              x:baseX + (width?width/2:0),
              y:baseY + (height?height/2:0)
            };
          }
          if(!anchor || !Number.isFinite(anchor.x) || !Number.isFinite(anchor.y)) return false;
          this.offsetX=rect.width/2 - anchor.x*this.scale;
          this.offsetY=rect.height/2 - anchor.y*this.scale;
          this.applyTransform();
          return true;
        }
        showNodeDetails(node){
          if(!node) return;
          if(typeof this.options.onNodeDetails==='function'){
            this.options.onNodeDetails(node, this);
            return;
          }
          this.promptRename(node);
        }
        promptRename(node){
          if(!node) return;
          if(typeof this.options.onInlineEdit==='function'){
            this.options.onInlineEdit(node, this);
            return;
          }
          const text=prompt('请输入节点标题', node.topic || '');
          if(text===null) return;
          const cleaned=text.trim();
          if(!cleaned) return;
          this.update_node(node.id, cleaned);
        }
      }
      SimpleMind.event_type={
        show:'show',
        select:'select',
        refresh:'refresh',
        update:'update',
        edit:'edit',
        after_edit:'after_edit'
      };
      if(typeof window.jsMind==='undefined'){ window.jsMind=SimpleMind; }
      const defaultData = <?php echo json_encode($defaultData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
      let initialData = <?php echo json_encode($initialDataDecoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
      if(!initialData || !initialData.data){ initialData = JSON.parse(JSON.stringify(defaultData)); }
      if(defaultData && defaultData.data){ enforceRightOrientation(defaultData.data); }
      if(initialData && initialData.data){ enforceRightOrientation(initialData.data); }
    const jmContainer=document.getElementById('jsmind-container');
    let currentMapId=0;
    if(jmContainer){
      const parsed=parseInt(jmContainer.dataset.mapId || '0', 10);
      currentMapId=Number.isFinite(parsed)?parsed:0;
    }
    if(!window.jsMind){
      if(jmContainer){
        jmContainer.innerHTML='<div class="map-error"><strong>思维导图加载失败</strong><span>请刷新页面或稍后再试。</span></div>';
      }
      return;
    }
    const overlay=document.createElementNS('http://www.w3.org/2000/svg','svg');
    overlay.id='drag-overlay';
    overlay.dataset.exportIgnore='true';
    overlay.setAttribute('width','100%');
    overlay.setAttribute('height','100%');
    overlay.setAttribute('viewBox','0 0 100 100');
    const ghostLine=document.createElementNS('http://www.w3.org/2000/svg','line');
    ghostLine.setAttribute('opacity','0');
    const ghostRing=document.createElementNS('http://www.w3.org/2000/svg','circle');
    ghostRing.setAttribute('opacity','0');
    overlay.appendChild(ghostLine);
    overlay.appendChild(ghostRing);
    const jm=new jsMind({
      container:'jsmind-container',
      editable:true,
        theme:'fresh-blue',
        support_html:true,
        mode:'full',
        onInsertBetween:(parent, child)=>handleInsertBetweenNodes(parent, child),
      });
      const blobUrlRegistry=new Set();
      const externalScriptCache=new Map();
      function loadExternalScript(src, resolver){
        if(typeof resolver==='function'){
          try{
            const immediate=resolver();
            if(immediate){ return Promise.resolve(immediate); }
          }catch(_){ }
        }
        if(externalScriptCache.has(src)){
          return externalScriptCache.get(src);
        }
        const promise=new Promise((resolve,reject)=>{
          const script=document.createElement('script');
          script.src=src;
          script.async=true;
          script.onload=()=>{
            if(typeof resolver==='function'){
              try{
                const resolved=resolver();
                if(resolved){ resolve(resolved); return; }
              }catch(err){ console.warn(err); }
            }
            resolve();
          };
          script.onerror=()=>{
            externalScriptCache.delete(src);
            reject(new Error(`Failed to load script: ${src}`));
          };
          document.head.appendChild(script);
        });
        externalScriptCache.set(src,promise);
        return promise;
      }
      async function ensureHtmlToImage(){
        if(window.htmlToImage){ return window.htmlToImage; }
        const lib=await loadExternalScript('https://cdn.jsdelivr.net/npm/html-to-image@1.11.11/dist/html-to-image.min.js',()=>window.htmlToImage);
        if(lib){ return lib; }
        if(window.htmlToImage){ return window.htmlToImage; }
        throw new Error('图像导出依赖加载失败');
      }
      async function ensureJsPDF(){
        if(window.jspdf && window.jspdf.jsPDF){ return window.jspdf.jsPDF; }
        const lib=await loadExternalScript('https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js',()=>window.jspdf);
        if(lib && lib.jsPDF){ return lib.jsPDF; }
        if(window.jspdf && window.jspdf.jsPDF){ return window.jspdf.jsPDF; }
        throw new Error('PDF 导出依赖加载失败');
      }
      function canvasToBlob(canvas, type, quality){
        return new Promise((resolve,reject)=>{
          if(!canvas || typeof canvas.toBlob!=='function'){
            reject(new Error('Canvas 不受支持'));
            return;
          }
          canvas.toBlob(blob=>{
            if(blob){ resolve(blob); }
            else{ reject(new Error('生成图像失败')); }
          }, type, quality);
        });
      }
      function buildExportFilename(extension, titleValue){
        const safeTitle=(titleValue || 'mindmap').replace(/[\\/:*?"<>|]/g,'_').replace(/\s+/g,' ').trim() || 'mindmap';
        const now=new Date();
        const pad=num=>String(num).padStart(2,'0');
        const timestamp=`${now.getFullYear()}${pad(now.getMonth()+1)}${pad(now.getDate())}-${pad(now.getHours())}${pad(now.getMinutes())}${pad(now.getSeconds())}`;
        return `${safeTitle}-${timestamp}.${extension}`;
      }
      function dataUrlToBlob(dataUrl){
        if(typeof dataUrl!=='string') return null;
        const match=dataUrl.match(/^data:(.*?);base64,(.*)$/);
        if(!match) return null;
        const mime=match[1]||'application/octet-stream';
        const binary=atob(match[2].replace(/\s+/g,''));
        const len=binary.length;
        const bytes=new Uint8Array(len);
        for(let i=0;i<len;i++){ bytes[i]=binary.charCodeAt(i); }
        return new Blob([bytes],{type:mime});
      }
      function openMindmapAttachment(descriptor){
        if(!descriptor) return;
        const fallbackUrl=descriptor.url || (descriptor.assetId ? `?mindmap_asset=${descriptor.assetId}` : '');
        const prettyName=attachmentLabel(descriptor);
        const sizeValue=typeof descriptor.size==='number'?descriptor.size:(typeof descriptor.filesize==='number'?descriptor.filesize:(typeof descriptor.length==='number'?descriptor.length:null));
        const mimeHint=typeof descriptor.mime==='string'?descriptor.mime:'';
        if(window.AttachmentPreview){
          if(descriptor.content){
            const blob=dataUrlToBlob(descriptor.content);
            if(!blob){ alert('附件不可用'); return; }
            window.AttachmentPreview.open({kind:'blob',blob,name:prettyName,mime:mimeHint||blob.type||'',size:blob.size || sizeValue || null,downloadName:descriptor.name || descriptor.filename || prettyName});
            return;
          }
          if(fallbackUrl){
            window.AttachmentPreview.open({kind:'url',url:fallbackUrl,name:prettyName,mime:mimeHint,size:sizeValue||null});
            return;
          }
        }
        if(fallbackUrl){
          const win=window.open(fallbackUrl,'_blank','noopener');
          if(!win){ window.location.href=fallbackUrl; }
        }
      }
      function openMindmapLink(raw){
        if(!raw) return;
        const value=String(raw).trim();
        if(!value) return;
        if(/^javascript:/i.test(value) || /^data:/i.test(value) || /^vbscript:/i.test(value)){
          alert('该链接格式不受支持');
          return;
        }
        const target=/^https?:/i.test(value) || /^mailto:/i.test(value) || /^ftp:/i.test(value) ? value : (value.startsWith('//') ? 'https:'+value : (/^[a-z]+:/i.test(value)?value:`https://${value}`));
        const win=window.open(target,'_blank','noopener');
        if(!win){ window.location.href=target; }
      }
      function attachmentLabel(descriptor){
        let label='附件';
        if(descriptor){
          if(descriptor.name) label=descriptor.name;
          else if(descriptor.filename) label=descriptor.filename;
          else if(descriptor.assetId) label=`附件 #${descriptor.assetId}`;
        }
        label=String(label || '附件');
        return label.length>16 ? label.slice(0,15)+'…' : label;
      }
      async function uploadMindmapFile(file, nodeId){
        const fd=new FormData();
        fd.append('action','upload_mindmap_asset');
        fd.append('map_id', String(currentMapId||0));
        fd.append('node_id', nodeId);
        fd.append('file', file);
        const res=await fetch(location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'fetch'}});
        if(!res.ok) throw new Error('网络异常');
        const json=await res.json();
        if(!json.ok) throw new Error(json.error||'上传失败');
        return json;
      }
      let inlineEditState=null;
      function finishInlineEditing(commit){
        if(!inlineEditState) return;
        const {span,nodeId,initialText,onBlur,onKeydown,onPaste}=inlineEditState;
        inlineEditState=null;
        span.removeEventListener('blur', onBlur);
        span.removeEventListener('keydown', onKeydown);
        span.removeEventListener('paste', onPaste);
        span.contentEditable='false';
        span.removeAttribute('data-editing');
        if(span.parentElement){ span.parentElement.classList.remove('editing'); }
        if(!commit){
          span.textContent=initialText;
          scheduleHandleRefresh();
          return;
        }
        let value=span.textContent || '';
        value=value.replace(/\r/g,'');
        value=value.split('\n').map(line=>line.trim()).join('\n').trim();
        if(!value){
          span.textContent=initialText;
          scheduleHandleRefresh();
          return;
        }
        if(value!==initialText){
          const target=jm.get_node(nodeId);
          if(target){
            performUndoable('rename-node',()=>{
              if(typeof jm.update_node==='function'){ jm.update_node(nodeId, value); }
              markDirty();
              requestAnimationFrame(()=>refreshInspector(jm.get_node(nodeId)));
              return true;
            },{mergeKey:`rename:${nodeId}`});
          }else if(typeof jm.update_node==='function'){
            jm.update_node(nodeId, value);
            markDirty();
          }
        }
        scheduleHandleRefresh();
      }
      function startInlineEditing(node){
        if(!node || !node.el) return;
        if(inlineEditState && inlineEditState.nodeId===node.id) return;
        finishInlineEditing(true);
        const el=node.el;
        const span=el.querySelector('.node-topic');
        if(!span) return;
        const initialText=node.topic || '';
        const onBlur=()=>finishInlineEditing(true);
        const onKeydown=(e)=>{
          if(e.key==='Enter' && !e.shiftKey){
            e.preventDefault();
            span.blur();
          }else if(e.key==='Escape'){
            e.preventDefault();
            finishInlineEditing(false);
            span.blur();
          }
        };
        const onPaste=(e)=>{
          if(!e.clipboardData) return;
          e.preventDefault();
          const text=e.clipboardData.getData('text/plain');
          if(!text) return;
          const selection=window.getSelection();
          if(selection && selection.rangeCount){
            selection.deleteFromDocument();
            const range=selection.getRangeAt(0);
            const node=document.createTextNode(text);
            range.insertNode(node);
            range.setStartAfter(node);
            range.collapse(true);
            selection.removeAllRanges();
            selection.addRange(range);
          }else{
            span.textContent+=text;
            const sel=window.getSelection();
            if(sel){
              const range=document.createRange();
              range.selectNodeContents(span);
              range.collapse(false);
              sel.removeAllRanges();
              sel.addRange(range);
            }
          }
          span.normalize();
        };
        inlineEditState={nodeId:node.id, span, initialText, onBlur, onKeydown, onPaste};
        el.classList.add('editing');
        span.contentEditable='true';
        span.spellcheck=false;
        span.dataset.editing='1';
        span.addEventListener('blur', onBlur);
        span.addEventListener('keydown', onKeydown);
        span.addEventListener('paste', onPaste);
        hideHandle();
        requestAnimationFrame(()=>{
          try{ span.focus({preventScroll:true}); }
          catch(_){ span.focus(); }
          const selection=window.getSelection();
          if(selection){
            const range=document.createRange();
            range.selectNodeContents(span);
            selection.removeAllRanges();
            selection.addRange(range);
          }
        });
      }
      function commitInlineEditing(){ finishInlineEditing(true); }
      function currentEditingId(){ return inlineEditState ? inlineEditState.nodeId : null; }
      jm.options.onInlineEdit=startInlineEditing;
      jm.show(initialData);
      jmContainer.appendChild(overlay);
      syncOverlaySize();
      if(!jm.get_selected_node() && initialData && initialData.data){
        jm.select_node(initialData.data.id);
      }
      function syncOverlaySize(){
        const rect=jmContainer.getBoundingClientRect();
        const width=Math.max(rect.width,1);
        const height=Math.max(rect.height,1);
        overlay.setAttribute('viewBox',`0 0 ${width} ${height}`);
      }
      syncOverlaySize();
      window.addEventListener('resize',()=>requestAnimationFrame(syncOverlaySize));
      document.addEventListener('scroll',()=>requestAnimationFrame(syncOverlaySize), true);
      const dragHandle=document.createElement('div');
      dragHandle.id='node-handle';
      dragHandle.textContent='+';
      dragHandle.dataset.exportIgnore='true';
      let handleSource=null;
      let pointerDragState=null;
      function hideHandle(){
        dragHandle.classList.remove('show','dragging');
        if(dragHandle.parentElement){ dragHandle.remove(); }
        handleSource=null;
        hideGhost();
        pointerDragState=null;
      }
      function updateHandlePosition(){
        const node=jm.get_selected_node();
        if(!node){ hideHandle(); return; }
        const editingId=currentEditingId();
        if(editingId && editingId===node.id){ hideHandle(); return; }
        const el=document.querySelector(`.jsmind-node[nodeid="${node.id}"]`);
        if(!el){ hideHandle(); return; }
        if(dragHandle.parentElement!==el){
          if(dragHandle.parentElement){ dragHandle.remove(); }
          el.appendChild(dragHandle);
        }
        dragHandle.style.left='';
        dragHandle.style.top='';
        dragHandle.classList.add('show');
        handleSource=node;
        syncOverlaySize();
      }
      function scheduleHandleRefresh(){
        requestAnimationFrame(()=>{
          if(jm && typeof jm.enforceScaleBounds==='function'){
            const changed=jm.enforceScaleBounds();
            if(changed && typeof jm.applyTransform==='function'){
              jm.applyTransform();
            }
          }
          updateHandlePosition();
          if(popoverOpen){ positionInspectorPopover(jm.get_selected_node()); }
        });
      }
      function svgPointFromClient(x,y){
        const matrix=overlay.getScreenCTM();
        if(!matrix){ return {x,y}; }
        const pt=overlay.createSVGPoint();
        pt.x=x;
        pt.y=y;
        const result=pt.matrixTransform(matrix.inverse());
        return {x:result.x, y:result.y};
      }
      function eventToSvgPoint(evt){ return svgPointFromClient(evt.clientX, evt.clientY); }
      function nodeCenterSvg(nodeId){
        if(!nodeId) return null;
        const el=document.querySelector(`.jsmind-node[nodeid="${nodeId}"]`);
        if(!el) return null;
        const rect=el.getBoundingClientRect();
        return svgPointFromClient(rect.left + rect.width/2, rect.top + rect.height/2);
      }
      function showGhost(start,end){
        if(!start || !end){ hideGhost(); return; }
        ghostLine.setAttribute('x1', start.x);
        ghostLine.setAttribute('y1', start.y);
        ghostLine.setAttribute('x2', end.x);
        ghostLine.setAttribute('y2', end.y);
        ghostLine.setAttribute('opacity','1');
        ghostRing.setAttribute('cx', end.x);
        ghostRing.setAttribute('cy', end.y);
        ghostRing.setAttribute('r', 16);
        ghostRing.setAttribute('opacity','1');
      }
      function hideGhost(){
        ghostLine.setAttribute('opacity','0');
        ghostRing.setAttribute('opacity','0');
      }
      dragHandle.addEventListener('pointerdown',e=>{
        if(!handleSource){ return; }
        e.preventDefault();
        const origin=nodeCenterSvg(handleSource.id);
        if(!origin){ return; }
        syncOverlaySize();
        dragHandle.setPointerCapture(e.pointerId);
        dragHandle.classList.add('dragging');
        const initialPoint=eventToSvgPoint(e);
        pointerDragState={ pointerId:e.pointerId, sourceId:handleSource.id, lastPoint:initialPoint, origin };
        showGhost(origin, initialPoint);
      });
      function finishPointerDrag(evt, cancelled){
        if(!pointerDragState || evt.pointerId!==pointerDragState.pointerId) return;
        const state=pointerDragState;
        pointerDragState=null;
        dragHandle.classList.remove('dragging');
        hideGhost();
        try{ dragHandle.releasePointerCapture(evt.pointerId); }catch(_){ }
        if(cancelled){ return; }
        const source=jm.get_node(state.sourceId);
        if(!source) return;
        const hovered=document.elementFromPoint(evt.clientX, evt.clientY);
        const targetEl=hovered ? hovered.closest('.jsmind-node') : null;
        if(targetEl){
          const targetId=targetEl.getAttribute('nodeid');
          const targetNode=targetId ? jm.get_node(targetId) : null;
          if(targetNode && targetNode.id!==source.id){
            executeCreateNodeCommand({
              parentId:source.id,
              topic:'🔗 '+targetNode.topic,
              data:{link:targetNode.id},
              meta:{relation:true,source:'handle'}
            });
            return;
          }
        }
        const dropPoint=eventToSvgPoint(evt);
        executeCreateNodeCommand({ parentId:source.id, topic:'子节点', position:dropPoint, meta:{source:'handle'} });
      }
      window.addEventListener('pointermove',e=>{
        if(!pointerDragState || e.pointerId!==pointerDragState.pointerId) return;
        const hovered=document.elementFromPoint(e.clientX, e.clientY);
        let ringPoint=eventToSvgPoint(e);
        if(hovered){
          const targetEl=hovered.closest('.jsmind-node');
          if(targetEl){
            const targetId=targetEl.getAttribute('nodeid');
            const center=nodeCenterSvg(targetId);
            if(center) ringPoint=center;
          }
        }
        pointerDragState.lastPoint=ringPoint;
        showGhost(pointerDragState.origin, ringPoint);
      });
      window.addEventListener('pointerup',e=>finishPointerDrag(e,false));
      window.addEventListener('pointercancel',e=>finishPointerDrag(e,true));
      window.addEventListener('resize',scheduleHandleRefresh);
      document.addEventListener('scroll',scheduleHandleRefresh, true);
      scheduleHandleRefresh();
      const titleInput=document.getElementById('map-title');
      const saveState=document.getElementById('save-state');
      const importInput=document.getElementById('import-input');
      const attachInput=document.getElementById('attach-file-input');
      const inspector=document.getElementById('node-inspector');
      const nodeTopicInput=document.getElementById('node-topic-input');
      const nodeNoteInput=document.getElementById('node-note');
      const nodeFoldField=document.getElementById('node-fold-field');
      const nodeFoldToggle=document.getElementById('node-fold-toggle');
      const nodeFoldToggleText=document.getElementById('node-fold-toggle-text');
      const nodeFoldHint=document.getElementById('node-fold-hint');
      const relationField=document.getElementById('node-relations-field');
      const relationList=document.getElementById('node-relations-list');
      const mindShell=document.querySelector('.mind-shell');
      const mindInfoBar=document.getElementById('mind-info-bar');
      const mindInfoHandle=document.getElementById('mind-info-handle');
      const mindInfoHandleIcon=mindInfoHandle ? mindInfoHandle.querySelector('.icon') : null;
      const mindInfoContent=mindInfoBar ? mindInfoBar.querySelector('.mind-info-content') : null;
      const dock=document.getElementById('mind-dock');
      const dockButtons=dock ? Array.from(dock.querySelectorAll('.dock-btn[data-action]')) : [];
      const dockSaveButton=dock ? dock.querySelector('.dock-btn[data-action="save"]') : null;
      const dockSaveLabel=dockSaveButton ? dockSaveButton.querySelector('.label') : null;
      const dockUndoButton=dock ? dock.querySelector('.dock-btn[data-action="undo"]') : null;
      const dockRedoButton=dock ? dock.querySelector('.dock-btn[data-action="redo"]') : null;
      const dockFoldButton=dock ? dock.querySelector('.dock-btn[data-action="fold"]') : null;
      const dockFoldLabel=dockFoldButton ? dockFoldButton.querySelector('[data-fold-label]') : null;
      const dockFoldIcon=dockFoldButton ? dockFoldButton.querySelector('[data-fold-icon]') : null;
      const exportOverlay=document.getElementById('mind-export-overlay');
      const mapIo=document.getElementById('map-io');
      const mapIoButton=document.getElementById('map-io-button');
      const mapIoMenu=document.getElementById('map-io-menu');
      const mapDeleteButton=document.getElementById('map-delete-btn');
      const importModal=document.getElementById('mind-import-modal');
      const importModalName=importModal ? importModal.querySelector('[data-import-name]') : null;
      const importModeButtons=importModal ? Array.from(importModal.querySelectorAll('[data-import-mode]')) : [];
      const importCancelButton=importModal ? importModal.querySelector('[data-import-cancel]') : null;
      const relationToast=document.getElementById('mind-relation-toast');
      let pendingImportPayload=null;
      let pendingImportFileName='';
      const foldAllMenuItem=null;
      const nodePopover=document.getElementById('node-popover');
      const sheetHandle=nodePopover ? nodePopover.querySelector('.sheet-handle') : null;
      const popoverHeader=nodePopover ? nodePopover.querySelector('header') : null;
      const nodeContextMenu=document.getElementById('node-context-menu');
      const settingsLayer=document.getElementById('mind-settings');
      const gridToggle=document.getElementById('setting-grid');
      const fisheyeToggle=document.getElementById('setting-fisheye');
      const pointerMedia=window.matchMedia ? window.matchMedia('(pointer: coarse)') : null;
      let pointerIsCoarse=pointerMedia ? pointerMedia.matches : false;
      let exportOverlayHideTimer=null;
      if(mapDeleteButton){ mapDeleteButton.disabled=!currentMapId; }
      if(pointerIsCoarse && fisheyeToggle){ fisheyeToggle.checked=false; }
      let infoBarCollapsed=false;
      function applyInfoBarState(){
        if(!mindInfoBar) return;
        mindInfoBar.dataset.collapsed=infoBarCollapsed?'true':'false';
        if(mindInfoHandle){
          mindInfoHandle.setAttribute('aria-expanded', infoBarCollapsed?'false':'true');
          mindInfoHandle.setAttribute('aria-label', infoBarCollapsed?'展开顶部栏':'收起顶部栏');
        }
        if(mindInfoHandleIcon){
          mindInfoHandleIcon.textContent=infoBarCollapsed ? '∨' : '∧';
        }
        if(mindInfoContent){
          mindInfoContent.setAttribute('aria-hidden', infoBarCollapsed?'true':'false');
        }
      }
      function collapseInfoBar(force=false){
        if(!mindInfoBar || infoBarCollapsed) return;
        if(!force && document.activeElement && mindInfoBar.contains(document.activeElement)){ return; }
        infoBarCollapsed=true;
        applyInfoBarState();
      }
      function expandInfoBar(){
        if(!mindInfoBar || !infoBarCollapsed) return;
        infoBarCollapsed=false;
        applyInfoBarState();
      }
      function toggleInfoBar(){
        if(infoBarCollapsed){ expandInfoBar(); }
        else{ collapseInfoBar(true); }
      }
      applyInfoBarState();
      if(mindInfoHandle){
        mindInfoHandle.addEventListener('click',e=>{
          e.preventDefault();
          e.stopPropagation();
          toggleInfoBar();
        });
      }
      if(mindInfoBar){
        mindInfoBar.addEventListener('focusin',()=>{ expandInfoBar(); });
      }
      function showExportOverlay(){
        if(!exportOverlay) return;
        if(exportOverlayHideTimer){
          clearTimeout(exportOverlayHideTimer);
          exportOverlayHideTimer=null;
        }
        exportOverlay.dataset.active='true';
        exportOverlay.setAttribute('aria-hidden','false');
      }
      function hideExportOverlay(){
        if(!exportOverlay) return;
        exportOverlay.dataset.active='false';
        if(exportOverlayHideTimer){
          clearTimeout(exportOverlayHideTimer);
        }
        exportOverlayHideTimer=setTimeout(()=>{
          exportOverlay.setAttribute('aria-hidden','true');
          exportOverlayHideTimer=null;
        },360);
      }
      function isFisheyeEnabled(){
        return !pointerIsCoarse && (!fisheyeToggle || fisheyeToggle.checked);
      }
      function syncFisheyeState(){
        const enabled=isFisheyeEnabled();
        if(mindShell){ mindShell.dataset.fisheye=enabled?'on':'off'; }
        if(!enabled && dockButtons.length){ dockButtons.forEach(btn=>btn.style.transform=''); }
      }
      function handlePointerPrecisionChange(event){
        pointerIsCoarse=event ? !!event.matches : pointerIsCoarse;
        if(pointerIsCoarse && fisheyeToggle){ fisheyeToggle.checked=false; }
        syncFisheyeState();
      }
      syncFisheyeState();
      if(pointerMedia){
        if(pointerMedia.addEventListener) pointerMedia.addEventListener('change',handlePointerPrecisionChange);
        else if(pointerMedia.addListener) pointerMedia.addListener(handlePointerPrecisionChange);
      }
      const UNDO_MAX_DEPTH=100;
      const UNDO_MERGE_WINDOW=200;
      class MindUndoManager{
        constructor(options={}){
          this.maxDepth=typeof options.maxDepth==='number' && options.maxDepth>0 ? options.maxDepth : UNDO_MAX_DEPTH;
          this.stack=[];
          this.redoStack=[];
          this.isRestoring=false;
        }
        clear(){ this.stack.length=0; this.redoStack.length=0; }
        canUndo(){ return this.stack.length>0; }
        canRedo(){ return this.redoStack.length>0; }
        push(entry){
          if(!entry) return;
          const now=entry.timestamp || Date.now();
          const prev=this.stack[this.stack.length-1];
          if(prev && entry.mergeKey && prev.mergeKey===entry.mergeKey && (now - prev.timestamp) <= UNDO_MERGE_WINDOW){
            prev.after=entry.after;
            prev.afterSerialized=entry.afterSerialized;
            prev.timestamp=now;
            return;
          }
          this.stack.push({
            label:entry.label || '',
            before:entry.before,
            after:entry.after,
            beforeSerialized:entry.beforeSerialized,
            afterSerialized:entry.afterSerialized,
            timestamp:now,
            mergeKey:entry.mergeKey || null,
          });
          if(this.stack.length>this.maxDepth){ this.stack.shift(); }
          this.redoStack.length=0;
        }
        pushExisting(entry){
          if(!entry) return;
          this.stack.push(entry);
          if(this.stack.length>this.maxDepth){ this.stack.shift(); }
        }
        popUndoEntry(){ return this.stack.pop() || null; }
        pushRedo(entry){ if(entry){ this.redoStack.push(entry); if(this.redoStack.length>this.maxDepth){ this.redoStack.shift(); } } }
        popRedoEntry(){ return this.redoStack.pop() || null; }
      }
      const undoManager=new MindUndoManager({maxDepth:UNDO_MAX_DEPTH});
      function snapshotSignature(snapshot){
        if(!snapshot || !snapshot.tree) return '';
        try{ return JSON.stringify(snapshot.tree); }
        catch(_){ return ''; }
      }
      function captureMindSnapshot(){
        if(!jm || typeof jm.get_data!=='function') return null;
        let data=null;
        try{ data=jm.get_data('node_tree'); }
        catch(err){ console.warn(err); return null; }
        if(!data) return null;
        const selected=typeof jm.get_selected_node==='function' ? jm.get_selected_node() : null;
        return {
          tree:deepClone(data),
          selectedId:selected && selected.id ? selected.id : null,
          view:{
            offsetX:Number.isFinite(jm.offsetX)?jm.offsetX:null,
            offsetY:Number.isFinite(jm.offsetY)?jm.offsetY:null,
            scale:Number.isFinite(jm.scale)?jm.scale:null,
          }
        };
      }
      function applyViewState(view){
        if(!jm || !view) return;
        if(typeof view.scale==='number' && isFinite(view.scale)){ jm.scale=view.scale; }
        if(typeof view.offsetX==='number' && isFinite(view.offsetX)){ jm.offsetX=view.offsetX; }
        if(typeof view.offsetY==='number' && isFinite(view.offsetY)){ jm.offsetY=view.offsetY; }
        if(typeof jm.applyTransform==='function'){ jm.applyTransform(); }
      }
      function afterMindStateChange(){
        if(typeof cancelRelationMode==='function'){ cancelRelationMode(false); }
        scheduleHandleRefresh();
        requestAnimationFrame(()=>refreshInspector(jm.get_selected_node()));
        updateFoldAllLabel();
        updateFoldButtonState();
        if(typeof updateHandlePosition==='function'){ updateHandlePosition(); }
        if(typeof syncOverlaySize==='function'){ syncOverlaySize(); }
      }
      function updateFoldButtonState(){
        if(!dockFoldButton) return;
        const node=jm && typeof jm.get_selected_node==='function' ? jm.get_selected_node() : null;
        const hasChildren=!!(node && node.children && node.children.length);
        let label='折叠';
        let icon='⇅';
        let tip='折叠/展开（Space 或 ←/→）';
        if(hasChildren){
          const collapsed=node.expanded===false;
          label=collapsed?'展开':'折叠';
          icon=collapsed?'⤴':'⤵';
          tip=collapsed?'展开（Space 或 →）':'折叠（Space 或 ←）';
        }
        if(dockFoldLabel){ dockFoldLabel.textContent=label; }
        if(dockFoldIcon){ dockFoldIcon.textContent=icon; }
        dockFoldButton.dataset.tip=tip;
        dockFoldButton.disabled=!hasChildren;
        dockFoldButton.setAttribute('aria-label', hasChildren ? `${label}节点` : '折叠或展开节点');
      }
      function restoreMindSnapshot(snapshot){
        if(!snapshot || !snapshot.tree) return false;
        if(!jm || typeof jm.show!=='function') return false;
        undoManager.isRestoring=true;
        try{
          jm.show(deepClone(snapshot.tree));
          if(snapshot.view){ applyViewState(snapshot.view); }
          if(snapshot.selectedId && typeof jm.select_node==='function'){
            try{ jm.select_node(snapshot.selectedId); }
            catch(_){ /* ignore */ }
          }
          afterMindStateChange();
          markDirty();
          return true;
        }catch(err){
          console.error(err);
          return false;
        }finally{
          undoManager.isRestoring=false;
        }
      }
      function updateUndoRedoButtons(){
        if(dockUndoButton){ dockUndoButton.disabled=!undoManager.canUndo(); }
        if(dockRedoButton){ dockRedoButton.disabled=!undoManager.canRedo(); }
      }
      function performUndoable(label, action, options={}){
        if(typeof action!=='function') return;
        if(undoManager.isRestoring) return action();
        const before=captureMindSnapshot();
        const result=action();
        if(result instanceof Promise){
          console.warn('performUndoable 不支持异步操作');
          return result;
        }
        if(result===false && !options.force){ return result; }
        const after=captureMindSnapshot();
        if(!before || !after) return result;
        const beforeSerialized=snapshotSignature(before);
        const afterSerialized=snapshotSignature(after);
        if(!beforeSerialized || beforeSerialized===afterSerialized) return result;
        undoManager.push({
          label:label||'',
          before,
          after,
          beforeSerialized,
          afterSerialized,
          mergeKey:options.mergeKey||null,
          timestamp:Date.now(),
        });
        updateUndoRedoButtons();
        return result;
      }
      function undoMindChange(){
        if(!undoManager.canUndo()) return false;
        const entry=undoManager.popUndoEntry();
        if(!entry) return false;
        const success=restoreMindSnapshot(entry.before);
        if(success){
          undoManager.pushRedo(entry);
        }else{
          undoManager.pushExisting(entry);
        }
        updateUndoRedoButtons();
        return success;
      }
      function redoMindChange(){
        if(!undoManager.canRedo()) return false;
        const entry=undoManager.popRedoEntry();
        if(!entry) return false;
        const success=restoreMindSnapshot(entry.after);
        if(success){
          entry.timestamp=Date.now();
          undoManager.pushExisting(entry);
        }else{
          undoManager.pushRedo(entry);
        }
        updateUndoRedoButtons();
        return success;
      }
      let panAnimation=null;
      function stopPanAnimation(){
        if(panAnimation && panAnimation.raf){ cancelAnimationFrame(panAnimation.raf); }
        panAnimation=null;
      }
      function centerOnNodeSmooth(node){
        if(!jm || !node || typeof jm.center_node!=='function') return false;
        const startX=Number.isFinite(jm.offsetX)?jm.offsetX:0;
        const startY=Number.isFinite(jm.offsetY)?jm.offsetY:0;
        const startScale=jm.scale;
        const prevHasCentered=jm.hasCentered;
        const centered=jm.center_node(node);
        if(!centered){ return false; }
        const targetX=Number.isFinite(jm.offsetX)?jm.offsetX:startX;
        const targetY=Number.isFinite(jm.offsetY)?jm.offsetY:startY;
        jm.offsetX=startX;
        jm.offsetY=startY;
        jm.scale=startScale;
        jm.hasCentered=prevHasCentered;
        if(typeof jm.applyTransform==='function'){ jm.applyTransform(); }
        stopPanAnimation();
        if(Math.abs(targetX-startX)<0.5 && Math.abs(targetY-startY)<0.5){
          jm.offsetX=targetX;
          jm.offsetY=targetY;
          if(typeof jm.applyTransform==='function'){ jm.applyTransform(); }
          return true;
        }
        const duration=260;
        const startTime=performance.now();
        const ease=t=>t<0.5?2*t*t:1-Math.pow(-2*t+2,2)/2;
        function step(now){
          const elapsed=now-startTime;
          const progress=Math.min(1, elapsed/duration);
          const eased=ease(progress);
          jm.offsetX=startX + (targetX-startX)*eased;
          jm.offsetY=startY + (targetY-startY)*eased;
          if(typeof jm.applyTransform==='function'){ jm.applyTransform(); }
          if(progress<1){ panAnimation={raf:requestAnimationFrame(step)}; }
          else{ panAnimation=null; }
        }
        panAnimation={raf:requestAnimationFrame(step)};
        return true;
      }
      function centerOnNearestVisible(node){
        if(!node) return false;
        let current=node;
        while(current){
          if(centerOnNodeSmooth(current)) return true;
          current=current.parent || null;
        }
        return false;
      }
      const deleteMapForm=document.getElementById('delete-map-form');
      let saveButtonDefault=dockSaveLabel ? (dockSaveButton?.dataset.defaultLabel || dockSaveLabel.textContent || '保存') : '保存';
      if(dockSaveButton && !dockSaveButton.dataset.defaultLabel){ dockSaveButton.dataset.defaultLabel=saveButtonDefault; }
      let dirty=false;
      let relationMode=null;
      let relationToastTimer=null;
      let nodeClipboardTemplate=null;
      let contextMenuState=null;
      const ATTACH_MAX_BYTES=15*1024*1024;
      const imageExts=['.png','.jpg','.jpeg','.gif','.webp','.bmp','.svg','.avif','.heic','.heif'];
      const textExts=['.txt','.md','.markdown','.csv','.json','.yaml','.yml','.log','.tsv'];
      const videoExts=['.mp4','.mov','.mkv','.avi','.webm','.m4v','.ogv'];
      const audioExts=['.mp3','.m4a','.aac','.wav','.ogg','.oga','.opus','.flac','.weba'];
      const officeExts=['.pdf','.doc','.docx','.docm','.dotx','.dotm','.xls','.xlsx','.xlsm','.xlsb','.xltx','.xltm'];
      const archiveExts=['.zip','.rar'];
      function setSaveButtonState(text, disabled, state){
        if(dockSaveLabel){
          if(typeof text==='string'){ dockSaveLabel.textContent=text; }
          else if(text===null){ dockSaveLabel.textContent=saveButtonDefault; }
        }
        if(dockSaveButton && typeof disabled==='boolean'){ dockSaveButton.disabled=disabled; }
        if(dockSaveButton){
          if(state){ dockSaveButton.dataset.state=state; }
          else{ dockSaveButton.removeAttribute('data-state'); }
        }
      }
      function markDirty(){
        dirty=true;
        if(saveState){
          saveState.textContent='未保存';
          saveState.classList.add('show','dirty');
        }
        setSaveButtonState('未保存',false,'dirty');
      }
      function showSaving(){
        if(saveState){
          saveState.textContent='保存中...';
          saveState.classList.add('show');
          saveState.classList.remove('dirty');
        }
        setSaveButtonState('保存中...', true,'saving');
      }
      function markSaved(){
        dirty=false;
        if(saveState){
          saveState.textContent='保存成功';
          saveState.classList.add('show');
          saveState.classList.remove('dirty');
        }
        setSaveButtonState('保存成功', false,'saved');
        setTimeout(()=>{
          if(!dirty){
            if(saveState) saveState.classList.remove('show');
            setSaveButtonState(null,false,null);
          }
        },1500);
      }
      function clearRelationHighlights(){
        document.querySelectorAll('.jsmind-node.relation-source,.jsmind-node.relation-target').forEach(el=>{
          el.classList.remove('relation-source','relation-target');
        });
      }
      function showRelationToast(message, sticky=false){
        if(!relationToast) return;
        relationToast.textContent=message || '';
        relationToast.dataset.visible='true';
        if(relationToastTimer){ clearTimeout(relationToastTimer); relationToastTimer=null; }
        if(!sticky){
          relationToastTimer=window.setTimeout(()=>{ relationToast.dataset.visible='false'; relationToastTimer=null; },2600);
        }
      }
      function hideRelationToast(){
        if(relationToast){ relationToast.dataset.visible='false'; }
        if(relationToastTimer){ clearTimeout(relationToastTimer); relationToastTimer=null; }
      }
      function startRelationMode(){
        const node=ensureNode();
        if(!node){ alert('请先选择一个节点'); return false; }
        commitInlineEditing();
        relationMode={from:node.id};
        hideHandle();
        clearRelationHighlights();
        const el=document.querySelector(`.jsmind-node[nodeid="${node.id}"]`);
        if(el){ el.classList.add('relation-source'); }
        if(mindShell){ mindShell.dataset.relationMode='pending'; }
        showRelationToast('点击另一个节点以建立关联，或按 Esc 取消', true);
        return true;
      }
      function cancelRelationMode(notify=false){
        if(!relationMode) return;
        relationMode=null;
        if(mindShell){ mindShell.removeAttribute('data-relation-mode'); }
        clearRelationHighlights();
        if(notify){ showRelationToast('已取消关联'); }
        else{ hideRelationToast(); }
        scheduleHandleRefresh();
      }
      function updateRelationHover(nodeId){
        if(!relationMode) return;
        const targetId=(nodeId && nodeId!==relationMode.from)?nodeId:null;
        document.querySelectorAll('.jsmind-node.relation-target').forEach(el=>el.classList.remove('relation-target'));
        if(targetId){
          const el=document.querySelector(`.jsmind-node[nodeid="${targetId}"]`);
          if(el){ el.classList.add('relation-target'); }
        }
      }
      function completeRelationMode(targetNode){
        if(!relationMode || !targetNode) return;
        const sourceId=relationMode.from;
        if(targetNode.id===sourceId){ showRelationToast('请选择不同的节点'); return; }
        const options=relationMode.options || {};
        const existing=typeof jm.get_relations==='function' ? jm.get_relations(sourceId) : [];
        const duplicate=existing.some(rel=>rel && ((rel.from===sourceId && rel.to===targetNode.id) || (rel.bidirectional && rel.from===targetNode.id && rel.to===sourceId)));
        cancelRelationMode(false);
        if(duplicate){ showRelationToast('已存在关联'); return; }
        if(typeof jm.add_relation==='function'){
          performUndoable('add-relation',()=>{
            const relation=jm.add_relation(sourceId, targetNode.id, options);
            if(!relation) return false;
            markDirty();
            scheduleHandleRefresh();
            requestAnimationFrame(()=>refreshInspector(jm.get_selected_node()));
            showRelationToast('关联已创建');
            return true;
          },{mergeKey:`relation:add:${sourceId}`});
        }
      }
      function toggleRelationMode(){
        if(relationMode){ cancelRelationMode(false); }
        else{ startRelationMode(); }
      }
      function toggleSettings(forceShow){
        if(!settingsLayer) return;
        const currentHidden=settingsLayer.getAttribute('aria-hidden')!=='false';
        const willShow=typeof forceShow==='boolean' ? forceShow : currentHidden;
        settingsLayer.setAttribute('aria-hidden', willShow ? 'false' : 'true');
      }
      if(settingsLayer){
        settingsLayer.addEventListener('click',e=>{
          if(e.target===settingsLayer){ toggleSettings(false); }
        });
        settingsLayer.querySelectorAll('[data-settings-close]').forEach(btn=>{
          btn.addEventListener('click',()=>toggleSettings(false));
        });
      }
      const inspectorFields=[nodeTopicInput,nodeNoteInput,nodeFoldToggle].filter(Boolean);
      let inspectorSyncing=false;
      function setInspectorEnabled(enabled){
        inspectorFields.forEach(el=>{ el.disabled=!enabled; });
        if(inspector){ inspector.classList.toggle('disabled', !enabled); }
      }
      function updateFoldToggleUI(node){
        if(!nodeFoldField || !nodeFoldToggle || !nodeFoldToggleText) return;
        const hasChildren=!!(node && node.children && node.children.length);
        nodeFoldField.hidden=!hasChildren;
        if(!hasChildren){
          nodeFoldToggle.checked=true;
          if(nodeFoldToggleText) nodeFoldToggleText.textContent='展开中';
          if(nodeFoldHint) nodeFoldHint.textContent='';
          return;
        }
        const count=node.children ? node.children.filter(Boolean).length : 0;
        const expanded=node.expanded!==false;
        nodeFoldToggle.checked=expanded;
        if(nodeFoldToggleText) nodeFoldToggleText.textContent=expanded?'展开中':'已折叠';
        if(nodeFoldHint){
          nodeFoldHint.textContent=expanded
            ? `共有 ${count} 个直接子节点`
            : `已折叠 ${count} 个直接子节点`;
        }
      }
      function hasCollapsedNodes(){
        if(typeof jm?.has_collapsed_nodes==='function'){
          try{ return !!jm.has_collapsed_nodes(); }
          catch(_){ /* ignore */ }
        }
        if(!jm || !jm.nodes || typeof jm.nodes.values!=='function') return false;
        for(const node of jm.nodes.values()){
          if(node && node.children && node.children.length && node.expanded===false){
            return true;
          }
        }
        return false;
      }
      function updateFoldAllLabel(){
        if(!foldAllMenuItem) return;
        foldAllMenuItem.textContent=hasCollapsedNodes() ? '展开全部' : '折叠全部';
      }
      function setAllNodesExpanded(expanded){
        if(!jm) return false;
        if(typeof jm.set_all_expanded==='function'){
          try{ return !!jm.set_all_expanded(expanded); }
          catch(_){ /* ignore */ }
        }
        if(!jm.nodes || typeof jm.nodes.values!=='function') return false;
        const desired=!!expanded;
        let changed=false;
        for(const node of jm.nodes.values()){
          if(!node || node.isroot) continue;
          if(!node.children || !node.children.length) continue;
          if(node.expanded===desired) continue;
          node.expanded=desired;
          if(node.model){ node.model.expanded=desired; }
          changed=true;
        }
        if(changed){
          jm.computeLayout();
          jm.render();
          if(typeof jm.emit==='function' && jsMind?.event_type?.refresh){
            jm.emit(jsMind.event_type.refresh);
          }
        }
        return changed;
      }
      function toggleFoldAll(){
        const target=hasCollapsedNodes() ? true : false;
        performUndoable('toggle-all',()=>{
          const changed=setAllNodesExpanded(target);
          if(changed){
            markDirty();
            scheduleHandleRefresh();
            requestAnimationFrame(()=>refreshInspector(jm.get_selected_node()));
          }
          updateFoldAllLabel();
          updateFoldButtonState();
          return changed;
        },{mergeKey:'fold:all'});
      }
      function setNodeExpandedState(node, expanded, options={}){
        if(!node) return;
        const mergeKey=`fold:${node.id}`;
        performUndoable('toggle-node',()=>{
          let changed=false;
          if(typeof jm.set_node_expanded==='function'){
            try{ changed=!!jm.set_node_expanded(node.id, expanded); }
            catch(_){ changed=false; }
          }
          if(!changed && typeof jm.set_node_expanded!=='function'){
            const desired=expanded!==false;
            if(node.expanded!==desired){
              node.expanded=desired;
              if(node.model){ node.model.expanded=desired; }
              jm.computeLayout();
              jm.render();
              if(typeof jm.emit==='function' && jsMind?.event_type?.refresh){
                jm.emit(jsMind.event_type.refresh);
              }
              changed=true;
            }
          }
          if(changed){
            markDirty();
            scheduleHandleRefresh();
            requestAnimationFrame(()=>{
              const latest=jm.get_node(node.id);
              if(latest){
                refreshInspector(latest);
                if(options.focus!==false){ centerOnNearestVisible(latest); }
              }
            });
          }else{
            updateFoldToggleUI(jm.get_node(node.id));
          }
          updateFoldAllLabel();
          updateFoldButtonState();
          return changed;
        },{mergeKey});
      }
      let popoverOpen=false;
      let sheetDragState=null;
      let longPressState=null;
      const popoverMedia=window.matchMedia('(max-width: 768px)');
      function updatePopoverMode(){
        if(!nodePopover) return;
        nodePopover.dataset.mode = popoverMedia.matches ? 'sheet' : 'panel';
        nodePopover.classList.remove('dragging');
        if(!sheetDragState){ nodePopover.style.transform=''; }
        if(popoverOpen){ positionInspectorPopover(jm.get_selected_node()); }
      }
      updatePopoverMode();
      if(popoverMedia.addEventListener) popoverMedia.addEventListener('change',()=>updatePopoverMode());
      else if(popoverMedia.addListener) popoverMedia.addListener(()=>updatePopoverMode());
      function isContextMenuOpen(){ return !!(nodeContextMenu && !nodeContextMenu.hidden); }
      function closeNodeContextMenu(){
        if(!nodeContextMenu) return;
        nodeContextMenu.hidden=true;
        nodeContextMenu.removeAttribute('style');
        nodeContextMenu.removeAttribute('data-mode');
        contextMenuState=null;
      }
      function openNodeContextMenu(node, anchor){
        if(!nodeContextMenu || !node) return;
        try{ jm.select_node(node.id); }catch(_){ }
        contextMenuState={nodeId:node.id};
        nodeContextMenu.hidden=false;
        const mode=popoverMedia.matches ? 'sheet' : 'menu';
        nodeContextMenu.dataset.mode=mode;
        if(mode==='sheet'){
          nodeContextMenu.style.left='';
          nodeContextMenu.style.top='';
          return;
        }
        nodeContextMenu.style.left='';
        nodeContextMenu.style.top='';
        requestAnimationFrame(()=>{
          const rect=nodeContextMenu.getBoundingClientRect();
          const margin=12;
          const fallback=node.el ? node.el.getBoundingClientRect() : null;
          const baseX=anchor && typeof anchor.x==='number' ? anchor.x : (fallback ? fallback.right : window.innerWidth/2);
          const baseY=anchor && typeof anchor.y==='number' ? anchor.y : (fallback ? fallback.top : window.innerHeight/2);
          let left=baseX - rect.width/2;
          let top=baseY;
          if(left < margin) left=margin;
          if(left + rect.width > window.innerWidth - margin){ left = window.innerWidth - rect.width - margin; }
          if(top < margin) top=margin;
          if(top + rect.height > window.innerHeight - margin){ top = window.innerHeight - rect.height - margin; }
          nodeContextMenu.style.left=`${Math.round(left)}px`;
          nodeContextMenu.style.top=`${Math.round(top)}px`;
        });
      }
      function isPopoverOpen(){ return popoverOpen; }
      function openInspectorPopover(node){
        if(!nodePopover || !node) return;
        closeNodeContextMenu();
        popoverOpen=true;
        updatePopoverMode();
        nodePopover.hidden=false;
        nodePopover.classList.remove('dragging');
        nodePopover.style.transform='';
        refreshInspector(node);
        if(nodeTopicInput){
          requestAnimationFrame(()=>{
            try{ nodeTopicInput.focus({preventScroll:true}); }
            catch(_){ nodeTopicInput.focus(); }
          });
        }
      }
      function closeInspectorPopover(){
        if(!nodePopover) return;
        popoverOpen=false;
        nodePopover.hidden=true;
        nodePopover.classList.remove('dragging');
        nodePopover.style.transform='';
        if(sheetDragState && sheetDragState.pointerId!=null && nodePopover.releasePointerCapture){
          try{ nodePopover.releasePointerCapture(sheetDragState.pointerId); }catch(_){ }
        }
        sheetDragState=null;
      }
      function positionInspectorPopover(node){
        if(!nodePopover) return;
        if(nodePopover.dataset.mode==='sheet'){ nodePopover.style.left=''; nodePopover.style.top=''; return; }
        const el=node ? document.querySelector(`.jsmind-node[nodeid="${node.id}"]`) : null;
        const rect=el ? el.getBoundingClientRect() : null;
        const popRect=nodePopover.getBoundingClientRect();
        const margin=24;
        let left=rect ? rect.right + 16 : margin;
        let top=rect ? rect.top : margin;
        if(left + popRect.width > window.innerWidth - margin){ left = window.innerWidth - popRect.width - margin; }
        if(top + popRect.height > window.innerHeight - margin){ top = window.innerHeight - popRect.height - margin; }
        nodePopover.style.left=`${Math.max(margin,left)}px`;
        nodePopover.style.top=`${Math.max(margin,top)}px`;
      }
      if(nodePopover){
        nodePopover.addEventListener('click',e=>{
          if(e.target.closest('[data-pop-close]')){ e.preventDefault(); closeInspectorPopover(); }
          if(e.target.closest('[data-pop-save]')){ e.preventDefault(); commitInlineEditing(); closeInspectorPopover(); }
        });
      }
      if(relationList){
        relationList.addEventListener('click',e=>{
          const btn=e.target.closest('button[data-rel-id]');
          if(!btn) return;
          const relId=btn.dataset.relId;
          if(!relId) return;
          if(!confirm('确定移除该关联吗？')) return;
          if(typeof jm.remove_relation==='function'){
            performUndoable('remove-relation',()=>{
              if(!jm.remove_relation(relId)) return false;
              markDirty();
              scheduleHandleRefresh();
              refreshInspector(jm.get_selected_node());
              showRelationToast('关联已移除');
              return true;
            },{mergeKey:`relation:remove:${relId}`});
          }
        });
      }
      const startSheetDrag=(e)=>{
        if(!nodePopover || nodePopover.dataset.mode!=='sheet') return;
        if(e.pointerType==='mouse' && e.button!==0) return;
        const isHandle=sheetHandle && sheetHandle.contains(e.target);
        const isHeaderDrag=popoverHeader && popoverHeader.contains(e.target) && !e.target.closest('button, [data-pop-close], [data-pop-save]');
        if(!isHandle && !isHeaderDrag) return;
        sheetDragState={pointerId:e.pointerId,startY:e.clientY,translate:0};
        nodePopover.classList.add('dragging');
        try{ nodePopover.setPointerCapture(e.pointerId); }catch(_){ }
        e.preventDefault();
      };
      const moveSheetDrag=(e)=>{
        if(!sheetDragState || !nodePopover || e.pointerId!==sheetDragState.pointerId) return;
        const delta=Math.max(0, e.clientY - sheetDragState.startY);
        sheetDragState.translate=delta;
        nodePopover.style.transform=`translateX(-50%) translateY(${delta}px)`;
      };
      const endSheetDrag=(e,cancelled=false)=>{
        if(!sheetDragState || !nodePopover || e.pointerId!==sheetDragState.pointerId) return;
        const delta=sheetDragState.translate || 0;
        const pointerId=sheetDragState.pointerId;
        sheetDragState=null;
        if(nodePopover.releasePointerCapture){ try{ nodePopover.releasePointerCapture(pointerId); }catch(_){ } }
        if(!cancelled && delta>120){
          closeInspectorPopover();
        }else{
          nodePopover.classList.remove('dragging');
          nodePopover.style.transform='';
        }
      };
      if(sheetHandle){ sheetHandle.addEventListener('pointerdown', startSheetDrag); }
      if(popoverHeader){ popoverHeader.addEventListener('pointerdown', startSheetDrag); }
      if(nodePopover){
        nodePopover.addEventListener('pointermove', moveSheetDrag);
        nodePopover.addEventListener('pointerup',e=>endSheetDrag(e,false));
        nodePopover.addEventListener('pointercancel',e=>endSheetDrag(e,true));
      }
      document.addEventListener('pointerdown',e=>{
        if(!popoverOpen || !nodePopover) return;
        if(nodePopover.contains(e.target)) return;
        if(e.target.closest && e.target.closest('.jsmind-node')) return;
        closeInspectorPopover();
      });
      document.addEventListener('keydown',e=>{
        if(e.key==='Escape'){
          if(relationMode){ cancelRelationMode(true); return; }
          if(popoverOpen){ closeInspectorPopover(); }
          if(isContextMenuOpen()){ closeNodeContextMenu(); }
        }
      });
      document.addEventListener('pointerdown',e=>{
        if(!isContextMenuOpen() || !nodeContextMenu) return;
        if(nodeContextMenu.contains(e.target)) return;
        closeNodeContextMenu();
      });
      if(nodeContextMenu){
        nodeContextMenu.addEventListener('click',e=>{
          const btn=e.target.closest('button[data-menu-action]');
          if(!btn) return;
          const action=btn.dataset.menuAction;
          if(action==='edit'){
            const nodeId=contextMenuState?.nodeId;
            const node=nodeId ? jm.get_node(nodeId) : ensureNode();
            closeNodeContextMenu();
            if(node){ openInspectorPopover(node); }
          }
        });
      }
      window.addEventListener('resize',closeNodeContextMenu);
      const cancelLongPressState=()=>{
        if(longPressState && longPressState.timer){ clearTimeout(longPressState.timer); }
        longPressState=null;
      };
      if(jmContainer){
        jmContainer.addEventListener('pointerdown',e=>{
          const nodeEl=e.target.closest('.jsmind-node');
          if(!nodeEl){ cancelLongPressState(); return; }
          if(e.pointerType && !['touch','pen'].includes(e.pointerType)){ cancelLongPressState(); return; }
          const nodeId=nodeEl.getAttribute('nodeid');
          if(!nodeId){ cancelLongPressState(); return; }
          cancelLongPressState();
          longPressState={
            pointerId:e.pointerId,
            nodeId,
            startX:e.clientX,
            startY:e.clientY,
            triggered:false,
            timer:window.setTimeout(()=>{
              if(!longPressState || longPressState.pointerId!==e.pointerId) return;
              longPressState.triggered=true;
              longPressState.timer=null;
              const node=jm.get_node(longPressState.nodeId);
              if(node){ openNodeContextMenu(node,{x:longPressState.startX,y:longPressState.startY}); }
            }, LONG_PRESS_DELAY)
          };
        });
        jmContainer.addEventListener('pointermove',e=>{
          if(relationMode){
            const nodeEl=e.target.closest('.jsmind-node');
            const nodeId=nodeEl ? nodeEl.getAttribute('nodeid') : null;
            updateRelationHover(nodeId);
          }
          if(!longPressState || e.pointerId!==longPressState.pointerId) return;
          if(longPressState.triggered) return;
          const dx=Math.abs(e.clientX-longPressState.startX);
          const dy=Math.abs(e.clientY-longPressState.startY);
          if(dx>LONG_PRESS_TOLERANCE || dy>LONG_PRESS_TOLERANCE){ cancelLongPressState(); }
        });
        jmContainer.addEventListener('pointerleave',()=>{ if(relationMode){ updateRelationHover(null); } });
        const finishLongPress=(e)=>{
          if(!longPressState || e.pointerId!==longPressState.pointerId) return;
          const triggered=!!longPressState.triggered;
          cancelLongPressState();
          if(triggered){ e.preventDefault(); e.stopPropagation(); }
        };
        jmContainer.addEventListener('pointerup',finishLongPress);
        jmContainer.addEventListener('pointercancel',finishLongPress);
        jmContainer.addEventListener('click',e=>{
          if(!relationMode) return;
          const nodeEl=e.target.closest('.jsmind-node');
          if(!nodeEl) return;
          const nodeId=nodeEl.getAttribute('nodeid');
          if(!nodeId) return;
          const node=jm.get_node(nodeId);
          if(node){ completeRelationMode(node); }
        });
        jmContainer.addEventListener('contextmenu',e=>{
          const nodeEl=e.target.closest('.jsmind-node');
          if(!nodeEl) return;
          e.preventDefault();
          cancelLongPressState();
          const node=jm.get_node(nodeEl.getAttribute('nodeid'));
          if(node){ openNodeContextMenu(node,{x:e.clientX,y:e.clientY}); }
        });
      }
      function refreshInspector(node){
        inspectorSyncing=true;
        if(relationList){ relationList.innerHTML=''; }
        if(relationField){ relationField.classList.add('empty'); }
        if(!node){
          setInspectorEnabled(false);
          if(nodeTopicInput) nodeTopicInput.value='';
          if(nodeNoteInput) nodeNoteInput.value='';
          updateFoldToggleUI(null);
          updateFoldAllLabel();
          inspectorSyncing=false;
          if(popoverOpen){ positionInspectorPopover(null); }
          return;
        }
        setInspectorEnabled(true);
        if(nodeTopicInput) nodeTopicInput.value=node.topic || '';
        const data=normalizeNodeData(deepClone(node.data||{}));
        if(nodeNoteInput) nodeNoteInput.value=data.note || '';
        updateFoldToggleUI(node);
        updateFoldAllLabel();
        if(relationList){
          const relations=typeof jm.get_relations==='function' ? jm.get_relations(node.id) : [];
          if(relations.length){
            if(relationField){ relationField.classList.remove('empty'); }
            relations.forEach(rel=>{
              if(!rel) return;
              const partnerId=rel.from===node.id ? rel.to : rel.from;
              const partner=jm.get_node(partnerId);
              const name=(partner && partner.topic) ? partner.topic : partnerId;
              const direction=rel.bidirectional ? '↔' : (rel.from===node.id ? '↦' : '↤');
              const pill=document.createElement('span');
              pill.className='relation-pill';
              const labelSpan=document.createElement('span');
              labelSpan.textContent=`${direction} ${name}`;
              if(rel.label){ labelSpan.title=rel.label; }
              pill.appendChild(labelSpan);
              const remove=document.createElement('button');
              remove.type='button';
              remove.dataset.relId=rel.id;
              remove.setAttribute('aria-label',`移除与 ${name} 的关联`);
              remove.textContent='×';
              pill.appendChild(remove);
              relationList.appendChild(pill);
            });
          }
        }
        inspectorSyncing=false;
        if(popoverOpen){ positionInspectorPopover(node); }
      }
      refreshInspector(jm.get_selected_node());
      if(jm.options){ jm.options.onNodeDetails=openInspectorPopover; }
      updateFoldButtonState();
      updateUndoRedoButtons();
      function applyInspectorChange(mutator){
        if(typeof mutator!=='function') return;
        const node=ensureNode();
        if(!node) return;
        performUndoable('update-node-data',()=>{
          commitInlineEditing();
          const data=ensureNodeDataObject(node);
          mutator(data,node);
          node.model.data=data;
          node.data=data;
          jm.computeLayout();
          jm.render();
          jm.select_node(node.id);
          markDirty();
          requestAnimationFrame(()=>{
            const latest=jm.get_node(node.id);
            if(latest){
              updateHandlePosition();
              refreshInspector(latest);
            }
          });
          return true;
        },{mergeKey:`data:${node.id}`});
      }
      function deepClone(obj){ return obj ? JSON.parse(JSON.stringify(obj)) : null; }
      function ensureNode(){
        let node=jm.get_selected_node();
        if(node) return node;
        if(typeof jm.get_root === 'function'){ node=jm.get_root(); }
        if(!node && initialData && initialData.data){ jm.select_node(initialData.data.id); node=jm.get_selected_node(); }
        if(node && !node.selected){ jm.select_node(node.id); node=jm.get_selected_node(); }
        return node;
      }
      function ensureNodeDataObject(node){
        if(!node) return null;
        const current=node.model && node.model.data ? node.model.data : {};
        const normalized=normalizeNodeData(current);
        node.model.data=normalized;
        node.data=normalized;
        return normalized;
      }
      function executeCreateNodeCommand(input){
        const mergeKey=input && input.meta && input.meta.source ? `create:${input.meta.source}` : null;
        return performUndoable('create-node',()=>{
          commitInlineEditing();
          if(!input || !input.parentId) return null;
          const parent=jm.get_node(input.parentId);
          if(!parent) return null;
          const nodeId=input.id || randomId();
          const payloadData=deepClone(input.data)||{};
          const newNode=jm.add_node(parent, nodeId, input.topic || '新节点', payloadData);
          const style=deepClone(input.style);
          if(style && (style.background || style.foreground)){
            jm.set_node_color(newNode.id, style.background || null, style.foreground || null);
          }
          if(input.position){
            newNode.data = newNode.data || {};
            newNode.data.position = {x:input.position.x, y:input.position.y};
          }
          jm.select_node(newNode.id);
          markDirty();
          scheduleHandleRefresh();
          refreshInspector(jm.get_node(newNode.id));
          requestAnimationFrame(()=>{
            const target=jm.get_node(newNode.id);
            if(target){ centerOnNodeSmooth(target); }
          });
          return newNode;
        },{mergeKey});
      }
      function randomId(){ return 'node-' + Math.random().toString(36).slice(2,10); }
      function generateUniqueNodeId(){
        let candidate;
        do { candidate=randomId(); }
        while(jm && jm.nodes && typeof jm.nodes.has==='function' && jm.nodes.has(candidate));
        return candidate;
      }
      function prepareClipboardTemplate(template, options={}){
        if(!template) return null;
        const baseDirection=(options.baseDirection==='left')?'left':'right';
        const normalize=(node, depth)=>{
          if(!node || typeof node!=='object') return null;
          const normalized={
            topic:typeof node.topic==='string'?node.topic:'',
            data:node.data?deepClone(node.data):{},
            style:node.style?deepClone(node.style):null,
            meta:node.meta?deepClone(node.meta):null,
            expanded:node.expanded!==false,
            direction:depth===0?baseDirection:(node.direction==='left' || node.dir===-1?'left':'right'),
            children:[],
          };
          if(Array.isArray(node.children)){
            normalized.children=node.children.map(child=>normalize(child, depth+1)).filter(Boolean);
          }
          return normalized;
        };
        return normalize(template,0);
      }
      function buildNodeFromTemplate(template,parentNode,options={}){
        if(!template || !parentNode) return null;
        const parentChildren=parentNode.children || (parentNode.children=[]);
        const parentModelChildren=(jm && typeof jm.ensureModelChildren==='function')
          ? jm.ensureModelChildren(parentNode)
          : (parentNode.model.children = parentNode.model.children || []);
        let childIndex=typeof options.insertIndex==='number'?options.insertIndex:parentChildren.length;
        if(childIndex<0 || childIndex>parentChildren.length){ childIndex=parentChildren.length; }
        let modelIndex=typeof options.modelIndex==='number'?options.modelIndex:parentModelChildren.length;
        if(modelIndex<0 || modelIndex>parentModelChildren.length){ modelIndex=parentModelChildren.length; }
        const id=generateUniqueNodeId();
        const normalizedData=normalizeNodeData(deepClone(template.data)||{});
        const style=template.style?deepClone(template.style):null;
        const meta=template.meta?deepClone(template.meta):null;
        const direction=template.direction==='left'?'left':'right';
        const expanded=template.expanded!==false;
        const model={
          id,
          topic:template.topic || '新节点',
          data:normalizedData,
          children:[],
          direction,
          expanded,
        };
        if(style){ model.style=deepClone(style); }
        if(meta){ model.meta=deepClone(meta); }
        model.parentId=parentNode.id;
        const node={
          id,
          topic:model.topic,
          data:normalizedData,
          parent:parentNode,
          children:[],
          direction,
          expanded,
          isroot:false,
          style:style,
          meta:meta,
          model:model,
          depth:(parentNode.depth||0)+1,
        };
        node.dir=direction==='left'?-1:1;
        parentChildren.splice(childIndex,0,node);
        parentModelChildren.splice(modelIndex,0,model);
        jm.nodes.set(id,node);
        const childTemplates=Array.isArray(template.children)?template.children:[];
        if(childTemplates.length){
          childTemplates.forEach(childTpl=>{ buildNodeFromTemplate(childTpl, node); });
        }
        return node;
      }
      function copySelectedNode(){
        const node=ensureNode();
        if(!node || !node.model) return false;
        nodeClipboardTemplate=deepClone(node.model);
        return !!nodeClipboardTemplate;
      }
      function pasteNodeAsSibling(){
        if(!nodeClipboardTemplate){
          showRelationToast('请先复制一个节点');
          return null;
        }
        const target=ensureNode();
        if(!target || !target.parent){
          showRelationToast('根节点无法粘贴为同级');
          return null;
        }
        return performUndoable('paste-node',()=>{
          commitInlineEditing();
          const parent=target.parent;
          const baseDirection=(target.direction==='left' || target.dir===-1)?'left':'right';
          const template=prepareClipboardTemplate(nodeClipboardTemplate,{baseDirection});
          if(!template) return null;
          const parentChildren=parent.children || [];
          const insertIndex=parentChildren.indexOf(target)+1;
          const parentModelChildren=(jm && typeof jm.ensureModelChildren==='function')
            ? jm.ensureModelChildren(parent)
            : (parent.model.children = parent.model.children || []);
          const modelIndex=parentModelChildren.findIndex(child=>child && child.id===target.id)+1;
          const created=buildNodeFromTemplate(template,parent,{insertIndex,modelIndex});
          if(!created) return null;
          jm.computeLayout();
          jm.render();
          jm.select_node(created.id);
          if(typeof jm.emit==='function'){ jm.emit(SimpleMind.event_type.update); }
          markDirty();
          scheduleHandleRefresh();
          requestAnimationFrame(()=>{
            const latest=jm.get_node(created.id);
            if(latest){
              refreshInspector(latest);
              updateHandlePosition();
              centerOnNodeSmooth(latest);
            }
            updateFoldAllLabel();
            if(typeof syncOverlaySize==='function'){ syncOverlaySize(); }
          });
          return created;
        },{mergeKey:`paste:${target.parent.id}`});
      }
      function promoteNodeLevel(){
        const node=ensureNode();
        if(!node || !node.parent || node.parent.isroot) return false;
        return performUndoable('promote-node',()=>{
          commitInlineEditing();
          const parent=node.parent;
          const grand=parent.parent;
          if(!grand) return false;
          const parentChildren=parent.children || (parent.children=[]);
          const parentIndex=parentChildren.indexOf(node);
          if(parentIndex!==-1){ parentChildren.splice(parentIndex,1); }
          const parentModelChildren=(jm && typeof jm.ensureModelChildren==='function')
            ? jm.ensureModelChildren(parent)
            : (parent.model.children = parent.model.children || []);
          const modelIdx=parentModelChildren.findIndex(child=>child && child.id===node.id);
          if(modelIdx!==-1){ parentModelChildren.splice(modelIdx,1); }
          const direction=(parent.direction==='left' || parent.dir===-1)?'left':'right';
          node.parent=grand;
          node.direction=direction;
          node.dir=direction==='left'?-1:1;
          if(node.model){
            node.model.direction=direction;
            node.model.parentId=grand.id;
          }
          const grandChildren=grand.children || (grand.children=[]);
          const grandModelChildren=(jm && typeof jm.ensureModelChildren==='function')
            ? jm.ensureModelChildren(grand)
            : (grand.model.children = grand.model.children || []);
          const parentPosition=grandChildren.indexOf(parent);
          const insertIndex=parentPosition===-1?grandChildren.length:parentPosition+1;
          const parentModelPosition=grandModelChildren.findIndex(child=>child && child.id===parent.id);
          const modelInsertIndex=parentModelPosition===-1?grandModelChildren.length:parentModelPosition+1;
          grandChildren.splice(insertIndex,0,node);
          grandModelChildren.splice(modelInsertIndex,0,node.model);
          const updateDepth=(current, depth)=>{
            if(!current) return;
            current.depth=depth;
            if(current.model){ current.model.depth=depth; }
            if(current.children && current.children.length){
              current.children.forEach(child=>updateDepth(child, depth+1));
            }
          };
          updateDepth(node,(grand.depth||0)+1);
          jm.computeLayout();
          jm.render();
          jm.select_node(node.id);
          if(typeof jm.emit==='function'){ jm.emit(SimpleMind.event_type.update); }
          markDirty();
          scheduleHandleRefresh();
          requestAnimationFrame(()=>{
            const latest=jm.get_node(node.id);
            if(latest){
              refreshInspector(latest);
              updateHandlePosition();
              centerOnNodeSmooth(latest);
            }
            updateFoldAllLabel();
            if(typeof syncOverlaySize==='function'){ syncOverlaySize(); }
          });
          return true;
        },{mergeKey:`promote:${node.id}`});
      }
      function toggleBranch(){
        const node=ensureNode();
        if(!node || !node.children || !node.children.length) return false;
        const next=node.expanded===false;
        setNodeExpandedState(node, next);
        return true;
      }
      function expandSelectedNode(){
        const node=ensureNode();
        if(!node || !node.children || !node.children.length) return false;
        if(node.expanded!==false) return false;
        setNodeExpandedState(node,true);
        return true;
      }
      function collapseSelectedNode(){
        const node=ensureNode();
        if(!node || !node.children || !node.children.length) return false;
        if(node.expanded===false) return false;
        setNodeExpandedState(node,false);
        return true;
      }
      function isProbablyUrl(text){
        const value=(text||'').trim();
        return /^https?:\/\//i.test(value) || /^mailto:/i.test(value) || /^ftp:/i.test(value) || /^www\./i.test(value);
      }
      function findNodeElementByEvent(event){
        if(!event) return null;
        if(event.target && event.target.closest){
          const el=event.target.closest('.jsmind-node');
          if(el) return el;
        }
        if(event.composedPath){
          for(const entry of event.composedPath()){
            if(entry && entry.classList && entry.classList.contains && entry.classList.contains('jsmind-node')){ return entry; }
          }
        }
        if(typeof event.clientX==='number' && typeof event.clientY==='number'){
          const hovered=document.elementFromPoint(event.clientX, event.clientY);
          if(hovered && hovered.closest){
            const el=hovered.closest('.jsmind-node');
            if(el) return el;
          }
        }
        return null;
      }
      function resolveDropParent(event){
        const el=findNodeElementByEvent(event);
        if(el){
          const nodeId=el.getAttribute('nodeid');
          if(nodeId){ const node=jm.get_node(nodeId); if(node) return node; }
        }
        const selected=jm.get_selected_node();
        if(selected) return selected;
        return jm.get_root();
      }
      function fileExtension(name){
        if(!name) return '';
        const idx=name.lastIndexOf('.');
        return idx>=0 ? name.slice(idx).toLowerCase() : '';
      }
      function isAllowedAttachment(file){
        const type=(file.type||'').toLowerCase();
        if(type.startsWith('image/')) return true;
        if(type.startsWith('video/')) return true;
        if(type.startsWith('audio/')) return true;
        if(type.startsWith('text/')) return true;
        if(type==='application/json') return true;
        if(['application/pdf','application/zip','application/x-zip-compressed','application/x-rar-compressed','application/vnd.rar','application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/vnd.ms-word.document.macroenabled.12','application/vnd.ms-excel','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','application/vnd.ms-excel.sheet.macroenabled.12','application/vnd.ms-excel.sheet.binary.macroenabled.12'].includes(type)) return true;
        const ext=fileExtension(file.name);
        if(!ext) return false;
        if(imageExts.includes(ext) || textExts.includes(ext) || videoExts.includes(ext) || audioExts.includes(ext) || officeExts.includes(ext) || archiveExts.includes(ext)) return true;
        return false;
      }
      function sanitizeAttachmentFiles(files){
        const limit=Math.min(files.length,5);
        const accepted=[];
        for(let i=0;i<limit;i++){
          const file=files[i];
          if(file.size>ATTACH_MAX_BYTES){
            alert(file.name+' 超过 15MB，已跳过。');
            continue;
          }
          if(!isAllowedAttachment(file)){
            alert(file.name+' 类型不支持，仅可上传图片、音视频、PDF、Word/Excel、ZIP/RAR 或文本文件。');
            continue;
          }
          accepted.push(file);
        }
        return accepted;
      }
      async function attachFilesToNode(targetNode, files){
        if(!targetNode || !files || !files.length) return;
        const accepted=sanitizeAttachmentFiles(files);
        if(!accepted.length) return;
        const data=ensureNodeDataObject(targetNode);
        data.attachments=data.attachments || [];
        for(const file of accepted){
          try{
            const uploaded=await uploadMindmapFile(file, targetNode.id);
            data.attachments.push({
              assetId:uploaded.id,
              name:uploaded.name || file.name,
              size:uploaded.size ?? file.size,
              mime:uploaded.mime || file.type || 'application/octet-stream',
              url:uploaded.url,
              uploadedAt:Date.now()
            });
          }catch(err){
            console.error(err);
            alert((file.name||'文件')+' 上传失败：'+(err && err.message ? err.message : err));
          }
        }
        targetNode.model.data=data;
        targetNode.data=data;
        jm.computeLayout();
        jm.render();
        jm.select_node(targetNode.id);
        markDirty();
        scheduleHandleRefresh();
        refreshInspector(jm.get_node(targetNode.id));
      }
      function handleDroppedText(text, parent, event){
        if(!text || !parent) return;
        commitInlineEditing();
        const cleaned=text.trim();
        if(!cleaned) return;
        const firstLine=cleaned.split(/\r?\n/)[0].slice(0,100) || '新节点';
        const data={};
        if(isProbablyUrl(cleaned)) data.url=cleaned;
        if(cleaned.includes('\n')) data.note=cleaned;
        executeCreateNodeCommand({
          parentId:parent.id,
          topic:firstLine,
          data:Object.keys(data).length?data:null,
          position:event ? eventToSvgPoint(event) : null,
          meta:{source:'text'}
        });
      }
      async function handleDroppedFiles(files, targetNode){
        if(!files || !files.length) return;
        if(!targetNode){
          alert('请将文件拖放到具体的节点上以附加到该节点。');
          return;
        }
        commitInlineEditing();
        await attachFilesToNode(targetNode, files);
      }
      function handleInsertBetweenNodes(parentNode, childNode){
        if(!parentNode || !childNode || typeof jm.insert_node_between!=='function') return;
        performUndoable('insert-between',()=>{
          commitInlineEditing();
          const created=jm.insert_node_between(parentNode.id, childNode.id, {topic:'新节点'});
          if(!created) return false;
          markDirty();
          scheduleHandleRefresh();
          requestAnimationFrame(()=>{
            const latest=jm.get_node(created.id);
            if(latest){
              jm.select_node(latest.id);
              refreshInspector(latest);
              startInlineEditing(latest);
              centerOnNodeSmooth(latest);
            }
          });
          return true;
        },{mergeKey:`insert:${parentNode.id}`});
      }
      function addSiblingNode(){
        const node=ensureNode();
        if(!node || node.isroot || !node.parent) return;
        commitInlineEditing();
        executeCreateNodeCommand({ parentId:node.parent.id, topic:'新节点' });
      }
      function addChildNode(){
        const node=ensureNode();
        if(node){
          commitInlineEditing();
          executeCreateNodeCommand({ parentId:node.id, topic:'子节点' });
        }
      }
      function deleteSelectedNode(){
        const node=ensureNode(); if(!node || node.isroot) return;
        performUndoable('delete-node',()=>{
          commitInlineEditing();
          jm.remove_node(node.id);
          markDirty();
          scheduleHandleRefresh();
          requestAnimationFrame(()=>refreshInspector(jm.get_selected_node()));
          return true;
        },{mergeKey:`delete:${node.parent ? node.parent.id : 'root'}`});
      }
      function renameSelectedNode(){
        const node=ensureNode();
        if(!node) return;
        startInlineEditing(node);
      }
      function focusParentNode(){
        const node=ensureNode();
        if(node && node.parent){ jm.select_node(node.parent.id); scheduleHandleRefresh(); }
      }
      function openAttachmentDialog(){
        commitInlineEditing();
        const node=ensureNode();
        if(!node){ alert('请先选择一个节点'); return; }
        if(!attachInput) return;
        attachInput.dataset.targetId=node.id;
        attachInput.value='';
        attachInput.click();
      }
      function openLinkPrompt(){
        commitInlineEditing();
        const node=ensureNode();
        if(!node){ alert('请先选择一个节点'); return; }
        const raw=prompt('请输入链接地址');
        if(!raw) return;
        const cleaned=raw.trim();
        if(!cleaned) return;
        if(!isProbablyUrl(cleaned) && !confirm('该内容看起来不像链接，仍要创建吗？')) return;
        const title=prompt('请输入链接标题', cleaned) || cleaned;
        const data=ensureNodeDataObject(node);
        data.url=cleaned;
        data.linkTitle=(title||'').trim()||cleaned;
        node.model.data=data;
        node.data=data;
        jm.computeLayout();
        jm.render();
        jm.select_node(node.id);
        markDirty();
        scheduleHandleRefresh();
        refreshInspector(jm.get_node(node.id));
      }
      if(attachInput){
        attachInput.addEventListener('change',async e=>{
          const files=Array.from(e.target.files||[]);
          attachInput.value='';
          if(!files.length) return;
          const targetId=attachInput.dataset.targetId;
          const node=targetId ? jm.get_node(targetId) : ensureNode();
          if(!node){ alert('请先选择一个节点'); return; }
          await attachFilesToNode(node, files);
          refreshInspector(jm.get_node(node.id));
        });
      }
      if(nodeTopicInput){
        const commitTopic=()=>{
          if(inspectorSyncing) return;
          const node=ensureNode();
          if(!node) return;
          const value=(nodeTopicInput.value||'').trim();
          if(!value){
            nodeTopicInput.value=node.topic || '';
            return;
          }
          if(value===node.topic) return;
          commitInlineEditing();
          if(typeof jm.update_node==='function'){ jm.update_node(node.id, value); }
        };
        nodeTopicInput.addEventListener('change',commitTopic);
        nodeTopicInput.addEventListener('blur',commitTopic);
        nodeTopicInput.addEventListener('keydown',e=>{
          if(e.key==='Enter' && !e.shiftKey){
            e.preventDefault();
            commitTopic();
          }
        });
      }
      if(nodeNoteInput){
        const commitNote=()=>{
          if(inspectorSyncing) return;
          const node=ensureNode();
          if(!node) return;
          const value=(nodeNoteInput.value||'').replace(/\r\n?/g,'\n');
          const current=typeof (node.data && node.data.note)==='string'?node.data.note:'';
          if(current===value) return;
          applyInspectorChange(data=>{ data.note=value; });
        };
        nodeNoteInput.addEventListener('change',commitNote);
        nodeNoteInput.addEventListener('blur',commitNote);
      }
      if(nodeFoldToggle){
        nodeFoldToggle.addEventListener('change',()=>{
          if(inspectorSyncing) return;
          const node=ensureNode();
          if(!node) return;
          setNodeExpandedState(node, nodeFoldToggle.checked);
        });
      }
      const closeMapIoMenu=()=>{
        if(mapIo){ mapIo.setAttribute('aria-expanded','false'); }
        if(mapIoButton){ mapIoButton.setAttribute('aria-expanded','false'); }
      };
      const openMapIoMenu=()=>{
        if(mapIo){ mapIo.setAttribute('aria-expanded','true'); }
        if(mapIoButton){ mapIoButton.setAttribute('aria-expanded','true'); }
      };
      const toggleMapIoMenu=()=>{
        const expanded=mapIoButton && mapIoButton.getAttribute('aria-expanded')==='true';
        if(expanded) closeMapIoMenu(); else openMapIoMenu();
      };
      const handleMindAction=(action)=>{
        if(!action) return;
        closeMapIoMenu();
        if(action!=='relation' && relationMode){ cancelRelationMode(false); }
        switch(action){
          case 'save': saveMindmap(); break;
          case 'undo': undoMindChange(); break;
          case 'redo': redoMindChange(); break;
          case 'sibling': addSiblingNode(); break;
          case 'child': addChildNode(); break;
          case 'fold': {
            const node=ensureNode();
            if(node && node.children && node.children.length){
              const next=node.expanded===false;
              setNodeExpandedState(node, next);
            }
            break;
          }
          case 'attach': openAttachmentDialog(); break;
          case 'relation': toggleRelationMode(); break;
          case 'link': openLinkPrompt(); break;
          case 'delete': deleteSelectedNode(); break;
          case 'import': triggerImport(); break;
          case 'export':
          case 'export-json':
            exportMindmapAsJson();
            break;
          case 'export-pdf':
            exportMindmapAsImage('pdf');
            break;
          case 'export-jpg':
            exportMindmapAsImage('jpg');
            break;
          case 'delete-map':
            if(!currentMapId){
              alert('该导图尚未保存，无法删除。');
              break;
            }
            if(deleteMapForm && confirm('确认删除该导图？')){ deleteMapForm.submit(); }
            break;
        }
      };
      if(mapIoButton){
        mapIoButton.addEventListener('click',e=>{
          e.preventDefault();
          e.stopPropagation();
          toggleMapIoMenu();
        });
      }
      if(mapIoMenu){
        mapIoMenu.addEventListener('click',e=>{
          const item=e.target.closest('button[data-action]');
          if(!item) return;
          e.preventDefault();
          handleMindAction(item.dataset.action);
        });
      }
      if(mapIo){
        document.addEventListener('pointerdown',e=>{
          if(mapIo.getAttribute('aria-expanded')!=='true') return;
          if(mapIo.contains(e.target)) return;
          closeMapIoMenu();
        });
      }
      if(importModal){
        importModal.addEventListener('click',e=>{
          if(e.target===importModal){ closeImportModeDialog(); }
        });
      }
      if(importCancelButton){
        importCancelButton.addEventListener('click',e=>{ e.preventDefault(); closeImportModeDialog(); });
      }
      if(importModeButtons.length){
        importModeButtons.forEach(btn=>{
          btn.addEventListener('click',e=>{
            e.preventDefault();
            const mode=btn.dataset.importMode;
            if(!mode){ return; }
            if(!pendingImportPayload){ closeImportModeDialog(); return; }
            handleImportModeSelection(mode);
          });
        });
      }
      if(mapDeleteButton){
        mapDeleteButton.addEventListener('click',e=>{
          e.preventDefault();
          handleMindAction('delete-map');
        });
      }
      if(dock){
        dock.addEventListener('click',e=>{
          const btn=e.target.closest('.dock-btn');
          if(!btn || !dock.contains(btn)) return;
          const action=btn.dataset.action;
          if(action){
            handleMindAction(action);
          }
        });
        dock.addEventListener('keydown',e=>{
          if((e.key==='Enter' || e.key===' ') && e.target instanceof HTMLElement && e.target.closest('.dock-btn')){
            const btn=e.target.closest('.dock-btn');
            if(btn && btn.dataset.action){
              e.preventDefault();
              handleMindAction(btn.dataset.action);
            }
          }
        });
        const applyFisheye=(event)=>{
          if(!dockButtons.length) return;
          if(!isFisheyeEnabled()){ dockButtons.forEach(btn=>btn.style.transform=''); return; }
          const rect=dock.getBoundingClientRect();
          const centerX=event.clientX-rect.left + dock.scrollLeft;
          dockButtons.forEach(btn=>{
            const bx=btn.offsetLeft + btn.offsetWidth/2;
            const dist=Math.abs(centerX-bx);
            const scale=Math.max(1, 1.18 - dist/800);
            btn.style.transform=`scale(${scale})`;
          });
        };
        if(dockButtons.length){
          dock.addEventListener('mousemove',applyFisheye);
          dock.addEventListener('mouseleave',()=>{ dockButtons.forEach(btn=>btn.style.transform=''); });
        }
        if(fisheyeToggle){
          fisheyeToggle.addEventListener('change',()=>{
            syncFisheyeState();
          });
        }
      }
      document.addEventListener('keydown',e=>{
        if(e.key==='Escape'){ closeMapIoMenu(); }
      }, true);
      document.addEventListener('keydown',e=>{
        const key=(e.key||'').toLowerCase();
        if(key==='s' && (e.ctrlKey || e.metaKey)){
          e.preventDefault();
          saveMindmap();
        }
      });
      document.addEventListener('keydown',e=>{
        const activeEl=document.activeElement;
        if(activeEl){
          const tag=activeEl.tagName || '';
          if(activeEl.isContentEditable || /input|textarea|select/i.test(tag)) return;
        }
        if(currentEditingId()) return;
        const key=e.key || '';
        const lowerKey=key.toLowerCase();
        if((e.ctrlKey || e.metaKey) && !e.shiftKey && lowerKey==='z'){
          e.preventDefault();
          undoMindChange();
          return;
        }
        if((e.ctrlKey || e.metaKey) && ((e.shiftKey && lowerKey==='z') || (!e.metaKey && lowerKey==='y'))){
          e.preventDefault();
          redoMindChange();
          return;
        }
        if((e.ctrlKey || e.metaKey) && lowerKey==='c'){
          e.preventDefault();
          if(copySelectedNode()){ showRelationToast('节点已复制'); }
          return;
        }
        if((e.ctrlKey || e.metaKey) && lowerKey==='v'){
          e.preventDefault();
          const pasted=pasteNodeAsSibling();
          if(pasted){ showRelationToast('已粘贴节点'); }
          return;
        }
        if(key==='Enter' && !e.shiftKey){
          e.preventDefault();
          addSiblingNode();
        }else if(key==='Tab' && e.shiftKey){
          e.preventDefault();
          if(!promoteNodeLevel()){ focusParentNode(); }
        }else if(key==='Tab'){
          e.preventDefault();
          addChildNode();
        }else if(key==='Delete' || key==='Backspace'){
          e.preventDefault();
          deleteSelectedNode();
        }else if(key===' ' || e.code==='Space'){
          e.preventDefault();
          toggleBranch();
        }else if(key==='ArrowRight'){
          e.preventDefault();
          expandSelectedNode();
        }else if(key==='ArrowLeft'){
          e.preventDefault();
          if(!collapseSelectedNode()){ focusParentNode(); }
        }else if(key==='F2'){
          e.preventDefault();
          renameSelectedNode();
        }
      });
      function callView(method, ...args){
        if(jm.view && typeof jm.view[method] === 'function'){
          try{ jm.view[method](...args); return true; }catch(err){ console.warn(err); }
        }
        return false;
      }
      let dropHoverNode=null;
      function clearDropHover(){
        if(dropHoverNode){ dropHoverNode.classList.remove('drop-target'); dropHoverNode=null; }
      }
      if(jmContainer){
        jmContainer.addEventListener('pointerdown',()=>{ collapseInfoBar(true); });
      }
      jmContainer.addEventListener('dragenter',e=>{
        e.preventDefault();
        jmContainer.classList.add('dragover');
      });
      jmContainer.addEventListener('dragleave',e=>{
        if(!jmContainer.contains(e.relatedTarget)){ jmContainer.classList.remove('dragover'); clearDropHover(); }
      });
      jmContainer.addEventListener('dragover',e=>{
        e.preventDefault();
        const nodeEl=findNodeElementByEvent(e);
        if(dropHoverNode!==nodeEl){
          clearDropHover();
          if(nodeEl){ nodeEl.classList.add('drop-target'); dropHoverNode=nodeEl; }
        }
        const types=Array.from(e.dataTransfer?.types || []);
        const isFileDrag=types.includes('Files');
        if(isFileDrag && !nodeEl){ e.dataTransfer.dropEffect='none'; }
        else{ e.dataTransfer.dropEffect='copy'; }
      });
      jmContainer.addEventListener('drop',e=>{
        e.preventDefault();
        jmContainer.classList.remove('dragover');
        clearDropHover();
        syncOverlaySize();
        const nodeEl=findNodeElementByEvent(e);
        const target=nodeEl ? jm.get_node(nodeEl.getAttribute('nodeid')) : null;
        const parent=target || resolveDropParent(e);
        const files=e.dataTransfer.files && e.dataTransfer.files.length ? Array.from(e.dataTransfer.files) : [];
        if(files.length){
          handleDroppedFiles(files, target).catch(err=>console.error(err));
          return;
        }
        const uri=e.dataTransfer.getData('text/uri-list');
        let text='';
        if(uri){ text=uri; } else { text=e.dataTransfer.getData('text/plain') || ''; }
        if(text){
          handleDroppedText(text, parent, e);
        }
      });
      if(titleInput){ titleInput.addEventListener('input', markDirty); }
      if(window.jsMind && jsMind.event_type){
        jm.add_event_listener(type=>{
          if(isContextMenuOpen() && (type===jsMind.event_type.select || type===jsMind.event_type.refresh || type===jsMind.event_type.show)){
            closeNodeContextMenu();
          }
          if(type===jsMind.event_type.select){
            const selected=jm.get_selected_node();
            const editingId=currentEditingId();
            if(editingId && (!selected || selected.id!==editingId)){
              commitInlineEditing();
            }
            collapseInfoBar();
          }
          if(type===jsMind.event_type.select || type===jsMind.event_type.refresh || type===jsMind.event_type.after_edit || type===jsMind.event_type.show){
            scheduleHandleRefresh();
            requestAnimationFrame(()=>refreshInspector(jm.get_selected_node()));
            updateFoldAllLabel();
            updateFoldButtonState();
          }
          if(type===jsMind.event_type.edit || type===jsMind.event_type.after_edit || type===jsMind.event_type.update){ markDirty(); }
          updateUndoRedoButtons();
        });
      }
      function captureLayoutSnapshot(mind){
        if(!mind) return null;
        const captureElementState=(el, includeContent)=>{
          if(!el) return null;
          const style=el.style ? {
            width:el.style.width||'',
            height:el.style.height||'',
            transform:el.style.transform||'',
            left:el.style.left||'',
            top:el.style.top||'',
            display:el.style.display||'',
          } : null;
          const isSvg=(typeof SVGElement!=='undefined' && el instanceof SVGElement);
          const attrs=isSvg?{
            viewBox:el.getAttribute('viewBox'),
            width:el.getAttribute('width'),
            height:el.getAttribute('height'),
          }:null;
          const html=includeContent?el.innerHTML:null;
          return {style,attrs,html};
        };
        const nodes=[];
        if(mind.nodes && typeof mind.nodes.forEach==='function'){
          mind.nodes.forEach(node=>{
            if(!node) return;
            nodes.push({
              id:node.id,
              x:node.x,
              y:node.y,
              absX:node.absX,
              absY:node.absY,
              layoutHeight:node._layoutHeight,
              depth:node.depth,
              direction:node.direction,
              dir:node.dir,
              modelDirection:node.model && typeof node.model.direction!=='undefined'?node.model.direction:undefined,
            });
          });
        }
        return {
          bounds:mind.bounds?{...mind.bounds}:null,
          viewport:captureElementState(mind.viewport,false),
          nodeLayer:captureElementState(mind.nodeLayer,false),
          linkLayer:captureElementState(mind.linkLayer,true),
          relationLayer:captureElementState(mind.relationLayer,true),
          guideLayer:captureElementState(mind.guideLayer,true),
          view:{
            scale:typeof mind.scale==='number'?mind.scale:null,
            offsetX:typeof mind.offsetX==='number'?mind.offsetX:null,
            offsetY:typeof mind.offsetY==='number'?mind.offsetY:null,
          },
          nodes,
        };
      }
      function restoreLayoutSnapshot(mind,snapshot){
        if(!mind || !snapshot) return;
        const rebuildLinkRegistry=(mindInstance)=>{
          if(!mindInstance || !mindInstance.nodes || !mindInstance.linkLayer) return;
          if(mindInstance.linkRegistry && typeof mindInstance.linkRegistry.clear==='function'){
            mindInstance.linkRegistry.clear();
          }
          mindInstance.nodes.forEach(node=>{
            if(!node) return;
            if(!node.parent){
              node.linkGroup=null;
              node.linkShadow=null;
              node.linkPath=null;
              node.linkHighlight=null;
              return;
            }
            const selector=`g.trace-group[data-from="${node.parent.id}"][data-to="${node.id}"]`;
            const group=mindInstance.linkLayer.querySelector(selector);
            if(!group){
              node.linkGroup=null;
              node.linkShadow=null;
              node.linkPath=null;
              node.linkHighlight=null;
              return;
            }
            const shadow=group.querySelector('.trace.shadow');
            const core=group.querySelector('.trace.core');
            const highlight=group.querySelector('.trace.highlight');
            node.linkGroup=group;
            node.linkShadow=shadow||null;
            node.linkPath=core||null;
            node.linkHighlight=highlight||null;
            if(core){
              const parentId=node.parent.id;
              const childId=node.id;
              const enterEdge=()=>{ if(typeof mindInstance.setEdgeHover==='function'){ mindInstance.setEdgeHover(parentId, childId); } };
              const leaveEdge=()=>{ if(typeof mindInstance.clearEdgeHover==='function'){ mindInstance.clearEdgeHover(parentId, childId); } };
              core.addEventListener('pointerenter', enterEdge);
              core.addEventListener('pointerleave', leaveEdge);
              core.addEventListener('pointercancel', leaveEdge);
            }
            if(mindInstance.linkRegistry){
              mindInstance.linkRegistry.set(node.id,{group,shadow,core,highlight});
            }
          });
        };
        const rebuildRelationRegistry=(mindInstance)=>{
          if(!mindInstance || !mindInstance.relationLayer) return;
          if(mindInstance.relationRegistry && typeof mindInstance.relationRegistry.clear==='function'){
            mindInstance.relationRegistry.clear();
          }
          if(!Array.isArray(mindInstance.relations)) return;
          for(const relation of mindInstance.relations){
            if(!relation) continue;
            const group=mindInstance.relationLayer.querySelector(`g.relation-group[data-id="${relation.id}"]`);
            if(!group) continue;
            const shadow=group.querySelector('.relation-shadow');
            const core=group.querySelector('.relation-core');
            const highlight=group.querySelector('.relation-highlight');
            if(core){ core.dataset.bidirectional=relation.bidirectional?'true':'false'; }
            if(mindInstance.relationRegistry){
              mindInstance.relationRegistry.set(relation.id,{group,shadow,core,highlight,relation});
            }
          }
        };
        const restoreElementState=(el,state)=>{
          if(!el || !state) return;
          if(state.style){
            Object.entries(state.style).forEach(([key,value])=>{
              if(el.style){ el.style[key]=value||''; }
            });
          }
          if(state.attrs){
            Object.entries(state.attrs).forEach(([key,value])=>{
              if(value==null || value===''){ el.removeAttribute(key); }
              else{ el.setAttribute(key,value); }
            });
          }
          if(typeof state.html==='string'){ el.innerHTML=state.html; }
        };
        mind.bounds=snapshot.bounds?{...snapshot.bounds}:null;
        restoreElementState(mind.viewport,snapshot.viewport);
        restoreElementState(mind.nodeLayer,snapshot.nodeLayer);
        restoreElementState(mind.linkLayer,snapshot.linkLayer);
        restoreElementState(mind.relationLayer,snapshot.relationLayer);
        restoreElementState(mind.guideLayer,snapshot.guideLayer);
        if(snapshot.view){
          if(typeof snapshot.view.scale==='number' && isFinite(snapshot.view.scale)){ mind.scale=snapshot.view.scale; }
          if(typeof snapshot.view.offsetX==='number' && isFinite(snapshot.view.offsetX)){ mind.offsetX=snapshot.view.offsetX; }
          if(typeof snapshot.view.offsetY==='number' && isFinite(snapshot.view.offsetY)){ mind.offsetY=snapshot.view.offsetY; }
        }
        rebuildLinkRegistry(mind);
        rebuildRelationRegistry(mind);
        if(Array.isArray(snapshot.nodes) && mind.nodes && typeof mind.nodes.get==='function'){
          for(const nodeState of snapshot.nodes){
            if(!nodeState || !nodeState.id) continue;
            const node=mind.nodes.get(nodeState.id);
            if(!node) continue;
            node.x=nodeState.x;
            node.y=nodeState.y;
            node.absX=nodeState.absX;
            node.absY=nodeState.absY;
            node._layoutHeight=nodeState.layoutHeight;
            node.depth=nodeState.depth;
            node.direction=nodeState.direction;
            node.dir=nodeState.dir;
            if(node.model && nodeState.modelDirection!==undefined){ node.model.direction=nodeState.modelDirection; }
            if(node.el){
              const width=node.el.offsetWidth||0;
              const height=node.el.offsetHeight||0;
              if(Number.isFinite(node.absX)){ node.el.style.left=`${node.absX - width/2}px`; }
              if(Number.isFinite(node.absY)){ node.el.style.top=`${node.absY - height/2}px`; }
            }
            if(typeof mind.updateAnchors==='function'){ mind.updateAnchors(node); }
            if(typeof mind.updateLinkPath==='function'){ mind.updateLinkPath(node); }
            if(typeof mind.updateRelationsForNode==='function'){ mind.updateRelationsForNode(node); }
          }
        }
        if(typeof mind.updateEdgeButtonScale==='function'){ mind.updateEdgeButtonScale(); }
        if(typeof mind.applyTransform==='function'){ mind.applyTransform(); }
        if(typeof syncOverlaySize==='function'){ syncOverlaySize(); }
        if(typeof updateHandlePosition==='function'){ updateHandlePosition(); }
        updateFoldAllLabel();
      }
      function exportMindmapAsJson(){
        if(!jm){
          alert('思维导图尚未加载');
          return;
        }
        const data=jm.get_data('node_tree');
        if(data && data.data){ enforceRightOrientation(data.data); }
        const blob=new Blob([JSON.stringify(data,null,2)],{type:'application/json'});
        const url=URL.createObjectURL(blob);
        const a=document.createElement('a');
        a.href=url;
        a.download=buildExportFilename('json', titleInput ? titleInput.value.trim() : '');
        a.click();
        setTimeout(()=>URL.revokeObjectURL(url), 1000);
      }
      async function exportMindmapAsImage(format){
        if(!jmContainer){
          alert('思维导图尚未加载');
          return;
        }
        const titleValue=titleInput ? titleInput.value.trim() : '';
        const overlayDisplay=overlay ? overlay.style.display : null;
        const shouldAnimate=format==='pdf' || format==='jpg';
        if(shouldAnimate){
          showExportOverlay();
          await new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)));
        }
        let exportHost=null;
        let layoutSnapshot=null;
        try{
          const htmlToImage=await ensureHtmlToImage();
          const computedStyle=getComputedStyle(document.body);
          const backgroundColor=(computedStyle.getPropertyValue('--bg-void') || computedStyle.backgroundColor || '#0A0C0E').trim() || '#0A0C0E';
          if(overlay){ overlay.style.display='none'; }
          if(jm){ layoutSnapshot=captureLayoutSnapshot(jm); }
          if(typeof jm.computeLayout==='function'){ try{ jm.computeLayout(); }catch(err){ console.warn(err); } }
          const viewport=jm && jm.viewport ? jm.viewport : jmContainer.querySelector('.mind-viewport');
          if(!viewport){ throw new Error('无法定位导出视图'); }
          const bounds=jm && jm.bounds ? jm.bounds : null;
          if(!bounds || !isFinite(bounds.width) || !isFinite(bounds.height)){
            throw new Error('无法计算导图范围');
          }
          const viewportWidth=Math.max(1, Math.ceil(bounds.width));
          const viewportHeight=Math.max(1, Math.ceil(bounds.height));
          const SAFE_CANVAS_BOUND=16384;
          const basePixelRatio=window.devicePixelRatio || 1;
          const minPixelRatio=2;
          const maxPixelRatio=4;
          const sizeLimitedRatio=Math.max(1, Math.floor(SAFE_CANVAS_BOUND / Math.max(viewportWidth, viewportHeight)));
          const pixelRatio=Math.min(maxPixelRatio, Math.max(minPixelRatio, basePixelRatio), sizeLimitedRatio);
          exportHost=document.createElement('div');
          exportHost.style.position='fixed';
          exportHost.style.left='-120vw';
          exportHost.style.top='0';
          exportHost.style.opacity='0';
          exportHost.style.pointerEvents='none';
          const clone=viewport.cloneNode(true);
          clone.style.transform='translate(0px, 0px) scale(1)';
          clone.style.transformOrigin='top left';
          clone.style.width=`${viewportWidth}px`;
          clone.style.height=`${viewportHeight}px`;
          clone.style.overflow='visible';
          clone.style.position='absolute';
          clone.style.left='0';
          clone.style.top='0';
          const exportWrapper=document.createElement('div');
          exportWrapper.style.position='relative';
          exportWrapper.style.width=`${viewportWidth}px`;
          exportWrapper.style.height=`${viewportHeight}px`;
          exportWrapper.style.pointerEvents='none';
          exportWrapper.style.overflow='visible';
          exportWrapper.appendChild(clone);
          if(jm && jm.linkControlLayer){
            const overlayClone=jm.linkControlLayer.cloneNode(true);
            overlayClone.style.position='absolute';
            overlayClone.style.left='0';
            overlayClone.style.top='0';
            overlayClone.style.width=`${viewportWidth}px`;
            overlayClone.style.height=`${viewportHeight}px`;
            overlayClone.style.pointerEvents='none';
            overlayClone.style.transform='none';
            const buttons=overlayClone.querySelectorAll('.edge-insert-btn');
            buttons.forEach(btn=>{
              const rawX=btn.dataset ? btn.dataset.logicalX : null;
              const rawY=btn.dataset ? btn.dataset.logicalY : null;
              const logicalX=rawX!=null?parseFloat(rawX):NaN;
              const logicalY=rawY!=null?parseFloat(rawY):NaN;
              if(Number.isFinite(logicalX) && Number.isFinite(logicalY)){
                btn.style.left=`${logicalX}px`;
                btn.style.top=`${logicalY}px`;
              }else{
                btn.setAttribute('hidden','');
              }
            });
            exportWrapper.appendChild(overlayClone);
          }
          exportHost.appendChild(exportWrapper);
          document.body.appendChild(exportHost);
          const canvas=await htmlToImage.toCanvas(exportWrapper,{
            backgroundColor,
            pixelRatio,
            filter:node=>{
              if(!(node instanceof Element)) return true;
              return node.dataset.exportIgnore!=='true';
            }
          });
          if(!canvas){ throw new Error('无法生成导出图像'); }
          if(format==='jpg'){
            const blob=await canvasToBlob(canvas,'image/jpeg',0.95);
            const url=URL.createObjectURL(blob);
            const a=document.createElement('a');
            a.href=url;
            a.download=buildExportFilename('jpg', titleValue);
            document.body.appendChild(a);
            a.click();
            a.remove();
            setTimeout(()=>URL.revokeObjectURL(url), 1500);
          }else if(format==='pdf'){
            const jsPDF=await ensureJsPDF();
            const isLandscape=canvas.width>=canvas.height;
            const pageSize=isLandscape?[canvas.height, canvas.width]:[canvas.width, canvas.height];
            const pdf=new jsPDF({orientation:isLandscape?'landscape':'portrait', unit:'px', format:pageSize});
            const dataUrl=canvas.toDataURL('image/png');
            const pageWidth=pdf.internal.pageSize.getWidth();
            const pageHeight=pdf.internal.pageSize.getHeight();
            pdf.addImage(dataUrl,'PNG',0,0,pageWidth,pageHeight,undefined,'FAST');
            pdf.save(buildExportFilename('pdf', titleValue));
          }else{
            throw new Error('不支持的导出格式');
          }
        }catch(error){
          console.error(error);
          const message=error && error.message ? `导出失败：${error.message}` : '导出失败，请稍后重试。';
          alert(message);
        }finally{
          if(layoutSnapshot){ restoreLayoutSnapshot(jm, layoutSnapshot); }
          if(exportHost && exportHost.parentElement){ exportHost.remove(); }
          if(overlay){ overlay.style.display=overlayDisplay || ''; }
          if(shouldAnimate){ hideExportOverlay(); }
        }
      }
      function openImportModeDialog(fileName, data){
        pendingImportPayload=data;
        pendingImportFileName=fileName || '导图文件';
        if(importModalName){ importModalName.textContent=pendingImportFileName; }
        if(importModal){ importModal.dataset.open='true'; }
      }
      function closeImportModeDialog(){
        pendingImportPayload=null;
        pendingImportFileName='';
        if(importModal){ importModal.dataset.open='false'; }
      }
      function handleImportModeSelection(mode){
        if(!pendingImportPayload) return;
        const payload=JSON.parse(JSON.stringify(pendingImportPayload));
        try{
          commitInlineEditing();
          if(mapDeleteButton && mode!=='new-map'){ mapDeleteButton.disabled=!currentMapId; }
          switch(mode){
            case 'replace':
              jm.show(payload);
              initialData=JSON.parse(JSON.stringify(payload));
              if(initialData && initialData.data){ enforceRightOrientation(initialData.data); }
              markDirty();
              break;
            case 'append-node':
              importJsonAsSubtree(payload);
              break;
            case 'new-map':
              importJsonAsNewMap(payload);
              break;
            default:
              break;
          }
          closeImportModeDialog();
        }catch(err){
          alert(err.message || '导入失败');
        }
      }
      function triggerImport(){
        if(importInput){ importInput.click(); }
      }
      function findModelById(node,id){
        if(!node || !id) return null;
        if(node.id===id) return node;
        if(Array.isArray(node.children)){
          for(const child of node.children){
            const found=findModelById(child,id);
            if(found) return found;
          }
        }
        return null;
      }
      function cloneImportSubtree(source){
        if(!source || typeof source!=='object') return null;
        const cloned={
          id:'node-'+Math.random().toString(36).slice(2,10),
          topic:typeof source.topic==='string' && source.topic.trim()?source.topic.trim():'导入节点',
          data:normalizeNodeData(source.data ? JSON.parse(JSON.stringify(source.data)) : {}),
          expanded:source.expanded!==false,
          direction:'right',
          children:[]
        };
        if(source.meta){ cloned.meta=JSON.parse(JSON.stringify(source.meta)); }
        if(source.style){ cloned.style=JSON.parse(JSON.stringify(source.style)); }
        if(Array.isArray(source.children)){
          cloned.children=source.children.map(child=>cloneImportSubtree(child)).filter(Boolean);
        }
        return cloned;
      }
      function importJsonAsSubtree(json){
        const tree=json && json.data ? json.data : null;
        if(!tree){ alert('文件格式不兼容'); return; }
        const target=jm.get_selected_node() || jm.get_root();
        if(!target){ alert('请选择要导入到的节点'); return; }
        const current=jm.get_data('node_tree');
        if(!current || !current.data){ alert('当前导图数据异常'); return; }
        const parentModel=findModelById(current.data, target.id);
        if(!parentModel){ alert('无法定位目标节点'); return; }
        const newNode=cloneImportSubtree(tree);
        if(!newNode){ alert('导入数据为空'); return; }
        if(!Array.isArray(parentModel.children)){ parentModel.children=[]; }
        parentModel.children.push(newNode);
        parentModel.expanded=true;
        enforceRightOrientation(current.data);
        jm.show(current);
        requestAnimationFrame(()=>{ jm.select_node(newNode.id); scheduleHandleRefresh(); });
        markDirty();
      }
      function importJsonAsNewMap(json){
        const cloned=JSON.parse(JSON.stringify(json));
        if(cloned && cloned.data){ enforceRightOrientation(cloned.data); }
        jm.show(cloned);
        initialData=JSON.parse(JSON.stringify(cloned));
        if(initialData && initialData.data){ enforceRightOrientation(initialData.data); }
        currentMapId=0;
        if(jmContainer){ jmContainer.dataset.mapId='0'; }
        if(deleteMapForm){
          const idInput=deleteMapForm.querySelector('input[name="id"]');
          if(idInput){ idInput.value=''; }
        }
        if(mapDeleteButton){ mapDeleteButton.disabled=true; }
        if(titleInput){
          const metaName=cloned?.meta && typeof cloned.meta.name==='string' ? cloned.meta.name.trim() : '';
          const topicName=cloned?.data && typeof cloned.data.topic==='string' ? cloned.data.topic.trim() : '';
          const nextTitle=metaName || topicName || titleInput.value;
          if(nextTitle){ titleInput.value=nextTitle; }
        }
        markDirty();
        scheduleHandleRefresh();
      }
      if(importInput){
        importInput.addEventListener('change', e=>{
          const file=e.target.files[0]; if(!file) return;
          const reader=new FileReader();
          reader.onload=evt=>{
            try{
              const json=JSON.parse(evt.target.result);
              if(!json || !json.data){ alert('文件格式不兼容'); return; }
              enforceRightOrientation(json.data);
              closeImportModeDialog();
              pendingImportPayload=json;
              pendingImportFileName=file.name;
              openImportModeDialog(file.name, json);
            }catch(err){
              pendingImportPayload=null;
              pendingImportFileName='';
              alert('无法解析 JSON：'+err.message);
            }
            finally{
              importInput.value='';
            }
          };
          reader.onerror=()=>{
            pendingImportPayload=null;
            pendingImportFileName='';
            alert('读取文件失败');
            importInput.value='';
          };
          reader.readAsText(file,'utf-8');
        });
      }
      async function saveMindmap(){
        commitInlineEditing();
        const title=titleInput.value.trim()||'未命名导图';
        const currentData=jm.get_data('node_tree');
        if(currentData && currentData.data){ enforceRightOrientation(currentData.data); }
        const payload=JSON.stringify(currentData);
        const fd=new FormData();
        fd.append('action','save_mindmap');
        fd.append('id', String(currentMapId||0));
        fd.append('title', title);
        fd.append('content', payload);
        try{
          showSaving();
          const res=await fetch(location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'fetch'}});
          if(!res.ok) throw new Error('网络异常');
          const json=await res.json();
          if(!json.ok) throw new Error(json.error||'保存失败');
          currentMapId=parseInt(json.id,10)||0;
          document.getElementById('jsmind-container').dataset.mapId=currentMapId;
          if(mapDeleteButton){ mapDeleteButton.disabled=!currentMapId; }
          if(deleteMapForm){
            const idInput=deleteMapForm.querySelector('input[name="id"]');
            if(idInput){ idInput.value=currentMapId ? String(currentMapId) : ''; }
          }
          history.replaceState(null,'',`?view=map_edit&id=${json.id}`);
          initialData=JSON.parse(payload);
          if(initialData && initialData.data){ enforceRightOrientation(initialData.data); }
          markSaved();
        }catch(err){
          alert(err.message||'保存失败');
          markDirty();
        }
      }
      window.addEventListener('beforeunload',e=>{
        commitInlineEditing();
        if(blobUrlRegistry.size){
          blobUrlRegistry.forEach(url=>{ try{ URL.revokeObjectURL(url); }catch(_){ } });
          blobUrlRegistry.clear();
        }
        if(dirty){ e.preventDefault(); e.returnValue=''; }
      });
      })();
    </script>
  </body>
  </html>
  <?php
  exit;
}

if ($view === 'maps') {
  redirect('?cat=mindmaps');
}

// —— 首页 ——
$pdo=db(); [$cats,$counts]=get_categories();
$cat=$_GET['cat'] ?? 'all';
$q=trim((string)($_GET['q'] ?? ''));
$mindmap_total = (int)$pdo->query('SELECT COUNT(*) FROM mindmaps')->fetchColumn();
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
$all_total = (int)$pdo->query('SELECT COUNT(*) FROM items WHERE done = 0')->fetchColumn();
$categoryNames=[];
foreach($cats as $c){ $categoryNames[(int)$c['id']]=$c['name']; }
?>
<!doctype html>
<html lang="zh-Hans">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1"/>
<title>自适应备忘录 · 单文件</title>
<meta name="color-scheme" content="dark"/>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@500;600;700&family=Inter:wght@400;500;600&family=Noto+Sans+SC:wght@400;500;600;700&family=Noto+Serif+SC:wght@500;600;700&display=swap" rel="stylesheet">
<style>
  :root{
    --bg-void:#0A0C0E;
    --bg-elev-1:#0F1316;
    --bg-elev-2:#151A1E;
    --gold-700:#8E6B3D;
    --gold-600:#CFA66B;
    --gold-500:#E6C089;
    --gold-400:#F4D8A4;
    --accent-emerald:#23C4A2;
    --accent-crimson:#EF6D6D;
    --accent-cyan:#4BC3D1;
    --text-strong:#F2EEE6;
    --text-muted:#9F998E;
    --text-dim:#716B61;
    --divider:rgba(230,192,137,.2);
    --text:var(--text-strong);
    --bg:var(--bg-void);
    --panel:rgba(21,26,30,.9);
    --sidebar-width:clamp(240px,26vw,320px);
    --panel-strong:rgba(15,19,22,.94);
    --grid:rgba(201,168,106,.12);
    --grid-strong:rgba(201,168,106,.18);
    --glow:var(--gold-500);
    --glow-soft:rgba(227,198,139,.28);
    --glow-strong:rgba(227,198,139,.42);
    --danger:var(--accent-crimson);
    --danger-soft:rgba(209,75,75,.32);
    --accent:var(--gold-400);
    --accent-soft:rgba(227,198,139,.18);
    --border:rgba(201,168,106,.36);
    --border-soft:rgba(201,168,106,.2);
    --shadow:0 24px 60px rgba(0,0,0,.72);
    --grid-size:72px;
    --transition:300ms cubic-bezier(.22,.61,.36,1);
  }
  *,*::before,*::after{box-sizing:border-box}
  html,body{margin:0;min-height:100vh;background:var(--bg-void);color:var(--text-strong);font:16px/1.65 'Source Han Sans','Noto Sans SC','Inter','Microsoft YaHei',sans-serif;letter-spacing:.01em;position:relative;overflow-x:hidden}
  body{--item-padding:18px 16px;--item-gap:12px;--item-radius:18px;--item-line:36px;--item-desc-lines:3;--item-grid-gap:20px;}
  body[data-density='compact']{--item-padding:14px 14px;--item-gap:8px;--item-radius:16px;--item-line:28px;--item-desc-lines:2;--item-grid-gap:16px;}

  body::before{content:"";position:fixed;inset:0;background:
    linear-gradient(180deg,rgba(10,12,14,.45),rgba(10,12,14,.82)),
    url('data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" width="160" height="160" viewBox="0 0 160 160"%3E%3Cpath fill="rgba(201,168,106,0.05)" d="M0 79h160v2H0zm79-79h2v160h-2z"/%3E%3C/svg%3E');
    background-size:cover,160px 160px;opacity:.58;pointer-events:none;z-index:-3;
  }
  body::after{content:"";position:fixed;inset:0;background:
      radial-gradient(1200px 700px at 70% -10%,rgba(227,198,139,.06),transparent 60%),
      linear-gradient(120deg,rgba(201,168,106,.08),transparent 30%,rgba(201,168,106,.06) 70%,transparent 90%),
      repeating-linear-gradient(0deg,rgba(75,195,209,.08) 0,rgba(75,195,209,.08) 1px,transparent 1px,transparent calc(var(--grid-size))),
      repeating-linear-gradient(90deg,rgba(201,168,106,.12) 0,rgba(201,168,106,.12) 1px,transparent 1px,transparent calc(var(--grid-size)));
    mix-blend-mode:screen;opacity:.32;pointer-events:none;z-index:-4;background-size:100% 100%,100% 100%,var(--grid-size) var(--grid-size),var(--grid-size) var(--grid-size);
    background-attachment:fixed;
  }
  .scanlines{position:fixed;inset:0;pointer-events:none;z-index:-1;background:linear-gradient(to bottom,rgba(75,195,209,.14) 0,transparent 4px);background-size:100% 6px;opacity:.18}
  a{color:inherit;text-decoration:none}
  .app{display:flex;min-height:100vh;position:relative;z-index:0}
  .sidebar{position:fixed;top:0;left:0;bottom:0;width:var(--sidebar-width);overflow:auto;background:linear-gradient(165deg,rgba(12,14,18,.94) 0%,rgba(15,19,22,.9) 55%,rgba(21,26,30,.9) 100%);border-right:1px solid var(--border);box-shadow:inset 0 0 0 1px rgba(201,168,106,.08),0 18px 45px rgba(0,0,0,.45);padding:20px;backdrop-filter:blur(18px) saturate(170%);
    transition:transform var(--transition),box-shadow var(--transition);
  }
  .brand{display:flex;gap:12px;align-items:center;margin-bottom:18px;text-transform:uppercase;letter-spacing:.16em;color:var(--text-muted)}
  .brand .logo{width:34px;height:34px;border-radius:12px;background:radial-gradient(circle at 30% 30%,rgba(227,198,139,.85),rgba(227,198,139,.22));box-shadow:0 0 14px rgba(227,198,139,.45),0 0 32px rgba(227,198,139,.28);position:relative;overflow:hidden}
  .brand .logo::after{content:"";position:absolute;inset:6px;border-radius:10px;border:1px solid rgba(201,168,106,.38);box-shadow:0 0 16px rgba(227,198,139,.3);opacity:.85}
  .brand h1{font:600 16px/1.2 'Cinzel','Noto Serif SC',serif;color:var(--gold-400);text-shadow:0 0 18px rgba(227,198,139,.25)}
  .controls{display:flex;gap:10px;flex-wrap:wrap;margin:10px 0 18px}
  .dropdown{position:relative;display:inline-flex}
  .dropdown-toggle{width:100%}
  .dropdown-menu{position:absolute;top:calc(100% + 6px);left:0;min-width:100%;display:none;flex-direction:column;gap:6px;padding:10px;border-radius:14px;border:1px solid rgba(201,168,106,.32);background:rgba(12,16,18,.94);box-shadow:0 16px 36px rgba(0,0,0,.45);z-index:40}
  .dropdown-menu[data-open="true"]{display:flex}
  .dropdown-menu a{display:flex;align-items:center;gap:8px;padding:8px 12px;border-radius:10px;color:var(--text-strong);font:600 12px/1 'Inter','Noto Sans SC',sans-serif;letter-spacing:.12em;text-transform:uppercase;text-decoration:none;transition:background var(--transition),color var(--transition)}
  .dropdown-menu a:hover{background:rgba(201,168,106,.12);color:var(--gold-400)}
  .btn{position:relative;display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:10px 16px;border-radius:14px;border:1px solid transparent;background:transparent;color:var(--text-strong);font:600 12px/1 'Inter','Noto Sans SC',sans-serif;letter-spacing:.14em;text-transform:uppercase;text-decoration:none;box-shadow:none;transition:transform var(--transition),box-shadow var(--transition),border-color var(--transition),background var(--transition),color var(--transition);cursor:pointer}
  .btn::before{content:"";position:absolute;inset:0;border-radius:inherit;box-shadow:inset 0 0 0 1px rgba(227,198,139,.08);opacity:0;transition:opacity var(--transition)}
  .btn:hover::before{opacity:1}
  .btn-primary,.btn.acc{background:linear-gradient(135deg,var(--gold-500),var(--gold-600));color:#1b1306;border-color:rgba(142,107,61,.75);box-shadow:0 16px 34px rgba(227,198,139,.24),0 0 24px rgba(227,198,139,.18)}
  .btn-primary:hover,.btn.acc:hover{transform:translateY(-1px);box-shadow:0 20px 40px rgba(227,198,139,.3),0 0 30px rgba(227,198,139,.24)}
  .btn-outline{border:1px solid rgba(230,192,137,.4);background:rgba(15,19,22,.6);color:var(--gold-500);box-shadow:0 12px 28px rgba(0,0,0,.32)}
  .btn-outline:hover{border-color:rgba(230,192,137,.65);color:var(--gold-400)}
  .btn-ghost{border:1px solid rgba(230,192,137,.25);background:transparent;color:var(--gold-500)}
  .btn-ghost:hover{background:rgba(230,192,137,.08);color:var(--gold-400)}
  .btn-danger,.btn.danger{border:1px solid rgba(239,68,68,.35);color:#fca5a5;background:transparent;box-shadow:none}
  .btn-danger:hover,.btn.danger:hover{background:rgba(239,68,68,.08);border-color:rgba(239,68,68,.5)}
  .btn-small,.btn.small{padding:8px 12px;border-radius:12px;font-size:11px;letter-spacing:.18em}
  .btn:focus-visible{outline:none;box-shadow:0 0 0 3px rgba(230,192,137,.5)}
  .btn-icon{font-size:14px;line-height:1}
  .btn-label{display:inline-flex}
  .btn:active{transform:translateY(0);box-shadow:none}

  .section-title{font:600 12px/1 'Cinzel','Noto Serif SC',serif;text-transform:uppercase;letter-spacing:.24em;color:var(--gold-400);margin:14px 0 8px;text-shadow:0 0 14px rgba(227,198,139,.24)}
  .cat-list{display:flex;flex-direction:column;gap:8px}
  .cat{position:relative;display:flex;align-items:center;justify-content:space-between;gap:10px;padding:12px 14px;border-radius:14px;background:linear-gradient(140deg,rgba(15,19,22,.88),rgba(10,12,14,.88));border:1px solid var(--border-soft);box-shadow:inset 0 0 0 1px rgba(201,168,106,.06);transition:transform var(--transition),border-color var(--transition),box-shadow var(--transition)}
  .cat::before{content:"";position:absolute;inset:4px;border-radius:12px;border:1px dashed rgba(201,168,106,.18);opacity:0;transition:opacity var(--transition)}
  .cat:hover{transform:translateX(4px);border-color:rgba(201,168,106,.45);box-shadow:0 10px 22px rgba(0,0,0,.6)}
  .cat:hover::before{opacity:1}
  .cat.active{border-color:var(--glow);box-shadow:0 0 18px rgba(201,168,106,.3)}
  .cat .name{flex:1;display:block;font-weight:600;color:var(--text-strong);text-shadow:0 0 8px rgba(201,168,106,.18)}
  .cat .count{font:600 12px/1 'Inter','Noto Sans SC',sans-serif;color:var(--text-dim);letter-spacing:.14em;text-transform:uppercase}
  .footer{margin-top:18px;color:var(--text-dim);font-size:12px;line-height:1.8;text-shadow:0 0 10px rgba(201,168,106,.15)}
  .main{padding:24px 20px 40px;margin-left:var(--sidebar-width);background:linear-gradient(160deg,rgba(12,14,18,.82),rgba(10,12,14,.85));backdrop-filter:blur(14px) saturate(160%);position:relative;min-height:100vh;flex:1 1 auto;min-width:0;width:min(100%,calc(100vw - var(--sidebar-width)));max-width:calc(100vw - var(--sidebar-width));box-sizing:border-box}
  .main::before{content:"";position:absolute;inset:0;border-left:1px solid rgba(201,168,106,.12);border-top:1px solid rgba(201,168,106,.06);pointer-events:none;box-shadow:inset 0 0 0 1px rgba(201,168,106,.04)}
  .toolbar{display:flex;flex-wrap:wrap;gap:14px;align-items:center;margin-bottom:18px}
  .search{flex:1 1 260px;display:flex;align-items:center;gap:10px;background:linear-gradient(135deg,rgba(15,19,22,.9),rgba(10,12,14,.88));border:1px solid rgba(201,168,106,.32);border-radius:16px;padding:10px 14px;box-shadow:inset 0 0 28px rgba(201,168,106,.08)}
  .search input{all:unset;flex:1;color:var(--text-strong);font-size:15px;letter-spacing:.06em}
  .search input::placeholder{color:var(--text-dim)}
  .search button{padding:8px 14px;border-radius:12px;border:1px solid rgba(201,168,106,.42);background:rgba(201,168,106,.12);color:var(--gold-400);font:600 11px/1 'Inter','Noto Sans SC',sans-serif;text-transform:uppercase;letter-spacing:.18em;cursor:pointer;transition:background var(--transition),box-shadow var(--transition),border-color var(--transition)}
  .search button:hover{background:rgba(201,168,106,.2);border-color:rgba(201,168,106,.6);box-shadow:0 0 18px rgba(227,198,139,.22)}
  .actions-row{display:flex;gap:12px;flex-wrap:wrap;align-items:center}
  .density-toggle{display:inline-flex;border-radius:12px;border:1px solid rgba(230,192,137,.28);overflow:hidden;background:rgba(15,19,22,.6);box-shadow:0 10px 24px rgba(0,0,0,.35)}
  .density-toggle .btn{border:0;border-radius:0;box-shadow:none;font-size:11px;letter-spacing:.16em;padding:8px 14px;color:var(--text-dim)}
  .density-toggle .btn::before{display:none}
  .density-toggle .btn:hover{background:rgba(230,192,137,.08);color:var(--gold-400)}
  .density-toggle .btn.active{background:rgba(230,192,137,.16);color:var(--gold-400);box-shadow:inset 0 0 0 1px rgba(230,192,137,.25)}
  .items{display:grid;gap:var(--item-grid-gap);position:relative;grid-template-columns:repeat(3,minmax(0,1fr));align-items:start}
  .item{position:relative;padding:var(--item-padding);border-radius:var(--item-radius);background:linear-gradient(140deg,rgba(15,19,22,.9),rgba(10,12,14,.9));border:1px solid rgba(201,168,106,.28);box-shadow:var(--shadow);display:grid;gap:var(--item-gap);transition:transform var(--transition),box-shadow var(--transition),border-color var(--transition),opacity var(--transition)}
  .item::before{content:"";position:absolute;inset:6px;border-radius:14px;border:1px dashed rgba(201,168,106,.28);opacity:.85;pointer-events:none;box-shadow:0 0 26px rgba(227,198,139,.18)}
  .item::after{content:"";position:absolute;top:14px;right:16px;width:11px;height:11px;border-radius:50%;background:var(--gold-500);box-shadow:0 0 12px rgba(207,166,107,.5),0 0 22px rgba(142,107,61,.45)}
  .item:hover{transform:translateY(-4px);box-shadow:0 0 28px rgba(201,168,106,.28),0 26px 50px rgba(0,0,0,.6);border-color:rgba(201,168,106,.52)}
  .item.item-removing{opacity:0;transform:translateY(12px)}
  .item.done{background:linear-gradient(155deg,rgba(26,24,18,.9),rgba(18,16,12,.94));border-color:rgba(227,198,139,.55);box-shadow:0 0 28px rgba(201,168,106,.38),0 24px 58px rgba(0,0,0,.7)}
  .item-empty{grid-column:1/-1;text-align:center;padding:40px 24px;background:linear-gradient(150deg,rgba(15,19,22,.88),rgba(10,12,14,.9));border:1px dashed rgba(201,168,106,.28);box-shadow:none;color:var(--text-muted);letter-spacing:.12em}
  .item-empty::after,.item-empty::before{display:none}
  .item.done::after{background:transparent;box-shadow:none;border:1px solid rgba(230,192,137,.3)}
  .item.done::before{opacity:1;border-style:double;border-color:rgba(201,168,106,.45);box-shadow:0 0 32px rgba(227,198,139,.3)}
  .item-title{font-weight:600;font-size:16px;line-height:var(--item-line);letter-spacing:.2px;color:var(--text-strong);text-shadow:0 0 14px rgba(227,198,139,.16);display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;word-break:break-word}
  .item.done .item-title,.item.done .item-desc,.item.done .tinyline{text-decoration:line-through;color:rgba(227,198,139,.75)}
  .item-desc{color:var(--text-muted);white-space:pre-wrap;display:-webkit-box;-webkit-line-clamp:var(--item-desc-lines);-webkit-box-orient:vertical;overflow:hidden;word-break:break-word;font-size:13px;line-height:1.6;opacity:.88}
  .badge{display:inline-flex;align-items:center;gap:6px;font:600 11px/1 'Inter','Noto Sans SC',sans-serif;text-transform:uppercase;letter-spacing:.22em;padding:4px 10px;border-radius:999px;border:1px solid rgba(230,192,137,.32);background:rgba(230,192,137,.08);color:var(--text-muted);box-shadow:inset 0 0 12px rgba(230,192,137,.05)}
  .attachment-badge{background:rgba(75,195,209,.14);border-color:rgba(75,195,209,.38);color:#9fe8f1}
  .item-meta{display:flex;justify-content:space-between;align-items:flex-end;gap:12px;margin-top:6px;font-size:12px;opacity:.75}
  .item-meta .meta-left{display:flex;align-items:center;gap:6px;flex-wrap:wrap}
  .item-meta .meta-right{margin-left:auto;text-align:right;display:flex;justify-content:flex-end;min-width:130px}
  .item-meta .item-time{font:600 12px/1 'Inter','Noto Sans SC',sans-serif;letter-spacing:.12em;color:var(--text-dim);white-space:nowrap}
  .kbd{font:600 12px/1 'Inter','Noto Sans SC',sans-serif;padding:2px 6px;border:1px dashed rgba(201,168,106,.35);border-radius:6px;background:rgba(15,19,22,.82);color:var(--text-dim);text-transform:uppercase;letter-spacing:.16em;box-shadow:0 0 12px rgba(201,168,106,.12)}
  mark{background:rgba(75,195,209,.2);color:inherit;padding:0 2px;border-radius:4px}
  .tinyline{position:relative;margin-left:12px;padding-left:28px;color:var(--text-muted)}
  .tinyline::before{content:"";position:absolute;left:12px;top:4px;bottom:4px;width:2px;border-radius:999px;background:linear-gradient(to bottom,rgba(201,168,106,.65),rgba(201,168,106,.18));box-shadow:0 0 18px rgba(227,198,139,.25),0 0 24px rgba(201,168,106,.22)}
  .tlrow{position:relative;margin:6px 0;padding-left:10px;display:flex;gap:8px;align-items:center}
  .tlrow.done .step-title{text-decoration:line-through;color:rgba(201,168,106,.7)}
  .dot{position:absolute;left:-20px;top:8px;width:10px;height:10px;background:linear-gradient(135deg,rgba(201,168,106,.92),rgba(170,140,84,.85));border-radius:50%;box-shadow:0 0 12px rgba(201,168,106,.5),0 0 24px rgba(201,168,106,.35);will-change:filter,box-shadow}
  .dot::after{content:"";position:absolute;inset:-5px;border-radius:50%;border:1px dashed rgba(201,168,106,.45);opacity:.8;box-shadow:0 0 16px rgba(227,198,139,.26);transition:border-color var(--transition),box-shadow var(--transition),opacity var(--transition)}
  .dot::before{content:"";position:absolute;inset:1px;border-radius:inherit;background:radial-gradient(circle at 50% 50%,rgba(255,238,206,.85),rgba(201,168,106,.35));opacity:.9;filter:blur(.3px);pointer-events:none}
  .tlrow:not(.done) .dot{animation:dotBreathe 3.2s ease-in-out infinite}
  @keyframes dotBreathe{
    0%,100%{filter:brightness(.7);box-shadow:0 0 8px rgba(201,168,106,.45),0 0 20px rgba(201,168,106,.3)}
    50%{filter:brightness(1);box-shadow:0 0 14px rgba(201,168,106,.65),0 0 28px rgba(201,168,106,.38)}
  }
  .tlrow.done .dot{background:radial-gradient(circle at 50% 50%,rgba(198,250,222,.95),rgba(56,189,148,.85));box-shadow:0 0 16px rgba(56,189,148,.6),0 0 28px rgba(16,185,129,.45);filter:none;animation:none}
  .tlrow.done .dot::after{border-color:rgba(56,189,148,.45);opacity:.9;box-shadow:0 0 18px rgba(16,185,129,.35)}
  .ts{color:var(--text-dim);font:600 12px/1 'Inter','Noto Sans SC',sans-serif;margin-left:6px;letter-spacing:.12em;text-transform:uppercase}
  .item-actions{display:flex;gap:8px;justify-content:flex-end;align-items:center;flex-wrap:wrap}
  .item-actions form{margin:0}
  .item-actions .tip{margin-right:auto;color:var(--text-dim);font:600 11px/1 'Inter','Noto Sans SC',sans-serif;letter-spacing:.2em;text-transform:uppercase}
  .item-actions .status-tip{color:var(--gold-400)}
  .item-actions .note-tip{margin-right:0}
  .move-controls{display:flex;gap:6px}
  .move-controls.mobile{display:none}
  .err{background:rgba(209,75,75,.16);color:rgba(255,214,214,.92);border:1px solid rgba(209,75,75,.45);border-radius:16px;box-shadow:0 0 20px rgba(209,75,75,.24);padding:10px 14px}
  .flash-message{margin-bottom:12px;font:600 12px/1.4 'Inter','Noto Sans SC',sans-serif;letter-spacing:.16em;text-transform:uppercase}
  .shortcuts{margin-top:14px;color:var(--text-dim);font:600 11px/1.2 'Inter','Noto Sans SC',sans-serif;letter-spacing:.18em;text-transform:uppercase}
  .toast-container{position:fixed;left:50%;bottom:32px;transform:translateX(-50%);display:grid;gap:12px;z-index:140;pointer-events:none}
  .toast{display:flex;gap:12px;align-items:center;padding:14px 18px;border-radius:14px;background:rgba(15,19,22,.92);border:1px solid rgba(230,192,137,.32);box-shadow:0 20px 48px rgba(0,0,0,.55);color:var(--text-strong);min-width:260px;pointer-events:auto}
  .toast-message{font:600 12px/1.4 'Inter','Noto Sans SC',sans-serif;letter-spacing:.12em;text-transform:uppercase;color:var(--text-muted)}
  .modal-backdrop{position:fixed;inset:0;background:rgba(3,6,8,.76);backdrop-filter:blur(16px);display:none;align-items:center;justify-content:center;padding:16px;z-index:120}
  .modal-panel{background:linear-gradient(150deg,rgba(15,19,22,.92),rgba(10,12,14,.94));border:1px solid rgba(201,168,106,.32);border-radius:22px;box-shadow:0 32px 64px rgba(0,0,0,.68),0 0 34px rgba(201,168,106,.16);padding:18px;max-width:520px;width:100%;display:grid;gap:14px;position:relative}
  .modal-panel::before{content:"";position:absolute;inset:10px;border-radius:18px;border:1px dashed rgba(201,168,106,.24);opacity:.85;pointer-events:none;box-shadow:inset 0 0 22px rgba(201,168,106,.08)}
  .modal-header{display:flex;justify-content:space-between;align-items:center;gap:12px}
  .modal-title{font:600 16px/1 'Cinzel','Noto Serif SC',serif;color:var(--gold-400);letter-spacing:.18em;text-transform:uppercase;text-shadow:0 0 18px rgba(227,198,139,.24)}
  .modal-list{display:grid;gap:10px}
  .modal-form{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
  .modal-input{flex:1;min-width:200px;padding:10px 12px;border-radius:12px;border:1px solid rgba(201,168,106,.32);background:rgba(15,19,22,.86);color:var(--text-strong);letter-spacing:.08em}
  .modal-input::placeholder{color:var(--text-dim)}
  .modal-count{color:var(--text-dim);font:600 12px/1 'Inter','Noto Sans SC',sans-serif;letter-spacing:.16em;text-transform:uppercase}
  .mindmap-view{display:grid;gap:24px}
  .mindmap-header{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap}
  .mindmap-header h2{margin:0;font:600 20px/1 'Cinzel','Noto Serif SC',serif;color:var(--gold-400);letter-spacing:.16em;text-transform:uppercase;text-shadow:0 0 20px rgba(227,198,139,.24)}
  .mindmap-header-meta{margin-top:6px;color:var(--text-muted);font:13px/1.6 'Inter','Noto Sans SC',sans-serif;letter-spacing:.1em}
  .mindmap-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
  .mindmap-search{display:flex;align-items:center;gap:10px;padding:12px 16px;border-radius:16px;border:1px solid rgba(201,168,106,.28);background:rgba(15,19,22,.82);box-shadow:inset 0 0 22px rgba(0,0,0,.4);max-width:460px}
  .mindmap-search input{flex:1;border:0;background:transparent;color:var(--text-strong);font:15px/1.4 'Inter','Noto Sans SC',sans-serif;outline:none}
  .mindmap-search span{font-size:18px}
  .mindmap-grid{display:grid;gap:18px;grid-template-columns:repeat(auto-fit,minmax(min(320px,100%),1fr));justify-content:center}
  .mindmap-card{position:relative;display:flex;flex-direction:column;gap:14px;min-height:240px;min-width:0;width:100%;padding:20px;border-radius:20px;background:linear-gradient(180deg,rgba(21,26,30,.9),rgba(12,16,18,.94));border:1px solid rgba(201,168,106,.28);box-shadow:0 20px 48px rgba(0,0,0,.55);transition:transform var(--transition),box-shadow var(--transition),border-color var(--transition)}
  .mindmap-card:hover{transform:translateY(-4px);border-color:rgba(201,168,106,.45);box-shadow:0 24px 60px rgba(0,0,0,.6)}
  .mindmap-card h3{margin:0;font:600 18px/1.4 'Cinzel','Noto Serif SC',serif;color:var(--gold-400);letter-spacing:.1em;text-transform:uppercase;overflow-wrap:anywhere;hyphens:auto}
  .mindmap-card-meta{color:var(--text-muted);font:12px/1.6 'Inter','Noto Sans SC',sans-serif;letter-spacing:.08em}
  .mindmap-card pre{margin:0;background:rgba(15,19,22,.85);border:1px solid rgba(201,168,106,.24);padding:12px;border-radius:14px;max-height:150px;overflow:auto;font:12px/1.6 'JetBrains Mono','Fira Code',monospace;color:var(--text-strong);box-shadow:inset 0 0 18px rgba(0,0,0,.45);white-space:pre-wrap;word-break:break-word;overflow-wrap:anywhere}
  .mindmap-card-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:auto}
  .mindmap-empty{padding:40px;border:1px dashed rgba(201,168,106,.32);border-radius:20px;text-align:center;color:var(--text-muted);background:rgba(15,19,22,.82)}
  .mindmap-empty strong{display:block;margin-bottom:8px;color:var(--gold-400);font-size:16px;letter-spacing:.12em;text-transform:uppercase}
  .mindmap-import-backdrop{position:fixed;inset:0;display:none;align-items:center;justify-content:center;padding:24px;background:rgba(6,8,10,.78);backdrop-filter:blur(16px);z-index:160}
  .mindmap-import-backdrop[data-open="true"]{display:flex}
  .mindmap-import-panel{background:linear-gradient(160deg,rgba(21,26,30,.92),rgba(12,16,18,.94));border:1px solid rgba(201,168,106,.34);border-radius:24px;box-shadow:0 32px 64px rgba(0,0,0,.68),0 0 30px rgba(227,198,139,.18);padding:24px;max-width:420px;width:100%;display:grid;gap:16px;position:relative}
  .mindmap-import-panel::before{content:"";position:absolute;inset:12px;border-radius:18px;border:1px dashed rgba(201,168,106,.24);opacity:.7;pointer-events:none}
  .mindmap-import-panel h2{margin:0;font:600 18px/1.3 'Cinzel','Noto Serif SC',serif;color:var(--gold-400);letter-spacing:.14em;text-transform:uppercase;text-align:center}
  .mindmap-import-panel p{margin:0;color:var(--text-dim);font:12px/1.6 'Inter','Noto Sans SC',sans-serif;text-align:center;letter-spacing:.08em}
  .mindmap-import-panel .mode-buttons{display:grid;gap:10px}
  .mindmap-import-panel .mode-buttons button{padding:12px 16px;border-radius:16px;border:1px solid rgba(227,198,139,.6);background:linear-gradient(135deg,rgba(227,198,139,.82),rgba(170,140,84,.58));color:#1b1306;font:600 12px/1 'Inter','Noto Sans SC',sans-serif;text-transform:uppercase;letter-spacing:.14em;cursor:pointer;transition:transform var(--transition),box-shadow var(--transition),border-color var(--transition)}
  .mindmap-import-panel .mode-buttons button:hover{transform:translateY(-2px);box-shadow:0 18px 36px rgba(227,198,139,.3),0 0 28px rgba(227,198,139,.24)}
  .mindmap-import-panel .mode-buttons button:disabled{opacity:.45;cursor:not-allowed;transform:none;box-shadow:none}
  .mindmap-import-panel label{display:flex;flex-direction:column;gap:6px;font:600 12px/1 'Inter','Noto Sans SC',sans-serif;letter-spacing:.14em;text-transform:uppercase;color:var(--text-muted)}
  .mindmap-import-panel select{padding:10px 12px;border-radius:12px;border:1px solid rgba(201,168,106,.32);background:rgba(15,19,22,.86);color:var(--text-strong);font:600 13px/1.2 'Inter','Noto Sans SC',sans-serif}
  .mindmap-import-panel .import-tip{color:var(--text-dim);font:12px/1.5 'Inter','Noto Sans SC',sans-serif;text-align:left}
  .mindmap-import-panel .actions{display:flex;justify-content:flex-end;gap:10px}
  .mindmap-import-panel .actions button{padding:10px 16px;border-radius:12px;border:1px solid rgba(201,168,106,.36);background:rgba(21,26,30,.82);color:var(--gold-400);font:600 12px/1 'Inter','Noto Sans SC',sans-serif;text-transform:uppercase;letter-spacing:.14em;cursor:pointer;transition:transform var(--transition),box-shadow var(--transition)}
  .mindmap-import-panel .actions button:hover{transform:translateY(-2px);box-shadow:0 12px 26px rgba(227,198,139,.22)}
  .attachment-preview-backdrop{position:fixed;inset:0;display:none;align-items:center;justify-content:center;padding:20px;background:rgba(5,7,10,.78);backdrop-filter:blur(18px);z-index:260}
  .attachment-preview-backdrop[data-open="true"]{display:flex}
  .attachment-preview-panel{width:min(960px,calc(100vw - 32px));max-height:min(90vh,96svh);background:linear-gradient(165deg,rgba(21,26,30,.95),rgba(12,16,18,.92));border:1px solid rgba(201,168,106,.34);border-radius:24px;box-shadow:0 32px 64px rgba(0,0,0,.7),0 0 34px rgba(227,198,139,.2);padding:20px;display:flex;flex-direction:column;gap:16px;position:relative}
  .attachment-preview-header{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}
  .attachment-preview-title{margin:0;font:600 18px/1.2 'Cinzel','Noto Serif SC',serif;color:var(--gold-400);letter-spacing:.16em;text-transform:uppercase}
  .attachment-preview-meta{color:var(--text-muted);font:12px/1.5 'Inter','Noto Sans SC',sans-serif;letter-spacing:.1em}
  .attachment-preview-close{border:1px solid rgba(201,168,106,.32);background:rgba(15,19,22,.8);color:var(--gold-400);border-radius:50%;width:36px;height:36px;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:18px;transition:border-color var(--transition),transform var(--transition)}
  .attachment-preview-close:hover{border-color:rgba(227,198,139,.6);transform:translateY(-1px)}
  .attachment-preview-body{position:relative;flex:1 1 auto;min-height:220px;padding:16px;border-radius:18px;border:1px solid rgba(201,168,106,.26);background:rgba(12,16,18,.82);box-shadow:inset 0 0 22px rgba(0,0,0,.45);overflow:auto}
  .attachment-preview-body img,.attachment-preview-body video,.attachment-preview-body iframe{display:block;width:100%;height:100%;max-height:70vh;border-radius:14px;background:#050607}
  .attachment-preview-body video{background:#000}
  .attachment-preview-body iframe{border:0;min-height:60vh}
  .attachment-preview-loading{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:var(--text-muted);font:14px/1.6 'Inter','Noto Sans SC',sans-serif;letter-spacing:.08em}
  .attachment-preview-error{color:#fca5a5;font:14px/1.6 'Inter','Noto Sans SC',sans-serif;padding:18px;text-align:center}
  .attachment-preview-list{list-style:none;margin:0;padding:0;display:grid;gap:8px}
  .attachment-preview-list li{padding:10px 12px;border-radius:12px;border:1px solid rgba(201,168,106,.28);background:rgba(12,16,18,.78);font:13px/1.5 'Inter','Noto Sans SC',sans-serif;letter-spacing:.04em;color:var(--text-strong);display:flex;justify-content:space-between;gap:12px;align-items:center}
  .attachment-preview-list li .entry-size{color:var(--text-muted);font-size:12px;letter-spacing:.08em}
  .attachment-preview-tree{list-style:none;margin:0;padding:0;display:grid;gap:6px}
  .attachment-preview-tree ul{list-style:none;margin:6px 0 0;padding-left:18px;display:grid;gap:6px}
  .attachment-preview-tree summary{cursor:pointer;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 12px;border-radius:12px;border:1px solid rgba(201,168,106,.28);background:rgba(12,16,18,.78);font:600 13px/1.4 'Inter','Noto Sans SC',sans-serif;letter-spacing:.08em;color:var(--text-strong)}
  .attachment-preview-tree summary::-webkit-details-marker{display:none}
  .attachment-preview-tree .tree-entry{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 12px;border-radius:12px;border:1px solid rgba(201,168,106,.26);background:rgba(12,16,18,.78);font:13px/1.5 'Inter','Noto Sans SC',sans-serif;letter-spacing:.04em;color:var(--text-strong)}
  .attachment-preview-tree .entry-label{display:flex;align-items:center;gap:8px;min-width:0}
  .attachment-preview-tree .entry-size{color:var(--text-muted);font-size:12px;letter-spacing:.08em}
  .attachment-preview-tree summary .entry-size{font-weight:500}
  .attachment-docx{color:var(--text-strong);font:400 14px/1.75 'Noto Sans SC','Inter',sans-serif;display:grid;gap:14px}
  .attachment-docx h1,.attachment-docx h2,.attachment-docx h3,.attachment-docx h4{color:var(--gold-400);margin:12px 0 6px;font-weight:600}
  .attachment-docx table{width:100%;border-collapse:collapse;margin:12px 0;border:1px solid rgba(201,168,106,.24)}
  .attachment-docx th,.attachment-docx td{border:1px solid rgba(201,168,106,.24);padding:6px 8px}
  .attachment-docx a{color:var(--gold-400)}
  .attachment-excel{display:grid;gap:16px}
  .attachment-excel .excel-sheet{border:1px solid rgba(201,168,106,.28);border-radius:14px;background:rgba(12,16,18,.78);padding:14px;box-shadow:inset 0 0 18px rgba(0,0,0,.36)}
  .attachment-excel .excel-sheet h3{margin:0 0 10px;font:600 14px/1.4 'Inter','Noto Sans SC',sans-serif;color:var(--gold-400);letter-spacing:.1em;text-transform:uppercase}
  .attachment-excel .excel-table{overflow:auto;border-radius:10px;border:1px solid rgba(201,168,106,.24)}
  .attachment-excel table{width:100%;border-collapse:collapse;min-width:320px;font:13px/1.45 'Inter','Noto Sans SC',sans-serif;color:var(--text-strong);background:rgba(12,16,18,.88)}
  .attachment-excel th,.attachment-excel td{border:1px solid rgba(201,168,106,.2);padding:6px 8px;text-align:left}
  .attachment-excel tbody tr:nth-child(even){background:rgba(201,168,106,.08)}
  .attachment-preview-footer{display:flex;justify-content:flex-end;gap:10px;flex-wrap:wrap}
  .attachment-preview-download{display:inline-flex;align-items:center;justify-content:center;padding:10px 16px;border-radius:12px;border:1px solid rgba(201,168,106,.36);background:rgba(15,19,22,.85);color:var(--gold-400);font:600 12px/1 'Inter','Noto Sans SC',sans-serif;letter-spacing:.14em;text-transform:uppercase;cursor:pointer;text-decoration:none;transition:transform var(--transition),box-shadow var(--transition)}
  .attachment-preview-download:hover{transform:translateY(-1px);box-shadow:0 16px 32px rgba(227,198,139,.25)}
  body[data-density='compact'] .item-title{font-size:15px}
  body[data-density='compact'] .item-desc{font-size:12px;opacity:.78}
  body[data-density='compact'] .item-meta{margin-top:4px}
  body[data-density='compact'] .btn-detail .btn-label{display:none}
  body[data-density='compact'] .item-actions{gap:6px}
  @media (max-width:1200px){
    .items{grid-template-columns:repeat(2,minmax(0,1fr))}
  }
  @media (max-width:920px){
    .app{display:block}
    .sidebar{position:static;height:auto;overflow:visible;border-right:0;border-bottom:1px solid rgba(201,168,106,.18);border-radius:0 0 22px 22px;box-shadow:0 18px 40px rgba(0,0,0,.55);width:auto}
    .main{margin-left:0;width:100%;max-width:100%}
    .cat-list{display:grid;grid-template-columns:1fr 1fr;gap:10px}
    .items{grid-template-columns:1fr}
    .item-actions span.tip{display:none}
    .move-controls.desktop{display:none}
    .move-controls.mobile{display:flex}
    .mindmap-grid{grid-template-columns:1fr}
    .mindmap-actions{width:100%;justify-content:flex-start}
  }
  @media (max-width:720px){
    .toolbar{flex-direction:column;align-items:stretch}
    .search{width:100%}
    .actions-row{width:100%;flex-direction:column;align-items:stretch}
    .actions-row .btn{width:100%;justify-content:center}
    .mindmap-actions{flex-direction:column;align-items:stretch}
    .mindmap-actions .btn{width:100%;justify-content:center}
    .mindmap-search{width:100%}
  }
  @media (max-width:520px){
    .mindmap-card{padding:16px}
    .mindmap-card h3{font-size:16px;letter-spacing:.08em}
    .mindmap-header{gap:12px}
    .mindmap-header h2{font-size:18px}
  }
</style>
</head>
<body>
  <div class="scanlines" aria-hidden="true"></div>
  <div class="app">
  <aside class="sidebar" role="region" aria-label="侧边栏导航">
    <div class="brand">
      <div class="logo" aria-hidden="true"></div>
      <h1>自适应备忘录 · Memo</h1>
    </div>
    <div class="controls">
      <div class="dropdown" id="new-entry-dropdown">
        <button class="btn btn-primary dropdown-toggle" id="btn-new-menu" type="button" aria-haspopup="true" aria-expanded="false">＋ 新建</button>
        <div class="dropdown-menu" id="new-menu" role="menu" aria-labelledby="btn-new-menu" data-open="false">
          <a href="?view=new" role="menuitem">新建备忘录</a>
          <a href="?view=map_edit" role="menuitem">新建导图</a>
        </div>
      </div>
      <button class="btn btn-outline" id="btn-cat-mgr">分类管理</button>
    </div>
    <div class="section-title">分类 · Categories</div>
    <div class="cat-list" id="cat-list">
      <a class="cat <?php echo ($cat==='all'?'active':''); ?>" href="?cat=all&q=<?php echo urlencode($q); ?>">
        <span class="name">全部 · All</span><span class="count"><?php echo $all_total; ?></span>
      </a>
      <?php foreach ($cats as $c): ?>
      <a class="cat <?php echo ($cat===(string)$c['id']?'active':''); ?>" data-id="<?php echo $c['id']; ?>" href="?cat=<?php echo $c['id']; ?>&q=<?php echo urlencode($q); ?>">
        <span class="name"><?php echo h($c['name']); ?></span>
        <span class="count"><?php echo (int)($counts[$c['id']] ?? 0); ?></span>
      </a>
      <?php endforeach; ?>
      <a class="cat <?php echo ($cat==='mindmaps'?'active':''); ?>" data-id="mindmaps" href="?cat=mindmaps&q=<?php echo urlencode($q); ?>">
        <span class="name">思维导图</span>
        <span class="count"><?php echo $mindmap_total; ?></span>
      </a>
    </div>
    <div class="footer">
      <div>✅ 勾选完成</div>
      <div>↔ 左右按钮调整顺序（桌面）</div>
      <div>↑↓ 按钮调整顺序（移动端）</div>
      <div>⤓ 导入 / 导出 JSON / CSV</div>
    </div>
  </aside>
  <main class="main">
    <?php if (!empty($_SESSION['flash'])): ?>
      <div class="err flash-message"><?php echo h($_SESSION['flash']); unset($_SESSION['flash']); ?></div>
    <?php endif; ?>
    <?php if ($isMindmapCategory): ?>
      <section class="mindmap-view" aria-label="思维导图库">
        <div class="mindmap-header">
          <div>
            <h2>思维导图库</h2>
            <div class="mindmap-header-meta">集中管理所有导图，支持多版本协作、导入导出与快速检索。</div>
          </div>
          <div class="mindmap-actions">
            <button class="btn btn-outline btn-small" type="button" id="btn-import-map">导入导图</button>
            <button class="btn btn-outline btn-small" type="button" id="btn-export-maps">导出全部</button>
            <a class="btn btn-primary btn-small" href="?view=map_edit">＋ 新建导图</a>
          </div>
        </div>
        <div class="mindmap-search" role="search">
          <span aria-hidden="true">🔍</span>
          <input id="mindmap-search" placeholder="搜索标题或大纲关键字" value="<?php echo h($q); ?>" autocomplete="off">
        </div>
        <?php if (!$mindmapsAll): ?>
          <div class="mindmap-empty">
            <strong>暂时还没有导图。</strong><br>点击右上角「新建导图」开始构建第一个思维导图。
          </div>
        <?php else: ?>
          <div class="mindmap-empty mindmap-empty-filter" id="mindmap-empty-filter" style="display:none;">
            <strong>未找到匹配的导图。</strong><br>尝试修改搜索关键字或清除筛选。
          </div>
          <div class="mindmap-grid" id="mindmap-grid">
            <?php foreach ($mindmapsAll as $m): $outlineDisplay = (string)($m['outline'] ?? ''); ?>
              <article class="mindmap-card" data-title="<?php echo h($m['title']); ?>" data-outline="<?php echo h(str_replace("\n",' ',$outlineDisplay)); ?>">
                <div>
                  <h3><?php echo h($m['title']); ?></h3>
                  <div class="mindmap-card-meta">更新：<?php echo dt((int)$m['updated_at']); ?> · 创建：<?php echo dt((int)$m['created_at']); ?></div>
                </div>
                <?php if ($outlineDisplay !== ''): ?>
                  <pre><?php echo h($outlineDisplay); ?></pre>
                <?php else: ?>
                  <pre>（暂无内容）</pre>
                <?php endif; ?>
                <div class="mindmap-card-actions">
                  <a class="btn btn-primary btn-small" href="?view=map_edit&id=<?php echo $m['id']; ?>">编辑</a>
                  <form method="post" onsubmit="return confirm('确认删除该导图？');">
                    <input type="hidden" name="action" value="delete_mindmap">
                    <input type="hidden" name="id" value="<?php echo $m['id']; ?>">
                    <button class="btn btn-danger btn-small" type="submit">删除</button>
                  </form>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>
      <input id="mind-import-file" type="file" accept="application/json" hidden>
      <div class="mindmap-import-backdrop" id="map-import-modal" data-open="false" role="dialog" aria-modal="true" aria-labelledby="import-modal-title">
        <div class="mindmap-import-panel">
          <h2 id="import-modal-title">导入导图</h2>
          <p>选择导入方式以处理文件：<strong id="import-file-name">未选择文件</strong></p>
          <div class="mode-buttons">
            <button type="button" data-mode="replace" data-requires-target="true">覆盖现有导图</button>
            <button type="button" data-mode="append" data-requires-target="true">导入为根节点</button>
            <button type="button" data-mode="new">导入为新导图</button>
          </div>
          <label for="import-target-select">目标导图（覆盖/追加时选择）
            <select id="import-target-select">
              <option value="">请选择</option>
              <?php foreach ($mindmapsAll as $m): ?>
                <option value="<?= $m['id']; ?>"><?= h($m['title']); ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <p class="import-tip">导入文件将自动调整为向右展开结构。如需插入到特定节点，可在编辑界面使用导入功能。</p>
          <div class="actions">
            <button type="button" data-action="cancel">取消</button>
          </div>
        </div>
      </div>
      <script type="application/json" id="mind-maps-data"><?php echo json_encode(array_map(fn($m)=>[
        'id'=>$m['id'],
        'title'=>$m['title'],
        'content'=>$m['content'],
        'created_at'=>$m['created_at'],
        'updated_at'=>$m['updated_at'],
      ], $mindmapsAll), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?></script>
    <?php else: ?>
      <div class="toolbar">
        <form class="search" method="get" style="flex:1">
          <input type="hidden" name="cat" value="<?php echo h((string)$cat); ?>">
          <input name="q" value="<?php echo h($q); ?>" placeholder="搜索标题/内容 · Search"/>
          <button>搜索</button>
        </form>
        <div class="actions-row">
          <div class="density-toggle" role="group" aria-label="显示密度">
            <button class="btn btn-ghost btn-small density-option" type="button" data-density="comfortable">舒适</button>
            <button class="btn btn-ghost btn-small density-option" type="button" data-density="compact">紧凑</button>
          </div>
          <button class="btn btn-outline btn-small" type="button" id="btn-import-items">导入 JSON</button>
          <a class="btn btn-outline btn-small" href="?cat=<?php echo h((string)$cat); ?>&q=<?php echo urlencode($q); ?>&export=json">导出 JSON</a>
          <a class="btn btn-outline btn-small" href="?cat=<?php echo h((string)$cat); ?>&q=<?php echo urlencode($q); ?>&export=csv">导出 CSV</a>
        </div>
      </div>
      <input id="memo-import-input" type="file" accept="application/json" hidden>
      <div class="items" id="items">
        <?php if (!$items): ?>
          <div class="item item-empty">没有条目 · No items</div>
        <?php endif; ?>
        <?php foreach ($items as $it): ?>
          <?php
            $steps_time=get_steps_by_time((int)$it['id']);
            $attachments=attachments_for_item((int)$it['id']);
            $titlePlain=(string)$it['title'];
            $titleHtml = $q!=='' ? highlight_text($titlePlain, $q) : h($titlePlain);
            $descRaw = (string)$it['description'];
            $descHtml = $descRaw!=='' ? nl2br($q!=='' ? highlight_text($descRaw, $q) : h($descRaw)) : '';
            $catLabel = $it['category_id'] ? ($categoryNames[(int)$it['category_id']] ?? '未分类') : '未分类';
          ?>
          <article class="item <?php echo $it['done']?'done':''; ?>" data-id="<?php echo $it['id']; ?>" data-category-id="<?php echo $it['category_id']!==null?(int)$it['category_id']:''; ?>" data-title="<?php echo h($titlePlain); ?>" data-updated="<?php echo dt((int)$it['updated_at']); ?>">
            <div class="item-head" style="display:flex;gap:8px;align-items:flex-start">
              <form method="post" class="form-toggle-item" onsubmit="return false" style="margin:0">
                <input type="hidden" name="action" value="toggle_done">
                <input type="hidden" name="id" value="<?php echo $it['id']; ?>">
                <input type="checkbox" class="item-toggle" <?php echo $it['done']?'checked':''; ?> title="完成">
              </form>
              <div style="flex:1">
                <div class="item-title"><?php echo $titleHtml; ?></div>
                <?php if ($descHtml!==''): ?>
                  <div class="item-desc"><?php echo $descHtml; ?></div>
                <?php endif; ?>
                <?php if ($steps_time): ?>
                <div class="tinyline" aria-label="时间轴">
                  <?php foreach($steps_time as $s): ?>
                    <div class="tlrow <?php echo $s['done']?'done':''; ?>">
                      <span class="dot"></span>
                      <form method="post" class="form-toggle-step" onsubmit="return false" style="margin:0;display:inline-block">
                        <input type="hidden" name="action" value="toggle_step">
                        <input type="hidden" name="id" value="<?php echo $s['id']; ?>">
                        <input type="checkbox" class="step-toggle" <?php echo $s['done']?'checked':''; ?> title="完成">
                      </form>
                      <span class="step-title"><?php echo h($s['title']); ?></span>
                      <span class="ts"><?php echo dt((int)$s['created_at']); ?></span>
                    </div>
                  <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <div class="meta meta-inline item-meta" aria-label="分类与更新时间">
                  <div class="meta-left">
                    <span class="badge"><?php echo h($catLabel); ?></span>
                    <?php if(!empty($attachments)): ?>
                      <span class="badge attachment-badge">📎 <?php echo count($attachments); ?></span>
                    <?php endif; ?>
                  </div>
                  <div class="meta-right">
                    <span class="item-time js-updated">更新 <?php echo dt((int)$it['updated_at']); ?></span>
                  </div>
                </div>
              </div>
            </div>
            <div class="item-actions">
              <span class="tip status-tip"><?php echo $it['done'] ? '已刻印完成' : '待刻录'; ?></span>
              <span class="tip note-tip">↔ 按钮调整排序</span>
              <div class="move-controls desktop">
                <button class="btn btn-ghost btn-small" onclick="moveCard(<?php echo $it['id']; ?>,'left')">← 左移</button>
                <button class="btn btn-ghost btn-small" onclick="moveCard(<?php echo $it['id']; ?>,'right')">→ 右移</button>
              </div>
              <div class="move-controls mobile">
                <button class="btn btn-ghost btn-small" onclick="moveCard(<?php echo $it['id']; ?>,'up')">↑ 上移</button>
                <button class="btn btn-ghost btn-small" onclick="moveCard(<?php echo $it['id']; ?>,'down')">↓ 下移</button>
              </div>
              <a class="btn btn-ghost btn-small btn-detail" href="?view=item&id=<?php echo $it['id']; ?>">
                <span class="btn-icon" aria-hidden="true">✦</span>
                <span class="btn-label">详情</span>
              </a>
              <form method="post" class="form-delete-item" style="margin:0">
                <input type="hidden" name="action" value="delete_item">
                <input type="hidden" name="id" value="<?php echo $it['id']; ?>">
                <button class="btn btn-danger btn-small" type="submit">删除</button>
              </form>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
      <div class="shortcuts">
        快捷键：<span class="kbd">/</span> 聚焦搜索。
      </div>
    <?php endif; ?>
  </main>
</div>
<div id="toast-container" class="toast-container" aria-live="assertive" aria-atomic="true"></div>
<div id="cat-modal" class="modal-backdrop">
  <div class="modal-panel">
    <div class="modal-header">
      <div class="modal-title">分类管理</div>
      <button class="btn btn-outline btn-small" onclick="closeCatModal()">关闭</button>
    </div>
    <div id="cat-rows" class="modal-list"></div>
    <form onsubmit="return addCat(event)" class="modal-form">
      <input type="text" id="new-cat-name" class="modal-input" placeholder="新增分类名" required>
      <button class="btn btn-primary">新增</button>
    </form>
  </div>
</div>
<script>
const $=s=>document.querySelector(s); const $$=s=>Array.from(document.querySelectorAll(s));
const throttle=(fn,ms)=>{let t=0;return (...a)=>{const n=Date.now();if(n-t>ms){t=n;fn(...a);} }};
const newMenuToggle=document.getElementById('btn-new-menu');
const newMenu=document.getElementById('new-menu');
if(newMenuToggle && newMenu){
  const openMenu=()=>{ newMenu.dataset.open='true'; newMenuToggle.setAttribute('aria-expanded','true'); };
  const closeMenu=()=>{ newMenu.dataset.open='false'; newMenuToggle.setAttribute('aria-expanded','false'); };
  newMenuToggle.addEventListener('click',e=>{ e.preventDefault(); const isOpen=newMenu.dataset.open==='true'; if(isOpen){ closeMenu(); }else{ openMenu(); const first=newMenu.querySelector('a'); if(first){ first.focus(); } }});
  newMenuToggle.addEventListener('keydown',e=>{ if(e.key==='ArrowDown'){ e.preventDefault(); openMenu(); const first=newMenu.querySelector('a'); if(first){ first.focus(); } }});
  newMenu.addEventListener('keydown',e=>{ if(e.key==='Escape'){ closeMenu(); newMenuToggle.focus(); }});
  document.addEventListener('click',e=>{ if(!newMenu.contains(e.target) && e.target!==newMenuToggle){ closeMenu(); }});
}
window.addEventListener('keydown',e=>{
  if(e.key==='/' && !/input|textarea|select/i.test(document.activeElement.tagName)){
    e.preventDefault(); const q=document.querySelector('input[name="q"]'); if(q){ q.focus(); q.select(); }
  }
});
<?php if ($isMindmapCategory): ?>
(function(){
  const mapsDataElement=document.getElementById('mind-maps-data');
  let mapsData=[];
  try{
    mapsData=mapsDataElement ? JSON.parse(mapsDataElement.textContent||'[]') : [];
  }catch(_){ mapsData=[]; }
  const mapsById=new Map((mapsData||[]).map(item=>[String(item.id), item]));
  const mindImportInput=document.getElementById('mind-import-file');
  const mindImportButton=document.getElementById('btn-import-map');
  const mindExportButton=document.getElementById('btn-export-maps');
  const mindImportModal=document.getElementById('map-import-modal');
  const mindImportFileName=document.getElementById('import-file-name');
  const mindImportTargetSelect=document.getElementById('import-target-select');
  let pendingImport=null;
  let pendingImportName='';
  if(mindImportTargetSelect){
    if(mapsData.length){
      mindImportTargetSelect.disabled=false;
      mindImportTargetSelect.value=mindImportTargetSelect.value || String(mapsData[0].id);
    }else{
      mindImportTargetSelect.disabled=true;
    }
  }
  if(mindImportModal){
    const targetButtons=Array.from(mindImportModal.querySelectorAll('[data-requires-target="true"]'));
    if(!mapsData.length){ targetButtons.forEach(btn=>btn.disabled=true); }
  }
  function openImportModal(){
    if(!mindImportModal) return;
    mindImportModal.dataset.open='true';
    if(mindImportFileName){ mindImportFileName.textContent=pendingImportName || '未选择文件'; }
  }
  function closeImportModal(){
    if(mindImportModal){ mindImportModal.dataset.open='false'; }
    pendingImport=null;
    pendingImportName='';
    if(mindImportInput){ mindImportInput.value=''; }
  }
  function resolveImportTitle(data,fallback){
    const metaName=data?.meta && typeof data.meta.name==='string' ? data.meta.name.trim() : '';
    const topicName=data?.data && typeof data.data.topic==='string' ? data.data.topic.trim() : '';
    if(metaName) return metaName;
    if(topicName) return topicName;
    if(typeof fallback==='string' && fallback.trim()) return fallback.trim();
    return '未命名导图';
  }
  function enforceRightOrientationFromRoot(node, depth=0){
    if(!node || typeof node!=='object') return;
    node.direction=depth===0?'center':'right';
    if(Array.isArray(node.children)){
      node.children=node.children.map(child=>{
        if(child && typeof child==='object'){ enforceRightOrientationFromRoot(child, depth+1); return child; }
        return null;
      }).filter(Boolean);
    }else{
      node.children=[];
    }
  }
  function cloneImportSubtree(source){
    if(!source || typeof source!=='object') return null;
    const cloned={
      id:'node-'+Math.random().toString(36).slice(2,10),
      topic:typeof source.topic==='string' && source.topic.trim()?source.topic.trim():'导入节点',
      data:source.data?JSON.parse(JSON.stringify(source.data)):{},
      expanded:source.expanded!==false,
      direction:'right',
      children:[],
    };
    if(source.meta){ cloned.meta=JSON.parse(JSON.stringify(source.meta)); }
    if(source.style){ cloned.style=JSON.parse(JSON.stringify(source.style)); }
    if(Array.isArray(source.children)){
      cloned.children=source.children.map(child=>cloneImportSubtree(child)).filter(Boolean);
    }
    return cloned;
  }
  async function saveMindmapRequest(id,title,data){
    const fd=new FormData();
    fd.append('action','save_mindmap');
    fd.append('id', String(id||0));
    fd.append('title', title || '未命名导图');
    fd.append('content', JSON.stringify(data));
    const res=await fetch(location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'fetch'}});
    if(!res.ok) throw new Error('网络异常');
    const json=await res.json();
    if(!json.ok) throw new Error(json.error||'保存失败');
    return json;
  }
  async function handleImportMode(mode){
    if(!pendingImport){
      alert('请先选择导入文件');
      return;
    }
    const requiresTarget=mode==='replace' || mode==='append';
    const targetId=mindImportTargetSelect ? mindImportTargetSelect.value : '';
    if(requiresTarget){
      if(!targetId){ alert('请选择目标导图'); return; }
      if(!mapsById.has(String(targetId))){ alert('目标导图不存在'); return; }
    }
    try{
      if(mode==='new'){
        const payload=JSON.parse(JSON.stringify(pendingImport));
        if(payload && payload.data) enforceRightOrientationFromRoot(payload.data);
        const title=resolveImportTitle(payload,'');
        await saveMindmapRequest(0,title,payload);
      }else if(mode==='replace'){
        const target=mapsById.get(String(targetId));
        if(!target) throw new Error('目标导图不存在');
        const payload=JSON.parse(JSON.stringify(pendingImport));
        if(payload && payload.data) enforceRightOrientationFromRoot(payload.data);
        const title=resolveImportTitle(payload,target.title||'');
        await saveMindmapRequest(target.id,title,payload);
      }else if(mode==='append'){
        const target=mapsById.get(String(targetId));
        if(!target) throw new Error('目标导图不存在');
        let base;
        try{ base=JSON.parse(target.content); }
        catch(_){ throw new Error('目标导图内容无法解析'); }
        if(!base || typeof base!=='object' || !base.data){ throw new Error('目标导图缺少数据'); }
        const subtree=cloneImportSubtree(pendingImport.data || pendingImport);
        if(!subtree){ throw new Error('导入文件中缺少节点数据'); }
        if(!Array.isArray(base.data.children)){ base.data.children=[]; }
        base.data.children.push(subtree);
        enforceRightOrientationFromRoot(base.data);
        const title=target.title || resolveImportTitle(pendingImport,'');
        await saveMindmapRequest(target.id,title,base);
      }else{
        return;
      }
      alert('导入成功');
      closeImportModal();
      location.reload();
    }catch(err){
      alert(err instanceof Error ? err.message : '导入失败');
    }
  }
  function handleImportFile(event){
    const file=event.target.files && event.target.files[0];
    if(!file) return;
    const reader=new FileReader();
    reader.onload=evt=>{
      try{
        const json=JSON.parse(evt.target.result);
        if(!json || typeof json!=='object' || !json.data){ throw new Error('文件格式不兼容'); }
        pendingImport=json;
        pendingImportName=file.name;
        if(mindImportTargetSelect && mapsData.length){
          mindImportTargetSelect.disabled=false;
          if(!mindImportTargetSelect.value){ mindImportTargetSelect.value=String(mapsData[0].id); }
        }
        openImportModal();
      }catch(err){
        pendingImport=null;
        pendingImportName='';
        alert(err instanceof Error ? err.message : '无法解析导图文件');
      }finally{
        if(mindImportInput){ mindImportInput.value=''; }
      }
    };
    reader.onerror=()=>{
      pendingImport=null;
      pendingImportName='';
      alert('读取文件失败');
      if(mindImportInput){ mindImportInput.value=''; }
    };
    reader.readAsText(file,'utf-8');
  }
  function buildTimestamp(){
    const now=new Date();
    const pad=n=>String(n).padStart(2,'0');
    return `${now.getFullYear()}${pad(now.getMonth()+1)}${pad(now.getDate())}-${pad(now.getHours())}${pad(now.getMinutes())}${pad(now.getSeconds())}`;
  }
  function exportAllMaps(){
    if(!mapsData.length){
      alert('暂无导图可导出');
      return;
    }
    const payload=mapsData.map(map=>{
      let content;
      try{ content=JSON.parse(map.content); }
      catch(_){ content=map.content; }
      return {
        id: map.id,
        title: map.title,
        created_at: map.created_at,
        updated_at: map.updated_at,
        content,
      };
    });
    const blob=new Blob([JSON.stringify(payload,null,2)],{type:'application/json'});
    const url=URL.createObjectURL(blob);
    const a=document.createElement('a');
    a.href=url;
    a.download=`mindmaps-${buildTimestamp()}.json`;
    a.click();
    setTimeout(()=>URL.revokeObjectURL(url),1000);
  }
  if(mindImportButton && mindImportInput){
    mindImportButton.addEventListener('click',()=>{
      pendingImport=null;
      pendingImportName='';
      mindImportInput.click();
    });
  }
  if(mindImportInput){
    mindImportInput.addEventListener('change',handleImportFile);
  }
  if(mindExportButton){
    mindExportButton.addEventListener('click',exportAllMaps);
  }
  if(mindImportModal){
    mindImportModal.addEventListener('click',e=>{ if(e.target===mindImportModal){ closeImportModal(); }});
    mindImportModal.querySelectorAll('[data-mode]').forEach(btn=>{
      btn.addEventListener('click',e=>{ e.preventDefault(); handleImportMode(btn.dataset.mode); });
    });
    const cancelBtn=mindImportModal.querySelector('[data-action="cancel"]');
    if(cancelBtn){ cancelBtn.addEventListener('click',e=>{ e.preventDefault(); closeImportModal(); }); }
  }
  const mindSearchInput=document.getElementById('mindmap-search');
  const mindGrid=document.getElementById('mindmap-grid');
  const mindCards=mindGrid?Array.from(mindGrid.querySelectorAll('.mindmap-card')):[];
  const emptyFilter=document.getElementById('mindmap-empty-filter');
  const applyFilter=()=>{
    if(!mindSearchInput || !mindGrid) return;
    const q=mindSearchInput.value.trim().toLowerCase();
    let visibleCount=0;
    mindCards.forEach(card=>{
      const text=(card.dataset.title+' '+card.dataset.outline).toLowerCase();
      const match = q==='' || text.includes(q);
      card.style.display = match ? '' : 'none';
      if(match) visibleCount++;
    });
    if(emptyFilter){ emptyFilter.style.display = visibleCount===0 ? '' : 'none'; }
  };
  if(mindSearchInput){
    mindSearchInput.addEventListener('input',applyFilter);
    if(mindSearchInput.value.trim()!==''){ applyFilter(); }
  }
})();
<?php endif; ?>
const itemsContainer=document.getElementById('items');
const currentCategoryFilter=<?php echo json_encode((string)$cat); ?>;
const memoImportButton=document.getElementById('btn-import-items');
const memoImportInput=document.getElementById('memo-import-input');
const toastContainer=document.getElementById('toast-container');
const densityButtons=$$('.density-option');
const deleteForms=$$('.form-delete-item');
const DENSITY_KEY='memo-density';
function safeStorageGet(key){ try{ return window.localStorage.getItem(key); }catch(_){ return null; }}
function safeStorageSet(key,value){ try{ window.localStorage.setItem(key,value); }catch(_){ }}
function applyDensity(mode,{persist=true}={}){
  const value=mode==='compact'?'compact':'comfortable';
  document.body.dataset.density=value;
  densityButtons.forEach(btn=>{ btn.classList.toggle('active', btn.dataset.density===value); });
  if(persist){ safeStorageSet(DENSITY_KEY,value); }
}
const initialDensity=safeStorageGet(DENSITY_KEY);
applyDensity(initialDensity==='compact'?'compact':'comfortable',{persist:false});
densityButtons.forEach(btn=>{ btn.addEventListener('click',()=>applyDensity(btn.dataset.density)); });
function showToast(message, actions=[]){
  if(!toastContainer) return;
  const toast=document.createElement('div');
  toast.className='toast';
  const text=document.createElement('div');
  text.className='toast-message';
  text.textContent=message;
  toast.appendChild(text);
  actions.forEach(action=>{
    const btn=document.createElement('button');
    btn.type='button';
    btn.className='btn btn-ghost btn-small';
    btn.textContent=action && action.label ? action.label : '操作';
    btn.addEventListener('click',()=>{
      try{ if(action && typeof action.onClick==='function'){ action.onClick(); } }catch(_){ }
      toast.remove();
    });
    toast.appendChild(btn);
  });
  toastContainer.appendChild(toast);
  setTimeout(()=>{ if(toast.isConnected){ toast.remove(); } },5000);
}
async function undoDelete(token){
  if(!token) return;
  const fd=new FormData(); fd.append('action','undo_delete_item'); fd.append('token', token);
  try{
    const res=await fetch(location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'fetch'}});
    const data=await res.json().catch(()=>null);
    if(!res.ok || !data || !data.ok){ throw new Error((data && data.error) ? data.error : `撤销失败（${res.status}）`); }
    window.location.reload();
  }catch(err){
    alert(err instanceof Error ? err.message : '撤销失败');
  }
}
deleteForms.forEach(form=>{
  form.addEventListener('submit',ev=>{ ev.preventDefault(); handleDeleteForm(form); });
});
async function handleDeleteForm(form){
  if(form.dataset.loading==='1') return;
  form.dataset.loading='1';
  const fd=new FormData(form);
  const card=form.closest('article.item');
  const title=card ? (card.dataset.title || '备忘录') : '备忘录';
  try{
    const res=await fetch(location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'fetch'}});
    const data=await res.json().catch(()=>null);
    if(!res.ok || !data || !data.ok){
      const msg=(data && data.error) ? data.error : `删除失败（${res.status}）`;
      throw new Error(msg);
    }
    if(card){
      card.classList.add('item-removing');
      setTimeout(()=>{ if(card.isConnected){ card.remove(); ensureItemsEmptyState(); } },220);
    }
    showToast(`已删除 · ${title}`, data.undo_token ? [{label:'撤销', onClick:()=>undoDelete(data.undo_token)}] : []);
    try{
      const {cats,counts,total,mindmap_total}=await fetchCats();
      refreshSidebarCats(cats,counts,total,mindmap_total);
    }catch(_){ }
  }catch(err){
    alert(err instanceof Error ? err.message : '删除失败');
  }finally{
    form.dataset.loading='';
  }
}
if(memoImportButton && memoImportInput){
  const importButtonLabel=memoImportButton.textContent;
  memoImportButton.addEventListener('click',()=>{ memoImportInput.click(); });
  memoImportInput.addEventListener('change',async ()=>{
    const file=memoImportInput.files && memoImportInput.files[0];
    if(!file){ return; }
    const confirmMessage=`确定要导入“${file.name}”吗？导入的条目将追加到当前列表。`;
    if(!window.confirm(confirmMessage)){ memoImportInput.value=''; return; }
    memoImportButton.disabled=true;
    memoImportButton.textContent='导入中…';
    try{
      const fd=new FormData();
      fd.append('action','import_items');
      fd.append('file',file);
      const res=await fetch(window.location.pathname + window.location.search,{
        method:'POST',
        body:fd,
        headers:{'X-Requested-With':'XMLHttpRequest'}
      });
      const data=await res.json().catch(()=>null);
      if(!res.ok || !data || !data.ok){
        const errorMessage=(data && data.error) ? data.error : `导入失败（${res.status}）`;
        throw new Error(errorMessage);
      }
      const summaryParts=[`成功导入 ${data.imported ?? 0} 条备忘录`];
      if(data.skipped){ summaryParts.push(`跳过 ${data.skipped} 条`); }
      if(Array.isArray(data.created_categories) && data.created_categories.length){
        summaryParts.push(`新增分类：${data.created_categories.join('、')}`);
      }
      alert(summaryParts.join('，'));
      window.location.reload();
    }catch(err){
      alert(err instanceof Error ? err.message : '导入失败');
    }finally{
      memoImportInput.value='';
      memoImportButton.disabled=false;
      memoImportButton.textContent=importButtonLabel;
    }
  });
}
function ensureItemsEmptyState(){
  if(!itemsContainer) return;
  const hasCard=itemsContainer.querySelector('article.item');
  const placeholder=itemsContainer.querySelector('.item-empty');
  if(hasCard){
    if(placeholder) placeholder.remove();
    return;
  }
  if(!placeholder){
    const empty=document.createElement('div');
    empty.className='item item-empty';
    empty.textContent='没有条目 · No items';
    itemsContainer.appendChild(empty);
  }
}
function getColumnCount(){
  if(window.matchMedia('(max-width: 920px)').matches) return 1;
  if(window.matchMedia('(max-width: 1200px)').matches) return 2;
  return 3;
}
function moveCard(id, direction){
  const grid=$('#items'); if(!grid) return;
  const cards=$$('article.item');
  const index=cards.findIndex(card=>card.dataset.id===String(id));
  if(index===-1) return;
  const columns=getColumnCount();
  let offset=0;
  switch(direction){
    case 'left': {
      if(index===0) return;
      offset=-1;
      break;
    }
    case 'right': {
      if(index===cards.length-1) return;
      offset=1;
      break;
    }
    case 'up': {
      offset=-columns;
      break;
    }
    case 'down': {
      offset=columns;
      break;
    }
    default: {
      if(typeof direction==='number' && direction!==0){ offset=direction; }
      break;
    }
  }
  if(offset===0) return;
  const targetIndex=index+offset;
  if(targetIndex<0 || targetIndex>=cards.length) return;
  const card=cards[index];
  const target=cards[targetIndex];
  if(offset>0){
    grid.insertBefore(card, target.nextElementSibling);
  }else{
    grid.insertBefore(card, target);
  }
  sendOrder();
}
function sendOrder(){
  const ids=$$('article.item').map(x=>x.dataset.id).join(',');
  const fd=new FormData(); fd.append('action','reorder_items'); fd.append('order', ids);
  fetch(location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'fetch'}});
}
const catModal = document.getElementById('cat-modal');
document.getElementById('btn-cat-mgr').onclick = openCatModal;
function openCatModal(){ catModal.style.display='flex'; renderCatRowsFromDOM(); }
function closeCatModal(){ catModal.style.display='none'; }
function renderCatRows(cats, counts){
  const box=document.getElementById('cat-rows'); box.innerHTML='';
  cats.forEach(c=>{
    const row=document.createElement('div');
    row.innerHTML=`\
      <form onsubmit="return saveCat(event, ${c.id})" class="modal-form">\
        <input type="text" name="name" value="${escapeHtml(c.name)}" class="modal-input"/>\
        <span class="modal-count">共 ${counts[c.id]||0}</span>\
        <button class="btn btn-outline btn-small">保存</button>\
        <button class="btn btn-danger btn-small" onclick="return delCat(${c.id}, '${escapeHtml(c.name)}')">删除</button>\
      </form>`;
    box.appendChild(row);
  });
}
function renderCatRowsFromDOM(){ fetchCats().then(({cats,counts,total,mindmap_total})=>{renderCatRows(cats,counts); refreshSidebarCats(cats,counts,total,mindmap_total);}); }
function escapeHtml(s){ return (s||'').replace(/[&<>"']/g,m=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"'"}[m])); }
async function fetchCats(){
  const fd=new FormData(); fd.append('action','ping_cats');
  const j=await (await fetch(location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'fetch'}})).json();
  if(!j.ok) throw new Error('加载分类失败'); return j;
}
async function addCat(ev){
  ev.preventDefault();
  const name=document.getElementById('new-cat-name').value.trim(); if(!name) return false;
  const fd=new FormData(); fd.append('action','add_category'); fd.append('name', name);
  const j=await (await fetch(location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'fetch'}})).json();
  if(j.ok){ document.getElementById('new-cat-name').value=''; renderCatRows(j.cats,j.counts); refreshSidebarCats(j.cats,j.counts,j.total,j.mindmap_total); }
  return false;
}
async function saveCat(ev, id){
  ev.preventDefault();
  const name=new FormData(ev.target).get('name');
  const fd=new FormData(); fd.append('action','edit_category'); fd.append('id', id); fd.append('name', name);
  const j=await (await fetch(location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'fetch'}})).json();
  if(j.ok){ renderCatRows(j.cats,j.counts); refreshSidebarCats(j.cats,j.counts,j.total,j.mindmap_total); }
  return false;
}
async function delCat(id, name){
  if(!confirm(`确认删除分类【${name}】？该分类下条目将移入“其他”。`)) return false;
  const fd=new FormData(); fd.append('action','delete_category'); fd.append('id', id);
  const j=await (await fetch(location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'fetch'}})).json();
  if(j.ok){ renderCatRows(j.cats,j.counts); refreshSidebarCats(j.cats,j.counts,j.total,j.mindmap_total); }
  return false;
}
function refreshSidebarCats(cats, counts, total, mindmapTotal){
  const qParam=new URL(location.href).searchParams.get('q')||'';
  const urlCat=(new URL(location.href)).searchParams.get('cat')||'all';
  const list=document.getElementById('cat-list'); list.innerHTML='';
  const all=document.createElement('a');
  all.className='cat'+(urlCat==='all'?' active':'');
  all.href='?cat=all&q='+encodeURIComponent(qParam);
  all.innerHTML='<span class="name">全部 · All</span><span class="count">'+(total??0)+'</span>';
  list.appendChild(all);
  cats.forEach(c=>{
    const link=document.createElement('a');
    link.className='cat'+(String(urlCat)===String(c.id)?' active':'');
    link.dataset.id=c.id;
    link.href='?cat='+c.id+'&q='+encodeURIComponent(qParam);
    link.innerHTML=`<span class="name">${escapeHtml(c.name)}</span><span class="count">${counts[c.id]||0}</span>`;
    list.appendChild(link);
  });
  const mindLink=document.createElement('a');
  mindLink.className='cat'+(urlCat==='mindmaps'?' active':'');
  mindLink.dataset.id='mindmaps';
  mindLink.href='?cat=mindmaps&q='+encodeURIComponent(qParam);
  mindLink.innerHTML='<span class="name">思维导图</span><span class="count">'+(mindmapTotal??0)+'</span>';
  list.appendChild(mindLink);
}
function fmt(ts){ const d=new Date(ts*1000); const p=n=>String(n).padStart(2,'0'); return `${d.getFullYear()}-${p(d.getMonth()+1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`; }
if(itemsContainer){
  itemsContainer.addEventListener('change', async (e)=>{
    const t=e.target;
    if(t.classList.contains('item-toggle')){
      const card=t.closest('article.item'); if(!card) return;
      const id=card.dataset.id; const done=t.checked?1:0;
      const fd=new FormData(); fd.append('action','toggle_done'); fd.append('id', id); fd.append('done', done);
      try{
        const j=await (await fetch(location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'fetch'}})).json();
        if(j && j.ok){
          const newCategoryId=(j.category_id===null || typeof j.category_id==='undefined') ? '' : String(j.category_id);
          card.dataset.categoryId=newCategoryId;
          card.classList.toggle('done', !!j.done);
          const badge=card.querySelector('.js-updated'); if(badge&&j.updated_at) badge.textContent='更新 '+fmt(j.updated_at);
          const categoryBadge=card.querySelector('.meta .badge:not(.js-updated)');
          if(categoryBadge && j.category_label){ categoryBadge.textContent=j.category_label; }
          if(currentCategoryFilter==='all'){
            if(j.done){
              card.remove();
              ensureItemsEmptyState();
            }
          } else if(currentCategoryFilter!==newCategoryId){
            card.remove();
            ensureItemsEmptyState();
          }
          try{
            const {cats,counts,total,mindmap_total}=await fetchCats();
            refreshSidebarCats(cats,counts,total,mindmap_total);
          }catch(_){ }
        }
      }catch(_){ }
    }
    if(t.classList.contains('step-toggle')){
      const row=t.closest('.tlrow'); if(!row) return;
      const stepId=row.querySelector('input[name="id"]').value;
      const done=t.checked?1:0;
      const fd=new FormData(); fd.append('action','toggle_step'); fd.append('id', stepId); fd.append('done', done);
      try{
        const j=await (await fetch(location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'fetch'}})).json();
        if(j && j.ok){
          row.classList.toggle('done', !!done);
          const card=row.closest('article.item'); const badge=card && card.querySelector('.js-updated');
          if(badge && j.updated_at) badge.textContent='更新 '+fmt(j.updated_at);
        }
      }catch(_){ }
    }
  });
}
</script>
</body>
</html>
