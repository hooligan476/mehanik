<?php
// mehanik/admin/cars.php
require_once __DIR__.'/../../middleware.php';
require_admin();
require_once __DIR__.'/../../db.php';

// ensure DB connection object $mysqli exists (original code uses $mysqli)
if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
    // try to fallback or abort quietly
    http_response_code(500);
    echo "Database connection (mysqli) not available.";
    exit;
}

// Create helper sanitize
function s_post($k){ return isset($_POST[$k]) ? trim((string)$_POST[$k]) : ''; }

// Ensure vehicle_types and vehicle_bodies exist (best-effort) + create fuel_types, gearboxes, vehicle_years and new lookup tables
try {
  // ---------- new lookup tables (best-effort create) ----------
$mysqli->query("
  CREATE TABLE IF NOT EXISTS car_colors (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(191) NOT NULL,
    `key` VARCHAR(100) DEFAULT NULL,
    `order` INT NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$mysqli->query("
  CREATE TABLE IF NOT EXISTS engine_volumes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    label VARCHAR(64) NOT NULL,
    `order` INT NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$mysqli->query("
  CREATE TABLE IF NOT EXISTS passenger_counts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cnt SMALLINT UNSIGNED NOT NULL,
    label VARCHAR(64) DEFAULT NULL,
    `order` INT NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY ux_cnt (cnt)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$mysqli->query("
  CREATE TABLE IF NOT EXISTS interior_colors (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(191) NOT NULL,
    `order` INT NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$mysqli->query("
  CREATE TABLE IF NOT EXISTS upholstery_types (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(191) NOT NULL,
    `order` INT NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$mysqli->query("
  CREATE TABLE IF NOT EXISTS ignition_types (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(64) NOT NULL,
    `key` VARCHAR(64) DEFAULT NULL,
    `order` INT NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$mysqli->query("
  CREATE TABLE IF NOT EXISTS regions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(191) NOT NULL,
    `order` INT NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$mysqli->query("
  CREATE TABLE IF NOT EXISTS districts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    region_id INT UNSIGNED NOT NULL,
    name VARCHAR(191) NOT NULL,
    `order` INT NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX (region_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

    // vehicle_types
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS vehicle_types (
          id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          `key` VARCHAR(100) DEFAULT NULL,
          name VARCHAR(191) NOT NULL,
          `order` INT NOT NULL DEFAULT 0,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // vehicle_bodies (no FK constraint declared to avoid compatibility issues)
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS vehicle_bodies (
          id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          vehicle_type_id INT UNSIGNED NOT NULL,
          name VARCHAR(191) NOT NULL,
          `key` VARCHAR(100) DEFAULT NULL,
          `order` INT NOT NULL DEFAULT 0,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          INDEX (vehicle_type_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // fuel_types
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS fuel_types (
          id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          name VARCHAR(191) NOT NULL,
          `key` VARCHAR(100) DEFAULT NULL,
          `order` INT NOT NULL DEFAULT 0,
          active TINYINT(1) NOT NULL DEFAULT 1,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // gearboxes
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS gearboxes (
          id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          name VARCHAR(191) NOT NULL,
          `key` VARCHAR(100) DEFAULT NULL,
          `order` INT NOT NULL DEFAULT 0,
          active TINYINT(1) NOT NULL DEFAULT 1,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // vehicle_years
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS vehicle_years (
          id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          `year` SMALLINT UNSIGNED NOT NULL,
          `order` INT NOT NULL DEFAULT 0,
          active TINYINT(1) NOT NULL DEFAULT 1,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY ux_year (year)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (Throwable $e) {
    // ignore creation errors silently
}

// POST handling for brands/models, parts/components, types/bodies, and new helpers: fuel_types, gearboxes, years, colors, etc.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        // ----------------- new lookup handlers -----------------

        // Car colors
        if ($action === 'add_color' && s_post('color_name') !== '') {
            $name = s_post('color_name'); $key = s_post('color_key') ?: null; $order = (int)s_post('color_order');
            $st = $mysqli->prepare("INSERT INTO car_colors (`key`, name, `order`, active) VALUES (?, ?, ?, 1)");
            $st->bind_param('ssi', $key, $name, $order); $st->execute(); $st->close();
        } elseif ($action === 'edit_color') {
            $id = (int)s_post('id'); $name = s_post('name'); $key = s_post('key') ?: null; $order = (int)s_post('order');
            if ($id>0) { $st = $mysqli->prepare("UPDATE car_colors SET `key`=?, name=?, `order`=? WHERE id=?"); $st->bind_param('ssii',$key,$name,$order,$id); $st->execute(); $st->close(); }
        } elseif ($action === 'delete_color') {
            $id = (int)s_post('id'); if ($id>0) { $st=$mysqli->prepare("DELETE FROM car_colors WHERE id=?"); $st->bind_param('i',$id); $st->execute(); $st->close(); }
        } elseif ($action === 'toggle_color') {
            $id = (int)s_post('id'); $val = (int)s_post('value') ? 1:0; if ($id>0) { $st=$mysqli->prepare("UPDATE car_colors SET active=? WHERE id=?"); $st->bind_param('ii',$val,$id); $st->execute(); $st->close(); }
        }

        // Engine volumes
        if ($action === 'add_engine' && s_post('engine_label') !== '') {
            $label = s_post('engine_label'); $order = (int)s_post('engine_order');
            $st = $mysqli->prepare("INSERT INTO engine_volumes (label, `order`, active) VALUES (?, ?, 1)");
            $st->bind_param('si', $label, $order); $st->execute(); $st->close();
        } elseif ($action === 'edit_engine') {
            $id=(int)s_post('id'); $label=s_post('label'); $order=(int)s_post('order');
            if ($id>0) { $st=$mysqli->prepare("UPDATE engine_volumes SET label=?, `order`=? WHERE id=?"); $st->bind_param('sii',$label,$order,$id); $st->execute(); $st->close(); }
        } elseif ($action === 'delete_engine') {
            $id=(int)s_post('id'); if ($id>0){ $st=$mysqli->prepare("DELETE FROM engine_volumes WHERE id=?"); $st->bind_param('i',$id); $st->execute(); $st->close(); }
        } elseif ($action === 'toggle_engine') {
            $id=(int)s_post('id'); $val=(int)s_post('value')?1:0; if ($id>0){ $st=$mysqli->prepare("UPDATE engine_volumes SET active=? WHERE id=?"); $st->bind_param('ii',$val,$id); $st->execute(); $st->close(); }
        }

        // Passenger counts
        if ($action === 'add_passenger' && s_post('pc_cnt') !== '') {
            $cnt = (int)s_post('pc_cnt'); $label = s_post('pc_label') ?: null; $order = (int)s_post('pc_order');
            $st = $mysqli->prepare("INSERT IGNORE INTO passenger_counts (cnt, label, `order`, active) VALUES (?, ?, ?, 1)");
            $st->bind_param('isi', $cnt, $label, $order); $st->execute(); $st->close();
        } elseif ($action === 'edit_passenger') {
            $id=(int)s_post('id'); $cnt=(int)s_post('cnt'); $label=s_post('label'); $order=(int)s_post('order');
            if($id>0){ $st=$mysqli->prepare("UPDATE passenger_counts SET cnt=?, label=?, `order`=? WHERE id=?"); $st->bind_param('isii',$cnt,$label,$order,$id); $st->execute(); $st->close(); }
        } elseif ($action === 'delete_passenger') {
            $id=(int)s_post('id'); if($id>0){ $st=$mysqli->prepare("DELETE FROM passenger_counts WHERE id=?"); $st->bind_param('i',$id); $st->execute(); $st->close(); }
        } elseif ($action === 'toggle_passenger') {
            $id=(int)s_post('id'); $val=(int)s_post('value')?1:0; if($id>0){ $st=$mysqli->prepare("UPDATE passenger_counts SET active=? WHERE id=?"); $st->bind_param('ii',$val,$id); $st->execute(); $st->close(); }
        }

        // Interior colors
        if ($action === 'add_interior' && s_post('interior_name') !== '') {
            $name=s_post('interior_name'); $order=(int)s_post('interior_order');
            $st=$mysqli->prepare("INSERT INTO interior_colors (name, `order`, active) VALUES (?, ?, 1)"); $st->bind_param('si',$name,$order); $st->execute(); $st->close();
        } elseif ($action === 'edit_interior') { $id=(int)s_post('id'); $name=s_post('name'); $order=(int)s_post('order'); if($id>0){ $st=$mysqli->prepare("UPDATE interior_colors SET name=?, `order`=? WHERE id=?"); $st->bind_param('sii',$name,$order,$id); $st->execute(); $st->close(); } }
        elseif ($action === 'delete_interior') { $id=(int)s_post('id'); if($id>0){ $st=$mysqli->prepare("DELETE FROM interior_colors WHERE id=?"); $st->bind_param('i',$id); $st->execute(); $st->close(); } }
        elseif ($action === 'toggle_interior') { $id=(int)s_post('id'); $val=(int)s_post('value')?1:0; if($id>0){ $st=$mysqli->prepare("UPDATE interior_colors SET active=? WHERE id=?"); $st->bind_param('ii',$val,$id); $st->execute(); $st->close(); } }

        // Upholstery types
        if ($action === 'add_upholstery' && s_post('upholstery_name') !== '') {
            $name=s_post('upholstery_name'); $order=(int)s_post('upholstery_order');
            $st=$mysqli->prepare("INSERT INTO upholstery_types (name, `order`, active) VALUES (?, ?, 1)"); $st->bind_param('si',$name,$order); $st->execute(); $st->close();
        } elseif ($action === 'edit_upholstery') { $id=(int)s_post('id'); $name=s_post('name'); $order=(int)s_post('order'); if($id>0){ $st=$mysqli->prepare("UPDATE upholstery_types SET name=?, `order`=? WHERE id=?"); $st->bind_param('sii',$name,$order,$id); $st->execute(); $st->close(); } }
        elseif ($action === 'delete_upholstery') { $id=(int)s_post('id'); if($id>0){ $st=$mysqli->prepare("DELETE FROM upholstery_types WHERE id=?"); $st->bind_param('i',$id); $st->execute(); $st->close(); } }
        elseif ($action === 'toggle_upholstery') { $id=(int)s_post('id'); $val=(int)s_post('value')?1:0; if($id>0){ $st=$mysqli->prepare("UPDATE upholstery_types SET active=? WHERE id=?"); $st->bind_param('ii',$val,$id); $st->execute(); $st->close(); } }

        // Ignition types
        if ($action === 'add_ignition' && s_post('ignition_name') !== '') {
            $name=s_post('ignition_name'); $key=s_post('ignition_key')?:null; $order=(int)s_post('ignition_order');
            $st=$mysqli->prepare("INSERT INTO ignition_types (`key`, name, `order`, active) VALUES (?, ?, ?, 1)"); $st->bind_param('ssi',$key,$name,$order); $st->execute(); $st->close();
        } elseif ($action === 'edit_ignition') { $id=(int)s_post('id'); $name=s_post('name'); $key=s_post('key')?:null; $order=(int)s_post('order'); if($id>0){ $st=$mysqli->prepare("UPDATE ignition_types SET `key`=?, name=?, `order`=? WHERE id=?"); $st->bind_param('ssii',$key,$name,$order,$id); $st->execute(); $st->close(); } }
        elseif ($action === 'delete_ignition') { $id=(int)s_post('id'); if($id>0){ $st=$mysqli->prepare("DELETE FROM ignition_types WHERE id=?"); $st->bind_param('i',$id); $st->execute(); $st->close(); } }
        elseif ($action === 'toggle_ignition') { $id=(int)s_post('id'); $val=(int)s_post('value')?1:0; if($id>0){ $st=$mysqli->prepare("UPDATE ignition_types SET active=? WHERE id=?"); $st->bind_param('ii',$val,$id); $st->execute(); $st->close(); } }

        // Regions / Districts
        if ($action === 'add_region' && s_post('region_name') !== '') {
            $name=s_post('region_name'); $order=(int)s_post('region_order');
            $st=$mysqli->prepare("INSERT INTO regions (name, `order`, active) VALUES (?, ?, 1)"); $st->bind_param('si',$name,$order); $st->execute(); $st->close();
        } elseif ($action === 'edit_region') {
            $id=(int)s_post('id'); $name=s_post('name'); $order=(int)s_post('order');
            if($id>0){ $st=$mysqli->prepare("UPDATE regions SET name=?, `order`=? WHERE id=?"); $st->bind_param('sii',$name,$order,$id); $st->execute(); $st->close(); }
        } elseif ($action === 'delete_region') {
            $id=(int)s_post('id'); if($id>0){ $st=$mysqli->prepare("DELETE FROM regions WHERE id=?"); $st->bind_param('i',$id); $st->execute(); $st->close(); }
        } elseif ($action === 'toggle_region') {
            $id=(int)s_post('id'); $val=(int)s_post('value')?1:0; if($id>0){ $st=$mysqli->prepare("UPDATE regions SET active=? WHERE id=?"); $st->bind_param('ii',$val,$id); $st->execute(); $st->close(); }
        }

        // Districts (need region_id)
        if ($action === 'add_district' && s_post('district_name') !== '' && s_post('district_region_id') !== '') {
            $name=s_post('district_name'); $rid=(int)s_post('district_region_id'); $order=(int)s_post('district_order');
            if ($rid>0) { $st=$mysqli->prepare("INSERT INTO districts (region_id, name, `order`, active) VALUES (?, ?, ?, 1)"); $st->bind_param('isi',$rid,$name,$order); $st->execute(); $st->close(); }
        } elseif ($action === 'edit_district') {
            $id=(int)s_post('id'); $name=s_post('name'); $order=(int)s_post('order'); if($id>0){ $st=$mysqli->prepare("UPDATE districts SET name=?, `order`=? WHERE id=?"); $st->bind_param('sii',$name,$order,$id); $st->execute(); $st->close(); }
        } elseif ($action === 'delete_district') {
            $id=(int)s_post('id'); if($id>0){ $st=$mysqli->prepare("DELETE FROM districts WHERE id=?"); $st->bind_param('i',$id); $st->execute(); $st->close(); }
        } elseif ($action === 'toggle_district') {
            $id=(int)s_post('id'); $val=(int)s_post('value')?1:0; if($id>0){ $st=$mysqli->prepare("UPDATE districts SET active=? WHERE id=?"); $st->bind_param('ii',$val,$id); $st->execute(); $st->close(); }
        }

        // Brands
        if ($action === 'add_brand' && s_post('brand') !== '') {
            $stmt = $mysqli->prepare("INSERT INTO brands(name) VALUES(?)");
            $stmt->bind_param('s', $_POST['brand']);
            $stmt->execute(); $stmt->close();
        } elseif ($action === 'edit_brand') {
            $stmt = $mysqli->prepare("UPDATE brands SET name=? WHERE id=?");
            $stmt->bind_param('si', $_POST['name'], $_POST['id']); $stmt->execute(); $stmt->close();
        } elseif ($action === 'delete_brand') {
            $stmt = $mysqli->prepare("DELETE FROM brands WHERE id=?");
            $stmt->bind_param('i', $_POST['id']); $stmt->execute(); $stmt->close();
        }

        // Models
        if ($action === 'add_model' && s_post('model') !== '') {
            $stmt = $mysqli->prepare("INSERT INTO models(brand_id,name) VALUES(?,?)");
            $stmt->bind_param('is', $_POST['brand_id'], $_POST['model']); $stmt->execute(); $stmt->close();
        } elseif ($action === 'edit_model') {
            $stmt = $mysqli->prepare("UPDATE models SET name=? WHERE id=?");
            $stmt->bind_param('si', $_POST['name'], $_POST['id']); $stmt->execute(); $stmt->close();
        } elseif ($action === 'delete_model') {
            $stmt = $mysqli->prepare("DELETE FROM models WHERE id=?");
            $stmt->bind_param('i', $_POST['id']); $stmt->execute(); $stmt->close();
        }

        // Complex parts
        if ($action === 'add_part' && s_post('part') !== '') {
            $stmt = $mysqli->prepare("INSERT INTO complex_parts(name) VALUES(?)");
            $stmt->bind_param('s', $_POST['part']); $stmt->execute(); $stmt->close();
        } elseif ($action === 'edit_part') {
            $stmt = $mysqli->prepare("UPDATE complex_parts SET name=? WHERE id=?");
            $stmt->bind_param('si', $_POST['name'], $_POST['id']); $stmt->execute(); $stmt->close();
        } elseif ($action === 'delete_part') {
            $stmt = $mysqli->prepare("DELETE FROM complex_parts WHERE id=?");
            $stmt->bind_param('i', $_POST['id']); $stmt->execute(); $stmt->close();
        }

        // Components
        if ($action === 'add_component' && s_post('component') !== '') {
            $stmt = $mysqli->prepare("INSERT INTO components(complex_part_id,name) VALUES(?,?)");
            $stmt->bind_param('is', $_POST['part_id'], $_POST['component']); $stmt->execute(); $stmt->close();
        } elseif ($action === 'edit_component') {
            $stmt = $mysqli->prepare("UPDATE components SET name=? WHERE id=?");
            $stmt->bind_param('si', $_POST['name'], $_POST['id']); $stmt->execute(); $stmt->close();
        } elseif ($action === 'delete_component') {
            $stmt = $mysqli->prepare("DELETE FROM components WHERE id=?");
            $stmt->bind_param('i', $_POST['id']); $stmt->execute(); $stmt->close();
        }

        // Vehicle types / bodies
        if ($action === 'add_type' && s_post('type_name') !== '') {
            $key = s_post('type_key') ?: null;
            $order = (int)s_post('type_order');
            $st = $mysqli->prepare("INSERT INTO vehicle_types (`key`, name, `order`) VALUES (?, ?, ?)");
            $st->bind_param('ssi', $key, $_POST['type_name'], $order); $st->execute(); $st->close();
        } elseif ($action === 'edit_type') {
            $id = (int)s_post('id'); $name = s_post('name'); $key = s_post('key') ?: null; $order = (int)s_post('order');
            if ($id > 0) {
                $st = $mysqli->prepare("UPDATE vehicle_types SET `key` = ?, name = ?, `order` = ? WHERE id = ?");
                $st->bind_param('ssii', $key, $name, $order, $id); $st->execute(); $st->close();
            }
        } elseif ($action === 'delete_type') {
            $id = (int)s_post('id');
            if ($id > 0) {
                $st = $mysqli->prepare("DELETE FROM vehicle_types WHERE id = ?"); $st->bind_param('i', $id); $st->execute(); $st->close();
            }
        } elseif ($action === 'add_body') {
            $type_id = (int)s_post('type_id'); $name = s_post('body_name'); $key = s_post('body_key') ?: null; $order = (int)s_post('body_order');
            if ($type_id > 0 && $name !== '') {
                $st = $mysqli->prepare("INSERT INTO vehicle_bodies (vehicle_type_id, name, `key`, `order`) VALUES (?, ?, ?, ?)");
                $st->bind_param('issi', $type_id, $name, $key, $order); $st->execute(); $st->close();
            }
        } elseif ($action === 'edit_body') {
            $id = (int)s_post('id'); $name = s_post('name'); $key = s_post('key') ?: null; $order = (int)s_post('order');
            if ($id > 0) {
                $st = $mysqli->prepare("UPDATE vehicle_bodies SET name = ?, `key` = ?, `order` = ? WHERE id = ?");
                $st->bind_param('ssii', $name, $key, $order, $id); $st->execute(); $st->close();
            }
        } elseif ($action === 'delete_body') {
            $id = (int)s_post('id');
            if ($id > 0) {
                $st = $mysqli->prepare("DELETE FROM vehicle_bodies WHERE id = ?"); $st->bind_param('i', $id); $st->execute(); $st->close();
            }
        }

        //
        // NEW: fuel_types, gearboxes, vehicle_years handlers (add/edit/delete/toggle)
        //

        // Fuel types
        if ($action === 'add_fuel' && s_post('fuel_name') !== '') {
            $key = s_post('fuel_key') ?: null; $order = (int)s_post('fuel_order');
            $st = $mysqli->prepare("INSERT INTO fuel_types (`key`, name, `order`, active) VALUES (?, ?, ?, 1)");
            $st->bind_param('ssi', $key, $_POST['fuel_name'], $order); $st->execute(); $st->close();
        } elseif ($action === 'edit_fuel') {
            $id = (int)s_post('id'); $name = s_post('name'); $key = s_post('key') ?: null; $order = (int)s_post('order');
            if ($id > 0) {
                $st = $mysqli->prepare("UPDATE fuel_types SET `key` = ?, name = ?, `order` = ? WHERE id = ?");
                $st->bind_param('ssii', $key, $name, $order, $id); $st->execute(); $st->close();
            }
        } elseif ($action === 'delete_fuel') {
            $id = (int)s_post('id');
            if ($id > 0) {
                $st = $mysqli->prepare("DELETE FROM fuel_types WHERE id = ?"); $st->bind_param('i', $id); $st->execute(); $st->close();
            }
        } elseif ($action === 'toggle_fuel') {
            $id = (int)s_post('id'); $val = (int)s_post('value') ? 1 : 0;
            if ($id > 0) {
                $st = $mysqli->prepare("UPDATE fuel_types SET active = ? WHERE id = ?"); $st->bind_param('ii', $val, $id); $st->execute(); $st->close();
            }
        }

        // Gearboxes
        if ($action === 'add_gearbox' && s_post('gearbox_name') !== '') {
            $key = s_post('gearbox_key') ?: null; $order = (int)s_post('gearbox_order');
            $st = $mysqli->prepare("INSERT INTO gearboxes (`key`, name, `order`, active) VALUES (?, ?, ?, 1)");
            $st->bind_param('ssi', $key, $_POST['gearbox_name'], $order); $st->execute(); $st->close();
        } elseif ($action === 'edit_gearbox') {
            $id = (int)s_post('id'); $name = s_post('name'); $key = s_post('key') ?: null; $order = (int)s_post('order');
            if ($id > 0) {
                $st = $mysqli->prepare("UPDATE gearboxes SET `key` = ?, name = ?, `order` = ? WHERE id = ?");
                $st->bind_param('ssii', $key, $name, $order, $id); $st->execute(); $st->close();
            }
        } elseif ($action === 'delete_gearbox') {
            $id = (int)s_post('id');
            if ($id > 0) {
                $st = $mysqli->prepare("DELETE FROM gearboxes WHERE id = ?"); $st->bind_param('i', $id); $st->execute(); $st->close();
            }
        } elseif ($action === 'toggle_gearbox') {
            $id = (int)s_post('id'); $val = (int)s_post('value') ? 1 : 0;
            if ($id > 0) {
                $st = $mysqli->prepare("UPDATE gearboxes SET active = ? WHERE id = ?"); $st->bind_param('ii', $val, $id); $st->execute(); $st->close();
            }
        }

        // Vehicle years
        if ($action === 'add_year' && s_post('year_val') !== '') {
            $yr = (int)s_post('year_val'); $order = (int)s_post('year_order');
            if ($yr > 1900 && $yr < 2100) {
                $st = $mysqli->prepare("INSERT IGNORE INTO vehicle_years (`year`, `order`, active) VALUES (?, ?, 1)");
                $st->bind_param('ii', $yr, $order); $st->execute(); $st->close();
            }
        } elseif ($action === 'edit_year') {
            $id = (int)s_post('id'); $yr = (int)s_post('year'); $order = (int)s_post('order');
            if ($id > 0 && $yr > 1900 && $yr < 2100) {
                $st = $mysqli->prepare("UPDATE vehicle_years SET `year` = ?, `order` = ? WHERE id = ?");
                $st->bind_param('iii', $yr, $order, $id); $st->execute(); $st->close();
            }
        } elseif ($action === 'delete_year') {
            $id = (int)s_post('id');
            if ($id > 0) {
                $st = $mysqli->prepare("DELETE FROM vehicle_years WHERE id = ?"); $st->bind_param('i', $id); $st->execute(); $st->close();
            }
        } elseif ($action === 'toggle_year') {
            $id = (int)s_post('id'); $val = (int)s_post('value') ? 1 : 0;
            if ($id > 0) {
                $st = $mysqli->prepare("UPDATE vehicle_years SET active = ? WHERE id = ?"); $st->bind_param('ii', $val, $id); $st->execute(); $st->close();
            }
        }

    } catch (Throwable $e) {
        // silent
    }

    // redirect back to same page to avoid resubmission
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Fetch lists for display
$brands = []; $parts = []; $types = []; $bodiesByType = [];
$fuel_types = []; $gearboxes = []; $vehicle_years = [];
$car_colors = []; $engine_volumes = []; $passenger_counts = [];
$interior_colors = []; $upholstery_types = []; $ignition_types = [];
$regions = []; $districts = [];
try {
    $res = $mysqli->query("SELECT * FROM brands ORDER BY name");
    while ($r = $res->fetch_assoc()) $brands[] = $r;

    $res = $mysqli->query("SELECT * FROM complex_parts ORDER BY name");
    while ($r = $res->fetch_assoc()) $parts[] = $r;

    $res = $mysqli->query("SELECT * FROM vehicle_types ORDER BY `order` ASC, name ASC");
    while ($r = $res->fetch_assoc()) $types[] = $r;

    $res = $mysqli->query("SELECT * FROM vehicle_bodies ORDER BY vehicle_type_id ASC, `order` ASC, name ASC");
    while ($b = $res->fetch_assoc()) {
        $bodiesByType[(int)$b['vehicle_type_id']][] = $b;
    }
    $res = $mysqli->query("SELECT * FROM fuel_types ORDER BY `order` ASC, name ASC");
    while ($r = $res->fetch_assoc()) $fuel_types[] = $r;

    $res = $mysqli->query("SELECT * FROM gearboxes ORDER BY `order` ASC, name ASC");
    while ($r = $res->fetch_assoc()) $gearboxes[] = $r;

    $res = $mysqli->query("SELECT * FROM vehicle_years ORDER BY `order` ASC, `year` ASC");
    while ($r = $res->fetch_assoc()) $vehicle_years[] = $r;

    $res = $mysqli->query("SELECT * FROM car_colors ORDER BY `order` ASC, name ASC");
    while($r = $res->fetch_assoc()) $car_colors[] = $r;

    $res = $mysqli->query("SELECT * FROM engine_volumes ORDER BY `order` ASC, label ASC");
    while($r = $res->fetch_assoc()) $engine_volumes[] = $r;

    $res = $mysqli->query("SELECT * FROM passenger_counts ORDER BY `order` ASC, cnt ASC");
    while($r = $res->fetch_assoc()) $passenger_counts[] = $r;

    $res = $mysqli->query("SELECT * FROM interior_colors ORDER BY `order` ASC, name ASC");
    while($r = $res->fetch_assoc()) $interior_colors[] = $r;

    $res = $mysqli->query("SELECT * FROM upholstery_types ORDER BY `order` ASC, name ASC");
    while($r = $res->fetch_assoc()) $upholstery_types[] = $r;

    $res = $mysqli->query("SELECT * FROM ignition_types ORDER BY `order` ASC, name ASC");
    while($r = $res->fetch_assoc()) $ignition_types[] = $r;

    $res = $mysqli->query("SELECT * FROM regions ORDER BY `order` ASC, name ASC");
    while($r = $res->fetch_assoc()) $regions[] = $r;

    $res = $mysqli->query("SELECT * FROM districts ORDER BY region_id ASC, `order` ASC, name ASC");
    while($r = $res->fetch_assoc()) $districts[] = $r;

} catch (Throwable $e) {
    // ignore
}

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Админка — Справочники (Бренды / Части / ТипТс / Доп. параметры)</title>
  <link rel="stylesheet" href="/mehanik/assets/css/style.css">
  <style>
    .admin-tools { max-width:1100px; margin:20px auto; background:#fff; border-radius:10px; padding:18px; box-shadow:0 2px 6px rgba(0,0,0,0.06); font-family:Inter, Arial, sans-serif; }
    .tabs { display:flex; gap:8px; margin-bottom:14px; flex-wrap:wrap; }
    .tab-btn { padding:8px 12px; border-radius:8px; border:1px solid #e6eef7; background:transparent; cursor:pointer; font-weight:600; }
    .tab-btn.active { background:linear-gradient(180deg,#0b57a4,#074b82); color:#fff; border-color:transparent; box-shadow:0 8px 24px rgba(11,87,164,0.08); }
    .tab-panel { display:none; }
    .tab-panel.active { display:block; }
    h2 { margin-top:0; }
    form.inline { display:inline-flex; gap:6px; margin:6px 0; align-items:center; }
    input[type=text], input[type=number] { padding:8px 10px; border:1px solid #e6eef7; border-radius:8px; }
    button { padding:8px 10px; border-radius:8px; border:0; cursor:pointer; }
    .btn-add { background:#16a34a; color:#fff; }
    .btn-save { background:#0ea5e9; color:#fff; }
    .btn-del { background:#ef4444; color:#fff; }
    .btn-toggle { background:#f59e0b; color:#fff; }
    .cols { display:grid; grid-template-columns:1fr 1fr; gap:18px; margin-top:12px; }
    @media(max-width:900px){ .cols { grid-template-columns:1fr } }
    ul { list-style:none; padding:0; margin:0; }
    li.item { padding:10px; border:1px solid #eef3f7; border-radius:8px; margin-bottom:10px; background:#fbfdff; }
    .small { color:#6b7280; font-size:.95rem; }
    .muted { color:#6b7280; font-size:.9rem; margin-top:8px; }
    .toggle-ind { font-weight:700; margin-left:8px; }
  </style>
</head>
<body>
<?php require_once __DIR__.'/header.php'; ?>

<div class="admin-tools" role="main">
  <h1>Справочники</h1>

  <div class="tabs" role="tablist" aria-label="Admin reference tabs">
    <button class="tab-btn" data-tab="brands" role="tab">Бренды / Модели</button>
    <button class="tab-btn" data-tab="parts" role="tab">Комплексные части / Компоненты</button>
    <button class="tab-btn" data-tab="types" role="tab">ТипТс / Кузова</button>
    <button class="tab-btn" data-tab="extras" role="tab">Доп. параметры (год/топливо/коробка)</button>
  </div>

  <!-- Brands / Models -->
  <div id="panel-brands" class="tab-panel">
    <h2>Бренды</h2>
    <form method="post" style="display:flex;gap:8px;margin-bottom:10px;">
      <input type="text" name="brand" placeholder="Новый бренд">
      <button name="action" value="add_brand" class="btn-add">Добавить бренд</button>
    </form>

    <ul>
      <?php
        $bRes = $mysqli->query("SELECT * FROM brands ORDER BY name");
        while ($b = $bRes->fetch_assoc()):
      ?>
        <li class="item">
          <form method="post" class="inline" style="align-items:center;">
            <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
            <input type="text" name="name" value="<?= h($b['name']) ?>" style="min-width:220px;">
            <button name="action" value="edit_brand" class="btn-save">Сохранить</button>
            <button name="action" value="delete_brand" class="btn-del" onclick="return confirm('Удалить бренд?')">Удалить</button>
          </form>

          <div style="margin-top:8px;">
            <form method="post" class="inline">
              <input type="hidden" name="brand_id" value="<?= (int)$b['id'] ?>">
              <input type="text" name="model" placeholder="Новая модель">
              <button name="action" value="add_model" class="btn-add">Добавить модель</button>
            </form>

            <ul style="margin-top:8px;">
              <?php
                $ms = $mysqli->query("SELECT * FROM models WHERE brand_id=".(int)$b['id']." ORDER BY name");
                while ($m = $ms->fetch_assoc()):
              ?>
                <li style="margin-top:6px;">
                  <form method="post" class="inline">
                    <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                    <input type="text" name="name" value="<?= h($m['name']) ?>">
                    <button name="action" value="edit_model" class="btn-save">Сохранить</button>
                    <button name="action" value="delete_model" class="btn-del" onclick="return confirm('Удалить модель?')">Удалить</button>
                  </form>
                </li>
              <?php endwhile; ?>
            </ul>
          </div>
        </li>
      <?php endwhile; ?>
    </ul>
  </div>

  <!-- Parts / Components -->
  <div id="panel-parts" class="tab-panel">
    <h2>Комплексные части</h2>
    <form method="post" style="display:flex;gap:8px;margin-bottom:10px;">
      <input type="text" name="part" placeholder="Новая часть">
      <button name="action" value="add_part" class="btn-add">Добавить часть</button>
    </form>

    <ul>
      <?php
        $pRes = $mysqli->query("SELECT * FROM complex_parts ORDER BY name");
        while ($p = $pRes->fetch_assoc()):
      ?>
        <li class="item">
          <form method="post" class="inline">
            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
            <input type="text" name="name" value="<?= h($p['name']) ?>" style="min-width:220px;">
            <button name="action" value="edit_part" class="btn-save">Сохранить</button>
            <button name="action" value="delete_part" class="btn-del" onclick="return confirm('Удалить часть?')">Удалить</button>
          </form>

          <div style="margin-top:8px;">
            <form method="post" class="inline">
              <input type="hidden" name="part_id" value="<?= (int)$p['id'] ?>">
              <input type="text" name="component" placeholder="Новый компонент">
              <button name="action" value="add_component" class="btn-add">Добавить компонент</button>
            </form>

            <ul style="margin-top:8px;">
              <?php
                $cs = $mysqli->query("SELECT * FROM components WHERE complex_part_id=".(int)$p['id']." ORDER BY name");
                while ($c = $cs->fetch_assoc()):
              ?>
                <li style="margin-top:6px;">
                  <form method="post" class="inline">
                    <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                    <input type="text" name="name" value="<?= h($c['name']) ?>">
                    <button name="action" value="edit_component" class="btn-save">Сохранить</button>
                    <button name="action" value="delete_component" class="btn-del" onclick="return confirm('Удалить компонент?')">Удалить</button>
                  </form>
                </li>
              <?php endwhile; ?>
            </ul>
          </div>
        </li>
      <?php endwhile; ?>
    </ul>
  </div>

  <!-- Vehicle types / bodies -->
  <div id="panel-types" class="tab-panel">
    <h2>ТипТс / Кузова</h2>

    <form method="post" class="inline" style="gap:8px;margin-bottom:10px;">
      <input type="text" name="type_name" placeholder="Новый тип (напр. Легковые)">
      <input type="text" name="type_key" placeholder="ключ (напр. passenger)">
      <input type="number" name="type_order" placeholder="порядок" style="width:96px">
      <button name="action" value="add_type" class="btn-add">Добавить тип</button>
    </form>

    <div class="cols">
      <div>
        <ul>
          <?php foreach ($types as $t): ?>
            <li class="item">
              <div style="display:flex;justify-content:space-between;align-items:center;">
                <form method="post" class="inline">
                  <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                  <input type="text" name="name" value="<?= h($t['name']) ?>" style="min-width:200px;">
                  <input type="text" name="key" value="<?= h($t['key']) ?>" placeholder="ключ" style="width:140px;">
                  <input type="number" name="order" value="<?= (int)$t['order'] ?>" style="width:86px;">
                  <button name="action" value="edit_type" class="btn-save">Сохранить</button>
                </form>

                <form method="post" onsubmit="return confirm('Удалить тип и все его кузова?');">
                  <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                  <button name="action" value="delete_type" class="btn-del">Удалить</button>
                </form>
              </div>

              <div style="margin-top:10px;">
                <form method="post" class="inline">
                  <input type="hidden" name="type_id" value="<?= (int)$t['id'] ?>">
                  <input type="text" name="body_name" placeholder="Новый кузов (напр. Седан)" style="min-width:180px;">
                  <input type="text" name="body_key" placeholder="ключ" style="width:140px;">
                  <input type="number" name="body_order" placeholder="порядок" style="width:86px;">
                  <button name="action" value="add_body" class="btn-add">Добавить кузов</button>
                </form>

                <div style="margin-top:8px;">
                  <?php $bodies = $bodiesByType[(int)$t['id']] ?? []; if (!$bodies): ?>
                    <div class="small">Кузова не заданы</div>
                  <?php else: foreach ($bodies as $b): ?>
                    <div style="display:flex;gap:8px;align-items:center;margin-top:6px;">
                      <form method="post" class="inline">
                        <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                        <input type="text" name="name" value="<?= h($b['name']) ?>" style="min-width:160px;">
                        <input type="text" name="key" value="<?= h($b['key']) ?>" placeholder="ключ" style="width:140px;">
                        <input type="number" name="order" value="<?= (int)$b['order'] ?>" style="width:86px;">
                        <button name="action" value="edit_body" class="btn-save">Сохранить</button>
                      </form>

                      <form method="post" onsubmit="return confirm('Удалить кузов?');">
                        <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                        <button name="action" value="delete_body" class="btn-del">Удалить</button>
                      </form>
                    </div>
                  <?php endforeach; endif; ?>
                </div>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>

      <div>
        <h3>Подсказки</h3>
        <p class="small">Поле <strong>key</strong> — короткий стабильный идентификатор (латиница, без пробелов). Его удобнее использовать в API и шаблонах.</p>
        <p class="small">Поле <strong>order</strong> — приоритет сортировки (меньше = выше).</p>

        <h3>Экспорт</h3>
        <form method="get" action="<?= h($_SERVER['PHP_SELF']) ?>">
          <button type="submit" name="export" value="1" class="btn">Скачать JSON</button>
        </form>

        <?php if (!empty($_GET['export'])):
            $export = [];
            foreach ($types as $t) {
                $tid = (int)$t['id'];
                $export[] = [
                    'id'=>$tid,'key'=>$t['key'],'name'=>$t['name'],'order'=>(int)$t['order'],
                    'bodies'=> array_map(function($b){ return ['id'=>(int)$b['id'],'key'=>$b['key'],'name'=>$b['name'],'order'=>(int)$b['order']]; }, $bodiesByType[$tid] ?? [])
                ];
            }
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="vehicle_types_export_'.date('Ymd_His').'.json"');
            echo json_encode($export, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            exit;
        endif; ?>

      </div>
    </div>
  </div>

  <!-- Extras: years, fuel_types, gearboxes and new lookups -->
  <div id="panel-extras" class="tab-panel">
    <h2>Дополнительные параметры</h2>

    <!-- New extras: colors, engine volumes, passengers, interior, upholstery, ignition, regions/districts -->
<div class="cols" style="margin-top:16px;">

  <!-- Colors -->
  <div>
    <h3>Цвета авто</h3>
    <form method="post" class="inline" style="margin-bottom:10px;">
      <input type="text" name="color_name" placeholder="Название цвета" style="min-width:160px;">
      <input type="text" name="color_key" placeholder="ключ" style="width:120px;">
      <input type="number" name="color_order" placeholder="порядок" style="width:86px;">
      <button name="action" value="add_color" class="btn-add">Добавить</button>
    </form>

    <ul>
      <?php foreach ($car_colors as $c): ?>
        <li class="item">
          <form method="post" class="inline">
            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
            <input type="text" name="name" value="<?= h($c['name']) ?>" style="min-width:160px;">
            <input type="text" name="key" value="<?= h($c['key'] ?? '') ?>" style="width:120px;">
            <input type="number" name="order" value="<?= (int)$c['order'] ?>" style="width:86px;">
            <button name="action" value="edit_color" class="btn-save">Сохранить</button>
            <button name="action" value="delete_color" class="btn-del" onclick="return confirm('Удалить цвет?')">Удалить</button>
          </form>
          <form method="post" style="display:inline;">
            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
            <input type="hidden" name="value" value="<?= $c['active'] ? '0' : '1' ?>">
            <button name="action" value="toggle_color" class="btn-toggle"><?= $c['active'] ? 'Откл.' : 'Вкл.' ?></button>
            <span class="toggle-ind"><?= $c['active'] ? 'активен' : 'выключен' ?></span>
          </form>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>

  <!-- Engine volumes -->
  <div>
    <h3>Объёмы двигателя</h3>
    <form method="post" class="inline" style="margin-bottom:10px;">
      <input type="text" name="engine_label" placeholder="1.6 / 2.0T" style="min-width:160px;">
      <input type="number" name="engine_order" placeholder="порядок" style="width:86px;">
      <button name="action" value="add_engine" class="btn-add">Добавить</button>
    </form>

    <ul>
      <?php foreach ($engine_volumes as $ev): ?>
        <li class="item">
          <form method="post" class="inline">
            <input type="hidden" name="id" value="<?= (int)$ev['id'] ?>">
            <input type="text" name="label" value="<?= h($ev['label']) ?>" style="min-width:160px;">
            <input type="number" name="order" value="<?= (int)$ev['order'] ?>" style="width:86px;">
            <button name="action" value="edit_engine" class="btn-save">Сохранить</button>
            <button name="action" value="delete_engine" class="btn-del" onclick="return confirm('Удалить?')">Удалить</button>
          </form>
          <form method="post" style="display:inline;">
            <input type="hidden" name="id" value="<?= (int)$ev['id'] ?>">
            <input type="hidden" name="value" value="<?= $ev['active'] ? '0' : '1' ?>">
            <button name="action" value="toggle_engine" class="btn-toggle"><?= $ev['active'] ? 'Откл.' : 'Вкл.' ?></button>
            <span class="toggle-ind"><?= $ev['active'] ? 'активен' : 'выключен' ?></span>
          </form>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>

  <!-- Passengers -->
  <div>
    <h3>Количество пассажиров</h3>
    <form method="post" class="inline" style="margin-bottom:10px;">
      <input type="number" name="pc_cnt" placeholder="5" style="width:96px;">
      <input type="text" name="pc_label" placeholder="5 мест" style="min-width:120px;">
      <input type="number" name="pc_order" placeholder="порядок" style="width:86px;">
      <button name="action" value="add_passenger" class="btn-add">Добавить</button>
    </form>

    <ul>
      <?php foreach ($passenger_counts as $pc): ?>
        <li class="item">
          <form method="post" class="inline">
            <input type="hidden" name="id" value="<?= (int)$pc['id'] ?>">
            <input type="number" name="cnt" value="<?= (int)$pc['cnt'] ?>" style="width:86px;">
            <input type="text" name="label" value="<?= h($pc['label'] ?? '') ?>" style="min-width:120px;">
            <input type="number" name="order" value="<?= (int)$pc['order'] ?>" style="width:86px;">
            <button name="action" value="edit_passenger" class="btn-save">Сохранить</button>
            <button name="action" value="delete_passenger" class="btn-del" onclick="return confirm('Удалить?')">Удалить</button>
          </form>
          <form method="post" style="display:inline;">
            <input type="hidden" name="id" value="<?= (int)$pc['id'] ?>">
            <input type="hidden" name="value" value="<?= $pc['active'] ? '0' : '1' ?>">
            <button name="action" value="toggle_passenger" class="btn-toggle"><?= $pc['active'] ? 'Откл.' : 'Вкл.' ?></button>
            <span class="toggle-ind"><?= $pc['active'] ? 'активен' : 'выключен' ?></span>
          </form>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>

  <!-- Interior & Upholstery (stacked) -->
  <div>
    <h3>Цвета салона</h3>
    <form method="post" class="inline" style="margin-bottom:10px;">
      <input type="text" name="interior_name" placeholder="Чёрный / Бежевый" style="min-width:160px;">
      <input type="number" name="interior_order" placeholder="порядок" style="width:86px;">
      <button name="action" value="add_interior" class="btn-add">Добавить</button>
    </form>
    <ul>
      <?php foreach($interior_colors as $ic): ?>
        <li class="item">
          <form method="post" class="inline">
            <input type="hidden" name="id" value="<?= (int)$ic['id'] ?>">
            <input type="text" name="name" value="<?= h($ic['name']) ?>" style="min-width:160px;">
            <input type="number" name="order" value="<?= (int)$ic['order'] ?>" style="width:86px;">
            <button name="action" value="edit_interior" class="btn-save">Сохранить</button>
            <button name="action" value="delete_interior" class="btn-del" onclick="return confirm('Удалить?')">Удалить</button>
          </form>
          <form method="post" style="display:inline;">
            <input type="hidden" name="id" value="<?= (int)$ic['id'] ?>">
            <input type="hidden" name="value" value="<?= $ic['active'] ? '0' : '1' ?>">
            <button name="action" value="toggle_interior" class="btn-toggle"><?= $ic['active'] ? 'Откл.' : 'Вкл.' ?></button>
            <span class="toggle-ind"><?= $ic['active'] ? 'активен' : 'выключен' ?></span>
          </form>
        </li>
      <?php endforeach; ?>
    </ul>

    <h3 style="margin-top:14px;">Обшивка</h3>
    <form method="post" class="inline" style="margin-bottom:10px;">
      <input type="text" name="upholstery_name" placeholder="Ткань / Кожа" style="min-width:160px;">
      <input type="number" name="upholstery_order" placeholder="порядок" style="width:86px;">
      <button name="action" value="add_upholstery" class="btn-add">Добавить</button>
    </form>
    <ul>
      <?php foreach($upholstery_types as $up): ?>
        <li class="item">
          <form method="post" class="inline">
            <input type="hidden" name="id" value="<?= (int)$up['id'] ?>">
            <input type="text" name="name" value="<?= h($up['name']) ?>" style="min-width:160px;">
            <input type="number" name="order" value="<?= (int)$up['order'] ?>" style="width:86px;">
            <button name="action" value="edit_upholstery" class="btn-save">Сохранить</button>
            <button name="action" value="delete_upholstery" class="btn-del" onclick="return confirm('Удалить?')">Удалить</button>
          </form>
          <form method="post" style="display:inline;">
            <input type="hidden" name="id" value="<?= (int)$up['id'] ?>">
            <input type="hidden" name="value" value="<?= $up['active'] ? '0' : '1' ?>">
            <button name="action" value="toggle_upholstery" class="btn-toggle"><?= $up['active'] ? 'Откл.' : 'Вкл.' ?></button>
            <span class="toggle-ind"><?= $up['active'] ? 'активен' : 'выключен' ?></span>
          </form>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>

  <!-- Ignition types -->
  <div>
    <h3>Типы зажигания</h3>
    <form method="post" class="inline" style="margin-bottom:10px;">
      <input type="text" name="ignition_name" placeholder="Ключевой / Кнопочный / Пульт" style="min-width:160px;">
      <input type="text" name="ignition_key" placeholder="ключ" style="width:120px;">
      <input type="number" name="ignition_order" placeholder="порядок" style="width:86px;">
      <button name="action" value="add_ignition" class="btn-add">Добавить</button>
    </form>

    <ul>
      <?php foreach($ignition_types as $it): ?>
        <li class="item">
          <form method="post" class="inline">
            <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
            <input type="text" name="name" value="<?= h($it['name']) ?>" style="min-width:160px;">
            <input type="text" name="key" value="<?= h($it['key'] ?? '') ?>" style="width:120px;">
            <input type="number" name="order" value="<?= (int)$it['order'] ?>" style="width:86px;">
            <button name="action" value="edit_ignition" class="btn-save">Сохранить</button>
            <button name="action" value="delete_ignition" class="btn-del" onclick="return confirm('Удалить?')">Удалить</button>
          </form>
          <form method="post" style="display:inline;">
            <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
            <input type="hidden" name="value" value="<?= $it['active'] ? '0' : '1' ?>">
            <button name="action" value="toggle_ignition" class="btn-toggle"><?= $it['active'] ? 'Откл.' : 'Вкл.' ?></button>
            <span class="toggle-ind"><?= $it['active'] ? 'активен' : 'выключен' ?></span>
          </form>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>

  <!-- Regions & Districts -->
  <div>
    <h3>Велаят / Этрапы</h3>
    <form method="post" class="inline" style="margin-bottom:10px;">
      <input type="text" name="region_name" placeholder="Новый велаят" style="min-width:160px;">
      <input type="number" name="region_order" placeholder="порядок" style="width:86px;">
      <button name="action" value="add_region" class="btn-add">Добавить</button>
    </form>

    <ul>
      <?php foreach ($regions as $r): ?>
        <li class="item">
          <form method="post" class="inline">
            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <input type="text" name="name" value="<?= h($r['name']) ?>" style="min-width:160px;">
            <input type="number" name="order" value="<?= (int)$r['order'] ?>" style="width:86px;">
            <button name="action" value="edit_region" class="btn-save">Сохранить</button>
            <button name="action" value="delete_region" class="btn-del" onclick="return confirm('Удалить велаят?')">Удалить</button>
          </form>
          <form method="post" style="display:inline;">
            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <input type="hidden" name="value" value="<?= $r['active'] ? '0' : '1' ?>">
            <button name="action" value="toggle_region" class="btn-toggle"><?= $r['active'] ? 'Откл.' : 'Вкл.' ?></button>
            <span class="toggle-ind"><?= $r['active'] ? 'активен' : 'выключен' ?></span>
          </form>

          <!-- add district under this region -->
          <div style="margin-top:8px;">
            <form method="post" class="inline">
              <input type="hidden" name="district_region_id" value="<?= (int)$r['id'] ?>">
              <input type="text" name="district_name" placeholder="Новый этрап" style="min-width:140px;">
              <input type="number" name="district_order" placeholder="порядок" style="width:86px;">
              <button name="action" value="add_district" class="btn-add">Добавить этрап</button>
            </form>

            <ul style="margin-top:8px;">
              <?php foreach ($districts as $d): ?>
                <?php if ((int)$d['region_id'] === (int)$r['id']): ?>
                  <li style="margin-top:6px;">
                    <form method="post" class="inline">
                      <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                      <input type="text" name="name" value="<?= h($d['name']) ?>" style="min-width:140px;">
                      <input type="number" name="order" value="<?= (int)$d['order'] ?>" style="width:86px;">
                      <button name="action" value="edit_district" class="btn-save">Сохранить</button>
                      <button name="action" value="delete_district" class="btn-del" onclick="return confirm('Удалить этрап?')">Удалить</button>
                    </form>
                    <form method="post" style="display:inline;">
                      <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                      <input type="hidden" name="value" value="<?= $d['active'] ? '0' : '1' ?>">
                      <button name="action" value="toggle_district" class="btn-toggle"><?= $d['active'] ? 'Откл.' : 'Вкл.' ?></button>
                      <span class="toggle-ind"><?= $d['active'] ? 'активен' : 'выключен' ?></span>
                    </form>
                  </li>
                <?php endif; ?>
              <?php endforeach; ?>
            </ul>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>

</div>

    <div class="cols">
      <!-- Years -->
      <div>
        <h3>Годы выпуска</h3>
        <form method="post" class="inline" style="margin-bottom:10px;">
          <input type="number" name="year_val" placeholder="2020" style="width:120px;">
          <input type="number" name="year_order" placeholder="порядок" style="width:96px;">
          <button name="action" value="add_year" class="btn-add">Добавить год</button>
        </form>

        <ul>
          <?php foreach ($vehicle_years as $y): ?>
            <li class="item">
              <form method="post" class="inline">
                <input type="hidden" name="id" value="<?= (int)$y['id'] ?>">
                <input type="number" name="year" value="<?= (int)$y['year'] ?>" style="width:120px;">
                <input type="number" name="order" value="<?= (int)$y['order'] ?>" style="width:96px;">
                <button name="action" value="edit_year" class="btn-save">Сохранить</button>
                <button name="action" value="delete_year" class="btn-del" onclick="return confirm('Удалить год?')">Удалить</button>
              </form>
              <form method="post" style="display:inline;">
                <input type="hidden" name="id" value="<?= (int)$y['id'] ?>">
                <input type="hidden" name="value" value="<?= $y['active'] ? '0' : '1' ?>">
                <button name="action" value="toggle_year" class="btn-toggle"><?= $y['active'] ? 'Откл.' : 'Вкл.' ?></button>
                <span class="toggle-ind"><?= $y['active'] ? 'активен' : 'выключен' ?></span>
              </form>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>

      <!-- Fuel types -->
      <div>
        <h3>Тип топлива</h3>
        <form method="post" class="inline" style="margin-bottom:10px;">
          <input type="text" name="fuel_name" placeholder="Дизель / Бензин" style="min-width:160px;">
          <input type="text" name="fuel_key" placeholder="ключ" style="width:140px;">
          <input type="number" name="fuel_order" placeholder="порядок" style="width:86px;">
          <button name="action" value="add_fuel" class="btn-add">Добавить топливо</button>
        </form>

        <ul>
          <?php foreach ($fuel_types as $f): ?>
            <li class="item">
              <form method="post" class="inline">
                <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                <input type="text" name="name" value="<?= h($f['name']) ?>" style="min-width:160px;">
                <input type="text" name="key" value="<?= h($f['key']) ?>" style="width:140px;">
                <input type="number" name="order" value="<?= (int)$f['order'] ?>" style="width:86px;">
                <button name="action" value="edit_fuel" class="btn-save">Сохранить</button>
                <button name="action" value="delete_fuel" class="btn-del" onclick="return confirm('Удалить?')">Удалить</button>
              </form>
              <form method="post" style="display:inline;">
                <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                <input type="hidden" name="value" value="<?= $f['active'] ? '0' : '1' ?>">
                <button name="action" value="toggle_fuel" class="btn-toggle"><?= $f['active'] ? 'Откл.' : 'Вкл.' ?></button>
                <span class="toggle-ind"><?= $f['active'] ? 'активен' : 'выключен' ?></span>
              </form>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>

      <!-- Gearboxes -->
      <div>
        <h3>Коробки передач</h3>
        <form method="post" class="inline" style="margin-bottom:10px;">
          <input type="text" name="gearbox_name" placeholder="Автомат / Механика" style="min-width:160px;">
          <input type="text" name="gearbox_key" placeholder="ключ" style="width:140px;">
          <input type="number" name="gearbox_order" placeholder="порядок" style="width:86px;">
          <button name="action" value="add_gearbox" class="btn-add">Добавить коробку</button>
        </form>

        <ul>
          <?php foreach ($gearboxes as $g): ?>
            <li class="item">
              <form method="post" class="inline">
                <input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
                <input type="text" name="name" value="<?= h($g['name']) ?>" style="min-width:160px;">
                <input type="text" name="key" value="<?= h($g['key']) ?>" style="width:140px;">
                <input type="number" name="order" value="<?= (int)$g['order'] ?>" style="width:86px;">
                <button name="action" value="edit_gearbox" class="btn-save">Сохранить</button>
                <button name="action" value="delete_gearbox" class="btn-del" onclick="return confirm('Удалить?')">Удалить</button>
              </form>
              <form method="post" style="display:inline;">
                <input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
                <input type="hidden" name="value" value="<?= $g['active'] ? '0' : '1' ?>">
                <button name="action" value="toggle_gearbox" class="btn-toggle"><?= $g['active'] ? 'Откл.' : 'Вкл.' ?></button>
                <span class="toggle-ind"><?= $g['active'] ? 'активен' : 'выключен' ?></span>
              </form>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  </div>
            
</div>

<!-- подключаем внешний js -->
<script src="/mehanik/assets/js/admin-lookups.js"></script>
</body>
</html>
