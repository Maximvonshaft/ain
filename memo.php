<?php
// 单文件备忘录应用（修订版）
// 说明：此文件是原始单文件备忘录的完整替换版本。
// 修订内容：
//   1. 引入思维导图库与编辑器，可通过 ?view=maps / ?view=map_edit 访问。
//   2. 在侧边栏添加“思维导图”按钮，方便访问导图模块。
//   3. CSP 增加 'unsafe-inline'，修复无法执行内联脚本的问题。
//   4. 修复搜索框颜色变量 bug（color:var(--text)）。

declare(strict_types=1);
session_start();
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
const UPLOAD_DIR = __DIR__ . '/uploads';
const MAX_UPLOAD_BYTES = 15 * 1024 * 1024; // 15MB
const ALLOWED_UPLOAD_MIME_MAP = [
  'image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp','image/gif'=>'gif','image/svg+xml'=>'svg','image/avif'=>'avif','image/bmp'=>'bmp','image/x-icon'=>'ico',
  'application/pdf'=>'pdf','application/zip'=>'zip','application/x-zip-compressed'=>'zip',
  'text/plain'=>'txt','text/markdown'=>'md','text/x-markdown'=>'md','text/csv'=>'csv','application/json'=>'json','text/json'=>'json','text/yaml'=>'yaml','application/yaml'=>'yaml','text/x-yaml'=>'yaml','text/tab-separated-values'=>'tsv','text/x-log'=>'log',
  'video/mp4'=>'mp4','video/quicktime'=>'mov','video/x-matroska'=>'mkv','video/webm'=>'webm','video/x-msvideo'=>'avi','video/mpeg'=>'mpeg',
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
          ['id' => 'view-resources-attach', 'topic' => '上传附件（≤15MB 图片/PDF/ZIP/文本/视频）', 'children' => []],
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
      FOREIGN KEY(category_id) REFERENCES categories(id) ON DELETE SET NULL
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
  return $pdo;
}

