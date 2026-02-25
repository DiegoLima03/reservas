
<?php
// check.php — prueba de conectividad y recuentos
@ini_set('display_errors','1'); @ini_set('display_startup_errors','1'); @error_reporting(E_ALL);
require_once __DIR__ . '/db.php';
header('Content-Type: text/plain; charset=UTF-8');

try{
  $pdo = pdo();
  echo "Conexión OK\n";
  foreach (['comerciales','clientes','productos','reservas'] as $t){
    $n = $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
    echo "$t: $n filas\n";
  }
} catch(Throwable $e){
  http_response_code(500);
  echo "ERROR: " . $e->getMessage();
}
