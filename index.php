<?php
require 'includes/auth.php';
require_login();
require 'config/db.php';
require 'includes/functions.php';
$page_title = 'Dashboard';

$totalProducts = $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
$totalPieces = (int)$pdo->query("SELECT COALESCE(SUM(quantity),0) FROM products")->fetchColumn();

// Money owed: count people by customer name, the same way the Owing page groups them.
$owing = $pdo->query("
    SELECT COUNT(DISTINCT LOWER(TRIM(COALESCE(customer_name,'')))) AS people,
           COALESCE(SUM(total_amount - amount_paid),0) AS amount
    FROM sales WHERE amount_paid < total_amount
")->fetch();
$topOwing = $pdo->query("
    SELECT MAX(customer_name) AS name, SUM(total_amount - amount_paid) AS owed, COUNT(*) AS sales
    FROM sales WHERE amount_paid < total_amount
    GROUP BY LOWER(TRIM(COALESCE(customer_name,'')))
    ORDER BY owed DESC LIMIT 5
")->fetchAll();

$todayByStaff = $pdo->query("
    SELECT COALESCE(u.name,'Unknown') AS name, COUNT(*) AS sales, SUM(s.total_amount) AS amount
    FROM sales s LEFT JOIN users u ON u.id = s.user_id
    WHERE DATE(s.sale_date) = CURDATE()
    GROUP BY u.id, u.name ORDER BY amount DESC
")->fetchAll();

$today = $pdo->query("
    SELECT COALESCE(SUM(si.subtotal),0) AS amount,
           COALESCE(SUM(si.pieces),0) AS pieces,
           COUNT(DISTINCT s.id) AS sales
    FROM sales s JOIN sale_items si ON si.sale_id = s.id
    WHERE DATE(s.sale_date) = CURDATE()
")->fetch();

$recentSold = $pdo->query("
    SELECT si.*, s.sale_date, s.total_amount, s.amount_paid, p.name AS product_name, p.article_number, p.image, u.name AS user_name
    FROM sale_items si
    JOIN sales s ON s.id = si.sale_id
    JOIN products p ON p.id = si.product_id
    LEFT JOIN users u ON u.id = s.user_id
    ORDER BY s.sale_date DESC, si.id DESC LIMIT 8
")->fetchAll();

$recentAdded = $pdo->query("
    SELECT p.*, c.name AS category_name
    FROM products p LEFT JOIN categories c ON c.id = p.category_id
    ORDER BY p.created_at DESC, p.id DESC LIMIT 8
")->fetchAll();


require 'includes/header.php';
?>

<div class="page-head">
  <h1>Dashboard</h1>
  <div class="d-flex gap-2">
    <a href="sales.php?new=1" class="btn btn-brand"><i class="bi bi-cart-plus"></i> <span class="d-none d-sm-inline">New </span>Sale</a>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3">
    <div class="card stat dark h-100">
      <div class="label">Pieces in stock</div>
      <div class="value"><?= number_format($totalPieces) ?></div>
      <div class="sub">pieces of cloth left</div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card stat h-100">
      <div class="label">Products</div>
      <div class="value"><?= number_format($totalProducts) ?></div>
      <div class="sub">on the list</div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card stat dark h-100">
      <div class="label">Sold today</div>
      <div class="value">₦<?= money($today['amount']) ?></div>
      <div class="sub"><?= (int)$today['sales'] ?> sale<?= $today['sales'] == 1 ? '' : 's' ?></div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card stat h-100">
      <div class="label">Pieces sold today</div>
      <div class="value"><?= number_format($today['pieces']) ?></div>
      <div class="sub">pieces</div>
    </div>
  </div>
</div>

<a href="owing.php" class="card owing-card mb-3 text-decoration-none">
  <div class="card-body d-flex align-items-center gap-3">
    <div class="owing-icon"><i class="bi bi-hourglass-split"></i></div>
    <div class="flex-grow-1">
      <div class="label">Customers owing you</div>
      <div class="value">₦<?= money($owing['amount']) ?></div>
      <div class="sub"><?= (int)$owing['people'] ?> <?= $owing['people'] == 1 ? 'person' : 'people' ?> owing</div>
    </div>
    <i class="bi bi-chevron-right fs-4"></i>
  </div>
  <?php if ($topOwing): ?>
  <div class="owing-list">
    <?php foreach ($topOwing as $o): ?>
      <div class="d-flex justify-content-between"><span><?= e($o['name'] ?: 'No name') ?> <span class="opacity-75 small">(<?= (int)$o['sales'] ?>)</span></span><strong>₦<?= money($o['owed']) ?></strong></div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</a>

<?php if ($todayByStaff): ?>
<div class="card mb-3">
  <div class="card-header"><i class="bi bi-people"></i> Today's sales by staff</div>
  <?php foreach ($todayByStaff as $st): ?>
    <div class="list-row">
      <div><div class="title"><?= e($st['name']) ?></div><div class="meta"><?= (int)$st['sales'] ?> sale<?= $st['sales'] == 1 ? '' : 's' ?></div></div>
      <div class="end fw-semibold">₦<?= money($st['amount']) ?></div>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-receipt"></i> Recently sold</span>
        <a href="sales.php" class="small">All sales</a>
      </div>
      <?php if (!$recentSold): ?><div class="empty">No sales yet.</div><?php endif; ?>
      <?php foreach ($recentSold as $s): ?>
        <div class="list-row">
          <?php if ($s['image']): ?><img class="thumb" src="<?= e($s['image']) ?>" alt=""><?php endif; ?>
          <div class="min-w-0 flex-grow-1">
            <div class="title"><?= e($s['product_name']) ?> <?= balance_of($s) > 0 ? '<span class="badge bg-danger">Owing</span>' : '' ?></div>
            <div class="meta"><?= sale_line_label($s['sale_type'], $s['bundle_count']) ?> · <?= date('d M, H:i', strtotime($s['sale_date'])) ?> · by <?= e($s['user_name'] ?? 'Unknown') ?></div>
          </div>
          <div class="end fw-semibold">₦<?= money($s['subtotal']) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-plus-square"></i> Recently added</span>
        <a href="products.php" class="small">All products</a>
      </div>
      <?php if (!$recentAdded): ?><div class="empty">No products yet. <a href="products.php?new=1">Add one</a>.</div><?php endif; ?>
      <?php foreach ($recentAdded as $p): ?>
        <div class="list-row">
          <?php if ($p['image']): ?><img class="thumb" src="<?= e($p['image']) ?>" alt=""><?php endif; ?>
          <div class="flex-grow-1">
            <div class="title"><?= e($p['name']) ?></div>
            <div class="meta"><code><?= e($p['article_number']) ?></code><?= $p['category_name'] ? ' · ' . e($p['category_name']) : '' ?> · <?= date('d M', strtotime($p['created_at'])) ?></div>
          </div>
          <div class="end"><?= stock_label($p['quantity'], $p['pieces_per_bundle']) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<?php require 'includes/footer.php'; ?>
