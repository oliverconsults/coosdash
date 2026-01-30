<?php
require_once __DIR__ . '/functions_v2.inc.php';

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

  if ($user && password_verify($p, $user['password_hash'])) {
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    flash_set('Welcome back, ' . $user['username'] . '.', 'info');
    header('Location: /');
    exit;
  }

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
      <button class="btn btn-gold" type="submit">Login</button>
    </div>
  </form>
</div>

<?php renderFooter(); ?>
