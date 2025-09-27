<?php
// public/service.php
session_start();
require_once __DIR__ . '/../db.php';
$config = file_exists(__DIR__ . '/../config.php') ? require __DIR__ . '/../config.php' : ['base_url'=>'/mehanik'];

$id = (int)($_GET['id'] ?? 0);
$service = null;
$photos = [];
$prices = [];
$avgRating = 0.0;
$reviewsCount = 0;
$reviews = [];
$staff = []; // сотрудники

$user = $_SESSION['user'] ?? null;
$userId = (int)($user['id'] ?? 0);
$isAdmin = isset($user['role']) && $user['role'] === 'admin';

/* =======================
   HANDLERS: delete_review, upsert_review, submit_staff_rating, submit_service_rating
   (handlers are identical to the ones you already approved earlier)
   For brevity in this file we keep the handlers here exactly as in your working file.
   ======================= */

/* -- DELETE REVIEW -- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'delete_review')) {
    $rid = isset($_POST['review_id']) ? (int)$_POST['review_id'] : 0;
    if ($rid <= 0) { header('Location: service.php?id=' . $id . '#reviews'); exit; }

    if (empty($userId) || $userId <= 0) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Требуется авторизация']);
            exit;
        }
        header('Location: /mehanik/login.php');
        exit;
    }

    if ($st = $mysqli->prepare('SELECT user_id FROM service_reviews WHERE id=? AND service_id=? LIMIT 1')) {
        $st->bind_param('ii', $rid, $id);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        $ownerId = isset($row['user_id']) ? (int)$row['user_id'] : 0;

        if ($ownerId !== $userId) {
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'error' => 'Нет прав на удаление этого отзыва.']);
                exit;
            }
            header('Location: service.php?id=' . $id . '#reviews');
            exit;
        }

        if ($del = $mysqli->prepare('DELETE FROM service_reviews WHERE id = ? AND service_id = ?')) {
            $del->bind_param('ii', $rid, $id);
            $del->execute();
            $del->close();
        }

        if ($u = $mysqli->prepare('UPDATE service_reviews SET parent_id = NULL WHERE parent_id = ? AND service_id = ?')) {
            $u->bind_param('ii', $rid, $id);
            $u->execute();
            $u->close();
        }

        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true]);
            exit;
        }
    }

    header('Location: service.php?id=' . $id . '#reviews');
    exit;
}

// ======= upsert_review (обновлённый, безопасный) =======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'upsert_review')) {
    // входные данные
    $editingId = isset($_POST['editing_review_id']) ? (int)$_POST['editing_review_id'] : 0;
    $parentRaw  = $_POST['parent_id'] ?? '';
    $parentId = ($parentRaw === '' || strtolower($parentRaw) === 'null' || $parentRaw === '0') ? null : (int)$parentRaw;
    $comment   = trim((string)($_POST['comment'] ?? ''));
    $postedName = trim((string)($_POST['user_name'] ?? ''));
    $ratingRaw = $_POST['review_rating'] ?? null;

    // normalize UI rating (expected from client as 1..10) - keep as float or null
    $reviewRatingUi = null;
    if ($ratingRaw !== null && $ratingRaw !== '') {
        if (is_numeric($ratingRaw)) {
            $rv = (float)str_replace(',', '.', $ratingRaw);
            if ($rv >= 1 && $rv <= 10) $reviewRatingUi = $rv;
        }
    }

    // compute DB rating for service_reviews: convert UI(1..10) -> DB(0.5..5.0) and round to nearest 0.5
    $reviewRatingDb = null;
    if ($reviewRatingUi !== null) {
        $tmp = $reviewRatingUi / 2.0; // 1..10 -> 0.5..5.0 (raw)
        // round to nearest 0.5:
        $tmp = round($tmp * 2.0) / 2.0;
        // clamp to 0.1..5.0 just in case (DB constraint)
        $tmp = max(0.1, min(5.0, $tmp));
        $reviewRatingDb = $tmp;
    }

    // простая валидация
    if ($comment === '') {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Комментарий не может быть пустым.']);
            exit;
        }
        header('Location: service.php?id=' . $id . '#reviews');
        exit;
    }

    // выбираем имя для сохранения
    $uid = ($userId > 0) ? $userId : null;
    $userNameToSave = 'Гость';
    if ($uid && !empty($user['name'])) {
        $userNameToSave = $user['name'];
    } elseif ($postedName !== '') {
        $userNameToSave = $postedName;
    }

    // helper для динамического bind_param (как в вашем коде)
    function stmt_bind_params($stmt, $types, $params) {
        if ($params === []) return;
        $refs = [];
        $refs[] = &$types;
        foreach ($params as $k => $v) $refs[] = &$params[$k];
        call_user_func_array([$stmt, 'bind_param'], $refs);
    }

    // Проверка наличия колонки user_id
    $res = $mysqli->query("SHOW COLUMNS FROM `service_reviews` LIKE 'user_id'");
    $reviewsHasUserId = ($res && $res->num_rows > 0);

    // --- Редактирование существующего ---
    if ($editingId > 0) {
        // проверим права: только автор отзыва может редактировать
        $canEdit = false;
        if ($st = $mysqli->prepare("SELECT user_id FROM service_reviews WHERE id=? AND service_id=? LIMIT 1")) {
            $st->bind_param('ii', $editingId, $id);
            $st->execute();
            $row = $st->get_result()->fetch_assoc();
            $st->close();
            if ($row) {
                $revUid = isset($row['user_id']) ? (int)$row['user_id'] : 0;
                if ($revUid > 0 && $uid && $revUid === $uid) $canEdit = true;
            }
        }
        if (!$canEdit) {
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'error' => 'Нет прав на редактирование этого отзыва.']);
                exit;
            }
            header('Location: service.php?id=' . $id . '#reviews');
            exit;
        }

        // Собираем части UPDATE динамически
        $sets = [];
        $params = [];
        $types = '';

        $sets[] = "comment = ?";
        $params[] = $comment; $types .= 's';

        if ($reviewRatingDb !== null) {
            $sets[] = "rating = ?";
            $params[] = $reviewRatingDb; $types .= 'd';
        } else {
            $sets[] = "rating = NULL";
        }

        $sets[] = "user_name = ?";
        $params[] = $userNameToSave; $types .= 's';

        if ($parentId === null) {
            $sets[] = "parent_id = NULL";
        } else {
            $sets[] = "parent_id = ?";
            $params[] = $parentId; $types .= 'i';
        }

        if ($reviewsHasUserId) {
            if ($uid) {
                $sets[] = "user_id = ?";
                $params[] = $uid; $types .= 'i';
            } else {
                $sets[] = "user_id = NULL";
            }
        }

        $sets[] = "updated_at = NOW()";

        $sql = "UPDATE service_reviews SET " . implode(', ', $sets) . " WHERE id = ? AND service_id = ? LIMIT 1";
        $params[] = $editingId; $types .= 'i';
        $params[] = $id; $types .= 'i';

        try {
            if ($u = $mysqli->prepare($sql)) {
                if ($types !== '') stmt_bind_params($u, $types, $params);
                $u->execute();
                if ($u->errno) {
                    throw new Exception('DB error: ' . $u->error);
                }
                $u->close();
            } else {
                throw new Exception('Prepare failed: ' . $mysqli->error);
            }

            // Если пользователь авторизован и отправил рейтинг в UI (1..10), обновляем/вставляем в service_ratings
            if ($reviewRatingUi !== null && $uid) {
                $sqlUp = "INSERT INTO service_ratings (service_id, user_id, rating, created_at)
                          VALUES (?, ?, ?, NOW())
                          ON DUPLICATE KEY UPDATE rating = VALUES(rating), updated_at = NOW()";
                if ($stR = $mysqli->prepare($sqlUp)) {
                    $stR->bind_param('iid', $id, $uid, $reviewRatingUi);
                    $stR->execute();
                    $stR->close();
                }
            }

        } catch (Exception $ex) {
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok'=>false, 'error' => $ex->getMessage()]);
                exit;
            }
            error_log('upsert_review update error: ' . $ex->getMessage());
            header('Location: service.php?id=' . $id . '#reviews');
            exit;
        }

        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true, 'action' => 'updated']);
            exit;
        }
        header('Location: service.php?id=' . $id . '#reviews');
        exit;
    }

    // --- INSERT new review ---
    // Построим INSERT динамически: включаем только колонки с корректными значениями
    $cols = ['service_id','user_name','comment','created_at']; // created_at via NOW()
    $placeholders = ['?','?','?','NOW()'];
    $params = [$id, $userNameToSave, $comment];
    $types = 'iss';

    // rating in DB-scale (0.5..5.0) for service_reviews
    if ($reviewRatingDb !== null) {
        // insert rating before created_at
        array_splice($cols, 2, 0, 'rating');
        array_splice($placeholders, 2, 0, '?');
        array_splice($params, 2, 0, $reviewRatingDb);
        // rebuild types (int, string, double, string) -> we'll rebuild below
    }

    // user_id (если есть и определён)
    if ($reviewsHasUserId && $uid) {
        $cols[] = 'user_id';
        $placeholders[] = '?';
        $params[] = $uid;
    }

    // parent_id (если указан)
    if ($parentId !== null) {
        $cols[] = 'parent_id';
        $placeholders[] = '?';
        $params[] = $parentId;
    }

    // Now build types string based on $params order
    $types = '';
    foreach ($params as $p) {
        if (is_int($p)) $types .= 'i';
        elseif (is_float($p) || is_double($p)) $types .= 'd';
        else $types .= 's';
    }

    $sql = "INSERT INTO service_reviews (" . implode(',', $cols) . ") VALUES (" . implode(',', $placeholders) . ")";

    try {
        if ($ins = $mysqli->prepare($sql)) {
            if ($types !== '') {
                $refs = [];
                $refs[] = &$types;
                foreach ($params as $k => $v) $refs[] = &$params[$k];
                call_user_func_array([$ins, 'bind_param'], $refs);
            }
            $ins->execute();
            if ($ins->errno) {
                throw new Exception('DB error: ' . $ins->error);
            }
            $ins->close();

            // If user is logged in and provided UI rating, upsert into service_ratings (UI 1..10)
            if ($reviewRatingUi !== null && $uid) {
                $sqlUp = "INSERT INTO service_ratings (service_id, user_id, rating, created_at)
                          VALUES (?, ?, ?, NOW())
                          ON DUPLICATE KEY UPDATE rating = VALUES(rating), updated_at = NOW()";
                if ($stR = $mysqli->prepare($sqlUp)) {
                    $stR->bind_param('iid', $id, $uid, $reviewRatingUi);
                    $stR->execute();
                    $stR->close();
                }
            }

        } else {
            throw new Exception('Prepare failed: ' . $mysqli->error);
        }
    } catch (Exception $ex) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok'=>false, 'error' => $ex->getMessage()]);
            exit;
        }
        error_log('upsert_review insert error: ' . $ex->getMessage());
        header('Location: service.php?id=' . $id . '#reviews');
        exit;
    }

    // успешный ответ
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'action' => 'inserted']);
        exit;
    }
    header('Location: service.php?id=' . $id . '#reviews');
    exit;
}
// ======= end upsert_review =======


/* -- SUBMIT STAFF RATING -- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'submit_staff_rating')) {
    // auth required
    if (empty($userId) || $userId <= 0) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Требуется авторизация']);
            exit;
        }
        header('Location: /mehanik/login.php');
        exit;
    }

    $staffId = isset($_POST['staff_id']) ? (int)$_POST['staff_id'] : 0;
    $rating = isset($_POST['rating']) ? (float)$_POST['rating'] : 0.0;
    if ($staffId <= 0 || $rating < 1 || $rating > 10) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Неверные параметры (staff_id/rating)']);
            exit;
        }
        header('Location: service.php?id=' . $id . '#reviews');
        exit;
    }

    // upsert into service_staff_ratings
    $done = false;
    $sqlInsertDup = "INSERT INTO service_staff_ratings (staff_id, user_id, rating, created_at) VALUES (?,?,?,NOW())
                     ON DUPLICATE KEY UPDATE rating=VALUES(rating), updated_at=NOW()";
    if ($stmt = $mysqli->prepare($sqlInsertDup)) {
        $stmt->bind_param('iid', $staffId, $userId, $rating);
        if ($stmt->execute()) $done = true;
        $stmt->close();
    }
    if (!$done) {
        if ($check = $mysqli->prepare('SELECT id FROM service_staff_ratings WHERE staff_id=? AND user_id=? LIMIT 1')) {
            $check->bind_param('ii', $staffId, $userId);
            $check->execute();
            $res = $check->get_result()->fetch_assoc();
            $check->close();
            if ($res && isset($res['id'])) {
                $rid = (int)$res['id'];
                if ($u = $mysqli->prepare('UPDATE service_staff_ratings SET rating=?, updated_at=NOW() WHERE id=?')) {
                    $u->bind_param('di', $rating, $rid);
                    $u->execute();
                    $u->close();
                }
            } else {
                if ($ins = $mysqli->prepare('INSERT INTO service_staff_ratings (staff_id, user_id, rating, created_at) VALUES (?,?,?,NOW())')) {
                    $ins->bind_param('iid', $staffId, $userId, $rating);
                    $ins->execute();
                    $ins->close();
                }
            }
        }
    }

    $avg = 0.0; $cnt = 0;
    if ($q = $mysqli->prepare('SELECT AVG(rating) AS avg_rating, COUNT(*) AS cnt FROM service_staff_ratings WHERE staff_id=?')) {
        $q->bind_param('i', $staffId);
        $q->execute();
        $r = $q->get_result()->fetch_assoc();
        $q->close();
        if ($r) {
            $avg = $r['avg_rating'] !== null ? round((float)$r['avg_rating'], 1) : 0.0;
            $cnt = (int)$r['cnt'];
        }
    }

    if ($u = $mysqli->prepare('UPDATE service_staff SET rating=? WHERE id=?')) {
        $u->bind_param('di', $avg, $staffId);
        $u->execute();
        $u->close();
    }

    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'avg' => $avg, 'count' => $cnt]);
        exit;
    }
    header('Location: service.php?id=' . $id . '#reviews');
    exit;
}

/* -- SUBMIT SERVICE RATING -- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'submit_service_rating')) {
    if (empty($userId) || $userId <= 0) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Требуется авторизация']);
            exit;
        }
        header('Location: /mehanik/login.php');
        exit;
    }

    $rating = isset($_POST['rating']) ? (float)$_POST['rating'] : 0.0;
    if ($rating < 1 || $rating > 10) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Неверный рейтинг (ожидается 1–10)']);
            exit;
        }
        header('Location: service.php?id=' . $id . '#reviews');
        exit;
    }

    $sql = 'INSERT INTO service_ratings (service_id, user_id, rating, created_at) VALUES (?,?,?,NOW())
            ON DUPLICATE KEY UPDATE rating=VALUES(rating), updated_at=NOW()';
    if ($stmt = $mysqli->prepare($sql)) {
        $stmt->bind_param('iid', $id, $userId, $rating);
        $stmt->execute();
        $stmt->close();
    } else {
        if ($check = $mysqli->prepare('SELECT id FROM service_ratings WHERE service_id=? AND user_id=? LIMIT 1')) {
            $check->bind_param('ii', $id, $userId);
            $check->execute();
            $res = $check->get_result()->fetch_assoc();
            $check->close();
            if ($res && isset($res['id'])) {
                $rid = (int)$res['id'];
                if ($u = $mysqli->prepare('UPDATE service_ratings SET rating=?, updated_at=NOW() WHERE id=?')) {
                    $u->bind_param('di', $rating, $rid);
                    $u->execute();
                    $u->close();
                }
            } else {
                if ($ins = $mysqli->prepare('INSERT INTO service_ratings (service_id, user_id, rating, created_at) VALUES (?,?,?,NOW())')) {
                    $ins->bind_param('iid', $id, $userId, $rating);
                    $ins->execute();
                    $ins->close();
                }
            }
        }
    }

    $avg = 0.0; $cnt = 0;
    if ($q = $mysqli->prepare('SELECT AVG(rating) AS avg_rating, COUNT(*) AS cnt FROM service_ratings WHERE service_id=?')) {
        $q->bind_param('i', $id);
        $q->execute();
        $r = $q->get_result()->fetch_assoc();
        $q->close();
        if ($r) {
            $avg = $r['avg_rating'] !== null ? round((float)$r['avg_rating'], 1) : 0.0;
            $cnt = (int)$r['cnt'];
        }
    }

    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'avg' => $avg, 'count' => $cnt]);
        exit;
    }

    header('Location: service.php?id=' . $id . '#reviews');
    exit;
}

/* ---------------- Utilities & fetch data ---------------- */
$uploadsFsRoot  = realpath(__DIR__ . '/../uploads') ?: (__DIR__ . '/../uploads');
$uploadsUrlRoot = '/mehanik/uploads';

