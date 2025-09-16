<?php
// api/get-bodies.php
require_once __DIR__ . '/../middleware.php';
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json; charset=utf-8');

$vehicle_type = isset($_GET['vehicle_type']) ? trim($_GET['vehicle_type']) : null;
$vehicle_type_id = isset($_GET['vehicle_type_id']) ? (int)$_GET['vehicle_type_id'] : null;

$out = [];

try {
    if ($vehicle_type_id) {
        if (isset($mysqli) && $mysqli instanceof mysqli) {
            $st = $mysqli->prepare("SELECT id, name, `key` FROM vehicle_bodies WHERE vehicle_type_id = ? ORDER BY `order` ASC, name ASC");
            $st->bind_param('i', $vehicle_type_id);
            $st->execute();
            $res = $st->get_result();
            while ($r = $res->fetch_assoc()) $out[] = $r;
        } elseif (isset($pdo) && $pdo instanceof PDO) {
            $st = $pdo->prepare("SELECT id, name, `key` FROM vehicle_bodies WHERE vehicle_type_id = :tid ORDER BY `order` ASC, name ASC");
            $st->execute([':tid'=>$vehicle_type_id]);
            $out = $st->fetchAll(PDO::FETCH_ASSOC);
        }
    } elseif ($vehicle_type) {
        // try to resolve by key or name in vehicle_types
        if (isset($mysqli) && $mysqli instanceof mysqli) {
            $st = $mysqli->prepare("SELECT id FROM vehicle_types WHERE `key` = ? OR name = ? LIMIT 1");
            $st->bind_param('ss', $vehicle_type, $vehicle_type);
            $st->execute();
            $res = $st->get_result();
            $r = $res->fetch_assoc();
            if ($r) {
                $tid = (int)$r['id'];
                $st2 = $mysqli->prepare("SELECT id, name, `key` FROM vehicle_bodies WHERE vehicle_type_id = ? ORDER BY `order` ASC, name ASC");
                $st2->bind_param('i', $tid);
                $st2->execute();
                $res2 = $st2->get_result();
                while ($b = $res2->fetch_assoc()) $out[] = $b;
            }
        } elseif (isset($pdo) && $pdo instanceof PDO) {
            $st = $pdo->prepare("SELECT id FROM vehicle_types WHERE `key` = :k OR name = :k LIMIT 1");
            $st->execute([':k'=>$vehicle_type]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if ($r) {
                $tid = (int)$r['id'];
                $st2 = $pdo->prepare("SELECT id, name, `key` FROM vehicle_bodies WHERE vehicle_type_id = :tid ORDER BY `order` ASC, name ASC");
                $st2->execute([':tid'=>$tid]);
                $out = $st2->fetchAll(PDO::FETCH_ASSOC);
            }
        }
    } else {
        // return all bodies grouped by vehicle_type_id (optional)
        if (isset($mysqli) && $mysqli instanceof mysqli) {
            $res = $mysqli->query("SELECT id, vehicle_type_id, name, `key` FROM vehicle_bodies ORDER BY vehicle_type_id ASC, `order` ASC, name ASC");
            while ($r = $res->fetch_assoc()) $out[] = $r;
        } elseif (isset($pdo) && $pdo instanceof PDO) {
            $st = $pdo->query("SELECT id, vehicle_type_id, name, `key` FROM vehicle_bodies ORDER BY vehicle_type_id ASC, `order` ASC, name ASC");
            $out = $st->fetchAll(PDO::FETCH_ASSOC);
        }
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB error']);
    exit;
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);
