<?php
require 'includes/auth.php';
require_login();
require 'config/db.php';
require 'includes/functions.php';
$page_title = 'Sales';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'checkout') {
    $customer_name = trim($_POST['customer_name'] ?? '');
    $product_ids = $_POST['product_id'] ?? [];
    $sale_types = $_POST['sale_type'] ?? [];
    $counts = $_POST['count'] ?? [];
    $unit_prices = $_POST['unit_price'] ?? [];

    if (!$product_ids) {
        flash('danger', 'Add at least one item to the sale.');
        redirect('sales.php');
    }

    $pdo->beginTransaction();
    try {
        $total = 0;
        $lines = [];
        $needed = []; // pieces needed per product across all lines
        foreach ($product_ids as $i => $pid) {
            $count = (int)($counts[$i] ?? 0);
            $saleType = ($sale_types[$i] ?? '') === 'piece' ? 'piece' : 'bundle';
            $unitPrice = (float)($unit_prices[$i] ?? 0);
            if ($count <= 0) continue;

            $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ? FOR UPDATE");
            $stmt->execute([$pid]);
            $prod = $stmt->fetch();
            if (!$prod) throw new Exception('One of the products no longer exists.');

            $piecesNeeded = $saleType === 'bundle' ? (int)$prod['pieces_per_bundle'] * $count : $count;
            $needed[$pid] = ($needed[$pid] ?? 0) + $piecesNeeded;

            if ($prod['quantity'] < $needed[$pid]) {
                throw new Exception("Not enough stock for {$prod['name']} — only " . stock_label($prod['quantity'], $prod['pieces_per_bundle']) . " left.");
            }
            $subtotal = $unitPrice * $count;
            $total += $subtotal;
            $lines[] = ['product_id' => $pid, 'sale_type' => $saleType, 'count' => $count, 'pieces' => $piecesNeeded, 'unit_price' => $unitPrice, 'subtotal' => $subtotal];
        }

        if (!$lines) throw new Exception('Add at least one valid item.');

        $paid = min($total, max(0, (float)($_POST['amount_paid'] ?? $total)));
        if ($paid < $total && $customer_name === '') {
            throw new Exception("This sale is not fully paid — enter the customer's name so you know who is owing.");
        }

        $stmt = $pdo->prepare("INSERT INTO sales (customer_name, total_amount, amount_paid, user_id) VALUES (?,?,?,?)");
        $stmt->execute([$customer_name ?: null, $total, $paid, current_user_id()]);
        $saleId = $pdo->lastInsertId();

        if ($paid > 0) {
            $pdo->prepare("INSERT INTO sale_payments (sale_id, amount, user_id) VALUES (?,?,?)")->execute([$saleId, $paid, current_user_id()]);
        }

        $itemStmt = $pdo->prepare("INSERT INTO sale_items (sale_id, product_id, sale_type, bundle_count, pieces, unit_price, subtotal) VALUES (?,?,?,?,?,?,?)");
        $upd = $pdo->prepare("UPDATE products SET quantity = quantity - ? WHERE id = ?");
        $mv = $pdo->prepare("INSERT INTO stock_movements (product_id, type, quantity, reason, reference, user_id) VALUES (?, 'out', ?, ?, ?, ?)");
        foreach ($lines as $l) {
            $itemStmt->execute([$saleId, $l['product_id'], $l['sale_type'], $l['count'], $l['pieces'], $l['unit_price'], $l['subtotal']]);
            $upd->execute([$l['pieces'], $l['product_id']]);
            $mv->execute([$l['product_id'], $l['pieces'], 'Sale — ' . sale_line_label($l['sale_type'], $l['count']), "Sale#$saleId", current_user_id()]);
        }

        $pdo->commit();
        $owing = $total - $paid;
        flash($owing > 0 ? 'warning' : 'success', "Sale #$saleId saved — total ₦" . money($total) . ($owing > 0 ? ". $customer_name is owing ₦" . money($owing) . '.' : '.'));
    } catch (Exception $e) {
        $pdo->rollBack();
        flash('danger', $e->getMessage());
    }
    redirect('sales.php');
}

