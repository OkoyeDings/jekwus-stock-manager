<?php
require 'includes/auth.php';
require_login();
require 'config/db.php';
require 'includes/functions.php';
$page_title = 'Categories';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'add';
    $name = trim($_POST['name'] ?? '');
    try {
        if ($action === 'add' && $name !== '') {
            $pdo->prepare("INSERT INTO categories (name) VALUES (?)")->execute([$name]);
            flash('success', "Category \"$name\" added.");
        } elseif ($action === 'rename' && $name !== '') {
            $pdo->prepare("UPDATE categories SET name = ? WHERE id = ?")->execute([$name, (int)$_POST['id']]);
            flash('success', 'Category renamed.');
        } elseif ($action === 'delete') {
            require_admin();
            $pdo->prepare("DELETE FROM categories WHERE id = ?")->execute([(int)$_POST['id']]);
            flash('success', 'Category deleted. Its products are now uncategorised.');
        }
    } catch (PDOException $e) {
        flash('danger', $e->getCode() == 23000 ? "A category called \"$name\" already exists." : 'Could not save: ' . $e->getMessage());
    }
    redirect('categories.php');
}

$categories = $pdo->query("
    SELECT c.*, COUNT(p.id) AS product_count
    FROM categories c LEFT JOIN products p ON p.category_id = c.id
    GROUP BY c.id ORDER BY c.name
")->fetchAll();

require 'includes/header.php';
?>
<div class="page-head"><h1>Categories</h1></div>

<div class="row justify-content-center">
  <div class="col-lg-7">
    <div class="card mb-3">
      <div class="card-body">
        <form method="post" class="d-flex gap-2">
          <input type="hidden" name="action" value="add">
          <input class="form-control" name="name" placeholder="New category name" required>
          <button class="btn btn-brand text-nowrap"><i class="bi bi-plus-lg"></i> Add</button>
        </form>
      </div>
    </div>

    <div class="card">
      <?php if (!$categories): ?><div class="empty">No categories yet.</div><?php endif; ?>
      <?php foreach ($categories as $c): ?>
        <div class="list-row flex-wrap">
          <div>
            <div class="title"><?= e($c['name']) ?></div>
            <a class="meta text-decoration-none" href="products.php?cat=<?= $c['id'] ?>"><?= (int)$c['product_count'] ?> product<?= $c['product_count'] == 1 ? '' : 's' ?> <i class="bi bi-chevron-right"></i></a>
          </div>
          <div class="end d-flex gap-2">
            <button class="btn btn-sm btn-outline-dark" data-bs-toggle="collapse" data-bs-target="#rename-<?= $c['id'] ?>"><i class="bi bi-pencil"></i> Rename</button>
            <?php if (is_admin()): ?>
              <form method="post" data-msg="Delete category &quot;<?= e($c['name']) ?>&quot;? Its products will be kept but become uncategorised." onsubmit="return confirm(this.dataset.msg)">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $c['id'] ?>">
                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
              </form>
            <?php endif; ?>
          </div>
          <form method="post" class="collapse w-100 mt-2" id="rename-<?= $c['id'] ?>">
            <input type="hidden" name="action" value="rename">
            <input type="hidden" name="id" value="<?= $c['id'] ?>">
            <div class="d-flex gap-2">
              <input class="form-control" name="name" value="<?= e($c['name']) ?>" required>
              <button class="btn btn-brand">Save</button>
            </div>
          </form>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php require 'includes/footer.php'; ?>
