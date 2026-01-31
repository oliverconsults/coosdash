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

function james_state_path(): string {
  return '/var/www/coosdash/shared/data/james_state.json';
}

function james_enabled(): bool {
  $p = james_state_path();
  if (!is_file($p)) return false; // default: sleeps
  $raw = @file_get_contents($p);
  $j = $raw ? json_decode($raw, true) : null;
  if (!is_array($j)) return false;
  return !empty($j['enabled']);
}

function james_set_enabled(bool $enabled): bool {
  $p = james_state_path();
  @mkdir(dirname($p), 0775, true);
  $payload = [
    'enabled' => $enabled ? 1 : 0,
    'updated_at' => date('Y-m-d H:i:s'),
  ];
  return @file_put_contents($p, json_encode($payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)) !== false;
}

function workerlog_append(int $nodeId, string $line): void {
  $ts = date('Y-m-d H:i:s');
  $p = '/var/www/coosdash/shared/logs/worker.log';
  @mkdir(dirname($p), 0775, true);
  // Format: YYYY-MM-DD HH:MM:SS  #<node_id>  <line>
  @file_put_contents($p, $ts . '  #' . (int)$nodeId . '  ' . $line . "\n", FILE_APPEND);
}

function client_ip(): string {
  return (string)($_SERVER['REMOTE_ADDR'] ?? '');
}

