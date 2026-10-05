<?php
require 'includes/auth.php';
require_login();
require 'config/db.php';
require 'includes/functions.php';
$page_title = 'Purchase Orders';

// Create a new PO with line items
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    $supplier_id = $_POST['supplier_id'] ?: null;
    $order_date = $_POST['order_date'];
    $expected_date = $_POST['expected_date'] ?: null;
    $notes = trim($_POST['notes']);
    $product_ids = $_POST['product_id'] ?? [];
    $quantities = $_POST['po_quantity'] ?? [];
    $costs = $_POST['unit_cost'] ?? [];

    if ($product_ids) {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO purchase_orders (supplier_id, order_date, expected_date, notes, created_by) VALUES (?,?,?,?,?)");
        $stmt->execute([$supplier_id, $order_date, $expected_date, $notes, current_user_id()]);
        $poId = $pdo->lastInsertId();

        $itemStmt = $pdo->prepare("INSERT INTO purchase_order_items (po_id, product_id, quantity, unit_cost) VALUES (?,?,?,?)");
        foreach ($product_ids as $i => $pid) {
            if ((int)$quantities[$i] > 0) {
                $itemStmt->execute([$poId, $pid, (int)$quantities[$i], (float)$costs[$i]]);
            }
        }
        $pdo->commit();
        flash('success', 'Purchase order created.');
    } else {
        flash('danger', 'Add at least one product line.');
    }
    redirect('purchase_orders.php');
}

// Mark a PO as received -> adds stock for each line item
if (isset($_GET['receive'])) {
    $poId = $_GET['receive'];
    $stmt = $pdo->prepare("SELECT * FROM purchase_order_items WHERE po_id = ?");
    $stmt->execute([$poId]);
    $items = $stmt->fetchAll();
    foreach ($items as $item) {
        record_stock_movement($pdo, $item['product_id'], 'in', $item['quantity'], 'Purchase order received', "PO#$poId", current_user_id());
    }
    $stmt = $pdo->prepare("UPDATE purchase_orders SET status='received' WHERE id=?");
    $stmt->execute([$poId]);
    flash('success', 'Purchase order marked received and stock updated.');
    redirect('purchase_orders.php');
}

if (isset($_GET['cancel'])) {
    $stmt = $pdo->prepare("UPDATE purchase_orders SET status='cancelled' WHERE id=?");
    $stmt->execute([$_GET['cancel']]);
    flash('success', 'Purchase order cancelled.');
    redirect('purchase_orders.php');
}

$suppliers = $pdo->query("SELECT * FROM suppliers ORDER BY name")->fetchAll();
$products = $pdo->query("SELECT id, name, article_number, cost_price FROM products ORDER BY name")->fetchAll();

$orders = $pdo->query("
    SELECT po.*, s.name AS supplier_name,
      (SELECT COUNT(*) FROM purchase_order_items WHERE po_id = po.id) AS item_count,
      (SELECT COALESCE(SUM(quantity*unit_cost),0) FROM purchase_order_items WHERE po_id = po.id) AS total_cost
    FROM purchase_orders po LEFT JOIN suppliers s ON s.id = po.supplier_id
    ORDER BY po.created_at DESC
")->fetchAll();

require 'includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0">Purchase Orders</h4>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#poModal"><i class="bi bi-plus-lg"></i> New Purchase Order</button>
</div>

<table class="table table-striped bg-white shadow-sm">
  <thead><tr><th>#</th><th>Supplier</th><th>Order Date</th><th>Expected</th><th>Items</th><th>Est. Cost</th><th>Status</th><th>Actions</th></tr></thead>
  <tbody>
  <?php foreach ($orders as $o): ?>
    <tr>
      <td>PO#<?= $o['id'] ?></td>
      <td><?= htmlspecialchars($o['supplier_name'] ?? '-') ?></td>
      <td><?= $o['order_date'] ?></td>
      <td><?= $o['expected_date'] ?? '-' ?></td>
      <td><?= $o['item_count'] ?></td>
      <td>₦<?= money($o['total_cost']) ?></td>
      <td><span class="badge bg-<?= $o['status']==='received'?'success':($o['status']==='cancelled'?'secondary':'warning') ?>"><?= ucfirst($o['status']) ?></span></td>
      <td>
        <?php if ($o['status'] === 'pending'): ?>
          <a href="?receive=<?= $o['id'] ?>" class="btn btn-sm btn-success" onclick="return confirm('Mark received? This will add stock for all items.')">Receive</a>
          <a href="?cancel=<?= $o['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Cancel this PO?')">Cancel</a>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$orders): ?><tr><td colspan="8" class="text-center text-muted py-3">No purchase orders yet.</td></tr><?php endif; ?>
  </tbody>
</table>

<div class="modal fade" id="poModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="action" value="create">
        <div class="modal-header"><h5>New Purchase Order</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="row g-2 mb-3">
            <div class="col-md-5"><label class="form-label">Supplier</label>
              <select class="form-select" name="supplier_id">
                <option value="">-- none --</option>
                <?php foreach ($suppliers as $s): ?><option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3"><label class="form-label">Order Date</label><input type="date" class="form-control" name="order_date" value="<?= date('Y-m-d') ?>" required></div>
            <div class="col-md-4"><label class="form-label">Expected Date</label><input type="date" class="form-control" name="expected_date"></div>
          </div>
          <table class="table table-sm" id="lineTable">
            <thead><tr><th>Product</th><th style="width:120px">Qty</th><th style="width:140px">Unit Cost (₦)</th><th></th></tr></thead>
            <tbody id="lineBody"></tbody>
          </table>
          <button type="button" class="btn btn-sm btn-outline-primary" onclick="addLine()"><i class="bi bi-plus"></i> Add line</button>
          <div class="mt-3"><label class="form-label">Notes</label><textarea class="form-control" name="notes" rows="2"></textarea></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Create Order</button></div>
      </form>
    </div>
  </div>
</div>

<script>
const products = <?= json_encode($products) ?>;
function addLine() {
  const row = document.createElement('tr');
  const options = products.map(p => `<option value="${p.id}" data-cost="${p.cost_price}">${p.name} (${p.article_number})</option>`).join('');
  row.innerHTML = `
    <td><select class="form-select form-select-sm" name="product_id[]" onchange="this.closest('tr').querySelector('.costInput').value = this.selectedOptions[0].dataset.cost">${options}</select></td>
    <td><input type="number" min="1" class="form-control form-control-sm" name="po_quantity[]" value="1" required></td>
    <td><input type="number" step="0.01" class="form-control form-control-sm costInput" name="unit_cost[]" value="${products[0]?.cost_price || 0}"></td>
    <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove()"><i class="bi bi-x"></i></button></td>
  `;
  document.getElementById('lineBody').appendChild(row);
}
document.addEventListener('DOMContentLoaded', addLine);
</script>
<?php require 'includes/footer.php'; ?>
