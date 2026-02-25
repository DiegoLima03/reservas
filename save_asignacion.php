<?php
// save_asignacion.php — registra una salida/asignación a delegación
require_once __DIR__ . '/db.php';

@ini_set('display_errors','1');
@ini_set('display_startup_errors','1');
@error_reporting(E_ALL);

header('Content-Type: application/json; charset=UTF-8');

function isoWeekToMondayDate(?string $isoWeek): ?string
{
  if (!is_string($isoWeek)) return null;
  $isoWeek = trim($isoWeek);
  if ($isoWeek === '' || !preg_match('/^\d{4}-W\d{2}$/', $isoWeek)) return null;

  [$year, $week] = explode('-W', $isoWeek);
  $y = (int)$year;
  $w = (int)$week;
  if ($w < 1 || $w > 53) return null;

  $dt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
  return $dt->setISODate($y, $w, 1)->format('Y-m-d');
}

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

$producto_id   = (int)($input['producto_id']   ?? 0);
$proveedor_id  = (int)($input['proveedor_id']  ?? 0);
$delegacion_id = (int)($input['delegacion_id'] ?? 0);
$cantidad      = (int)($input['cantidad']      ?? 0);
$semana_salida = trim((string)($input['semana_salida'] ?? ''));
$fecha_salida  = trim((string)($input['fecha_salida'] ?? ''));

if ($semana_salida === '' && $fecha_salida !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_salida)) {
  $ts = strtotime($fecha_salida);
  if ($ts !== false) {
    $semana_salida = date('o-\WW', $ts);
  }
}

if ($fecha_salida === '' && $semana_salida !== '') {
  $fecha_salida = (string)isoWeekToMondayDate($semana_salida);
}

$errors = [];
if ($producto_id   <= 0) $errors[] = "producto_id vacío o no válido";
if ($proveedor_id  <= 0) $errors[] = "proveedor_id vacío o no válido";
if ($delegacion_id <= 0) $errors[] = "delegacion_id vacío o no válido";
if ($cantidad      <= 0) $errors[] = "cantidad debe ser > 0";
if ($semana_salida === '') $errors[] = "semana_salida requerida (YYYY-WNN)";
if ($semana_salida !== '' && !preg_match('/^\d{4}-W\d{2}$/', $semana_salida)) {
  $errors[] = "semana_salida inválida (YYYY-WNN)";
}
if ($fecha_salida === '') $errors[] = "No se pudo convertir semana_salida a fecha";

if ($errors) {
  echo json_encode(['ok' => false, 'errors' => $errors], JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  $pdo = pdo();
  $pdo->beginTransaction();

  // Validar entidades
  $st = $pdo->prepare("SELECT id FROM productos WHERE id = ?");
  $st->execute([$producto_id]);
  if (!$st->fetchColumn()) {
    throw new RuntimeException("No existe producto con id=$producto_id");
  }

  $st = $pdo->prepare("SELECT id FROM proveedores WHERE id = ?");
  $st->execute([$proveedor_id]);
  if (!$st->fetchColumn()) {
    throw new RuntimeException("No existe proveedor con id=$proveedor_id");
  }

  $st = $pdo->prepare("SELECT id FROM delegaciones WHERE id = ?");
  $st->execute([$delegacion_id]);
  if (!$st->fetchColumn()) {
    throw new RuntimeException("No existe delegación con id=$delegacion_id");
  }

  // Leer compras_stock de esa dupla producto-proveedor, bloqueando filas
  $st = $pdo->prepare("
    SELECT id, cantidad_disponible
    FROM compras_stock
    WHERE producto_id = ? AND proveedor_id = ?
    ORDER BY semana ASC, id ASC
    FOR UPDATE
  ");
  $st->execute([$producto_id, $proveedor_id]);
  $compras = $st->fetchAll(PDO::FETCH_ASSOC);

  if (!$compras) {
    throw new RuntimeException("No hay stock registrado para este producto/proveedor.");
  }

  $totalDisponible = 0;
  foreach ($compras as $c) {
    $totalDisponible += (int)$c['cantidad_disponible'];
  }

  if ($cantidad > $totalDisponible) {
    $pdo->rollBack();
    http_response_code(400);
    echo json_encode([
      'ok'    => false,
      'error' => "Stock insuficiente. Disponible: $totalDisponible, solicitado: $cantidad"
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // Insertar asignación
  $sqlIns = "
    INSERT INTO asignaciones
      (producto_id, proveedor_id, delegacion_id, cantidad_asignada, fecha_salida)
    VALUES
      (:producto_id, :proveedor_id, :delegacion_id, :cantidad_asignada, :fecha_salida)
  ";
  $ins = $pdo->prepare($sqlIns);
  $ok = $ins->execute([
    ':producto_id'       => $producto_id,
    ':proveedor_id'      => $proveedor_id,
    ':delegacion_id'     => $delegacion_id,
    ':cantidad_asignada' => $cantidad,
    ':fecha_salida'      => $fecha_salida,
  ]);

  if (!$ok) {
    throw new RuntimeException('No se pudo insertar la asignación.');
  }

  $asignacion_id = (int)$pdo->lastInsertId();

  // Restar cantidad de compras_stock con estrategia FIFO por fecha_compra
  $restante = $cantidad;
  $stUpd = $pdo->prepare("
    UPDATE compras_stock
    SET cantidad_disponible = cantidad_disponible - :quita
    WHERE id = :id
  ");

  // Detalle por lote: qué parte de la asignación sale de cada compra_stock
  $stInsLote = $pdo->prepare("
    INSERT INTO asignacion_lotes (asignacion_id, compra_stock_id, cantidad)
    VALUES (:asignacion_id, :compra_stock_id, :cantidad)
  ");

  foreach ($compras as $c) {
    if ($restante <= 0) break;
    $disp = (int)$c['cantidad_disponible'];
    if ($disp <= 0) continue;

    $quita = min($disp, $restante);
    $stUpd->execute([
      ':quita' => $quita,
      ':id'    => (int)$c['id'],
    ]);
    // Registrar relación asignación-lote
    $stInsLote->execute([
      ':asignacion_id'   => $asignacion_id,
      ':compra_stock_id' => (int)$c['id'],
      ':cantidad'        => $quita,
    ]);
    $restante -= $quita;
  }

  if ($restante > 0) {
    // No debería ocurrir porque ya validamos stock, pero por seguridad:
    throw new RuntimeException('Inconsistencia al actualizar compras_stock.');
  }

  $pdo->commit();

  echo json_encode([
    'ok' => true,
    'message' => 'Asignación registrada correctamente.',
    'asignacion_id' => $asignacion_id,
    'stock_restante_global' => $totalDisponible - $cantidad
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  if (isset($pdo) && $pdo->inTransaction()) {
    $pdo->rollBack();
  }
  http_response_code(500);
  echo json_encode([
    'ok'    => false,
    'error' => $e->getMessage()
  ], JSON_UNESCAPED_UNICODE);
}
