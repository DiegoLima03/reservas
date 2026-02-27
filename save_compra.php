<?php
// save_compra.php — registra compras en compras_stock
require_once __DIR__ . '/db.php';

@ini_set('display_errors','1');
@ini_set('display_startup_errors','1');
@error_reporting(E_ALL);

header('Content-Type: application/json; charset=UTF-8');

// Admite form-data o JSON
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

$producto_id     = (int)($input['producto_id']   ?? 0); // opcional, se recalcula
$producto_nombre = trim((string)($input['producto_nombre'] ?? ''));
$proveedor_id    = (int)($input['proveedor_id']  ?? 0);
$cantidad        = (int)($input['cantidad']      ?? 0);
$precio          = isset($input['precio']) ? (float)$input['precio'] : null;
$fecha_compra    = trim((string)($input['fecha_compra'] ?? ''));
$semana          = trim((string)($input['semana'] ?? ''));

if ($semana === '' && $fecha_compra !== '') {
  // Compatibilidad: convertir fecha YYYY-MM-DD a semana ISO YYYY-WNN
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_compra)) {
    $ts = strtotime($fecha_compra);
    if ($ts !== false) {
      $semana = date('o-\WW', $ts);
    }
  } else {
    $semana = $fecha_compra;
  }
}

$errors = [];
if ($producto_nombre === '') $errors[] = "Nombre de producto vacío o no válido";
if ($proveedor_id <= 0) $errors[] = "proveedor_id vacío o no válido";
if ($cantidad     <= 0) $errors[] = "cantidad debe ser > 0";
if ($precio !== null && $precio < 0) $errors[] = "precio no puede ser negativo";
if ($semana === '') $errors[] = "semana requerida (YYYY-WNN)";
if ($semana !== '' && !preg_match('/^\d{4}-W\d{2}$/', $semana)) {
  $errors[] = "semana inválida (YYYY-WNN)";
}

if ($errors) {
  echo json_encode(['ok' => false, 'errors' => $errors], JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  $pdo = pdo();
  $pdo->beginTransaction();

  // Validar existencia de proveedor y obtener su nombre
  $st = $pdo->prepare("SELECT nombre FROM proveedores WHERE id = ?");
  $st->execute([$proveedor_id]);
  $provNombre = $st->fetchColumn();
  if ($provNombre === false) {
    throw new RuntimeException("No existe proveedor con id=$proveedor_id");
  }

  // Resolver el id de producto a partir de nombre + proveedor
  $st = $pdo->prepare("
    SELECT p.id
    FROM productos p
    WHERE p.nombre = :nombre
      AND p.proveedor COLLATE utf8mb4_general_ci = :prov COLLATE utf8mb4_general_ci
    LIMIT 1
  ");
  $st->execute([
    ':nombre' => $producto_nombre,
    ':prov'   => $provNombre,
  ]);
  $producto_id = (int)$st->fetchColumn();

  if ($producto_id <= 0) {
    throw new RuntimeException("No existe producto '$producto_nombre' para el proveedor '$provNombre'.");
  }

  // Comprobar si existe columna de precio_unitario en compras_stock
  $hasPrecio = (bool)$pdo->query("SHOW COLUMNS FROM compras_stock LIKE 'precio_unitario'")->fetch(PDO::FETCH_ASSOC);

  // Insertar compra (entrada de stock)
  if ($hasPrecio) {
    $sql = "
      INSERT INTO compras_stock
        (producto_id, proveedor_id, cantidad_comprada, cantidad_disponible, semana, precio_unitario)
      VALUES
        (:producto_id, :proveedor_id, :cantidad_comprada, :cantidad_disponible, :semana, :precio_unitario)
    ";
  } else {
    $sql = "
      INSERT INTO compras_stock
        (producto_id, proveedor_id, cantidad_comprada, cantidad_disponible, semana)
      VALUES
        (:producto_id, :proveedor_id, :cantidad_comprada, :cantidad_disponible, :semana)
    ";
  }

  $stmt = $pdo->prepare($sql);
  $params = [
    ':producto_id'         => $producto_id,
    ':proveedor_id'        => $proveedor_id,
    ':cantidad_comprada'   => $cantidad,
    ':cantidad_disponible' => $cantidad,
    ':semana'              => $semana,
  ];
  if ($hasPrecio) {
    $params[':precio_unitario'] = $precio !== null ? $precio : 0;
  }
  $ok = $stmt->execute($params);

  if (!$ok) {
    throw new RuntimeException("No se pudo registrar la compra.");
  }

  $compra_id = (int)$pdo->lastInsertId();

  $pdo->commit();

  echo json_encode([
    'ok'        => true,
    'message'   => 'Compra registrada correctamente.',
    'compra_id' => $compra_id,
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  if (isset($pdo) && $pdo->inTransaction()) {
    $pdo->rollBack();
  }
  http_response_code(500);
  echo json_encode([
    'ok'    => false,
    'error' => $e->getMessage(),
  ], JSON_UNESCAPED_UNICODE);
}
