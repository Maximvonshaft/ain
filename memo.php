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
        'direction' => 'left',
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
        'direction' => 'left',
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
  $sanitize=function (&$node) use (&$sanitize,&$asset_refs,$map_id,$session_key){
    if(!is_array($node)) return;
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
      foreach($node['children'] as &$child){ $sanitize($child); }
      unset($child);
    }
  };
  $sanitize($payload['data']);
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
    <style>
      :root{ --bg:#f7f7fb; --surface:#fff; --border:#e5e7eb; --text:#0f172a; --muted:#64748b; --acc:#2563eb; --acc2:#60a5fa; --bad:#dc2626; }
      *{box-sizing:border-box} html,body{margin:0;background:var(--bg);color:var(--text);font:15px/1.65 system-ui,-apple-system,Segoe UI,Roboto,"PingFang SC","Noto Sans CJK SC","Microsoft YaHei",sans-serif}
      a{color:inherit;text-decoration:none}
      .wrap{max-width:1100px;margin:0 auto;padding:16px}
      .btn{padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:#fff;color:#0f172a;cursor:pointer}
      .btn.acc{background:linear-gradient(135deg,var(--acc),var(--acc2));border:0;color:#fff;font-weight:700}
      .card{background:#fff;border:1px solid var(--border);border-radius:14px;padding:14px}
      .row{display:grid;grid-template-columns:2fr 1fr auto;gap:10px;margin-bottom:10px}
      .row input,.row select{padding:10px;border:1px solid var(--border);border-radius:10px}
      .split{display:grid;grid-template-columns:minmax(280px,1fr) minmax(280px,1fr);gap:14px;align-items:start}
      @media (max-width:920px){ .row{grid-template-columns:1fr} .split{grid-template-columns:1fr} .btn{padding:12px} }
      .editbox,.preview{background:#fff;border:1px solid var(--border);border-radius:12px;padding:12px;min-height:240px}
      .preview{max-height:60vh;overflow:auto}
      .md-body img{max-width:100%;height:auto}
      .EasyMDEContainer .editor-toolbar{background:#ffffff;border-color:#e5e7eb}
      .EasyMDEContainer .editor-toolbar a{color:#334155}
      .EasyMDEContainer .editor-toolbar a.active,.EasyMDEContainer .editor-toolbar a:hover{background:#eff6ff}
      .EasyMDEContainer .CodeMirror{border:1px solid #e5e7eb;border-radius:10px;background:#fff;color:#0f172a;min-height:240px;max-height:60vh}
      .thumbs{display:flex;gap:10px;flex-wrap:wrap;margin-top:10px}
      .thumb{border:1px solid var(--border);border-radius:8px;overflow:hidden;background:#fff}
      .thumb img{display:block;max-width:200px;max-height:140px}
      .att-meta{color:var(--muted);font:12px/1 ui-monospace}
      .timeline{position:relative;margin-top:10px;margin-left:12px;padding-left:18px}
      .timeline::before{content:'';position:absolute;left:8px;top:0;bottom:0;width:2px;background:#e5e7eb}
      .tl-item{position:relative;margin:10px 0;padding:10px 10px 10px 12px;border:1px solid var(--border);border-radius:10px;background:#fff}
      .tl-item .tl-dot{position:absolute;left:-14px;top:16px;width:10px;height:10px;background:var(--acc);border-radius:50%;box-shadow:0 0 0 3px #fff}
      .tl-head{display:flex;gap:8px;align-items:center}
      .tl-item.done .tl-head div, .tl-item.done .md-body{ text-decoration:line-through; opacity:.75 }
      .drag{cursor:grab;color:#94a3b8;user-select:none}
      .ts{color:#64748b;font:12px/1 ui-monospace;margin-left:6px}
      details summary{cursor:pointer;color:#64748b}
      .save-tip{color:#16a34a;font-size:12px;display:none;margin-left:8px}
      .save-tip.show{display:inline}
    </style>
  </head>
  <body>
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
              <div class="md-body" id="md-view"><span style="color:#94a3b8">预览区域</span></div>
              <div class="thumbs" id="thumbs"></div>
            </div>
          </div>
        </form>
        <div style="display:flex;justify-content:space-between;align-items:center;margin:12px 0 6px">
          <div style="font-weight:800">流程子任务（时间轴）</div>
          <form id="add-step-form" onsubmit="return addStepAJAX(event)" style="display:flex;gap:6px;flex-wrap:wrap">
            <input id="new-step-title" placeholder="新增步骤 · Add step" style="flex:1;min-width:200px;padding:10px;border:1px solid var(--border);border-radius:8px">
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
            <div class="md-body" id="step-md-view-${s.id}">${s.notes?DOMPurify.sanitize(marked.parse(s.notes)):'<span style="color:#94a3b8">无备注</span>'}</div>\
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
    <style>
      :root{ --bg:#f7f7fb; --surface:#fff; --border:#e5e7eb; --text:#0f172a; --muted:#64748b; --acc:#2563eb; --acc2:#60a5fa; --bad:#dc2626; }
      *{box-sizing:border-box} html,body{margin:0;background:var(--bg);color:var(--text);font:15px/1.65 system-ui,-apple-system,Segoe UI,Roboto,"PingFang SC","Noto Sans CJK SC","Microsoft YaHei",sans-serif}
      a{color:inherit;text-decoration:none}
      .wrap{max-width:1100px;margin:0 auto;padding:16px}
      .btn{padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:#fff;color:#0f172a;cursor:pointer}
      .btn.acc{background:linear-gradient(135deg,var(--acc),var(--acc2));border:0;color:#fff;font-weight:700}
      .btn.danger{border-color:var(--bad);color:var(--bad)}
      .card{background:#fff;border:1px solid var(--border);border-radius:14px;padding:14px}
      .title{font-weight:900;font-size:22px;margin:0 0 6px;word-break:break-word}
      .meta{color:var(--muted);font:13px/1.4 ui-monospace,Menlo}
      .split{display:grid;grid-template-columns:minmax(280px,1fr) minmax(280px,1fr);gap:14px;align-items:start}
      @media (max-width:920px){ .split{grid-template-columns:1fr} .btn{padding:12px} }
      .editbox,.preview{background:#fff;border:1px solid var(--border);border-radius:12px;padding:12px;min-height:240px}
      .preview{max-height:60vh;overflow:auto}
      .md-body img{max宽继续
      .md-body img{max-width:100%;height:auto}
      .EasyMDEContainer .editor-toolbar{background:#ffffff;border-color:#e5e7eb}
      .EasyMDEContainer .editor-toolbar a{color:#334155}
      .EasyMDEContainer .editor-toolbar a.active,.EasyMDEContainer .editor-toolbar a:hover{background:#eff6ff}
      .EasyMDEContainer .CodeMirror{border:1px solid #e5e7eb;border-radius:10px;background:#fff;color:#0f172a;min-height:240px;max-height:60vh}
      .thumbs{display:flex;gap:10px;flex-wrap:wrap;margin-top:10px}
      .thumb{border:1px solid var(--border);border-radius:8px;overflow:hidden;background:#fff}
      .thumb img{display:block;max-width:200px;max-height:140px}
      .att-meta{color:#64748b;font:12px/1 ui-monospace}
      .timeline{position:relative;margin-top:10px;margin-left:12px;padding-left:18px}
      .timeline::before{content:'';position:absolute;left:8px;top:0;bottom:0;width:2px;background:#e5e7eb}
      .tl-item{position:relative;margin:10px 0;padding:10px 10px 10px 12px;border:1px solid var(--border);border-radius:10px;background:#fff}
      .tl-item .tl-dot{position:absolute;left:-14px;top:16px;width:10px;height:10px;background:#2563eb;border-radius:50%}
      .tl-head{display:flex;gap:8px;align-items:center}
      .tl-item.done .tl-head div, .tl-item.done .md-body{ text-decoration:line-through; opacity:.75 }
      .drag{cursor:grab;color:#94a3b8;user-select:none}
      .ts{color:#64748b;font:12px/1 ui-monospace;margin-left:6px}
      .badge{display:inline-block;font:12px/1 ui-monospace,Menlo;padding:4px 6px;border-radius:999px;border:1px solid var(--border);background:#fff;color:#64748b}
      .save-tip{color:#16a34a;font-size:12px;display:none;margin-left:8px}
      .save-tip.show{display:inline}
      .done-view .title, .done-view #md-view, .done-view .timeline { text-decoration:line-through; opacity:.8 }
    </style>
  </head>
  <body>
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
              <div style="display:grid;grid-template-columns:2fr 1fr auto;gap:10px;margin-bottom:10px;align-items:center">
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
              <div style="display:flex;gap:10px;justify-content:space-between;align-items:center;margin-top:10px;flex-wrap:wrap">
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
            <input id="new-step-title" name="title" placeholder="新增步骤 · Add step" style="padding:10px;border:1px solid var(--border);border-radius:8px;flex:1;min-width:200px">
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
  $initialDataDecoded = json_decode($initialPayload, true);
  if (!is_array($initialDataDecoded) || empty($initialDataDecoded['data'])) {
    $initialDataDecoded = $defaultData;
  }
  ?>
  <!doctype html>
  <html lang="zh-Hans">
  <head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <title>思维导图编辑器 · <?php echo h($mind['title']); ?></title>
    <style>
      :root{ --bg:#0f172a; --surface:#ffffff; --border:#e2e8f0; --muted:#64748b; --text:#0f172a; --acc:#2563eb; --acc2:#60a5fa; --ok:#16a34a; --bad:#dc2626; }
      *{box-sizing:border-box}
      html,body{margin:0;padding:0;background:var(--bg);color:var(--text);font:15px/1.6 "Inter","Segoe UI","PingFang SC","Microsoft YaHei",sans-serif;min-height:100vh}
      a{color:inherit;text-decoration:none}
      .layout{min-height:100vh;display:grid;grid-template-columns:320px 1fr}
      @media (max-width:1100px){ .layout{grid-template-columns:1fr} .sidebar{position:static;height:auto} }
      .sidebar{background:#f8fafc;border-right:1px solid var(--border);padding:18px;display:flex;flex-direction:column;gap:16px;position:sticky;top:0;height:100vh;overflow:auto}
      .sidebar h1{margin:0;font-size:20px;font-weight:800;color:#0f172a}
      .sidebar .meta{color:var(--muted);font:12px/1.4 ui-monospace}
      .sidebar label{font-weight:700;color:#0f172a;font-size:13px}
      .sidebar input[type="text"]{padding:10px;border:1px solid var(--border);border-radius:10px;font-size:15px;width:100%;background:#fff}
      .toolbar{display:grid;gap:10px}
      .toolbar button{padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:#fff;color:#0f172a;cursor:pointer;display:flex;gap:6px;align-items:center;justify-content:center;font-size:13px;font-weight:600}
      .toolbar button.acc{background:linear-gradient(135deg,var(--acc),var(--acc2));color:#fff;border:0}
      .toolbar button.danger{color:var(--bad);border-color:#fecaca;background:#fff0f0}
      .toolbar button:disabled{opacity:.5;cursor:not-allowed}
      .actions{display:flex;flex-wrap:wrap;gap:8px}
      .tips{background:#e0f2fe;border:1px solid #bae6fd;color:#0c4a6e;padding:12px;border-radius:12px;font-size:13px;line-height:1.6}
      .tips strong{font-weight:700}
      .tips code{background:rgba(15,23,42,.08);padding:2px 5px;border-radius:6px;font-size:12px}
      .editor-pane{background:#0f172a;position:relative}
      #jsmind-container{position:relative;width:100%;height:100vh;overflow:hidden;background:radial-gradient(circle at top,#1f2a40 0%,#111b2f 60%,#0b1223 100%);}
      .map-toolbar{position:absolute;top:16px;right:16px;display:flex;gap:8px;flex-wrap:wrap}
      .map-toolbar button{padding:8px 10px;border-radius:10px;border:0;background:rgba(15,23,42,.65);color:#fff;font-size:12px;cursor:pointer}
      .map-error{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;color:#e2e8f0;text-align:center;padding:40px 24px;font-size:16px;gap:8px}
      .map-error strong{font-size:20px;color:#f8fafc}
      .mind-viewport{position:absolute;inset:0;transform-origin:0 0;will-change:transform;}
      .mind-links{position:absolute;inset:0;width:100%;height:100%;pointer-events:none;transform-origin:0 0;}
      .mind-links path{fill:none;stroke:rgba(148,163,184,.65);stroke-width:2;stroke-linecap:round}
      .mind-nodes{position:absolute;inset:0}
      .jsmind-node{position:absolute;display:inline-flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;padding:10px 14px;border-radius:14px;background:#f8fafc;color:#0f172a;box-shadow:0 18px 32px rgba(15,23,42,.2);border:1px solid rgba(148,163,184,.35);min-width:140px;max-width:240px;text-align:center;font-weight:700;font-size:14px;line-height:1.4;cursor:pointer;transition:transform .15s ease,box-shadow .15s ease,border-color .15s ease}
      .jsmind-node:hover{transform:translateY(-2px);box-shadow:0 22px 38px rgba(37,99,235,.22)}
      .jsmind-node.selected{border:2px solid #2563eb;box-shadow:0 30px 48px rgba(37,99,235,.3)}
      .jsmind-node[data-direction="left"]{text-align:right;align-items:flex-end}
      .jsmind-node[data-direction="right"]{align-items:flex-start}
      .jsmind-node.editing{border:2px solid #60a5fa;box-shadow:0 26px 48px rgba(59,130,246,.35);background:#f8fafc;color:#0f172a;cursor:text}
      .jsmind-node .node-topic{display:block;word-break:break-word;white-space:pre-wrap;cursor:inherit}
      .jsmind-node .node-topic[contenteditable="true"]{outline:none;cursor:text}
      .jsmind-node .node-affordances{display:flex;gap:6px;flex-wrap:wrap;justify-content:center}
      .jsmind-node .node-badge{display:inline-flex;align-items:center;gap:4px;padding:4px 8px;border-radius:999px;font-weight:600;font-size:12px;line-height:1.2;background:rgba(37,99,235,.08);color:#1d4ed8;border:0;cursor:pointer;transition:background .15s ease,color .15s ease;white-space:nowrap}
      .jsmind-node .node-badge:focus-visible{outline:2px solid #1d4ed8;outline-offset:2px}
      .jsmind-node .node-badge:hover{background:rgba(37,99,235,.14);color:#1d4ed8}
      .jsmind-node .node-badge.link{background:rgba(16,185,129,.12);color:#047857}
      .jsmind-node .node-badge.link:hover{background:rgba(16,185,129,.18);color:#0f766e}
      .jsmind-node .node-badge:disabled{opacity:.55;cursor:not-allowed}
      .mind-background{position:absolute;inset:-400px;background:radial-gradient(circle,#172038 0%,rgba(15,23,42,0) 70%);pointer-events:none}
      .badge{display:inline-block;padding:3px 8px;border-radius:999px;background:#e2e8f0;color:#475569;font:12px/1 ui-monospace}
      .save-tip{font-size:12px;color:var(--ok);display:none}
      .save-tip.show{display:inline}
      .save-tip.dirty{color:#f97316}
      #jsmind-container.dragover{outline:2px dashed rgba(96,165,250,.85)}
      #node-handle{position:absolute;width:28px;height:28px;border-radius:999px;background:linear-gradient(135deg,var(--acc),var(--acc2));color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:18px;box-shadow:0 10px 20px rgba(37,99,235,.35);pointer-events:auto;opacity:0;transform:scale(.8);transition:opacity .15s ease, transform .15s ease;z-index:50;touch-action:none}
      #node-handle.show{opacity:1;transform:scale(1);pointer-events:auto}
      #node-handle.dragging{opacity:.85}
      #drag-overlay{position:absolute;inset:0;pointer-events:none}
      #drag-overlay line{stroke:rgba(148,163,184,.7);stroke-width:2;stroke-dasharray:6 6;stroke-linecap:round}
      #drag-overlay circle{fill:rgba(96,165,250,.3);stroke:rgba(59,130,246,.9);stroke-width:2}
      .mobile-toolbar{display:none}
      @media (max-width:1100px){
        .mobile-toolbar{position:fixed;left:16px;right:16px;bottom:16px;display:grid;grid-template-columns:repeat(auto-fit,minmax(0,1fr));gap:10px;background:rgba(15,23,42,.82);backdrop-filter:blur(12px);padding:10px;border-radius:16px;z-index:60;box-shadow:0 18px 38px rgba(15,23,42,.35)}
        .mobile-toolbar button{padding:12px 0;border:0;border-radius:12px;background:rgba(255,255,255,.12);color:#f8fafc;font-size:13px;font-weight:600;letter-spacing:.02em;cursor:pointer}
        .mobile-toolbar button.danger{background:rgba(248,113,113,.25);color:#fee2e2}
        .jsmind-node{min-width:120px;max-width:200px;font-size:13px;padding:9px 12px}
      }
      @media (max-width:700px){
        .jsmind-node{min-width:110px;max-width:180px;padding:8px 10px;font-size:12px}
      }
    </style>
  </head>
  <body>
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
          可从外部拖入图片、PDF、ZIP、文本或视频至画布，系统会引导选择创建同级或子级节点。
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
            <button style="width:100%;padding:10px 12px;border-radius:10px;border:1px solid #fecaca;background:#fff0f0;color:var(--bad);font-weight:700;cursor:pointer">删除导图</button>
          </form>
        <?php endif; ?>
      </aside>
      <main class="editor-pane">
        <div id="jsmind-container" data-map-id="<?php echo $mind['id']; ?>"></div>
        <div class="map-toolbar">
          <button id="btn-collapse">折叠/展开节点</button>
          <button id="btn-fit-floating">自适应视图</button>
        </div>
        <div class="mobile-toolbar" id="mobile-toolbar">
          <button data-action="add-sibling">同级</button>
          <button data-action="add-child">子级</button>
          <button data-action="attach-file">附件</button>
          <button data-action="attach-link">链接</button>
          <button data-action="delete" class="danger">删除</button>
        </div>
      </main>
    </div>
    <script>
      (function(){
      const DOUBLE_TAP_WINDOW=320;
      const isCompactViewport=()=>window.matchMedia('(max-width: 900px)').matches;
      let lastTapInfo={id:null,time:0};
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
          this.linkLayer=document.createElementNS('http://www.w3.org/2000/svg','svg');
          this.linkLayer.classList.add('mind-links');
          this.linkLayer.setAttribute('width','100%');
          this.linkLayer.setAttribute('height','100%');
          this.container.appendChild(this.linkLayer);
          this.viewport=document.createElement('div');
          this.viewport.className='mind-viewport';
          this.nodeLayer=document.createElement('div');
          this.nodeLayer.className='mind-nodes';
          this.viewport.appendChild(this.nodeLayer);
          this.container.appendChild(this.viewport);
          this.dragState=null;
          this.setupPan();
        }
        setupPan(){
          const startPan=(evt)=>{
            if(evt.button!==0 && evt.pointerType!=='touch') return;
            if(evt.target.closest('.jsmind-node')) return;
            this.dragState={pointerId:evt.pointerId,startX:evt.clientX,startY:evt.clientY,baseX:this.offsetX,baseY:this.offsetY};
            this.container.setPointerCapture(evt.pointerId);
          };
          const movePan=(evt)=>{
            if(!this.dragState || evt.pointerId!==this.dragState.pointerId) return;
            const dx=evt.clientX-this.dragState.startX;
            const dy=evt.clientY-this.dragState.startY;
            this.offsetX=this.dragState.baseX+dx;
            this.offsetY=this.dragState.baseY+dy;
            this.applyTransform();
          };
          const endPan=(evt)=>{
            if(!this.dragState || evt.pointerId!==this.dragState.pointerId) return;
            this.dragState=null;
            try{ this.container.releasePointerCapture(evt.pointerId); }catch(_){ }
          };
          this.container.addEventListener('pointerdown',startPan);
          this.container.addEventListener('pointermove',movePan);
          this.container.addEventListener('pointerup',endPan);
          this.container.addEventListener('pointercancel',endPan);
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
            const node={
              id:item.id || ('node-'+Math.random().toString(36).slice(2,9)),
              topic:item.topic || '',
              data:item.data || null,
              parent:parent,
              children:[],
              direction:item.direction || (parent?parent.direction:'right'),
              expanded:item.expanded!==false,
              isroot:!parent,
              style:item.style || null,
              meta:item.meta || null,
              model:item,
            };
            if(node.isroot){ node.direction='center'; }
            this.nodes.set(node.id,node);
            if(parent){ parent.children.push(node); node.model.parentId=parent.id; }
            else this.root=node;
            if(Array.isArray(item.children)){
              node.children=item.children.map(child=>build(child,node));
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
          const model={id:newId,topic:topic||'新节点',data:data||null,children:[]};
          const children=this.ensureModelChildren(parent);
          children.push(model);
          const node={
            id:newId,
            topic:model.topic,
            data:model.data,
            parent:parent,
            children:[],
            direction:model.direction || parent.direction,
            expanded:true,
            isroot:false,
            style:model.style || null,
            meta:model.meta || null,
            model:model,
          };
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
        computeLayout(){
          if(!this.root) return;
          const H_SPACING=200;
          const V_SPACING=120;
          const NODE_HEIGHT=80;
          const measure=(node)=>{
            if(!node) return NODE_HEIGHT;
            if(!node.expanded || !node.children.length) return NODE_HEIGHT;
            let total=0;
            const visible=node.children;
            for(let i=0;i<visible.length;i++){
              total+=measure(visible[i]);
              if(i<visible.length-1) total+=V_SPACING;
            }
            return Math.max(NODE_HEIGHT,total);
          };
          const left=this.root.children.filter(child=>(child.direction||'right')==='left');
          const right=this.root.children.filter(child=>child.direction==='right'||child.direction==='center'||child.direction===undefined||child.direction===null||child.direction==='');
          const leftHeight=left.length?left.reduce((sum,child)=>sum+measure(child),0)+V_SPACING*(left.length-1):0;
          const rightHeight=right.length?right.reduce((sum,child)=>sum+measure(child),0)+V_SPACING*(right.length-1):0;
          this.root.x=0;
          this.root.y=(Math.max(NODE_HEIGHT, leftHeight, rightHeight))/2;
          const assign=(node,depth,dir,startTop)=>{
            const height=measure(node);
            node.x=(dir===0?0:dir*depth*H_SPACING);
            node.y=startTop+height/2;
            node.dir=dir;
            if(!node.expanded || !node.children.length) return height;
            let top=startTop;
            const children=node.children;
            for(const child of children){
              const childDir = child.direction==='left'? -1 : (child.direction==='right'? 1 : (dir===0?1:dir));
              const childHeight=assign(child, depth+1, childDir, top);
              top+=childHeight+V_SPACING;
            }
            return height;
          };
          let leftTop=this.root.y - leftHeight/2;
          for(const child of left){
            const h=assign(child,1,-1,leftTop);
            leftTop+=h+V_SPACING;
          }
          let rightTop=this.root.y - rightHeight/2;
          for(const child of right){
            const h=assign(child,1,1,rightTop);
            rightTop+=h+V_SPACING;
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
          this.nodeLayer.style.width=`${this.bounds.width}px`;
          this.nodeLayer.style.height=`${this.bounds.height}px`;
        }
        render(){
          this.nodeLayer.innerHTML='';
          while(this.linkLayer.firstChild){ this.linkLayer.removeChild(this.linkLayer.firstChild); }
          if(!this.root) return;
          const createNodeEl=(node)=>{
            const el=document.createElement('div');
            el.className='jsmind-node';
            if(node.selected){ el.classList.add('selected'); }
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
            const badges=[];
            if(data.attachment){
              const badge=document.createElement('button');
              badge.type='button';
              badge.className='node-badge attachment';
              const label=attachmentLabel(data.attachment);
              badge.textContent='📎 '+label;
              badge.title=`打开附件：${label}`;
              badge.addEventListener('click',evt=>{ evt.preventDefault(); evt.stopPropagation(); openMindmapAttachment(data.attachment); });
              badges.push(badge);
            }
            if(data.url){
              const badge=document.createElement('button');
              badge.type='button';
              badge.className='node-badge link';
              badge.textContent='🔗 打开链接';
              badge.title='打开链接';
              badge.addEventListener('click',evt=>{ evt.preventDefault(); evt.stopPropagation(); openMindmapLink(data.url); });
              badges.push(badge);
            }
            if(badges.length){
              const wrap=document.createElement('div');
              wrap.className='node-affordances';
              badges.forEach(btn=>wrap.appendChild(btn));
              el.appendChild(wrap);
            }
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
            return el;
          };
          const walk=(node)=>{
            node.el=createNodeEl(node);
            if(node.style){
              if(node.style.background){ node.el.style.background=node.style.background; }
              if(node.style.foreground){ node.el.style.color=node.style.foreground; }
            }
            this.nodeLayer.appendChild(node.el);
            node.width=node.el.offsetWidth;
            node.height=node.el.offsetHeight;
            node.el.style.left=`${node.absX - node.width/2}px`;
            node.el.style.top=`${node.absY - node.height/2}px`;
            if(node.parent){
              const path=document.createElementNS('http://www.w3.org/2000/svg','path');
              const parentLeft=node.parent.absX - node.parent.width/2;
              const parentRight=node.parent.absX + node.parent.width/2;
              const childLeft=node.absX - node.width/2;
              const childRight=node.absX + node.width/2;
              const orientation=(node.dir===-1 || node.direction==='left') ? 'left' : ((node.dir===1 || node.direction==='right') ? 'right' : (childRight<=node.parent.absX ? 'left' : 'right'));
              const isLeftSide=orientation==='left';
              const startX=isLeftSide?parentLeft:parentRight;
              const startY=node.parent.absY;
              const endX=isLeftSide?childRight:childLeft;
              const endY=node.absY;
              const offset=Math.abs(endX-startX)*0.35;
              const midX=startX + (isLeftSide?-offset:offset);
              path.setAttribute('d',`M${startX} ${startY} C ${midX} ${startY} ${midX} ${endY} ${endX} ${endY}`);
              this.linkLayer.appendChild(path);
            }
            if(node.expanded){
              node.children.forEach(child=>walk(child));
            }
          };
          walk(this.root);
          this.applyTransform(true);
        }
        applyTransform(initial){
          if(!this.bounds){ return; }
          if(initial && !this.hasCentered){ this.center_root(); this.hasCentered=true; return; }
          const transform=`translate(${this.offsetX}px, ${this.offsetY}px) scale(${this.scale})`;
          this.viewport.style.transform=transform;
          this.linkLayer.style.transform=transform;
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
        if(!descriptor) return '附件';
        if(descriptor.name) return descriptor.name;
        if(descriptor.assetId) return `附件 #${descriptor.assetId}`;
        return '附件';
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
      document.body.appendChild(dragHandle);
      let handleSource=null;
      let pointerDragState=null;
      function hideHandle(){ dragHandle.classList.remove('show','dragging'); handleSource=null; hideGhost(); pointerDragState=null; }
      function updateHandlePosition(){
        const node=jm.get_selected_node();
        if(!node){ hideHandle(); return; }
        const editingId=currentEditingId();
        if(editingId && editingId===node.id){ hideHandle(); return; }
        const el=document.querySelector(`.jsmind-node[nodeid="${node.id}"]`);
        if(!el){ hideHandle(); return; }
        const rect=el.getBoundingClientRect();
        dragHandle.style.left=`${rect.right + 8 + window.scrollX}px`;
        dragHandle.style.top=`${rect.top + rect.height/2 - 14 + window.scrollY}px`;
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
      const mobileToolbar=document.getElementById('mobile-toolbar');
      const saveButton=document.getElementById('btn-save');
      const fitButton=document.getElementById('btn-fit');
      const fitFloatingButton=document.getElementById('btn-fit-floating');
      const addSiblingButton=document.getElementById('btn-add-sibling');
      const addChildButton=document.getElementById('btn-add-child');
      const deleteButton=document.getElementById('btn-delete');
      let saveButtonDefault=saveButton ? saveButton.textContent : '保存';
      if(saveButton){ saveButton.dataset.defaultLabel=saveButtonDefault; }
      let dirty=false;
      const commandLog=[];
      window.__mindmapCommands=commandLog;
      const ATTACH_MAX_BYTES=15*1024*1024;
      const imageExts=['.png','.jpg','.jpeg','.gif','.webp','.bmp','.svg','.avif','.heic','.heif'];
      const textExts=['.txt','.md','.markdown','.csv','.json','.yaml','.yml','.log'];
      const videoExts=['.mp4','.mov','.mkv','.avi','.webm','.m4v'];
      function setSaveButtonState(text, disabled){
        if(!saveButton) return;
        if(typeof text==='string'){ saveButton.textContent=text; }
        if(typeof disabled==='boolean'){ saveButton.disabled=disabled; }
      }
      function markDirty(){
        dirty=true;
        if(saveState){
          saveState.textContent='未保存';
          saveState.classList.add('show','dirty');
        }
        setSaveButtonState(saveButtonDefault,false);
      }
      function showSaving(){
        if(saveState){
          saveState.textContent='保存中...';
          saveState.classList.add('show');
          saveState.classList.remove('dirty');
        }
        setSaveButtonState('⏳ 保存中...', true);
      }
      function markSaved(){
        dirty=false;
        if(saveState){
          saveState.textContent='保存成功';
          saveState.classList.add('show');
          saveState.classList.remove('dirty');
        }
        setSaveButtonState('✅ 保存成功', false);
        setTimeout(()=>{
          if(!dirty){
            if(saveState) saveState.classList.remove('show');
            setSaveButtonState(saveButtonDefault,false);
          }
        },1500);
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
      function executeCreateNodeCommand(input){
        commitInlineEditing();
        if(!input || !input.parentId) return null;
        const parent=jm.get_node(input.parentId);
        if(!parent) return null;
        const nodeId=input.id || randomId();
        const payloadData=deepClone(input.data);
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
        return newNode;
      }
      function randomId(){ return 'node-' + Math.random().toString(36).slice(2,10); }
      function isProbablyUrl(text){
        const value=(text||'').trim();
        return /^https?:\/\//i.test(value) || /^mailto:/i.test(value) || /^ftp:/i.test(value) || /^www\./i.test(value);
      }
      function findNodeElementByEvent(event){
        if(!event || !event.target) return null;
        return event.target.closest ? event.target.closest('.jsmind-node') : null;
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
      async function createAttachmentNodes(parentNode, files, basePoint, placement){
        if(!parentNode || !files || !files.length) return;
        for(let index=0; index<files.length; index++){
          const file=files[index];
          const nodeId=randomId();
          try{
            const uploaded=await uploadMindmapFile(file, nodeId);
            const data={
              attachment:{
                assetId:uploaded.id,
                name:uploaded.name || file.name,
                size:uploaded.size ?? file.size,
                mime:uploaded.mime || file.type || 'application/octet-stream',
                url:uploaded.url,
              }
            };
            const offset=basePoint ? {x:basePoint.x + index*18, y:basePoint.y + index*18} : null;
            executeCreateNodeCommand({
              id:nodeId,
              parentId:parentNode.id,
              topic:'📎 '+(uploaded.name || file.name),
              data:data,
              position:offset,
              meta:{source:'file', placement:placement||'child'}
            });
          }catch(err){
            console.error(err);
            alert((file.name||'文件')+' 上传失败：'+(err && err.message ? err.message : err));
          }
        }
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
      function handleDroppedFiles(files, parent, event){
        if(!files || !files.length || !parent) return;
        commitInlineEditing();
        const accepted=sanitizeAttachmentFiles(files);
        if(!accepted.length) return;
        let createAsChild=true;
        if(!parent.isroot){
          createAsChild=confirm('将附件创建为子级节点？点击“确定”为子级，“取消”为同级。');
          if(!createAsChild && !parent.parent){
            alert('无法为根节点创建同级节点，已改为子级。');
            createAsChild=true;
          }
        }
        const target=(createAsChild || !parent.parent) ? parent : parent.parent;
        const basePoint=event ? eventToSvgPoint(event) : null;
        createAttachmentNodes(target, accepted, basePoint, createAsChild?'child':'sibling');
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
        executeCreateNodeCommand({
          parentId:node.id,
          topic:(title||'').trim()||cleaned,
          data:{url:cleaned},
          meta:{source:'link'}
        });
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
          const accepted=sanitizeAttachmentFiles(files);
          if(!accepted.length) return;
          await createAttachmentNodes(node, accepted, null, 'child');
        });
      }
      if(mobileToolbar){
        mobileToolbar.addEventListener('click',e=>{
          const btn=e.target.closest('button');
          if(!btn) return;
          switch(btn.dataset.action){
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
      document.getElementById('btn-collapse').onclick=()=>{
        const node=ensureNode(); if(node){ jm.toggle_node(node.id); markDirty(); requestAnimationFrame(updateHandlePosition); }
      };
      jmContainer.addEventListener('dragenter',e=>{
        e.preventDefault();
        jmContainer.classList.add('dragover');
      });
      jmContainer.addEventListener('dragleave',e=>{
        if(!jmContainer.contains(e.relatedTarget)){ jmContainer.classList.remove('dragover'); }
      });
      jmContainer.addEventListener('dragover',e=>{
        e.preventDefault();
        e.dataTransfer.dropEffect='copy';
      });
      jmContainer.addEventListener('drop',e=>{
        e.preventDefault();
        jmContainer.classList.remove('dragover');
        syncOverlaySize();
        const parent=resolveDropParent(e);
        const files=e.dataTransfer.files && e.dataTransfer.files.length ? Array.from(e.dataTransfer.files) : [];
        if(files.length){
          handleDroppedFiles(files, parent, e);
          return;
        }
        const uri=e.dataTransfer.getData('text/uri-list');
        let text='';
        if(uri){ text=uri; } else { text=e.dataTransfer.getData('text/plain') || ''; }
        if(text){
          handleDroppedText(text, parent, e);
        }
      });
      titleInput.addEventListener('input', markDirty);
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
          }
          if(type===jsMind.event_type.edit || type===jsMind.event_type.after_edit || type===jsMind.event_type.update){ markDirty(); }
        });
      }
      document.getElementById('btn-export-json').onclick=()=>{
        const data=jm.get_data('node_tree');
        const blob=new Blob([JSON.stringify(data,null,2)],{type:'application/json'});
        const url=URL.createObjectURL(blob);
        const a=document.createElement('a');
        a.href=url;
        a.download=(titleInput.value.trim()||'mindmap')+'.json';
        a.click();
        setTimeout(()=>URL.revokeObjectURL(url), 1000);
      };
      document.getElementById('btn-import-json').onclick=()=>importInput.click();
      importInput.addEventListener('change', e=>{
        const file=e.target.files[0]; if(!file) return;
        const reader=new FileReader();
        reader.onload=evt=>{
          try{
            const json=JSON.parse(evt.target.result);
            if(json && json.data){
              commitInlineEditing();
              jm.show(json);
              initialData=JSON.parse(JSON.stringify(json));
              markDirty();
            }
            else alert('文件格式不兼容');
          }catch(err){ alert('无法解析 JSON：'+err.message); }
        };
        reader.readAsText(file,'utf-8');
      });
      if(saveButton) saveButton.onclick=saveMindmap;
      async function saveMindmap(){
        commitInlineEditing();
        const title=titleInput.value.trim()||'未命名导图';
        const payload=JSON.stringify(jm.get_data('node_tree'));
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
    <style>
      :root{ --bg:#f7f8fc; --surface:#ffffff; --border:#e2e8f0; --muted:#64748b; --text:#0f172a; --acc:#2563eb; --acc2:#60a5fa; --bad:#dc2626; }
      *{box-sizing:border-box}
      html,body{margin:0;background:var(--bg);color:var(--text);font:15px/1.6 "Inter","Segoe UI","PingFang SC","Microsoft YaHei",sans-serif}
      a{color:inherit;text-decoration:none}
      .wrap{max-width:1180px;margin:0 auto;padding:20px 16px 60px}
      .header{display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap}
      .header h1{margin:0;font-size:24px;font-weight:800}
      .btn{padding:10px 14px;border:1px solid var(--border);border-radius:12px;background:#fff;color:#0f172a;cursor:pointer;text-decoration:none;font-weight:600}
      .btn.acc{background:linear-gradient(135deg,var(--acc),var(--acc2));color:#fff;border:0}
      .btn.danger{color:var(--bad);border-color:#fecaca;background:#fff0f0}
      .grid{margin-top:20px;display:grid;gap:16px;grid-template-columns:repeat(auto-fill,minmax(280px,1fr))}
      .card{background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:16px;display:flex;flex-direction:column;gap:12px;min-height:240px;box-shadow:0 8px 16px rgba(15,23,42,.05)}
      .card h2{margin:0;font-size:18px;font-weight:700;line-height:1.4}
      .meta{color:var(--muted);font:12px/1.4 ui-monospace}
      pre{background:#f8fafc;border:1px dashed #dbeafe;padding:10px;border-radius:12px;max-height:140px;overflow:auto;font:12px/1.5 ui-monospace;color:#0f172a}
      .card-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:auto}
      .search{margin-top:18px;display:flex;gap:8px;align-items:center;padding:8px 10px;border:1px solid var(--border);border-radius:12px;background:#fff;max-width:420px}
      .search input{all:unset;flex:1;font-size:14px}
      .empty{margin-top:40px;padding:40px;border:2px dashed #dbeafe;border-radius:16px;text-align:center;color:#64748b}
    </style>
  </head>
  <body>
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
        <span style="font-weight:700;color:#0f172a">🔍</span>
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
<meta name="color-scheme" content="light"/>
<style>
:root{ --bg:#f7f7fb; --surface:#fff; --card:#fff; --muted:#64748b; --text:#0f172a; --acc:#2563eb; --acc2:#60a5fa; --border:#e5e7eb; --ok:#16a34a; --bad:#dc2626; }
*{box-sizing:border-box} html,body{margin:0;background:var(--bg);color:var(--text);font:15px/1.6 system-ui,-apple-system,Segoe UI,Roboto,"PingFang SC","Noto Sans CJK SC","Microsoft YaHei",sans-serif}
a{color:inherit;text-decoration:none}
.app{display:grid;grid-template-columns:280px 1fr;min-height:100vh}
.sidebar{border-right:1px solid var(--border);background:var(--surface);padding:16px;position:sticky;top:0;align-self:start;height:100vh;overflow:auto}
.brand{display:flex;gap:10px;align-items:center;margin-bottom:12px}
.brand .logo{width:26px;height:26px;border-radius:6px;background:linear-gradient(135deg,var(--acc),var(--acc2));box-shadow:0 0 0 2px rgba(0,0,0,.03) inset}
.brand h1{font-size:16px;margin:0;font-weight:800}
.controls{display:flex;gap:8px;flex-wrap:wrap;margin:8px 0 12px}
.btn{padding:10px 12px;border:1px solid var(--border);background:var(--card);border-radius:12px;color:#0f172a;cursor:pointer;text-decoration:none;display:inline-block}
.btn.small{padding:8px 10px;border-radius:10px;font-size:12px}
.btn.acc{background:linear-gradient(135deg,var(--acc),var(--acc2));border:0;color:#fff;font-weight:800}
.btn.danger{border-color:var(--bad);color:var(--bad)}
.section-title{font-size:12px;letter-spacing:.12em;color:var(--muted);margin:12px 0 6px}
.cat-list{display:flex;flex-direction:column;gap:6px}
.cat{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:10px 12px;border:1px solid var(--border);border-radius:12px;cursor:pointer;background:var(--card)}
.cat.active{outline:2px solid #bfdbfe}
.cat .name{font-weight:600}
.cat .count{font:12px/1 ui-monospace,Menlo,Consolas;color:var(--muted)}
.footer{margin-top:16px;color:var(--muted);font-size:12px}
.main{padding:16px}
.toolbar{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-bottom:12px}
.search{flex:1 1 260px;display:flex;align-items:center;gap:8px;background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:8px 10px}
.search input{all:unset;flex:1;color:var(--text)}
.search button{padding:8px 10px;border:1px solid var(--border);border-radius:10px;background:var(--card);color:#0f172a;cursor:pointer}
.actions-row{display:flex;gap:8px;flex-wrap:wrap}
.items{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px}
.item{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:12px;display:grid;gap:8px}
.item[draggable="true"]{cursor:grab}
.item-title{font-weight:800;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;word-break:break-word}
.item.done .item-title, .item.done .item-desc, .item.done .tinyline { text-decoration:line-through }
.item-desc{color:var(--muted);white-space:pre-wrap;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;word-break:break-word}
.badge{display:inline-block;font:12px/1 ui-monospace,Menlo;padding:4px 6px;border-radius:999px;border:1px solid var(--border);background:var(--card);color:var(--muted)}
.kbd{font:12px/1 ui-monospace,Menlo;padding:2px 6px;border:1px solid var(--border);border-radius:6px;background:#fff;color:#muted}
.tinyline{position:relative;margin-left:10px;padding-left:14px}
.tinyline::before{content:'';position:absolute;left:6px;top:0;bottom:0;width:2px;background:#e5e7eb}
.tlrow{position:relative;margin:6px 0 6px 0;padding-left:8px;display:flex;gap:6px;align-items:center}
.tlrow.done .step-title{ text-decoration:line-through }
.dot{position:absolute;left:-4px;top:6px;width:8px;height:8px;background:#2563eb;border-radius:50%}
.ts{color:#64748b;font:12px/1 ui-monospace;margin-left:6px}
.item-actions{display:flex;gap:8px;justify-content:flex-end;align-items:center;flex-wrap:wrap}
.item-actions a{background:transparent;border:1px solid var(--border);padding:8px 10px;border-radius:10px;color:#64748b;text-decoration:none}
.item-actions a:hover{color:#0f172a;border-color:#cbd5e1}
.mob-move{display:none}
@media (max-width:920px){
  .app{grid-template-columns:1fr}
  .sidebar{position:static;height:auto;overflow:visible;border-right:0;border-bottom:1px solid var(--border)}
  .cat-list{display:grid;grid-template-columns:1fr 1fr;gap:8px}
  .items{grid-template-columns:1fr}
  .item-actions span.tip{display:none}
  .mob-move{display:inline-flex;gap:6px}
}
</style>
</head>
<body>
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
      <div class="err" style="background:#fff7ed;color:#b45309;border:1px solid #fed7aa;padding:8px 10px;border-radius:10px;margin-bottom:10px"><?php echo h($_SESSION['flash']); unset($_SESSION['flash']); ?></div>
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
              <div class="meta" style="display:flex;gap:8px;align-items:center;margin-top:6px;color:#64748b;flex-wrap:wrap">
                <span class="badge"><?php echo $it['category_id'] ? h(array_values(array_filter($cats,fn($c)=>$c['id']==$it['category_id']))[0]['name'] ?? '未分类') : '未分类'; ?></span>
                <span class="badge js-updated">更新 <?php echo dt((int)$it['updated_at']); ?></span>
              </div>
            </div>
          </div>
          <div class="item-actions">
            <span class="tip" style="margin右继续
            <span class="tip" style="margin-right:auto;color:#94a3b8">⬍/↔ 拖拽排序</span>
            <div class="mob-move">
              <button class="btn small" onclick="moveCard(<?php echo $it['id']; ?>,-1)">↑ 上移</button>
              <button class="btn small" onclick="moveCard(<?php echo $it['id']; ?>,1)">↓ 下移</button>
            </div>
            <a class="btn small" href="?view=item&id=<?php echo $it['id']; ?>">详情</a>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
    <div style="margin-top:14px;color:#64748b;font-size:12px">
      快捷键：<span class="kbd">/</span> 聚焦搜索。
    </div>
  </main>
</div>
<div id="cat-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.35);align-items:center;justify-content:center;padding:16px">
  <div style="background:#fff;border:1px solid var(--border);border-radius:12px;max-width:520px;width:100%;padding:14px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
      <div style="font-weight:800">分类管理</div>
      <button class="btn small" onclick="closeCatModal()">关闭</button>
    </div>
    <div id="cat-rows" style="display:grid;gap:8px"></div>
    <form onsubmit="return addCat(event)" style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap">
      <input type="text" id="new-cat-name" placeholder="新增分类名" required style="padding:8px 10px;border:1px solid var(--border);border-radius:10px;flex:1;min-width:200px">
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
      <form onsubmit="return saveCat(event, ${c.id})" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">\
        <input type="text" name="name" value="${escapeHtml(c.name)}" style="padding:8px 10px;border:1px solid var(--border);border-radius:10px;flex:1;min-width:180px">\
        <span class="count" style="color:#64748b;font:12px/1 ui-monospace">共 ${counts[c.id]||0}</span>\
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
