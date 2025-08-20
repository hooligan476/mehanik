<?php session_start(); ?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Вход</title>
<style>
body { font-family: Arial, sans-serif; margin:30px; }
form { max-width:300px; }
input { display:block; margin:8px 0; padding:8px; width:100%; }
button { padding:8px 12px; }
.msg { padding:10px; margin-bottom:15px; border-radius:5px; }
.error { background:#ffe0e0; border:1px solid #d66; color:#b71c1c; }
</style>
</head>
<body>
<h2>Вход</h2>

<form id="loginForm">
    <input type="email" name="email" placeholder="Email" required>
    <input type="password" name="password" placeholder="Пароль" required>
    <button type="submit">Войти</button>
</form>

<p>Нет аккаунта? <a href="register.php">Зарегистрироваться</a></p>

<script>
const loginForm = document.getElementById('loginForm');
loginForm.addEventListener('submit', async e => {
    e.preventDefault();
    const fd = new FormData(loginForm);
    try {
        const res = await fetch('../api/auth-login.php', { method:'POST', body: fd });
        const data = await res.json();
        if(data.ok){
            location.href = 'index.php';
        } else {
            alert(data.error || 'Ошибка авторизации');
        }
    } catch(err){
        alert('Ошибка сервера или сети: ' + err);
    }
});
</script>
</body>
</html>
