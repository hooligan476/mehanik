<?php
session_start();
function require_auth() {
  if (empty($_SESSION['user'])) {
    header('Location: /mehanik/public/login.php');
    exit;
  }
}
function require_admin() {
  if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    http_response_code(403);
    echo 'Forbidden';
    exit;
  }
}
