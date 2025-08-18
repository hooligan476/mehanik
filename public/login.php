<?php require_once __DIR__.'/../middleware.php'; $config = require __DIR__.'/../config.php'; ?>
<!doctype html><html lang="ru"><head><meta charset="utf-8"><title>Вход</title>
<link rel="stylesheet" href="/mehanik/assets/css/style.css"></head><body>
<div class="auth-card">
  <h2>Вход</h2>
  <form id="loginForm">
    <input type="email" name="email" placeholder="Email" required>
    <input type="password" name="password" placeholder="Пароль" required>
    <button type="submit">Войти</button>
  </form>
  <p>Нет аккаунта? <a href="<?= $config['base_url'] ?>/register.php">Регистрация</a></p>
</div>
<script src="/mehanik/assets/js/auth.js"></script>
</body></html>