function find_upload_url(string $value, string $preferredSubdir = 'services', string $uploadsFsRoot = '', string $uploadsUrlRoot = ''): array {
    // same implementation as before (kept intact)
    $fname = trim($value);
    if ($fname === '') return ['', ''];
    if (is_file($fname)) {
        $pos = mb_stripos($fname, DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR);
        if ($pos !== false) {
            $sub = substr($fname, $pos + 1);
            $url = rtrim($uploadsUrlRoot, '/') . '/' . str_replace(DIRECTORY_SEPARATOR, '/', rawurlencode(basename($sub)));
            if (mb_stripos($sub, $preferredSubdir) !== false) {
                $afterUploads = substr($sub, mb_stripos($sub, 'uploads/') + strlen('uploads/'));
                $url = rtrim($uploadsUrlRoot, '/') . '/'. str_replace('%2F','/', rawurlencode($afterUploads));
            }
            return [$url, $fname];
        }
        return ['', $fname];
    }
    $pathOnly = $fname;
    if (preg_match('#^https?://#i', $fname)) {
        $p = parse_url($fname, PHP_URL_PATH);
        if ($p !== null) $pathOnly = $p;
    }
    $pathOnly = str_replace('\\', '/', $pathOnly);
    $pathOnly = ltrim($pathOnly, '/');
    $uploadsPos = stripos($pathOnly, 'uploads/');
    $candidatesFs = [];
    if ($uploadsPos !== false) {
        $fromUploads = substr($pathOnly, $uploadsPos + strlen('uploads/'));
        $candidatesFs[] = rtrim($uploadsFsRoot, '/') . '/' . $fromUploads;
        $candidatesFs[] = rtrim($uploadsFsRoot, '/') . '/' . trim($preferredSubdir, '/') . '/' . basename($fromUploads);
    }
    $candidatesFs[] = rtrim($uploadsFsRoot, '/') . '/' . trim($preferredSubdir, '/') . '/' . basename($pathOnly);
    $candidatesFs[] = rtrim($uploadsFsRoot, '/') . '/' . basename($pathOnly);
    $candidatesFs[] = $pathOnly;
    $checked = [];
    foreach ($candidatesFs as $c) {
        $cNorm = str_replace(['//','\\\\'], ['/','/'], $c);
        if (!in_array($cNorm, $checked, true)) $checked[] = $cNorm;
    }
    foreach ($checked as $fs) {
        if (is_file($fs)) {
            $normalizedFs = str_replace('\\', '/', $fs);
            $uploadsRootNorm = str_replace('\\', '/', rtrim($uploadsFsRoot, '/'));
            if (stripos($normalizedFs, $uploadsRootNorm) !== false) {
                $rel = ltrim(substr($normalizedFs, strlen($uploadsRootNorm)), '/');
                $url = rtrim($uploadsUrlRoot, '/') . '/' . str_replace('%2F','/', rawurlencode($rel));
            } else {
                if (stripos($normalizedFs, '/'.$preferredSubdir.'/') !== false) {
                    $url = rtrim($uploadsUrlRoot, '/') . '/' . $preferredSubdir . '/' . rawurlencode(basename($normalizedFs));
                } else {
                    $url = rtrim($uploadsUrlRoot, '/') . '/' . $preferredSubdir . '/' . rawurlencode(basename($normalizedFs));
                }
            }
            return [$url, $fs];
        }
    }
    if (stripos($pathOnly, 'uploads/') !== false) {
        $after = substr($pathOnly, stripos($pathOnly, 'uploads/') + strlen('uploads/'));
        $url = rtrim($uploadsUrlRoot, '/') . '/' . str_replace('%2F','/', rawurlencode($after));
        $fallbackFs = rtrim($uploadsFsRoot, '/') . '/' . $after;
        return [$url, $fallbackFs];
    }
    $fallbackUrl = rtrim($uploadsUrlRoot, '/') . '/' . trim($preferredSubdir, '/') . '/' . rawurlencode(basename($pathOnly));
    $fallbackFs = rtrim($uploadsFsRoot, '/') . '/' . trim($preferredSubdir, '/') . '/' . basename($pathOnly);
    return [$fallbackUrl, $fallbackFs];
}

