<?php
require 'includes/auth.php';
require_admin();
require 'config/db.php';
require 'includes/functions.php';
$page_title = 'Staff';

// A login is either a simple username (e.g. "blessing") or an email address.
// Both live in the users.email column, which is what login.php looks up.
function clean_login($s) {
    $s = strtolower(trim($s));
    if (filter_var($s, FILTER_VALIDATE_EMAIL)) return $s;
    return preg_match('/^[a-z0-9._-]{3,30}$/', $s) ? $s : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        $login = clean_login($_POST['login'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'staff';
        if ($name === '' || !$login) {
            flash('danger', 'Enter a name and a username of 3–30 letters or numbers (no spaces), e.g. blessing.');
        } elseif (strlen($password) < 6) {
            flash('danger', 'Password must be at least 6 characters.');
        } else {
            try {
                $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?,?,?,?)")
                    ->execute([$name, $login, password_hash($password, PASSWORD_DEFAULT), $role]);
                flash('success', "Account created for $name. She logs in with username \"$login\" and the password you set.");
            } catch (PDOException $e) {
                flash('danger', $e->getCode() == 23000 ? "The username \"$login\" is already taken — try another." : 'Could not save: ' . $e->getMessage());
            }
        }
        redirect('users.php');
    }

    if ($action === 'reset_password' && $id) {
        $password = $_POST['password'] ?? '';
        if (strlen($password) < 6) {
            flash('danger', 'Password must be at least 6 characters.');
        } else {
            $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
            flash('success', 'Password changed.');
        }
        redirect('users.php');
    }

    // Disable rather than delete, so past sales still show who made them.
    if ($action === 'disable' && $id && $id != current_user_id()) {
        $pdo->prepare("UPDATE users SET active = 0 WHERE id = ?")->execute([$id]);
        flash('success', 'Account disabled. She can no longer log in, but her sales stay on record.');
        redirect('users.php');
    }

    if ($action === 'enable' && $id) {
        $pdo->prepare("UPDATE users SET active = 1 WHERE id = ?")->execute([$id]);
        flash('success', 'Account can log in again.');
        redirect('users.php');
    }
    redirect('users.php');
}

