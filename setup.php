<?php
// Run this ONCE in your browser after importing sql/schema.sql to create
// the first admin account. Delete this file afterwards for security.
require 'config/db.php';

$stmt = $pdo->query("SELECT COUNT(*) FROM users");
$userCount = $stmt->fetchColumn();

$error = '';
$done = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$name || !$email || strlen($password) < 6) {
        $error = 'Please fill all fields. Password must be at least 6 characters.';
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?,?,?, 'admin')");
        $stmt->execute([$name, $email, $hash]);
        $done = true;
    }
}
?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>Setup</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container" style="max-width:480px; margin-top:80px;">
  <div class="card shadow-sm">
    <div class="card-body p-4">
      <h4 class="mb-3">First-time setup</h4>
      <?php if ($done): ?>
        <div class="alert alert-success">Admin account created. You can now <a href="login.php">log in</a>. Please delete <code>setup.php</code> from the server now.</div>
      <?php else: ?>
        <?php if ($userCount > 0): ?>
          <div class="alert alert-warning">A user already exists. If you still want to create another admin, submit the form below, or just delete this file and go to <a href="login.php">login</a>.</div>
        <?php endif; ?>
        <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <form method="post">
          <div class="mb-3"><label class="form-label">Full name</label><input class="form-control" name="name" required></div>
          <div class="mb-3"><label class="form-label">Email</label><input type="email" class="form-control" name="email" required></div>
          <div class="mb-3"><label class="form-label">Password</label><input type="password" class="form-control" name="password" required minlength="6"></div>
          <button class="btn btn-primary w-100">Create admin account</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>
</body></html>
