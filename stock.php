<?php
require 'includes/auth.php';
require_login();
require 'config/db.php';
require 'includes/functions.php';
$page_title = 'Stock Movements';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $product_id = $_POST['product_id'];
    $type = $_POST['type'];
    $qty = (int)$_POST['quantity'];
    $reason = trim($_POST['reason']);

    if ($qty > 0 && in_array($type, ['in','out'])) {
        record_stock_movement($pdo, $product_id, $type, $qty, $reason, 'Manual entry', current_user_id());
        flash('success', 'Stock movement recorded.');
    } else {
        flash('danger', 'Enter a valid quantity.');
    }
    redirect('stock.php');
}

$products = $pdo->query("SELECT id, name, article_number, quantity FROM products ORDER BY name")->fetchAll();

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;
$total = $pdo->query("SELECT COUNT(*) FROM stock_movements")->fetchColumn();
$stmt = $pdo->prepare("
    SELECT sm.*, p.name AS product_name, p.article_number, u.name AS user_name
    FROM stock_movements sm
    JOIN products p ON p.id = sm.product_id
    LEFT JOIN users u ON u.id = sm.user_id
    ORDER BY sm.created_at DESC LIMIT $perPage OFFSET $offset
");
$stmt->execute();
$movements = $stmt->fetchAll();

require 'includes/header.php';
?>
<h4 class="mb-3">Stock Movements</h4>

<div class="card shadow-sm mb-4">
  <div class="card-body">
    <form method="post" class="row g-2 align-items-end">
      <div class="col-md-4">
        <label class="form-label">Product</label>
        <select class="form-select" name="product_id" required>
          <?php foreach ($products as $p): ?>
            <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> (<?= htmlspecialchars($p['article_number']) ?>) — current: <?= $p['quantity'] ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label">Type</label>
        <select class="form-select" name="type" required>
          <option value="in">Stock In</option>
          <option value="out">Stock Out</option>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label">Quantity</label>
        <input type="number" min="1" class="form-control" name="quantity" required>
      </div>
      <div class="col-md-3">
        <label class="form-label">Reason</label>
        <input class="form-control" name="reason" placeholder="e.g. Damaged, Restock, Correction">
      </div>
      <div class="col-md-1">
        <button class="btn btn-primary w-100">Save</button>
      </div>
    </form>
  </div>
</div>

<div class="table-responsive">
<table class="table table-striped bg-white shadow-sm">
  <thead><tr><th>Date</th><th>Product</th><th>SKU</th><th>Type</th><th>Qty</th><th>Reason</th><th>Reference</th><th>By</th></tr></thead>
  <tbody>
  <?php foreach ($movements as $m): ?>
    <tr>
      <td class="small text-muted"><?= date('d M Y, H:i', strtotime($m['created_at'])) ?></td>
      <td><?= htmlspecialchars($m['product_name']) ?></td>
      <td><code><?= htmlspecialchars($m['article_number']) ?></code></td>
      <td><span class="badge bg-<?= $m['type'] === 'in' ? 'success' : 'secondary' ?>"><?= strtoupper($m['type']) ?></span></td>
      <td><?= $m['quantity'] ?></td>
      <td><?= htmlspecialchars($m['reason'] ?? '-') ?></td>
      <td class="small"><?= htmlspecialchars($m['reference'] ?? '-') ?></td>
      <td class="small"><?= htmlspecialchars($m['user_name'] ?? 'System') ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$movements): ?><tr><td colspan="8" class="text-center text-muted py-3">No stock movements yet.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>

<?php $totalPages = ceil($total / $perPage); if ($totalPages > 1): ?>
<nav><ul class="pagination">
  <?php for ($i=1; $i<=$totalPages; $i++): ?>
    <li class="page-item <?= $i==$page?'active':'' ?>"><a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a></li>
  <?php endfor; ?>
</ul></nav>
<?php endif; ?>

<?php require 'includes/footer.php'; ?>