$monthStart = date('Y-m-01 00:00:00');
$stmt = $pdo->prepare("
    SELECT u.*,
           COUNT(s.id) AS month_sales,
           COALESCE(SUM(s.total_amount),0) AS month_amount,
           (SELECT MAX(sale_date) FROM sales WHERE user_id = u.id) AS last_sale
    FROM users u LEFT JOIN sales s ON s.user_id = u.id AND s.sale_date >= ?
    GROUP BY u.id ORDER BY u.active DESC, u.role DESC, u.name
");
$stmt->execute([$monthStart]);
$users = $stmt->fetchAll();

require 'includes/header.php';
?>
<div class="page-head">
  <h1><a href="settings.php" class="text-reset text-decoration-none" title="Back to Settings"><i class="bi bi-chevron-left"></i></a> Staff</h1>
</div>

<div class="row g-3">
  <div class="col-lg-5">
    <div class="card">
      <div class="card-header"><i class="bi bi-person-plus"></i> Add a sales girl</div>
      <div class="card-body">
        <p class="small text-muted">Each sales girl gets her own login. Every sale she records will show <strong>“Sold by”</strong> with her name.</p>
        <form method="post" class="d-grid gap-3" autocomplete="off">
          <input type="hidden" name="action" value="add">
          <div><label class="form-label">Her name</label><input class="form-control" name="name" placeholder="e.g. Blessing" required></div>
          <div>
            <label class="form-label">Username (for logging in)</label>
            <input class="form-control" name="login" placeholder="e.g. blessing" required autocapitalize="none" autocorrect="off" spellcheck="false" pattern="[A-Za-z0-9._@\-]{3,}" oninput="this.value=this.value.replace(/\s/g,'').toLowerCase()">
            <div class="form-text">One word, no spaces.</div>
          </div>
          <div>
            <label class="form-label">Password</label>
            <div class="input-group">
              <input type="password" class="form-control" name="password" id="newPass" minlength="6" required autocomplete="new-password">
              <button type="button" class="btn btn-outline-secondary" onclick="togglePass('newPass', this)"><i class="bi bi-eye"></i></button>
            </div>
            <div class="form-text">At least 6 characters. Give it to her privately.</div>
          </div>
          <div>
            <label class="form-label">Account type</label>
            <select class="form-select" name="role">
              <option value="staff">Sales staff</option>
              <option value="admin">Admin (full access)</option>
            </select>
          </div>
          <button class="btn btn-brand py-2"><i class="bi bi-person-plus"></i> Create account</button>
        </form>
      </div>
    </div>

    <div class="card mt-3">
      <div class="card-body small">
        <div class="fw-semibold mb-1">What sales staff can do</div>
        <div><i class="bi bi-check-lg text-success"></i> Record sales and payments</div>
        <div><i class="bi bi-check-lg text-success"></i> Add and edit products and stock</div>
        <div><i class="bi bi-check-lg text-success"></i> See who is owing</div>
        <div class="mt-1"><i class="bi bi-x-lg text-danger"></i> Delete products</div>
        <div><i class="bi bi-x-lg text-danger"></i> Change store settings or manage staff</div>
      </div>
    </div>
  </div>

  <div class="col-lg-7">
    <div class="card">
      <div class="card-header"><i class="bi bi-people"></i> All accounts</div>
      <?php foreach ($users as $u): $me = $u['id'] == current_user_id(); ?>
        <div class="list-row flex-wrap <?= $u['active'] ? '' : 'opacity-50' ?>">
          <div class="min-w-0 flex-grow-1">
            <div class="title">
              <?= e($u['name']) ?>
              <span class="badge bg-<?= $u['role'] === 'admin' ? 'dark' : 'secondary' ?>"><?= $u['role'] === 'admin' ? 'admin' : 'sales staff' ?></span>
              <?= $u['active'] ? '' : '<span class="badge bg-light text-dark border">disabled</span>' ?>
              <?= $me ? '<span class="meta">(you)</span>' : '' ?>
            </div>
            <div class="meta"><i class="bi bi-person-badge"></i> Login: <strong><?= e($u['email']) ?></strong></div>
            <div class="meta">
              This month: <a href="sales.php?by=<?= $u['id'] ?>"><?= (int)$u['month_sales'] ?> sale<?= $u['month_sales'] == 1 ? '' : 's' ?> · ₦<?= money($u['month_amount']) ?></a>
              <?= $u['last_sale'] ? ' · last sale ' . date('d M', strtotime($u['last_sale'])) : '' ?>
            </div>
          </div>
          <div class="d-flex gap-2">
            <button class="btn btn-sm btn-outline-dark" data-bs-toggle="collapse" data-bs-target="#pw-<?= $u['id'] ?>" title="Change password"><i class="bi bi-key"></i></button>
            <?php if (!$me && $u['active']): ?>
              <form method="post" data-msg="Disable <?= e($u['name']) ?>? She will no longer be able to log in. Her sales stay on record." onsubmit="return confirm(this.dataset.msg)">
                <input type="hidden" name="action" value="disable"><input type="hidden" name="id" value="<?= $u['id'] ?>">
                <button class="btn btn-sm btn-outline-danger" title="Disable"><i class="bi bi-person-x"></i></button>
              </form>
            <?php elseif (!$me): ?>
              <form method="post">
                <input type="hidden" name="action" value="enable"><input type="hidden" name="id" value="<?= $u['id'] ?>">
                <button class="btn btn-sm btn-outline-success">Enable</button>
              </form>
            <?php endif; ?>
          </div>
          <form method="post" class="collapse w-100" id="pw-<?= $u['id'] ?>" autocomplete="off">
            <input type="hidden" name="action" value="reset_password"><input type="hidden" name="id" value="<?= $u['id'] ?>">
            <div class="d-flex gap-2 mt-2">
              <input type="password" class="form-control" name="password" minlength="6" required placeholder="New password for <?= e($u['name']) ?>" autocomplete="new-password">
              <button class="btn btn-brand text-nowrap">Save</button>
            </div>
          </form>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<script>
function togglePass(id, btn) {
  const i = document.getElementById(id);
  i.type = i.type === 'password' ? 'text' : 'password';
  btn.innerHTML = i.type === 'password' ? '<i class="bi bi-eye"></i>' : '<i class="bi bi-eye-slash"></i>';
}
</script>
<?php require 'includes/footer.php'; ?>