function column_exists($mysqli, $table, $col) {
    $table_q = $mysqli->real_escape_string($table);
    $col_q = $mysqli->real_escape_string($col);
    $res = $mysqli->query("SHOW COLUMNS FROM `{$table_q}` LIKE '{$col_q}'");
    return ($res && $res->num_rows > 0);
}

if (!column_exists($mysqli, 'service_reviews', 'parent_id')) {
    @ $mysqli->query("ALTER TABLE service_reviews ADD COLUMN parent_id INT NULL DEFAULT NULL, ADD INDEX (parent_id)");
}

$haveRatingsTable = ($mysqli->query("SHOW TABLES LIKE 'service_ratings'")->num_rows > 0);
if (!$haveRatingsTable) {
    @ $mysqli->query("\n        CREATE TABLE IF NOT EXISTS service_ratings (\n          id INT AUTO_INCREMENT PRIMARY KEY,\n          service_id INT NOT NULL,\n          user_id INT NOT NULL,\n          rating DECIMAL(3,1) NOT NULL,\n          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n          updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,\n          UNIQUE KEY uniq_service_user (service_id, user_id)\n        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n    ");
    $haveRatingsTable = true;
}

$reviewsHasUpdatedAt = column_exists($mysqli, 'service_reviews', 'updated_at');
$reviewsHasUserId    = column_exists($mysqli, 'service_reviews', 'user_id');
$reviewsHasParentId  = column_exists($mysqli, 'service_reviews', 'parent_id');

