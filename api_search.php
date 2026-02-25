
<?php
// api_search.php — endpoint de autocompletado para proveedor / delegacion / producto
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

// Tipos de búsqueda: proveedor, delegacion, producto
if (!in_array($type, array('proveedor','delegacion','producto'), true)) {
  http_response_code(400);
  echo json_encode(array('error' => 'Tipo inválido'), JSON_UNESCAPED_UNICODE);
  exit;
}
if ($q === '' || mb_strlen($q) < 1) {
  echo json_encode(array());
  exit;
}

try {
  $pdo = pdo();

  if ($type === 'proveedor') {
    $stmt = $pdo->prepare("
      SELECT id, nombre
      FROM proveedores
      WHERE nombre LIKE :q
      ORDER BY nombre ASC
    ");
  } elseif ($type === 'delegacion') {
    $stmt = $pdo->prepare("
      SELECT id, nombre
      FROM delegaciones
      WHERE nombre LIKE :q
      ORDER BY nombre ASC
    ");
  } else { // producto
    // Autocompletar productos por nombre, devolviendo solo un registro por nombre
    $stmt = $pdo->prepare("
      SELECT MIN(id) AS id, nombre
      FROM productos
      WHERE nombre LIKE :q
      GROUP BY nombre
      ORDER BY nombre ASC
    ");
  }

  $stmt->execute([':q' => '%'.$q.'%']);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode($rows, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  if ($debug) {
    http_response_code(500);
    echo json_encode(array('error' => $e->getMessage()), JSON_UNESCAPED_UNICODE);
  } else {
    // log discreto en servidor
    @file_put_contents(__DIR__ . '/api_error.log',
      date('c') . " [api_search] " . $e->getMessage() . "\n", FILE_APPEND);
    http_response_code(500);
    echo json_encode(array('error' => 'Error del servidor'), JSON_UNESCAPED_UNICODE);
  }
}
