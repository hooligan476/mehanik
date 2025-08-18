<?php require_once __DIR__.'/../middleware.php';
session_destroy();
header('Location: /mehanik/public/login.php');