// Customer pays some or all of what they owe on a sale
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pay') {
    $saleId = (int)($_POST['sale_id'] ?? 0);
    $amount = round((float)($_POST['amount'] ?? 0), 2);
    $back = $_POST['back'] ?? 'sales.php';
    if (!preg_match('/^(sales|owing|index)\.php(\?[\w=&%.-]*)?$/', $back)) $back = 'sales.php';

    $pdo->beginTransaction();
    $stmt = $pdo->prepare("SELECT * FROM sales WHERE id = ? FOR UPDATE");
    $stmt->execute([$saleId]);
    $sale = $stmt->fetch();
    $bal = $sale ? balance_of($sale) : 0;
    if (!$sale || $amount <= 0 || $bal <= 0) {
        $pdo->rollBack();
        flash('danger', 'Enter an amount to record.');
        redirect($back);
    }
    $amount = min($amount, $bal);
    $pdo->prepare("UPDATE sales SET amount_paid = amount_paid + ? WHERE id = ?")->execute([$amount, $saleId]);
    $pdo->prepare("INSERT INTO sale_payments (sale_id, amount, user_id) VALUES (?,?,?)")->execute([$saleId, $amount, current_user_id()]);
    $pdo->commit();
    $left = $bal - $amount;
    flash('success', "₦" . money($amount) . " received for sale #$saleId. " . ($left > 0 ? 'Still owing ₦' . money($left) . '.' : 'Now fully paid.'));
    redirect($back);
}

$products = $pdo->query("SELECT id, name, article_number, size, color, image, pieces_per_bundle, bundle_price, piece_price, quantity FROM products WHERE quantity > 0 ORDER BY name")->fetchAll();
foreach ($products as &$p) {
    $p['stock_label'] = stock_label($p['quantity'], $p['pieces_per_bundle']);
}
unset($p);

$todayTotal = $pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE DATE(sale_date) = CURDATE()")->fetchColumn();

