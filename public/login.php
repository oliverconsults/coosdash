<?php
require_once __DIR__ . '/functions_v3.inc.php';

if (isLoggedIn()) {
  header('Location: /');
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $u = trim($_POST['username'] ?? '');
  $p = (string)($_POST['password'] ?? '');

  $pdo = db();
  $st = $pdo->prepare('SELECT id, username, password_hash FROM users WHERE username=? LIMIT 1');
  $st->execute([$u]);
  $user = $st->fetch();

  $ok = false;
  if ($user && password_verify($p, $user['password_hash'])) {
    $ok = true;
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    loginlog_append('login', $user['username'], true);
    flash_set('Welcome back, ' . $user['username'] . '.', 'info');
    header('Location: /');
    exit;
  }

  // failed attempt
  loginlog_append('login_fail', $u, false);
  // small delay to slow down brute force a bit
  usleep(250000);
  flash_set('Login fehlgeschlagen.', 'err');
}

renderHeader('Login');
?>

<div class="card" style="max-width:520px;margin:0 auto;">
  <h2>Login</h2>
  <form method="post">
    <label>Username</label>
    <input name="username" autocomplete="username" required>

    <label>Password</label>
    <input type="password" name="password" autocomplete="current-password" required>

    <div style="margin-top:12px;">
      <button class="btn" type="submit">Login</button>
    </div>
  </form>
</div>

<?php renderFooter(); ?>
