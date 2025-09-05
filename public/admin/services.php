<?php
// htdocs/mehanik/public/admin/services.php
session_start();

// доступ только admin или superadmin
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'] ?? '', ['admin','superadmin'], true)) {
    header('Location: /mehanik/public/login.php');
    exit;
}

// DB настройки (совместимы с products.php)
$dbHost = '127.0.0.1';
$dbName = 'mehanik';
$dbUser = 'root';
$dbPass = '';

try {
    $pdo = new PDO("mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (PDOException $e) {
    die("DB error: " . $e->getMessage());
}

// базовый путь до public
$basePublic = '/mehanik/public';

// параметры
$msg = $_GET['msg'] ?? '';
$err = $_GET['err'] ?? '';

$user_id = isset($_GET['user_id']) && $_GET['user_id'] !== '' ? (int)$_GET['user_id'] : null;

// ПАГИНАЦИЯ
$perPage = 10;
$page    = max(1, intval($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

// helper: собрать query string для возврата (с учетом user_id и page)
function backQS($page, $user_id) {
    $q = [];
    if ($page > 1)   $q[] = 'page=' . (int)$page;
    if ($user_id)    $q[] = 'user_id=' . (int)$user_id;
    return $q ? ('?' . implode('&', $q)) : '';
}

// обработка действий approve / reject / delete (GET — как было)
if (isset($_GET['action'], $_GET['id'])) {
    $id = (int)$_GET['id'];
    $act = $_GET['action'];

    try {
        if ($act === 'approve') {
            $st = $pdo->prepare("UPDATE services SET status='approved' WHERE id=?");
            $st->execute([$id]);
            header("Location: {$basePublic}/admin/services.php" . backQS($page,$user_id));
            exit;
        } elseif ($act === 'reject') {
            $st = $pdo->prepare("UPDATE services SET status='rejected' WHERE id=?");
            $st->execute([$id]);
            header("Location: {$basePublic}/admin/services.php" . backQS($page,$user_id));
            exit;
        } elseif ($act === 'delete') {
            // удаление сервиса — как у товаров, простое удаление записи
            // (при желании можно расширить: удалять фото, цены, staff, отзывы и т.д.)
            $st = $pdo->prepare("DELETE FROM services WHERE id=?");
            $st->execute([$id]);
            header("Location: {$basePublic}/admin/services.php" . backQS($page,$user_id));
            exit;
        }
    } catch (PDOException $e) {
        header("Location: {$basePublic}/admin/services.php?err=db_error" . ($user_id ? "&user_id={$user_id}" : ''));
        exit;
    }
}

// всего сервисов (с учетом фильтра по user_id)
if ($user_id) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM services WHERE user_id = ?");
    $st->execute([$user_id]);
} else {
    $st = $pdo->query("SELECT COUNT(*) FROM services");
}
$totalServices = (int)$st->fetchColumn();
$totalPages = max(1, (int)ceil($totalServices / $perPage));

// список сервисов на текущей странице
if ($user_id) {
    $sql = "SELECT s.id, s.name, s.logo, s.address, s.phone, s.user_id, s.status, s.created_at,
                   u.name AS owner_name,
                   (SELECT ROUND(AVG(rating),1) FROM service_ratings WHERE service_id = s.id) AS avg_rating,
                   (SELECT COUNT(*) FROM service_reviews WHERE service_id = s.id) AS reviews_count
            FROM services s
            LEFT JOIN users u ON u.id = s.user_id
            WHERE s.user_id = :uid
            ORDER BY s.id DESC
            LIMIT :limit OFFSET :offset";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':uid', $user_id, PDO::PARAM_INT);
} else {
    $sql = "SELECT s.id, s.name, s.logo, s.address, s.phone, s.user_id, s.status, s.created_at,
                   u.name AS owner_name,
                   (SELECT ROUND(AVG(rating),1) FROM service_ratings WHERE service_id = s.id) AS avg_rating,
                   (SELECT COUNT(*) FROM service_reviews WHERE service_id = s.id) AS reviews_count
            FROM services s
            LEFT JOIN users u ON u.id = s.user_id
            ORDER BY s.id DESC
            LIMIT :limit OFFSET :offset";
    $stmt = $pdo->prepare($sql);
}
$stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
$stmt->execute();
$services = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Админ — Сервисы / Услуги</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="/mehanik/assets/css/style.css">
<style>
/* Общая обёртка */
.page-wrap {
  max-width:1200px;
  margin:18px auto;
  padding:16px;
  font-family: Arial, sans-serif;
}
/* Верхняя строка */
.header-row {
  display:flex;
  justify-content:space-between;
  align-items:center;
  gap:12px;
  margin-bottom:14px;
}
.header-row h2 { margin:0; font-size:20px; color:#1f2d3d; }

/* Сообщения */
.msg { padding:10px 12px; border-radius:8px; margin-bottom:12px; font-size:14px; }
.msg-success { background:#eaf7ef; color:#116530; border:1px solid #cfead1; }
.msg-error   { background:#fff0f0; color:#9b1a1a; border:1px solid #f2c6c6; }

/* Таблица */
.table-wrap { overflow-x:auto; background:transparent; border-radius:8px; }
table { border-collapse:collapse; width:100%; min-width:1020px; background:#fff; box-shadow:0 2px 8px rgba(15,20,30,0.04); border-radius:6px; overflow:hidden; }
th, td { padding:10px 12px; border-bottom:1px solid #f0f2f5; text-align:left; vertical-align:middle; font-size:14px; color:#24303a; }
th { background:#fbfcfd; font-weight:600; color:#556171; font-size:13px; text-transform:uppercase; letter-spacing:0.02em; }
tr:hover td { background:#fbfbfd; }

/* Logo thumb */
.logo-thumb { width:64px; height:48px; object-fit:cover; border-radius:6px; border:1px solid #eef3f8; background:#fff; }

/* Действия (ссылки выглядят как кнопки) */
.actions a { margin:0 6px 6px 0; text-decoration:none; display:inline-block; padding:6px 9px; border-radius:6px; font-size:13px; color:#fff; }
.actions a.view { background:#6c757d; }
.actions a.edit { background:#2b7ae4; }
.actions a.approve { background:#2ecc71; }
.actions a.reject { background:#f04336; }
.actions a.delete { background:#6f42c1; }
.actions a.small { padding:5px 8px; font-size:12px; }

/* Пагинация */
.pagination { margin-top:14px; display:flex; gap:6px; flex-wrap:wrap; }
.pagination a { padding:8px 10px; border-radius:6px; border:1px solid #e6e9ee; text-decoration:none; color:#2b2f36; }
.pagination a.active { background:#2b7ae4; color:#fff; border-color:#2b7ae4; }

/* Мелкие правки */
.table-note { font-size:13px; color:#697580; margin-top:8px; }
.status-badge { display:inline-block;padding:6px 9px;border-radius:999px;font-weight:700;font-size:12px; }
.status-approved { background:#e6f6ea;color:#1b7a31;border:1px solid #cfead1; }
.status-rejected { background:#fff0f0;color:#b22222;border:1px solid #f2c6c6; }
.status-pending { background:#fff7ea;color:#b26a00;border:1px solid #f0dcc1; }
@media (max-width:800px) {
  th, td { padding:8px; font-size:13px; }
  .header-row { flex-direction:column; align-items:flex-start; gap:8px; }
}
</style>
</head>
<body>
<?php require_once __DIR__ . '/header.php'; ?>

<div class="page-wrap">
  <div class="header-row">
    <h2>Админ — Сервисы / Услуги<?= $user_id ? ' пользователя #'.htmlspecialchars($user_id) : '' ?></h2>
    <div class="table-note">Всего сервисов: <strong><?= $totalServices ?></strong></div>
  </div>

  <?php if ($msg): ?>
    <div class="msg msg-success"><?= htmlspecialchars($msg) ?></div>
  <?php endif; ?>
  <?php if ($err): ?>
    <div class="msg msg-error"><?= htmlspecialchars($err) ?></div>
  <?php endif; ?>

  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>ID</th>
          <th>Логотип</th>
          <th>Название</th>
          <th>Адрес</th>
          <th>Телефон</th>
          <th>Рейтинг</th>
          <th>Отзывы</th>
          <th>Владелец</th>
          <th>Создано</th>
          <th>Статус</th>
          <th>Действия</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$services): ?>
        <tr><td colspan="11" style="text-align:center; padding:18px 12px;">Сервисы не найдены.</td></tr>
      <?php else: ?>
        <?php foreach ($services as $s): ?>
          <?php
            $statusRaw = $s['status'] ?? '';
            $status = strtolower(trim((string)$statusRaw));

            if ($status === 'approved') {
                $badgeClass = 'status-approved';
                $label = 'Подтверждён';
            } elseif ($status === 'rejected') {
                $badgeClass = 'status-rejected';
                $label = 'Отклонён';
            } else {
                $badgeClass = 'status-pending';
                $label = $statusRaw !== '' ? htmlspecialchars($statusRaw) : 'На модерации';
            }

            // logo url -> try to render relative path if available
            $logoHtml = '';
            if (!empty($s['logo'])) {
                $logoPath = $s['logo'];
                // if logo value already looks like a public path, leave; otherwise prefix with /mehanik/
                if (preg_match('#^https?://#i', $logoPath)) {
                    $logoUrl = $logoPath;
                } else {
                    if (strpos($logoPath, '/') === 0) $logoUrl = $logoPath;
                    else $logoUrl = '/mehanik/' . ltrim($logoPath, '/');
                }
                $logoHtml = '<img class="logo-thumb" src="' . htmlspecialchars($logoUrl) . '" alt="logo">';
            } else {
                $logoHtml = '<div style="width:64px;height:48px;display:flex;align-items:center;justify-content:center;color:#9aa4ae;background:#fbfcfd;border-radius:6px;border:1px solid #eef3f8;">—</div>';
            }

            $avg = $s['avg_rating'] !== null ? number_format((float)$s['avg_rating'],1) : '—';
            $reviewsCount = (int)($s['reviews_count'] ?? 0);
            $createdOut = $s['created_at'] ? htmlspecialchars(date('d.m.Y H:i', strtotime($s['created_at']))) : '';
          ?>
          <tr>
            <td><?= htmlspecialchars($s['id']) ?></td>
            <td><?= $logoHtml ?></td>
            <td style="max-width:260px;"><?= htmlspecialchars($s['name'] ?? '') ?></td>
            <td><?= htmlspecialchars($s['address'] ?? '') ?></td>
            <td><?= htmlspecialchars($s['phone'] ?? '') ?></td>
            <td><?= $avg ?></td>
            <td><?= $reviewsCount ?></td>
            <td><?= htmlspecialchars($s['owner_name'] ?? '') ?> (ID: <?= htmlspecialchars($s['user_id'] ?? '') ?>)</td>
            <td><?= $createdOut ?></td>
            <td><span class="status-badge <?= $badgeClass ?>"><?= $label ?></span></td>
            <td class="actions">
              <a class="view" href="<?= htmlspecialchars($basePublic . '/service.php?id=' . $s['id']) ?>" target="_blank">Просмотр</a>
              <a class="edit" href="<?= htmlspecialchars($basePublic . '/admin/edit_service.php?id=' . $s['id']) ?>">Ред.</a>

              <?php if ($s['status'] !== 'approved'): ?>
                <a class="approve small" href="<?= htmlspecialchars($basePublic . '/admin/services.php?action=approve&id=' . $s['id'] . backQS($page,$user_id)) ?>">Подтвердить</a>
              <?php endif; ?>
              <?php if ($s['status'] !== 'rejected'): ?>
                <a class="reject small" href="<?= htmlspecialchars($basePublic . '/admin/services.php?action=reject&id=' . $s['id'] . backQS($page,$user_id)) ?>">Отклонить</a>
              <?php endif; ?>

              <a class="delete" href="<?= htmlspecialchars($basePublic . '/admin/services.php?action=delete&id=' . $s['id'] . backQS($page,$user_id)) ?>"
                 onclick="return confirm('Удалить сервис #<?= htmlspecialchars($s['id']) ?>?')">Удал.</a>
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
      <?php for ($i = 1; $i <= $totalPages; $i++): ?>
        <?php
          $link = $basePublic . '/admin/services.php?page=' . $i . ($user_id ? '&user_id=' . (int)$user_id : '');
          $cls  = $i === $page ? 'active' : '';
        ?>
        <a class="<?= $cls ?>" href="<?= htmlspecialchars($link) ?>"><?= $i ?></a>
      <?php endfor; ?>
    </div>
  <?php endif; ?>

</div>
</body>
</html>
