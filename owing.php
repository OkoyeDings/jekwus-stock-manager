<?php
require 'includes/auth.php';
require_login();
require 'config/db.php';
require 'includes/functions.php';
$page_title = 'Owing';

// Every sale that still has a balance, grouped by customer name.
$rows = $pdo->query("
    SELECT s.*, u.name AS user_name
    FROM sales s LEFT JOIN users u ON u.id = s.user_id
    WHERE s.amount_paid < s.total_amount
    ORDER BY s.sale_date ASC
")->fetchAll();

$customers = [];
foreach ($rows as $s) {
    $key = strtolower(trim($s['customer_name'] ?? '')) ?: '(no name)';
    if (!isset($customers[$key])) {
        $customers[$key] = ['name' => $s['customer_name'] ?: 'No name', 'owed' => 0, 'sales' => [], 'oldest' => $s['sale_date']];
    }
    $c = &$customers[$key];
    $c['owed'] += balance_of($s);
    $c['sales'][] = $s;
    unset($c);
}
uasort($customers, fn($a, $b) => $b['owed'] <=> $a['owed']);
$totalOwed = array_sum(array_column($customers, 'owed'));
$peopleOwing = count($customers);

$search = trim($_GET['q'] ?? '');
if ($search !== '') {
    $customers = array_filter($customers, fn($c) => stripos($c['name'], $search) !== false);
}

require 'includes/header.php';
?>
<div class="page-head">
  <h1><a href="sales.php" class="text-reset text-decoration-none" title="Back to Sales"><i class="bi bi-chevron-left"></i></a> Customers Owing</h1>
  <a href="sales.php?status=owing&month=all" class="btn btn-outline-dark btn-sm">View as sales list</a>
</div>

<div class="row g-3 mb-3">
  <div class="col-6">
    <div class="card stat dark h-100">
      <div class="label">Total owed to you</div>
      <div class="value">₦<?= money($totalOwed) ?></div>
    </div>
  </div>
  <div class="col-6">
    <div class="card stat h-100">
      <div class="label">People owing</div>
      <div class="value text-danger"><?= $peopleOwing ?></div>
      <div class="sub"><?= count($rows) ?> unpaid sale<?= count($rows) == 1 ? '' : 's' ?></div>
    </div>
  </div>
</div>

<form method="get" class="mb-3">
  <div class="input-group">
    <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
    <input type="search" name="q" class="form-control" placeholder="Search customer name" value="<?= e($search) ?>">
  </div>
</form>

<div class="card">
  <?php if (!$customers): ?><div class="empty"><?= $search ? 'No customer matches your search.' : 'Nobody is owing you. 🎉' ?></div><?php endif; ?>
  <?php $i = 0; foreach ($customers as $c): $i++; $days = (int)floor((time() - strtotime($c['oldest'])) / 86400); ?>
    <div class="list-row flex-wrap">
      <div class="min-w-0 flex-grow-1" role="button" data-bs-toggle="collapse" data-bs-target="#cust-<?= $i ?>">
        <div class="title"><?= e($c['name']) ?></div>
        <div class="meta">
          <?= count($c['sales']) ?> unpaid sale<?= count($c['sales']) == 1 ? '' : 's' ?> · oldest <?= $days == 0 ? 'today' : "$days day" . ($days == 1 ? '' : 's') . ' ago' ?>
        </div>
      </div>
      <div class="end fw-bold text-danger" role="button" data-bs-toggle="collapse" data-bs-target="#cust-<?= $i ?>">₦<?= money($c['owed']) ?> <i class="bi bi-chevron-down small text-muted"></i></div>
      <div class="collapse w-100" id="cust-<?= $i ?>">
        <?php foreach ($c['sales'] as $s): $bal = balance_of($s); ?>
          <div class="pay-box mt-2">
            <div class="d-flex justify-content-between small">
              <span><a href="sales.php?month=<?= date('Y-m', strtotime($s['sale_date'])) ?>&status=owing">Sale #<?= $s['id'] ?></a> · <?= date('d M Y', strtotime($s['sale_date'])) ?> · by <?= e($s['user_name'] ?? 'Unknown') ?></span>
              <span>₦<?= money($s['total_amount']) ?></span>
            </div>
            <div class="d-flex justify-content-between small text-muted"><span>Paid</span><span>₦<?= money($s['amount_paid']) ?></span></div>
            <div class="d-flex justify-content-between small text-danger fw-semibold"><span>Owing</span><span>₦<?= money($bal) ?></span></div>
            <form method="post" action="sales.php" class="d-flex gap-2 mt-2">
              <input type="hidden" name="action" value="pay">
              <input type="hidden" name="sale_id" value="<?= $s['id'] ?>">
              <input type="hidden" name="back" value="owing.php">
              <input type="number" inputmode="decimal" step="0.01" min="0.01" max="<?= $bal ?>" name="amount" class="form-control" value="<?= $bal ?>" required>
              <button class="btn btn-success text-nowrap"><i class="bi bi-cash"></i> Record payment</button>
            </form>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php require 'includes/footer.php'; ?>
