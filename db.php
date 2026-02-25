<?php
// db.php — Conexión PDO (versión compatible, sin strict_types)
define('DB_HOST', 'localhost');
define('DB_NAME', 'reservasdb'); // <-- tu BD
define('DB_USER', 'root');              // <-- tu usuario
define('DB_PASS', '');                  // <-- tu contraseña
//define('DB_USER', 'root');
//define('DB_PASS', '');// luego cambiar
define('DB_CHARSET', 'utf8mb4');

function pdo() {
  static $pdo = null;
  if ($pdo === null) {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    $opt = array(
      PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES   => false,
    );
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $opt);
  }
  return $pdo;
}

