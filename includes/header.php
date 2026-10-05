<?php
$current = basename($_SERVER['PHP_SELF']);
$storeName = setting($pdo, 'store_name', 'Jekwus Luxury Fashion');
$logo = setting($pdo, 'logo');
$navItems = [
    'index.php'      => ['Dashboard', 'bi-speedometer2'],
    'products.php'   => ['Products', 'bi-box-seam'],
    'categories.php' => ['Categories', 'bi-tags'],
    'sales.php'      => ['Sales', 'bi-cart3'],
    'settings.php'   => ['Settings', 'bi-gear'],
];
// Staff lives inside Settings and Owing inside Sales, so keep their parent highlighted.
if ($current === 'users.php') $current = 'settings.php';
if ($current === 'owing.php') $current = 'sales.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#12100e">
<title><?= isset($page_title) ? e($page_title) . ' - ' : '' ?><?= e($storeName) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css?v=5">
</head>
<body class="<?= is_logged_in() ? 'has-nav' : '' ?>">
<?php if (is_logged_in()): ?>
<header class="topbar">
  <a class="brand" href="index.php">
    <?php if ($logo): ?><img src="<?= e($logo) ?>" alt=""><?php else: ?><i class="bi bi-gem"></i><?php endif; ?>
    <span><?= e($storeName) ?></span>
  </a>
  <nav class="top-links d-none d-lg-flex">
    <?php foreach ($navItems as $file => [$label, $icon]): ?>
      <a href="<?= $file ?>" class="<?= $file === $current ? 'active' : '' ?>"><i class="bi <?= $icon ?>"></i> <?= $label ?></a>
    <?php endforeach; ?>
  </nav>
  <span class="who d-none d-sm-inline"><i class="bi bi-person-circle"></i> <?= e(current_user_name()) ?></span>
  <a href="logout.php" class="logout" title="Log out"><i class="bi bi-box-arrow-right"></i><span class="d-none d-sm-inline"> Log out</span></a>
</header>

<nav class="bottom-nav d-lg-none">
  <?php foreach ($navItems as $file => [$label, $icon]): ?>
    <a href="<?= $file ?>" class="<?= $file === $current ? 'active' : '' ?>"><i class="bi <?= $icon ?>"></i><span><?= $label ?></span></a>
  <?php endforeach; ?>
</nav>
<?php endif; ?>
<main class="page">
<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-<?= e($_SESSION['flash']['type']) ?> alert-dismissible fade show">
    <?= e($_SESSION['flash']['msg']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>
