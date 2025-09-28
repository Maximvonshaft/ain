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
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@500;600;700&family=Inter:wght@400;500;600&family=Noto+Sans+SC:wght@400;500;700&family=Noto+Serif+SC:wght@500;600;700&display=swap" rel="stylesheet">
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
        --r-xs:6px;--r-sm:10px;--r-md:14px;--r-lg:18px;
        --shadow-1:0 8px 24px rgba(0,0,0,.5),0 0 24px rgba(227,198,139,.12);
        --shadow-2:0 16px 48px rgba(0,0,0,.6) inset,0 0 1px rgba(201,168,106,.35);
        --t-fast:200ms;--t:300ms;--t-slow:450ms;--ease:cubic-bezier(.22,.61,.36,1);
      }
      *,*::before,*::after{box-sizing:border-box}
      html,body{margin:0;min-height:100vh;background:var(--bg-void);color:var(--text-strong);font:16px/1.6 'Noto Sans SC','Source Han Sans SC','Inter',sans-serif;letter-spacing:.01em;position:relative;overflow-x:hidden}
      body{animation:body-breathe 10s ease-in-out infinite}
      body::before{content:"";position:fixed;inset:0;background:
        radial-gradient(1200px 720px at 70% -10%,rgba(255,255,255,.06),transparent 60%),
        linear-gradient(120deg,rgba(201,168,106,.08),transparent 30%,rgba(201,168,106,.06) 70%,transparent 90%),
        #050607;
        z-index:-3;
      }
      body::after{content:"";position:fixed;inset:0;background:
        linear-gradient(180deg,rgba(75,195,209,.08) 0,transparent 55%),
        repeating-linear-gradient(90deg,rgba(201,168,106,.07) 0,rgba(201,168,106,.07) 1px,transparent 1px,transparent 56px),
        repeating-linear-gradient(0deg,rgba(201,168,106,.05) 0,rgba(201,168,106,.05) 1px,transparent 1px,transparent 56px);
        mix-blend-mode:screen;opacity:.55;pointer-events:none;z-index:-2;animation:grid-pan 24s linear infinite;
      }
      @keyframes body-breathe{0%,100%{filter:brightness(.94)}50%{filter:brightness(1)}}
      @keyframes grid-pan{0%{background-position:0 0,0 0,0 0}100%{background-position:0 80px,56px 0,0 56px}}
      .scanlines{position:fixed;inset:0;pointer-events:none;z-index:-1;background:linear-gradient(to bottom,rgba(75,195,209,.12) 0,transparent 3px);background-size:100% 5px;opacity:.28;animation:scan 12s linear infinite}
      @keyframes scan{0%{transform:translateY(-100%)}100%{transform:translateY(100%)} }
      a{color:inherit;text-decoration:none}
      .wrap{max-width:1160px;margin:0 auto;padding:32px 24px 96px;position:relative;z-index:0}
      .wrap::before{content:"";position:absolute;inset:18px;border-radius:var(--r-lg);border:1px solid rgba(201,168,106,.12);opacity:.6;pointer-events:none;box-shadow:0 0 60px rgba(0,0,0,.45)}
      .card{position:relative;background:linear-gradient(180deg,rgba(21,26,30,.92),rgba(15,19,22,.92));border:1px solid rgba(201,168,106,.28);border-radius:var(--r-lg);padding:28px;box-shadow:var(--shadow-1);backdrop-filter:blur(12px)}
      .card::before{content:"";position:absolute;inset:10px;border-radius:calc(var(--r-lg)-4px);box-shadow:inset 0 0 0 1px rgba(227,198,139,.22),inset 0 0 34px rgba(227,198,139,.08);pointer-events:none}
      .btn{position:relative;display:inline-flex;align-items:center;justify-content:center;padding:12px 18px;border-radius:var(--r-sm);font:600 12px/1 'Inter','Noto Sans SC',sans-serif;letter-spacing:.18em;text-transform:uppercase;cursor:pointer;border:1px solid rgba(201,168,106,.38);background:rgba(12,16,18,.6);color:var(--gold-400);transition:all var(--t) var(--ease);box-shadow:inset 0 0 12px rgba(201,168,106,.12)}
      .btn::after{content:"";position:absolute;inset:2px;border-radius:calc(var(--r-sm)-2px);border:1px dashed rgba(201,168,106,.32);opacity:.65;transition:opacity var(--t) var(--ease);pointer-events:none}
      .btn:hover{transform:translateY(-2px);background:rgba(201,168,106,.16);box-shadow:0 0 24px rgba(227,198,139,.12)}
      .btn:hover::after{opacity:1}
      .btn.acc{color:var(--bg-void);background:linear-gradient(135deg,rgba(227,198,139,.86),rgba(170,140,84,.8));border-color:rgba(227,198,139,.72);box-shadow:0 0 30px rgba(227,198,139,.2)}
      .btn:focus-visible{outline:2px solid var(--accent-cyan);outline-offset:3px}
      .row{display:grid;grid-template-columns:2fr 1fr auto;gap:16px;margin-bottom:20px;align-items:center}
      .row input,.row select{padding:12px 14px;border-radius:var(--r-sm);border:1px solid rgba(201,168,106,.28);background:rgba(12,16,18,.62);color:var(--text-strong);font:500 14px/1.4 'Inter','Noto Sans SC',sans-serif;letter-spacing:.04em;transition:border-color var(--t) var(--ease),box-shadow var(--t) var(--ease)}
      .row input::placeholder{color:var(--text-muted)}
      .row input:focus,.row select:focus{border-color:var(--gold-500);box-shadow:0 0 0 3px rgba(227,198,139,.18),inset 0 0 0 1px rgba(227,198,139,.2);outline:none}
      .split{display:grid;grid-template-columns:minmax(320px,1fr) minmax(320px,1fr);gap:24px;align-items:start}
      @media (max-width:960px){.row{grid-template-columns:1fr}.split{grid-template-columns:1fr}.wrap{padding:24px 16px 80px}}
      .editbox,.preview{position:relative;background:linear-gradient(180deg,rgba(21,26,30,.92),rgba(15,19,22,.92));border:1px solid rgba(201,168,106,.26);border-radius:var(--r-md);padding:20px;min-height:280px;box-shadow:inset 0 0 32px rgba(201,168,106,.08)}
      .editbox::before,.preview::before{content:"";position:absolute;inset:10px;border-radius:calc(var(--r-md)-4px);border:1px dashed rgba(201,168,106,.22);opacity:.6;pointer-events:none}
      .preview{max-height:62vh;overflow:auto}
      .preview::-webkit-scrollbar{width:8px}
      .preview::-webkit-scrollbar-thumb{background:rgba(201,168,106,.28);border-radius:6px}
      .md-body{color:var(--text-dim);font:400 15px/1.75 'Inter','Noto Sans SC',sans-serif}
      .md-body img{max-width:100%;height:auto;border:1px solid rgba(201,168,106,.32);border-radius:var(--r-sm);box-shadow:0 0 18px rgba(227,198,139,.16)}
      .EasyMDEContainer .editor-toolbar{background:rgba(12,16,18,.78);border-color:rgba(201,168,106,.28);border-radius:var(--r-sm) var(--r-sm) 0 0;color:var(--text-muted)}
      .EasyMDEContainer .editor-toolbar a{color:var(--text-muted);letter-spacing:.18em;text-transform:uppercase;font-size:11px}
      .EasyMDEContainer .editor-toolbar a.active,
      .EasyMDEContainer .editor-toolbar a:hover{background:rgba(201,168,106,.16);color:var(--text-strong)}
      .EasyMDEContainer .CodeMirror{border:1px solid rgba(201,168,106,.28);border-radius:0 0 var(--r-sm) var(--r-sm);background:rgba(10,14,16,.85);color:var(--text-strong);min-height:280px;max-height:62vh}
      .thumbs{display:flex;gap:14px;flex-wrap:wrap;margin-top:18px}
      .thumb{position:relative;border:1px solid rgba(201,168,106,.3);border-radius:var(--r-sm);overflow:hidden;background:rgba(12,16,18,.72);box-shadow:var(--shadow-1)}
      .thumb::after{content:"";position:absolute;inset:6px;border-radius:calc(var(--r-sm)-6px);border:1px dashed rgba(201,168,106,.2);pointer-events:none}
      .thumb img{display:block;max-width:220px;max-height:150px}
      .att-meta{color:var(--text-muted);font:12px/1 'Inter','Noto Sans SC',sans-serif;letter-spacing:.12em}
      .timeline{position:relative;margin-top:22px;margin-left:18px;padding-left:32px;color:var(--text-muted)}
      .timeline::before{content:"";position:absolute;left:12px;top:0;bottom:0;width:2px;background:linear-gradient(to bottom,rgba(201,168,106,.65),rgba(201,168,106,.08));animation:energy-flow 8s ease-in-out infinite}
      .tl-item{position:relative;margin:16px 0;padding:18px 18px 18px 22px;border:1px solid rgba(201,168,106,.28);border-radius:var(--r-md);background:linear-gradient(180deg,rgba(21,26,30,.9),rgba(12,16,18,.92));box-shadow:var(--shadow-1)}
      .tl-item::after{content:"";position:absolute;inset:2px;border-radius:calc(var(--r-md)-4px);border:1px solid rgba(201,168,106,.14);opacity:.4;pointer-events:none}
      .tl-item .tl-dot{position:absolute;left:-26px;top:20px;width:16px;height:16px;border-radius:50%;background:radial-gradient(circle at 30% 30%,rgba(227,198,139,.9),rgba(170,140,84,.8));box-shadow:0 0 18px rgba(227,198,139,.35)}
      .tl-item .tl-dot::after{content:"";position:absolute;inset:-6px;border-radius:50%;border:1px dashed rgba(201,168,106,.35);animation:pulse 2.6s ease-in-out infinite}
      .tl-head{display:flex;gap:12px;align-items:center;color:var(--text-strong);font:600 15px/1.4 'Inter','Noto Sans SC',sans-serif}
      .tl-item.done{background:linear-gradient(180deg,rgba(34,56,42,.92),rgba(17,29,24,.95));border-color:rgba(36,194,160,.45);box-shadow:0 0 26px rgba(36,194,160,.24)}
      .tl-item.done .tl-head div,.tl-item.done .md-body{opacity:.75;text-decoration:line-through}
      .drag{cursor:grab;color:var(--text-muted);font-size:14px}
      .ts{color:var(--text-muted);font:12px/1 'Inter','Noto Sans SC',sans-serif;margin-left:8px;letter-spacing:.08em}
      details summary{cursor:pointer;color:var(--text-muted);text-transform:uppercase;letter-spacing:.16em;font:600 11px/1 'Inter','Noto Sans SC',sans-serif}
      .save-tip{color:var(--gold-400);font:11px/1 'Inter','Noto Sans SC',sans-serif;letter-spacing:.2em;text-transform:uppercase;margin-left:8px;display:none}
      .save-tip.show{display:inline}
      .placeholder-muted{color:var(--text-muted)}
      @keyframes pulse{0%,100%{transform:scale(.85);opacity:.6}50%{transform:scale(1);opacity:1}}
      @keyframes energy-flow{0%{background-position:0 0}100%{background-position:0 160px}}
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
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@500;600;700&family=Inter:wght@400;500;600&family=Noto+Sans+SC:wght@400;500;700&family=Noto+Serif+SC:wght@500;600;700&display=swap" rel="stylesheet">
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
        --r-xs:6px;--r-sm:10px;--r-md:14px;--r-lg:18px;
        --shadow-1:0 8px 24px rgba(0,0,0,.5),0 0 24px rgba(227,198,139,.12);
        --shadow-2:0 16px 48px rgba(0,0,0,.6) inset,0 0 1px rgba(201,168,106,.35);
        --t-fast:200ms;--t:300ms;--t-slow:450ms;--ease:cubic-bezier(.22,.61,.36,1);
      }
      *,*::before,*::after{box-sizing:border-box}
      html,body{margin:0;min-height:100vh;background:var(--bg-void);color:var(--text-strong);font:16px/1.6 'Noto Sans SC','Source Han Sans SC','Inter',sans-serif;letter-spacing:.01em;position:relative;overflow-x:hidden}
      body::before{content:"";position:fixed;inset:0;background:
        radial-gradient(1200px 720px at 70% -10%,rgba(255,255,255,.05),transparent 60%),
        linear-gradient(120deg,rgba(201,168,106,.08),transparent 30%,rgba(201,168,106,.06) 70%,transparent 90%),
        #050607;
        z-index:-3;
      }
      body::after{content:"";position:fixed;inset:0;background:
        linear-gradient(180deg,rgba(75,195,209,.08) 0,transparent 55%),
        repeating-linear-gradient(90deg,rgba(201,168,106,.07) 0,rgba(201,168,106,.07) 1px,transparent 1px,transparent 56px),
        repeating-linear-gradient(0deg,rgba(201,168,106,.05) 0,rgba(201,168,106,.05) 1px,transparent 1px,transparent 56px);
        mix-blend-mode:screen;opacity:.55;pointer-events:none;z-index:-2;animation:grid-pan 26s linear infinite;
      }
      @keyframes grid-pan{0%{background-position:0 0,0 0,0 0}100%{background-position:0 90px,56px 0,0 56px}}
      .scanlines{position:fixed;inset:0;pointer-events:none;z-index:-1;background:linear-gradient(to bottom,rgba(75,195,209,.12) 0,transparent 3px);background-size:100% 5px;opacity:.3;animation:scan 12s linear infinite}
      @keyframes scan{0%{transform:translateY(-100%)}100%{transform:translateY(100%)} }
      a{color:inherit;text-decoration:none}
      .wrap{max-width:1120px;margin:0 auto;padding:32px 24px 120px;position:relative;z-index:0}
      .wrap::before{content:"";position:absolute;inset:18px;border-radius:var(--r-lg);border:1px solid rgba(201,168,106,.14);opacity:.6;pointer-events:none;box-shadow:0 0 60px rgba(0,0,0,.45)}
      .btn{position:relative;display:inline-flex;align-items:center;justify-content:center;padding:12px 18px;border-radius:var(--r-sm);font:600 12px/1 'Inter','Noto Sans SC',sans-serif;letter-spacing:.18em;text-transform:uppercase;cursor:pointer;border:1px solid rgba(201,168,106,.38);background:rgba(12,16,18,.6);color:var(--gold-400);transition:all var(--t) var(--ease);box-shadow:inset 0 0 12px rgba(201,168,106,.12)}
      .btn::after{content:"";position:absolute;inset:2px;border-radius:calc(var(--r-sm)-2px);border:1px dashed rgba(201,168,106,.3);opacity:.65;transition:opacity var(--t) var(--ease);pointer-events:none}
      .btn:hover{transform:translateY(-2px);background:rgba(201,168,106,.16);box-shadow:0 0 24px rgba(227,198,139,.12)}
      .btn:hover::after{opacity:1}
      .btn.acc{color:var(--bg-void);background:linear-gradient(135deg,rgba(227,198,139,.86),rgba(170,140,84,.82));border-color:rgba(227,198,139,.72);box-shadow:0 0 30px rgba(227,198,139,.2)}
      .btn.danger{color:rgba(255,224,224,.92);border-color:rgba(209,75,75,.6);background:linear-gradient(135deg,rgba(61,18,20,.9),rgba(33,12,14,.9));box-shadow:0 0 26px rgba(209,75,75,.25)}
      .btn:focus-visible{outline:2px solid var(--accent-cyan);outline-offset:3px}
      .card{position:relative;background:linear-gradient(180deg,rgba(21,26,30,.92),rgba(15,19,22,.92));border:1px solid rgba(201,168,106,.28);border-radius:var(--r-lg);padding:28px;box-shadow:var(--shadow-1);backdrop-filter:blur(12px)}
      .card::before{content:"";position:absolute;inset:10px;border-radius:calc(var(--r-lg)-4px);box-shadow:inset 0 0 0 1px rgba(227,198,139,.22),inset 0 0 34px rgba(227,198,139,.08);pointer-events:none}
      .title{font:600 26px/1.3 'Cinzel','Noto Serif SC','Noto Sans SC',serif;margin:0 0 10px;color:var(--text-strong);letter-spacing:.08em;text-transform:uppercase;text-shadow:0 0 22px rgba(227,198,139,.18)}
      .meta{color:var(--text-dim);font:13px/1.8 'Inter','Noto Sans SC',sans-serif;letter-spacing:.12em}
      .split{display:grid;grid-template-columns:minmax(320px,1fr) minmax(320px,1fr);gap:26px;align-items:start;margin-top:16px}
      @media (max-width:960px){.split{grid-template-columns:1fr}}
      .editbox,.preview{position:relative;background:linear-gradient(180deg,rgba(21,26,30,.92),rgba(15,19,22,.92));border:1px solid rgba(201,168,106,.26);border-radius:var(--r-md);padding:20px;min-height:300px;box-shadow:inset 0 0 32px rgba(201,168,106,.08)}
      .editbox::before,.preview::before{content:"";position:absolute;inset:10px;border-radius:calc(var(--r-md)-4px);border:1px dashed rgba(201,168,106,.22);opacity:.55;pointer-events:none}
      .editbox input,
      .editbox select,
      .editbox textarea{width:100%;padding:12px 14px;border-radius:var(--r-sm);border:1px solid rgba(201,168,106,.3);background:rgba(12,16,18,.62);color:var(--text-strong);font:500 14px/1.4 'Inter','Noto Sans SC',sans-serif;letter-spacing:.04em;transition:border-color var(--t) var(--ease),box-shadow var(--t) var(--ease)}
      .editbox input::placeholder,
      .editbox textarea::placeholder{color:var(--text-muted)}
      .editbox input:focus,
      .editbox select:focus,
      .editbox textarea:focus{border-color:var(--gold-500);box-shadow:0 0 0 3px rgba(227,198,139,.18),inset 0 0 0 1px rgba(227,198,139,.2);outline:none}
      .editbox textarea{min-height:280px;border-radius:var(--r-md)}
      .preview{max-height:64vh;overflow:auto}
      .preview::-webkit-scrollbar{width:8px}
      .preview::-webkit-scrollbar-thumb{background:rgba(201,168,106,.3);border-radius:6px}
      .md-body{color:var(--text-dim);font:400 15px/1.75 'Inter','Noto Sans SC',sans-serif}
      .md-body img{max-width:100%;height:auto;border:1px solid rgba(201,168,106,.32);border-radius:var(--r-sm);box-shadow:0 0 18px rgba(227,198,139,.16)}
      .EasyMDEContainer .editor-toolbar{background:rgba(12,16,18,.78);border-color:rgba(201,168,106,.28);border-radius:var(--r-sm) var(--r-sm) 0 0;color:var(--text-muted)}
      .EasyMDEContainer .editor-toolbar a{color:var(--text-muted);letter-spacing:.18em;text-transform:uppercase;font-size:11px}
      .EasyMDEContainer .editor-toolbar a.active,
      .EasyMDEContainer .editor-toolbar a:hover{background:rgba(201,168,106,.16);color:var(--text-strong)}
      .EasyMDEContainer .CodeMirror{border:1px solid rgba(201,168,106,.28);border-radius:0 0 var(--r-sm) var(--r-sm);background:rgba(10,14,16,.85);color:var(--text-strong);min-height:280px;max-height:64vh}
      .preview .thumbs{display:flex;gap:14px;flex-wrap:wrap;margin-top:18px}
      .thumb{position:relative;border:1px solid rgba(201,168,106,.3);border-radius:var(--r-sm);overflow:hidden;background:rgba(12,16,18,.72);box-shadow:var(--shadow-1)}
      .thumb::after{content:"";position:absolute;inset:6px;border-radius:calc(var(--r-sm)-6px);border:1px dashed rgba(201,168,106,.2);pointer-events:none}
      .thumb img{display:block;max-width:220px;max-height:150px}
      .att-meta{color:var(--text-muted);font:12px/1 'Inter','Noto Sans SC',sans-serif;letter-spacing:.12em}
      .timeline{position:relative;margin-top:24px;margin-left:20px;padding-left:36px;color:var(--text-muted)}
      .timeline::before{content:"";position:absolute;left:14px;top:0;bottom:0;width:2px;background:linear-gradient(to bottom,rgba(201,168,106,.68),rgba(201,168,106,.08));animation:energy-flow 8s ease-in-out infinite}
      .tl-item{position:relative;margin:18px 0;padding:20px 20px 20px 26px;border:1px solid rgba(201,168,106,.28);border-radius:var(--r-md);background:linear-gradient(180deg,rgba(21,26,30,.9),rgba(12,16,18,.94));box-shadow:var(--shadow-1)}
      .tl-item::after{content:"";position:absolute;inset:2px;border-radius:calc(var(--r-md)-4px);border:1px solid rgba(201,168,106,.14);opacity:.4;pointer-events:none}
      .tl-item .tl-dot{position:absolute;left:-30px;top:22px;width:18px;height:18px;border-radius:50%;background:radial-gradient(circle at 30% 30%,rgba(227,198,139,.92),rgba(170,140,84,.82));box-shadow:0 0 18px rgba(227,198,139,.35)}
      .tl-item .tl-dot::after{content:"";position:absolute;inset:-7px;border-radius:50%;border:1px dashed rgba(201,168,106,.35);animation:pulse 2.6s ease-in-out infinite}
      .tl-head{display:flex;gap:12px;align-items:center;color:var(--text-strong);font:600 15px/1.4 'Inter','Noto Sans SC',sans-serif}
      .tl-item.done{background:linear-gradient(180deg,rgba(34,56,42,.92),rgba(17,29,24,.95));border-color:rgba(36,194,160,.45);box-shadow:0 0 26px rgba(36,194,160,.24)}
      .tl-item.done .tl-head div,.tl-item.done .md-body{opacity:.75;text-decoration:line-through}
      .drag{cursor:grab;color:var(--text-muted);font-size:14px}
      .ts{color:var(--text-muted);font:12px/1 'Inter','Noto Sans SC',sans-serif;margin-left:8px;letter-spacing:.08em}
      details summary{cursor:pointer;color:var(--text-muted);text-transform:uppercase;letter-spacing:.16em;font:600 11px/1 'Inter','Noto Sans SC',sans-serif}
      .save-tip{color:var(--gold-400);font:11px/1 'Inter','Noto Sans SC',sans-serif;letter-spacing:.2em;text-transform:uppercase;margin-left:8px;display:none}
      .save-tip.show{display:inline}
      .placeholder-muted{color:var(--text-muted)}
      @keyframes pulse{0%,100%{transform:scale(.85);opacity:.6}50%{transform:scale(1);opacity:1}}
      @keyframes energy-flow{0%{background-position:0 0}100%{background-position:0 180px}}
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
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@500;600;700&family=Inter:wght@400;500;600&family=Noto+Sans+SC:wght@400;500;700&family=Noto+Serif+SC:wght@500;600;700&display=swap" rel="stylesheet">
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
        --r-xs:6px;--r-sm:10px;--r-md:14px;--r-lg:18px;
        --shadow-1:0 8px 24px rgba(0,0,0,.5),0 0 24px rgba(227,198,139,.12);
        --shadow-2:0 16px 48px rgba(0,0,0,.6) inset,0 0 1px rgba(201,168,106,.35);
        --t-fast:200ms;--t:300ms;--t-slow:450ms;--ease:cubic-bezier(.22,.61,.36,1);
      }
      *,*::before,*::after{box-sizing:border-box}
      html,body{margin:0;min-height:100vh;background:var(--bg-void);color:var(--text-strong);font:16px/1.6 'Noto Sans SC','Source Han Sans SC','Inter',sans-serif;letter-spacing:.01em;position:relative;overflow:hidden}
      body{animation:body-breathe 10s ease-in-out infinite}
      body::before{content:"";position:fixed;inset:0;background:
        radial-gradient(1200px 720px at 70% -10%,rgba(255,255,255,.06),transparent 60%),
        linear-gradient(120deg,rgba(201,168,106,.08),transparent 30%,rgba(201,168,106,.06) 70%,transparent 90%),
        #050607;
        z-index:-3;
      }
      body::after{content:"";position:fixed;inset:0;background:
        linear-gradient(180deg,rgba(75,195,209,.08) 0,transparent 60%),
        repeating-linear-gradient(90deg,rgba(201,168,106,.07) 0,rgba(201,168,106,.07) 1px,transparent 1px,transparent 64px),
        repeating-linear-gradient(0deg,rgba(201,168,106,.05) 0,rgba(201,168,106,.05) 1px,transparent 1px,transparent 64px);
        mix-blend-mode:screen;opacity:.55;pointer-events:none;z-index:-2;animation:grid-pan 28s linear infinite;
      }
      @keyframes body-breathe{0%,100%{filter:brightness(.94)}50%{filter:brightness(1)}}
      @keyframes grid-pan{0%{background-position:0 0,0 0,0 0}100%{background-position:0 90px,64px 0,0 64px}}
      .scanlines{position:fixed;inset:0;pointer-events:none;z-index:-1;background:linear-gradient(to bottom,rgba(75,195,209,.12) 0,transparent 3px);background-size:100% 5px;opacity:.28;animation:scan 12s linear infinite}
      @keyframes scan{0%{transform:translateY(-100%)}100%{transform:translateY(100%)} }
      a{color:inherit;text-decoration:none}
      .layout{min-height:100vh;display:grid;grid-template-columns:minmax(320px,360px) 1fr;position:relative;z-index:0}
      .layout.compact{grid-template-columns:1fr}
      .sidebar{position:sticky;top:0;height:100vh;overflow:auto;padding:28px 24px;background:linear-gradient(165deg,rgba(15,19,22,.94),rgba(10,14,16,.92));border-right:1px solid rgba(201,168,106,.22);box-shadow:0 24px 60px rgba(0,0,0,.55),inset 0 0 0 1px rgba(201,168,106,.14);backdrop-filter:blur(18px)}
      .sidebar h1{margin:0;font:600 20px/1.3 'Cinzel','Noto Serif SC','Noto Sans SC',serif;letter-spacing:.18em;text-transform:uppercase;color:var(--gold-400);text-shadow:0 0 22px rgba(227,198,139,.24)}
      .sidebar .meta{color:var(--text-dim);font:12px/1.6 'Inter','Noto Sans SC',sans-serif;letter-spacing:.12em;margin:10px 0 20px}
      .sidebar label{display:block;font:600 11px/1 'Inter','Noto Sans SC',sans-serif;text-transform:uppercase;letter-spacing:.22em;color:var(--text-muted);margin-top:18px}
      .sidebar input[type="text"],
      .sidebar select{width:100%;padding:12px 14px;border-radius:var(--r-sm);border:1px solid rgba(201,168,106,.3);background:rgba(12,16,18,.62);color:var(--text-strong);font:500 14px/1.4 'Inter','Noto Sans SC',sans-serif;letter-spacing:.04em;margin-top:8px;transition:border-color var(--t) var(--ease),box-shadow var(--t) var(--ease)}
      .sidebar input::placeholder{color:var(--text-muted)}
      .sidebar input:focus,
      .sidebar select:focus{border-color:var(--gold-500);box-shadow:0 0 0 3px rgba(227,198,139,.18),inset 0 0 0 1px rgba(227,198,139,.22);outline:none}
      .actions{display:flex;flex-wrap:wrap;gap:12px;margin:22px 0}
      .btn-like,
      .toolbar button,
      .actions button{position:relative;display:inline-flex;align-items:center;justify-content:center;padding:12px 16px;border-radius:var(--r-sm);border:1px solid rgba(201,168,106,.35);background:rgba(12,16,18,.58);color:var(--gold-400);font:600 12px/1 'Inter','Noto Sans SC',sans-serif;text-transform:uppercase;letter-spacing:.18em;cursor:pointer;transition:all var(--t) var(--ease);box-shadow:inset 0 0 12px rgba(201,168,106,.12)}
      .btn-block{width:100%;justify-content:center}
      .toolbar{display:grid;gap:12px;margin-bottom:18px}
      .toolbar button::after,
      .actions button::after,
      .btn-like::after{content:"";position:absolute;inset:2px;border-radius:calc(var(--r-sm)-2px);border:1px dashed rgba(201,168,106,.28);opacity:.65;transition:opacity var(--t) var(--ease);pointer-events:none}
      .toolbar button:hover,
      .actions button:hover,
      .btn-like:hover{transform:translateY(-2px);background:rgba(201,168,106,.16);box-shadow:0 0 24px rgba(227,198,139,.12)}
      .toolbar button:hover::after,
      .actions button:hover::after,
      .btn-like:hover::after{opacity:1}
      .toolbar button.acc,
      .actions button.acc{color:var(--bg-void);background:linear-gradient(135deg,rgba(227,198,139,.86),rgba(170,140,84,.8));border-color:rgba(227,198,139,.72);box-shadow:0 0 28px rgba(227,198,139,.18)}
      .toolbar button.danger,
      .btn-like.danger{color:rgba(255,224,224,.9);border-color:rgba(209,75,75,.6);background:linear-gradient(135deg,rgba(61,18,20,.9),rgba(35,12,14,.92));box-shadow:0 0 26px rgba(209,75,75,.24)}
      .toolbar button:disabled,
      .actions button:disabled{opacity:.45;cursor:not-allowed}
      .toolbar button:focus-visible,
      .actions button:focus-visible,
      .btn-like:focus-visible{outline:2px solid var(--accent-cyan);outline-offset:3px}
      .tips{background:linear-gradient(180deg,rgba(21,26,30,.92),rgba(12,16,18,.92));border:1px solid rgba(201,168,106,.24);border-radius:var(--r-md);padding:18px;color:var(--text-muted);font:13px/1.7 'Inter','Noto Sans SC',sans-serif;box-shadow:var(--shadow-1)}
      .tips strong{color:var(--text-strong)}
      .tips code{display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:var(--r-xs);border:1px dashed rgba(201,168,106,.3);background:rgba(12,16,18,.7);color:var(--gold-400);font:600 11px/1 'Inter','Noto Sans SC',sans-serif;letter-spacing:.14em}
      .inspector{margin-top:22px;padding:20px;border-radius:var(--r-md);background:linear-gradient(180deg,rgba(21,26,30,.92),rgba(15,19,22,.92));border:1px solid rgba(201,168,106,.26);box-shadow:inset 0 0 28px rgba(201,168,106,.08)}
      .inspector h2{margin:0 0 14px;font:600 13px/1 'Inter','Noto Sans SC',sans-serif;text-transform:uppercase;letter-spacing:.2em;color:var(--gold-400)}
      .inspector .field{display:flex;flex-direction:column;gap:6px;margin-bottom:12px}
      .inspector label{font:600 11px/1 'Inter','Noto Sans SC',sans-serif;text-transform:uppercase;letter-spacing:.18em;color:var(--text-muted)}
      .inspector select,
      .inspector input[type="text"]{padding:10px 12px;border-radius:var(--r-sm);border:1px solid rgba(201,168,106,.28);background:rgba(12,16,18,.62);color:var(--text-strong);font:500 13px/1.4 'Inter','Noto Sans SC',sans-serif}
      .inspector .chips-preview{display:flex;flex-wrap:wrap;gap:6px}
      .inspector .chips-preview span{padding:4px 8px;border-radius:999px;border:1px dashed rgba(201,168,106,.28);background:rgba(201,168,106,.08);color:var(--text-muted);font-size:11px;letter-spacing:.12em}
      .inspector .empty{color:var(--text-muted);font-size:12px}
      .inspector.disabled{opacity:.45;pointer-events:none}
      .editor-pane{position:relative;overflow:hidden;background:radial-gradient(1000px 600px at 50% -10%,rgba(227,198,139,.14),transparent 60%),linear-gradient(160deg,rgba(15,19,22,.9),rgba(8,10,12,.95))}
      #jsmind-container{position:relative;width:100%;height:100vh;height:100dvh;max-height:100%;overflow:hidden;background:linear-gradient(170deg,rgba(10,14,16,.92),rgba(5,7,8,.96));touch-action:none}
      .map-toolbar{position:absolute;top:18px;right:18px;display:flex;gap:12px;flex-wrap:wrap;z-index:60}
      .map-toolbar button{padding:10px 14px;border-radius:var(--r-sm);border:1px solid rgba(201,168,106,.3);background:rgba(12,16,18,.7);color:var(--gold-400);font:600 11px/1 'Inter','Noto Sans SC',sans-serif;letter-spacing:.16em;text-transform:uppercase;box-shadow:inset 0 0 12px rgba(201,168,106,.1);transition:all var(--t) var(--ease)}
      .map-toolbar button:hover{background:rgba(201,168,106,.18);box-shadow:0 0 20px rgba(227,198,139,.16)}
      .map-error{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:48px;gap:14px;color:var(--text-strong);background:rgba(10,12,14,.88);backdrop-filter:blur(10px);text-align:center}
      .map-error strong{font:600 20px/1.4 'Cinzel','Noto Serif SC','Noto Sans SC',serif;letter-spacing:.12em;color:var(--gold-400)}
      .mind-viewport,.mind-links{position:absolute;top:0;left:0;transform-origin:0 0}
      .mind-links{pointer-events:none}
      .mind-background{position:absolute;inset:0;background:
        linear-gradient(120deg,rgba(201,168,106,.04) 0,transparent 32%,rgba(201,168,106,.05) 72%,transparent 100%),
        repeating-linear-gradient(0deg,rgba(201,168,106,.05) 0,rgba(201,168,106,.05) 1px,transparent 1px,transparent 56px),
        repeating-linear-gradient(90deg,rgba(201,168,106,.05) 0,rgba(201,168,106,.05) 1px,transparent 1px,transparent 56px);
        opacity:.55;pointer-events:none}
      .mind-links path{fill:none;stroke:url(#gold-wire);stroke-width:2;stroke-linecap:round;filter:drop-shadow(0 0 6px rgba(227,198,139,.25));stroke-dasharray:32 18;animation:edge-breathe 8s ease-in-out infinite}
      .mind-links path[data-status="doing"]{stroke:url(#gold-wire-strong);stroke-dasharray:16 12;animation:edge-pulse 2.4s linear infinite}
      .mind-links path[data-status="done"]{stroke:rgba(36,194,160,.8);stroke-dasharray:26 24;filter:drop-shadow(0 0 10px rgba(36,194,160,.3))}
      .mind-links path[data-priority="high"]{stroke:rgba(209,75,75,.85);filter:drop-shadow(0 0 10px rgba(209,75,75,.28))}
      .mind-links circle{fill:var(--gold-500);filter:drop-shadow(0 0 8px rgba(227,198,139,.3))}
      .jsmind-node{position:absolute;display:flex;flex-direction:column;align-items:flex-start;gap:10px;min-width:170px;max-width:340px;padding:18px 20px;border-radius:16px;background:linear-gradient(180deg,rgba(21,26,30,.94),rgba(12,16,18,.94));border:1.5px solid rgba(201,168,106,.32);box-shadow:0 18px 46px rgba(0,0,0,.6),0 0 28px rgba(227,198,139,.16);color:var(--text-strong);font:600 13px/1.55 'Inter','Noto Sans SC',sans-serif;letter-spacing:.05em;transition:transform var(--t) var(--ease),box-shadow var(--t) var(--ease),border-color var(--t) var(--ease),background var(--t) var(--ease);backdrop-filter:blur(12px)}
      .jsmind-node::before{content:"";position:absolute;inset:8px;border-radius:12px;border:1px solid rgba(227,198,139,.18);box-shadow:inset 0 0 32px rgba(227,198,139,.1);opacity:.8;pointer-events:none;animation:node-glow 10s ease-in-out infinite}
      .jsmind-node::after{content:"";position:absolute;inset:-14px;border-radius:22px;border:1px dashed rgba(201,168,106,.18);opacity:0;pointer-events:none}
      .jsmind-node:hover{transform:translateY(-3px);box-shadow:0 0 26px rgba(227,198,139,.2),0 32px 70px rgba(0,0,0,.65)}
      .jsmind-node.selected{border-color:var(--gold-500);box-shadow:0 0 34px rgba(227,198,139,.3),0 36px 80px rgba(0,0,0,.68)}
      .jsmind-node.selected::after{opacity:1;animation:shield 3s linear infinite}
      .jsmind-node[data-depth="0"]{background:linear-gradient(180deg,rgba(25,32,36,.95),rgba(18,24,28,.95));border:2px solid rgba(227,198,139,.45);box-shadow:0 0 36px rgba(227,198,139,.28),0 44px 90px rgba(0,0,0,.7);animation:core-breathe 9s ease-in-out infinite}
      .jsmind-node[data-status="done"]{background:linear-gradient(180deg,rgba(28,48,40,.94),rgba(16,28,24,.96));border-color:rgba(36,194,160,.55);box-shadow:0 0 32px rgba(36,194,160,.3)}
      .jsmind-node[data-status="done"]::before{border-color:rgba(36,194,160,.45);animation:crystal 7s ease-in-out infinite}
      .jsmind-node[data-status="doing"]::before{background:repeating-linear-gradient(120deg,rgba(227,198,139,.12) 0,rgba(227,198,139,.12) 18px,transparent 18px,transparent 36px);animation:energy-loop 3s linear infinite;opacity:.95}
      .jsmind-node[data-status="backlog"]::before{opacity:.55}
      .jsmind-node[data-priority="high"]{border-color:rgba(209,75,75,.6);box-shadow:0 0 30px rgba(209,75,75,.28)}
      .jsmind-node.has-attachment{border-color:rgba(201,168,106,.42);box-shadow:0 0 26px rgba(227,198,139,.22)}
      .jsmind-node.has-link{box-shadow:0 0 24px rgba(75,195,209,.24)}
      .jsmind-node.drop-target{border:2px dashed rgba(201,168,106,.85);background:rgba(15,19,22,.9)}
      .jsmind-node .node-topic{position:relative;z-index:1;display:block;font:600 16px/1.4 'Cinzel','Noto Serif SC','Noto Sans SC',serif;letter-spacing:.06em;text-transform:none;color:var(--text-strong);text-shadow:0 0 18px rgba(227,198,139,.24);word-break:break-word;white-space:pre-wrap}
      .jsmind-node .node-topic::after{content:"";position:absolute;top:-12px;right:-16px;width:12px;height:12px;border-radius:50%;background:radial-gradient(circle at 30% 30%,rgba(227,198,139,.9),rgba(170,140,84,.8));box-shadow:0 0 14px rgba(227,198,139,.4);opacity:0;transform:scale(.85);animation:amber-blink 2s ease-in-out infinite;pointer-events:none}
      .jsmind-node[data-status="backlog"] .node-topic::after{opacity:1}
      .jsmind-node .node-topic[contenteditable="true"]{outline:none;cursor:text}
      .jsmind-node .node-flair{display:flex;gap:8px;flex-wrap:wrap;align-items:center;font:600 11px/1 'Inter','Noto Sans SC',sans-serif;text-transform:uppercase;letter-spacing:.18em;color:var(--text-muted)}
      .jsmind-node .node-flair .pill{padding:4px 8px;border-radius:999px;border:1px dashed rgba(201,168,106,.28);background:rgba(201,168,106,.08)}
      .jsmind-node .node-meta{display:flex;gap:8px;flex-wrap:wrap;font:500 11px/1.4 'Inter','Noto Sans SC',sans-serif;color:var(--text-dim)}
      .jsmind-node .node-links{display:flex;gap:8px;flex-wrap:wrap}
      .jsmind-node .node-links a{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:var(--r-xs);border:1px solid rgba(201,168,106,.28);background:rgba(12,16,18,.7);color:var(--gold-400);font:500 11px/1 'Inter','Noto Sans SC',sans-serif;letter-spacing:.1em;transition:all var(--t) var(--ease)}
      .jsmind-node .node-links a:hover{background:rgba(201,168,106,.18);box-shadow:0 0 18px rgba(227,198,139,.16)}
      .jsmind-node .node-attachments{display:flex;gap:8px;flex-wrap:wrap}
      .jsmind-node .node-attachments button{padding:6px 10px;border-radius:var(--r-xs);border:1px dashed rgba(201,168,106,.28);background:rgba(12,16,18,.7);color:var(--text-strong);font:500 11px/1 'Inter','Noto Sans SC',sans-serif;letter-spacing:.1em;cursor:pointer}
      .jsmind-node .node-attachments button:hover{background:rgba(201,168,106,.16)}
      .jsmind-node .node-progress{position:relative;width:100%;height:8px;border-radius:999px;background:rgba(201,168,106,.12);overflow:hidden}
      .jsmind-node .node-progress span{position:absolute;left:0;top:0;bottom:0;border-radius:999px;background:linear-gradient(90deg,rgba(201,168,106,.85),rgba(170,140,84,.9));box-shadow:0 0 18px rgba(227,198,139,.25);animation:progress-flow 4s linear infinite}
      .sidebar-toggle{position:absolute;top:18px;left:18px;padding:10px 14px;border-radius:var(--r-sm);border:1px solid rgba(201,168,106,.3);background:rgba(12,16,18,.7);color:var(--gold-400);font:600 11px/1 'Inter','Noto Sans SC',sans-serif;text-transform:uppercase;letter-spacing:.16em;cursor:pointer;box-shadow:inset 0 0 12px rgba(201,168,106,.12);display:none;z-index:80}
      .sidebar-toggle[aria-expanded="true"]{background:rgba(201,168,106,.2)}
      .sidebar-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.6);backdrop-filter:blur(6px);z-index:70}
      .mobile-toolbar{position:absolute;bottom:20px;left:50%;transform:translateX(-50%);display:flex;gap:10px;padding:10px 12px;border-radius:999px;background:rgba(15,19,22,.92);border:1px solid rgba(201,168,106,.28);box-shadow:0 18px 40px rgba(0,0,0,.55);backdrop-filter:blur(12px);z-index:70}
      .mobile-toolbar button{padding:10px 12px;border-radius:12px;border:1px solid rgba(201,168,106,.28);background:rgba(12,16,18,.7);color:var(--gold-400);font:600 11px/1 'Inter','Noto Sans SC',sans-serif;text-transform:uppercase;letter-spacing:.14em}
      .mobile-toolbar button.primary{color:var(--bg-void);background:linear-gradient(135deg,rgba(227,198,139,.86),rgba(170,140,84,.82));border-color:rgba(227,198,139,.7)}
      .mobile-toolbar button.danger{color:rgba(255,224,224,.92);border-color:rgba(209,75,75,.55)}
      .mobile-save-status{position:absolute;bottom:88px;left:50%;transform:translateX(-50%);padding:10px 16px;border-radius:var(--r-sm);border:1px solid rgba(201,168,106,.3);background:rgba(12,16,18,.8);color:var(--text-strong);font:600 11px/1 'Inter','Noto Sans SC',sans-serif;text-transform:uppercase;letter-spacing:.16em;box-shadow:0 0 18px rgba(227,198,139,.16);display:none;z-index:70}
      .mobile-save-status.show{display:block}
      @media (max-width:1080px){.layout{grid-template-columns:1fr}.sidebar{position:fixed;left:-100%;top:0;bottom:0;width:320px;transition:transform var(--t) var(--ease);z-index:75}.layout.sidebar-open .sidebar{transform:translateX(100%)}.sidebar-toggle{display:block}}
      @keyframes edge-breathe{0%,100%{opacity:.75}50%{opacity:1}}
      @keyframes edge-pulse{to{stroke-dashoffset:-260}}
      @keyframes node-glow{0%,100%{opacity:.7}50%{opacity:1}}
      @keyframes shield{to{transform:rotate(360deg)}}
      @keyframes core-breathe{0%,100%{box-shadow:0 0 36px rgba(227,198,139,.28),0 44px 90px rgba(0,0,0,.7)}50%{box-shadow:0 0 48px rgba(227,198,139,.32),0 48px 96px rgba(0,0,0,.74)}}
      @keyframes crystal{0%,100%{opacity:.85}50%{opacity:1}}
      @keyframes energy-loop{to{background-position:200px 0}}
      @keyframes amber-blink{0%,100%{opacity:.25;transform:scale(.8)}50%{opacity:.85;transform:scale(1)}}
      @keyframes progress-flow{to{transform:translateX(20%)}}
    </style>

  </head>
  <body>
    <div class="scanlines" aria-hidden="true"></div>
    <svg aria-hidden="true" width="0" height="0" style="position:absolute">
      <defs>
        <linearGradient id="gold-wire" x1="0" y1="0" x2="1" y2="0">
          <stop offset="0%" stop-color="#AA8C54"/>
          <stop offset="50%" stop-color="#D1B274"/>
          <stop offset="100%" stop-color="#E3C68B"/>
        </linearGradient>
        <linearGradient id="gold-wire-strong" x1="0" y1="0" x2="1" y2="0">
          <stop offset="0%" stop-color="#E3C68B"/>
          <stop offset="50%" stop-color="#D1B274"/>
          <stop offset="100%" stop-color="#AA8C54"/>
        </linearGradient>
      </defs>
    </svg>
    <div class="layout">
      <aside class="sidebar">
        <div style="display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap">
          <h1>思维导图编辑器</h1>
          <a href="<?= htmlspecialchars($_SERVER['PHP_SELF'].'?view=maps') ?>" style="font-size:13px;color:var(--muted)">← 导图库</a>
        </div>
        <div class="meta">ID：<?php echo $mind['id'] ?: '新建'; ?> · 最近保存：<?php echo dt((int)$mind['updated_at']); ?></div>
        <label for="map-title">导图标题</label>
        <input id="map-title" value="<?php echo h($mind['title']); ?>" placeholder="输入导图标题">
        <div class="actions">
          <button id="btn-save" class="acc">💾 保存</button>
          <button id="btn-export-json">⬇️ 导出 JSON</button>
          <button id="btn-import-json">⬆️ 导入 JSON</button>
          <input id="import-input" type="file" accept="application/json" style="display:none">
        </div>
        <div class="toolbar">
          <button id="btn-add-sibling">↕ 同级节点 (Enter)</button>
          <button id="btn-add-child">→ 子级节点 (Tab)</button>
          <button id="btn-delete" class="danger">🗑 删除节点 (Del)</button>
        </div>
        <div class="toolbar">
          <button id="btn-attach-file">📎 上传附件</button>
          <button id="btn-attach-link">🔗 新增链接</button>
          <input id="attach-file-input" type="file" accept="image/*,application/pdf,application/zip,application/x-zip-compressed,text/plain,text/markdown,text/csv,application/json,video/*" style="display:none">
        </div>
        <section class="inspector" id="node-inspector">
          <h2>节点信息</h2>
          <div class="field">
            <label for="node-type">类型</label>
            <select id="node-type">
              <option value="idea">💡 创意</option>
              <option value="task">✅ 任务</option>
              <option value="document">📄 文档</option>
              <option value="media">🖼 媒体</option>
              <option value="decision">🧭 决策</option>
            </select>
          </div>
          <div class="field">
            <label for="node-status">状态</label>
            <select id="node-status">
              <option value="backlog">待计划</option>
              <option value="doing">进行中</option>
              <option value="done">已完成</option>
            </select>
          </div>
          <div class="field">
            <label for="node-priority">优先级</label>
            <select id="node-priority">
              <option value="normal">普通</option>
              <option value="high">高</option>
              <option value="low">低</option>
            </select>
          </div>
          <div class="field">
            <label for="node-owner">负责人</label>
            <input id="node-owner" type="text" placeholder="输入姓名或团队">
          </div>
          <div class="field">
            <label for="node-tags">标签</label>
            <input id="node-tags" type="text" placeholder="用逗号分隔多个标签">
            <div class="chips-preview" id="node-tags-preview"><span class="empty">暂无标签</span></div>
          </div>
        </section>
        <div class="toolbar">
          <button id="btn-fit">🧭 自适应视图</button>
          <button id="btn-center">◎ 居中</button>
          <button id="btn-zoom-in">＋ 放大</button>
          <button id="btn-zoom-out">－ 缩小</button>
        </div>
        <div class="tips">
          <strong>快捷键</strong><br>
          <code>Enter</code> 同级 · <code>Tab</code> 子级 · <code>Shift+Tab</code> 升级 · <code>Del</code> 删除 · <code>F2</code> 重命名 · <code>Ctrl/Cmd+Z</code> 撤销<br>
          鼠标中键/空格拖拽 · 滚轮缩放 · 按住 <code>Alt</code> 拖动可复制节点。<br>
          将图片、PDF、ZIP、文本或视频文件拖到节点上即可为该节点附加附件，超过 15MB 或不在白名单内的文件将被拒绝。
        </div>
        <div>
          <span class="badge">提示</span>
          <div class="meta" style="margin-top:6px">保存数据存入 SQLite，可多端共享；导出 JSON 可用于备份或导入其他工具（如 FreeMind、XMind）。</div>
        </div>
        <div class="save-tip" id="save-state">保存成功</div>
        <?php if ($mind['id']): ?>
          <form method="post" onsubmit="return confirm('确认删除该导图？');" style="margin-top:auto">
            <input type="hidden" name="action" value="delete_mindmap">
            <input type="hidden" name="id" value="<?php echo $mind['id']; ?>">
            <button class="btn-like danger btn-block">删除导图</button>
          </form>
        <?php endif; ?>
      </aside>
      <main class="editor-pane">
        <button type="button" id="sidebar-toggle" class="sidebar-toggle" aria-expanded="false">☰ 操作</button>
        <div id="jsmind-container" data-map-id="<?php echo $mind['id']; ?>"></div>
        <div class="map-toolbar">
          <button id="btn-collapse">折叠/展开节点</button>
          <button id="btn-fit-floating">自适应视图</button>
        </div>
        <div class="mobile-toolbar" id="mobile-toolbar">
          <button data-action="save" class="primary">保存</button>
          <button data-action="add-sibling">同级</button>
          <button data-action="add-child">子级</button>
          <button data-action="attach-file">附件</button>
          <button data-action="attach-link">链接</button>
          <button data-action="delete" class="danger">删除</button>
        </div>
        <div class="mobile-save-status" id="mobile-save-status" role="status" aria-live="polite"></div>
      </main>
    </div>
    <div class="sidebar-backdrop" id="sidebar-backdrop" hidden></div>
    <script>
      (function(){
      const DOUBLE_TAP_WINDOW=320;
      const NODE_TYPES=[
        {value:'idea',label:'创意',icon:'💡',accent:'#0284c7'},
        {value:'task',label:'任务',icon:'✅',accent:'#ca8a04'},
        {value:'document',label:'文档',icon:'📄',accent:'#1d4ed8'},
        {value:'media',label:'媒体',icon:'🖼',accent:'#db2777'},
        {value:'decision',label:'决策',icon:'🧭',accent:'#059669'},
      ];
      const NODE_STATUS=[
        {value:'backlog',label:'待计划',icon:'⏳'},
        {value:'doing',label:'进行中',icon:'🛠'},
        {value:'done',label:'已完成',icon:'✅'},
      ];
      const NODE_PRIORITY=[
        {value:'high',label:'高',icon:'⚡'},
        {value:'normal',label:'普通',icon:'•'},
        {value:'low',label:'低',icon:'⬇️'},
      ];
      const TYPE_ICON_MAP=NODE_TYPES.reduce((acc,item)=>{acc[item.value]=item.icon;return acc;},{});
      const TYPE_ACCENT_MAP=NODE_TYPES.reduce((acc,item)=>{acc[item.value]=item.accent;return acc;},{});
      const isCompactViewport=()=>window.matchMedia('(max-width: 900px)').matches;
      let lastTapInfo={id:null,time:0};
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
        const allowedTypes=NODE_TYPES.map(item=>item.value);
        const allowedStatus=NODE_STATUS.map(item=>item.value);
        const allowedPriority=NODE_PRIORITY.map(item=>item.value);
        if(!allowedTypes.includes(data.type)){ data.type='idea'; }
        if(!allowedStatus.includes(data.status)){ data.status='backlog'; }
        if(!allowedPriority.includes(data.priority)){ data.priority='normal'; }
        if(typeof data.owner!=='string'){ data.owner=''; }
        else{ data.owner=data.owner.trim(); }
        if(Array.isArray(data.tags)){
          data.tags=data.tags.map(tag=>String(tag||'').trim()).filter(Boolean);
        }else if(typeof data.tags==='string'){
          data.tags=data.tags.split(/[;,，\s]+/).map(tag=>tag.trim()).filter(Boolean);
        }else{
          data.tags=[];
        }
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
        toggle_node(id){
          const node=this.nodes.get(id);
          if(!node) return;
          node.expanded=!node.expanded;
          node.model.expanded=node.expanded;
          this.computeLayout();
          this.render();
          this.emit(SimpleMind.event_type.refresh);
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
          if(node.selected && !forMeasure){ el.classList.add('selected'); }
          el.dataset.nodeid=node.id;
          el.setAttribute('nodeid', node.id);
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
          const type=(data.type && TYPE_ICON_MAP[data.type])?data.type:'idea';
          el.dataset.type=type;
          el.dataset.icon=TYPE_ICON_MAP[type] || '🧠';
          el.dataset.depth=String(node.depth||0);
          if(TYPE_ACCENT_MAP[type]){ el.style.setProperty('--node-accent', TYPE_ACCENT_MAP[type]); }
          if(data.status){ el.dataset.status=data.status; } else{ el.dataset.status='backlog'; }
          if(data.priority){ el.dataset.priority=data.priority; } else{ el.dataset.priority='normal'; }
          const attachments=gatherAttachments(data);
          if(attachments.length){ el.classList.add('has-attachment'); }
          if(data.url){ el.classList.add('has-link'); }
          const flairElements=[];
          const statusInfo=NODE_STATUS.find(item=>item.value===data.status);
          if(statusInfo){
            const badge=document.createElement('span');
            badge.className='pill status';
            badge.textContent=`${statusInfo.icon||''} ${statusInfo.label}`.trim();
            flairElements.push(badge);
          }
          const priorityInfo=NODE_PRIORITY.find(item=>item.value===data.priority);
          if(priorityInfo && data.priority!=='normal'){
            const badge=document.createElement('span');
            badge.className='pill priority';
            badge.textContent=`${priorityInfo.icon||''} ${priorityInfo.label}`.trim();
            flairElements.push(badge);
          }
          if(data.owner){
            const badge=document.createElement('span');
            badge.className='pill owner';
            badge.textContent=`👤 ${data.owner}`;
            flairElements.push(badge);
          }
          if(flairElements.length){
            const wrap=document.createElement('div');
            wrap.className='node-flair';
            flairElements.forEach(item=>wrap.appendChild(item));
            el.appendChild(wrap);
          }
          if(data.tags && data.tags.length){
            const tagWrap=document.createElement('div');
            tagWrap.className='node-tags';
            data.tags.slice(0,6).forEach(tag=>{
              const chip=document.createElement('span');
              chip.className='node-tag';
              chip.textContent=tag;
              tagWrap.appendChild(chip);
            });
            el.appendChild(tagWrap);
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
          if(!forMeasure){
            el.addEventListener('click',(evt)=>{
              const wasSelected=!!node.selected;
              this.select_node(node.id);
              if(isCompactViewport()){
                const now=Date.now();
                if(wasSelected && lastTapInfo.id===node.id && (now-lastTapInfo.time)<=DOUBLE_TAP_WINDOW){
                  evt.preventDefault();
                  this.promptRename(node);
                }
                lastTapInfo={id:node.id,time:now};
              }
            });
            el.addEventListener('dblclick',()=>{ this.promptRename(node); });
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
              const path=document.createElementNS('http://www.w3.org/2000/svg','path');
              path.setAttribute('data-from', node.parent.id);
              path.setAttribute('data-to', node.id);
              path.setAttribute('data-type', node.data && node.data.type ? node.data.type : 'idea');
              this.linkLayer.appendChild(path);
              node.linkPath=path;
              this.linkRegistry.set(node.id,path);
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
          const horizontalGap=Math.max(60, Math.abs(end.x-start.x)*0.35);
          const controlX=start.x + (isLeft?-horizontalGap:horizontalGap);
          const controlY1=start.y;
          const controlY2=end.y;
          node.linkPath.setAttribute('d', `M${start.x} ${start.y} C ${controlX} ${controlY1} ${controlX} ${controlY2} ${end.x} ${end.y}`);
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
          requestAnimationFrame(updateHandlePosition);
          return;
        }
        let value=span.textContent || '';
        value=value.replace(/\r/g,'');
        value=value.split('\n').map(line=>line.trim()).join('\n').trim();
        if(!value){
          span.textContent=initialText;
          requestAnimationFrame(updateHandlePosition);
          return;
        }
        if(value!==initialText){
          if(typeof jm.update_node==='function'){ jm.update_node(nodeId, value); }
          markDirty();
        }
        requestAnimationFrame(updateHandlePosition);
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
      window.addEventListener('resize',()=>requestAnimationFrame(updateHandlePosition));
      document.addEventListener('scroll',()=>requestAnimationFrame(updateHandlePosition), true);
      requestAnimationFrame(updateHandlePosition);
      const titleInput=document.getElementById('map-title');
      const saveState=document.getElementById('save-state');
      const importInput=document.getElementById('import-input');
      const attachInput=document.getElementById('attach-file-input');
      const attachFileBtn=document.getElementById('btn-attach-file');
      const attachLinkBtn=document.getElementById('btn-attach-link');
      const inspector=document.getElementById('node-inspector');
      const nodeTypeSelect=document.getElementById('node-type');
      const nodeStatusSelect=document.getElementById('node-status');
      const nodePrioritySelect=document.getElementById('node-priority');
      const nodeOwnerInput=document.getElementById('node-owner');
      const nodeTagsInput=document.getElementById('node-tags');
      const nodeTagsPreview=document.getElementById('node-tags-preview');
      const mobileToolbar=document.getElementById('mobile-toolbar');
      const mobileSaveButton=mobileToolbar ? mobileToolbar.querySelector('button[data-action="save"]') : null;
      const mobileSaveStatus=document.getElementById('mobile-save-status');
      const sidebarToggle=document.getElementById('sidebar-toggle');
      const sidebarBackdrop=document.getElementById('sidebar-backdrop');
      const saveButton=document.getElementById('btn-save');
      const fitButton=document.getElementById('btn-fit');
      const fitFloatingButton=document.getElementById('btn-fit-floating');
      const addSiblingButton=document.getElementById('btn-add-sibling');
      const addChildButton=document.getElementById('btn-add-child');
      const deleteButton=document.getElementById('btn-delete');
      let saveButtonDefault=saveButton ? saveButton.textContent : '保存';
      if(saveButton){ saveButton.dataset.defaultLabel=saveButtonDefault; }
      let mobileSaveDefault=mobileSaveButton ? mobileSaveButton.textContent : '保存';
      if(mobileSaveButton){ mobileSaveButton.dataset.defaultLabel=mobileSaveDefault; }
      let dirty=false;
      let mobileStatusTimer=null;
      const commandLog=[];
      window.__mindmapCommands=commandLog;
      const ATTACH_MAX_BYTES=15*1024*1024;
      const imageExts=['.png','.jpg','.jpeg','.gif','.webp','.bmp','.svg','.avif','.heic','.heif'];
      const textExts=['.txt','.md','.markdown','.csv','.json','.yaml','.yml','.log'];
      const videoExts=['.mp4','.mov','.mkv','.avi','.webm','.m4v'];
      function setMobileSaveStatus(text, state, opts={}){
        if(!mobileSaveStatus) return;
        if(mobileStatusTimer){ clearTimeout(mobileStatusTimer); mobileStatusTimer=null; }
        if(!text){
          mobileSaveStatus.textContent='';
          mobileSaveStatus.className='mobile-save-status';
          return;
        }
        mobileSaveStatus.textContent=text;
        mobileSaveStatus.className='mobile-save-status show';
        if(state){ mobileSaveStatus.classList.add(`state-${state}`); }
        if(opts.autoHide){
          const duration=typeof opts.duration==='number' && isFinite(opts.duration)?opts.duration:1800;
          mobileStatusTimer=setTimeout(()=>{
            mobileStatusTimer=null;
            if(!dirty){ setMobileSaveStatus('', null); }
          }, duration);
        }
      }
      function setSaveButtonState(text, disabled){
        if(typeof text==='string'){
          if(saveButton) saveButton.textContent=text;
          if(mobileSaveButton) mobileSaveButton.textContent=text;
        }else if(text===null){
          if(saveButton) saveButton.textContent=saveButtonDefault;
          if(mobileSaveButton) mobileSaveButton.textContent=mobileSaveDefault;
        }
        if(typeof disabled==='boolean'){
          if(saveButton) saveButton.disabled=disabled;
          if(mobileSaveButton) mobileSaveButton.disabled=disabled;
        }
      }
      function markDirty(){
        dirty=true;
        if(saveState){
          saveState.textContent='未保存';
          saveState.classList.add('show','dirty');
        }
        setSaveButtonState(null,false);
        setMobileSaveStatus('未保存','dirty');
      }
      function showSaving(){
        if(saveState){
          saveState.textContent='保存中...';
          saveState.classList.add('show');
          saveState.classList.remove('dirty');
        }
        setSaveButtonState('⏳ 保存中...', true);
        setMobileSaveStatus('保存中...','saving');
      }
      function markSaved(){
        dirty=false;
        if(saveState){
          saveState.textContent='保存成功';
          saveState.classList.add('show');
          saveState.classList.remove('dirty');
        }
        setSaveButtonState('✅ 保存成功', false);
        setMobileSaveStatus('保存成功','success',{autoHide:true});
        setTimeout(()=>{
          if(!dirty){
            if(saveState) saveState.classList.remove('show');
            setSaveButtonState(null,false);
            setMobileSaveStatus('', null);
          }
        },1500);
      }
      const inspectorFields=[nodeTypeSelect,nodeStatusSelect,nodePrioritySelect,nodeOwnerInput,nodeTagsInput].filter(Boolean);
      let inspectorSyncing=false;
      function parseTagsString(value){
        if(!value) return [];
        return value.split(/[;,，\s]+/).map(tag=>tag.trim()).filter(Boolean).slice(0,12);
      }
      function setInspectorEnabled(enabled){
        inspectorFields.forEach(el=>{ el.disabled=!enabled; });
        if(inspector){ inspector.classList.toggle('disabled', !enabled); }
      }
      function renderTagPreview(tags){
        if(!nodeTagsPreview) return;
        nodeTagsPreview.innerHTML='';
        if(!tags || !tags.length){
          const span=document.createElement('span');
          span.className='empty';
          span.textContent='暂无标签';
          nodeTagsPreview.appendChild(span);
          return;
        }
        tags.slice(0,12).forEach(tag=>{
          const span=document.createElement('span');
          span.textContent=tag;
          nodeTagsPreview.appendChild(span);
        });
      }
      function refreshInspector(node){
        inspectorSyncing=true;
        if(!node){
          setInspectorEnabled(false);
          if(nodeTypeSelect) nodeTypeSelect.value='idea';
          if(nodeStatusSelect) nodeStatusSelect.value='backlog';
          if(nodePrioritySelect) nodePrioritySelect.value='normal';
          if(nodeOwnerInput) nodeOwnerInput.value='';
          if(nodeTagsInput) nodeTagsInput.value='';
          renderTagPreview([]);
          inspectorSyncing=false;
          return;
        }
        setInspectorEnabled(true);
        const data=normalizeNodeData(deepClone(node.data||{}));
        if(nodeTypeSelect) nodeTypeSelect.value=data.type||'idea';
        if(nodeStatusSelect) nodeStatusSelect.value=data.status||'backlog';
        if(nodePrioritySelect) nodePrioritySelect.value=data.priority||'normal';
        if(nodeOwnerInput) nodeOwnerInput.value=data.owner||'';
        if(nodeTagsInput) nodeTagsInput.value=data.tags && data.tags.length?data.tags.join(', '):'';
        renderTagPreview(data.tags||[]);
        inspectorSyncing=false;
      }
      refreshInspector(jm.get_selected_node());
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
        if(!payloadData.type && parent && parent.data && parent.data.type){ payloadData.type=parent.data.type; }
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
        requestAnimationFrame(updateHandlePosition);
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
        requestAnimationFrame(updateHandlePosition);
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
        requestAnimationFrame(updateHandlePosition);
        requestAnimationFrame(()=>refreshInspector(jm.get_selected_node()));
      }
      function renameSelectedNode(){
        const node=ensureNode();
        if(!node) return;
        startInlineEditing(node);
      }
      function focusParentNode(){
        const node=ensureNode();
        if(node && node.parent){ jm.select_node(node.parent.id); requestAnimationFrame(updateHandlePosition); }
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
        requestAnimationFrame(updateHandlePosition);
        refreshInspector(jm.get_node(node.id));
      }
      if(addSiblingButton) addSiblingButton.onclick=addSiblingNode;
      if(addChildButton) addChildButton.onclick=addChildNode;
      if(deleteButton) deleteButton.onclick=deleteSelectedNode;
      if(attachFileBtn) attachFileBtn.onclick=openAttachmentDialog;
      if(attachLinkBtn) attachLinkBtn.onclick=openLinkPrompt;
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
      if(nodeTypeSelect){
        nodeTypeSelect.addEventListener('change',()=>{
          if(inspectorSyncing) return;
          const value=nodeTypeSelect.value;
          applyInspectorChange(data=>{ data.type=value; });
        });
      }
      if(nodeStatusSelect){
        nodeStatusSelect.addEventListener('change',()=>{
          if(inspectorSyncing) return;
          const value=nodeStatusSelect.value;
          applyInspectorChange(data=>{ data.status=value; });
        });
      }
      if(nodePrioritySelect){
        nodePrioritySelect.addEventListener('change',()=>{
          if(inspectorSyncing) return;
          const value=nodePrioritySelect.value;
          applyInspectorChange(data=>{ data.priority=value; });
        });
      }
      if(nodeOwnerInput){
        const commit=()=>{
          if(inspectorSyncing) return;
          const value=nodeOwnerInput.value.trim();
          applyInspectorChange(data=>{ data.owner=value; });
        };
        nodeOwnerInput.addEventListener('change',commit);
        nodeOwnerInput.addEventListener('blur',commit);
      }
      if(nodeTagsInput){
        nodeTagsInput.addEventListener('input',()=>{
          if(inspectorSyncing) return;
          renderTagPreview(parseTagsString(nodeTagsInput.value));
        });
        const commitTags=()=>{
          if(inspectorSyncing) return;
          const tags=parseTagsString(nodeTagsInput.value);
          applyInspectorChange(data=>{ data.tags=tags; });
        };
        nodeTagsInput.addEventListener('change',commitTags);
        nodeTagsInput.addEventListener('blur',commitTags);
      }
      function setSidebar(open){
        document.body.classList.toggle('sidebar-open', !!open);
        if(sidebarToggle){ sidebarToggle.setAttribute('aria-expanded', open?'true':'false'); }
        if(sidebarBackdrop){ sidebarBackdrop.hidden=!open; }
      }
      if(sidebarToggle){
        sidebarToggle.addEventListener('click',()=>{
          const willOpen=!document.body.classList.contains('sidebar-open');
          setSidebar(willOpen);
        });
      }
      if(sidebarBackdrop){ sidebarBackdrop.addEventListener('click',()=>setSidebar(false)); }
      const sidebarMedia=window.matchMedia('(max-width: 1024px)');
      const handleSidebarMedia=(evt)=>{ if(!evt.matches){ setSidebar(false); } };
      sidebarMedia.addEventListener('change',handleSidebarMedia);
      setSidebar(false);
      if(mobileToolbar){
        mobileToolbar.addEventListener('click',e=>{
          const btn=e.target.closest('button');
          if(!btn) return;
          switch(btn.dataset.action){
            case 'save': saveMindmap(); break;
            case 'add-sibling': addSiblingNode(); break;
            case 'add-child': addChildNode(); break;
            case 'attach-file': openAttachmentDialog(); break;
            case 'attach-link': openLinkPrompt(); break;
            case 'delete': deleteSelectedNode(); break;
          }
        });
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
      if(fitButton){
        fitButton.onclick=()=>{
          if(!callView('zoomToFit') && !callView('zoom_to_fit')){
            callView('set_zoom', 1);
            if(!callView('move_to_center')) callView('center_root');
          }
        };
      }
      if(fitFloatingButton && fitButton){
        fitFloatingButton.onclick=()=>fitButton.click();
      }
      const centerButton=document.getElementById('btn-center');
      if(centerButton){ centerButton.onclick=()=>{ if(!callView('move_to_center')) callView('center_root'); }; }
      const zoomInButton=document.getElementById('btn-zoom-in');
      if(zoomInButton){ zoomInButton.onclick=()=>{ if(!callView('zoomIn')) callView('zoom_in'); }; }
      const zoomOutButton=document.getElementById('btn-zoom-out');
      if(zoomOutButton){ zoomOutButton.onclick=()=>{ if(!callView('zoomOut')) callView('zoom_out'); }; }
      const collapseButton=document.getElementById('btn-collapse');
      if(collapseButton){
        collapseButton.addEventListener('click',()=>{
          const node=ensureNode();
          if(node){ jm.toggle_node(node.id); markDirty(); requestAnimationFrame(updateHandlePosition); }
        });
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
          if(type===jsMind.event_type.select){
            const selected=jm.get_selected_node();
            const editingId=currentEditingId();
            if(editingId && (!selected || selected.id!==editingId)){
              commitInlineEditing();
            }
          }
          if(type===jsMind.event_type.select || type===jsMind.event_type.refresh || type===jsMind.event_type.after_edit || type===jsMind.event_type.show){
            requestAnimationFrame(updateHandlePosition);
            requestAnimationFrame(()=>refreshInspector(jm.get_selected_node()));
          }
          if(type===jsMind.event_type.edit || type===jsMind.event_type.after_edit || type===jsMind.event_type.update){ markDirty(); }
        });
      }
      const exportButton=document.getElementById('btn-export-json');
      if(exportButton){
        exportButton.addEventListener('click',()=>{
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
        });
      }
      const importButton=document.getElementById('btn-import-json');
      if(importButton && importInput){
        importButton.addEventListener('click',()=>importInput.click());
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
      if(saveButton) saveButton.onclick=saveMindmap;
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
          setMobileSaveStatus(err && err.message ? err.message : '保存失败','error');
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
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@500;600;700&family=Inter:wght@400;500;600&family=Noto+Sans+SC:wght@400;500;700&family=Noto+Serif+SC:wght@500;600;700&display=swap" rel="stylesheet">
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
        --r-xs:6px;--r-sm:10px;--r-md:14px;--r-lg:18px;
        --shadow-1:0 8px 24px rgba(0,0,0,.5),0 0 24px rgba(227,198,139,.12);
        --shadow-2:0 16px 48px rgba(0,0,0,.6) inset,0 0 1px rgba(201,168,106,.35);
        --t-fast:200ms;--t:300ms;--t-slow:450ms;--ease:cubic-bezier(.22,.61,.36,1);
      }
      *,*::before,*::after{box-sizing:border-box}
      html,body{margin:0;min-height:100vh;background:var(--bg-void);color:var(--text-strong);font:16px/1.6 'Noto Sans SC','Source Han Sans SC','Inter',sans-serif;letter-spacing:.01em;position:relative;overflow-x:hidden}
      body{animation:body-breathe 10s ease-in-out infinite}
      body::before{content:"";position:fixed;inset:0;background:
        radial-gradient(1200px 720px at 70% -10%,rgba(255,255,255,.05),transparent 60%),
        linear-gradient(120deg,rgba(201,168,106,.08),transparent 30%,rgba(201,168,106,.06) 70%,transparent 90%),
        #050607;
        z-index:-3;
      }
      body::after{content:"";position:fixed;inset:0;background:
        linear-gradient(180deg,rgba(75,195,209,.08) 0,transparent 55%),
        repeating-linear-gradient(90deg,rgba(201,168,106,.07) 0,rgba(201,168,106,.07) 1px,transparent 1px,transparent 60px),
        repeating-linear-gradient(0deg,rgba(201,168,106,.05) 0,rgba(201,168,106,.05) 1px,transparent 1px,transparent 60px);
        mix-blend-mode:screen;opacity:.52;pointer-events:none;z-index:-2;animation:grid-pan 24s linear infinite;
      }
      @keyframes body-breathe{0%,100%{filter:brightness(.94)}50%{filter:brightness(1)}}
      @keyframes grid-pan{0%{background-position:0 0,0 0,0 0}100%{background-position:0 80px,60px 0,0 60px}}
      .scanlines{position:fixed;inset:0;pointer-events:none;z-index:-1;background:linear-gradient(to bottom,rgba(75,195,209,.12) 0,transparent 3px);background-size:100% 5px;opacity:.28;animation:scan 12s linear infinite}
      @keyframes scan{0%{transform:translateY(-100%)}100%{transform:translateY(100%)} }
      a{color:inherit;text-decoration:none}
      .wrap{max-width:1180px;margin:0 auto;padding:36px 24px 96px;position:relative;z-index:0}
      .wrap::before{content:"";position:absolute;inset:24px;border-radius:var(--r-lg);border:1px solid rgba(201,168,106,.14);opacity:.6;pointer-events:none;box-shadow:0 0 64px rgba(0,0,0,.45)}
      .header{display:flex;gap:18px;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;margin-bottom:24px}
      .header h1{margin:0;font:600 28px/1.3 'Cinzel','Noto Serif SC','Noto Sans SC',serif;text-transform:uppercase;letter-spacing:.18em;color:var(--gold-400);text-shadow:0 0 24px rgba(227,198,139,.24)}
      .header .meta{color:var(--text-dim);font:14px/1.8 'Inter','Noto Sans SC',sans-serif;letter-spacing:.08em;max-width:520px}
      .btn{position:relative;display:inline-flex;align-items:center;justify-content:center;padding:12px 18px;border-radius:var(--r-sm);border:1px solid rgba(201,168,106,.35);background:rgba(12,16,18,.6);color:var(--gold-400);font:600 12px/1 'Inter','Noto Sans SC',sans-serif;text-transform:uppercase;letter-spacing:.18em;cursor:pointer;transition:all var(--t) var(--ease);box-shadow:inset 0 0 12px rgba(201,168,106,.12)}
      .btn::after{content:"";position:absolute;inset:2px;border-radius:calc(var(--r-sm)-2px);border:1px dashed rgba(201,168,106,.3);opacity:.65;transition:opacity var(--t) var(--ease);pointer-events:none}
      .btn:hover{transform:translateY(-2px);background:rgba(201,168,106,.18);box-shadow:0 0 24px rgba(227,198,139,.14)}
      .btn:hover::after{opacity:1}
      .btn.acc{color:var(--bg-void);background:linear-gradient(135deg,rgba(227,198,139,.86),rgba(170,140,84,.8));border-color:rgba(227,198,139,.7);box-shadow:0 0 30px rgba(227,198,139,.2)}
      .btn.danger{color:rgba(255,224,224,.9);border-color:rgba(209,75,75,.6);background:linear-gradient(135deg,rgba(61,18,20,.9),rgba(35,12,14,.9));box-shadow:0 0 26px rgba(209,75,75,.22)}
      .btn:focus-visible{outline:2px solid var(--accent-cyan);outline-offset:3px}
      .search{margin:20px 0 28px;display:flex;align-items:center;gap:12px;padding:12px 16px;border-radius:var(--r-md);border:1px solid rgba(201,168,106,.26);background:linear-gradient(180deg,rgba(21,26,30,.9),rgba(15,19,22,.9));box-shadow:inset 0 0 26px rgba(201,168,106,.06)}
      .search span{font-size:18px;color:var(--gold-500)}
      .search input{flex:1;border:none;background:transparent;color:var(--text-strong);font:500 15px/1.5 'Inter','Noto Sans SC',sans-serif;letter-spacing:.06em}
      .search input::placeholder{color:var(--text-muted)}
      .empty{margin-top:32px;padding:32px;border-radius:var(--r-lg);border:1px solid rgba(201,168,106,.26);background:linear-gradient(180deg,rgba(21,26,30,.9),rgba(15,19,22,.92));box-shadow:var(--shadow-1);text-align:center;color:var(--text-muted);font:15px/1.8 'Inter','Noto Sans SC',sans-serif}
      .empty strong{display:block;font:600 18px/1.5 'Cinzel','Noto Serif SC','Noto Sans SC',serif;color:var(--gold-400);letter-spacing:.12em;margin-bottom:8px}
      .grid{display:grid;gap:20px;grid-template-columns:repeat(auto-fill,minmax(320px,1fr))}
      .card{position:relative;display:flex;flex-direction:column;gap:14px;padding:22px;border-radius:var(--r-lg);background:linear-gradient(180deg,rgba(21,26,30,.92),rgba(15,19,22,.94));border:1px solid rgba(201,168,106,.28);box-shadow:var(--shadow-1);transition:transform var(--t) var(--ease),box-shadow var(--t) var(--ease),border-color var(--t) var(--ease)}
      .card::before{content:"";position:absolute;inset:12px;border-radius:calc(var(--r-lg)-6px);border:1px dashed rgba(201,168,106,.2);opacity:.65;pointer-events:none;animation:card-glow 12s ease-in-out infinite}
      .card:hover{transform:translateY(-4px);box-shadow:0 0 28px rgba(227,198,139,.18),0 32px 70px rgba(0,0,0,.68);border-color:rgba(227,198,139,.45)}
      .card h2{margin:0;font:600 20px/1.4 'Cinzel','Noto Serif SC','Noto Sans SC',serif;color:var(--text-strong);letter-spacing:.08em;text-transform:none;text-shadow:0 0 18px rgba(227,198,139,.2)}
      .card .meta{color:var(--text-dim);font:12px/1.6 'Inter','Noto Sans SC',sans-serif;letter-spacing:.1em}
      .card pre{margin:0;font:500 13px/1.7 'Inter','Noto Sans SC',sans-serif;color:var(--text-muted);background:rgba(12,16,18,.55);border:1px solid rgba(201,168,106,.18);border-radius:var(--r-sm);padding:12px;max-height:160px;overflow:auto;white-space:pre-wrap}
      .card-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:auto}
      @keyframes card-glow{0%,100%{opacity:.55}50%{opacity:1}}
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
<link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@500;600;700&family=Inter:wght@400;500;600&family=Noto+Sans+SC:wght@400;500;700&family=Noto+Serif+SC:wght@500;600;700&display=swap" rel="stylesheet">
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
    --r-xs:6px;--r-sm:10px;--r-md:14px;--r-lg:18px;
    --shadow-1:0 8px 24px rgba(0,0,0,.5),0 0 24px rgba(227,198,139,.12);
    --shadow-2:0 16px 48px rgba(0,0,0,.6) inset,0 0 1px rgba(201,168,106,.35);
    --t-fast:200ms;--t:300ms;--t-slow:450ms;--ease:cubic-bezier(.22,.61,.36,1);
  }
  *,*::before,*::after{box-sizing:border-box}
  html,body{margin:0;min-height:100vh;background:var(--bg-void);color:var(--text-strong);font:16px/1.6 'Noto Sans SC','Source Han Sans SC','Inter',sans-serif;letter-spacing:.01em;position:relative;overflow-x:hidden}
  body::before{content:"";position:fixed;inset:0;background:
    radial-gradient(1200px 720px at 70% -10%,rgba(255,255,255,.06),transparent 60%),
    linear-gradient(120deg,rgba(201,168,106,.08),transparent 30%,rgba(201,168,106,.06) 70%,transparent 90%),
    #050607;
    z-index:-3;
  }
  body::after{content:"";position:fixed;inset:0;background:
    linear-gradient(180deg,rgba(75,195,209,.08) 0,transparent 55%),
    repeating-linear-gradient(90deg,rgba(201,168,106,.07) 0,rgba(201,168,106,.07) 1px,transparent 1px,transparent 60px),
    repeating-linear-gradient(0deg,rgba(201,168,106,.05) 0,rgba(201,168,106,.05) 1px,transparent 1px,transparent 60px);
    mix-blend-mode:screen;opacity:.55;pointer-events:none;z-index:-2;animation:grid-pan 24s linear infinite;
  }
  @keyframes grid-pan{0%{background-position:0 0,0 0,0 0}100%{background-position:0 80px,60px 0,0 60px}}
  .scanlines{position:fixed;inset:0;pointer-events:none;z-index:-1;background:linear-gradient(to bottom,rgba(75,195,209,.12) 0,transparent 3px);background-size:100% 5px;opacity:.28;animation:scan 12s linear infinite}
  @keyframes scan{0%{transform:translateY(-100%)}100%{transform:translateY(100%)} }
  a{color:inherit;text-decoration:none}
  .app{display:grid;grid-template-columns:300px 1fr;min-height:100vh;position:relative;z-index:0}
  .sidebar{position:sticky;top:0;height:100vh;overflow:auto;padding:28px 24px;background:linear-gradient(165deg,rgba(15,19,22,.94),rgba(10,14,16,.92));border-right:1px solid rgba(201,168,106,.2);box-shadow:0 24px 60px rgba(0,0,0,.55),inset 0 0 0 1px rgba(201,168,106,.12);backdrop-filter:blur(18px)}
  .brand{display:flex;gap:12px;align-items:center;margin-bottom:20px}
  .brand .logo{width:36px;height:36px;border-radius:12px;background:radial-gradient(circle at 30% 30%,rgba(227,198,139,.9),rgba(170,140,84,.4));box-shadow:0 0 20px rgba(227,198,139,.32)}
  .brand h1{margin:0;font:600 16px/1.3 'Cinzel','Noto Serif SC','Noto Sans SC',serif;text-transform:uppercase;letter-spacing:.18em;color:var(--gold-400);text-shadow:0 0 18px rgba(227,198,139,.2)}
  .controls{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px}
  .btn{position:relative;display:inline-flex;align-items:center;justify-content:center;padding:10px 16px;border-radius:var(--r-sm);border:1px solid rgba(201,168,106,.32);background:rgba(12,16,18,.6);color:var(--gold-400);font:600 12px/1 'Inter','Noto Sans SC',sans-serif;letter-spacing:.16em;text-transform:uppercase;cursor:pointer;transition:all var(--t) var(--ease);box-shadow:inset 0 0 12px rgba(201,168,106,.12)}
  .btn::after{content:"";position:absolute;inset:2px;border-radius:calc(var(--r-sm)-2px);border:1px dashed rgba(201,168,106,.28);opacity:.65;transition:opacity var(--t) var(--ease)}
  .btn:hover{transform:translateY(-2px);background:rgba(201,168,106,.18);box-shadow:0 0 22px rgba(227,198,139,.16)}
  .btn:hover::after{opacity:1}
  .btn.acc{color:var(--bg-void);background:linear-gradient(135deg,rgba(227,198,139,.86),rgba(170,140,84,.8));border-color:rgba(227,198,139,.72);box-shadow:0 0 28px rgba(227,198,139,.2)}
  .btn.small{padding:8px 12px;border-radius:var(--r-xs);font-size:11px}
  .btn.danger{color:rgba(255,224,224,.9);border-color:rgba(209,75,75,.6);background:linear-gradient(135deg,rgba(61,18,20,.9),rgba(35,12,14,.9));box-shadow:0 0 24px rgba(209,75,75,.22)}
  .btn:focus-visible{outline:2px solid var(--accent-cyan);outline-offset:3px}
  .section-title{font:600 11px/1 'Inter','Noto Sans SC',sans-serif;text-transform:uppercase;letter-spacing:.24em;color:var(--text-muted);margin:16px 0 10px}
  .cat-list{display:flex;flex-direction:column;gap:10px}
  .cat{position:relative;display:flex;align-items:center;justify-content:space-between;gap:10px;padding:12px 14px;border-radius:var(--r-sm);background:linear-gradient(135deg,rgba(21,26,30,.9),rgba(15,19,22,.92));border:1px solid rgba(201,168,106,.24);box-shadow:inset 0 0 0 1px rgba(201,168,106,.08);transition:transform var(--t) var(--ease),border-color var(--t) var(--ease),box-shadow var(--t) var(--ease)}
  .cat::before{content:"";position:absolute;inset:4px;border-radius:calc(var(--r-sm)-4px);border:1px dashed rgba(201,168,106,.18);opacity:0;transition:opacity var(--t) var(--ease)}
  .cat:hover{transform:translateX(4px);border-color:rgba(227,198,139,.45);box-shadow:0 16px 36px rgba(0,0,0,.55)}
  .cat:hover::before{opacity:1}
  .cat.active{border-color:var(--gold-500);box-shadow:0 0 20px rgba(227,198,139,.24)}
  .cat .name{font:600 14px/1.4 'Inter','Noto Sans SC',sans-serif;color:var(--text-strong)}
  .cat .count{font:12px/1 'Inter','Noto Sans SC',sans-serif;color:var(--text-muted)}
  .footer{margin-top:24px;color:var(--text-muted);font:12px/1.8 'Inter','Noto Sans SC',sans-serif}
  .main{padding:28px 26px;background:linear-gradient(165deg,rgba(10,12,14,.72),rgba(6,8,10,.85));backdrop-filter:blur(14px);position:relative}
  .main::before{content:"";position:absolute;inset:0;border-left:1px solid rgba(201,168,106,.08);border-top:1px solid rgba(201,168,106,.08);pointer-events:none}
  .toolbar{display:flex;flex-wrap:wrap;gap:14px;align-items:center;margin-bottom:20px}
  .search{flex:1 1 260px;display:flex;align-items:center;gap:10px;padding:12px 16px;border-radius:var(--r-md);border:1px solid rgba(201,168,106,.26);background:linear-gradient(180deg,rgba(21,26,30,.9),rgba(15,19,22,.92));box-shadow:inset 0 0 26px rgba(201,168,106,.06)}
  .search input{flex:1;border:none;background:transparent;color:var(--text-strong);font:500 15px/1.5 'Inter','Noto Sans SC',sans-serif}
  .search input::placeholder{color:var(--text-muted)}
  .search button{padding:10px 14px;border-radius:var(--r-xs);border:1px solid rgba(201,168,106,.3);background:rgba(12,16,18,.65);color:var(--gold-400);font:600 11px/1 'Inter','Noto Sans SC',sans-serif;letter-spacing:.16em;text-transform:uppercase;cursor:pointer;transition:all var(--t) var(--ease)}
  .search button:hover{background:rgba(201,168,106,.18);box-shadow:0 0 18px rgba(227,198,139,.16)}
  .actions-row{display:flex;gap:10px;flex-wrap:wrap}
  .items{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:18px}
  .item{position:relative;padding:20px;border-radius:var(--r-lg);background:linear-gradient(180deg,rgba(21,26,30,.92),rgba(15,19,22,.94));border:1px solid rgba(201,168,106,.26);box-shadow:var(--shadow-1);display:flex;flex-direction:column;gap:14px;transition:transform var(--t) var(--ease),box-shadow var(--t) var(--ease),border-color var(--t) var(--ease)}
  .item::before{content:"";position:absolute;inset:10px;border-radius:calc(var(--r-lg)-6px);border:1px dashed rgba(201,168,106,.18);opacity:.6;pointer-events:none;animation:card-breathe 14s ease-in-out infinite}
  .item:hover{transform:translateY(-3px);box-shadow:0 0 26px rgba(227,198,139,.18),0 30px 70px rgba(0,0,0,.65);border-color:rgba(227,198,139,.4)}
  .item.done{background:linear-gradient(180deg,rgba(34,56,42,.92),rgba(17,29,24,.95));border-color:rgba(36,194,160,.45);box-shadow:0 0 26px rgba(36,194,160,.24)}
  .item.done .item-title,
  .item.done .item-desc,
  .item.done .tinyline{opacity:.75;text-decoration:line-through}
  .item-title{font:600 18px/1.4 'Cinzel','Noto Serif SC','Noto Sans SC',serif;color:var(--text-strong);letter-spacing:.08em}
  .item-desc{color:var(--text-dim);white-space:pre-wrap;line-height:1.7;max-height:140px;overflow:auto}
  .tinyline{position:relative;margin-left:14px;padding-left:18px;color:var(--text-muted);font:500 12px/1.6 'Inter','Noto Sans SC',sans-serif}
  .tinyline::before{content:"";position:absolute;left:6px;top:0;bottom:0;width:2px;background:linear-gradient(to bottom,rgba(201,168,106,.55),transparent);animation:timeline 8s linear infinite}
  .tlrow{display:flex;gap:8px;align-items:center;margin:6px 0}
  .dot{width:10px;height:10px;border-radius:50%;background:radial-gradient(circle at 30% 30%,rgba(227,198,139,.9),rgba(170,140,84,.8));box-shadow:0 0 14px rgba(227,198,139,.3)}
  .meta-inline{display:flex;gap:8px;flex-wrap:wrap}
  .badge{display:inline-flex;align-items:center;padding:4px 10px;border-radius:999px;border:1px dashed rgba(201,168,106,.28);background:rgba(201,168,106,.08);color:var(--text-muted);font:600 11px/1 'Inter','Noto Sans SC',sans-serif;letter-spacing:.16em;text-transform:uppercase}
  .item-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
  .item-actions .tip{margin-right:auto;color:var(--text-muted);font:600 11px/1 'Inter','Noto Sans SC',sans-serif;letter-spacing:.16em;text-transform:uppercase}
  .mob-move{display:none;gap:6px}
  .err{background:rgba(209,75,75,.18);color:rgba(255,224,224,.92);border:1px solid rgba(209,75,75,.45);border-radius:var(--r-md);padding:12px 14px;box-shadow:0 0 20px rgba(209,75,75,.18)}
  .shortcuts{margin-top:16px;color:var(--text-muted);font:600 11px/1.2 'Inter','Noto Sans SC',sans-serif;letter-spacing:.16em}
  .kbd{display:inline-flex;align-items:center;justify-content:center;padding:2px 6px;border-radius:var(--r-xs);border:1px dashed rgba(201,168,106,.28);background:rgba(12,16,18,.7);color:var(--gold-400);font:600 11px/1 'Inter','Noto Sans SC',sans-serif;letter-spacing:.12em}
  .modal-backdrop{position:fixed;inset:0;background:rgba(5,7,8,.75);backdrop-filter:blur(12px);display:none;align-items:center;justify-content:center;padding:20px;z-index:120}
  .modal-panel{background:linear-gradient(180deg,rgba(21,26,30,.92),rgba(15,19,22,.94));border:1px solid rgba(201,168,106,.3);border-radius:var(--r-lg);box-shadow:0 32px 64px rgba(0,0,0,.7);padding:22px;max-width:560px;width:100%;display:grid;gap:16px;position:relative}
  .modal-panel::before{content:"";position:absolute;inset:12px;border-radius:calc(var(--r-lg)-6px);border:1px dashed rgba(201,168,106,.2);opacity:.75;pointer-events:none}
  .modal-header{display:flex;justify-content:space-between;align-items:center;gap:12px}
  .modal-title{font:600 18px/1 'Cinzel','Noto Serif SC','Noto Sans SC',serif;color:var(--text-strong);letter-spacing:.14em;text-transform:uppercase}
  .modal-list{display:grid;gap:10px;max-height:320px;overflow:auto}
  .modal-form{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
  .modal-input{flex:1;min-width:200px;padding:10px 12px;border-radius:var(--r-sm);border:1px solid rgba(201,168,106,.3);background:rgba(12,16,18,.7);color:var(--text-strong);font:500 14px/1.4 'Inter','Noto Sans SC',sans-serif}
  .modal-input::placeholder{color:var(--text-muted)}
  .modal-count{color:var(--text-muted);font:600 12px/1 'Inter','Noto Sans SC',sans-serif;letter-spacing:.16em}
  @media (max-width:920px){
    .app{grid-template-columns:1fr}
    .sidebar{position:static;height:auto;border-right:none;border-bottom:1px solid rgba(201,168,106,.2);border-radius:0 0 var(--r-lg) var(--r-lg)}
    .cat-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
    .items{grid-template-columns:1fr}
    .item-actions .tip{display:none}
    .mob-move{display:inline-flex}
  }
  @keyframes scan{0%{transform:translateY(-100%)}100%{transform:translateY(100%)} }
  @keyframes card-breathe{0%,100%{opacity:.55}50%{opacity:1}}
  @keyframes timeline{to{background-position:0 160px}}
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
        <div class="name">全部 · All</div><div class="count"><?php echo $all_total; ?></div>
      </a>
      <?php foreach ($cats as $c): ?>
      <div class="cat <?php echo ($cat===(string)$c['id']?'active':''); ?>" data-id="<?php echo $c['id']; ?>">
        <a href="?cat=<?php echo $c['id']; ?>&q=<?php echo urlencode($q); ?>" style="flex:1" class="name"><?php echo h($c['name']); ?></a>
        <div class="count"><?php echo (int)($counts[$c['id']] ?? 0); ?></div>
      </div>
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
        <div class="item" style="grid-column:1/-1;text-align:center;color:var(--muted)">没有条目 · No items</div>
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
            <span class="tip" style="margin-right:auto;color:var(--text-muted)">拖动卡片可快速重排顺序</span>
            <span class="tip">⬍/↔ 拖拽排序</span>
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
  all.innerHTML='<div class="name">全部 · All</div><div class="count">'+(total??0)+'</div>';
  list.appendChild(all);
  cats.forEach(c=>{
    const div=document.createElement('div');
    div.className='cat'+(String(urlCat)===String(c.id)?' active':'');
    div.dataset.id=c.id;
    div.innerHTML=`<a href="?cat=${c.id}&q=${encodeURIComponent(qParam)}" style="flex:1" class="name">${escapeHtml(c.name)}</a><div class="count">${counts[c.id]||0}</div>`;
    list.appendChild(div);
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
