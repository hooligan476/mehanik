<?php
session_start();
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Регистрация</title>
<style>
body { font-family: Arial, sans-serif; margin: 30px; }
form { max-width: 300px; }
input { display: block; margin: 8px 0; padding: 8px; width: 100%; }
button { padding: 8px 12px; }
.msg { padding: 10px; margin-bottom: 15px; border-radius: 5px; }
.error { background: #ffe0e0; border: 1px solid #d66; color: #b71c1c; }
</style>
</head>
<body>
<h2>Регистрация</h2>

<form id="registerForm">
    <input type="text" name="name" placeholder="Имя" required>
    <input type="email" name="email" placeholder="Email" required>
    <input type="password" name="password" placeholder="Пароль (≥6 символов)" required>
    <button type="submit">Зарегистрироваться</button>
</form>

<p>Уже есть аккаунт? <a href="login.php">Войти</a></p>

<script>
const registerForm = document.getElementById('registerForm');
registerForm.addEventListener('submit', async e => {
    e.preventDefault();
    const fd = new FormData(registerForm);
    try {
        const res = await fetch('../api/auth-register.php', { method:'POST', body: fd });
        const data = await res.json();
        if(data.ok) {
            // Успешная регистрация → редирект на verify.php
            location.href = 'verify.php';
        } else {
            alert(data.error || 'Ошибка регистрации');
        }
    } catch(err) {
        alert('Ошибка сервера или сети: ' + err);
    }
});
</script>
</body>
</html>