$haveStaffTable = ($mysqli->query("SHOW TABLES LIKE 'service_staff'")->num_rows > 0);

/* ---------------- Fetch service and related data ---------------- */
if ($id > 0) {
    if ($st = $mysqli->prepare("SELECT id, user_id, name, description, logo, contact_name, phone, email, address, latitude, longitude
                                FROM services WHERE id=? AND (status='approved' OR status='active')")) {
        $st->bind_param("i", $id);
        $st->execute();
        $service = $st->get_result()->fetch_assoc();
        $st->close();
    }

    if ($service) {
        if ($st = $mysqli->prepare("SELECT id, photo FROM service_photos WHERE service_id=? ORDER BY id ASC")) {
            $st->bind_param("i", $id);
            $st->execute();
            $photos = $st->get_result()->fetch_all(MYSQLI_ASSOC);
            $st->close();
        }
        if ($st = $mysqli->prepare("SELECT id, name, price FROM service_prices WHERE service_id=? ORDER BY id ASC")) {
            $st->bind_param("i", $id);
            $st->execute();
            $prices = $st->get_result()->fetch_all(MYSQLI_ASSOC);
            $st->close();
        }
        if ($haveRatingsTable) {
            if ($st = $mysqli->prepare("SELECT AVG(rating) AS avg_rating FROM service_ratings WHERE service_id=?")) {
                $st->bind_param("i", $id);
                $st->execute();
                $r = $st->get_result()->fetch_assoc();
                if ($r) $avgRating = $r['avg_rating'] !== null ? round((float)$r['avg_rating'], 1) : 0.0;
                $st->close();
            }
        }

        if ($haveStaffTable) {
            if ($st = $mysqli->prepare("SELECT id, photo, name, position, rating FROM service_staff WHERE service_id=? ORDER BY id ASC")) {
                $st->bind_param("i", $id);
                $st->execute();
                $staff = $st->get_result()->fetch_all(MYSQLI_ASSOC);
                $st->close();
            }
        }

        $cols = "id, service_id, user_id, user_name, rating, comment, parent_id, created_at";
        if ($reviewsHasUpdatedAt) $cols .= ", updated_at";
        $sql = "SELECT $cols FROM service_reviews WHERE service_id = ? ORDER BY created_at ASC";
        if ($st = $mysqli->prepare($sql)) {
            $st->bind_param('i', $id);
            $st->execute();
            $flat = $st->get_result()->fetch_all(MYSQLI_ASSOC);
            $st->close();
        } else {
            $flat = [];
        }

        $byId = [];
        foreach ($flat as &$row) {
            $row['children'] = [];
            $byId[(int)$row['id']] = $row;
        }
        $tree = [];
        foreach ($byId as $rid => &$r) {
            $pid = isset($r['parent_id']) && $r['parent_id'] !== null ? (int)$r['parent_id'] : 0;
            if ($pid && isset($byId[$pid])) {
                $byId[$pid]['children'][] = &$r;
            } else {
                $tree[] = &$r;
            }
        }
        $reviews = $tree;
        $reviewsCount = count($flat);
    }
}

/* Helper to render reviews recursively (server-side) */
/**
 * Рендер дерева отзывов (рекурсивно).
 * Показывает рядом с именем автора пятизвёздочную шкалу, только если у отзыва есть рейтинг.
 * Рейтинг может храниться в двух формах:
 *  - UI 1..10 (например 7 или 7.0)  -> отображаем как numeric 7.0 и переводим в 5* через /2
 *  - DB 0.5..5.0 (например 3.5)    -> переводим в UI: 3.5*2 = 7.0 и в 5* через округление
 */
function render_reviews_tree(array $nodes, $level = 0, $currentUserId = 0) {
    $html = '';
    foreach ($nodes as $n) {
        $id = (int)($n['id'] ?? 0);
        $userName = htmlspecialchars($n['user_name'] ?? 'Гость', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $time = htmlspecialchars(date('d.m.Y H:i', strtotime($n['created_at'] ?? '')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $commentEsc = nl2br(htmlspecialchars($n['comment'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        $hasChildren = !empty($n['children']);
        $indent = max(0, $level * 18);

        // Rating handling: show stars only when rating present and valid
        $ratingRaw = null;
        if (array_key_exists('rating', $n) && $n['rating'] !== null && $n['rating'] !== '') {
            // accept numeric strings too
            if (is_numeric($n['rating'])) {
                $ratingRaw = (float)$n['rating'];
            } else {
                // try to clean (e.g. "null" or other) -> treat as no rating
                $ratingRaw = null;
            }
        }

        $starsHtml = '';
        $ratingNumericDisplay = ''; // numeric in UI scale 1..10 (e.g. "7.0")
        $ratingDataAttr = ''; // data-rating attribute (store raw as was in DB for JS)
        if ($ratingRaw !== null) {
            // Determine DB-like 0..5 scale from stored value:
            // if stored value looks like UI (>5) treat as 1..10, else treat as 0.5..5.0
            if ($ratingRaw > 5.0) {
                // stored as 1..10
                $uiVal = $ratingRaw;
                $dbScale = $uiVal / 2.0;
                $ratingNumericDisplay = number_format($uiVal, 1, '.', '');
                $ratingDataAttr = htmlspecialchars((string)$ratingRaw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            } else {
                // stored as 0.5..5.0
                $dbScale = $ratingRaw;
                // convert to UI 1..10 for display numeric
                $uiVal = $dbScale * 2.0;
                $ratingNumericDisplay = number_format($uiVal, 1, '.', '');
                $ratingDataAttr = htmlspecialchars((string)$ratingRaw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }

            // clamp dbScale between 0 and 5
            $dbScale = max(0.0, min(5.0, (float)$dbScale));
            // convert to whole star count (round to nearest integer as requested)
            $filledStars = (int) round($dbScale);
            if ($filledStars < 0) $filledStars = 0;
            if ($filledStars > 5) $filledStars = 5;

            // build stars html (5 stars total)
            $starsHtml .= '<span class="review-stars" aria-hidden="true">';
            for ($si = 1; $si <= 5; $si++) {
                if ($si <= $filledStars) {
                    $starsHtml .= '<span class="star filled">★</span>';
                } else {
                    $starsHtml .= '<span class="star">☆</span>';
                }
            }
            $starsHtml .= '</span>';
            // add numeric next to it (with data-rating attribute preserving original raw value)
            $starsHtml .= ' <span class="review-rating-value" data-rating="' . $ratingDataAttr . '" title="Оценка автора">' . htmlspecialchars($ratingNumericDisplay) . '</span>';
        }

        $html .= '<div class="review-card" id="review-' . $id . '" style="margin-left:' . $indent . 'px;margin-top:10px;">';
        $html .= '<div class="review-meta">';
        // left: name + stars
        $html .= '<div>';
        $html .= '<span class="review-name">' . $userName . '</span>';
        if ($starsHtml !== '') {
            $html .= ' <span class="review-stars-wrap">' . $starsHtml . '</span>';
        }
        $html .= ' <span class="review-time">' . $time . '</span>';
        $html .= '</div>';

        // actions (reply/edit/delete)
        $html .= '<div class="review-actions">';
        $html .= '<button class="btn-small" type="button" onclick="startReply(' . $id . ', ' . json_encode($userName, JSON_UNESCAPED_UNICODE) . ')">Ответить</button>';

        // only author can edit/delete
        $canManage = ($currentUserId > 0 && isset($n['user_id']) && (int)$n['user_id'] === (int)$currentUserId);
        if ($canManage) {
            $html .= '<button class="btn-small" type="button" onclick="startEdit(' . $id . ')">Изменить</button>';
            $html .= '<form method="post" style="display:inline-block;margin:0;" onsubmit="return confirm(\'Удалить отзыв?\')">';
            $html .= '<input type="hidden" name="action" value="delete_review">';
            $html .= '<input type="hidden" name="review_id" value="' . $id . '">';
            $html .= '<button type="submit" class="btn-small">Удалить</button>';
            $html .= '</form>';
        }

        $html .= '</div></div>'; // .review-meta

        $html .= '<div class="review-comment">' . $commentEsc . '</div>';

        if ($hasChildren) {
            $html .= '<div style="margin-left:18px; margin-top:8px;">' . render_reviews_tree($n['children'], $level + 1, $currentUserId) . '</div>';
        }

        $html .= '</div>'; // .review-card
    }
    return $html;
}


/* helper toPublicUrl for simple relative -> public mapping */
function toPublicUrl($rel){
    if (!$rel) return '';
    if (preg_match('#^https?://#i',$rel)) return $rel;
    if (strpos($rel, '/') === 0) return $rel;
    return '/mehanik/' . ltrim($rel, '/');
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title><?= $service ? htmlspecialchars($service['name']) . ' — Mehanik' : 'Сервис — Mehanik' ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">

  <!-- Подключаем внешний CSS -->
  <link rel="stylesheet" href="/mehanik/assets/css/service.css">
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>

<div class="container">
  <?php if (!$service): ?>
    <div class="card">Сервис не найден или ещё не одобрен.</div>
  <?php else:
      [$logoUrl,$logoFs] = !empty($service['logo'])
        ? find_upload_url($service['logo'], 'services', $uploadsFsRoot, $uploadsUrlRoot)
        : ['', ''];
  ?>
    <div class="svc-grid">
      <!-- LEFT -->
      <aside class="card">
        <?php if ($logoUrl): ?>
          <img src="<?= htmlspecialchars($logoUrl) ?>" alt="Логотип" class="logo">
        <?php else: ?>
          <div class="logo--placeholder">Нет логотипа</div>
        <?php endif; ?>

        <h1 class="title"><?= htmlspecialchars($service['name']) ?></h1>

        <div class="contact-list">
          <?php if (!empty($service['contact_name'])): ?><div><strong>Контакт:</strong> <?= htmlspecialchars($service['contact_name']) ?></div><?php endif; ?>
          <?php if (!empty($service['phone'])): ?><div><strong>Телефон:</strong> <a href="tel:<?= rawurlencode($service['phone']) ?>"><?= htmlspecialchars($service['phone']) ?></a></div><?php endif; ?>
          <?php if (!empty($service['email'])): ?><div><strong>Email:</strong> <a href="mailto:<?= htmlspecialchars($service['email']) ?>"><?= htmlspecialchars($service['email']) ?></a></div><?php endif; ?>
          <?php if (!empty($service['address'])): ?><div><strong>Адрес:</strong> <?= htmlspecialchars($service['address']) ?></div><?php endif; ?>
        </div>

        <a class="btn btn-wide" href="booking.php?service_id=<?= $id ?>">Записаться</a>

        <?php if (!empty($prices)): ?>
          <div class="prices card" style="margin-top:14px; padding:12px;">
            <div style="font-weight:800; margin-bottom:8px;">Цены на услуги</div>
            <?php foreach ($prices as $p): ?>
              <div class="price-row">
                <div><?= htmlspecialchars($p['name']) ?></div>
                <div style="font-weight:700; color:var(--accent);">
                  <?= is_numeric($p['price']) ? number_format($p['price'], 2, '.', ' ') : htmlspecialchars($p['price']) ?> тмт
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </aside>

      <!-- MIDDLE -->
      <main>
        <div class="card">
          <!-- Rating moved above description -->
          <div class="rating-row" style="justify-content:space-between;align-items:center;">
            <div style="display:flex;align-items:center;gap:12px;">
              <div id="avgStars" class="stars" style="--percent:<?= ($avgRating > 0) ? (($avgRating/10)*100) . '%' : '0%'; ?>" aria-hidden="true"></div>
              <div>
                <div id="avgNum"><?= number_format($avgRating, 1) ?></div>
                <div id="avgMeta"><?= $reviewsCount ?> отзывов</div>
              </div>
            </div>

            <div>
              <div id="service-star-input" aria-hidden="true"></div>
            </div>
          </div>

          <h2 class="section-title">Описание</h2>
          <p class="description"><?= nl2br(htmlspecialchars($service['description'])) ?></p>

          <div style="margin-top:14px;">
            <h3 class="section-title">Местоположение</h3>
            <div id="map" class="map-card"></div>
          </div>

          <?php if (!empty($photos)): ?>
            <div style="margin-top:14px;">
              <h3 class="section-title">Фотографии</h3>
              <div class="photos-grid">
                <?php foreach ($photos as $p):
                    $val = $p['photo'] ?? '';
                    [$url,$fs] = $val ? find_upload_url($val, 'services', $uploadsFsRoot, $uploadsUrlRoot) : ['',''];
                    if ($url): ?>
                      <div class="thumb" role="button" tabindex="0" onclick="openLightbox('<?= htmlspecialchars($url) ?>')">
                        <img src="<?= htmlspecialchars($url) ?>" alt="Фото">
                      </div>
                    <?php endif;
                endforeach; ?>
              </div>
            </div>

            <div id="lb" class="lb-overlay" onclick="closeLightbox(event)">
              <button class="lb-close" onclick="closeLightbox(event)">×</button>
              <img id="lbImg" class="lb-img" src="" alt="Фото">
            </div>
          <?php endif; ?>
        </div>

        <!-- Reviews & Rating -->
        <section class="card" id="reviews" style="margin-top:18px;">
          <?php
            // find user's review (if any)
            function find_user_review_in_tree($nodes, $uid) {
                foreach ($nodes as $n) {
                    if (!empty($n['user_id']) && (int)$n['user_id'] === (int)$uid) {
                        return [
                            'id' => (int)$n['id'],
                            'rating' => isset($n['rating']) ? (float)$n['rating'] : 0,
                            'comment' => $n['comment'] ?? ''
                        ];
                    }
                    if (!empty($n['children'])) {
                        $found = find_user_review_in_tree($n['children'], $uid);
                        if ($found) return $found;
                    }
                }
                return null;
            }

            $userReview = ($userId > 0) ? find_user_review_in_tree($reviews, $userId) : null;
          ?>

          <!-- Top area: 10-star picker -->
          <div class="review-card" style="margin-bottom:12px;">
            <label style="display:block;font-weight:700;margin-bottom:8px;">Оцените сервис (1–10)</label>
            <div style="display:flex; gap:12px; align-items:center;">
              <div id="ten-star-picker" role="radiogroup" aria-label="10-star rating">
                <?php for ($s = 1; $s <= 10; $s++): $idStar = 'pick-' . $s; ?>
                  <input type="radio" id="<?= $idStar ?>" name="first_rating" value="<?= $s ?>">
                  <label for="<?= $idStar ?>" data-value="<?= $s ?>" title="<?= $s ?>"><?= '★' ?></label>
                <?php endfor; ?>
              </div>

              <button id="ten-star-apply" class="btn btn-small" type="button">Отправить</button>
              <button id="ten-star-send-rating" class="btn btn-small" type="button" style="margin-left:6px;">Отправить рейтинг</button>
            </div>
          </div>

          <?php if (empty($reviews)): ?>
            <div class="review-card">
              <div>Пока нет отзывов — будьте первым!</div>
            </div>
          <?php else: ?>

            <?php
              echo render_reviews_tree($reviews, 0, $userId);
            ?>

          <?php endif; ?>

          <!-- Add / edit form -->
          <div class="review-card" style="margin-top:12px;">
            <div id="replyIndicator" class="reply-indicator" style="display:none;">
              <span id="replyToText"></span>
              <button class="btn-ghost" type="button" onclick="cancelReply()">Отменить ответ</button>
            </div>

            <h3 class="section-title" id="formTitle">Оставить отзыв</h3>
            <form id="reviewForm" method="post" action="service.php?id=<?= $id ?>#reviews">
              <?php if ($userId <= 0): ?>
                <div style="margin-bottom:8px;">
                  <label>Ваше имя</label>
                  <input id="user_name" name="user_name" class="input" type="text" placeholder="Как вас зовут?" required>
                </div>
              <?php endif; ?>

              <div>
                <label>Комментарий</label>
                <textarea id="comment" name="comment" class="input" rows="4" required placeholder="Поделитесь впечатлением..."></textarea>
              </div>

              <input type="hidden" id="editing_review_id" name="editing_review_id" value="">
              <input type="hidden" id="parent_id" name="parent_id" value="0">
              <!-- hidden holds UI scale 1..10 when user selected via apply -->
              <input type="hidden" id="review_rating_hidden" name="review_rating" value="">

              <div style="margin-top:8px; display:flex; gap:8px; justify-content:flex-end;">
                <input type="hidden" name="action" value="upsert_review">
                <button type="submit" class="btn">Сохранить отзыв</button>
                <button type="button" class="btn-ghost" onclick="resetReviewForm()">Очистить</button>
              </div>
            </form>
          </div>

        </section>
      </main>

      <!-- RIGHT: сотрудники -->
      <aside class="card">
        <h3 class="section-title">Сотрудники</h3>
        <?php if (empty($staff)): ?>
          <div style="color:var(--muted);">Информация пока не добавлена.</div>
        <?php else: ?>
          <div style="display:flex; flex-direction:column; gap:10px;">
            <?php foreach ($staff as $s):
              $photoVal = $s['photo'] ?? '';
              [$stUrl, $stFs] = $photoVal ? find_upload_url($photoVal, 'staff', $uploadsFsRoot, $uploadsUrlRoot) : ['',''];
              $stName = htmlspecialchars($s['name'] ?? 'Без имени');
              $stPos  = htmlspecialchars($s['position'] ?? '');
              $stRating = isset($s['rating']) ? (float)$s['rating'] : 0.0;
              $stPercent = max(0, min(100, ($stRating/5)*100));
              $staffIdLocal = (int)($s['id'] ?? 0);
            ?>
              <div class="staff-card">
                <?php if ($stUrl): ?>
                  <img class="staff-photo" src="<?= htmlspecialchars($stUrl) ?>" alt="<?= $stName ?>">
                <?php else: ?>
                  <div class="staff-photo" style="display:flex;align-items:center;justify-content:center;color:#9aa3af;">—</div>
                <?php endif; ?>
                <div style="flex:1">
                  <div style="display:flex;align-items:center;justify-content:space-between;">
                    <div>
                      <div class="staff-name"><?= $stName ?></div>
                      <?php if ($stPos): ?><div class="staff-pos"><?= $stPos ?></div><?php endif; ?>
                    </div>
                    <div style="text-align:right;">
                      <div class="staff-rating" style="display:flex;align-items:center;gap:8px;">
                        <span class="stars" style="--percent:<?= $stPercent ?>%;" title="Рейтинг сотрудника: <?= number_format($stRating,1) ?>"></span>
                        <span style="font-weight:700"><?= number_format($stRating,1) ?></span>
                      </div>
                    </div>
                  </div>

                  <div style="margin-top:8px; display:flex; gap:8px; align-items:center;">
                    <div id="staff-picker-<?= $staffIdLocal ?>" style="display:flex; gap:6px; align-items:center;">
                      <?php for ($si=1;$si<=5;$si++): ?>
                        <button type="button" class="btn-small" onclick="sendStaffRating(<?= $staffIdLocal ?>, <?= $si ?>)"><?= $si ?></button>
                      <?php endfor; ?>
                    </div>
                    <div style="margin-left:auto; color:var(--muted); font-size:0.9rem;">Оцените сотрудника</div>
                  </div>

                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </aside>
    </div>
  <?php endif; ?>
</div>

<footer class="site-footer">&copy; <?= date('Y') ?> Mehanik</footer>

<!-- Перед подключением общего JS пробросим в window нужные данные -->
<script>
  // user review (null если нет)
  window.__USER_REVIEW__ = <?= json_encode($userReview ?? null, JSON_UNESCAPED_UNICODE) ?>;

  // SERVICE_LOCATION для initMap (lat/lng may be empty)
  window.SERVICE_LOCATION = {
    lat: <?= ($service && !empty($service['latitude'])) ? (float)$service['latitude'] : 37.95 ?>,
    lng: <?= ($service && !empty($service['longitude'])) ? (float)$service['longitude'] : 58.38 ?>,
    zoom: <?= ($service && !empty($service['latitude']) && !empty($service['longitude'])) ? 15 : 13 ?>,
    name: <?= json_encode($service['name'] ?? '', JSON_UNESCAPED_UNICODE) ?>
  };
</script>

<!-- Подключаем внешний JS (в котором реализованы все интерактивы) -->
<script defer src="/mehanik/assets/js/service.js"></script>

<!-- Google Maps (вызовет initMap из service.js). Замените YOUR_GOOGLE_API_KEY -->
<script async defer src="https://maps.googleapis.com/maps/api/js?key=YOUR_GOOGLE_API_KEY&callback=initMap"></script>

</body>
</html>
