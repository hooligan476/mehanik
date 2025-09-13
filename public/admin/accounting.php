<?php
// public/admin/accounting.php
// Скелет-страницы Бухгалтерии — заглушка

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/header.php';

// В дальнейшем сюда будут загружаться транзакции, фильтры, экспорт и т.д.
?>
<main style="max-width:1200px;margin:20px auto;padding:0 16px;">
  <h1>Бухгалтерия</h1>
  <p class="muted">История платежей, списания и счета — заглушка.</p>

  <section style="margin-top:18px;">
    <div style="background:#fff;border-radius:10px;padding:12px;box-shadow:0 4px 12px rgba(2,6,23,0.06);">
      <table style="width:100%;border-collapse:collapse;">
        <thead>
          <tr style="text-align:left;border-bottom:1px solid #eee;">
            <th style="padding:8px;">Дата</th>
            <th style="padding:8px;">Пользователь</th>
            <th style="padding:8px;">Тип</th>
            <th style="padding:8px;">Сумма</th>
            <th style="padding:8px;">Статус</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td style="padding:8px;">2025-08-28</td>
            <td style="padding:8px;">#42 — Petr Ivanov</td>
            <td style="padding:8px;">Пополнение</td>
            <td style="padding:8px;">100.00 TMT</td>
            <td style="padding:8px;">Успешно</td>
          </tr>
          <tr>
            <td style="padding:8px;">2025-09-03</td>
            <td style="padding:8px;">#77 — Company A</td>
            <td style="padding:8px;">Ежемесячная оплата</td>
            <td style="padding:8px;">250.00 TMT</td>
            <td style="padding:8px;">Ожидание</td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>

  <p style="margin-top:12px;color:#6b7280;">Примечание: в этой заглушке можно реализовать экспорт и просмотр квитанций позже.</p>
</main>
