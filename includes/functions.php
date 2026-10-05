<?php
function flash($type, $msg) {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}

function money($n) {
    return number_format((float)$n, 2);
}

function redirect($url) {
    header("Location: $url");
    exit;
}

// Site settings (store name, logo) live in a small key/value table,
// created automatically the first time it's needed.
function setting(PDO $pdo, $key, $default = null) {
    static $cache = null;
    if ($cache === null) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS settings (setting_key VARCHAR(50) PRIMARY KEY, setting_value TEXT NULL)");
        $cache = $pdo->query("SELECT setting_key, setting_value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    return $cache[$key] ?? $default;
}

function save_setting(PDO $pdo, $key, $value) {
    setting($pdo, $key); // make sure the table exists
    $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $stmt->execute([$key, $value]);
}

// Brings an existing database up to date. Runs once per version bump,
// so the live database upgrades itself the first time a page loads.
function run_migrations(PDO $pdo) {
    $version = (int)setting($pdo, 'schema_version', 1);
    if ($version >= 2) return;

    $steps = [
        // Sell by the piece instead of by half bundle
        "ALTER TABLE products ADD COLUMN piece_price DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER bundle_price",
        "UPDATE products SET piece_price = ROUND(bundle_price / GREATEST(pieces_per_bundle, 1), 2) WHERE piece_price = 0",
        "ALTER TABLE sale_items MODIFY sale_type ENUM('bundle','half_bundle','piece') NOT NULL DEFAULT 'bundle'",
        "ALTER TABLE products ADD COLUMN created_by INT NULL",
        // Unpaid / part-paid sales
        "ALTER TABLE sales ADD COLUMN customer_phone VARCHAR(50) NULL AFTER customer_name",
        "ALTER TABLE sales ADD COLUMN amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER total_amount",
        "ALTER TABLE sales ADD COLUMN note VARCHAR(255) NULL",
        "UPDATE sales SET amount_paid = total_amount", // every sale before this update was paid in full
        "CREATE TABLE IF NOT EXISTS sale_payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sale_id INT NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            user_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        )",
        // Removing a staff member disables them instead of wiping who made their sales
        "ALTER TABLE users ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1",
    ];
    foreach ($steps as $sql) {
        try {
            $pdo->exec($sql);
        } catch (PDOException $e) {
            // 1060 = column already exists; safe to skip on a re-run
            if (($e->errorInfo[1] ?? 0) != 1060) throw $e;
        }
    }
    save_setting($pdo, 'schema_version', 2);
}

// Stock is stored in pieces. Show it as pieces first, with the bundle breakdown.
function stock_label($qty, $ppb) {
    $qty = max(0, (int)$qty);
    $ppb = max(1, (int)$ppb);
    $text = number_format($qty) . ' pcs';
    if ($ppb > 1 && $qty >= $ppb) {
        $b = intdiv($qty, $ppb);
        $r = $qty % $ppb;
        $text .= ' · ' . $b . ' bundle' . ($b == 1 ? '' : 's') . ($r ? " + $r" : '');
    }
    return $text;
}

// Human label for one sale line, e.g. "2 × bundle" or "5 pcs".
function sale_line_label($type, $count) {
    $count = (int)$count;
    if ($type === 'piece') return $count . ' pc' . ($count == 1 ? '' : 's');
    if ($type === 'half_bundle') return "$count × half bundle";
    return "$count × bundle";
}

function balance_of($sale) {
    return max(0, round((float)$sale['total_amount'] - (float)$sale['amount_paid'], 2));
}

function payment_badge($sale) {
    $bal = balance_of($sale);
    if ($bal <= 0) return '<span class="badge bg-success">Paid</span>';
    if ((float)$sale['amount_paid'] > 0) return '<span class="badge bg-warning text-dark">Part paid</span>';
    return '<span class="badge bg-danger">Owing</span>';
}

// Saves an uploaded photo into uploads/ and returns its path, null if none sent.
// Throws with a friendly message if the file is not a usable image.
function save_uploaded_image($field, $prefix) {
    if (empty($_FILES[$field]['name'])) return null;
    $types = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $info = @getimagesize($_FILES[$field]['tmp_name']);
    if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK || !$info || !isset($types[$info['mime']])) {
        throw new Exception('Picture must be a PNG, JPG, WEBP or GIF image.');
    }
    if ($_FILES[$field]['size'] > 8 * 1024 * 1024) {
        throw new Exception('Picture must be smaller than 8 MB.');
    }
    $dir = dirname(__DIR__) . '/uploads';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $file = $prefix . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $types[$info['mime']];
    if (!move_uploaded_file($_FILES[$field]['tmp_name'], "$dir/$file")) {
        throw new Exception('Could not save the picture on the server.');
    }
    return "uploads/$file";
}

function e($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES);
}

// Adjusts a product's quantity and logs the movement in one place,
// so stock.php, sales.php, and purchase order "receive" actions all
// stay consistent.
function record_stock_movement(PDO $pdo, $product_id, $type, $qty, $reason = null, $reference = null, $user_id = null) {
    $pdo->beginTransaction();
    try {
        if ($type === 'in') {
            $stmt = $pdo->prepare("UPDATE products SET quantity = quantity + ? WHERE id = ?");
        } else {
            $stmt = $pdo->prepare("UPDATE products SET quantity = GREATEST(quantity - ?, 0) WHERE id = ?");
        }
        $stmt->execute([$qty, $product_id]);

        $stmt = $pdo->prepare("INSERT INTO stock_movements (product_id, type, quantity, reason, reference, user_id) VALUES (?,?,?,?,?,?)");
        $stmt->execute([$product_id, $type, $qty, $reason, $reference, $user_id]);

        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        return false;
    }
}

if (isset($pdo)) run_migrations($pdo);
