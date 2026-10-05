<?php
require 'includes/auth.php';
require_login();
require 'config/db.php';
require 'includes/functions.php';
$page_title = 'Products';

// Handle Add / Edit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $id = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name']);
    $article_number = trim($_POST['article_number']);
    $category_id = $_POST['category_id'] ?: null;
    $size = trim($_POST['size']);
    $color = trim($_POST['color']);
    // Every bundle is 10 pieces. Existing products keep the size they were created with.
    $pieces_per_bundle = 10;
    if ($id) {
        $stmt = $pdo->prepare("SELECT pieces_per_bundle FROM products WHERE id = ?");
        $stmt->execute([$id]);
        $pieces_per_bundle = max(1, (int)($stmt->fetchColumn() ?: 10));
    }
    $bundle_price = (float)$_POST['bundle_price'];
    $piece_price = (float)$_POST['piece_price'];
    // Stock is entered as full bundles plus loose pieces, and stored as total pieces.
    $stockEntered = ($_POST['stock_bundles'] ?? '') !== '' || ($_POST['stock_pieces'] ?? '') !== '';
    $quantity = max(0, (int)($_POST['stock_bundles'] ?? 0)) * $pieces_per_bundle + max(0, (int)($_POST['stock_pieces'] ?? 0));

    try {
        $image = save_uploaded_image('image', 'product');

        if ($id) {
            $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
            $stmt->execute([$id]);
            $old = $stmt->fetch();
            if (!$old) redirect('products.php');

            if (!empty($_POST['remove_image'])) $newImage = null;
            else $newImage = $image ?? $old['image'];

            $stmt = $pdo->prepare("UPDATE products SET name=?, article_number=?, category_id=?, size=?, color=?, pieces_per_bundle=?, bundle_price=?, piece_price=?, image=? WHERE id=?");
            $stmt->execute([$name, $article_number, $category_id, $size, $color, $pieces_per_bundle, $bundle_price, $piece_price, $newImage, $id]);

            if ($stockEntered) {
                $diff = $quantity - (int)$old['quantity'];
                if ($diff != 0) {
                    record_stock_movement($pdo, $id, $diff > 0 ? 'in' : 'out', abs($diff), 'Stock updated on product page', null, current_user_id());
                }
            }
            flash('success', 'Product updated.');
        } else {
            $stmt = $pdo->prepare("INSERT INTO products (name, article_number, category_id, size, color, pieces_per_bundle, bundle_price, piece_price, quantity, image, created_by) VALUES (?,?,?,?,?,?,?,?,0,?,?)");
            $stmt->execute([$name, $article_number, $category_id, $size, $color, $pieces_per_bundle, $bundle_price, $piece_price, $image, current_user_id()]);
            $newId = $pdo->lastInsertId();
            if ($quantity > 0) {
                record_stock_movement($pdo, $newId, 'in', $quantity, 'Initial stock', null, current_user_id());
            }
            flash('success', 'Product added.');
        }
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            flash('danger', "Article number \"$article_number\" is already used by another product.");
        } else {
            flash('danger', 'Could not save the product: ' . $e->getMessage());
        }
    } catch (Exception $e) {
        flash('danger', $e->getMessage());
    }
    redirect('products.php');
}

// Handle Delete (admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    require_admin();
    $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
    $stmt->execute([(int)$_POST['id']]);
    flash('success', 'Product deleted.');
    redirect('products.php');
}

$search = trim($_GET['q'] ?? '');
$catFilter = (int)($_GET['cat'] ?? 0);
$sql = "SELECT p.*, c.name AS category_name, u.name AS added_by FROM products p LEFT JOIN categories c ON c.id = p.category_id LEFT JOIN users u ON u.id = p.created_by WHERE 1";
$params = [];
if ($search !== '') {
    $sql .= " AND (p.name LIKE ? OR p.article_number LIKE ? OR p.color LIKE ?)";
    array_push($params, "%$search%", "%$search%", "%$search%");
}
if ($catFilter) {
    $sql .= " AND p.category_id = ?";
    $params[] = $catFilter;
}
$sql .= " ORDER BY p.name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

$categories = $pdo->query("SELECT * FROM categories ORDER BY name")->fetchAll();

function thumb($p, $cls = 'thumb') {
    if ($p['image']) {
        return '<img class="' . $cls . '" src="' . e($p['image']) . '" alt="" loading="lazy" role="button" onclick=\'showPhoto(' . json_encode($p['image']) . ', ' . json_encode($p['name'], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG) . ')\'>';
    }
    return '<div class="' . $cls . ' thumb-empty"><i class="bi bi-image"></i></div>';
}

require 'includes/header.php';
?>

<div class="page-head">
  <h1>Products</h1>
  <button class="btn btn-brand" onclick="openAdd()"><i class="bi bi-plus-lg"></i> Add Product</button>
</div>

