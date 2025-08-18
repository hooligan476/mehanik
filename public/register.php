<?php require_once __DIR__.'/../middleware.php'; $config = require __DIR__.'/../config.php'; ?>
<!doctype html><html lang="ru"><head><meta charset="utf-8"><title>Регистрация</title>
<link rel="stylesheet" href="/mehanik/assets/css/style.css"></head><body>
<div class="auth-card">
  <h2>Регистрация</h2>
  <form id="registerForm">
    <input type="text" name="name" placeholder="Имя" required>
    <input type="email" name="email" placeholder="Email" required>
    <input type="password" name="password" placeholder="Пароль" required>
    <button type="submit">Создать</button>
  </form>
  <p>Есть аккаунт? <a href="<?= $config['base_url'] ?>/login.php">Войти</a></p>
</div>
<script src="/mehanik/assets/js/auth.js"></script>
</body></html>