function client_ua(): string {
  return (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
}

function loginlog_append(string $event, string $username='', bool $ok=false): void {
  $ts = date('Y-m-d H:i:s');
  $p = '/var/www/coosdash/shared/logs/login.log';
  @mkdir(dirname($p), 0775, true);
  $ip = client_ip();
  $ua = client_ua();
  $user = trim($username);
  $line = $ts
    . '  ip=' . ($ip !== '' ? $ip : '-')
    . '  user=' . ($user !== '' ? $user : '-')
    . '  ok=' . ($ok ? '1' : '0')
    . '  event=' . $event
    . '  ua=' . str_replace(["\n","\r","\t"], ' ', $ua);
  @file_put_contents($p, $line . "\n", FILE_APPEND);
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
      .wrap{max-width:1200px;margin:0;padding:12px 14px}
      .top{display:flex;align-items:center;justify-content:space-between;margin-bottom:18px}
      .brand{font-weight:800;letter-spacing:.08em}
      .brand span{color:var(--gold)}
      .meta{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;color:var(--muted);font-size:12px}
      .att-clip{color:rgba(255,255,255,.92)}
      .btn{display:inline-block;padding:10px 14px;border:1px solid var(--border);border-radius:12px;background:rgba(15,22,35,.9);color:var(--text);transition:transform .08s ease, box-shadow .12s ease, border-color .12s ease, background .12s ease;}
      .btn-md{padding:10px 12px;border-radius:11px;font-size:13px;line-height:1.15}
      .btn-sm{padding:6px 10px;border-radius:10px;font-size:12px;line-height:1.1}
      .btn:hover{background:rgba(15,22,35,1);border-color:rgba(212,175,55,.35);box-shadow:0 10px 26px rgba(0,0,0,.35), 0 0 0 1px rgba(212,175,55,.08) inset;transform:translateY(-1px)}
      .btn:active{transform:translateY(0px);box-shadow:0 6px 14px rgba(0,0,0,.28)}
      .btn-gold{border-color:rgba(212,175,55,.85);background:linear-gradient(180deg, rgba(212,175,55,.28) 0%, rgba(212,175,55,.14) 60%, rgba(15,22,35,.92) 100%);box-shadow:0 0 0 1px rgba(212,175,55,.18) inset, 0 10px 26px rgba(0,0,0,.28)}
      .btn-gold:hover{border-color:rgba(255,215,128,.95);box-shadow:0 12px 34px rgba(0,0,0,.38), 0 0 0 1px rgba(255,215,128,.22) inset}

      /* super-obvious blink for "active" James button (Firefox-safe) */
      .btn-gold{position:relative;}
      .btn-gold::after{
        content:"";
        position:absolute;
        inset:0;
        border-radius:12px;
        background:rgba(255,215,128,.55);
        mix-blend-mode:screen;
        opacity:0;
        pointer-events:none;
        animation:jamesBlinkOpacity .9s steps(2,end) infinite !important;
      }
      @keyframes jamesBlinkOpacity{0%,100%{opacity:0}50%{opacity:1}}
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
      .tree-item.ms-canceled a{color:rgba(255,120,120,.95);text-decoration:line-through;opacity:.9}
      .tree-item.ms-canceled a span{color:rgba(255,120,120,.95) !important}
      .tree-item.ms-later a{font-style:italic;opacity:.92}
      .tree-branch{padding-left:0;border-left:1px dashed rgba(212,175,55,.18)}
      .tree-leaf{padding-left:0}
      .tag{display:inline-block;font-size:11px;padding:2px 8px;border-radius:999px;border:1px solid rgba(212,175,55,.22);color:var(--muted)}
      .kanban{display:grid;grid-template-columns:repeat(4,260px);gap:12px;overflow-x:auto;padding-bottom:6px;justify-content:start}
      .kanban-col{background:rgba(5,7,11,.22);border:1px solid rgba(212,175,55,.14);border-radius:14px;padding:10px}
      .kanban-col h3{margin:0 0 10px 0;font-size:12px;letter-spacing:.04em;color:var(--muted);text-transform:uppercase;display:flex;justify-content:space-between;align-items:center}
      .kanban-card{display:block;padding:10px 10px;border-radius:12px;border:1px solid rgba(212,175,55,.18);background:linear-gradient(180deg, rgba(212,175,55,.10) 0%, rgba(15,22,35,.62) 55%, rgba(8,12,18,.75) 100%);color:var(--text)}
      .kanban-card:hover{border-color:rgba(212,175,55,.6);background:linear-gradient(180deg, rgba(212,175,55,.14) 0%, rgba(15,22,35,.72) 55%, rgba(8,12,18,.82) 100%);text-decoration:none}
      .kanban-card + .kanban-card{margin-top:8px}
      .kanban-title{font-size:13px;line-height:1.2;margin:0}
      .kanban-meta{margin-top:6px;display:flex;gap:8px;align-items:center;justify-content:space-between}
      .pill{display:inline-block;font-size:11px;padding:2px 8px;border-radius:999px;border:1px solid rgba(212,175,55,.22);color:var(--muted);white-space:nowrap}
      .pill.section{color:rgba(242,217,138,.95)}
      .pill.dim{color:rgba(154,166,195,.9)}
      .tag.gold{color:var(--gold);border-color:rgba(212,175,55,.55)}
      .row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
      textarea,input,select{width:100%;padding:10px 12px;border-radius:12px;border:1px solid rgba(212,175,55,.22);background:rgba(5,7,11,.55);color:var(--text)}
      textarea{min-height:110px;resize:vertical}
      textarea.task-note{min-height:330px}
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
          <div class="meta">AI Project Manager OS • dark mode • <?php echo h(nowIso()); ?></div>
        </div>
        <div class="row">
          <?php if (isLoggedIn()): ?>
            <a class="btn" href="/">Dashboard</a>
            <?php $jOn = james_enabled(); ?>
            <?php
              $iconFile = $jOn ? (__DIR__ . '/img/james_active.gif') : (__DIR__ . '/img/james_sleep.png');
              $iconVer = is_file($iconFile) ? (string)@filemtime($iconFile) : '1';
              $jIcon = ($jOn ? '/img/james_active.gif' : '/img/james_sleep.png') . '?v=' . rawurlencode($iconVer);
            ?>
            <a class="btn <?php echo $jOn ? 'btn-gold' : ''; ?>" href="/james.php?toggle=1" style="display:flex; align-items:center; gap:8px;">
              <img src="<?php echo h($jIcon); ?>" alt="James" width="18" height="18" style="display:block; flex:0 0 auto; border-radius:4px;">
              <span><?php echo $jOn ? 'James aktiv' : 'James sleeps'; ?></span>
            </a>
            <a class="btn" href="/workerlog.php">Worker Log</a>
            <a class="btn" href="/loginlog.php">Login Log</a>
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
