<?php
$config = require __DIR__ . '/config.php';
$mysqli = new mysqli(
  $config['db']['host'],
  $config['db']['user'],
  $config['db']['pass'],
  $config['db']['name']
);
if ($mysqli->connect_errno) {
  die('DB connection failed: ' . $mysqli->connect_error);
}
$mysqli->set_charset($config['db']['charset']);