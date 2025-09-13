<?php
// public/admin/notifications.php
// Скелет-страницы Уведомлений — заглушка

if (session_status() === PHP_SESSION_NONE) session_start();

// Подключаем хедер (он поднимает сессию/пользователя, считает pending и т.д.)
require_once __DIR__ . '/header.php';

// В будущем: подключить пагинацию/фильтры/поиск/REST API
?>
<main style="max-width:1200px;margin:20px auto;padding:0 16px;">
  <h1>Уведомления</h1>
  <p class="muted">Здесь будет лента админских действий и системных уведомлений. Пока — заглушка.</p>

  <section style="margin-top:18px;">
    <div style="background:#fff;border-radius:10px;padding:12px;box-shadow:0 4px 12px rgba(2,6,23,0.06);">
      <table style="width:100%;border-collapse:collapse;">
        <thead>
          <tr style="text-align:left;border-bottom:1px solid #eee;">
            <th style="padding:8px;">Время</th>
            <th style="padding:8px;">Тип</th>
            <th style="padding:8px;">Детали</th>
            <th style="padding:8px;">Админ</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td style="padding:8px;">2025-09-01 10:12</td>
            <td style="padding:8px;">Подтверждение пользователя</td>
            <td style="padding:8px;">Админ #3 (Ivan) подтвердил пользователя #42 (Petr Ivanov)</td>
            <td style="padding:8px;">Ivan (id:3)</td>
          </tr>
          <tr>
            <td style="padding:8px;">2025-09-02 14:20</td>
            <td style="padding:8px;">Изменение статуса товара</td>
            <td style="padding:8px;">Товар #101 помечен как approved</td>
            <td style="padding:8px;">Maggie (id:2)</td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>

  <p style="margin-top:12px;color:#6b7280;">Примечание: записи об админах нельзя удалить или изменять — управлять сможет только супер-админ (реализация будет добавлена позже).</p>
</main>
