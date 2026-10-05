<?php
require 'includes/auth.php';
require_login();
require 'config/db.php';
require 'includes/functions.php';
$page_title = 'Suppliers';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'] ?? null;
    $name = trim($_POST['name']);
    $contact = trim($_POST['contact_person']);
    $phone = trim($_POST['phone']);
    $email = trim($_POST['email']);
    $address = trim($_POST['address']);

    if ($id) {
        $stmt = $pdo->prepare("UPDATE suppliers SET name=?, contact_person=?, phone=?, email=?, address=? WHERE id=?");
        $stmt->execute([$name,$contact,$phone,$email,$address,$id]);
        flash('success', 'Supplier updated.');
    } else {
        $stmt = $pdo->prepare("INSERT INTO suppliers (name, contact_person, phone, email, address) VALUES (?,?,?,?,?)");
        $stmt->execute([$name,$contact,$phone,$email,$address]);
        flash('success', 'Supplier added.');
    }
    redirect('suppliers.php');
}

if (isset($_GET['delete'])) {
    require_admin();
    $stmt = $pdo->prepare("DELETE FROM suppliers WHERE id = ?");
    $stmt->execute([$_GET['delete']]);
    flash('success', 'Supplier deleted.');
    redirect('suppliers.php');
}

$editSupplier = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM suppliers WHERE id = ?");
    $stmt->execute([$_GET['edit']]);
    $editSupplier = $stmt->fetch();
}

$suppliers = $pdo->query("SELECT * FROM suppliers ORDER BY name")->fetchAll();
require 'includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0">Suppliers</h4>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#supplierModal" onclick="resetForm()"><i class="bi bi-plus-lg"></i> Add Supplier</button>
</div>

<table class="table table-striped bg-white shadow-sm">
  <thead><tr><th>Name</th><th>Contact</th><th>Phone</th><th>Email</th><th>Actions</th></tr></thead>
  <tbody>
  <?php foreach ($suppliers as $s): ?>
    <tr>
      <td><?= htmlspecialchars($s['name']) ?></td>
      <td><?= htmlspecialchars($s['contact_person'] ?? '-') ?></td>
      <td><?= htmlspecialchars($s['phone'] ?? '-') ?></td>
      <td><?= htmlspecialchars($s['email'] ?? '-') ?></td>
      <td>
        <button class="btn btn-sm btn-outline-secondary" onclick='editSupplier(<?= json_encode($s) ?>)'><i class="bi bi-pencil"></i></button>
        <?php if (is_admin()): ?><a href="?delete=<?= $s['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete supplier?')"><i class="bi bi-trash"></i></a><?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$suppliers): ?><tr><td colspan="5" class="text-center text-muted py-3">No suppliers yet.</td></tr><?php endif; ?>
  </tbody>
</table>

<div class="modal fade" id="supplierModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="id" id="f_id">
        <div class="modal-header"><h5 id="modalTitle">Add Supplier</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="mb-2"><label class="form-label">Name</label><input class="form-control" name="name" id="f_name" required></div>
          <div class="mb-2"><label class="form-label">Contact Person</label><input class="form-control" name="contact_person" id="f_contact"></div>
          <div class="mb-2"><label class="form-label">Phone</label><input class="form-control" name="phone" id="f_phone"></div>
          <div class="mb-2"><label class="form-label">Email</label><input type="email" class="form-control" name="email" id="f_email"></div>
          <div class="mb-2"><label class="form-label">Address</label><textarea class="form-control" name="address" id="f_address" rows="2"></textarea></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save</button></div>
      </form>
    </div>
  </div>
</div>
<script>
function resetForm(){document.getElementById('modalTitle').innerText='Add Supplier';document.querySelector('#supplierModal form').reset();document.getElementById('f_id').value='';}
function editSupplier(s){
  document.getElementById('modalTitle').innerText='Edit Supplier';
  document.getElementById('f_id').value=s.id;
  document.getElementById('f_name').value=s.name;
  document.getElementById('f_contact').value=s.contact_person||'';
  document.getElementById('f_phone').value=s.phone||'';
  document.getElementById('f_email').value=s.email||'';
  document.getElementById('f_address').value=s.address||'';
  new bootstrap.Modal(document.getElementById('supplierModal')).show();
}
<?php if ($editSupplier): ?>document.addEventListener('DOMContentLoaded',()=>editSupplier(<?= json_encode($editSupplier) ?>));<?php endif; ?>
</script>
<?php require 'includes/footer.php'; ?>
