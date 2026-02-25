<?php
// save_producto.php — alta sencilla de productos
require_once __DIR__ . '/db.php';

@ini_set('display_errors','1');
@ini_set('display_startup_errors','1');
@error_reporting(E_ALL);

header('Content-Type: application/json; charset=UTF-8');

// Recibir datos POST (form o JSON)
$input = $_POST;
if (empty($input)) {
  $raw = file_get_contents('php://input');
  if ($raw) {
    $parsed = json_decode($raw, true);
    if (is_array($parsed)) {
      $input = $parsed;
    }
  }
}

$tipo_id       = isset($input['tipo_id']) ? (int)$input['tipo_id'] : 0;
$nombre        = trim((string)($input['nombre']    ?? ''));
$codigo_velneo = trim((string)($input['codigo_velneo'] ?? ''));
$proveedor_id  = isset($input['proveedor_id']) ? (int)$input['proveedor_id'] : 0;

$errors = [];
if ($tipo_id <= 0) {
  $errors[] = 'tipo_id requerido';
}
if ($nombre === '') {
  $errors[] = 'nombre requerido';
}
if ($proveedor_id <= 0) {
  $errors[] = 'proveedor_id requerido';
}
if ($codigo_velneo !== '' && mb_strlen($codigo_velneo, 'UTF-8') > 64) {
  $errors[] = 'codigo_velneo supera 64 caracteres';
}

if ($errors) {
  echo json_encode(['ok' => false, 'errors' => $errors], JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  $pdo = pdo();

  // Obtener nombre del tipo para guardar también en productos.tipo
  $st = $pdo->prepare("SELECT nombre FROM tipos_producto WHERE id = ?");
  $st->execute([$tipo_id]);
  $tipoNombre = $st->fetchColumn();
  if (!$tipoNombre) {
    throw new RuntimeException("Tipo de producto no encontrado (id=$tipo_id)");
  }

   // Obtener nombre del proveedor para guardar en productos.proveedor
   $st = $pdo->prepare("SELECT nombre FROM proveedores WHERE id = ?");
   $st->execute([$proveedor_id]);
   $proveedorNombre = $st->fetchColumn();
   if (!$proveedorNombre) {
     throw new RuntimeException("Proveedor no encontrado (id=$proveedor_id)");
   }

  $hasCodigoVelneo = (bool)$pdo->query("SHOW COLUMNS FROM productos LIKE 'codigo_velneo'")->fetch(PDO::FETCH_ASSOC);
  if ($hasCodigoVelneo) {
    $sql = "
      INSERT INTO productos (tipo_id, tipo, nombre, codigo_velneo, proveedor, cantidad, pedido)
      VALUES (:tipo_id, :tipo, :nombre, :codigo_velneo, :proveedor, :cantidad, :pedido)
    ";
  } else {
    $sql = "
      INSERT INTO productos (tipo_id, tipo, nombre, proveedor, cantidad, pedido)
      VALUES (:tipo_id, :tipo, :nombre, :proveedor, :cantidad, :pedido)
    ";
  }

  $stmt = $pdo->prepare($sql);
  $params = [
    ':tipo_id'   => $tipo_id,
    ':tipo'      => $tipoNombre,
    ':nombre'    => $nombre,
    ':proveedor' => $proveedorNombre,
    ':cantidad'  => 0,          // stock inicial gestionado por compras_stock
    ':pedido'    => 0,
  ];
  if ($hasCodigoVelneo) {
    $params[':codigo_velneo'] = ($codigo_velneo !== '') ? $codigo_velneo : null;
  }
  $ok = $stmt->execute($params);

  if (!$ok) {
    throw new RuntimeException('No se pudo insertar el producto.');
  }

  $id = (int)$pdo->lastInsertId();

  echo json_encode([
    'ok'      => true,
    'message' => 'Producto creado correctamente.',
    'id'      => $id,
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    'ok'    => false,
    'error' => $e->getMessage(),
  ], JSON_UNESCAPED_UNICODE);
}
