-- Jekwus Luxury Fashion - Stock & Inventory Database Schema

CREATE DATABASE IF NOT EXISTS stock_app CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE stock_app;

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin','staff') NOT NULL DEFAULT 'staff',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE
);

CREATE TABLE suppliers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    contact_person VARCHAR(150),
    phone VARCHAR(50),
    email VARCHAR(150),
    address VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    article_number VARCHAR(60) NOT NULL UNIQUE,
    category_id INT NULL,
    size VARCHAR(20) NULL,
    color VARCHAR(40) NULL,
    description TEXT NULL,
    cost_price DECIMAL(12,2) NOT NULL DEFAULT 0,
    pieces_per_bundle INT NOT NULL DEFAULT 12,
    bundle_price DECIMAL(12,2) NOT NULL DEFAULT 0,
    half_bundle_price DECIMAL(12,2) NOT NULL DEFAULT 0,
    quantity INT NOT NULL DEFAULT 0,
    reorder_level INT NOT NULL DEFAULT 5,
    image VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
);
-- quantity, reorder_level and pieces_per_bundle are all counted in individual pieces.
-- A "bundle" is pieces_per_bundle pieces; a "half bundle" is half of that.

CREATE TABLE stock_movements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    type ENUM('in','out') NOT NULL,
    quantity INT NOT NULL,
    reason VARCHAR(150) NULL,
    reference VARCHAR(100) NULL,
    user_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE purchase_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT NULL,
    status ENUM('pending','received','cancelled') NOT NULL DEFAULT 'pending',
    order_date DATE NOT NULL,
    expected_date DATE NULL,
    notes VARCHAR(255) NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE purchase_order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    po_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL,
    unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
    FOREIGN KEY (po_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);

CREATE TABLE sales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    customer_name VARCHAR(150) NULL,
    total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    user_id INT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE sale_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INT NOT NULL,
    product_id INT NOT NULL,
    sale_type ENUM('bundle','half_bundle') NOT NULL DEFAULT 'bundle',
    bundle_count INT NOT NULL DEFAULT 1,
    pieces INT NOT NULL,
    unit_price DECIMAL(12,2) NOT NULL,
    subtotal DECIMAL(12,2) NOT NULL,
    FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);
-- bundle_count = how many bundles (or half bundles) were sold.
-- pieces = the actual number of pieces this line deducted from stock.
-- unit_price = the price charged per bundle / per half bundle (entered at sale time, since bulk prices vary).

-- Starter categories (edit/add more from the Categories page any time)
INSERT INTO categories (name) VALUES ('Jeans'), ('Skirts');

-- No default admin user is seeded here.
-- After importing this schema, open setup.php in your browser ONCE to create
-- the first admin account (it generates a proper password hash for you).
-- Delete setup.php from the server after you've created that account.