<form class="row g-2 mb-3" method="get">
  <div class="col-12 col-md-6">
    <div class="input-group">
      <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
      <input type="search" name="q" class="form-control" placeholder="Search name, article # or color" value="<?= e($search) ?>">
    </div>
  </div>
  <div class="col-8 col-md-4">
    <select name="cat" class="form-select" onchange="this.form.submit()">
      <option value="">All categories</option>
      <?php foreach ($categories as $c): ?>
        <option value="<?= $c['id'] ?>" <?= $catFilter == $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-4 col-md-2 d-grid"><button class="btn btn-outline-dark">Search</button></div>
</form>

<?php if (!$products): ?>
  <div class="card empty">
    <?= $search || $catFilter ? 'No products match your search.' : 'No products yet. Tap "Add Product" to start.' ?>
  </div>
<?php endif; ?>

<?php if ($products): ?>
<!-- Phones: cards -->
<div class="d-md-none d-grid gap-2">
  <?php foreach ($products as $p): ?>
    <div class="card product-card">
      <div class="top">
        <?= thumb($p, 'thumb thumb-lg') ?>
        <div class="flex-grow-1 min-w-0">
          <div class="name"><?= e($p['name']) ?></div>
          <code><?= e($p['article_number']) ?></code>
          <div class="mt-1"><span class="badge stock-badge bg-<?= $p['quantity'] > 0 ? 'success' : 'danger' ?>"><?= stock_label($p['quantity'], $p['pieces_per_bundle']) ?></span></div>
        </div>
      </div>
      <dl>
        <div><dt>Category</dt><dd><?= e($p['category_name'] ?? '-') ?></dd></div>
        <div><dt>Size / Color</dt><dd><?= e(trim(($p['size'] ?? '') . ' ' . ($p['color'] ?? ''))) ?: '-' ?></dd></div>
        <div><dt>Bundle price</dt><dd>₦<?= money($p['bundle_price']) ?></dd></div>
        <div><dt>Price per piece</dt><dd>₦<?= money($p['piece_price']) ?></dd></div>
        <div><dt>Added by</dt><dd><?= e($p['added_by'] ?? '-') ?></dd></div>
      </dl>
      <div class="actions">
        <button class="btn btn-outline-dark" onclick='openEdit(<?= json_encode($p, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG) ?>)'><i class="bi bi-pencil"></i> Edit</button>
        <?php if (is_admin()): ?>
          <button class="btn btn-outline-danger" onclick='askDelete(<?= (int)$p['id'] ?>, <?= json_encode($p['name'], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG) ?>)'><i class="bi bi-trash"></i> Delete</button>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<!-- Tablets / desktop: table -->
<div class="card d-none d-md-block">
  <div class="table-responsive">
    <table class="table table-hover">
      <thead><tr>
        <th></th><th>Name</th><th>Article #</th><th>Category</th><th>Size/Color</th>
        <th>In stock</th><th>Bundle Price</th><th>Per Piece</th><th class="text-end">Actions</th>
      </tr></thead>
      <tbody>
      <?php foreach ($products as $p): ?>
        <tr>
          <td style="width:64px"><?= thumb($p) ?></td>
          <td class="fw-semibold"><?= e($p['name']) ?></td>
          <td><code><?= e($p['article_number']) ?></code></td>
          <td><?= e($p['category_name'] ?? '-') ?></td>
          <td><?= e(trim(($p['size'] ?? '') . ' ' . ($p['color'] ?? ''))) ?: '-' ?></td>
          <td>
            <span class="badge stock-badge bg-<?= $p['quantity'] > 0 ? 'success' : 'danger' ?>"><?= stock_label($p['quantity'], $p['pieces_per_bundle']) ?></span>
          </td>
          <td>₦<?= money($p['bundle_price']) ?></td>
          <td>₦<?= money($p['piece_price']) ?></td>
          <td class="text-end text-nowrap">
            <button class="btn btn-sm btn-outline-dark" onclick='openEdit(<?= json_encode($p, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG) ?>)'><i class="bi bi-pencil"></i> Edit</button>
            <?php if (is_admin()): ?>
              <button class="btn btn-sm btn-outline-danger" onclick='askDelete(<?= (int)$p['id'] ?>, <?= json_encode($p['name'], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG) ?>)'><i class="bi bi-trash"></i></button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- Add/Edit -->
