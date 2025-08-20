<?php
session_start();
$_SESSION = [];
session_destroy();

// Редирект на главную страницу
header('Location: /mehanik/public/index.php');
exit;
