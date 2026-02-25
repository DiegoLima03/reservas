<?php
// save_reserva.php — inserta la reserva guardando IDs y nombres
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
    if (is_array($parsed)) $input = $parsed;
  }
}

$comercial_id = (int)($input['comercial_id'] ?? 0);
$producto_id  = (int)($input['producto_id'] ?? 0);
$cantidad     = (int)($input['cantidad'] ?? 0);
$fecha        = trim((string)($input['fecha'] ?? '')); // nuevo campo YYYY-MM-DD

$errors = [];
if ($comercial_id <= 0) $errors[] = "comercial_id vacío o no válido";
if ($producto_id  <= 0) $errors[] = "producto_id vacío o no válido";
if ($cantidad     <= 0) $errors[] = "cantidad debe ser > 0";
if ($fecha === '')       $errors[] = "fecha requerida (YYYY-MM-DD)";


if ($errors) {
  echo json_encode(['ok' => false, 'errors' => $errors], JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  $pdo = pdo();
  $pdo->beginTransaction();

  // Obtener nombres de tablas relacionadas
  $st = $pdo->prepare("SELECT nombre FROM comerciales WHERE id=?");
  $st->execute([$comercial_id]);
  $comercial_nombre = $st->fetchColumn();




  $st = $pdo->prepare("SELECT nombre, tipo, cultivo, vuelo, fecha FROM productos WHERE id=?");
   $st->execute([$producto_id]);
   $producto_row = $st->fetch(PDO::FETCH_ASSOC);
  $producto_nombre = $producto_row ? $producto_row['nombre'] : false;

  // Validaciones extra
	if (!$comercial_nombre) { throw new RuntimeException("No existe comercial con id=$comercial_id"); }
	
	if (!$producto_nombre)  { throw new RuntimeException("No existe producto con id=$producto_id"); }


  // Insertar reserva (sin producto_ref)
	$sql = "INSERT INTO reservas
			  (comercial_id, comercial_nombre,
			   producto_id,  producto_nombre,
			   cantidad,     fecha)
			VALUES (?, ?, ?, ?, ?, ?)";
	$ins = $pdo->prepare($sql);
	$ok = $ins->execute([
	  $comercial_id,  $comercial_nombre,
	  $producto_id,   $producto_nombre,
	  $cantidad,      $fecha
	]);




  if (!$ok) {
    throw new RuntimeException('No se pudo insertar la reserva (PDO::execute=false).');
  }

  // Obtener el ID de la reserva recién insertada
  $reserva_id = $pdo->lastInsertId();

  // ===================================================================
  // CREAR OFERTA EN BD DEMANDA ANTES DEL COMMIT
  // ===================================================================
  $ofertaResult = ['ok' => false, 'message' => 'No se intentó crear oferta'];
  $id_oferta = null;
  
  try {
    require_once __DIR__ . '/crear_oferta.php';
    
    $fechaProducto      = ($producto_row['fecha'] ?? null);
    if ($fechaProducto === '0000-00-00') { $fechaProducto = null; } // limpia falsy

    $datosOferta = [
     'tipo'             => $producto_row['tipo'] ?? '',
      'nombre'           => $producto_row['nombre'] ?? '',
      'cantidad'         => $cantidad,
      'cultivo'          => $producto_row['cultivo'] ?? '',
      'vuelo'            => $producto_row['vuelo'] ?? '',
     'fecha'            => $fechaProducto,     // <- p.fecha
     'fecha_expedicion'  => $fecha,             // <- input usuario
     'cliente'          => $comercial_nombre
    ];
    
    $ofertaResult = crearOfertaDesdeReserva($datosOferta);
    
    // Si se creó correctamente, obtener el ID
    if ($ofertaResult['ok'] && isset($ofertaResult['oferta_id'])) {
      $id_oferta = (int)$ofertaResult['oferta_id'];
    }
    
    // Log opcional (si falla, no afecta la reserva)
    if (!$ofertaResult['ok']) {
      @file_put_contents(__DIR__ . '/crear_oferta.log',
        date('c') . " [WARNING] " . $ofertaResult['message'] . "\n", FILE_APPEND);
    }
  } catch (Throwable $eOferta) {
    // Si falla la creación de oferta, solo lo registramos (no afecta la reserva)
    @file_put_contents(__DIR__ . '/crear_oferta.log',
      date('c') . " [ERROR] " . $eOferta->getMessage() . "\n", FILE_APPEND);
  }
  
  // Actualizar la reserva con el id_oferta (si se obtuvo)
  if ($id_oferta !== null) {
    $updateReserva = $pdo->prepare("UPDATE reservas SET id_oferta = ? WHERE id = ?");
    $updateReserva->execute([$id_oferta, $reserva_id]);
  }
  // ===================================================================

  // Recalcular total reservado del producto en la tabla productos
  $update = $pdo->prepare("
    UPDATE productos p
    JOIN (
      SELECT producto_id, SUM(cantidad) AS total
      FROM reservas
      WHERE producto_id = ?
      GROUP BY producto_id
    ) r ON p.id = r.producto_id
    SET p.pedido = r.total
  ");
  $update->execute([$producto_id]);

  $pdo->commit();

  echo json_encode([
    'ok' => true, 
    'message' => 'Reserva guardada correctamente.',
    'oferta_info' => $ofertaResult // Info adicional sobre la oferta creada
  ]);

} catch (Throwable $e) {
  if ($pdo && $pdo->inTransaction()) { $pdo->rollBack(); }
  http_response_code(500);
  echo json_encode([
    'ok'=>false,
    'error' => $e->getMessage(),
    'trace' => $e->getTraceAsString()
  ], JSON_UNESCAPED_UNICODE);
}