<div class="modal fade" id="productModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-fullscreen-sm-down modal-dialog-scrollable">
    <div class="modal-content">
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="f_id">
        <div class="modal-header">
          <h5 class="modal-title" id="modalTitle">Add Product</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body row g-3">
          <div class="col-12">
            <label class="form-label">Picture of the design</label>
            <div class="d-flex align-items-center gap-3">
              <div class="photo-preview" id="photoPreview"><i class="bi bi-camera"></i></div>
              <div class="flex-grow-1">
                <input type="file" class="form-control" name="image" id="f_image" accept="image/*" onchange="previewPhoto(this)">
                <div class="form-check mt-1 d-none" id="removeImageWrap"><input class="form-check-input" type="checkbox" name="remove_image" value="1" id="f_remove_image"><label class="form-check-label small" for="f_remove_image">Remove current picture</label></div>
                <div class="form-text">On a phone you can take a photo straight away. Max 8 MB.</div>
              </div>
            </div>
          </div>
          <div class="col-md-6"><label class="form-label">Name</label><input class="form-control" name="name" id="f_name" placeholder="e.g. High-waist Skinny Jeans" required></div>
          <div class="col-md-6"><label class="form-label">Article #</label><input class="form-control" name="article_number" id="f_article_number" required></div>
          <div class="col-md-4"><label class="form-label">Category</label>
            <select class="form-select" name="category_id" id="f_category_id">
              <option value="">-- none --</option>
              <?php foreach ($categories as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-6 col-md-4"><label class="form-label">Size</label><input class="form-control" name="size" id="f_size" placeholder="e.g. 28-34"></div>
          <div class="col-6 col-md-4"><label class="form-label">Color</label><input class="form-control" name="color" id="f_color"></div>

          <div class="col-6"><label class="form-label">Full bundles in stock</label><input type="number" inputmode="numeric" min="0" step="1" class="form-control" name="stock_bundles" id="f_stock_bundles" value="0" oninput="calcStock()"></div>
          <div class="col-6"><label class="form-label">Pieces available</label><input type="number" inputmode="numeric" min="0" step="1" class="form-control" name="stock_pieces" id="f_stock_pieces" value="0" oninput="calcStock()"></div>
          <div class="col-12 small text-muted mt-1" id="stockTotal"></div>

          <div class="col-6"><label class="form-label">Bundle price (₦)</label><input type="number" inputmode="decimal" step="0.01" min="0" class="form-control" name="bundle_price" id="f_bundle_price" required oninput="suggestPiece()"></div>
          <div class="col-6"><label class="form-label">Price per piece (₦)</label><input type="number" inputmode="decimal" step="0.01" min="0" class="form-control" name="piece_price" id="f_piece_price" required oninput="this.dataset.touched=1"></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-brand px-4">Save</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Delete confirm -->
<div class="modal fade" id="deleteModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" id="d_id">
        <div class="modal-body">
          <h5>Delete product?</h5>
          <p class="mb-0">"<span id="d_name"></span>" will be removed permanently, along with its sales lines.</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-danger">Delete</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Photo viewer -->
<div class="modal fade" id="photoModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content bg-dark">
      <div class="modal-header border-0"><h6 class="modal-title text-white" id="photoTitle"></h6><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
      <img id="photoFull" class="w-100 rounded-bottom" alt="" style="max-height:80vh;object-fit:contain">
    </div>
  </div>
</div>

<script>
let currentPpb = 10; // pieces in one bundle for the product being edited
function modal(id) { return bootstrap.Modal.getOrCreateInstance(document.getElementById(id)); }
function f(id) { return document.getElementById('f_' + id); }
function setPreview(src) {
  document.getElementById('photoPreview').innerHTML = src ? '<img src="' + src + '" alt="">' : '<i class="bi bi-camera"></i>';
}
function previewPhoto(input) {
  if (input.files && input.files[0]) setPreview(URL.createObjectURL(input.files[0]));
}
function calcStock() {
  const ppb = currentPpb;
  const total = (parseInt(f('stock_bundles').value || 0) * ppb) + parseInt(f('stock_pieces').value || 0);
  document.getElementById('stockTotal').innerText = 'Total in stock: ' + total.toLocaleString() + ' pieces';
}
function openAdd() {
  document.getElementById('modalTitle').innerText = 'Add Product';
  document.querySelector('#productModal form').reset();
  f('id').value = '';
  currentPpb = 10;
  delete f('piece_price').dataset.touched;
  setPreview(null);
  document.getElementById('removeImageWrap').classList.add('d-none');
  calcStock();
  modal('productModal').show();
}
function openEdit(p) {
  document.querySelector('#productModal form').reset();
  document.getElementById('modalTitle').innerText = 'Edit Product';
  f('id').value = p.id;
  f('name').value = p.name;
  f('article_number').value = p.article_number;
  f('category_id').value = p.category_id || '';
  f('size').value = p.size || '';
  f('color').value = p.color || '';
  const ppb = currentPpb = Math.max(1, parseInt(p.pieces_per_bundle));
  f('stock_bundles').value = Math.floor(p.quantity / ppb);
  f('stock_pieces').value = p.quantity % ppb;
  f('bundle_price').value = p.bundle_price;
  f('piece_price').value = p.piece_price;
  f('piece_price').dataset.touched = 1;
  setPreview(p.image);
  document.getElementById('removeImageWrap').classList.toggle('d-none', !p.image);
  calcStock();
  modal('productModal').show();
}
function suggestPiece() {
  const piece = f('piece_price');
  const ppb = currentPpb;
  if (!piece.dataset.touched && f('bundle_price').value) {
    piece.value = (parseFloat(f('bundle_price').value) / ppb).toFixed(2);
  }
}
function askDelete(id, name) {
  document.getElementById('d_id').value = id;
  document.getElementById('d_name').innerText = name;
  modal('deleteModal').show();
}
function showPhoto(src, name) {
  document.getElementById('photoFull').src = src;
  document.getElementById('photoTitle').innerText = name;
  modal('photoModal').show();
}
<?php if (isset($_GET['new'])): ?>
document.addEventListener('DOMContentLoaded', openAdd);
<?php endif; ?>
</script>

<?php require 'includes/footer.php'; ?>
