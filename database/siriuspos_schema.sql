-- ==============================================================================
-- DATABASE SCRIPT: SiriusPOS (ERP Phase 1)
-- Database Engine: MySQL 8.0+ / MariaDB 10.4+
-- Karakteristik: Hybrid Retail Minimarket & Coffee Corner (BOM + Multi-Location)
-- ==============================================================================

CREATE DATABASE IF NOT EXISTS sirius_pos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE sirius_pos;

-- ------------------------------------------------------------------------------
-- 1. USERS & OTORISASI ROLE KASIR / SUPERVISOR
-- ------------------------------------------------------------------------------
-- Menyimpan data staff kasir, barista, supervisor, dan admin.
-- Memuat pin_code (terenkripsi) untuk supervisor override saat void / buka laci.
CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'supervisor', 'cashier', 'barista', 'warehouse') NOT NULL DEFAULT 'cashier',
    pin_code VARCHAR(255) NULL COMMENT 'Hash PIN 6 digit untuk otorisasi supervisor',
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------------------------
-- 2. CABANG & SUB-LOKASI INVENTORY (MULTI-LOCATION)
-- ------------------------------------------------------------------------------
-- Toko minimarket dan bar kopi berada di cabang yang sama, tapi beda sub-lokasi fisik.
CREATE TABLE IF NOT EXISTS branches (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(30) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    address TEXT NULL,
    phone VARCHAR(30) NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS locations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    branch_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(50) NOT NULL COMMENT 'Contoh: RAK-FRONT, BAR-COUNTER, GUDANG-BELAKANG',
    name VARCHAR(100) NOT NULL,
    division ENUM('retail', 'coffee', 'general') NOT NULL DEFAULT 'general',
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
    UNIQUE KEY uq_branch_location_code (branch_id, code)
) ENGINE=InnoDB;

-- ------------------------------------------------------------------------------
-- 3. SATUAN (UoM), KATEGORI, SUPPLIER & MASTER PRODUK
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS units (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20) NOT NULL UNIQUE COMMENT 'pcs, box, karton, gr, ml, cup',
    name VARCHAR(50) NOT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS categories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    division ENUM('retail', 'coffee', 'general') NOT NULL DEFAULT 'general',
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
) ENGINE=InnoDB;

-- Supplier Resmi Distributor (TOP 14-30 hari) & Vendor Konsinyasi UMKM
CREATE TABLE IF NOT EXISTS suppliers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(30) NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL,
    contact_person VARCHAR(100) NULL,
    phone VARCHAR(30) NULL,
    email VARCHAR(100) NULL,
    address TEXT NULL,
    payment_terms_days INT NOT NULL DEFAULT 0 COMMENT 'TOP: 0 = COD/Cash, 14, 30 hari',
    is_consignment_vendor BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'TRUE jika vendor titip jual UMKM',
    revenue_share_percentage DECIMAL(5, 2) NOT NULL DEFAULT 0.00 COMMENT 'Persentase bagi hasil untuk toko (misal 20%)',
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
) ENGINE=InnoDB;

-- Master Produk: Mendukung Barang Retail, Bahan Baku F&B, dan Menu Racikan (BOM)
CREATE TABLE IF NOT EXISTS products (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sku VARCHAR(64) NOT NULL UNIQUE,
    barcode VARCHAR(64) NULL,
    name VARCHAR(150) NOT NULL,
    category_id BIGINT UNSIGNED NULL,
    division ENUM('retail', 'coffee') NOT NULL DEFAULT 'retail',
    type ENUM('standard', 'composite', 'raw_material') NOT NULL DEFAULT 'standard'
        COMMENT 'standard: barang retail; composite: menu kopi ber-resep; raw_material: biji kopi/susu/cup',
    primary_unit_id BIGINT UNSIGNED NOT NULL COMMENT 'Satuan terkecil (pcs, gr, ml)',
    purchase_price DECIMAL(15, 2) NOT NULL DEFAULT 0.00 COMMENT 'Baseline HPP',
    selling_price DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
    min_stock_alert INT NOT NULL DEFAULT 5,
    track_expiry BOOLEAN NOT NULL DEFAULT TRUE,
    is_consignment BOOLEAN NOT NULL DEFAULT FALSE,
    supplier_id BIGINT UNSIGNED NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    INDEX idx_product_barcode (barcode),
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    FOREIGN KEY (primary_unit_id) REFERENCES units(id),
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Konversi Multi-Satuan (UoM Conversion: Masuk Dus, Jual Pcs)
CREATE TABLE IF NOT EXISTS product_uom_conversions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id BIGINT UNSIGNED NOT NULL,
    from_unit_id BIGINT UNSIGNED NOT NULL COMMENT 'Satuan besar (Dus/Karton)',
    to_unit_id BIGINT UNSIGNED NOT NULL COMMENT 'Satuan kecil (Pcs)',
    multiplier DECIMAL(10, 4) NOT NULL COMMENT 'Contoh: 1 Dus = 12 Pcs -> multiplier = 12',
    is_purchase_unit BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (from_unit_id) REFERENCES units(id),
    FOREIGN KEY (to_unit_id) REFERENCES units(id),
    UNIQUE KEY uq_prod_uom (product_id, from_unit_id, to_unit_id)
) ENGINE=InnoDB;