// All-time money owed, for the "Customers owing" link at the top.
$owingAll = $pdo->query("
    SELECT COUNT(DISTINCT LOWER(TRIM(COALESCE(customer_name,'')))) AS people,
           COALESCE(SUM(total_amount - amount_paid),0) AS amount
    FROM sales WHERE amount_paid < total_amount
")->fetch();

// Filters: ?month=2026-09 (or "all"), ?status=owing, ?by=<user id>
$month = $_GET['month'] ?? date('Y-m');
$allTime = $month === 'all';
if (!$allTime && !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) $month = date('Y-m');
$status = ($_GET['status'] ?? '') === 'owing' ? 'owing' : '';
$by = (int)($_GET['by'] ?? 0);

$where = [];
$params = [];
if (!$allTime) {
    $where[] = 's.sale_date >= ? AND s.sale_date < ?';
    $params[] = "$month-01 00:00:00";
    $params[] = date('Y-m-01 00:00:00', strtotime("$month-01 +1 month"));
}
if ($status === 'owing') $where[] = 's.amount_paid < s.total_amount';
if ($by) { $where[] = 's.user_id = ?'; $params[] = $by; }
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$baseMonth = $allTime ? date('Y-m') : $month;
$prevMonth = date('Y-m', strtotime("$baseMonth-01 -1 month"));
$nextMonth = date('Y-m', strtotime("$baseMonth-01 +1 month"));
$isCurrentMonth = !$allTime && $month === date('Y-m');
$periodLabel = $allTime ? 'all time' : date('F Y', strtotime("$month-01"));

function q(array $change) {
    $params = array_merge(['month' => $_GET['month'] ?? null, 'status' => $_GET['status'] ?? null, 'by' => $_GET['by'] ?? null], $change);
    return '?' . http_build_query(array_filter($params, fn($v) => $v !== null && $v !== ''));
}

$stmt = $pdo->prepare("
    SELECT COUNT(*) AS sales, COALESCE(SUM(total_amount),0) AS amount,
           COALESCE(SUM(amount_paid),0) AS paid, COALESCE(SUM(total_amount - amount_paid),0) AS owing
    FROM sales s $whereSql
");
$stmt->execute($params);
$stats = $stmt->fetch();

$stmt = $pdo->prepare("SELECT COALESCE(SUM(si.pieces),0) FROM sale_items si JOIN sales s ON s.id = si.sale_id $whereSql");
$stmt->execute($params);
$piecesSold = (int)$stmt->fetchColumn();

// Who sold what in this period
$stmt = $pdo->prepare("
    SELECT u.id, COALESCE(u.name, 'Unknown') AS name, COUNT(*) AS sales, SUM(s.total_amount) AS amount
    FROM sales s LEFT JOIN users u ON u.id = s.user_id $whereSql
    GROUP BY u.id, u.name ORDER BY amount DESC
");
$stmt->execute($params);
$byStaff = $stmt->fetchAll();

$staffList = $pdo->query("SELECT id, name FROM users ORDER BY name")->fetchAll();

// Totals for the last 12 months that had sales, for comparing
$pastMonths = $pdo->query("
    SELECT DATE_FORMAT(sale_date, '%Y-%m') AS ym, COUNT(*) AS sales, SUM(total_amount) AS amount
    FROM sales GROUP BY ym ORDER BY ym DESC LIMIT 12
")->fetchAll();

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;
$total = $stats['sales'];
$stmt = $pdo->prepare("SELECT s.*, u.name AS user_name FROM sales s LEFT JOIN users u ON u.id = s.user_id $whereSql ORDER BY s.sale_date DESC, s.id DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$sales = $stmt->fetchAll();

$saleItemsBySale = [];
$paymentsBySale = [];
if ($sales) {
    $ids = array_column($sales, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT si.*, p.name AS product_name, p.article_number, p.image FROM sale_items si JOIN products p ON p.id = si.product_id WHERE si.sale_id IN ($in)");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $row) $saleItemsBySale[$row['sale_id']][] = $row;

    $stmt = $pdo->prepare("SELECT sp.*, u.name AS user_name FROM sale_payments sp LEFT JOIN users u ON u.id = sp.user_id WHERE sp.sale_id IN ($in) ORDER BY sp.created_at");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $row) $paymentsBySale[$row['sale_id']][] = $row;
}

$selfUrl = 'sales.php' . q([]);

require 'includes/header.php';
?>
<div class="page-head">
  <h1>Sales</h1>
  <button class="btn btn-brand" onclick="openSale()"><i class="bi bi-cart-plus"></i> New Sale</button>
</div>

<a href="owing.php" class="card owing-card mb-3 text-decoration-none">
  <div class="card-body d-flex align-items-center gap-3 py-2">
    <div class="owing-icon"><i class="bi bi-hourglass-split"></i></div>
    <div class="flex-grow-1">
      <div class="label">Customers owing</div>
      <div class="fw-bold fs-5">₦<?= money($owingAll['amount']) ?> <span class="fw-normal fs-6 opacity-75">· <?= (int)$owingAll['people'] ?> <?= $owingAll['people'] == 1 ? 'person' : 'people' ?></span></div>
    </div>
    <i class="bi bi-chevron-right fs-4"></i>
  </div>
</a>

<div class="card mb-3">
  <div class="card-body d-flex align-items-center gap-2">
    <a class="btn btn-outline-dark" href="<?= q(['month' => $prevMonth, 'page' => null]) ?>" title="Previous month"><i class="bi bi-chevron-left"></i></a>
    <form method="get" class="flex-grow-1">
      <?php if ($status): ?><input type="hidden" name="status" value="owing"><?php endif; ?>
      <?php if ($by): ?><input type="hidden" name="by" value="<?= $by ?>"><?php endif; ?>
      <input type="month" name="month" class="form-control text-center fw-semibold" value="<?= $allTime ? '' : e($month) ?>" max="<?= date('Y-m') ?>" onchange="this.form.submit()">
    </form>
    <?php if ($isCurrentMonth): ?>
      <span class="btn btn-outline-dark disabled"><i class="bi bi-chevron-right"></i></span>
    <?php else: ?>
      <a class="btn btn-outline-dark" href="<?= q(['month' => $nextMonth, 'page' => null]) ?>" title="Next month"><i class="bi bi-chevron-right"></i></a>
    <?php endif; ?>
  </div>
  <div class="card-body pt-0 d-flex flex-wrap gap-2">
    <div class="btn-group">
      <a class="btn btn-sm <?= $status ? 'btn-outline-dark' : 'btn-dark' ?>" href="<?= q(['status' => null, 'page' => null]) ?>">All sales</a>
      <a class="btn btn-sm <?= $status ? 'btn-danger' : 'btn-outline-danger' ?>" href="<?= q(['status' => 'owing', 'page' => null]) ?>">Owing only</a>
    </div>
    <a class="btn btn-sm <?= $allTime ? 'btn-dark' : 'btn-outline-dark' ?>" href="<?= q(['month' => 'all', 'page' => null]) ?>">All time</a>
    <form method="get" class="ms-auto">
      <input type="hidden" name="month" value="<?= e($month) ?>">
      <?php if ($status): ?><input type="hidden" name="status" value="owing"><?php endif; ?>
      <select name="by" class="form-select form-select-sm" style="min-height:32px" onchange="this.form.submit()">
        <option value="">Sold by: everyone</option>
        <?php foreach ($staffList as $u): ?><option value="<?= $u['id'] ?>" <?= $by == $u['id'] ? 'selected' : '' ?>>Sold by: <?= e($u['name']) ?></option><?php endforeach; ?>
      </select>
    </form>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-12 col-md-3">
    <div class="card stat dark h-100">
      <div class="label">Sales for <?= e($periodLabel) ?></div>
      <div class="value">₦<?= money($stats['amount']) ?></div>
      <div class="sub"><?= (int)$stats['sales'] ?> sale<?= $stats['sales'] == 1 ? '' : 's' ?></div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card stat h-100">
      <div class="label">Money received</div>
      <div class="value text-success">₦<?= money($stats['paid']) ?></div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <a class="card stat h-100 text-decoration-none text-reset" href="<?= q(['status' => 'owing', 'page' => null]) ?>">
      <div class="label">Still owing</div>
      <div class="value text-danger">₦<?= money($stats['owing']) ?></div>
    </a>
  </div>
  <div class="col-12 col-md-3">
    <div class="card stat h-100">
      <div class="label">Pieces sold</div>
      <div class="value"><?= number_format($piecesSold) ?></div>
    </div>
  </div>
</div>
<?php if ($isCurrentMonth && !$status && !$by): ?>
  <p class="text-muted small mb-3"><i class="bi bi-calendar-day"></i> Sold today: <strong>₦<?= money($todayTotal) ?></strong></p>
<?php endif; ?>

<div class="card">
  <?php if (!$sales): ?><div class="empty">No <?= $status ? 'unpaid ' : '' ?>sales for <?= e($periodLabel) ?>.</div><?php endif; ?>
  <?php foreach ($sales as $s): $items = $saleItemsBySale[$s['id']] ?? []; $bal = balance_of($s); ?>
    <div class="list-row flex-wrap">
      <div class="min-w-0 flex-grow-1" role="button" data-bs-toggle="collapse" data-bs-target="#sale-<?= $s['id'] ?>">
        <div class="title">#<?= $s['id'] ?> · <?= e($s['customer_name'] ?? 'Walk-in') ?> <?= payment_badge($s) ?></div>
        <div class="meta"><?= date('d M Y, H:i', strtotime($s['sale_date'])) ?> · <?= count($items) ?> item<?= count($items) == 1 ? '' : 's' ?></div>
        <div class="meta"><i class="bi bi-person"></i> Sold by <strong><?= e($s['user_name'] ?? 'Unknown') ?></strong></div>
      </div>
      <div class="end" role="button" data-bs-toggle="collapse" data-bs-target="#sale-<?= $s['id'] ?>">
        <div class="fw-semibold">₦<?= money($s['total_amount']) ?> <i class="bi bi-chevron-down small text-muted"></i></div>
        <?php if ($bal > 0): ?><div class="small text-danger">owes ₦<?= money($bal) ?></div><?php endif; ?>
      </div>
      <div class="collapse w-100" id="sale-<?= $s['id'] ?>">
        <div class="mt-2 pt-2 border-top">
          <?php foreach ($items as $it): ?>
            <div class="d-flex justify-content-between align-items-center gap-2 small py-1">
              <?php if ($it['image']): ?><img class="thumb thumb-sm" src="<?= e($it['image']) ?>" alt=""><?php endif; ?>
              <span class="flex-grow-1"><?= e($it['product_name']) ?> <code><?= e($it['article_number']) ?></code><br>
                <span class="text-muted"><?= sale_line_label($it['sale_type'], $it['bundle_count']) ?> @ ₦<?= money($it['unit_price']) ?></span></span>
              <span class="fw-semibold">₦<?= money($it['subtotal']) ?></span>
            </div>
          <?php endforeach; ?>
          <div class="pay-box mt-2">
            <div class="d-flex justify-content-between small"><span>Paid so far</span><strong>₦<?= money($s['amount_paid']) ?></strong></div>
            <?php foreach ($paymentsBySale[$s['id']] ?? [] as $pm): ?>
              <div class="d-flex justify-content-between small text-muted"><span><?= date('d M, H:i', strtotime($pm['created_at'])) ?> · taken by <?= e($pm['user_name'] ?? 'Unknown') ?></span><span>₦<?= money($pm['amount']) ?></span></div>
            <?php endforeach; ?>
            <?php if ($bal > 0): ?>
              <div class="d-flex justify-content-between small text-danger fw-semibold mt-1"><span>Balance owing</span><span>₦<?= money($bal) ?></span></div>
              <form method="post" class="d-flex gap-2 mt-2">
                <input type="hidden" name="action" value="pay">
                <input type="hidden" name="sale_id" value="<?= $s['id'] ?>">
                <input type="hidden" name="back" value="<?= e($selfUrl) ?>">
                <input type="number" inputmode="decimal" step="0.01" min="0.01" max="<?= $bal ?>" name="amount" class="form-control" value="<?= $bal ?>" required>
                <button class="btn btn-success text-nowrap"><i class="bi bi-cash"></i> Record payment</button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<?php $totalPages = ceil($total / $perPage); if ($totalPages > 1): ?>
<nav class="mt-3"><ul class="pagination justify-content-center flex-wrap">
  <?php for ($i = 1; $i <= $totalPages; $i++): ?>
    <li class="page-item <?= $i == $page ? 'active' : '' ?>"><a class="page-link" href="<?= q(['page' => $i]) ?>"><?= $i ?></a></li>
  <?php endfor; ?>
</ul></nav>
<?php endif; ?>

<?php if (count($byStaff) > 0 && !$by): ?>
<div class="card mt-3">
  <div class="card-header"><i class="bi bi-people"></i> Who sold what — <?= e($periodLabel) ?></div>
  <?php foreach ($byStaff as $st): ?>
    <a class="list-row text-decoration-none text-reset" href="<?= q(['by' => $st['id'], 'page' => null]) ?>">
      <div>
        <div class="title"><?= e($st['name']) ?></div>
        <div class="meta"><?= (int)$st['sales'] ?> sale<?= $st['sales'] == 1 ? '' : 's' ?></div>
      </div>
      <div class="end fw-semibold">₦<?= money($st['amount']) ?> <i class="bi bi-chevron-right small text-muted"></i></div>
    </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($pastMonths): ?>
<div class="card mt-3">
  <div class="card-header"><i class="bi bi-calendar3"></i> Monthly totals</div>
  <?php foreach ($pastMonths as $m): ?>
    <a class="list-row text-decoration-none text-reset <?= $m['ym'] === $month ? 'bg-light' : '' ?>" href="?month=<?= e($m['ym']) ?>">
      <div>
        <div class="title"><?= date('F Y', strtotime($m['ym'] . '-01')) ?></div>
        <div class="meta"><?= (int)$m['sales'] ?> sale<?= $m['sales'] == 1 ? '' : 's' ?></div>
      </div>
      <div class="end fw-semibold">₦<?= money($m['amount']) ?> <i class="bi bi-chevron-right small text-muted"></i></div>
    </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="modal fade" id="saleModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-fullscreen-sm-down modal-dialog-scrollable">
    <div class="modal-content">
      <form method="post" onsubmit="return checkSale()">
        <input type="hidden" name="action" value="checkout">
        <div class="modal-header"><h5 class="modal-title">New Sale <span class="small text-muted fw-normal">· by <?= e(current_user_name()) ?></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <?php if (!$products): ?>
            <div class="alert alert-warning mb-0">No products in stock. Add stock on the <a href="products.php">Products</a> page first.</div>
          <?php else: ?>
          <div class="mb-3"><label class="form-label">Customer name</label><input class="form-control" name="customer_name" id="customerName" placeholder="Walk-in"></div>
          <div id="saleLines"></div>
          <button type="button" class="btn btn-outline-dark w-100" onclick="addSaleLine()"><i class="bi bi-plus-lg"></i> Add another item</button>

          <div class="pay-box mt-3">
            <label class="form-label">Amount paid now (₦)</label>
            <div class="d-flex gap-2">
              <input type="number" inputmode="decimal" step="0.01" min="0" class="form-control" name="amount_paid" id="amountPaid" oninput="this.dataset.touched=1; calcTotal()">
              <button type="button" class="btn btn-outline-success text-nowrap" onclick="setPaid('full')">Paid in full</button>
              <button type="button" class="btn btn-outline-danger text-nowrap" onclick="setPaid(0)">Not paid</button>
            </div>
            <div class="small mt-2" id="balanceNote"></div>
          </div>
          <?php endif; ?>
        </div>
        <div class="modal-footer justify-content-between">
          <div>Total <span class="sale-total">₦<span id="saleTotal">0.00</span></span></div>
          <div class="d-flex gap-2">
            <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
            <button class="btn btn-brand px-4" <?= $products ? '' : 'disabled' ?>>Complete Sale</button>
          </div>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
const availProducts = <?= json_encode($products) ?>;
let saleTotal = 0;
function esc(s) { const d = document.createElement('div'); d.innerText = s ?? ''; return d.innerHTML; }
function fmt(n) { return n.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}); }
function openSale() {
  const lines = document.getElementById('saleLines');
  if (lines && !lines.children.length) addSaleLine();
  bootstrap.Modal.getOrCreateInstance(document.getElementById('saleModal')).show();
}
function addSaleLine() {
  const options = availProducts.map(p =>
    `<option value="${p.id}">${esc(p.name)} (${esc(p.article_number)})${p.size || p.color ? ' · ' + esc([p.size, p.color].filter(Boolean).join(' ')) : ''} — ${esc(p.stock_label)}</option>`
  ).join('');
  const line = document.createElement('div');
  line.className = 'sale-line';
  line.innerHTML = `
    <div class="d-flex gap-2 mb-2 align-items-center">
      <div class="lineThumb"></div>
      <select class="form-select prodSelect" name="product_id[]" onchange="updateLine(this.closest('.sale-line'))">${options}</select>
      <button type="button" class="btn btn-outline-danger" title="Remove" onclick="this.closest('.sale-line').remove(); calcTotal();"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="row g-2 align-items-end">
      <div class="col-12 col-sm-4">
        <label class="form-label small">Sold as</label>
        <select class="form-select typeSelect" name="sale_type[]" onchange="updateLine(this.closest('.sale-line'))">
          <option value="bundle">Bundle</option>
          <option value="piece">Single pieces</option>
        </select>
      </div>
      <div class="col-5 col-sm-3">
        <label class="form-label small countLabel">How many bundles</label>
        <input type="number" inputmode="numeric" min="1" class="form-control countInput" name="count[]" value="1" oninput="calcTotal()">
      </div>
      <div class="col-7 col-sm-5">
        <label class="form-label small priceLabel">Price per bundle (₦)</label>
        <input type="number" inputmode="decimal" step="0.01" min="0" class="form-control priceInput" name="unit_price[]" oninput="calcTotal()">
      </div>
    </div>
    <div class="d-flex justify-content-between small mt-2">
      <span class="text-muted stockNote"></span>
      <span>Subtotal: <strong>₦<span class="lineSubtotal">0.00</span></strong></span>
    </div>`;
  document.getElementById('saleLines').appendChild(line);
  updateLine(line);
}
function productOf(line) {
  const id = line.querySelector('.prodSelect').value;
  return availProducts.find(p => String(p.id) === id);
}
function updateLine(line) {
  const p = productOf(line);
  if (!p) return;
  const piece = line.querySelector('.typeSelect').value === 'piece';
  line.querySelector('.priceInput').value = parseFloat(piece ? p.piece_price : p.bundle_price).toFixed(2);
  line.querySelector('.countLabel').innerText = piece ? 'How many pieces' : 'How many bundles';
  line.querySelector('.priceLabel').innerText = piece ? 'Price per piece (₦)' : 'Price per bundle (₦)';
  line.querySelector('.lineThumb').innerHTML = p.image ? `<img class="thumb" src="${esc(p.image)}" alt="">` : '';
  calcTotal();
}
function calcTotal() {
  let total = 0;
  const used = {};
  document.querySelectorAll('#saleLines .sale-line').forEach(line => {
    const price = parseFloat(line.querySelector('.priceInput').value || 0);
    const count = parseInt(line.querySelector('.countInput').value || 0);
    const sub = price * count;
    line.querySelector('.lineSubtotal').innerText = fmt(sub);
    const p = productOf(line);
    if (p) {
      const pieces = line.querySelector('.typeSelect').value === 'bundle' ? p.pieces_per_bundle * count : count;
      used[p.id] = (used[p.id] || 0) + pieces;
      const enough = used[p.id] <= p.quantity;
      line.querySelector('.stockNote').innerHTML = enough
        ? 'In stock: ' + esc(p.stock_label) + ' · this line takes ' + pieces + ' pcs'
        : '<span class="text-danger">Not enough stock (' + esc(p.stock_label) + ')</span>';
    }
    total += sub;
  });
  saleTotal = total;
  document.getElementById('saleTotal').innerText = fmt(total);
  const paid = document.getElementById('amountPaid');
  if (paid) {
    if (!paid.dataset.touched) paid.value = total.toFixed(2);
    const bal = total - parseFloat(paid.value || 0);
    document.getElementById('balanceNote').innerHTML = bal > 0.005
      ? '<span class="text-danger fw-semibold"><i class="bi bi-exclamation-circle"></i> Customer will owe ₦' + fmt(bal) + '</span> — this sale will show as owing until it is paid.'
      : '<span class="text-success"><i class="bi bi-check-circle"></i> Fully paid</span>';
  }
}
function setPaid(v) {
  const paid = document.getElementById('amountPaid');
  paid.value = (v === 'full' ? saleTotal : v).toFixed(2);
  paid.dataset.touched = 1;
  calcTotal();
}
function checkSale() {
  if (!document.querySelectorAll('#saleLines .sale-line').length) { addSaleLine(); return false; }
  const bal = saleTotal - parseFloat(document.getElementById('amountPaid').value || 0);
  const name = document.getElementById('customerName');
  if (bal > 0.005 && !name.value.trim()) {
    alert("This sale is not fully paid. Enter the customer's name so you know who is owing.");
    name.focus();
    return false;
  }
  return true;
}
<?php if (isset($_GET['new'])): ?>
document.addEventListener('DOMContentLoaded', openSale);
<?php endif; ?>
</script>
<?php require 'includes/footer.php'; ?>
