<?php
require 'includes/auth.php';
require_login();
require 'config/db.php';
require 'includes/functions.php';
$page_title = 'Settings';

$me = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$me->execute([current_user_id()]);
$me = $me->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'branding') {
        require_admin();
        $storeName = trim($_POST['store_name'] ?? '');
        if ($storeName !== '') save_setting($pdo, 'store_name', $storeName);

        if (!empty($_POST['remove_logo'])) {
            save_setting($pdo, 'logo', '');
        } elseif (!empty($_FILES['logo']['name'])) {
            $types = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif'];
            $info = @getimagesize($_FILES['logo']['tmp_name']);
            if ($_FILES['logo']['error'] !== UPLOAD_ERR_OK || !$info || !isset($types[$info['mime']])) {
                flash('danger', 'Logo must be a PNG, JPG, WEBP or GIF image.');
                redirect('settings.php');
            }
            if ($_FILES['logo']['size'] > 2 * 1024 * 1024) {
                flash('danger', 'Logo must be smaller than 2 MB.');
                redirect('settings.php');
            }
            $dir = __DIR__ . '/uploads';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $file = 'logo_' . time() . '.' . $types[$info['mime']];
            if (!move_uploaded_file($_FILES['logo']['tmp_name'], "$dir/$file")) {
                flash('danger', 'Could not save the logo on the server.');
                redirect('settings.php');
            }
            save_setting($pdo, 'logo', "uploads/$file");
        }
        flash('success', 'Store details saved.');
        redirect('settings.php');
    }

    if ($action === 'account') {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $newPass = $_POST['new_password'] ?? '';
        $email = strtolower($email);
        if ($name === '' || !(filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/^[a-z0-9._-]{3,30}$/', $email))) {
            flash('danger', 'Enter your name and a valid username (no spaces) or email.');
            redirect('settings.php');
        }
        if (!password_verify($_POST['current_password'] ?? '', $me['password'])) {
            flash('danger', 'Your current password is wrong. Nothing was changed.');
            redirect('settings.php');
        }
        if ($newPass !== '' && (strlen($newPass) < 6 || $newPass !== ($_POST['confirm_password'] ?? ''))) {
            flash('danger', 'New password must be at least 6 characters and match the confirmation.');
            redirect('settings.php');
        }
        try {
            $pdo->prepare("UPDATE users SET name = ?, email = ? WHERE id = ?")->execute([$name, $email, $me['id']]);
            if ($newPass !== '') {
                $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([password_hash($newPass, PASSWORD_DEFAULT), $me['id']]);
            }
            $_SESSION['name'] = $name;
            flash('success', $newPass !== '' ? 'Account and password updated.' : 'Account updated.');
        } catch (PDOException $e) {
            flash('danger', $e->getCode() == 23000 ? 'That username is already used by another account.' : 'Could not save: ' . $e->getMessage());
        }
        redirect('settings.php');
    }
}

$storeName = setting($pdo, 'store_name', 'Jekwus Luxury Fashion');
$logo = setting($pdo, 'logo');

require 'includes/header.php';
?>
<div class="page-head"><h1>Settings</h1></div>

<div class="row g-3">
  <?php if (is_admin()): ?>
  <div class="col-12">
    <a href="users.php" class="card text-decoration-none text-reset">
      <div class="card-body d-flex align-items-center gap-3">
        <i class="bi bi-people fs-3"></i>
        <div class="flex-grow-1"><div class="fw-semibold">Staff accounts</div><div class="small text-muted">Add sales girls, change their passwords, see who sold what</div></div>
        <i class="bi bi-chevron-right"></i>
      </div>
    </a>
  </div>
  <?php endif; ?>
  <?php if (is_admin()): ?>
  <div class="col-lg-6">
    <div class="card">
      <div class="card-header"><i class="bi bi-shop"></i> Store &amp; logo</div>
      <div class="card-body">
        <form method="post" enctype="multipart/form-data" class="d-grid gap-3">
          <input type="hidden" name="action" value="branding">
          <div>
            <label class="form-label">Store name</label>
            <input class="form-control" name="store_name" value="<?= e($storeName) ?>" required>
          </div>
          <div>
            <label class="form-label">Logo</label>
            <div class="d-flex align-items-center gap-3 mb-2">
              <div class="rounded p-2" style="background:#12100e">
                <?php if ($logo): ?><img src="<?= e($logo) ?>" alt="Current logo" style="height:48px;max-width:160px;object-fit:contain">
                <?php else: ?><i class="bi bi-gem fs-3" style="color:#c9a24b"></i><?php endif; ?>
              </div>
              <?php if ($logo): ?>
                <div class="form-check"><input class="form-check-input" type="checkbox" name="remove_logo" value="1" id="rmLogo"><label class="form-check-label" for="rmLogo">Remove logo</label></div>
              <?php endif; ?>
            </div>
            <input type="file" class="form-control" name="logo" accept="image/png,image/jpeg,image/webp,image/gif">
            <div class="form-text">PNG with a transparent background looks best. Max 2 MB.</div>
          </div>
          <button class="btn btn-brand">Save store details</button>
        </form>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="col-lg-6">
    <div class="card">
      <div class="card-header"><i class="bi bi-person-circle"></i> My account <span class="badge bg-secondary ms-1"><?= e($me['role']) ?></span></div>
      <div class="card-body">
        <form method="post" class="d-grid gap-3" autocomplete="off">
          <input type="hidden" name="action" value="account">
          <div><label class="form-label">Name</label><input class="form-control" name="name" value="<?= e($me['name']) ?>" required></div>
          <div><label class="form-label">Username or email (used to log in)</label><input type="text" class="form-control" name="email" value="<?= e($me['email']) ?>" required></div>
          <div class="row g-2">
            <div class="col-sm-6"><label class="form-label">New password</label><input type="password" class="form-control" name="new_password" minlength="6" autocomplete="new-password" placeholder="Leave blank to keep"></div>
            <div class="col-sm-6"><label class="form-label">Confirm new password</label><input type="password" class="form-control" name="confirm_password" autocomplete="new-password"></div>
          </div>
          <div><label class="form-label">Current password</label><input type="password" class="form-control" name="current_password" required autocomplete="current-password"><div class="form-text">Needed to save any change here.</div></div>
          <button class="btn btn-brand">Save account</button>
        </form>
      </div>
    </div>
  </div>

</div>
<?php require 'includes/footer.php'; ?>
