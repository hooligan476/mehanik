<?php
// mehanik/admin/vehicle-types.php
require_once __DIR__ . '/../../middleware.php';
require_admin();
require_once __DIR__ . '/../../db.php';

// Ensure tables exist (best-effort)
try {
    if (isset($mysqli) && $mysqli instanceof mysqli) {
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
        // vehicle_bodies
        $mysqli->query("
            CREATE TABLE IF NOT EXISTS vehicle_bodies (
              id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              vehicle_type_id INT UNSIGNED NOT NULL,
              name VARCHAR(191) NOT NULL,
              `key` VARCHAR(100) DEFAULT NULL,
              `order` INT NOT NULL DEFAULT 0,
              created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
              INDEX (vehicle_type_id),
              CONSTRAINT fk_vehicle_bodies_type FOREIGN KEY (vehicle_type_id) REFERENCES vehicle_types(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } elseif (isset($pdo) && $pdo instanceof PDO) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS vehicle_types (
              id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              `key` VARCHAR(100) DEFAULT NULL,
              name VARCHAR(191) NOT NULL,
              `order` INT NOT NULL DEFAULT 0,
              created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $pdo->exec("
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
        // Note: adding FK in PDO fallback omitted because MySQL versions or privileges might block; cascade handled manually if needed.
    }
} catch (Throwable $e) {
    // ignore creation errors; admin will still be able to use page where possible
}

// POST handling
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    // small helper to sanitize string inputs
    $s = function($k){ return isset($_POST[$k]) ? trim((string)$_POST[$k]) : ''; };
    try {
        if ($action === 'add_type') {
            $name = $s('type_name');
            $key  = $s('type_key') ?: null;
            $order = (int)($s('type_order') ?: 0);
            if ($name !== '') {
                if (isset($mysqli) && $mysqli instanceof mysqli) {
                    $st = $mysqli->prepare("INSERT INTO vehicle_types (`key`, name, `order`) VALUES (?, ?, ?)");
                    $st->bind_param('ssi', $key, $name, $order);
                    $st->execute(); $st->close();
                } else {
                    $st = $pdo->prepare("INSERT INTO vehicle_types (`key`, name, `order`) VALUES (:k, :n, :o)");
                    $st->execute([':k'=>$key, ':n'=>$name, ':o'=>$order]);
                }
            }
        } elseif ($action === 'edit_type') {
            $id = (int)$s('id');
            $name = $s('name');
            $key  = $s('key') ?: null;
            $order = (int)($s('order') ?: 0);
            if ($id > 0 && $name !== '') {
                if (isset($mysqli) && $mysqli instanceof mysqli) {
                    $st = $mysqli->prepare("UPDATE vehicle_types SET `key` = ?, name = ?, `order` = ? WHERE id = ?");
                    $st->bind_param('ssii', $key, $name, $order, $id);
                    $st->execute(); $st->close();
                } else {
                    $st = $pdo->prepare("UPDATE vehicle_types SET `key` = :k, name = :n, `order` = :o WHERE id = :id");
                    $st->execute([':k'=>$key, ':n'=>$name, ':o'=>$order, ':id'=>$id]);
                }
            }
        } elseif ($action === 'delete_type') {
            $id = (int)$s('id');
            if ($id > 0) {
                if (isset($mysqli) && $mysqli instanceof mysqli) {
                    $st = $mysqli->prepare("DELETE FROM vehicle_types WHERE id = ?");
                    $st->bind_param('i', $id); $st->execute(); $st->close();
                } else {
                    $st = $pdo->prepare("DELETE FROM vehicle_types WHERE id = :id"); $st->execute([':id'=>$id]);
                }
            }
        } elseif ($action === 'add_body') {
            $type_id = (int)$s('type_id');
            $name = $s('body_name');
            $key  = $s('body_key') ?: null;
            $order = (int)($s('body_order') ?: 0);
            if ($type_id > 0 && $name !== '') {
                if (isset($mysqli) && $mysqli instanceof mysqli) {
                    $st = $mysqli->prepare("INSERT INTO vehicle_bodies (vehicle_type_id, name, `key`, `order`) VALUES (?, ?, ?, ?)");
                    $st->bind_param('issi', $type_id, $name, $key, $order);
                    $st->execute(); $st->close();
                } else {
                    $st = $pdo->prepare("INSERT INTO vehicle_bodies (vehicle_type_id, name, `key`, `order`) VALUES (:tid, :n, :k, :o)");
                    $st->execute([':tid'=>$type_id, ':n'=>$name, ':k'=>$key, ':o'=>$order]);
                }
            }
        } elseif ($action === 'edit_body') {
            $id = (int)$s('id');
            $name = $s('name');
            $key  = $s('key') ?: null;
            $order = (int)($s('order') ?: 0);
            if ($id > 0 && $name !== '') {
                if (isset($mysqli) && $mysqli instanceof mysqli) {
                    $st = $mysqli->prepare("UPDATE vehicle_bodies SET name = ?, `key` = ?, `order` = ? WHERE id = ?");
                    $st->bind_param('ssii', $name, $key, $order, $id);
                    $st->execute(); $st->close();
                } else {
                    $st = $pdo->prepare("UPDATE vehicle_bodies SET name = :n, `key` = :k, `order` = :o WHERE id = :id");
                    $st->execute([':n'=>$name, ':k'=>$key, ':o'=>$order, ':id'=>$id]);
                }
            }
        } elseif ($action === 'delete_body') {
            $id = (int)$s('id');
            if ($id > 0) {
                if (isset($mysqli) && $mysqli instanceof mysqli) {
                    $st = $mysqli->prepare("DELETE FROM vehicle_bodies WHERE id = ?");
                    $st->bind_param('i', $id); $st->execute(); $st->close();
                } else {
                    $st = $pdo->prepare("DELETE FROM vehicle_bodies WHERE id = :id"); $st->execute([':id'=>$id]);
                }
            }
        }
    } catch (Throwable $e) {
        // optionally log error; we won't show raw DB errors to admin UI
    }

    // redirect back to avoid form resubmission
    header('Location: ' . basename(__FILE__));
    exit;
}

// --- Fetch data for display ---
$types = [];
try {
    if (isset($mysqli) && $mysqli instanceof mysqli) {
        $res = $mysqli->query("SELECT * FROM vehicle_types ORDER BY `order` ASC, name ASC");
        while ($r = $res->fetch_assoc()) $types[] = $r;
        // fetch bodies grouped
        $bRes = $mysqli->query("SELECT * FROM vehicle_bodies ORDER BY vehicle_type_id ASC, `order` ASC, name ASC");
        $bodiesByType = [];
        while ($b = $bRes->fetch_assoc()) {
            $bodiesByType[(int)$b['vehicle_type_id']][] = $b;
        }
    } else {
        $st = $pdo->query("SELECT * FROM vehicle_types ORDER BY `order` ASC, name ASC");
        $types = $st->fetchAll(PDO::FETCH_ASSOC);
        $bodiesByType = [];
        $st2 = $pdo->query("SELECT * FROM vehicle_bodies ORDER BY vehicle_type_id ASC, `order` ASC, name ASC");
        $rows = $st2->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $b) $bodiesByType[(int)$b['vehicle_type_id']][] = $b;
    }
} catch (Throwable $e) {
    $types = [];
    $bodiesByType = [];
}

// small helper for escaping
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }

?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Админ — Тип ТС / Кузова</title>
  <link rel="stylesheet" href="/mehanik/assets/css/style.css">
  <style>
    body { background:#f5f6fa; font-family:Inter, Arial, sans-serif; }
    .wrap { max-width:1100px; margin:20px auto; background:#fff; border-radius:10px; padding:20px; box-shadow:0 2px 8px rgba(0,0,0,0.06); }
    h1 { margin:0 0 12px; }
    .grid { display:grid; grid-template-columns: 1fr 1fr; gap:18px; }
    @media(max-width:900px){ .grid{ grid-template-columns: 1fr } }
    form.inline { display:inline-flex; gap:6px; margin:6px 0; align-items:center; }
    input[type=text], input[type=number] { padding:8px 10px; border:1px solid #e6eef7; border-radius:8px; }
    button { padding:8px 10px; border-radius:8px; border:0; cursor:pointer; }
    .btn-add { background:#16a34a; color:#fff; }
    .btn-save { background:#0ea5e9; color:#fff; }
    .btn-del { background:#ef4444; color:#fff; }
    ul { list-style:none; margin:10px 0 0 0; padding:0; }
    li.type { padding:12px; border:1px solid #eef3f7; border-radius:8px; margin-bottom:12px; background:#fbfdff; }
    .type-head { display:flex; gap:8px; align-items:center; justify-content:space-between; }
    .bodies { margin-top:10px; padding-left:14px; }
    .body-item { display:flex; gap:8px; align-items:center; margin:6px 0; }
    label.small { font-size:.9rem; color:#6b7280; margin-right:8px; }
  </style>
</head>
<body>
<?php require_once __DIR__ . '/header.php'; ?>

<div class="wrap">
  <h1>Типы ТС и Кузова</h1>

  <div style="display:flex; gap:12px; align-items:flex-start; margin-bottom:14px;">
    <form method="post" class="inline" style="flex:1;">
      <input type="text" name="type_name" placeholder="Новый тип ТС (напр. Легковые)" required>
      <input type="text" name="type_key" placeholder="ключ (необязательно)">
      <input type="number" name="type_order" placeholder="порядок" style="width:86px;">
      <button name="action" value="add_type" class="btn btn-add">Добавить тип</button>
    </form>
  </div>

  <div class="grid">
    <div>
      <h2>Список типов</h2>
      <ul>
        <?php foreach ($types as $t): ?>
          <li class="type">
            <div class="type-head">
              <div style="display:flex;gap:8px;align-items:center;">
                <form method="post" class="inline" style="align-items:center;">
                  <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                  <input type="text" name="name" value="<?= h($t['name']) ?>" style="min-width:180px;">
                  <input type="text" name="key" value="<?= h($t['key']) ?>" placeholder="ключ" style="width:140px;">
                  <input type="number" name="order" value="<?= (int)$t['order'] ?>" style="width:86px;">
                  <button name="action" value="edit_type" class="btn btn-save">Сохранить</button>
                </form>
              </div>
              <div>
                <form method="post" class="inline" onsubmit="return confirm('Удалить тип и все его кузова?');">
                  <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                  <button name="action" value="delete_type" class="btn btn-del">Удалить тип</button>
                </form>
              </div>
            </div>

            <div class="bodies">
              <div style="display:flex; gap:8px; align-items:center; margin-bottom:6px;">
                <form method="post" class="inline" style="flex:1;">
                  <input type="hidden" name="type_id" value="<?= (int)$t['id'] ?>">
                  <input type="text" name="body_name" placeholder="Новый кузов (напр. Седан)" required style="min-width:180px;">
                  <input type="text" name="body_key" placeholder="ключ (необязательно)" style="width:140px;">
                  <input type="number" name="body_order" placeholder="порядок" style="width:86px;">
                  <button name="action" value="add_body" class="btn btn-add">Добавить кузов</button>
                </form>
              </div>

              <?php
                $bodies = $bodiesByType[(int)$t['id']] ?? [];
                if (count($bodies) === 0) {
                  echo '<div class="small" style="color:#6b7280">Кузова не заданы</div>';
                } else {
                  foreach ($bodies as $b): ?>
                    <div class="body-item">
                      <form method="post" class="inline">
                        <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                        <input type="text" name="name" value="<?= h($b['name']) ?>" style="min-width:160px;">
                        <input type="text" name="key" value="<?= h($b['key']) ?>" placeholder="ключ" style="width:140px;">
                        <input type="number" name="order" value="<?= (int)$b['order'] ?>" style="width:86px;">
                        <button name="action" value="edit_body" class="btn btn-save">Сохранить</button>
                      </form>

                      <form method="post" class="inline" onsubmit="return confirm('Удалить кузов?');" style="margin-left:8px;">
                        <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                        <button name="action" value="delete_body" class="btn btn-del">Удалить</button>
                      </form>
                    </div>
              <?php endforeach; } ?>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>

    <div>
      <h2>Подсказки и экспорт</h2>
      <p class="small">Здесь вы можете управлять типами транспортных средств и их кузовами. Поле <strong>ключ</strong> можно использовать в API (напр. <code>passenger</code>, <code>cargo</code>), а <strong>порядок</strong> — для сортировки в интерфейсе.</p>

      <h3>Экспорт в JSON</h3>
      <form method="get" action="<?= basename(__FILE__) ?>">
        <button type="submit" name="export" value="1" class="btn">Скачать JSON</button>
      </form>

      <?php if (!empty($_GET['export'])):
          // produce export
          $export = [];
          foreach ($types as $t) {
              $tid = (int)$t['id'];
              $export[] = [
                  'id'=>$tid,
                  'key'=>$t['key'],
                  'name'=>$t['name'],
                  'order'=>(int)$t['order'],
                  'bodies'=> array_map(function($b){ return ['id'=>(int)$b['id'],'key'=>$b['key'],'name'=>$b['name'],'order'=>(int)$b['order']]; }, $bodiesByType[$tid] ?? [])
              ];
          }
          header('Content-Type: application/json; charset=utf-8');
          header('Content-Disposition: attachment; filename="vehicle_types_export_'.date('Ymd_His').'.json"');
          echo json_encode($export, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
          exit;
      endif; ?>

      <h3 style="margin-top:18px;">Примеры использования</h3>
      <ul>
        <li class="small"><strong>API</strong>: /mehanik/api/get-bodies.php?vehicle_type=passenger — вернёт список кузовов по ключу.</li>
        <li class="small">В шаблоне добавления автомобиля используйте <code>vehicle_type</code> для выбора типа, затем подгружайте кузова по типу.</li>
      </ul>
    </div>
  </div>
</div>
</body>
</html>