-- Bill of Materials (BOM Resep Menu Kopi: 1 Cup Latte = 1 Cup + 18g Biji + 200ml Susu)
CREATE TABLE IF NOT EXISTS recipe_boms (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    parent_product_id BIGINT UNSIGNED NOT NULL COMMENT 'Menu Kopi (composite)',
    material_product_id BIGINT UNSIGNED NOT NULL COMMENT 'Bahan Mentah (raw_material/standard)',
    quantity DECIMAL(10, 4) NOT NULL COMMENT 'Takaran bahan per 1 cup menu',
    unit_id BIGINT UNSIGNED NOT NULL,
    yield_loss_percentage DECIMAL(5, 2) NOT NULL DEFAULT 0.00 COMMENT 'Toleransi susut/grinder retention',
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (parent_product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (material_product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (unit_id) REFERENCES units(id),
    UNIQUE KEY uq_parent_material (parent_product_id, material_product_id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------------------------
-- 4. BATCH EXPIRY & IMMUTABLE STOCK LEDGER (FEFO)
-- ------------------------------------------------------------------------------
-- Mencatat stok per batch dan tanggal kedaluwarsa untuk First Expired, First Out.
CREATE TABLE IF NOT EXISTS inventory_batches (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    batch_no VARCHAR(64) NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    location_id BIGINT UNSIGNED NOT NULL COMMENT 'Sub-lokasi fisik batch ini berada',
    expired_at DATE NULL COMMENT 'Kunci pengurutan FEFO',
    initial_qty DECIMAL(12, 4) NOT NULL,
    current_qty DECIMAL(12, 4) NOT NULL,
    unit_cost DECIMAL(15, 2) NOT NULL DEFAULT 0.00 COMMENT 'HPP per satuan batch ini',
    status ENUM('active', 'depleted', 'expired', 'quarantined') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    INDEX idx_batch_fefo (product_id, location_id, status, expired_at),
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Buku Besar Mutasi Stok (Immutable Double-Entry Ledger)
-- Setiap penambahan/pengurangan stok WAJIB punya baris riwayat di sini.
CREATE TABLE IF NOT EXISTS stock_movements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    branch_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    batch_id BIGINT UNSIGNED NULL,
    from_location_id BIGINT UNSIGNED NULL COMMENT 'Asal stok',
    to_location_id BIGINT UNSIGNED NULL COMMENT 'Tujuan stok',
    reference_type VARCHAR(50) NOT NULL COMMENT 'POS_SALE, POS_BOM_CONSUMPTION, INTERNAL_TRANSFER, PO_RECEIPT, WASTE_SPOILAGE, STOCK_ADJUSTMENT',
    reference_id BIGINT UNSIGNED NULL,
    quantity DECIMAL(12, 4) NOT NULL COMMENT 'Kuantitas selalu absolut positif',
    unit_cost DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
    total_cost DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
    notes VARCHAR(255) NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_mov_ref (reference_type, reference_id),
    INDEX idx_mov_prod (product_id, created_at),
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (batch_id) REFERENCES inventory_batches(id) ON DELETE SET NULL,
    FOREIGN KEY (from_location_id) REFERENCES locations(id) ON DELETE SET NULL,
    FOREIGN KEY (to_location_id) REFERENCES locations(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------------------------
-- 5. INTERNAL STOCK TRANSFER (KASIR CATAT MUTASI RAK KE BAR)
-- ------------------------------------------------------------------------------
-- Sesuai kesepakatan: Barista bilang ke kasir, kasir langsung input di sistem.
CREATE TABLE IF NOT EXISTS internal_stock_transfers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    transfer_number VARCHAR(50) NOT NULL UNIQUE,
    from_location_id BIGINT UNSIGNED NOT NULL COMMENT 'Contoh: RAK-FRONT (Minimarket)',
    to_location_id BIGINT UNSIGNED NOT NULL COMMENT 'Contoh: BAR-COUNTER (Coffee)',
    requested_by BIGINT UNSIGNED NOT NULL COMMENT 'Kasir yang mencatat',
    approved_by BIGINT UNSIGNED NULL,
    status ENUM('pending', 'approved', 'rejected', 'completed') NOT NULL DEFAULT 'completed',
    transfer_date DATETIME NOT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (from_location_id) REFERENCES locations(id),
    FOREIGN KEY (to_location_id) REFERENCES locations(id),
    FOREIGN KEY (requested_by) REFERENCES users(id),
    FOREIGN KEY (approved_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS internal_stock_transfer_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    transfer_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL COMMENT 'Misal: Susu UHT 1000ml',
    batch_id BIGINT UNSIGNED NULL,
    requested_qty DECIMAL(10, 4) NOT NULL,
    transferred_qty DECIMAL(10, 4) NOT NULL,
    unit_id BIGINT UNSIGNED NOT NULL,
    unit_cost DECIMAL(15, 2) NOT NULL DEFAULT 0.00 COMMENT 'HPP yang dialihkan ke Barista',
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (transfer_id) REFERENCES internal_stock_transfers(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (batch_id) REFERENCES inventory_batches(id) ON DELETE SET NULL,
    FOREIGN KEY (unit_id) REFERENCES units(id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------------------------
-- 6. SHIFT KASIR & KAS KECIL (PETTY CASH OPERASIONAL)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cash_shifts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    branch_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL COMMENT 'Kasir yang bertugas',
    start_time DATETIME NOT NULL,
    end_time DATETIME NULL,
    opening_balance DECIMAL(15, 2) NOT NULL DEFAULT 0.00 COMMENT 'Modal uang laci awal',
    total_cash_sales DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
    total_qris_sales DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
    total_cash_out DECIMAL(15, 2) NOT NULL DEFAULT 0.00 COMMENT 'Total pengeluaran kas kecil saat shift',
    expected_cash_in_drawer DECIMAL(15, 2) NOT NULL DEFAULT 0.00 COMMENT 'opening + cash_sales - cash_out',
    actual_cash_in_drawer DECIMAL(15, 2) NULL COMMENT 'Uang fisik riil saat tutup shift',
    discrepancy DECIMAL(15, 2) NOT NULL DEFAULT 0.00 COMMENT 'Selisih lebih / kurang',
    status ENUM('open', 'closed') NOT NULL DEFAULT 'open',
    notes TEXT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- Pengeluaran Kas Kecil dari Laci Kasir (Cash Out) dengan Bukti Foto Nota
CREATE TABLE IF NOT EXISTS petty_cash_expenses (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cash_shift_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NOT NULL,
    category ENUM('emergency_ice', 'gallon_water', 'trash_security', 'cleaning_supplies', 'other') NOT NULL,
    division ENUM('retail', 'coffee', 'general') NOT NULL DEFAULT 'general',
    amount DECIMAL(15, 2) NOT NULL,
    recipient VARCHAR(100) NOT NULL COMMENT 'Penerima uang (tukang es kristal, iuran RT, dll)',
    receipt_photo_path VARCHAR(255) NULL COMMENT 'Path file foto bukti nota / kuitansi',
    notes TEXT NULL,
    recorded_by BIGINT UNSIGNED NOT NULL,
    approved_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cash_shift_id) REFERENCES cash_shifts(id) ON DELETE CASCADE,
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (recorded_by) REFERENCES users(id),
    FOREIGN KEY (approved_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------------------------
-- 7. LOYALTY PELANGGAN (SINGLE CUSTOMER POINT)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS customers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    phone VARCHAR(20) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NULL,
    current_points INT NOT NULL DEFAULT 0,
    total_spent DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS loyalty_points_ledgers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id BIGINT UNSIGNED NOT NULL,
    order_id BIGINT UNSIGNED NULL,
    division ENUM('retail', 'coffee', 'general') NOT NULL DEFAULT 'general',
    type ENUM('earn', 'redeem', 'adjustment') NOT NULL,
    points INT NOT NULL COMMENT 'Nilai perubahan poin (+10 atau -50)',
    description VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cust_points (customer_id, created_at),
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------------------------
-- 8. PENJUALAN 1 STRUK PANJANG & KDS KITCHEN DISPLAY (ANTREAN BARISTA)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS orders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_number VARCHAR(50) NOT NULL UNIQUE,
    branch_id BIGINT UNSIGNED NOT NULL,
    cash_shift_id BIGINT UNSIGNED NOT NULL,
    cashier_id BIGINT UNSIGNED NOT NULL,
    customer_id BIGINT UNSIGNED NULL,
    queue_number VARCHAR(20) NULL COMMENT 'Nomor Antrean Kopi di struk (misal: C-042)',
    subtotal DECIMAL(15, 2) NOT NULL,
    discount_amount DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
    tax_amount DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
    total_amount DECIMAL(15, 2) NOT NULL,

    -- Pemisahan Omset & HPP per Divisi (Cost Center ERP)
    retail_subtotal DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
    coffee_subtotal DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
    retail_cogs DECIMAL(15, 2) NOT NULL DEFAULT 0.00 COMMENT 'HPP barang minimarket yang keluar',
    coffee_cogs DECIMAL(15, 2) NOT NULL DEFAULT 0.00 COMMENT 'HPP bahan baku kopi yang terpakai via BOM',

    payment_status ENUM('unpaid', 'paid', 'refunded', 'void') NOT NULL DEFAULT 'paid',
    payment_method ENUM('cash', 'qris', 'split') NOT NULL DEFAULT 'cash',
    order_date DATETIME NOT NULL,
    is_void BOOLEAN NOT NULL DEFAULT FALSE,
    void_reason VARCHAR(255) NULL,
    void_by BIGINT UNSIGNED NULL COMMENT 'Supervisor yang otorisasi void',
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    INDEX idx_order_date (branch_id, order_date),
    INDEX idx_queue (branch_id, queue_number),
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (cash_shift_id) REFERENCES cash_shifts(id),
    FOREIGN KEY (cashier_id) REFERENCES users(id),
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
    FOREIGN KEY (void_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- Detail Item Belanja (Ada opsi custom_notes untuk menu kopi: less sugar, oat milk, dll)
CREATE TABLE IF NOT EXISTS order_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    division ENUM('retail', 'coffee') NOT NULL,
    quantity DECIMAL(10, 2) NOT NULL,
    unit_price DECIMAL(15, 2) NOT NULL,
    cost_price DECIMAL(15, 2) NOT NULL COMMENT 'HPP satuan saat checkout',
    subtotal DECIMAL(15, 2) NOT NULL,
    discount_amount DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
    notes VARCHAR(255) NULL COMMENT 'Opsi Kopi: Less Ice, Less Sugar, Oat Milk, dll',
    is_void BOOLEAN NOT NULL DEFAULT FALSE,
    void_reason VARCHAR(255) NULL,
    void_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (void_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    payment_method ENUM('cash', 'qris') NOT NULL,
    amount DECIMAL(15, 2) NOT NULL,
    change_amount DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
    reference_no VARCHAR(100) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Kitchen Display System (KDS) untuk Barista
-- Dibuat otomatis saat order mengandung menu kopi
CREATE TABLE IF NOT EXISTS kds_tickets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NOT NULL,
    queue_number VARCHAR(20) NOT NULL COMMENT 'Dicocokkan dengan struk pelanggan',
    status ENUM('queued', 'preparing', 'ready', 'collected') NOT NULL DEFAULT 'queued',
    started_at DATETIME NULL,
    ready_at DATETIME NULL,
    collected_at DATETIME NULL,
    barista_id BIGINT UNSIGNED NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    INDEX idx_kds_status (branch_id, status),
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (barista_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS kds_ticket_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_id BIGINT UNSIGNED NOT NULL,
    order_item_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    quantity INT NOT NULL,
    custom_notes TEXT NULL COMMENT 'Menampilkan catatan rasa ke layar barista',
    status ENUM('queued', 'in_progress', 'done') NOT NULL DEFAULT 'queued',
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (ticket_id) REFERENCES kds_tickets(id) ON DELETE CASCADE,
    FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------------------------
-- 9. PURCHASING, HUTANG USAHA (AP) & ALERT JATUH TEMPO
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS purchase_orders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    po_number VARCHAR(50) NOT NULL UNIQUE,
    supplier_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NOT NULL,
    order_date DATE NOT NULL,
    expected_delivery_date DATE NULL,
    total_amount DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
    status ENUM('draft', 'sent', 'partially_received', 'received', 'cancelled') NOT NULL DEFAULT 'draft',
    created_by BIGINT UNSIGNED NOT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS purchase_order_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    purchase_order_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    ordered_qty DECIMAL(10, 4) NOT NULL,
    received_qty DECIMAL(10, 4) NOT NULL DEFAULT 0.0000,
    unit_id BIGINT UNSIGNED NOT NULL,
    unit_price DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
    subtotal DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (unit_id) REFERENCES units(id)
) ENGINE=InnoDB;

-- Goods Receipt Note (GRN - Penerimaan Barang dari Distributor)
CREATE TABLE IF NOT EXISTS goods_receipt_notes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    grn_number VARCHAR(50) NOT NULL UNIQUE,
    purchase_order_id BIGINT UNSIGNED NULL,
    supplier_id BIGINT UNSIGNED NOT NULL,
    location_id BIGINT UNSIGNED NOT NULL COMMENT 'Lokasi gudang penerimaan',
    receipt_date DATETIME NOT NULL,
    received_by BIGINT UNSIGNED NOT NULL,
    invoice_ref_number VARCHAR(100) NULL COMMENT 'No Surat Jalan / Faktur Supplier',
    status ENUM('draft', 'verified') NOT NULL DEFAULT 'verified',
    notes TEXT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE SET NULL,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    FOREIGN KEY (location_id) REFERENCES locations(id),
    FOREIGN KEY (received_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS goods_receipt_note_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    grn_id BIGINT UNSIGNED NOT NULL,
    po_item_id BIGINT UNSIGNED NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    batch_no VARCHAR(64) NOT NULL,
    expired_at DATE NULL COMMENT 'Input tgl expired barang masuk (Kunci FEFO)',
    received_qty DECIMAL(10, 4) NOT NULL,
    unit_id BIGINT UNSIGNED NOT NULL,
    unit_cost DECIMAL(15, 2) NOT NULL,
    subtotal DECIMAL(15, 2) NOT NULL,
    batch_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (grn_id) REFERENCES goods_receipt_notes(id) ON DELETE CASCADE,
    FOREIGN KEY (po_item_id) REFERENCES purchase_order_items(id) ON DELETE SET NULL,
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (unit_id) REFERENCES units(id),
    FOREIGN KEY (batch_id) REFERENCES inventory_batches(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Hutang Usaha (AP Invoice) dengan Alert Jatuh Tempo TOP
CREATE TABLE IF NOT EXISTS ap_invoices (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_number VARCHAR(50) NOT NULL UNIQUE,
    supplier_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NOT NULL,
    grn_id BIGINT UNSIGNED NULL,
    issue_date DATE NOT NULL,
    due_date DATE NOT NULL COMMENT 'Kunci alert jatuh tempo (TOP 14-30 hari)',
    total_amount DECIMAL(15, 2) NOT NULL,
    paid_amount DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
    status ENUM('unpaid', 'partially_paid', 'paid', 'overdue') NOT NULL DEFAULT 'unpaid',
    notes TEXT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    INDEX idx_ap_due (due_date, status),
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (grn_id) REFERENCES goods_receipt_notes(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ap_payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ap_invoice_id BIGINT UNSIGNED NOT NULL,
    payment_date DATE NOT NULL,
    amount DECIMAL(15, 2) NOT NULL,
    payment_method ENUM('bank_transfer', 'cash', 'giro') NOT NULL DEFAULT 'bank_transfer',
    reference_no VARCHAR(100) NULL,
    recorded_by BIGINT UNSIGNED NOT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (ap_invoice_id) REFERENCES ap_invoices(id) ON DELETE CASCADE,
    FOREIGN KEY (recorded_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------------------------
-- 10. KONSINYASI UMKM (BAGI HASIL & RETUR BARANG BASI)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS consignment_settlements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    settlement_no VARCHAR(50) NOT NULL UNIQUE,
    supplier_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NOT NULL,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    total_sold_qty INT NOT NULL DEFAULT 0,
    total_gross_sales DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
    total_returned_qty INT NOT NULL DEFAULT 0 COMMENT 'Barang basi/retur dikembalikan ke penitip',
    store_share_amount DECIMAL(15, 2) NOT NULL DEFAULT 0.00 COMMENT 'Komisi toko (misal 20%)',
    vendor_payable_amount DECIMAL(15, 2) NOT NULL DEFAULT 0.00 COMMENT 'Hak bayar supplier (misal 80%)',
    status ENUM('draft', 'approved', 'paid') NOT NULL DEFAULT 'draft',
    settled_by BIGINT UNSIGNED NOT NULL,
    paid_at DATETIME NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (settled_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS consignment_settlement_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    settlement_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    initial_stock_qty INT NOT NULL DEFAULT 0,
    received_qty INT NOT NULL DEFAULT 0,
    sold_qty INT NOT NULL DEFAULT 0,
    returned_damaged_qty INT NOT NULL DEFAULT 0,
    closing_stock_qty INT NOT NULL DEFAULT 0,
    selling_price DECIMAL(15, 2) NOT NULL,
    gross_sales DECIMAL(15, 2) NOT NULL,
    store_commission_pct DECIMAL(5, 2) NOT NULL,
    store_commission_amount DECIMAL(15, 2) NOT NULL,
    vendor_payable_amount DECIMAL(15, 2) NOT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (settlement_id) REFERENCES consignment_settlements(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------------------------
-- 11. WASTE & SPOILAGE LOG (BASI / TUMPAH / DIAL-IN BEANS)
-- ------------------------------------------------------------------------------
-- Dicatat sebagai beban kerugian divisi bersangkutan (bukan penjualan).
CREATE TABLE IF NOT EXISTS inventory_waste_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    branch_id BIGINT UNSIGNED NOT NULL,
    location_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    batch_id BIGINT UNSIGNED NULL,
    division ENUM('retail', 'coffee') NOT NULL DEFAULT 'retail',
    quantity DECIMAL(10, 4) NOT NULL,
    unit_id BIGINT UNSIGNED NOT NULL,
    unit_cost DECIMAL(15, 2) NOT NULL,
    total_loss DECIMAL(15, 2) NOT NULL COMMENT 'Beban rugi (quantity * unit_cost)',
    reason ENUM('expired', 'damaged', 'barista_spill', 'dial_in_beans', 'pest_damage', 'other') NOT NULL,
    notes TEXT NULL,
    recorded_by BIGINT UNSIGNED NOT NULL,
    approved_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (location_id) REFERENCES locations(id),
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (batch_id) REFERENCES inventory_batches(id) ON DELETE SET NULL,
    FOREIGN KEY (unit_id) REFERENCES units(id),
    FOREIGN KEY (recorded_by) REFERENCES users(id),
    FOREIGN KEY (approved_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------------------------
-- 12. STOCK OPNAME & AUDITING (CYCLE COUNT)
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS stock_opnames (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    opname_number VARCHAR(50) NOT NULL UNIQUE,
    branch_id BIGINT UNSIGNED NOT NULL,
    location_id BIGINT UNSIGNED NOT NULL,
    category_id BIGINT UNSIGNED NULL COMMENT 'Cycle count partial per kategori (misal: Rak Susu)',
    status ENUM('in_progress', 'submitted', 'approved', 'rejected') NOT NULL DEFAULT 'in_progress',
    opname_date DATE NOT NULL,
    notes TEXT NULL,
    initiated_by BIGINT UNSIGNED NOT NULL,
    approved_by BIGINT UNSIGNED NULL,
    approved_at DATETIME NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (location_id) REFERENCES locations(id),
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    FOREIGN KEY (initiated_by) REFERENCES users(id),
    FOREIGN KEY (approved_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS stock_opname_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    opname_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    batch_id BIGINT UNSIGNED NULL,
    system_qty DECIMAL(10, 4) NOT NULL,
    physical_qty DECIMAL(10, 4) NOT NULL,
    difference_qty DECIMAL(10, 4) NOT NULL COMMENT 'physical - system',
    unit_cost DECIMAL(15, 2) NOT NULL,
    difference_value DECIMAL(15, 2) NOT NULL COMMENT 'difference_qty * unit_cost',
    notes VARCHAR(255) NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (opname_id) REFERENCES stock_opnames(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (batch_id) REFERENCES inventory_batches(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------------------------
-- 13. PROMO ENGINE, BUNDLING & MULTI-TIER PRICING (GROSIR)
-- ------------------------------------------------------------------------------
-- Harga Grosir Berjenjang (Beli 1 @ Rp 4.500, >= 10 @ Rp 4.000)
CREATE TABLE IF NOT EXISTS product_price_tiers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id BIGINT UNSIGNED NOT NULL,
    min_qty DECIMAL(10, 2) NOT NULL,
    unit_price DECIMAL(15, 2) NOT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    UNIQUE KEY uq_prod_tier (product_id, min_qty)
) ENGINE=InnoDB;

-- Bundling Silang (Beli 2 Roti Minimarket, Diskon 50% Americano di Bar)
CREATE TABLE IF NOT EXISTS promotions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    type ENUM('cross_bundle', 'buy_x_get_y', 'percentage_discount', 'fixed_discount') NOT NULL DEFAULT 'cross_bundle',
    start_date DATETIME NOT NULL,
    end_date DATETIME NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    description TEXT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS promotion_rules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    promotion_id BIGINT UNSIGNED NOT NULL,
    condition_product_id BIGINT UNSIGNED NOT NULL COMMENT 'Item pemicu (Roti Sari Roti)',
    condition_min_qty DECIMAL(10, 2) NOT NULL DEFAULT 1.00 COMMENT 'Jumlah syarat beli (2 pcs)',
    reward_product_id BIGINT UNSIGNED NOT NULL COMMENT 'Item diskon (Iced Americano)',
    reward_discount_type ENUM('percentage', 'fixed_price', 'free') NOT NULL DEFAULT 'percentage',
    reward_discount_value DECIMAL(15, 2) NOT NULL DEFAULT 0.00 COMMENT 'Nilai potongan (50%)',
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (promotion_id) REFERENCES promotions(id) ON DELETE CASCADE,
    FOREIGN KEY (condition_product_id) REFERENCES products(id),
    FOREIGN KEY (reward_product_id) REFERENCES products(id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------------------------
-- 14. KEAMANAN KASIR & SUPERVISOR OVERRIDE AUDIT TRAIL
-- ------------------------------------------------------------------------------
-- Merekam aktivitas rawan fraud: buka laci tanpa belanja, void, ubah harga.
CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    branch_id BIGINT UNSIGNED NULL,
    user_id BIGINT UNSIGNED NOT NULL COMMENT 'Kasir / Operator yang melakukan aksi',
    supervisor_id BIGINT UNSIGNED NULL COMMENT 'Supervisor yang memasukkan PIN override',
    action VARCHAR(50) NOT NULL COMMENT 'OPEN_DRAWER_NO_SALE, VOID_ORDER, VOID_ITEM, MANUAL_DISCOUNT, PRICE_OVERRIDE',
    reference_type VARCHAR(50) NULL,
    reference_id BIGINT UNSIGNED NULL,
    reason VARCHAR(255) NULL,
    payload JSON NULL COMMENT 'Snapshot data sebelum/sesudah kejadian',
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_action (action, created_at),
    INDEX idx_audit_user (user_id, created_at),
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (supervisor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
