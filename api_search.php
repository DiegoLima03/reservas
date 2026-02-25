
<?php
// api_search.php — endpoint de autocompletado (sin strict_types, con debug y log)
require_once __DIR__ . '/db.php';

$debug = isset($_GET['debug']) ? true : false;
if ($debug) {
  @ini_set('display_errors','1');
  @ini_set('display_startup_errors','1');
  @error_reporting(E_ALL);
}
header('Content-Type: application/json; charset=UTF-8');

$type = isset($_GET['type']) ? $_GET['type'] : '';
$q    = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

if (!in_array($type, array('comercial','cliente','producto'), true)) {
  http_response_code(400);
  echo json_encode(array('error' => 'Tipo inválido'));
  exit;
}
if ($q === '' || mb_strlen($q) < 1) {
  echo json_encode(array());
  exit;
}

try {
  $pdo = pdo();
  if ($type === 'comercial') {
    $stmt = $pdo->prepare("SELECT id, nombre FROM comerciales WHERE nombre LIKE :q ORDER BY nombre ASC");
  } elseif ($type === 'cliente') {
    $stmt = $pdo->prepare("SELECT id, nombre FROM clientes WHERE nombre LIKE :q ORDER BY nombre ASC");
} else { // producto
  $stmt = $pdo->prepare("
    SELECT id, nombre
    FROM productos
    WHERE nombre LIKE :q
    ORDER BY nombre ASC
  ");
}


  $stmt->execute([':q' => '%'.$q.'%']);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
  echo json_encode($rows, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  if ($debug) {
    http_response_code(500);
    echo json_encode(array('error' => $e->getMessage()));
  } else {
    // log discreto en servidor
    @file_put_contents(__DIR__ . '/api_error.log',
      date('c') . " [api_search] " . $e->getMessage() . "\n", FILE_APPEND);
    http_response_code(500);
    echo json_encode(array('error' => 'Error del servidor'));
  }
}