// —— 获取分类及计数 ——
function get_categories(): array {
  $pdo=db();
  $cats=$pdo->query('SELECT id,name FROM categories ORDER BY name COLLATE NOCASE')->fetchAll();
  $map=[]; foreach($cats as $c) $map[$c['id']] = 0;
  $rows=$pdo->query('SELECT category_id, COUNT(*) AS c FROM items GROUP BY category_id')->fetchAll();
  foreach($rows as $r){ $cid=$r['category_id']; if($cid!==null&&isset($map[$cid])) $map[$cid]=(int)$r['c']; }
  return [$cats,$map];
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
      if(isset($node['data']['attachment']) && is_array($node['data']['attachment'])){
        $attachment=&$node['data']['attachment'];
        if(isset($attachment['content']) && is_string($attachment['content']) && str_starts_with($attachment['content'],'data:')){
          $asset=create_mindmap_asset_from_dataurl($attachment['content'],$attachment['name'] ?? ($node['topic'] ?? '附件'),$map_id,$node['id'],$session_key);
          if($asset){
            $asset_id=(int)$asset['id'];
            $attachment=[
              'assetId'=>$asset_id,
              'name'=>$asset['orig_name'],
              'size'=>(int)$asset['size'],
              'mime'=>$asset['mime'],
              'url'=>'?mindmap_asset='.$asset_id,
            ];
            $asset_refs[$asset_id]=$node['id'];
          } else {
            unset($node['data']['attachment']);
          }
        } else {
          $asset_id=(int)($attachment['assetId'] ?? ($attachment['id'] ?? 0));
          if($asset_id>0){
            $attachment['assetId']=$asset_id;
            $attachment['name']=$attachment['name'] ?? ($node['topic'] ?? '附件');
            $attachment['url']=$attachment['url'] ?? ('?mindmap_asset='.$asset_id);
            $asset_refs[$asset_id]=$node['id'];
          } else {
            unset($node['data']['attachment']);
          }
        }
        if(isset($node['data']['attachment'])){
          unset($node['data']['attachment']['content'],$node['data']['attachment']['id']);
          if(isset($node['data']['attachment']['type']) && !isset($node['data']['attachment']['mime'])){
            $node['data']['attachment']['mime']=$node['data']['attachment']['type'];
          }
          unset($node['data']['attachment']['type']);
        }
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
  $total = (int)$pdo->query('SELECT COUNT(*) FROM items')->fetchColumn();
  $uncat = (int)$pdo->query('SELECT COUNT(*) FROM items WHERE category_id IS NULL')->fetchColumn();
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok'=>1,'cats'=>$cats,'counts'=>$counts,'total'=>$total,'uncat'=>$uncat], JSON_UNESCAPED_UNICODE);
  exit;
}

// —— 下载附件 ——
if (isset($_GET['download']) && ctype_digit((string)$_GET['download'])) {
  $att=get_attachment((int)$_GET['download']); if(!$att){ http_response_code(404); echo 'Not Found'; exit; }
  $path=UPLOAD_DIR.DIRECTORY_SEPARATOR.$att['stored_name']; if(!is_file($path)){ http_response_code(404); echo 'File Missing'; exit; }
  $mime=$att['mime']; $isImage=in_array($mime,['image/png','image/jpeg','image/webp','image/gif','image/svg+xml'],true);
  $filename=$att['orig_name'];
  header('Content-Length: '.$att['size']); header('X-Content-Type-Options: nosniff');
  if($isImage){ header('Content-Type: '.$mime); header('Content-Disposition: inline; filename="'.rawurlencode($filename).'"'); }
  else { header('Content-Type: application/octet-stream'); header('Content-Disposition: attachment; filename="'.rawurlencode($filename).'"'); }
  readfile($path); exit;
}

if (isset($_GET['mindmap_asset']) && ctype_digit((string)$_GET['mindmap_asset'])) {
  $asset=get_mindmap_asset((int)$_GET['mindmap_asset']); if(!$asset){ http_response_code(404); echo 'Not Found'; exit; }
  $path=UPLOAD_DIR.DIRECTORY_SEPARATOR.$asset['stored_name']; if(!is_file($path)){ http_response_code(404); echo 'File Missing'; exit; }
  $mime=$asset['mime'];
  $filename=$asset['orig_name'];
  $inline=str_starts_with($mime,'image/') || str_starts_with($mime,'video/') || $mime==='application/pdf';
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
        $id=(int)$_POST['id']; $done=(int)($_POST['done']??0); $nowt=now();
        $pdo->prepare('UPDATE items SET done=?, updated_at=? WHERE id=?')->execute([$done?1:0,$nowt,$id]);
        if(is_ajax()){ header('Content-Type: application/json'); echo json_encode(['ok'=>1,'id'=>$id,'updated_at'=>$nowt]); exit; }
        break;
      }
      case 'edit_item': {
        $id=(int)$_POST['id']; $title=trim((string)($_POST['title']??'')); if($title==='') throw new RuntimeException('标题必填');
        $desc=(string)($_POST['description']??''); $catId=(isset($_POST['category_id'])&&ctype_digit((string)$_POST['category_id']))?(int)$_POST['category_id']:null;
        $pdo->prepare('UPDATE items SET title=?, description=?, category_id=?, updated_at=? WHERE id=?')->execute([$title,$desc,$catId,now(),$id]);
        if(is_ajax()){ header('Content-Type: application/json'); echo json_encode(['ok'=>1]); exit; } break;
      }
      case 'delete_item': { $id=(int)$_POST['id']; $pdo->prepare('DELETE FROM items WHERE id=?')->execute([$id]); break; }
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
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE items SET category_id=? WHERE category_id=?')->execute([$other,$id]);
        $pdo->prepare('DELETE FROM categories WHERE id=?')->execute([$id]);
        $pdo->commit();
        if(is_ajax()) json_cats(); break;
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
        if(!$ext) throw new RuntimeException('仅允许图片、PDF、ZIP、文本或视频文件');
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
        if(!$ext) throw new RuntimeException('仅允许图片、PDF、ZIP、文本或视频文件');
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
        redirect('?view=maps');
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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/easymde/dist/easymde.min.css">
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
      body{animation:breathing 10s ease-in-out infinite;background:
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
        mix-blend-mode:screen;opacity:.28;pointer-events:none;z-index:-3;animation:grid-pan 18s linear infinite;
      }
      @keyframes breathing{0%,100%{filter:brightness(.92)}50%{filter:brightness(1)}}
      @keyframes grid-pan{0%{background-position:0 0,0 0}100%{background-position:0 var(--grid-size),var(--grid-size) 0}}
      .scanlines{position:fixed;inset:0;pointer-events:none;z-index:-1;background:linear-gradient(to bottom,rgba(75,195,209,.14) 0,transparent 4px);background-size:100% 6px;opacity:.25;animation:scan 12s linear infinite}
      @keyframes scan{0%{transform:translateY(-100%)}100%{transform:translateY(100%)}}
      a{color:inherit;text-decoration:none}
      h1,h2,h3,h4{font-family:'Cinzel','Noto Serif SC',serif;color:var(--gold-400);letter-spacing:-0.5px;text-transform:uppercase}
      .wrap{max-width:1180px;margin:0 auto;padding:32px 24px 64px;position:relative;z-index:0}
      .wrap::before{content:"";position:absolute;inset:16px;border-radius:var(--r-lg);border:1px dashed rgba(201,168,106,.18);opacity:.65;pointer-events:none}
      .card{position:relative;background:linear-gradient(180deg,rgba(21,26,30,.9),rgba(15,19,22,.92));border:1px solid rgba(201,168,106,.28);border-radius:var(--r-lg);padding:24px;box-shadow:var(--shadow-1);backdrop-filter:blur(14px)}
      .card::before{content:"";position:absolute;inset:10px;border-radius:calc(var(--r-lg) - 4px);box-shadow:inset 0 0 0 1px rgba(227,198,139,.18),inset 0 0 34px rgba(227,198,139,.08);pointer-events:none;opacity:.9}
      .btn{position:relative;display:inline-flex;align-items:center;justify-content:center;padding:10px 18px;border-radius:var(--r-sm);border:1px solid rgba(201,168,106,.4);background:rgba(21,26,30,.82);color:var(--gold-400);cursor:pointer;text-transform:uppercase;font:600 13px/1.2 'Inter','Noto Sans SC',sans-serif;letter-spacing:.12em;transition:transform var(--transition),border-color var(--transition);box-shadow:none;overflow:hidden}
      .btn::after{content:none}
      .btn:hover{transform:translateY(-2px);border-color:rgba(227,198,139,.65)}
      .btn:active{transform:translateY(0);background:rgba(21,26,30,.94);border-color:var(--gold-700)}
      .btn.acc{background:linear-gradient(135deg,rgba(201,168,106,.18),rgba(170,140,84,.24));color:var(--gold-400);box-shadow:none}
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
      .preview{max-height:60vh;overflow:auto;background-image:linear-gradient(120deg,rgba(201,168,106,.06),transparent 55%)}
      .preview::-webkit-scrollbar{width:8px}
      .preview::-webkit-scrollbar-thumb{background:rgba(201,168,106,.28);border-radius:999px}
      .md-body{color:var(--text-dim);font:400 15px/1.75 'Noto Sans SC','Inter',sans-serif}
      .md-body img{max-width:100%;height:auto;border:1px solid rgba(201,168,106,.32);border-radius:var(--r-sm);box-shadow:0 16px 34px rgba(0,0,0,.55),0 0 24px rgba(227,198,139,.12)}
      .EasyMDEContainer .editor-toolbar{background:rgba(10,14,16,.82);border:1px solid rgba(201,168,106,.28);border-radius:var(--r-md) var(--r-md) 0 0;color:var(--text-muted)}
      .EasyMDEContainer .editor-toolbar a{color:var(--text-muted);text-transform:uppercase;letter-spacing:.18em;font-family:'Inter','Noto Sans SC',sans-serif}
      .EasyMDEContainer .editor-toolbar a.active,.EasyMDEContainer .editor-toolbar a:hover{background:rgba(201,168,106,.16);color:var(--text-strong)}
      .EasyMDEContainer .CodeMirror{border:1px solid rgba(201,168,106,.28);border-radius:0 0 var(--r-md) var(--r-md);background:rgba(12,16,18,.82);color:var(--text-strong);min-height:280px;max-height:60vh;box-shadow:inset 0 0 24px rgba(0,0,0,.55)}
      .thumbs{display:flex;gap:14px;flex-wrap:wrap;margin-top:16px}
      .thumb{position:relative;border:1px solid rgba(201,168,106,.34);border-radius:var(--r-sm);overflow:hidden;background:rgba(10,14,16,.82);box-shadow:var(--shadow-1)}
      .thumb::after{content:"";position:absolute;inset:6px;border-radius:calc(var(--r-sm) - 4px);border:1px dashed rgba(201,168,106,.26);pointer-events:none}
      .thumb img{display:block;max-width:220px;max-height:150px}
      .att-meta{color:var(--text-muted);font:12px/1 'Inter','Noto Sans SC',sans-serif;letter-spacing:.12em}
      .timeline{position:relative;margin-top:20px;margin-left:16px;padding-left:32px;color:var(--text-muted)}
      .timeline::before{content:"";position:absolute;left:12px;top:0;bottom:0;width:2px;background:linear-gradient(180deg,rgba(201,168,106,.5),rgba(201,168,106,.06));animation:energy 6s linear infinite}
      .tl-item{position:relative;margin:14px 0;padding:16px 18px 18px 24px;border:1px solid rgba(201,168,106,.32);border-radius:var(--r-md);background:linear-gradient(180deg,rgba(21,26,30,.9),rgba(15,19,22,.92));box-shadow:var(--shadow-1)}
      .tl-item::before{content:"";position:absolute;inset:8px;border-radius:calc(var(--r-md) - 4px);border:1px dashed rgba(201,168,106,.16);opacity:.6;pointer-events:none}
      .tl-item .tl-dot{position:absolute;left:-30px;top:20px;width:16px;height:16px;border-radius:50%;background:linear-gradient(135deg,rgba(201,168,106,.92),rgba(170,140,84,.9));box-shadow:0 0 24px rgba(227,198,139,.32)}
      .tl-item .tl-dot::after{content:"";position:absolute;inset:-6px;border-radius:inherit;border:1px dashed rgba(201,168,106,.4);animation:pulse 2.4s ease-in-out infinite}
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
      @keyframes pulse{0%,100%{transform:scale(.85);opacity:.7}50%{transform:scale(1.05);opacity:1}}
      @keyframes energy{0%{background-position:0 0}100%{background-position:0 120px}}
    </style>
  </head>
  <body>
    <div class="scanlines" aria-hidden="true"></div>
    <div class="wrap">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <a class="btn" href="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>">← 返回首页</a>
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
              <button class="btn acc" type="submit">保存</button>
              <span id="save-tip" class="save-tip">保存成功</span>
            </div>
          </div>
          <div class="split" id="split">
            <div class="editbox">
              <textarea id="md-editor" name="description" placeholder="描述 · 支持 Markdown"></textarea>
              <div style="display:flex;gap:10px;justify-content:space-between;align-items:center;margin-top:10px">
                <div>
                  <input id="att-file-item" type="file" accept="image/*,application/pdf,application/zip,application/x-zip-compressed,text/plain,text/markdown,text/csv,application/json,video/*" style="display:none">
                  <button class="btn" type="button" id="btn-insert-att-item">插入附件到备注</button>
                  <span class="att-meta">图片、PDF、ZIP、文本或视频 ≤ 15MB</span>
                </div>
                <button class="btn" type="button" id="btn-preview-toggle">预览置顶/置底</button>
              </div>
            </div>
            <div class="preview">
              <div class="md-body" id="md-view"><span style="color:var(--text-dim)">预览区域</span></div>
              <div class="thumbs" id="thumbs"></div>
            </div>
          </div>
        </form>
        <div style="display:flex;justify-content:space-between;align-items:center;margin:12px 0 6px">
          <div style="font-weight:800">流程子任务（时间轴）</div>
          <form id="add-step-form" onsubmit="return addStepAJAX(event)" style="display:flex;gap:6px;flex-wrap:wrap">
            <input id="new-step-title" placeholder="新增步骤 · Add step" style="flex:1;min-width:200px;padding:12px;border:1px solid var(--border);border-radius:12px;background:rgba(6,25,14,.82);color:var(--text);letter-spacing:.08em">
            <button class="btn acc" type="submit">添加</button>
          </form>
        </div>
        <div class="timeline" id="timeline" data-item="0"></div>
      </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/dompurify/dist/purify.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/easymde/dist/easymde.min.js"></script>
    <script>
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
        const controller={
          saving(){
            if(timer){ clearTimeout(timer); timer=null; }
            if(buttonEl){ buttonEl.disabled=true; buttonEl.textContent='⏳ 保存中...'; }
            if(tipEl){ tipEl.textContent='保存中...'; tipEl.classList.add('show'); tipEl.classList.remove('dirty'); }
          },
          success(){
            if(timer){ clearTimeout(timer); }
            if(buttonEl){ buttonEl.disabled=false; buttonEl.textContent='✅ 保存成功'; }
            if(tipEl){ tipEl.textContent='保存成功'; tipEl.classList.add('show'); tipEl.classList.remove('dirty'); }
            timer=setTimeout(()=>{
              if(buttonEl){ buttonEl.disabled=false; buttonEl.textContent=defaultLabel; }
              if(tipEl){ tipEl.classList.remove('show'); tipEl.classList.remove('dirty'); }
              timer=null;
            },1500);
          },
          error(message){
            if(timer){ clearTimeout(timer); timer=null; }
            if(buttonEl){ buttonEl.disabled=false; buttonEl.textContent=defaultLabel; }
            if(tipEl){
              tipEl.textContent=message || '未保存';
              tipEl.classList.add('show');
              tipEl.classList.add('dirty');
            }
          },
          reset(){
            if(timer){ clearTimeout(timer); timer=null; }
            if(buttonEl){ buttonEl.disabled=false; buttonEl.textContent=defaultLabel; }
            if(tipEl){ tipEl.classList.remove('show'); tipEl.classList.remove('dirty'); }
          }
        };
        return controller;
      }
      function safeHTML(md){ return DOMPurify.sanitize(marked.parse(md||'')); }
      function renderMD(){ $('#md-view').innerHTML = safeHTML(mde.value()); }
      const mde = new EasyMDE({
        element: document.getElementById('md-editor'),
        spellChecker:false, status:false,
        toolbar:["bold","italic","heading","|","quote","unordered-list","ordered-list","code","link","image","table","|","preview","guide"]
      });
      mde.codemirror.on('change', renderMD); renderMD();
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
        fd.append('description', mde.value());
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
        mde.codemirror.replaceSelection(j.markdown+"\n"); renderMD();
        if(j.mime.startsWith('image/')){ const div=document.createElement('div'); div.className='thumb'; div.innerHTML=`<a href="${j.url}" target="_blank"><img src="${j.url}" alt=""></a>`; $('#thumbs').prepend(div); }
        e.target.value='';
      });
      $('#btn-preview-toggle').onclick=()=>{ const split=$('#split'); split.insertBefore(split.lastElementChild,split.firstElementChild); };
      function escapeHTML(s){ return (s||'').replace(/[&<>"']/g,m=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"'"}[m])); }
      function stepNodeHTML(s){
        const ts=new Date(s.created_at*1000).toISOString().slice(0,16).replace('T',' ');
        const checked=s.done? 'checked' : '';
        return `\
          <div class="tl-item ${s.done?'done':''}" draggable="true" data-id="${s.id}">\
            <span class="tl-dot"></span>\
            <div class="tl-head">\
              <span class="drag">⬍</span>\
              <form onsubmit="return false" style="margin:0">\
                <input type="hidden" name="id" value="${s.id}">\
                <input type="checkbox" ${checked} onchange="toggleStep(${s.id}, this.checked)" title="完成">\
              </form>\
              <div style="font-weight:700;flex:1">${escapeHTML(s.title)} <span class="ts">${ts}</span></div>\
              <details>\
                <summary>编辑</summary>\
                <div style="margin-top:6px;display:grid;gap:6px">\
                  <form onsubmit="return saveStepTitleAJAX(event, ${s.id}, this)" style="display:flex;gap:6px;flex-wrap:wrap">\
                    <input type="hidden" name="action" value="edit_step">\
                    <input type="hidden" name="id" value="${s.id}">\
                    <input name="title" value="${escapeHTML(s.title)}" style="padding:8px;border:1px solid var(--border);border-radius:8px;flex:1;min-width:180px">\
                    <button class="btn">保存标题</button>\
                  </form>\
                  <form onsubmit="return saveStepNotesAJAX(event, ${s.id})" id="form-notes-${s.id}">\
                    <input type="hidden" name="action" value="edit_step_notes">\
                    <input type="hidden" name="id" value="${s.id}">\
                    <textarea id="md-step-${s.id}" name="notes" style="min-height:120px">${escapeHTML(s.notes||'')}</textarea>\
                    <div style="display:flex;gap:8px;justify-content:space-between;align-items:center;margin-top:6px;flex-wrap:wrap">\
                      <div>\
                        <input id="att-file-step-${s.id}" type="file" accept="image/*,application/pdf,application/zip,application/x-zip-compressed,text/plain,text/markdown,text/csv,application/json,video/*" style="display:none">\
                        <button class="btn" type="button" onclick="insertAttachmentToStep(${s.id})">插入附件到备注</button>\
                        <span class="att-meta">图片、PDF、ZIP、文本或视频 ≤ 15MB</span>\
                      </div>\
                      <button class="btn acc" type="submit">保存备注</button>\
                    </div>\
                  </form>\
                </div>\
              </details>\
            </div>\
            <div class="md-body" id="step-md-view-${s.id}">${s.notes?DOMPurify.sanitize(marked.parse(s.notes)):'<span class="placeholder-muted">无备注</span>'}</div>\
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
      async function saveStepTitleAJAX(ev, stepId, form){
        ev.preventDefault(); const fd=new FormData(form);
        await fetch(location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'fetch'}});
        return false;
      }
      async function saveStepNotesAJAX(ev, stepId){
        ev.preventDefault(); const f=$('#form-notes-'+stepId); const fd=new FormData(f); const ta=f.querySelector('textarea[name="notes"]');
        const r=await fetch(location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'fetch'}});
        if(r.ok) $('#step-md-view-'+stepId).innerHTML = DOMPurify.sanitize(marked.parse(ta.value));
        return false;
      }
      async function toggleStep(stepId, done){
        const fd=new FormData(); fd.append('action','toggle_step'); fd.append('id', stepId); fd.append('done', done?1:0);
        const r=await fetch(location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'fetch'}});
        if(r.ok){ const el=document.querySelector(`.tl-item[data-id="${stepId}"]`); if(el){ el.classList.toggle('done', !!done); } }
      }
      const stepMDE={};
      window.insertAttachmentToStep = async function(stepId){
        const inp=$('#att-file-step-'+stepId);
        inp.onchange=async e=>{
          const f=e.target.files[0]; if(!f) return;
          const fd=new FormData(); fd.append('action','upload_attachment'); fd.append('target','step'); fd.append('target_id', String(stepId)); fd.append('file', f);
          const r=await fetch(location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'fetch'}});
          const j=await r.json(); if(!j.ok){ alert(j.error||'上传失败'); return; }
          if(!stepMDE[stepId]) stepMDE[stepId]=new EasyMDE({ element: document.getElementById('md-step-'+stepId), spellChecker:false, status:false });
          stepMDE[stepId].codemirror.replaceSelection(j.markdown+"\n"); const val=stepMDE[stepId].value(); $('#step-md-view-'+stepId).innerHTML = DOMPurify.sanitize(marked.parse(val));
          e.target.value='';
        };
        inp.click();
      };
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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/easymde/dist/easymde.min.css">
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
        mix-blend-mode:screen;opacity:.28;pointer-events:none;z-index:-3;animation:grid-pan 18s linear infinite;
      }
      @keyframes grid-pan{0%{background-position:0 0,0 0}100%{background-position:0 var(--grid-size),var(--grid-size) 0}}
      .scanlines{position:fixed;inset:0;pointer-events:none;z-index:-1;background:linear-gradient(to bottom,rgba(75,195,209,.14) 0,transparent 4px);background-size:100% 6px;opacity:.25;animation:scan 12s linear infinite}
      @keyframes scan{0%{transform:translateY(-100%)}100%{transform:translateY(100%)}}
      a{color:inherit;text-decoration:none}
      h1,h2,h3,h4{font-family:'Cinzel','Noto Serif SC',serif;color:var(--gold-400);letter-spacing:-0.5px;text-transform:uppercase}
      .wrap{max-width:1180px;margin:0 auto;padding:32px 24px 64px;position:relative;z-index:0}
      .wrap::before{content:"";position:absolute;inset:16px;border-radius:var(--r-lg);border:1px dashed rgba(201,168,106,.2);opacity:.6;pointer-events:none}
      .btn{position:relative;display:inline-flex;align-items:center;justify-content:center;padding:10px 18px;border-radius:var(--r-sm);border:1px solid rgba(201,168,106,.4);background:rgba(21,26,30,.82);color:var(--gold-400);cursor:pointer;text-transform:uppercase;font:600 13px/1.2 'Inter','Noto Sans SC',sans-serif;letter-spacing:.12em;transition:transform var(--transition),border-color var(--transition);box-shadow:none;overflow:hidden}
      .btn::after{content:none}
      .btn:hover{transform:translateY(-2px);border-color:rgba(227,198,139,.65)}
      .btn:active{transform:translateY(0);border-color:var(--gold-700);background:rgba(21,26,30,.94)}
      .btn.acc{background:linear-gradient(135deg,rgba(201,168,106,.18),rgba(170,140,84,.24));box-shadow:none}
      .btn.danger{color:#F8E6E6;border-color:rgba(209,75,75,.55);box-shadow:none}
      .btn:focus-visible{outline:2px solid var(--accent-cyan);outline-offset:3px}
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
      .EasyMDEContainer .editor-toolbar{background:rgba(10,14,16,.82);border:1px solid rgba(201,168,106,.28);border-radius:var(--r-md) var(--r-md) 0 0;color:var(--text-muted)}
      .EasyMDEContainer .editor-toolbar a{color:var(--text-muted);text-transform:uppercase;letter-spacing:.18em;font-family:'Inter','Noto Sans SC',sans-serif}
      .EasyMDEContainer .editor-toolbar a.active,.EasyMDEContainer .editor-toolbar a:hover{background:rgba(201,168,106,.18);color:var(--text-strong)}
      .EasyMDEContainer .CodeMirror{border:1px solid rgba(201,168,106,.28);border-radius:0 0 var(--r-md) var(--r-md);background:rgba(12,16,18,.82);color:var(--text-strong);min-height:280px;max-height:60vh;box-shadow:inset 0 0 24px rgba(0,0,0,.55)}
      .thumbs{display:flex;gap:14px;flex-wrap:wrap;margin-top:16px}
      .thumb{position:relative;border:1px solid rgba(201,168,106,.34);border-radius:var(--r-sm);overflow:hidden;background:rgba(10,14,16,.82);box-shadow:var(--shadow-1)}
      .thumb::after{content:"";position:absolute;inset:6px;border-radius:calc(var(--r-sm) - 4px);border:1px dashed rgba(201,168,106,.26);pointer-events:none}
      .thumb img{display:block;max-width:220px;max-height:150px}
      .timeline{position:relative;margin-top:22px;margin-left:18px;padding-left:34px;color:var(--text-muted)}
      .timeline::before{content:"";position:absolute;left:12px;top:0;bottom:0;width:2px;background:linear-gradient(180deg,rgba(201,168,106,.5),rgba(201,168,106,.06));animation:energy 6s linear infinite}
      .tl-item{position:relative;margin:14px 0;padding:16px 20px 20px 26px;border:1px solid rgba(201,168,106,.34);border-radius:var(--r-md);background:linear-gradient(180deg,rgba(21,26,30,.9),rgba(15,19,22,.94));box-shadow:var(--shadow-1)}
      .tl-item::before{content:"";position:absolute;inset:8px;border-radius:calc(var(--r-md) - 4px);border:1px dashed rgba(201,168,106,.2);pointer-events:none;opacity:.7}
      .tl-item .tl-dot{position:absolute;left:-28px;top:22px;width:16px;height:16px;border-radius:50%;background:linear-gradient(135deg,rgba(201,168,106,.92),rgba(170,140,84,.9));box-shadow:0 0 24px rgba(227,198,139,.32)}
      .tl-item .tl-dot::after{content:"";position:absolute;inset:-6px;border-radius:inherit;border:1px dashed rgba(201,168,106,.42);animation:pulse 2.4s ease-in-out infinite}
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
      @keyframes pulse{0%,100%{transform:scale(.85);opacity:.7}50%{transform:scale(1.05);opacity:1}}
      @keyframes energy{0%{background-position:0 0}100%{background-position:0 120px}}
    </style>

  </head>
  <body>
    <div class="scanlines" aria-hidden="true"></div>
    <div class="wrap">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;gap:8px;flex-wrap:wrap">
        <a class="btn" href="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>">← 返回首页</a>
        <form method="post" onsubmit="return confirm('确认删除？');">
          <input type="hidden" name="action" value="delete_item"><input type="hidden" name="id" value="<?php echo $it['id']; ?>">
          <button class="btn danger">删除</button>
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
                  <button class="btn acc" type="submit">保存</button><span id="save-tip" class="save-tip">保存成功</span>
                </div>
              </div>
              <textarea id="md-editor" name="description"><?php echo h($it['description']); ?></textarea>
                <div style="display:flex;gap:12px;justify-content:space-between;align-items:center;margin-top:14px;flex-wrap:wrap">
                <div>
                  <input id="att-file-item" type="file" accept="image/*,application/pdf,application/zip,application/x-zip-compressed,text/plain,text/markdown,text/csv,application/json,video/*" style="display:none">
                  <button class="btn" type="button" id="btn-insert-att-item">插入附件到备注</button>
                  <span class="att-meta">图片、PDF、ZIP、文本或视频 ≤ 15MB</span>
                </div>
              </div>
            </form>
          </div>
          <div class="preview">
            <div class="md-body" id="md-view"></div>
            <div class="thumbs" id="thumbs">
              <?php if ($itemAtts): foreach ($itemAtts as $a): $isImg=in_array($a['mime'],['image/png','image/jpeg','image/webp','image/gif','image/svg+xml'],true); ?>
                <div class="thumb">
                  <?php if ($isImg): ?>
                    <a href="?download=<?php echo $a['id']; ?>" target="_blank" title="<?php echo h($a['orig_name']); ?>"><img src="?download=<?php echo $a['id']; ?>" alt=""></a>
                  <?php else: ?>
                    <div style="display:flex;gap:8px;align-items:center;padding:8px">
                      <a class="btn" href="?download=<?php echo $a['id']; ?>">下载 ZIP</a>
                      <div class="att-meta"><?php echo h($a['orig_name']); ?> · <?php echo bytes_h((int)$a['size']); ?></div>
                    </div>
                  <?php endif; ?>
                  <form method="post" style="padding:6px;text-align:right">
                    <input type="hidden" name="action" value="delete_attachment">
                    <input type="hidden" name="id" value="<?php echo $a['id']; ?>">
                    <button class="btn" style="font-size:12px">删除</button>
                  </form>
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
            <button class="btn acc">添加</button>
          </form>
        </div>
        <div class="timeline" id="timeline" data-item="<?php echo $it['id']; ?>">
          <?php foreach ($steps as $s): ?>
            <div class="tl-item <?php echo $s['done']?'done':''; ?>" draggable="true" data-id="<?php echo $s['id']; ?>">
              <span class="tl-dot"></span>
              <div class="tl-head">
                <span class="drag">⬍</span>
                <form method="post" style="margin:0">
                  <input type="hidden" name="action" value="toggle_step">
                  <input type="hidden" name="id" value="<?php echo $s['id']; ?>">
                  <input type="hidden" name="done" value="<?php echo $s['done']?0:1; ?>">
                  <input type="checkbox" <?php echo $s['done']?'checked':''; ?> onchange="this.form.submit()" title="完成">
                </form>
                <div style="font-weight:700;flex:1"><?php echo h($s['title']); ?> <span class="ts"><?php echo dt((int)$s['created_at']); ?></span></div>
                <details>
                  <summary>编辑</summary>
                  <div style="margin-top:6px;display:grid;gap:6px">
                    <form method="post" onsubmit="return saveStepTitleAJAX(event, <?php echo $s['id']; ?>, this)" style="display:flex;gap:6px;flex-wrap:wrap">
                      <input type="hidden" name="action" value="edit_step">
                      <input type="hidden" name="id" value="<?php echo $s['id']; ?>">
                      <input name="title" value="<?php echo h($s['title']); ?>" style="padding:8px;border:1px solid var(--border);border-radius:8px;flex:1;min-width:180px">
                      <button class="btn">保存标题</button>
                    </form>
                    <form method="post" onsubmit="return saveStepNotesAJAX(event, <?php echo $s['id']; ?>)" id="form-notes-<?php echo $s['id']; ?>">
                      <input type="hidden" name="action" value="edit_step_notes">
                      <input type="hidden" name="id" value="<?php echo $s['id']; ?>">
                      <textarea id="md-step-<?php echo $s['id']; ?>" name="notes" style="min-height:120px"><?php echo h($s['notes'] ?? ''); ?></textarea>
                      <div style="display:flex;gap:8px;justify-content:space-between;align-items:center;margin-top:6px;flex-wrap:wrap">
                        <div>
                          <input id="att-file-step-<?php echo $s['id']; ?>" type="file" accept="image/*,application/pdf,application/zip,application/x-zip-compressed,text/plain,text/markdown,text/csv,application/json,video/*" style="display:none">
                          <button class="btn" type="button" onclick="insertAttachmentToStep(<?php echo $s['id']; ?>)">插入附件到备注</button>
                          <span class="att-meta">图片、PDF、ZIP、文本或视频 ≤ 15MB</span>
                        </div>
                        <button class="btn acc" type="submit">保存备注</button>
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
    <script src="https://cdn.jsdelivr.net/npm/easymde/dist/easymde.min.js"></script>
    <script>
      const $=s=>document.querySelector(s); const $$=s=>Array.from(document.querySelectorAll(s));
      const throttle=(fn,ms)=>{let t=0;return (...a)=>{const n=Date.now();if(n-t>ms){t=n;fn(...a);} }};
      function createSaveFeedbackController(tipEl, buttonEl){
        const defaultLabel = buttonEl ? (buttonEl.dataset.defaultLabel || buttonEl.textContent || '保存') : '保存';
        if(buttonEl){ buttonEl.dataset.defaultLabel = defaultLabel; }
        let timer=null;
        return {
          saving(){
            if(timer){ clearTimeout(timer); timer=null; }
            if(buttonEl){ buttonEl.disabled=true; buttonEl.textContent='⏳ 保存中...'; }
            if(tipEl){ tipEl.textContent='保存中...'; tipEl.classList.add('show'); tipEl.classList.remove('dirty'); }
          },
          success(){
            if(timer){ clearTimeout(timer); }
            if(buttonEl){ buttonEl.disabled=false; buttonEl.textContent='✅ 保存成功'; }
            if(tipEl){ tipEl.textContent='保存成功'; tipEl.classList.add('show'); tipEl.classList.remove('dirty'); }
            timer=setTimeout(()=>{
              if(buttonEl){ buttonEl.disabled=false; buttonEl.textContent=defaultLabel; }
              if(tipEl){ tipEl.classList.remove('show'); tipEl.classList.remove('dirty'); }
              timer=null;
            },1500);
          },
          error(message){
            if(timer){ clearTimeout(timer); timer=null; }
            if(buttonEl){ buttonEl.disabled=false; buttonEl.textContent=defaultLabel; }
            if(tipEl){
              tipEl.textContent=message || '未保存';
              tipEl.classList.add('show');
              tipEl.classList.add('dirty');
            }
          },
          reset(){
            if(timer){ clearTimeout(timer); timer=null; }
            if(buttonEl){ buttonEl.disabled=false; buttonEl.textContent=defaultLabel; }
            if(tipEl){ tipEl.classList.remove('show'); tipEl.classList.remove('dirty'); }
          }
        };
      }
      function safeHTML(md){ return DOMPurify.sanitize(marked.parse(md||'')); }
      function renderMDTo(id, md){ const el=document.getElementById(id); if(el) el.innerHTML=safeHTML(md); }
      const mde = new EasyMDE({
        element: document.getElementById('md-editor'),
        spellChecker:false, status:false,
        autosave:{enabled:true, uniqueId:'memo-item-<?php echo $it['id']; ?>', delay:800},
        toolbar:["bold","italic","heading","|","quote","unordered-list","ordered-list","code","link","image","table","|","preview","guide"]
      });
      renderMDTo('md-view', mde.value());
      mde.codemirror.on('change', ()=> renderMDTo('md-view', mde.value()));
      async function saveItemAJAX(ev, form){
        ev.preventDefault();
        const fd = new FormData(form);
        const tip=form.querySelector('.save-tip') || document.getElementById('save-tip');
        const btn=form.querySelector('button[type="submit"]');
        const feedback=createSaveFeedbackController(tip, btn);
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
      document.getElementById('btn-insert-att-item').addEventListener('click',()=>document.getElementById('att-file-item').click());
      document.getElementById('att-file-item').addEventListener('change', async (e)=>{
        const f=e.target.files[0]; if(!f) return;
        const fd=new FormData(); fd.append('action','upload_attachment'); fd.append('target','item'); fd.append('target_id','<?php echo $it['id']; ?>'); fd.append('file', f);
        const j=await (await fetch(location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'fetch'}})).json();
        if(!j.ok){ alert(j.error||'上传失败'); return; }
        mde.codemirror.replaceSelection(j.markdown+"\n"); mde.codemirror.focus();
        if(j.mime.startsWith('image/')){ const div=document.createElement('div'); div.className='thumb'; div.innerHTML=`<a href="${j.url}" target="_blank"><img src="${j.url}" alt=""></a>`; document.getElementById('thumbs').prepend(div); }
        e.target.value='';
      });
      const stepMDE={};
      async function saveStepTitleAJAX(ev, stepId, form){
        ev.preventDefault(); const fd=new FormData(form);
        await fetch(location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'fetch'}});
        return false;
      }
      async function saveStepNotesAJAX(ev, stepId){
        ev.preventDefault(); const form=document.getElementById('form-notes-'+stepId);
        const fd=new FormData(form); const ta=form.querySelector('textarea[name="notes"]');
        const res=await fetch(location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'fetch'}});
        if(res.ok){ renderMDTo('step-md-view-'+stepId, ta.value); }
        return false;
      }
      window.insertAttachmentToStep = async function(stepId){
        const input=document.getElementById('att-file-step-'+stepId);
        input.onchange = async (e)=>{
          const f=e.target.files[0]; if(!f) return;
          const fd=new FormData(); fd.append('action','upload_attachment'); fd.append('target','step'); fd.append('target_id', String(stepId)); fd.append('file', f);
          const j=await (await fetch(location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'fetch'}})).json();
          if(!j.ok){ alert(j.error||'上传失败'); return; }
          if(!stepMDE[stepId]){ stepMDE[stepId]=new EasyMDE({ element: document.getElementById('md-step-'+stepId), spellChecker:false, status:false }); }
          stepMDE[stepId].codemirror.replaceSelection(j.markdown+"\n");
          const val=stepMDE[stepId].value(); renderMDTo('step-md-view-'+stepId, val);
          e.target.value='';
        };
        input.click();
      };
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
      <?php foreach ($steps as $s): ?>
        renderMDTo('step-md-view-<?php echo $s['id']; ?>', <?php echo json_encode((string)($s['notes'] ?? ''), JSON_UNESCAPED_UNICODE); ?>);
      <?php endforeach; ?>
    </script>
  </body>
  </html>
  <?php
  exit;
}

// —— 思维导图视图 ——
if ($view === 'map') {
  redirect('?view=maps');
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
      }
      *,*::before,*::after{box-sizing:border-box}
      html,body{margin:0;min-height:100vh;color:var(--text-strong);background:var(--bg-void);font:16px/1.6 'Source Han Sans','Noto Sans SC','Inter','Microsoft YaHei',sans-serif;letter-spacing:.01em;position:relative;overflow:hidden}
      body{animation:breathing 12s ease-in-out infinite;background:
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
        mix-blend-mode:screen;opacity:.3;pointer-events:none;z-index:-3;animation:grid-pan 18s linear infinite;
      }
      @keyframes breathing{0%,100%{filter:brightness(.9)}50%{filter:brightness(1)}}
      @keyframes grid-pan{0%{background-position:0 0,0 0}100%{background-position:0 var(--grid-size),var(--grid-size) 0}}
      .scanlines{position:fixed;inset:0;pointer-events:none;z-index:-1;background:linear-gradient(to bottom,rgba(75,195,209,.14) 0,transparent 4px);background-size:100% 6px;opacity:.24;animation:scan 12s linear infinite}
      @keyframes scan{0%{transform:translateY(-100%)}100%{transform:translateY(100%)}}
      a{color:inherit;text-decoration:none}
      .mind-shell{position:relative;min-height:100vh;display:flex;flex-direction:column;gap:0;padding:24px 24px 160px}
      @media (max-width:900px){.mind-shell{padding:18px 14px 170px}}
      .mind-info-bar{position:absolute;top:28px;left:28px;display:flex;flex-direction:column;gap:12px;padding:14px 18px;border-radius:20px;border:1px solid rgba(201,168,106,.32);background:linear-gradient(180deg,rgba(21,26,30,.92),rgba(12,16,18,.88));box-shadow:0 18px 48px rgba(0,0,0,.55),0 0 28px rgba(227,198,139,.14) inset;backdrop-filter:blur(16px);min-width:260px;max-width:min(460px,calc(100% - 56px));pointer-events:auto;z-index:20}
      .mind-info-row{display:flex;align-items:center;gap:12px}
      .map-back{display:inline-flex;align-items:center;gap:6px;padding:8px 12px;border-radius:999px;border:1px solid rgba(201,168,106,.32);background:rgba(201,168,106,.08);font:600 12px/1 'Inter','Noto Sans SC',sans-serif;letter-spacing:.16em;text-transform:uppercase;color:var(--gold-400);transition:background var(--transition),border-color var(--transition),transform var(--transition)}
      .map-back:hover{border-color:rgba(227,198,139,.6);background:rgba(201,168,106,.16);transform:translateY(-1px)}
      .mind-info-row .map-title-input{flex:1;min-width:0}
      .map-meta{display:flex;flex-wrap:wrap;gap:10px;align-items:center;font:600 11px/1.4 'Inter','Noto Sans SC',sans-serif;letter-spacing:.16em;text-transform:uppercase;color:var(--text-muted)}
      .map-meta span{white-space:nowrap}
      .map-title-input{width:100%;padding:12px 16px;border-radius:16px;border:1px solid rgba(201,168,106,.34);background:rgba(12,16,18,.78);color:var(--text-strong);font:600 17px/1.35 'Cinzel','Noto Serif SC',serif;letter-spacing:.08em;transition:border-color var(--transition),box-shadow var(--transition)}
      .map-title-input:focus{outline:none;border-color:var(--gold-500);box-shadow:0 0 0 3px rgba(227,198,139,.18)}
      .save-state{margin-left:auto;padding:6px 12px;border-radius:999px;border:1px solid rgba(201,168,106,.32);background:rgba(201,168,106,.12);font:600 11px/1 'Inter','Noto Sans SC',sans-serif;letter-spacing:.16em;text-transform:uppercase;color:var(--gold-400);opacity:0;transform:translateY(-6px);transition:opacity var(--t-fast) var(--ease),transform var(--t-fast) var(--ease)}
      .save-state.show{opacity:1;transform:translateY(0)}
      .save-state.dirty{color:var(--accent-crimson);border-color:rgba(209,75,75,.45);background:rgba(209,75,75,.12)}
      @media (max-width:720px){
        .mind-info-bar{left:50%;top:16px;transform:translateX(-50%);width:calc(100% - 24px);max-width:none;padding:8px 12px 10px;gap:6px;border-radius:18px}
        .mind-info-row{gap:8px}
        .map-back{padding:5px 9px;font-size:11px}
        .map-title-input{padding:8px 12px;font-size:15px}
        .save-state{font-size:10px;padding:4px 10px}
        .map-meta{font-size:10px;gap:8px;letter-spacing:.12em;justify-content:space-between}
        .map-meta span{flex:1 1 auto;min-width:0}
      }
      .mind-stage{position:relative;flex:1;border-radius:28px;border:1px solid rgba(201,168,106,.24);background:linear-gradient(160deg,rgba(15,19,22,.9),rgba(10,12,14,.94));box-shadow:inset 0 0 48px rgba(0,0,0,.6),0 18px 38px rgba(0,0,0,.45);overflow:hidden}
      .mind-stage::before{content:"";position:absolute;inset:14px;border-radius:20px;border:1px dashed rgba(201,168,106,.2);opacity:.4;pointer-events:none}
      #jsmind-container{position:absolute;inset:0;overflow:hidden;touch-action:none;background:transparent}
      .mind-background{position:absolute;inset:0;background:radial-gradient(circle at 18% 24%,rgba(227,198,139,.08),transparent 55%),radial-gradient(circle at 68% 12%,rgba(227,198,139,.05),transparent 60%),linear-gradient(120deg,rgba(201,168,106,.06),transparent 65%);pointer-events:none;opacity:.8}
      .mind-viewport,.mind-links{position:absolute;top:0;left:0;transform-origin:0 0}
      .mind-links{pointer-events:none;overflow:visible}
      .mind-links .trace-group{pointer-events:none}
      .mind-links .trace{fill:none;stroke-linecap:round;stroke-linejoin:bevel}
      .mind-links .trace.shadow{stroke:rgba(122,94,54,.55);stroke-width:2.1;opacity:.65;filter:url(#mindSoftGlow)}
      .mind-links .trace.core{stroke:url(#mindGoldTrace);stroke-width:1.6;filter:url(#mindSoftGlow)}
      .mind-links .trace.highlight{stroke:rgba(255,242,218,.32);stroke-width:0.8}
      .mind-nodes{position:absolute;top:0;left:0}
      .jsmind-node{position:absolute;display:flex;flex-direction:column;align-items:flex-start;gap:10px;padding:18px 20px;border-radius:var(--r-md);color:var(--text-strong);font:600 14px/1.5 'Inter','Noto Sans SC',sans-serif;min-width:170px;max-width:320px;background:linear-gradient(180deg,rgba(21,26,30,.94),rgba(15,19,22,.96));border:1.6px solid rgba(201,168,106,.32);box-shadow:0 20px 48px rgba(0,0,0,.58),0 0 30px rgba(227,198,139,.12);transition:transform var(--transition),box-shadow var(--transition),border-color var(--transition),filter var(--transition);backdrop-filter:blur(12px);letter-spacing:.04em}
      .jsmind-node::before{content:"";position:absolute;inset:10px;border-radius:calc(var(--r-md) - 4px);border:1px solid rgba(201,168,106,.22);opacity:.7;pointer-events:none;animation:nodeGlow 9s ease-in-out infinite}
      .jsmind-node::after{content:"";position:absolute;inset:-14px;border-radius:calc(var(--r-md) + 10px);border:1px dashed rgba(201,168,106,.22);opacity:0;pointer-events:none}
      @keyframes nodeGlow{0%,100%{opacity:.4}50%{opacity:.85}}
      .jsmind-node .node-topic{font:600 16px/1.45 'Cinzel','Noto Serif SC',serif;color:var(--gold-400);letter-spacing:.06em;text-transform:uppercase}
      .jsmind-node .node-meta{display:flex;flex-wrap:wrap;gap:8px;color:var(--text-dim);font:500 12px/1.4 'Inter','Noto Sans SC',sans-serif;text-transform:uppercase;letter-spacing:.16em}
      .jsmind-node .node-meta span{padding:2px 8px;border-radius:999px;border:1px solid rgba(201,168,106,.28);background:rgba(21,26,30,.78)}
      .jsmind-node .node-body{color:var(--text-muted);font:400 13px/1.7 'Noto Sans SC','Inter',sans-serif}
      .jsmind-node .node-note{color:var(--text-muted);font:400 13px/1.7 'Noto Sans SC','Inter',sans-serif;white-space:pre-wrap;word-break:break-word}
      .jsmind-node .node-footer{display:flex;gap:8px;flex-wrap:wrap;font:600 11px/1 'Inter','Noto Sans SC',sans-serif;color:var(--text-dim);letter-spacing:.14em;text-transform:uppercase}
      .jsmind-node.isroot{border-width:2px;border-color:rgba(227,198,139,.55);box-shadow:0 0 0 1px rgba(227,198,139,.25),0 30px 60px rgba(0,0,0,.6)}
      .jsmind-node.isroot::after{opacity:.4;animation:ringPulse 3.6s linear infinite}
      @keyframes ringPulse{0%{transform:scale(.88);opacity:.15}50%{transform:scale(1.06);opacity:.32}100%{transform:scale(1.12);opacity:0}}
      .jsmind-node.selected{border-color:var(--gold-400);box-shadow:0 0 0 1px rgba(227,198,139,.32),0 0 40px rgba(227,198,139,.26);transform:translateY(-2px)}
      .jsmind-node.selected::after{opacity:.55;animation:ringPulse 2.4s linear infinite}
      .jsmind-node.is-collapsed{border-style:dashed;border-color:rgba(201,168,106,.4);background:linear-gradient(180deg,rgba(21,26,30,.86),rgba(12,16,18,.9))}
      .jsmind-node:not(.isroot) .node-topic::before{content:"";display:inline-block;width:6px;height:6px;margin-right:8px;border-radius:50%;background:var(--gold-400);box-shadow:0 0 6px rgba(227,198,139,.4);vertical-align:middle}
      .node-collapse-marker{position:absolute;right:18px;bottom:16px;padding:4px 10px;border-radius:999px;border:1px solid rgba(201,168,106,.28);background:rgba(201,168,106,.12);color:var(--gold-400);font:600 10px/1 'Inter','Noto Sans SC',sans-serif;letter-spacing:.14em;text-transform:uppercase;box-shadow:0 0 12px rgba(227,198,139,.18);pointer-events:none}
      .jsmind-node.is-collapsed .node-collapse-marker{background:rgba(201,168,106,.2);border-color:rgba(201,168,106,.42)}
      .mind-dock-wrap{position:fixed;left:50%;bottom:26px;transform:translateX(-50%);pointer-events:none;z-index:120}
      .mind-dock{pointer-events:auto;display:flex;align-items:center;gap:10px;padding:12px 14px;border-radius:28px;background:linear-gradient(180deg,rgba(21,26,30,.9),rgba(12,16,18,.85));border:1px solid rgba(201,168,106,.32);box-shadow:0 18px 40px rgba(0,0,0,.55),0 0 32px rgba(227,198,139,.12) inset;backdrop-filter:blur(12px);position:relative;justify-content:center}
      .dock-btn{position:relative;display:grid;grid-template-rows:auto auto;align-items:center;justify-items:center;width:92px;height:66px;border-radius:18px;padding:8px 6px;background:rgba(201,168,106,.08);border:1px solid rgba(201,168,106,.36);color:var(--gold-400);font:600 13px/1 'Inter','Noto Sans SC',sans-serif;text-transform:uppercase;letter-spacing:.12em;cursor:pointer;transition:transform var(--transition),border-color var(--transition),box-shadow var(--transition),background-color var(--transition);touch-action:manipulation}
      .dock-btn .icon{font-size:20px}
      .dock-btn .label{font-size:12px}
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
      .dock-more{position:relative}
      .dock-menu{position:absolute;bottom:78px;right:0;min-width:180px;padding:10px;margin:0;list-style:none;border-radius:18px;border:1px solid rgba(201,168,106,.32);background:linear-gradient(180deg,rgba(21,26,30,.96),rgba(12,16,18,.9));box-shadow:0 22px 48px rgba(0,0,0,.6);display:none}
      .dock-more[aria-expanded="true"] .dock-menu{display:block}
      .dock-menu li{padding:10px 12px;border-radius:12px;color:var(--text-strong);font:600 13px/1 'Inter','Noto Sans SC',sans-serif;letter-spacing:.12em;text-transform:uppercase;cursor:pointer;transition:background-color var(--transition),color var(--transition)}
      .dock-menu li:hover{background:rgba(201,168,106,.12);color:var(--gold-400)}
      .mind-shell[data-fisheye="on"] .dock-btn{transform-origin:50% 65%}
      @media (max-width:960px){.mind-dock{gap:8px;padding:10px 12px}.dock-btn{width:82px;height:60px}}
      @media (max-width:720px){.mind-dock{padding:10px 12px;border-radius:24px}.dock-btn{width:78px;height:56px}}
      @media (max-width:600px){.mind-dock{flex-wrap:wrap;row-gap:8px;column-gap:8px;max-width:calc(100vw - 24px)}.dock-btn{width:calc(50% - 12px);flex:1 1 calc(50% - 12px);height:56px}.dock-sep{display:none}.dock-more{width:100%}.dock-more .dock-btn{width:100%;flex:1 1 100%}}
      @media (prefers-reduced-motion: reduce){.dock-btn,.dock-btn:hover{transition:none!important;transform:none!important}.mind-shell[data-fisheye="on"] .dock-btn{transform:none!important}}
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
      </defs>
    </svg>
    <div class="mind-shell" data-fisheye="on">
      <div class="mind-stage">
        <header class="mind-info-bar">
          <div class="mind-info-row">
            <a class="map-back" href="<?= htmlspecialchars($_SERVER['PHP_SELF'].'?view=maps') ?>" aria-label="返回导图库">← 导图库</a>
            <label class="sr-only" for="map-title">导图标题</label>
            <input id="map-title" class="map-title-input" value="<?php echo h($mind['title']); ?>" placeholder="输入导图标题">
            <div class="save-state" id="save-state">保存成功</div>
          </div>
          <div class="map-meta">
            <span>导图 ID：<?php echo $mind['id'] ?: '新建'; ?></span>
            <span>最近保存：<?php echo dt((int)$mind['updated_at']); ?></span>
          </div>
        </header>
        <div id="jsmind-container" data-map-id="<?php echo $mind['id']; ?>"></div>
      </div>
      <input id="import-input" type="file" accept="application/json" hidden>
      <input id="attach-file-input" type="file" accept="image/*,application/pdf,application/zip,application/x-zip-compressed,text/plain,text/markdown,text/csv,application/json,video/*" hidden>
      <?php if ($mind['id']): ?>
        <form id="delete-map-form" method="post" hidden>
          <input type="hidden" name="action" value="delete_mindmap">
          <input type="hidden" name="id" value="<?php echo $mind['id']; ?>">
        </form>
      <?php endif; ?>
      <div class="mind-dock-wrap">
        <nav class="mind-dock" id="mind-dock" role="toolbar" aria-label="思维导图操作工具栏">
          <button class="dock-btn" data-action="save" data-default-label="保存" title="保存 (Ctrl+S)" aria-label="保存">
            <span class="icon">💾</span>
            <span class="label">保存</span>
          </button>
          <button class="dock-btn" data-action="sibling" title="新增同级 (Enter)" aria-label="新增同级节点">
            <span class="icon">⧉</span>
            <span class="label">同级</span>
          </button>
          <button class="dock-btn" data-action="child" title="新增子级 (Tab)" aria-label="新增子级节点">
            <span class="icon">↳</span>
            <span class="label">子级</span>
          </button>
          <button class="dock-btn" data-action="attach" title="上传附件" aria-label="上传附件">
            <span class="icon">📎</span>
            <span class="label">附件</span>
          </button>
          <button class="dock-btn" data-action="link" title="新增链接" aria-label="新增链接">
            <span class="icon">🔗</span>
            <span class="label">链接</span>
          </button>
          <button class="dock-btn danger" data-action="delete" title="删除节点 (Del)" aria-label="删除节点">
            <span class="icon">🗑</span>
            <span class="label">删除</span>
          </button>
          <div class="dock-sep" aria-hidden="true"></div>
          <div class="dock-more" aria-expanded="false">
            <button class="dock-btn ghost" type="button" aria-haspopup="menu" aria-expanded="false" title="更多选项" aria-label="更多">
              <span class="icon">⋯</span>
              <span class="label">更多</span>
            </button>
            <ul class="dock-menu" role="menu">
              <li role="menuitem" data-action="import">导入 JSON</li>
              <li role="menuitem" data-action="export">导出 JSON</li>
              <?php if ($mind['id']): ?>
              <li role="menuitem" data-action="delete-map" data-danger="true">删除导图</li>
              <?php endif; ?>
            </ul>
          </div>
        </nav>
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
          <textarea id="node-note" rows="4" placeholder="例如：&#10;状态：已完成&#10;优先级：高&#10;负责人：张三&#10;标签：#重要 #任务"></textarea>
        </div>
        <div class="field fold-field" id="node-fold-field" hidden>
          <div class="fold-row">
            <label for="node-fold-toggle">折叠子节点</label>
            <label class="toggle-switch">
              <input type="checkbox" id="node-fold-toggle">
              <span class="track"><span class="thumb"></span></span>
              <span class="toggle-text" id="node-fold-toggle-text">展开中</span>
            </label>
          </div>
          <p class="fold-hint" id="node-fold-hint"></p>
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
          this.background=document.createElement('div');
          this.background.className='mind-background';
          this.container.appendChild(this.background);
          this.viewport=document.createElement('div');
          this.viewport.className='mind-viewport';
          this.linkLayer=document.createElementNS('http://www.w3.org/2000/svg','svg');
          this.linkLayer.classList.add('mind-links');
          this.viewport.appendChild(this.linkLayer);
          this.nodeLayer=document.createElement('div');
          this.nodeLayer.className='mind-nodes';
          this.viewport.appendChild(this.nodeLayer);
          this.container.appendChild(this.viewport);
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
          this.linkRegistry=new Map();
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
                const nextScale=Math.max(0.3, Math.min(2.5, pinch.baseScale * (distance / pinch.initialDistance)));
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
        handleWheel(evt){
          if(!evt) return;
          if(evt.ctrlKey || evt.metaKey){
            evt.preventDefault();
            const delta=Math.max(-1, Math.min(1, evt.deltaY));
            const factor=delta<0?1.12:0.9;
            const prevScale=this.scale;
            const nextScale=Math.max(0.3, Math.min(2.5, prevScale*factor));
            if(Math.abs(nextScale-prevScale)<0.0001) return;
            const rect=this.container.getBoundingClientRect();
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
        remove_node(id){
          const node=this.nodes.get(id);
          if(!node || node.isroot) return;
          const parent=node.parent;
          parent.children=parent.children.filter(child=>child!==node);
          if(parent.model && parent.model.children){
            parent.model.children=parent.model.children.filter(child=>child.id!==id);
          }
          const stack=[node];
          while(stack.length){
            const cur=stack.pop();
            this.nodes.delete(cur.id);
            if(cur.children && cur.children.length){ stack.push(...cur.children); }
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
          this.sizeCache.clear();
          this.measureHost.innerHTML='';
          const measureNode=(node)=>{
            if(!node) return;
            const el=this.buildNodeElement(node,{forMeasure:true});
            this.measureHost.appendChild(el);
            const rect=el.getBoundingClientRect();
            this.sizeCache.set(node.id,{width:rect.width,height:rect.height});
            el.remove();
            if(node.expanded!==false && node.children && node.children.length){ node.children.forEach(child=>measureNode(child)); }
          };
          measureNode(this.root);
        }
        computeLayout(){
          if(!this.root) return;
          this.collectNodeSizes();
          const H_SPACING=220;
          const MIN_SPACING=40;
          const MIN_HEIGHT=60;
          const heightMap=new Map();
          const getNodeHeight=(node)=>{
            if(!node) return MIN_HEIGHT;
            const cached=this.sizeCache.get(node.id);
            if(cached && cached.height){ return Math.max(MIN_HEIGHT, cached.height); }
            return MIN_HEIGHT;
          };
          const gapBetween=(a,b)=>Math.max(MIN_SPACING, Math.min(160,(a+b)*0.25));
          const measure=(node)=>{
            if(!node) return MIN_HEIGHT;
            if(heightMap.has(node.id)) return heightMap.get(node.id);
            const base=getNodeHeight(node);
            if(!node.expanded || !node.children.length){ heightMap.set(node.id, base); return base; }
            const visible=node.children.filter(Boolean);
            if(!visible.length){ heightMap.set(node.id, base); return base; }
            const childHeights=visible.map(child=>measure(child));
            let total=0;
            for(let i=0;i<childHeights.length;i++){
              total+=childHeights[i];
              if(i<childHeights.length-1){ total+=gapBetween(childHeights[i], childHeights[i+1]); }
            }
            const result=Math.max(base,total);
            heightMap.set(node.id,result);
            return result;
          };
          const subtreeHeight=(nodes)=>{
            if(!nodes || !nodes.length) return 0;
            let total=0;
            for(let i=0;i<nodes.length;i++){
              const node=nodes[i];
              const h=measure(node);
              total+=h;
              if(i<nodes.length-1){ total+=gapBetween(h, measure(nodes[i+1])); }
            }
            return total;
          };
          const right=this.root.children.filter(Boolean);
          const rightHeight=subtreeHeight(right);
          const rootHeight=getNodeHeight(this.root);
          const canvasHeight=Math.max(rootHeight, rightHeight);
          this.root.x=0;
          this.root.y=canvasHeight/2;
          this.root.dir=0;
          this.root.direction='center';
          if(this.root.model){ this.root.model.direction='center'; }
          const assign=(node,depth,startTop)=>{
            const height=measure(node);
            node.x=this.root.x + depth*H_SPACING;
            node.y=startTop+height/2;
            node.dir=depth===0?0:1;
            node.direction=depth===0?'center':'right';
            if(node.model){ node.model.direction=node.direction; }
            if(!node.expanded || !node.children.length) return height;
            let cursor=startTop;
            const children=node.children.filter(Boolean);
            for(let i=0;i<children.length;i++){
              const child=children[i];
              const childHeight=assign(child, depth+1, cursor);
              cursor+=childHeight;
              if(i<children.length-1){
                const nextHeight=measure(children[i+1]);
                cursor+=gapBetween(childHeight, nextHeight);
              }
            }
            return height;
          };
          let rightTop=this.root.y - rightHeight/2;
          for(let i=0;i<right.length;i++){
            const child=right[i];
            const h=assign(child,1,rightTop);
            rightTop+=h;
            if(i<right.length-1){ rightTop+=gapBetween(h, measure(right[i+1])); }
          }
          this.bounds={minX:Infinity,maxX:-Infinity,minY:Infinity,maxY:-Infinity};
          for(const node of this.nodes.values()){
            if(node.y<this.bounds.minY) this.bounds.minY=node.y;
            if(node.y>this.bounds.maxY) this.bounds.maxY=node.y;
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
          this.linkLayer.setAttribute('viewBox',`0 0 ${this.bounds.width} ${this.bounds.height}`);
          this.linkLayer.setAttribute('width',`${this.bounds.width}`);
          this.linkLayer.setAttribute('height',`${this.bounds.height}`);
          this.linkLayer.style.width=`${this.bounds.width}px`;
          this.linkLayer.style.height=`${this.bounds.height}px`;
          this.nodeLayer.style.width=`${this.bounds.width}px`;
          this.nodeLayer.style.height=`${this.bounds.height}px`;
          this.viewport.style.width=`${this.bounds.width}px`;
          this.viewport.style.height=`${this.bounds.height}px`;
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
          if(node.children && node.children.length && node.expanded===false){
            const marker=document.createElement('span');
            marker.className='node-collapse-marker';
            marker.textContent='⤴ 已折叠';
            marker.title='该节点包含折叠的子节点';
            marker.setAttribute('aria-hidden','true');
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
          this.nodeLayer.innerHTML='';
          while(this.linkLayer.firstChild){ this.linkLayer.removeChild(this.linkLayer.firstChild); }
          if(this.resizeObserver){ this.resizeObserver.disconnect(); }
          this.linkRegistry.clear();
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
              this.linkRegistry.set(node.id,{group,shadow,core,highlight});
              this.updateLinkPath(node);
            }
            if(this.resizeObserver){ this.resizeObserver.observe(node.el); }
            if(node.expanded){
              node.children.forEach(child=>walk(child));
            }
          };
          walk(this.root);
          this.applyTransform(true);
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
          const start=isLeft ? parent.anchors.left : parent.anchors.right;
          const end=isLeft ? node.anchors.right : node.anchors.left;
          const route=buildTraceRoute(start,end,isLeft?-1:1);
          let pathData=buildChamferedPath(route, TRACE_CHAMFER);
          if(!pathData){
            pathData=`M${start.x} ${start.y} L${end.x} ${end.y}`;
          }
          node.linkPath.setAttribute('d', pathData);
          if(node.linkShadow){ node.linkShadow.setAttribute('d', pathData); }
          if(node.linkHighlight){ node.linkHighlight.setAttribute('d', pathData); }
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
          });
        }
        applyTransform(initial){
          if(!this.bounds){ return; }
          if(initial && !this.hasCentered){ this.center_root(); this.hasCentered=true; return; }
          const transform=`translate(${this.offsetX}px, ${this.offsetY}px) scale(${this.scale})`;
          this.viewport.style.transform=transform;
        }
        zoom(step){
          const prev=this.scale;
          this.scale=Math.max(0.3, Math.min(2.5, this.scale*step));
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
          const scale=Math.min(rect.width/(this.bounds.width+200), rect.height/(this.bounds.height+200));
          this.scale=Math.max(0.3, Math.min(2.5, scale));
          this.center_root();
          return true;
        }
        set_zoom(z){
          if(typeof z!=='number' || !isFinite(z)) return false;
          this.scale=Math.max(0.3, Math.min(2.5, z));
          this.applyTransform();
          return true;
        }
        center_root(){
          if(!this.root || !this.bounds) return false;
          const rect=this.container.getBoundingClientRect();
          const centerX=this.root.absX + (this.root.el?this.root.el.offsetWidth/2:0);
          const centerY=this.root.absY + (this.root.el?this.root.el.offsetHeight/2:0);
          this.offsetX=rect.width/2 - centerX*this.scale;
          this.offsetY=rect.height/2 - centerY*this.scale;
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
      });
      const blobUrlRegistry=new Set();
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
        if(descriptor.assetId && descriptor.url){
          const win=window.open(descriptor.url,'_blank','noopener');
          if(!win){ window.location.href=descriptor.url; }
          return;
        }
        if(descriptor.content){
          const blob=dataUrlToBlob(descriptor.content);
          if(!blob){ alert('附件不可用'); return; }
          const url=URL.createObjectURL(blob);
          blobUrlRegistry.add(url);
          const a=document.createElement('a');
          a.href=url;
          a.download=descriptor.name || 'attachment';
          document.body.appendChild(a);
          a.click();
          a.remove();
          setTimeout(()=>{
            if(blobUrlRegistry.has(url)){ URL.revokeObjectURL(url); blobUrlRegistry.delete(url); }
          },1500);
          return;
        }
        if(descriptor.url){
          const win=window.open(descriptor.url,'_blank','noopener');
          if(!win){ window.location.href=descriptor.url; }
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
          if(typeof jm.update_node==='function'){ jm.update_node(nodeId, value); }
          markDirty();
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
      const mindShell=document.querySelector('.mind-shell');
      const dock=document.getElementById('mind-dock');
      const dockButtons=dock ? Array.from(dock.querySelectorAll('.dock-btn[data-action]')) : [];
      const dockSaveButton=dock ? dock.querySelector('.dock-btn[data-action="save"]') : null;
      const dockSaveLabel=dockSaveButton ? dockSaveButton.querySelector('.label') : null;
      const dockMore=dock ? dock.querySelector('.dock-more') : null;
      const dockMoreButton=dockMore ? dockMore.querySelector('.dock-btn') : null;
      const dockMenu=dockMore ? dockMore.querySelector('.dock-menu') : null;
      const foldAllMenuItem=dockMenu ? dockMenu.querySelector('[data-action="fold-all"]') : null;
      const nodePopover=document.getElementById('node-popover');
      const sheetHandle=nodePopover ? nodePopover.querySelector('.sheet-handle') : null;
      const popoverHeader=nodePopover ? nodePopover.querySelector('header') : null;
      const nodeContextMenu=document.getElementById('node-context-menu');
      const settingsLayer=document.getElementById('mind-settings');
      const gridToggle=document.getElementById('setting-grid');
      const fisheyeToggle=document.getElementById('setting-fisheye');
      const pointerMedia=window.matchMedia ? window.matchMedia('(pointer: coarse)') : null;
      let pointerIsCoarse=pointerMedia ? pointerMedia.matches : false;
      if(pointerIsCoarse && fisheyeToggle){ fisheyeToggle.checked=false; }
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
      const deleteMapForm=document.getElementById('delete-map-form');
      let saveButtonDefault=dockSaveLabel ? (dockSaveButton?.dataset.defaultLabel || dockSaveLabel.textContent || '保存') : '保存';
      if(dockSaveButton && !dockSaveButton.dataset.defaultLabel){ dockSaveButton.dataset.defaultLabel=saveButtonDefault; }
      let dirty=false;
      const commandLog=[];
      window.__mindmapCommands=commandLog;
      let contextMenuState=null;
      const ATTACH_MAX_BYTES=15*1024*1024;
      const imageExts=['.png','.jpg','.jpeg','.gif','.webp','.bmp','.svg','.avif','.heic','.heif'];
      const textExts=['.txt','.md','.markdown','.csv','.json','.yaml','.yml','.log'];
      const videoExts=['.mp4','.mov','.mkv','.avi','.webm','.m4v'];
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
        const changed=setAllNodesExpanded(target);
        if(changed){
          markDirty();
          scheduleHandleRefresh();
          requestAnimationFrame(()=>refreshInspector(jm.get_selected_node()));
        }
        updateFoldAllLabel();
      }
      function setNodeExpandedState(node, expanded){
        if(!node) return;
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
          requestAnimationFrame(()=>refreshInspector(jm.get_node(node.id)));
        }else{
          updateFoldToggleUI(jm.get_node(node.id));
        }
        updateFoldAllLabel();
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
          if(!longPressState || e.pointerId!==longPressState.pointerId) return;
          if(longPressState.triggered) return;
          const dx=Math.abs(e.clientX-longPressState.startX);
          const dy=Math.abs(e.clientY-longPressState.startY);
          if(dx>LONG_PRESS_TOLERANCE || dy>LONG_PRESS_TOLERANCE){ cancelLongPressState(); }
        });
        const finishLongPress=(e)=>{
          if(!longPressState || e.pointerId!==longPressState.pointerId) return;
          const triggered=!!longPressState.triggered;
          cancelLongPressState();
          if(triggered){ e.preventDefault(); e.stopPropagation(); }
        };
        jmContainer.addEventListener('pointerup',finishLongPress);
        jmContainer.addEventListener('pointercancel',finishLongPress);
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
        inspectorSyncing=false;
        if(popoverOpen){ positionInspectorPopover(node); }
      }
      refreshInspector(jm.get_selected_node());
      if(jm.options){ jm.options.onNodeDetails=openInspectorPopover; }
      function applyInspectorChange(mutator){
        if(typeof mutator!=='function') return;
        const node=ensureNode();
        if(!node) return;
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
          updateHandlePosition();
          refreshInspector(jm.get_node(node.id));
        });
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
        const command={
          type:'createNode',
          id:newNode.id,
          parentId:parent.id,
          topic:input.topic || '新节点',
          data:deepClone(input.data),
          style:deepClone(input.style),
          position:input.position ? {x:input.position.x, y:input.position.y} : null,
          timestamp:Date.now(),
          meta:deepClone(input.meta)
        };
        commandLog.push(command);
        window.__mindmapCommands=commandLog;
        jm.select_node(newNode.id);
        markDirty();
        scheduleHandleRefresh();
        refreshInspector(jm.get_node(newNode.id));
        return newNode;
      }
      function randomId(){ return 'node-' + Math.random().toString(36).slice(2,10); }
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
        if(type.startsWith('text/')) return true;
        if(type==='application/pdf' || type==='application/zip' || type==='application/x-zip-compressed' || type==='application/json') return true;
        const ext=fileExtension(file.name);
        if(!ext) return false;
        if(imageExts.includes(ext) || textExts.includes(ext) || videoExts.includes(ext)) return true;
        return ext==='.pdf' || ext==='.zip';
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
            alert(file.name+' 类型不支持，仅可上传图片、PDF、ZIP、文本或视频文件。');
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
        commitInlineEditing();
        jm.remove_node(node.id); markDirty();
        scheduleHandleRefresh();
        requestAnimationFrame(()=>refreshInspector(jm.get_selected_node()));
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
      if(dock){
        const closeDockMenu=()=>{
          if(dockMore){ dockMore.setAttribute('aria-expanded','false'); }
          if(dockMoreButton){ dockMoreButton.setAttribute('aria-expanded','false'); }
        };
        const openDockMenu=()=>{
          if(dockMore){ dockMore.setAttribute('aria-expanded','true'); }
          if(dockMoreButton){ dockMoreButton.setAttribute('aria-expanded','true'); }
          updateFoldAllLabel();
        };
        const toggleDockMenu=()=>{
          const expanded=dockMoreButton && dockMoreButton.getAttribute('aria-expanded')==='true';
          if(expanded) closeDockMenu(); else openDockMenu();
        };
        if(dockMoreButton){
          dockMoreButton.addEventListener('click',e=>{
            e.preventDefault();
            e.stopPropagation();
            toggleDockMenu();
          });
        }
        document.addEventListener('pointerdown',e=>{
          if(!dockMore || dockMore.getAttribute('aria-expanded')!=='true') return;
          if(dockMore.contains(e.target)) return;
          closeDockMenu();
        });
        const handleDockAction=(action)=>{
          switch(action){
            case 'save': saveMindmap(); break;
            case 'sibling': addSiblingNode(); break;
            case 'child': addChildNode(); break;
            case 'attach': openAttachmentDialog(); break;
            case 'link': openLinkPrompt(); break;
            case 'delete': deleteSelectedNode(); break;
            case 'import': triggerImport(); break;
            case 'export': exportMindmap(); break;
            case 'delete-map':
              if(deleteMapForm && confirm('确认删除该导图？')){ deleteMapForm.submit(); }
              break;
          }
        };
        dock.addEventListener('click',e=>{
          const btn=e.target.closest('.dock-btn');
          if(!btn || !dock.contains(btn)) return;
          const action=btn.dataset.action;
          if(action){
            if(btn!==dockMoreButton) closeDockMenu();
            handleDockAction(action);
          }
        });
        dock.addEventListener('keydown',e=>{
          if((e.key==='Enter' || e.key===' ') && e.target instanceof HTMLElement && e.target.closest('.dock-btn')){
            const btn=e.target.closest('.dock-btn');
            if(btn && btn.dataset.action){
              e.preventDefault();
              handleDockAction(btn.dataset.action);
            }
          }else if(e.key==='Escape'){ closeDockMenu(); }
        });
        const applyFisheye=(event)=>{
          if(!dockButtons.length) return;
          if(!isFisheyeEnabled()){ dockButtons.forEach(btn=>btn.style.transform=''); return; }
          const rect=dock.getBoundingClientRect();
          const centerX=event.clientX-rect.left;
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
        const activeEl=document.activeElement;
        if(activeEl){
          const tag=activeEl.tagName || '';
          if(activeEl.isContentEditable || /input|textarea|select/i.test(tag)) return;
        }
        if(currentEditingId()) return;
        if(e.key==='Enter' && !e.shiftKey){ e.preventDefault(); addSiblingNode(); }
        else if(e.key==='Tab' && e.shiftKey){ e.preventDefault(); focusParentNode(); }
        else if(e.key==='Tab'){ e.preventDefault(); addChildNode(); }
        else if(e.key==='Delete' || e.key==='Backspace'){ e.preventDefault(); deleteSelectedNode(); }
        else if(e.key==='F2'){ e.preventDefault(); renameSelectedNode(); }
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
          }
          if(type===jsMind.event_type.select || type===jsMind.event_type.refresh || type===jsMind.event_type.after_edit || type===jsMind.event_type.show){
            scheduleHandleRefresh();
            requestAnimationFrame(()=>refreshInspector(jm.get_selected_node()));
            updateFoldAllLabel();
          }
          if(type===jsMind.event_type.edit || type===jsMind.event_type.after_edit || type===jsMind.event_type.update){ markDirty(); }
        });
      }
      function exportMindmap(){
        const data=jm.get_data('node_tree');
        if(data && data.data){ enforceRightOrientation(data.data); }
        const blob=new Blob([JSON.stringify(data,null,2)],{type:'application/json'});
        const url=URL.createObjectURL(blob);
        const a=document.createElement('a');
        a.href=url;
        const titleValue=titleInput ? titleInput.value.trim() : '';
        a.download=(titleValue || 'mindmap')+'.json';
        a.click();
        setTimeout(()=>URL.revokeObjectURL(url), 1000);
      }
      function triggerImport(){
        if(importInput){ importInput.click(); }
      }
      if(importInput){
        importInput.addEventListener('change', e=>{
          const file=e.target.files[0]; if(!file) return;
          const reader=new FileReader();
          reader.onload=evt=>{
            try{
              const json=JSON.parse(evt.target.result);
              if(json && json.data){
                commitInlineEditing();
                enforceRightOrientation(json.data);
                jm.show(json);
                initialData=JSON.parse(JSON.stringify(json));
                if(initialData && initialData.data){ enforceRightOrientation(initialData.data); }
                markDirty();
              }
              else alert('文件格式不兼容');
            }catch(err){ alert('无法解析 JSON：'+err.message); }
          };
          reader.readAsText(file,'utf-8');
        });
      }
      if(dockSaveButton){
        dockSaveButton.addEventListener('click',e=>{ e.preventDefault(); saveMindmap(); });
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
  $maps = get_mindmaps();
  ?>
  <!doctype html>
  <html lang="zh-Hans">
  <head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <title>思维导图库</title>
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
        --text-muted:#A7A39A;
        --text-dim:#7A766E;
        --divider:rgba(201,168,106,.2);
        --bg:var(--bg-void);
        --panel:rgba(21,26,30,.9);
        --panel-strong:rgba(15,19,22,.94);
        --glow:var(--gold-500);
        --glow-soft:rgba(227,198,139,.24);
        --border:rgba(201,168,106,.34);
        --grid-size:72px;
        --transition:300ms cubic-bezier(.22,.61,.36,1);
      }
      *,*::before,*::after{box-sizing:border-box}
      html,body{margin:0;min-height:100vh;background:var(--bg-void);color:var(--text-strong);font:16px/1.65 'Source Han Sans','Noto Sans SC','Inter','Microsoft YaHei',sans-serif;letter-spacing:.01em;position:relative;overflow-x:hidden}
      body{animation:breathing 12s ease-in-out infinite;background:
        radial-gradient(1200px 700px at 70% -10%,rgba(227,198,139,.06),transparent 60%),
        linear-gradient(120deg,rgba(201,168,106,.08),transparent 30%,rgba(201,168,106,.06) 70%,transparent 90%),
        #0A0C0E;
      }
      body::before{content:"";position:fixed;inset:0;background:
        linear-gradient(180deg,rgba(10,12,14,.45),rgba(10,12,14,.82)),
        url('data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" width="160" height="160" viewBox="0 0 160 160"%3E%3Cpath fill="rgba(201,168,106,0.05)" d="M0 79h160v2H0zm79-79h2v160h-2z"/%3E%3C/svg%3E');
        background-size:cover,160px 160px;opacity:.6;pointer-events:none;z-index:-3;
      }
      body::after{content:"";position:fixed;inset:0;background:
        repeating-linear-gradient(0deg,rgba(75,195,209,.08) 0,rgba(75,195,209,.08) 1px,transparent 1px,transparent var(--grid-size)),
        repeating-linear-gradient(90deg,rgba(201,168,106,.12) 0,rgba(201,168,106,.12) 1px,transparent 1px,transparent var(--grid-size));
        mix-blend-mode:screen;opacity:.3;pointer-events:none;z-index:-4;animation:grid-pan 18s linear infinite;
      }
      @keyframes breathing{0%,100%{filter:brightness(.92)}50%{filter:brightness(1.02)}}
      .scanlines{position:fixed;inset:0;pointer-events:none;z-index:-1;background:linear-gradient(to bottom,rgba(75,195,209,.12) 0,transparent 4px);background-size:100% 6px;opacity:.24;animation:scan 12s linear infinite}
      @keyframes grid-pan{0%{background-position:0 0,0 0}100%{background-position:0 var(--grid-size),var(--grid-size) 0}}
      @keyframes scan{0%{transform:translateY(-100%)}100%{transform:translateY(100%)}}
      a{color:inherit;text-decoration:none}
      .wrap{max-width:1180px;margin:0 auto;padding:32px 20px 80px;position:relative;z-index:0}
      .wrap::before{content:"";position:absolute;inset:20px;border-radius:28px;border:1px dashed rgba(201,168,106,.24);pointer-events:none}
      .header{display:flex;gap:16px;align-items:center;justify-content:space-between;flex-wrap:wrap;margin-bottom:24px}
      .header h1{margin:0;font:600 28px/1.2 'Cinzel','Noto Serif SC',serif;letter-spacing:.18em;text-transform:uppercase;color:var(--gold-400);text-shadow:0 0 26px rgba(227,198,139,.28)}
      .header .meta{color:var(--text-muted);font:14px/1.7 'Inter','Noto Sans SC',sans-serif;letter-spacing:.12em}
      .btn{position:relative;padding:12px 18px;border-radius:16px;border:1px solid rgba(201,168,106,.38);background:rgba(21,26,30,.82);color:var(--gold-400);font:600 12px/1 'Inter','Noto Sans SC',sans-serif;text-transform:uppercase;letter-spacing:.16em;cursor:pointer;transition:transform var(--transition),border-color var(--transition);box-shadow:none;overflow:hidden}
      .btn::after{content:none}
      .btn:hover{transform:translateY(-2px);border-color:rgba(227,198,139,.6)}
      .btn.acc{background:linear-gradient(135deg,rgba(201,168,106,.18),rgba(170,140,84,.26));color:var(--bg-void);box-shadow:none}
      .btn.danger{border-color:rgba(209,75,75,.55);color:#F6D6D6;box-shadow:none}
      .btn:focus-visible{outline:2px solid var(--accent-cyan);outline-offset:3px}
      .search{margin-top:20px;display:flex;gap:12px;align-items:center;padding:12px 16px;border-radius:18px;border:1px solid rgba(201,168,106,.34);background:linear-gradient(135deg,rgba(21,26,30,.82),rgba(15,19,22,.92));box-shadow:inset 0 0 22px rgba(0,0,0,.5);max-width:480px}
      .search input{all:unset;flex:1;color:var(--text-strong);font-size:15px;letter-spacing:.06em}
      .search input::placeholder{color:var(--text-dim)}
      .search span{font-size:20px;font-weight:600;color:var(--gold-400);text-shadow:0 0 16px rgba(227,198,139,.3)}
      .grid{margin-top:28px;display:grid;gap:18px;grid-template-columns:repeat(auto-fill,minmax(300px,1fr))}
      .card{position:relative;display:flex;flex-direction:column;gap:14px;min-height:260px;padding:22px;border-radius:24px;background:linear-gradient(180deg,rgba(21,26,30,.9),rgba(15,19,22,.94));border:1px solid rgba(201,168,106,.32);box-shadow:0 24px 60px rgba(0,0,0,.58);transition:transform var(--transition),box-shadow var(--transition),border-color var(--transition)}
      .card::before{content:"";position:absolute;inset:12px;border-radius:20px;box-shadow:inset 0 0 0 1px rgba(227,198,139,.18),inset 0 0 34px rgba(227,198,139,.08);pointer-events:none;animation:cardGlow 12s ease-in-out infinite}
      @keyframes cardGlow{0%,100%{opacity:.55}50%{opacity:1}}
      .card:hover{transform:translateY(-6px);border-color:rgba(201,168,106,.45);box-shadow:0 0 24px rgba(201,168,106,.32),0 32px 68px rgba(0,0,0,.6)}
      .card h2{margin:0;font:600 18px/1.4 'Cinzel','Noto Serif SC',serif;color:var(--gold-400);letter-spacing:.08em;text-shadow:0 0 18px rgba(227,198,139,.22)}
  .meta{color:var(--text-muted);font:12px/1.6 'Inter','Noto Sans SC',sans-serif;letter-spacing:.1em}
  .meta-inline{display:flex;gap:8px;align-items:center;margin-top:6px;flex-wrap:wrap;color:var(--text-muted)}
      pre{background:rgba(15,19,22,.85);border:1px solid rgba(201,168,106,.28);padding:14px;border-radius:18px;max-height:160px;overflow:auto;font:12px/1.6 'JetBrains Mono','Fira Code',monospace;color:var(--text-strong);box-shadow:inset 0 0 18px rgba(0,0,0,.45)}
      .card-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:auto}
      .card-actions .btn{flex:1 0 auto;text-align:center}
      .empty{margin-top:48px;padding:48px;border:1px dashed rgba(201,168,106,.34);border-radius:24px;text-align:center;color:var(--text-muted);background:rgba(15,19,22,.85);box-shadow:0 24px 48px rgba(0,0,0,.55)}
      .empty strong{color:var(--gold-400);font-size:18px;letter-spacing:.12em;text-transform:uppercase}
      .tag{display:inline-flex;align-items:center;padding:4px 10px;border-radius:999px;border:1px solid rgba(201,168,106,.32);background:rgba(21,26,30,.78);color:var(--text-muted);font:600 11px/1 'Inter','Noto Sans SC',sans-serif;letter-spacing:.16em;text-transform:uppercase}
      @media (max-width:720px){
        .grid{grid-template-columns:1fr}
        .header{align-items:flex-start}
      }
    </style>

  </head>
<body>
  <div class="scanlines" aria-hidden="true"></div>
  <div class="wrap">
      <div class="header">
        <div>
          <h1>思维导图库</h1>
          <div class="meta">集中管理所有导图，支持多版本协作、导入导出与快速检索。</div>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap">
          <a class="btn" href="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>">← 返回备忘录</a>
          <a class="btn acc" href="<?= htmlspecialchars($_SERVER['PHP_SELF'].'?view=map_edit') ?>">＋ 新建导图</a>
        </div>
      </div>
      <div class="search">
        <span>🔍</span>
        <input id="mind-search" placeholder="搜索标题或大纲关键字">
      </div>
      <?php if (!$maps): ?>
        <div class="empty">
          <strong>暂时还没有导图。</strong><br>点击右上角「新建导图」开始构建第一个思维导图。
        </div>
      <?php else: ?>
        <div class="grid" id="mind-grid">
          <?php foreach ($maps as $m): $outline = mindmap_outline_preview($m['content']); ?>
            <article class="card" data-title="<?php echo h($m['title']); ?>" data-outline="<?php echo h(str_replace("\n",' ',$outline)); ?>">
              <div>
                <h2><?php echo h($m['title']); ?></h2>
                <div class="meta">更新：<?php echo dt((int)$m['updated_at']); ?> · 创建：<?php echo dt((int)$m['created_at']); ?></div>
              </div>
              <?php if ($outline !== ''): ?>
                <pre><?php echo h($outline); ?></pre>
              <?php else: ?>
                <pre>（暂无内容）</pre>
              <?php endif; ?>
              <div class="card-actions">
                <a class="btn acc" href="<?= htmlspecialchars($_SERVER['PHP_SELF'].'?view=map_edit&id='.$m['id']) ?>">编辑</a>
                <form method="post" onsubmit="return confirm('确认删除该导图？');">
                  <input type="hidden" name="action" value="delete_mindmap">
                  <input type="hidden" name="id" value="<?php echo $m['id']; ?>">
                  <button class="btn danger" type="submit">删除</button>
                </form>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
    <script>
      const searchInput=document.getElementById('mind-search');
      const cards=document.querySelectorAll('#mind-grid .card');
      if(searchInput){
        searchInput.addEventListener('input',()=>{
          const q=searchInput.value.trim().toLowerCase();
          cards.forEach(card=>{
            const text=(card.dataset.title+' '+card.dataset.outline).toLowerCase();
            card.style.display = q==='' || text.includes(q) ? '' : 'none';
          });
        });
      }
    </script>
  </body>
  </html>
  <?php
  exit;
}

// —— 首页 ——
$pdo=db(); [$cats,$counts]=get_categories();
$cat=$_GET['cat'] ?? 'all'; $q=trim((string)($_GET['q'] ?? '')); $params=[]; $where=[];
if($cat!=='all' && ctype_digit((string)$cat)){ $where[]='category_id = :cat'; $params[':cat']=(int)$cat; }
if($q!==''){ $where[]='(title LIKE :q OR description LIKE :q)'; $params[':q']='%'.$q.'%'; }
$sql='SELECT * FROM items'; if($where) $sql.=' WHERE '.implode(' AND ',$where); $sql.=' ORDER BY order_index ASC, updated_at DESC, id DESC';
$st=$pdo->prepare($sql); $st->execute($params); $items=$st->fetchAll();
$all_total = (int)$pdo->query('SELECT COUNT(*) FROM items')->fetchColumn();
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
    --gold-700:#AA8C54;
    --gold-600:#C9A86A;
    --gold-500:#D1B274;
    --gold-400:#E3C68B;
    --accent-emerald:#24C2A0;
    --accent-crimson:#D14B4B;
    --accent-cyan:#4BC3D1;
    --text-strong:#E8E5DF;
    --text-muted:#A7A39A;
    --text-dim:#7A766E;
    --divider:rgba(201,168,106,.2);
    --text:var(--text-strong);
    --bg:var(--bg-void);
    --panel:rgba(21,26,30,.9);
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
    animation:grid-shift 22s linear infinite;
  }
  @keyframes grid-shift{0%{background-position:0 0,0 0,0 0,0 0}100%{background-position:0 0,0 0,0 var(--grid-size),var(--grid-size) 0}}
  .scanlines{position:fixed;inset:0;pointer-events:none;z-index:-1;background:linear-gradient(to bottom,rgba(75,195,209,.14) 0,transparent 4px);background-size:100% 6px;opacity:.24;animation:scan 12s linear infinite}
  @keyframes scan{0%{transform:translateY(-100%)}100%{transform:translateY(100%);}}
  a{color:inherit;text-decoration:none}
  .app{display:grid;grid-template-columns:280px 1fr;min-height:100vh;position:relative;z-index:0}
  .sidebar{position:sticky;top:0;align-self:start;height:100vh;overflow:auto;background:linear-gradient(165deg,rgba(12,14,18,.94) 0%,rgba(15,19,22,.9) 55%,rgba(21,26,30,.9) 100%);border-right:1px solid var(--border);box-shadow:inset 0 0 0 1px rgba(201,168,106,.08),0 18px 45px rgba(0,0,0,.45);padding:20px;backdrop-filter:blur(18px) saturate(170%);
    transition:transform var(--transition),box-shadow var(--transition);
  }
  .brand{display:flex;gap:12px;align-items:center;margin-bottom:18px;text-transform:uppercase;letter-spacing:.16em;color:var(--text-muted)}
  .brand .logo{width:34px;height:34px;border-radius:12px;background:radial-gradient(circle at 30% 30%,rgba(227,198,139,.85),rgba(227,198,139,.22));box-shadow:0 0 14px rgba(227,198,139,.45),0 0 32px rgba(227,198,139,.28);position:relative;overflow:hidden}
  .brand .logo::after{content:"";position:absolute;inset:6px;border-radius:10px;border:1px solid rgba(201,168,106,.38);box-shadow:0 0 16px rgba(227,198,139,.3);animation:breathe 6s ease-in-out infinite}
  .brand h1{font:600 16px/1.2 'Cinzel','Noto Serif SC',serif;color:var(--gold-400);text-shadow:0 0 18px rgba(227,198,139,.25)}
  .controls{display:flex;gap:10px;flex-wrap:wrap;margin:10px 0 18px}
  .btn{position:relative;display:inline-flex;align-items:center;justify-content:center;padding:10px 16px;border-radius:14px;border:1px solid rgba(201,168,106,.36);background:rgba(21,26,30,.82);color:var(--gold-400);font:600 12px/1 'Inter','Noto Sans SC',sans-serif;letter-spacing:.14em;text-transform:uppercase;text-decoration:none;box-shadow:none;transition:transform var(--transition),border-color var(--transition);overflow:hidden}
  .btn::after{content:none}
  .btn:hover{transform:translateY(-2px);border-color:rgba(227,198,139,.6)}
  .btn:hover::after{content:none}
  .btn:active{transform:translateY(0)}
  .btn.acc{background:linear-gradient(135deg,rgba(201,168,106,.22),rgba(170,140,84,.3));color:var(--bg-void);box-shadow:none}
  .btn.danger{color:var(--danger);border-color:rgba(255,93,125,.45);box-shadow:none}
  .btn.small{padding:8px 12px;border-radius:12px;font-size:11px}
  .btn:focus-visible{outline:2px solid var(--accent-cyan);outline-offset:3px;box-shadow:0 0 0 3px rgba(201,168,106,.25)}
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
  .main{padding:24px 20px;background:linear-gradient(160deg,rgba(12,14,18,.82),rgba(10,12,14,.85));backdrop-filter:blur(14px) saturate(160%);position:relative}
  .main::before{content:"";position:absolute;inset:0;border-left:1px solid rgba(201,168,106,.12);border-top:1px solid rgba(201,168,106,.06);pointer-events:none;box-shadow:inset 0 0 0 1px rgba(201,168,106,.04)}
  .toolbar{display:flex;flex-wrap:wrap;gap:14px;align-items:center;margin-bottom:18px}
  .search{flex:1 1 260px;display:flex;align-items:center;gap:10px;background:linear-gradient(135deg,rgba(15,19,22,.9),rgba(10,12,14,.88));border:1px solid rgba(201,168,106,.32);border-radius:16px;padding:10px 14px;box-shadow:inset 0 0 28px rgba(201,168,106,.08)}
  .search input{all:unset;flex:1;color:var(--text-strong);font-size:15px;letter-spacing:.06em}
  .search input::placeholder{color:var(--text-dim)}
  .search button{padding:8px 14px;border-radius:12px;border:1px solid rgba(201,168,106,.42);background:rgba(201,168,106,.12);color:var(--gold-400);font:600 11px/1 'Inter','Noto Sans SC',sans-serif;text-transform:uppercase;letter-spacing:.18em;cursor:pointer;transition:background var(--transition),box-shadow var(--transition),border-color var(--transition)}
  .search button:hover{background:rgba(201,168,106,.2);border-color:rgba(201,168,106,.6);box-shadow:0 0 18px rgba(227,198,139,.22)}
  .actions-row{display:flex;gap:10px;flex-wrap:wrap}
  .items{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px;position:relative}
  .item{position:relative;padding:18px 16px;border-radius:18px;background:linear-gradient(140deg,rgba(15,19,22,.9),rgba(10,12,14,.9));border:1px solid rgba(201,168,106,.28);box-shadow:var(--shadow);display:grid;gap:10px;transition:transform var(--transition),box-shadow var(--transition),border-color var(--transition)}
  .item::before{content:"";position:absolute;inset:6px;border-radius:14px;border:1px dashed rgba(201,168,106,.24);opacity:.7;pointer-events:none;animation:breathe 12s ease-in-out infinite}
  .item::after{content:"";position:absolute;top:14px;right:16px;width:11px;height:11px;border-radius:50%;background:var(--danger);box-shadow:0 0 12px var(--danger);animation:pulse 1.4s ease-in-out infinite}
  .item:hover{transform:translateY(-4px);box-shadow:0 0 28px rgba(201,168,106,.28),0 26px 50px rgba(0,0,0,.6);border-color:rgba(201,168,106,.52)}
  .item.done{background:linear-gradient(155deg,rgba(26,24,18,.9),rgba(18,16,12,.94));border-color:rgba(227,198,139,.55);box-shadow:0 0 28px rgba(201,168,106,.38),0 24px 58px rgba(0,0,0,.7)}
  .item-empty{grid-column:1/-1;text-align:center;padding:40px 24px;background:linear-gradient(150deg,rgba(15,19,22,.88),rgba(10,12,14,.9));border:1px dashed rgba(201,168,106,.28);box-shadow:none;color:var(--text-muted);letter-spacing:.12em}
  .item-empty::after,.item-empty::before{display:none}
  .item.done::after{display:none}
  .item.done::before{opacity:1;border-style:double;border-color:rgba(201,168,106,.45);animation:crystal 10s ease-in-out infinite}
  @keyframes crystal{0%,100%{box-shadow:0 0 18px rgba(201,168,106,.25)}50%{box-shadow:0 0 28px rgba(201,168,106,.45)}}
  .item[draggable="true"]{cursor:grab}
  .item-title{font:600 16px/1.4 'Cinzel','Noto Serif SC',serif;color:var(--gold-400);text-shadow:0 0 16px rgba(227,198,139,.24);display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;word-break:break-word;letter-spacing:.08em}
  .item.done .item-title,.item.done .item-desc,.item.done .tinyline{text-decoration:line-through;color:rgba(227,198,139,.75)}
  .item-desc{color:var(--text-dim);white-space:pre-wrap;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;word-break:break-word}
  .badge{display:inline-flex;align-items:center;gap:6px;font:600 11px/1.2 'Inter','Noto Sans SC',sans-serif;text-transform:uppercase;letter-spacing:.22em;padding:4px 10px;border-radius:999px;border:1px dashed rgba(201,168,106,.35);background:rgba(201,168,106,.08);color:var(--text-dim);box-shadow:inset 0 0 12px rgba(201,168,106,.05)}
  .kbd{font:600 12px/1 'Inter','Noto Sans SC',sans-serif;padding:2px 6px;border:1px dashed rgba(201,168,106,.35);border-radius:6px;background:rgba(15,19,22,.82);color:var(--text-dim);text-transform:uppercase;letter-spacing:.16em;box-shadow:0 0 12px rgba(201,168,106,.12)}
  .tinyline{position:relative;margin-left:12px;padding-left:18px;color:var(--text-muted)}
  .tinyline::before{content:"";position:absolute;left:6px;top:0;bottom:0;width:2px;background:linear-gradient(to bottom,rgba(201,168,106,.55),rgba(201,168,106,.08));animation:energy 6s linear infinite}
  .tlrow{position:relative;margin:6px 0;padding-left:10px;display:flex;gap:8px;align-items:center}
  .tlrow.done .step-title{text-decoration:line-through;color:rgba(201,168,106,.7)}
  .dot{position:absolute;left:-6px;top:8px;width:10px;height:10px;background:linear-gradient(135deg,rgba(201,168,106,.92),rgba(170,140,84,.85));border-radius:50%;box-shadow:0 0 12px rgba(201,168,106,.5),0 0 24px rgba(201,168,106,.35)}
  .dot::after{content:"";position:absolute;inset:-5px;border-radius:50%;border:1px dashed rgba(201,168,106,.4);animation:pulse 2.2s ease-in-out infinite}
  .ts{color:var(--text-dim);font:600 12px/1 'Inter','Noto Sans SC',sans-serif;margin-left:6px;letter-spacing:.12em;text-transform:uppercase}
  .item-actions{display:flex;gap:10px;justify-content:flex-end;align-items:center;flex-wrap:wrap}
  .item-actions .tip{margin-right:auto;color:var(--text-dim);font:600 11px/1 'Inter','Noto Sans SC',sans-serif;letter-spacing:.2em;text-transform:uppercase}
  .item-actions .status-tip{color:var(--gold-400)}
  .item-actions .note-tip{margin-right:0}
  .item-actions a{background:rgba(201,168,106,.12);border:1px solid rgba(201,168,106,.32);padding:8px 12px;border-radius:12px;color:var(--gold-400);text-transform:uppercase;letter-spacing:.14em;font:600 11px/1 'Inter','Noto Sans SC',sans-serif;transition:var(--transition)}
  .item-actions a:hover{box-shadow:0 0 18px rgba(201,168,106,.3)}
  .mob-move{display:none}
  .err{background:rgba(209,75,75,.16);color:rgba(255,214,214,.92);border:1px solid rgba(209,75,75,.45);border-radius:16px;box-shadow:0 0 20px rgba(209,75,75,.24);padding:10px 14px}
  .flash-message{margin-bottom:12px;font:600 12px/1.4 'Inter','Noto Sans SC',sans-serif;letter-spacing:.16em;text-transform:uppercase}
  .shortcuts{margin-top:14px;color:var(--text-dim);font:600 11px/1.2 'Inter','Noto Sans SC',sans-serif;letter-spacing:.18em;text-transform:uppercase}
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
  @media (max-width:920px){
    .app{grid-template-columns:1fr}
    .sidebar{position:static;height:auto;overflow:visible;border-right:0;border-bottom:1px solid rgba(201,168,106,.18);border-radius:0 0 22px 22px;box-shadow:0 18px 40px rgba(0,0,0,.55)}
    .cat-list{display:grid;grid-template-columns:1fr 1fr;gap:10px}
    .items{grid-template-columns:1fr}
    .item-actions span.tip{display:none}
    .mob-move{display:inline-flex;gap:6px}
  }
  @keyframes breathe{0%,100%{opacity:.5}50%{opacity:1}}
  @keyframes pulse{0%,100%{transform:scale(.75);opacity:.6}50%{transform:scale(1.05);opacity:1}}
  @keyframes energy{0%{background-position:0 0}100%{background-position:0 120px}}
</style>
</head>
<body>
  <div class="scanlines" aria-hidden="true"></div>
  <div class="app">
  <aside class="sidebar">
    <div class="brand">
      <div class="logo" aria-hidden="true"></div>
      <h1>自适应备忘录 · Memo</h1>
    </div>
    <div class="controls">
      <a class="btn acc" href="?view=new">＋ 新建备忘录</a>
      <button class="btn" id="btn-cat-mgr">分类管理</button>
      <a class="btn" href="?view=maps">思维导图</a>
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
    </div>
    <div class="footer">
      <div>✅ 勾选完成</div>
      <div>⬍/↔ 拖拽排序（桌面）</div>
      <div>↑↓ 移动按钮（移动端）</div>
      <div>⤓ 导出 JSON / CSV</div>
    </div>
  </aside>
  <main class="main">
    <?php if (!empty($_SESSION['flash'])): ?>
      <div class="err flash-message"><?php echo h($_SESSION['flash']); unset($_SESSION['flash']); ?></div>
    <?php endif; ?>
    <div class="toolbar">
      <form class="search" method="get" style="flex:1">
        <input type="hidden" name="cat" value="<?php echo h((string)$cat); ?>">
        <input name="q" value="<?php echo h($q); ?>" placeholder="搜索标题/内容 · Search"/>
        <button>搜索</button>
      </form>
      <div class="actions-row">
        <a class="btn small" href="?cat=<?php echo h((string)$cat); ?>&q=<?php echo urlencode($q); ?>&export=json">导出 JSON</a>
        <a class="btn small" href="?cat=<?php echo h((string)$cat); ?>&q=<?php echo urlencode($q); ?>&export=csv">导出 CSV</a>
      </div>
    </div>
    <div class="items" id="items">
      <?php if (!$items): ?>
        <div class="item item-empty">没有条目 · No items</div>
      <?php endif; ?>
      <?php foreach ($items as $it): ?>
        <?php $steps_time=get_steps_by_time((int)$it['id']); ?>
        <article class="item <?php echo $it['done']?'done':''; ?>" draggable="true" data-id="<?php echo $it['id']; ?>">
          <div class="item-head" style="display:flex;gap:8px;align-items:flex-start">
            <form method="post" class="form-toggle-item" onsubmit="return false" style="margin:0">
              <input type="hidden" name="action" value="toggle_done">
              <input type="hidden" name="id" value="<?php echo $it['id']; ?>">
              <input type="checkbox" class="item-toggle" <?php echo $it['done']?'checked':''; ?> title="完成">
            </form>
            <div style="flex:1">
              <div class="item-title"><?php echo h($it['title']); ?></div>
              <?php if ($it['description']!==''): ?>
                <div class="item-desc"><?php echo nl2br(h($it['description'])); ?></div>
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
              <div class="meta meta-inline">
                <span class="badge"><?php echo $it['category_id'] ? h(array_values(array_filter($cats,fn($c)=>$c['id']==$it['category_id']))[0]['name'] ?? '未分类') : '未分类'; ?></span>
                <span class="badge js-updated">更新 <?php echo dt((int)$it['updated_at']); ?></span>
              </div>
            </div>
          </div>
          <div class="item-actions">
            <span class="tip status-tip"><?php echo $it['done'] ? '已刻印完成' : '待刻录'; ?></span>
            <span class="tip note-tip">⬍/↔ 拖拽排序</span>
            <div class="mob-move">
              <button class="btn small" onclick="moveCard(<?php echo $it['id']; ?>,-1)">↑ 上移</button>
              <button class="btn small" onclick="moveCard(<?php echo $it['id']; ?>,1)">↓ 下移</button>
            </div>
            <a class="btn small" href="?view=item&id=<?php echo $it['id']; ?>">详情</a>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
    <div class="shortcuts">
      快捷键：<span class="kbd">/</span> 聚焦搜索。
    </div>
  </main>
</div>
<div id="cat-modal" class="modal-backdrop">
  <div class="modal-panel">
    <div class="modal-header">
      <div class="modal-title">分类管理</div>
      <button class="btn small" onclick="closeCatModal()">关闭</button>
    </div>
    <div id="cat-rows" class="modal-list"></div>
    <form onsubmit="return addCat(event)" class="modal-form">
      <input type="text" id="new-cat-name" class="modal-input" placeholder="新增分类名" required>
      <button class="btn">新增</button>
    </form>
  </div>
</div>
<script>
const $=s=>document.querySelector(s); const $$=s=>Array.from(document.querySelectorAll(s));
const throttle=(fn,ms)=>{let t=0;return (...a)=>{const n=Date.now();if(n-t>ms){t=n;fn(...a);} }};
window.addEventListener('keydown',e=>{
  if(e.key==='/' && !/input|textarea|select/i.test(document.activeElement.tagName)){
    e.preventDefault(); const q=document.querySelector('input[name="q"]'); if(q){ q.focus(); q.select(); }
  }
});
const isMobile=window.matchMedia('(max-width: 920px)').matches;
(function(){
  const grid=$('#items'); if(!grid || isMobile) return;
  let dragging=null;
  grid.addEventListener('dragstart', e=>{ const card=e.target.closest('article.item[draggable]'); if(!card) return; dragging=card; e.dataTransfer.effectAllowed='move'; });
  grid.addEventListener('dragover', throttle(e=>{
    if(!dragging) return; e.preventDefault();
    const cards=$$('article.item[draggable]').filter(n=>n!==dragging); if(!cards.length) return;
    let best=null, bestD=1e9;
    for(const n of cards){ const r=n.getBoundingClientRect(); const cx=r.left+r.width/2, cy=r.top+r.height/2; const d=Math.hypot(e.clientX-cx,e.clientY-cy); if(d<bestD){ bestD=d; best=n; } }
    if(!best) return;
    const r=best.getBoundingClientRect(); const dx=e.clientX-(r.left+r.width/2); const dy=e.clientY-(r.top+r.height/2);
    const vertical=Math.abs(dy)>=Math.abs(dx); const after=vertical?(dy>0):(dx>0);
    best.parentNode.insertBefore(dragging, after?best.nextSibling:best);
  }, 30));
  grid.addEventListener('drop', e=>{
    e.preventDefault(); if(!dragging) return; dragging=null;
    sendOrder();
  });
})();
function moveCard(id, dir){
  const grid=$('#items'); const el=grid.querySelector(`article.item[data-id="${id}"]`); if(!el) return;
  const sib = dir<0 ? el.previousElementSibling : el.nextElementSibling;
  if(!sib) return;
  if(dir<0) grid.insertBefore(el, sib); else grid.insertBefore(sib, el);
  sendOrder();
}
function sendOrder(){
  const ids=$$('article.item[draggable]').map(x=>x.dataset.id).join(',');
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
        <button class="btn small">保存</button>\
        <button class="btn small danger" onclick="return delCat(${c.id}, '${escapeHtml(c.name)}')">删除</button>\
      </form>`;
    box.appendChild(row);
  });
}
function renderCatRowsFromDOM(){ fetchCats().then(({cats,counts,total})=>{renderCatRows(cats,counts); refreshSidebarCats(cats,counts,total);}); }
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
  if(j.ok){ document.getElementById('new-cat-name').value=''; renderCatRows(j.cats,j.counts); refreshSidebarCats(j.cats,j.counts,j.total); }
  return false;
}
async function saveCat(ev, id){
  ev.preventDefault();
  const name=new FormData(ev.target).get('name');
  const fd=new FormData(); fd.append('action','edit_category'); fd.append('id', id); fd.append('name', name);
  const j=await (await fetch(location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'fetch'}})).json();
  if(j.ok){ renderCatRows(j.cats,j.counts); refreshSidebarCats(j.cats,j.counts,j.total); }
  return false;
}
async function delCat(id, name){
  if(!confirm(`确认删除分类【${name}】？该分类下条目将移入“其他”。`)) return false;
  const fd=new FormData(); fd.append('action','delete_category'); fd.append('id', id);
  const j=await (await fetch(location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'fetch'}})).json();
  if(j.ok){ renderCatRows(j.cats,j.counts); refreshSidebarCats(j.cats,j.counts,j.total); }
  return false;
}
function refreshSidebarCats(cats, counts, total){
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
}
function fmt(ts){ const d=new Date(ts*1000); const p=n=>String(n).padStart(2,'0'); return `${d.getFullYear()}-${p(d.getMonth()+1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`; }
document.getElementById('items').addEventListener('change', async (e)=>{
  const t=e.target;
  if(t.classList.contains('item-toggle')){
    const card=t.closest('article.item'); if(!card) return;
    const id=card.dataset.id; const done=t.checked?1:0;
    const fd=new FormData(); fd.append('action','toggle_done'); fd.append('id', id); fd.append('done', done);
    try{
      const j=await (await fetch(location.href,{method:'POST',body:fd,headers:{'X-Requested-With':'fetch'}})).json();
      if(j && j.ok){
        card.classList.toggle('done', !!done);
        const badge=card.querySelector('.js-updated'); if(badge&&j.updated_at) badge.textContent='更新 '+fmt(j.updated_at);
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
</script>
</body>
</html>
