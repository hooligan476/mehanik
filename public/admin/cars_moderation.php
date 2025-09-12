<?php
// mehanik/public/admin/cars_moderation.php
// Админ: модерация объявлений Автомаркета
session_start();
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'] ?? '', ['admin','superadmin'], true)) {
    header('Location: /mehanik/public/login.php');
    exit;
}

// --- подключение к БД: ищем mehanik/db.php в нескольких местах и/или используем $pdo если он уже есть ---
$projectRoot = dirname(__DIR__, 2); // .../mehanik
$dbCandidates = [
    $projectRoot . '/db.php',
    __DIR__ . '/../db.php',
    __DIR__ . '/../../db.php',
    $projectRoot . '/config/db.php'
];
foreach ($dbCandidates as $dbFile) {
    if (is_file($dbFile)) {
        require_once $dbFile;
        break;
    }
}

// fallback: если нет $pdo, попробуем создать на основе config (если есть)
$configPath = $projectRoot . '/config.php';
$config = is_file($configPath) ? (array)require $configPath : [];
if (!isset($pdo) || !($pdo instanceof PDO)) {
    $dbHost = $config['db_host'] ?? $config['host'] ?? '127.0.0.1';
    $dbName = $config['db_name'] ?? $config['database'] ?? 'mehanik';
    $dbUser = $config['db_user'] ?? $config['user'] ?? 'root';
    $dbPass = $config['db_pass'] ?? $config['pass'] ?? '';
    try {
        $pdo = new PDO("mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo "<h2>Ошибка подключения к БД</h2><p>" . htmlspecialchars($e->getMessage()) . "</p>";
        exit;
    }
}
// --- /DB ---

$basePublic = '/mehanik/public';

// безопасное чтение GET-параметров
$statusFilterRaw = trim((string)($_GET['status'] ?? ''));
$brandFilterRaw  = trim((string)($_GET['brand'] ?? ''));
$modelFilterRaw  = trim((string)($_GET['model'] ?? ''));
$qRaw            = trim((string)($_GET['q'] ?? ''));
$user_id         = isset($_GET['user_id']) && $_GET['user_id'] !== '' ? (int)$_GET['user_id'] : null;

// pagination + sorting (без warning)
$perPage = isset($_GET['per_page']) ? max(1, (int)$_GET['per_page']) : 20;
$page    = max(1, intval($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

// sorting — разрешённые поля
$allowedSort = ['id','brand','model','year','mileage','price','created_at'];
$sortRaw = (string)($_GET['sort'] ?? 'id');
$orderRaw = strtoupper((string)($_GET['order'] ?? 'DESC'));
$sort = in_array($sortRaw, $allowedSort, true) ? $sortRaw : 'id';
$order = $orderRaw === 'ASC' ? 'ASC' : 'DESC';

// helper: собирает дополнительные GET параметры (для ссылок действий)
function preserveQS(array $extra = []) {
    $qs = $_GET;
    foreach ($extra as $k => $v) {
        if ($v === null) unset($qs[$k]); else $qs[$k] = $v;
    }
    if (count($qs) === 0) return '';
    return '?' . http_build_query($qs);
}

// helper для backQS (используется в ссылках действий)
function backQS($page, $user_id, $statusFilterRaw, $brandFilterRaw, $modelFilterRaw, $qRaw, $sort, $order, $perPage) {
    $q = [];
    if ($page > 1) $q['page'] = (int)$page;
    if ($user_id) $q['user_id'] = (int)$user_id;
    if ($statusFilterRaw !== '') $q['status'] = $statusFilterRaw;
    if ($brandFilterRaw !== '') $q['brand'] = $brandFilterRaw;
    if ($modelFilterRaw !== '') $q['model'] = $modelFilterRaw;
    if ($qRaw !== '') $q['q'] = $qRaw;
    if ($sort) $q['sort'] = $sort;
    if ($order) $q['order'] = $order;
    if ($perPage) $q['per_page'] = (int)$perPage;
    return $q ? ('&' . http_build_query($q)) : '';
}

// --- Actions: approve / reject / delete (GET) ---
if (isset($_GET['action'], $_GET['id'])) {
    $id = (int)$_GET['id'];
    $act = $_GET['action'];
    try {
        if ($act === 'approve') {
            $st = $pdo->prepare("UPDATE cars SET status = 'approved' WHERE id = ?");
            $st->execute([$id]);
        } elseif ($act === 'reject') {
            $st = $pdo->prepare("UPDATE cars SET status = 'rejected' WHERE id = ?");
            $st->execute([$id]);
        } elseif ($act === 'delete') {
            // собираем файлы для удаления
            $st = $pdo->prepare("SELECT photo FROM cars WHERE id = ?");
            $st->execute([$id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);

            $toDelete = [];
            if (!empty($row['photo'])) $toDelete[] = $row['photo'];

            $st2 = $pdo->prepare("SELECT file_path FROM car_photos WHERE car_id = ?");
            $st2->execute([$id]);
            $rows = $st2->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) if (!empty($r['file_path'])) $toDelete[] = $r['file_path'];

            // удаляем записи и файлы в транзакции
            $pdo->beginTransaction();
            $delp = $pdo->prepare("DELETE FROM car_photos WHERE car_id = ?");
            $delp->execute([$id]);
            $delc = $pdo->prepare("DELETE FROM cars WHERE id = ?");
            $delc->execute([$id]);
            $pdo->commit();

            // удаляем файлы (несколько вариантов путей)
            foreach ($toDelete as $path) {
                $candidates = [];
                if (strpos($path, 'uploads/') === 0) $candidates[] = __DIR__ . '/../' . $path;
                if (strpos($path, '/mehanik/uploads/') === 0) $candidates[] = __DIR__ . '/..' . $path;
                $candidates[] = __DIR__ . '/../uploads/cars/' . ltrim($path, '/');
                foreach ($candidates as $f) {
                    if (file_exists($f) && is_file($f)) {
                        @unlink($f);
                        break;
                    }
                }
            }
        }
    } catch (Throwable $e) {
        header("Location: {$basePublic}/admin/cars_moderation.php?err=db_error");
        exit;
    }

    // редирект назад с текущими фильтрами
    $qs = backQS($page,$user_id,$statusFilterRaw,$brandFilterRaw,$modelFilterRaw,$qRaw,$sort,$order,$perPage);
    header("Location: {$basePublic}/admin/cars_moderation.php?page={$page}{$qs}");
    exit;
}

// --- Подготовка фильтров ---
// Получаем список брендов (id, name)
$brandsStmt = $pdo->query("SELECT id, name FROM brands ORDER BY name ASC");
$brandsList = $brandsStmt->fetchAll(PDO::FETCH_ASSOC);

// Получаем модели (все) и сгруппируем по brand_id для JS
$modelsStmt = $pdo->query("SELECT id, name, brand_id FROM models ORDER BY brand_id ASC, name ASC");
$modelsAll = $modelsStmt->fetchAll(PDO::FETCH_ASSOC);
$modelsByBrand = [];
foreach ($modelsAll as $m) {
    $bid = (int)$m['brand_id'];
    $modelsByBrand[$bid][] = ['id' => (int)$m['id'], 'name' => $m['name']];
}

// Если бренд не выбран, но пришёл model=id -> найдем бренд этой модели и подставим brandFilterRaw
if ($brandFilterRaw === '' && $modelFilterRaw !== '' && ctype_digit($modelFilterRaw)) {
    $st = $pdo->prepare("SELECT brand_id FROM models WHERE id = ? LIMIT 1");
    $st->execute([(int)$modelFilterRaw]);
    $mr = $st->fetch(PDO::FETCH_ASSOC);
    if ($mr && !empty($mr['brand_id'])) {
        // установим brandFilterRaw так, чтобы сервер показал модели этого бренда
        $brandFilterRaw = (string)(int)$mr['brand_id'];
    }
}

// Преобразуем входные brand/model фильтры: поддерживаем как id так и текст
$brandFilter = null;   // will be brand id or null
$brandNameForQuery = null;
if ($brandFilterRaw !== '') {
    if (ctype_digit($brandFilterRaw)) {
        $brandFilter = (int)$brandFilterRaw;
        $st = $pdo->prepare("SELECT name FROM brands WHERE id = ? LIMIT 1");
        $st->execute([$brandFilter]);
        $bRow = $st->fetch(PDO::FETCH_ASSOC);
        $brandNameForQuery = $bRow ? $bRow['name'] : null;
    } else {
        $brandNameForQuery = $brandFilterRaw;
    }
}

$modelFilter = null;   // will be model id or null
$modelNameForQuery = null;
if ($modelFilterRaw !== '') {
    if (ctype_digit($modelFilterRaw)) {
        $modelFilter = (int)$modelFilterRaw;
        $st = $pdo->prepare("SELECT name FROM models WHERE id = ? LIMIT 1");
        $st->execute([$modelFilter]);
        $mRow = $st->fetch(PDO::FETCH_ASSOC);
        $modelNameForQuery = $mRow ? $mRow['name'] : null;
    } else {
        $modelNameForQuery = $modelFilterRaw;
    }
}

// --- Построение WHERE и параметров для основной выборки ---
$where = '1=1';
$params = [];

if ($user_id) {
    $where .= ' AND c.user_id = :uid';
    $params[':uid'] = $user_id;
}
if ($statusFilterRaw !== '') {
    $where .= ' AND c.status = :status';
    $params[':status'] = $statusFilterRaw;
}
if ($brandNameForQuery !== null && $brandNameForQuery !== '') {
    // cars.brand хранит текст — сравниваем по имени бренда
    $where .= ' AND c.brand = :brand_name';
    $params[':brand_name'] = $brandNameForQuery;
}
if ($modelNameForQuery !== null && $modelNameForQuery !== '') {
    $where .= ' AND c.model = :model_name';
    $params[':model_name'] = $modelNameForQuery;
}
if ($qRaw !== '') {
    if (ctype_digit($qRaw)) {
        $where .= ' AND (c.id = :qid OR c.brand LIKE :q_like OR c.model LIKE :q_like OR u.name LIKE :q_like OR c.description LIKE :q_like)';
        $params[':qid'] = (int)$qRaw;
        $params[':q_like'] = "%{$qRaw}%";
    } else {
        $where .= ' AND (c.brand LIKE :q_like OR c.model LIKE :q_like OR u.name LIKE :q_like OR c.description LIKE :q_like)';
        $params[':q_like'] = "%{$qRaw}%";
    }
}

// total count
$countSql = "SELECT COUNT(*) FROM cars c LEFT JOIN users u ON u.id = c.user_id WHERE {$where}";
$stCount = $pdo->prepare($countSql);
foreach ($params as $k => $v) $stCount->bindValue($k, $v);
$stCount->execute();
$totalCars = (int)$stCount->fetchColumn();
$totalPages = max(1, (int)ceil($totalCars / $perPage));

// ORDER BY — защитим от инъекций, используем whitelist
$orderByMap = [
    'id' => 'c.id',
    'brand' => 'c.brand',
    'model' => 'c.model',
    'year' => 'c.year',
    'mileage' => 'c.mileage',
    'price' => 'c.price',
    'created_at' => 'c.created_at'
];
$orderSql = ($orderByMap[$sort] ?? 'c.id') . ' ' . $order;

// main query with paging
$sql = "SELECT c.id, c.brand, c.model, c.year, c.mileage, c.price, c.user_id, c.status, c.created_at, u.name AS owner_name
        FROM cars c
        LEFT JOIN users u ON u.id = c.user_id
        WHERE {$where}
        ORDER BY {$orderSql}
        LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$cars = $stmt->fetchAll(PDO::FETCH_ASSOC);

// JSON-структура моделей для JS (группировка по brand id)
$modelsJson = json_encode($modelsByBrand, JSON_UNESCAPED_UNICODE);

// helper для построения ссылок сортировки (toggle ASC/DESC)
function sortLink($col, $label) {
    $qs = $_GET;
    $currentSort = $_GET['sort'] ?? 'id';
    $currentOrder = strtoupper($_GET['order'] ?? 'DESC');
    if ($currentSort === $col) {
        // toggle
        $newOrder = $currentOrder === 'ASC' ? 'DESC' : 'ASC';
    } else {
        $newOrder = 'DESC';
    }
    $qs['sort'] = $col;
    $qs['order'] = $newOrder;
    // go to page 1
    $qs['page'] = 1;
    $link = $_SERVER['PHP_SELF'] . '?' . http_build_query($qs);
    $arrow = ($currentSort === $col) ? ($currentOrder === 'ASC' ? ' ↑' : ' ↓') : '';
    return '<a href="'.htmlspecialchars($link).'">'.htmlspecialchars($label . $arrow).'</a>';
}
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Админ — Автомаркет (модерация)</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="/mehanik/assets/css/style.css">
<style>
/* стили (не жёстко меняем основной дизайн) */
.page-wrap{max-width:1200px;margin:18px auto;padding:16px;font-family:Arial, sans-serif;}
.header-row{display:flex;flex-direction:column;gap:10px;margin-bottom:12px;}
.filters{display:flex;gap:8px;align-items:center;flex-wrap:wrap;}
.filters select, .filters input[type="text"]{padding:8px;border-radius:8px;border:1px solid #e6e9ef;}
.reset-btn { padding:8px 10px;border-radius:8px;background:#6b7280;color:#fff;text-decoration:none; display:inline-block; }
.table-wrap{overflow-x:auto;border-radius:8px;margin-top:12px;}
table{border-collapse:collapse;width:100%;min-width:1020px;background:#fff;box-shadow:0 2px 8px rgba(15,20,30,0.04);border-radius:6px;overflow:hidden;}
th,td{padding:10px 12px;border-bottom:1px solid #f0f2f5;text-align:left;font-size:14px;color:#24303a;vertical-align:middle;}
th{background:#fbfcfd;font-weight:600;color:#556171;font-size:13px;text-transform:uppercase;}
.actions a{margin:0 6px 6px 0;text-decoration:none;display:inline-block;padding:6px 9px;border-radius:6px;font-size:13px;color:#fff;}
.actions a.view{background:#6c757d;} .actions a.edit{background:#2b7ae4;} .actions a.approve{background:#2ecc71;} .actions a.reject{background:#f04336;} .actions a.delete{background:#6f42c1;}
.pagination{margin-top:14px;display:flex;gap:6px;flex-wrap:wrap;}
.pagination a{padding:8px 10px;border-radius:6px;border:1px solid #e6e9ee;text-decoration:none;color:#2b2f36;}
.pagination a.active{background:#2b7ae4;color:#fff;border-color:#2b7ae4;}
@media(max-width:900px){ .filters{flex-direction:column;align-items:stretch;} }
.small-muted { color:#6b7280; font-size:13px; margin-left:8px; }
/* Оставляем существующие стили... */
/* ... */

th,td{padding:10px 12px;border-bottom:1px solid #f0f2f5;text-align:left;font-size:14px;color:#24303a;vertical-align:middle;}
th{background:#fbfcfd;font-weight:600;color:#556171;font-size:13px;text-transform:uppercase;}

/* --- Новое: ссылки в заголовках таблицы не должны быть синими --- */
th a {
  color: inherit;            /* наследовать цвет th */
  text-decoration: none;     /* убрать подчеркивание */
  font-weight: 700;          /* чуть жирнее для восприятия */
}
th a:hover {
  color: #2b7ae4;            /* аккуратный hover-эффект (синий) */
  text-decoration: underline;
}

/* оставляем остальные правила */
.actions a{margin:0 6px 6px 0;text-decoration:none;display:inline-block;padding:6px 9px;border-radius:6px;font-size:13px;color:#fff;}
/* ... */

</style>
</head>
<body>
<?php require_once __DIR__ . '/header.php'; ?>

<div class="page-wrap">
  <div class="header-row">
    <h2 style="margin:0">Админ — Автомаркет (модерация)</h2>
    <div class="small-muted">Всего объявлений: <strong><?= (int)$totalCars ?></strong></div>

    <!-- Фильтры: автоматическое применение -->
    <form id="filtersForm" method="get" action="" class="filters" style="margin-top:6px;">
      <input type="hidden" name="user_id" value="<?= $user_id ? (int)$user_id : '' ?>">
      <!-- сохраняем сортировку/пагинацию в ссылках -->
      <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
      <input type="hidden" name="order" value="<?= htmlspecialchars($order) ?>">
      <input type="hidden" name="per_page" value="<?= (int)$perPage ?>">

      <label>
        <select name="status" id="f_status">
          <option value="">Все статусы</option>
          <option value="pending" <?= $statusFilterRaw === 'pending' ? 'selected' : '' ?>>На модерации</option>
          <option value="approved" <?= $statusFilterRaw === 'approved' ? 'selected' : '' ?>>Подтверждён</option>
          <option value="rejected" <?= $statusFilterRaw === 'rejected' ? 'selected' : '' ?>>Отклонён</option>
        </select>
      </label>

      <label>
        <select name="brand" id="f_brand">
          <option value="">Все бренды</option>
          <?php foreach ($brandsList as $b): ?>
            <?php $selected = (string)$brandFilterRaw === (string)$b['id'] || (string)$brandFilterRaw === (string)$b['name']; ?>
            <option value="<?= (int)$b['id'] ?>" <?= $selected ? 'selected' : '' ?>><?= htmlspecialchars($b['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <label>
        <select name="model" id="f_model">
          <option value="">Все модели</option>
          <?php
            // Show models for selected brand only; if no brand selected — keep only placeholder.
            $modelsToShow = [];
            if ($brandFilter !== null) {
                $modelsToShow = $modelsByBrand[$brandFilter] ?? [];
            } elseif ($brandFilterRaw !== '' && ctype_digit($brandFilterRaw)) {
                // brandFilterRaw numeric but brandFilter null only in weird cases; try to coerce
                $bf = (int)$brandFilterRaw;
                $modelsToShow = $modelsByBrand[$bf] ?? [];
            } else {
                // brand not selected — modelsToShow stays empty (we do NOT list all models)
                $modelsToShow = [];
            }

            foreach ($modelsToShow as $m):
              $isSelected = ((string)$modelFilterRaw === (string)($m['id'] ?? '') ) || ((string)$modelFilterRaw === (string)($m['name'] ?? ''));
          ?>
            <option value="<?= (int)$m['id'] ?>" <?= $isSelected ? 'selected' : '' ?>><?= htmlspecialchars($m['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <label style="flex:1;min-width:220px;">
        <input type="text" name="q" id="f_q" placeholder="поиск: id, бренд, модель, владелец..." value="<?= htmlspecialchars($qRaw) ?>" style="width:100%;padding:8px;border-radius:8px;border:1px solid #e6e9ef;">
      </label>

      <a href="/mehanik/public/admin/cars_moderation.php" class="reset-btn">Сброс</a>
    </form>
  </div>

  <div class="table-wrap" style="margin-top:8px;">
    <table>
      <thead>
        <tr>
          <th><?= sortLink('id','ID') ?></th>
          <th><?= sortLink('brand','Бренд') ?></th>
          <th><?= sortLink('model','Модель') ?></th>
          <th><?= sortLink('year','Год') ?></th>
          <th><?= sortLink('mileage','Пробег') ?></th>
          <th><?= sortLink('price','Цена') ?></th>
          <th>Владелец</th>
          <th><?= sortLink('created_at','Создано') ?></th>
          <th>Статус</th>
          <th>Действия</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$cars): ?>
          <tr><td colspan="10" style="text-align:center;padding:18px 12px;">Объявления не найдены.</td></tr>
        <?php else: ?>
          <?php foreach ($cars as $c):
            $statusRaw = $c['status'] ?? '';
            $status = strtolower(trim((string)$statusRaw));
            $badgeLabel = $status === 'approved' ? 'Подтверждён' : ($status === 'rejected' ? 'Отклонён' : ($statusRaw ?: 'На модерации'));
            $rowStyle = $status === 'approved' ? 'background:#f6fff6;' : ($status === 'rejected' ? 'background:#fff6f6;' : 'background:#fffdf7;');
            $priceOut = ($c['price'] !== null && is_numeric($c['price'])) ? number_format((float)$c['price'], 2, '.', ' ') . ' TMT' : htmlspecialchars($c['price']);
            $createdOut = !empty($c['created_at']) ? date('d.m.Y H:i', strtotime($c['created_at'])) : '';
          ?>
            <tr style="<?= $rowStyle ?>">
              <td><?= htmlspecialchars($c['id']) ?></td>
              <td><?= htmlspecialchars($c['brand'] ?? '') ?></td>
              <td><?= htmlspecialchars($c['model'] ?? '') ?></td>
              <td><?= htmlspecialchars($c['year'] ?? '') ?></td>
              <td><?= htmlspecialchars(number_format((float)($c['mileage'] ?? 0),0,'.',' ')) ?> км</td>
              <td><?= $priceOut ?></td>
              <td><?= htmlspecialchars($c['owner_name'] ?? '-') ?> (ID: <?= htmlspecialchars($c['user_id'] ?? '') ?>)</td>
              <td><?= $createdOut ?></td>
              <td><span style="display:inline-block;padding:6px 9px;border-radius:999px;background:#f3f5f8;color:#334155;"><?= htmlspecialchars($badgeLabel) ?></span></td>
              <td class="actions">
                <a class="view" href="<?= htmlspecialchars($basePublic . '/car.php?id=' . $c['id']) ?>" target="_blank">Просмотр</a>
                <a class="edit" href="<?= htmlspecialchars($basePublic . '/edit-car.php?id=' . $c['id']) ?>">Ред.</a>

                <?php if ($c['status'] !== 'approved'): ?>
                  <a class="approve small" href="<?= htmlspecialchars($basePublic . '/admin/cars_moderation.php?action=approve&id=' . $c['id'] . backQS($page,$user_id,$statusFilterRaw,$brandFilterRaw,$modelFilterRaw,$qRaw,$sort,$order,$perPage)) ?>">Подтвердить</a>
                <?php endif; ?>
                <?php if ($c['status'] !== 'rejected'): ?>
                  <a class="reject small" href="<?= htmlspecialchars($basePublic . '/admin/cars_moderation.php?action=reject&id=' . $c['id'] . backQS($page,$user_id,$statusFilterRaw,$brandFilterRaw,$modelFilterRaw,$qRaw,$sort,$order,$perPage)) ?>">Отклонить</a>
                <?php endif; ?>

                <a class="delete" href="<?= htmlspecialchars($basePublic . '/admin/cars_moderation.php?action=delete&id=' . $c['id'] . backQS($page,$user_id,$statusFilterRaw,$brandFilterRaw,$modelFilterRaw,$qRaw,$sort,$order,$perPage)) ?>" onclick="return confirm('Удалить объявление #<?= htmlspecialchars($c['id']) ?>?')">Удал.</a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- пагинация -->
  <?php if ($totalPages > 1): ?>
    <div class="pagination" aria-label="Пагинация">
      <?php for ($i = 1; $i <= $totalPages; $i++):
        $qs = $_GET;
        $qs['page'] = $i;
        $link = '/mehanik/public/admin/cars_moderation.php?' . http_build_query($qs);
        $cls = $i === $page ? 'active' : '';
      ?>
        <a class="<?= $cls ?>" href="<?= htmlspecialchars($link) ?>"><?= $i ?></a>
      <?php endfor; ?>
    </div>
  <?php endif; ?>
</div>

<script>
// modelsByBrand from server
const modelsByBrand = <?= $modelsJson ?: '{}' ?>;

// helper: fill models select based on brand id
function populateModels(brandId, selectedModelId) {
  const sel = document.getElementById('f_model');
  if (!sel) return;
  // clear options and add placeholder
  sel.innerHTML = '';
  const opt0 = document.createElement('option');
  opt0.value = '';
  opt0.textContent = 'Все модели';
  sel.appendChild(opt0);

  if (!brandId) {
    // brand not selected -> do not fill models (leave only placeholder)
    return;
  }

  const arr = modelsByBrand[String(brandId)] || [];
  arr.forEach(m => {
    const o = document.createElement('option');
    o.value = m.id;
    o.textContent = m.name;
    if (String(selectedModelId) === String(m.id)) o.selected = true;
    sel.appendChild(o);
  });
}

document.addEventListener('DOMContentLoaded', function() {
  const form = document.getElementById('filtersForm');
  const brandEl = document.getElementById('f_brand');
  const modelEl = document.getElementById('f_model');
  const qEl = document.getElementById('f_q');
  const statusEl = document.getElementById('f_status');

  // initial population
  const selectedBrand = brandEl ? brandEl.value : '';
  const selectedModel = "<?= htmlspecialchars($modelFilterRaw, ENT_QUOTES) ?>";

  // If brand selected -> populate models; else leave placeholder only.
  populateModels(selectedBrand || '', selectedModel || '');

  // auto-submit on change (but when brand changes, update models first)
  function submitFormDebounced(ms = 0) {
    if (!form) return;
    setTimeout(() => form.submit(), ms);
  }

  if (brandEl) {
    brandEl.addEventListener('change', function() {
      populateModels(this.value, '');
      submitFormDebounced(60);
    });
  }

  if (modelEl) {
    modelEl.addEventListener('change', function() {
      submitFormDebounced(0);
    });
  }

  if (statusEl) {
    statusEl.addEventListener('change', function() {
      submitFormDebounced(0);
    });
  }

  // search with debounce
  if (qEl) {
    let t;
    qEl.addEventListener('input', function() {
      clearTimeout(t);
      t = setTimeout(() => form.submit(), 400);
    });
  }
});
</script>
</body>
</html>
