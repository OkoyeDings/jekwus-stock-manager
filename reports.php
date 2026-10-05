<?php
require 'includes/auth.php';
require_login();
require 'config/db.php';
require 'includes/functions.php';
$page_title = 'Reports';

$lowStock = $pdo->query("SELECT * FROM products WHERE quantity <= reorder_level ORDER BY quantity ASC")->fetchAll();
$inventoryValue = $pdo->query("SELECT COALESCE(SUM(quantity*cost_price),0) AS cost_val, COALESCE(SUM((quantity/NULLIF(pieces_per_bundle,0))*bundle_price),0) AS retail_val FROM products")->fetch();
$bestSellers = $pdo->query("
    SELECT p.name, p.article_number, SUM(si.pieces) AS total_sold, SUM(si.subtotal) AS revenue
    FROM sale_items si JOIN products p ON p.id = si.product_id
    GROUP BY si.product_id ORDER BY total_sold DESC LIMIT 10
")->fetchAll();

require 'includes/header.php';
?>
<h4 class="mb-4">Reports</h4>

<div class="row g-3 mb-4">
  <div class="col-md-6">
    <div class="card text-bg-success shadow-sm"><div class="card-body">
      <div class="small">Inventory Value (at cost)</div><div class="fs-4 fw-bold">₦<?= money($inventoryValue['cost_val']) ?></div>
    </div></div>
  </div>
  <div class="col-md-6">
    <div class="card text-bg-info shadow-sm"><div class="card-body">
      <div class="small">Inventory Value (at bundle retail, est.)</div><div class="fs-4 fw-bold">₦<?= money($inventoryValue['retail_val']) ?></div>
    </div></div>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="card shadow-sm">
      <div class="card-header"><strong><i class="bi bi-exclamation-triangle text-warning"></i> Low Stock / Reorder List</strong></div>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead><tr><th>Product</th><th>SKU</th><th>Qty</th><th>Reorder At</th></tr></thead>
          <tbody>
          <?php foreach ($lowStock as $p): ?>
            <tr class="<?= $p['quantity']==0?'table-danger':'table-warning' ?>">
              <td><?= htmlspecialchars($p['name']) ?></td><td><?= htmlspecialchars($p['article_number']) ?></td><td><?= $p['quantity'] ?></td><td><?= $p['reorder_level'] ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$lowStock): ?><tr><td colspan="4" class="text-center text-muted py-3">Nothing low on stock.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card shadow-sm">
      <div class="card-header"><strong><i class="bi bi-trophy"></i> Best Sellers</strong></div>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead><tr><th>Product</th><th>SKU</th><th>Units Sold</th><th>Revenue</th></tr></thead>
          <tbody>
          <?php foreach ($bestSellers as $b): ?>
            <tr><td><?= htmlspecialchars($b['name']) ?></td><td><?= htmlspecialchars($b['article_number']) ?></td><td><?= $b['total_sold'] ?></td><td>₦<?= money($b['revenue']) ?></td></tr>
          <?php endforeach; ?>
          <?php if (!$bestSellers): ?><tr><td colspan="4" class="text-center text-muted py-3">No sales yet.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php require 'includes/footer.php'; ?>
