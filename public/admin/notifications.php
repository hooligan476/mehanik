<?php
// public/admin/notifications.php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/header.php';

if (!$user || !in_array(strtolower($user['role'] ?? ''), ['admin','superadmin']) && !((int)($user['is_superadmin'] ?? 0) === 1)) {
    http_response_code(403);
    echo "Доступ запрещён.";
    exit;
}

function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$items = [];
$lastError = '';
try {
    if (isset($mysqli) && $mysqli instanceof mysqli) {
        $sql = "SELECT n.*, u.name AS admin_name, u.phone AS admin_phone
                FROM notifications n
                LEFT JOIN users u ON u.id = n.admin_id
                ORDER BY n.created_at DESC
                LIMIT 200";
        $res = $mysqli->query($sql);
        if ($res) {
            while ($r = $res->fetch_assoc()) $items[] = $r;
        } else {
            $lastError = $mysqli->error;
        }
    } elseif (isset($pdo) && $pdo instanceof PDO) {
        $sql = "SELECT n.*, u.name AS admin_name, u.phone AS admin_phone
                FROM notifications n
                LEFT JOIN users u ON u.id = n.admin_id
                ORDER BY n.created_at DESC
                LIMIT 200";
        $st = $pdo->query($sql);
        $items = $st->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $lastError = "Нет подключения к БД";
    }
} catch (Throwable $e) {
    $lastError = $e->getMessage();
}
?>
<main style="max-width:1200px;margin:20px auto;padding:0 16px;">
  <h1>Уведомления</h1>
  <p class="muted">Лента действий администраторов и системных уведомлений (последние 200 записей).</p>

  <section style="margin-top:18px;">
    <div style="background:#fff;border-radius:10px;padding:12px;box-shadow:0 4px 12px rgba(2,6,23,0.06);">
      <table style="width:100%;border-collapse:collapse;font-size:.95rem">
        <thead>
          <tr style="text-align:left;border-bottom:1px solid #eee;">
            <th style="padding:8px;width:170px">Время</th>
            <th style="padding:8px;width:160px">Тип</th>
            <th style="padding:8px">Детали</th>
            <th style="padding:8px;width:180px">Админ</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($items)): ?>
            <tr><td colspan="4" style="padding:16px;text-align:center;color:#6b7280">Записей нет<?= $lastError ? ' — ' . esc($lastError) : '' ?></td></tr>
          <?php else: foreach ($items as $it): ?>
            <tr>
              <td style="padding:8px;vertical-align:top"><?= esc($it['created_at'] ?? '') ?></td>
              <td style="padding:8px;vertical-align:top"><?= esc($it['type'] ?? '') ?></td>
              <td style="padding:8px;vertical-align:top"><?= nl2br(esc($it['message'] ?? '')) ?>
                <?php if (!empty($it['meta'])): ?>
                  <div style="margin-top:6px;color:#6b7280;font-size:.85rem">Meta: <?= esc(is_string($it['meta']) ? $it['meta'] : json_encode($it['meta'], JSON_UNESCAPED_UNICODE)) ?></div>
                <?php endif; ?>
              </td>
              <td style="padding:8px;vertical-align:top">
                <?php if (!empty($it['admin_name'])): ?>
                  <?= esc($it['admin_name']) ?> <span style="color:#9ca3af;font-weight:400">#<?= (int)($it['admin_id'] ?? 0) ?></span>
                <?php else: ?>
                  <?= esc($it['admin_phone'] ?? ('#' . (int)($it['admin_id'] ?? 0))) ?>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <p style="margin-top:12px;color:#6b7280;">Записи об админах нельзя удалить или изменять — управление будет доступно только супер-админу.</p>
</main>
