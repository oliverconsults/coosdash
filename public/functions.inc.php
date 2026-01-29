<?php
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

function cfg() {
  static $cfg = null;
  if ($cfg !== null) return $cfg;
  $path = '/var/www/coosdash/shared/config.local.php';
  if (!is_file($path)) {
    http_response_code(500);
    die('Missing config.local.php');
  }
  $cfg = require $path;
  return $cfg;
}

function db() {
  static $pdo = null;
  if ($pdo) return $pdo;
  $c = cfg();
  $db = $c['db'];
  $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $db['host'],
    (int)$db['port'],
    $db['name'],
    $db['charset'] ?? 'utf8mb4'
  );
  $pdo = new PDO($dsn, $db['user'], $db['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
  ]);
  return $pdo;
}

function isLoggedIn(): bool {
  return !empty($_SESSION['user_id']);
}

function requireLogin(): void {
  if (!isLoggedIn()) {
    header('Location: /login.php');
    exit;
  }
}

function h($s): string {
  return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function nowIso(): string {
  return date('Y-m-d H:i:s');
}

function flash_set(string $msg, string $type='info'): void {
  $_SESSION['flash'] = ['msg'=>$msg, 'type'=>$type];
}

function flash_get(): ?array {
  if (empty($_SESSION['flash'])) return null;
  $f = $_SESSION['flash'];
  unset($_SESSION['flash']);
  return $f;
}

function renderHeader(string $title='COOS'): void {
  $f = flash_get();
  ?>
  <!doctype html>
  <html lang="de">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title><?php echo h($title); ?></title>
    <style>
      :root{
        --bg:#0b0f16; --panel:#0f1623; --panel2:#0b1020;
        --text:#e8eefc; --muted:#9aa6c3; --gold:#d4af37;
        --border:rgba(212,175,55,.18);
      }
      *{box-sizing:border-box}
      body{margin:0;background:linear-gradient(180deg,#05070b 0%, var(--bg) 45%, #05070b 100%);
        color:var(--text);font-family:ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Arial;}
      a{color:var(--gold);text-decoration:none}
      a:hover{text-decoration:underline}
      .wrap{max-width:1200px;margin:0 auto;padding:22px}
      .top{display:flex;align-items:center;justify-content:space-between;margin-bottom:18px}
      .brand{font-weight:800;letter-spacing:.08em}
      .brand span{color:var(--gold)}
      .meta{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;color:var(--muted);font-size:12px}
      .btn{display:inline-block;padding:10px 14px;border:1px solid var(--border);border-radius:12px;background:rgba(15,22,35,.9);color:var(--text)}
      .btn:hover{background:rgba(15,22,35,1)}
      .btn-gold{border-color:rgba(212,175,55,.6);box-shadow:0 0 0 1px rgba(212,175,55,.1) inset}
      .grid{display:grid;grid-template-columns:360px 1fr;gap:16px}
      .card{background:rgba(15,22,35,.92);border:1px solid var(--border);border-radius:16px;padding:14px}
      .card h2{margin:0 0 10px 0;font-size:15px;letter-spacing:.02em}
      .tree{font-size:12px}
      .tree details{margin:6px 0}
      .tree summary{list-style:none}
      .tree summary::-webkit-details-marker{display:none}
      .tree-item{display:flex;align-items:center;gap:6px;padding:6px 8px;border-radius:10px;border:1px solid rgba(212,175,55,.12);background:rgba(5,7,11,.35)}
      .tree-item.active{border-color:rgba(212,175,55,.6);background:rgba(212,175,55,.06)}
      .tree-item a{color:var(--text)}
      .tree-item a:hover{color:var(--gold)}
      .tree-num{min-width:46px;opacity:.9;color:var(--muted);font-size:12px}
      .tree-branch{padding-left:4px;border-left:1px dashed rgba(212,175,55,.18)}
      .tree-leaf{padding-left:4px}
      .tag{display:inline-block;font-size:11px;padding:2px 8px;border-radius:999px;border:1px solid rgba(212,175,55,.22);color:var(--muted)}
      .tag.gold{color:var(--gold);border-color:rgba(212,175,55,.55)}
      .row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
      textarea,input,select{width:100%;padding:10px 12px;border-radius:12px;border:1px solid rgba(212,175,55,.22);background:rgba(5,7,11,.55);color:var(--text)}
      textarea{min-height:110px;resize:vertical}
      label{display:block;margin:10px 0 6px 0;color:var(--muted);font-size:12px}
      table{width:100%;border-collapse:collapse}
      td,th{padding:10px;border-bottom:1px solid rgba(212,175,55,.12);vertical-align:top}
      .note{padding:10px;border:1px solid rgba(212,175,55,.14);border-radius:12px;margin:10px 0;background:rgba(11,16,32,.5)}
      .note .head{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;color:var(--muted);font-size:12px;margin-bottom:6px}
      .flash{padding:10px 12px;border-radius:12px;margin:10px 0;border:1px solid rgba(212,175,55,.25);background:rgba(212,175,55,.06)}
      .flash.err{border-color:rgba(255,80,80,.35);background:rgba(255,80,80,.06)}
      @media (max-width: 900px){.grid{grid-template-columns:1fr}}
    </style>
  </head>
  <body>
    <div class="wrap">
      <div class="top">
        <div>
          <div class="brand">COOS<span>.eu</span></div>
          <div class="meta">Idea-to-Cash OS • dark mode • <?php echo h(nowIso()); ?></div>
        </div>
        <div class="row">
          <?php if (isLoggedIn()): ?>
            <a class="btn" href="/">Dashboard</a>
            <a class="btn" href="/logout.php">Logout</a>
          <?php endif; ?>
        </div>
      </div>
      <?php if ($f): ?>
        <div class="flash <?php echo $f['type']==='err'?'err':''; ?>"><?php echo h($f['msg']); ?></div>
      <?php endif; ?>
  <?php
}

function renderFooter(): void {
  echo "</div></body></html>";
}
