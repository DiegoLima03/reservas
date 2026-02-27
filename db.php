<?php
// db.php — Conexión PDO (versión compatible, sin strict_types)
define('DB_HOST', '127.0.0.1:3308');
define('DB_NAME', 'compras_chipiona');  // <-- aquí el nombre de tu BD
define('DB_USER', 'root');              // en Wamp suele ser root
define('DB_PASS', '');                  // y contraseña vacía
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

