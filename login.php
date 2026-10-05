<?php
require 'includes/auth.php';
require 'config/db.php';
require 'includes/functions.php';

if (is_logged_in()) {
    redirect('index.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password']) && !$user['active']) {
        $error = 'This account has been disabled. Ask the shop owner.';
    } elseif ($user && password_verify($password, $user['password'])) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['name'] = $user['name'];
        $_SESSION['role'] = $user['role'];
        redirect('index.php');
    } else {
        $error = 'Wrong username or password.';
    }
}
$storeName = setting($pdo, 'store_name', 'Jekwus Luxury Fashion');
$logo = setting($pdo, 'logo');
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#12100e">
<title>Login - <?= e($storeName) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css?v=5">
<style>
  body { background: var(--brand-dark); min-height: 100vh; display: flex; align-items: center; }
  .brand-card { border-top: 3px solid var(--brand-gold); }
</style>
</head>
<body>
<div class="container py-4" style="max-width:420px;">
  <div class="text-center mb-4" style="color:var(--brand-gold)">
    <?php if ($logo): ?><img src="<?= e($logo) ?>" alt="" style="max-height:80px;max-width:220px;object-fit:contain">
    <?php else: ?><i class="bi bi-gem" style="font-size:2.5rem"></i><?php endif; ?>
    <h1 class="h4 mt-2 mb-0"><?= e($storeName) ?></h1>
    <div class="small" style="color:#b8b0a3">Stock &amp; Sales</div>
  </div>
  <div class="card brand-card">
    <div class="card-body p-4">
      <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
      <form method="post">
        <div class="mb-3"><label class="form-label">Username or email</label><input type="text" class="form-control" name="email" autocapitalize="none" autocorrect="off" spellcheck="false" value="<?= e($_POST['email'] ?? '') ?>" required autocomplete="username"></div>
        <div class="mb-3"><label class="form-label">Password</label><input type="password" class="form-control" name="password" required autocomplete="current-password"></div>
        <button class="btn btn-brand w-100 py-2">Log in</button>
      </form>
    </div>
  </div>
</div>
</body></html>
