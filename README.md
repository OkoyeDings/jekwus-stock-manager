# Jekwus Luxury Fashion — Stock & Inventory Manager

A lightweight PHP + MySQL app for tracking Jekwus Luxury Fashion's ladies' jeans and skirts — stock, bundle sales, suppliers, and purchase orders.

## Features
- Login with two roles: **admin** (full access, manages users) and **staff** (day-to-day operations)
- Product catalog: every item needs a unique **article number**, name, category, size, color, cost price, pieces-per-bundle, bundle price, half-bundle price, photo, and reorder level
- Stock is always tracked in **pieces** under the hood, but every sale is entered the way the shop actually sells: **Bundle** or **Half Bundle**, how many, and the price it sold for. The app converts that to pieces automatically using each product's "pieces per bundle" and deducts stock correctly
- Stock movements (manual stock in/out with reason, full audit log)
- Suppliers and Purchase Orders — create a PO, mark it "received" and stock updates automatically
- Dashboard with low-stock alerts, today's sales, and recent activity
- Reports: reorder list, inventory value (cost & estimated bundle retail), best sellers

## Requirements
- PHP 8+ with PDO MySQL extension
- MySQL 5.7+ / MariaDB
- Any standard shared host (cPanel etc.) or local server (XAMPP/MAMP/Laragon)

## Setup
1. Upload all files to your server (or place in your local server's web root).
2. Create a MySQL database and import `sql/schema.sql` (via phpMyAdmin or `mysql -u user -p dbname < sql/schema.sql`).
3. Edit `config/db.php` with your actual DB host, name, username, and password.
4. Open `setup.php` in your browser — it creates your first admin account (name, email, password). This generates a proper password hash for you, so don't skip it.
5. **Delete `setup.php`** from the server once the admin account is created (or restrict access to it).
6. Log in at `login.php` with the admin account you just created.
7. Create staff accounts from **Users** in the nav (admin only).

## How the bundle / half-bundle workflow works
1. When you add a product, set **Pieces per Bundle** (e.g. 12), plus a **Bundle Price** and a **Half Bundle Price**.
2. When you record a sale, pick the product, choose **Bundle** or **Half Bundle**, enter how many were sold, and the price it actually sold for (it pre-fills from the product's price but you can override it for a negotiated deal).
3. The app works out the number of pieces that leaves stock (bundle count × pieces-per-bundle, or half of that for half bundles) and deducts it — you never have to do that math by hand.

## Notes on how stock stays accurate
Every place that changes quantity (adding a product with an opening quantity, manual stock in/out, receiving a purchase order, completing a sale) writes to the `stock_movements` table, so you always have a full audit trail of what happened to every item and who did it.

## Folder structure
```
config/       DB connection settings
includes/     shared auth, header, footer, helper functions
sql/          schema.sql to import
uploads/      product photos (created automatically on upload)
assets/       CSS
*.php         one file per page (products, stock, sales, suppliers, purchase_orders, reports, users)
```

## Suggested next steps (not included yet)
- Printable/PDF receipts for sales
- CSV export for reports
- Barcode scanning support
- Password reset via email

## Security notes before going live
- Change the default DB credentials in `config/db.php`
- Delete `setup.php` after first use
- Serve over HTTPS
- Consider rate-limiting `login.php` if it'll be internet-facing
