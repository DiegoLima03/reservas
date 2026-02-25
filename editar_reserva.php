<?php
// editar_reserva.php — Edita comercial y cantidad de una reserva,
// ajusta productos.pedido (delta) y sincroniza la oferta (cajas/cliente)
require_once __DIR__ . '/db.php';

@ini_set('display_errors','1');
@ini_set('display_startup_errors','1');
@error_reporting(E_ALL);

header('Content-Type: application/json; charset=UTF-8');

try{
  if($_SERVER['REQUEST_METHOD']!=='POST'){
    http_response_code(405);
    echo json_encode(['ok'=>false,'error'=>'Método no permitido']);
    exit;
  }

  // input JSON
  $raw = file_get_contents('php://input');
  $in  = json_decode($raw, true);
  if(!is_array($in)) $in = $_POST;

  $id               = (int)($in['id'] ?? 0);
  $nuevaCantidad    = (int)($in['cantidad'] ?? 0);
  $comercialIdInput = isset($in['comercial_id']) ? (int)$in['comercial_id'] : null; // puede venir null
  $comercialNomIn   = trim((string)($in['comercial_nombre'] ?? ''));

  $errs=[];
  if($id<=0)                $errs[]='ID de reserva inválido';
  if($nuevaCantidad<=0)     $errs[]='Cantidad debe ser > 0';
  if($comercialNomIn==='')  $errs[]='Comercial/Cliente requerido';

  if($errs){
    http_response_code(400);
    echo json_encode(['ok'=>false,'errors'=>$errs], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $pdo = pdo();
  $pdo->beginTransaction();

  // 1) Leer y bloquear la reserva actual (incluye id_oferta)
  $st = $pdo->prepare("
    SELECT id, producto_id, cantidad, comercial_id, comercial_nombre, id_oferta
    FROM reservas
    WHERE id = ?
    FOR UPDATE
  ");
  $st->execute([$id]);
  $reserva = $st->fetch(PDO::FETCH_ASSOC);

  if(!$reserva){
    $pdo->rollBack();
    http_response_code(404);
    echo json_encode(['ok'=>false, 'error'=>'Reserva no encontrada']);
    exit;
  }

  $productoId     = (int)$reserva['producto_id'];
  $cantidadActual = (int)$reserva['cantidad'];
  $idOferta       = isset($reserva['id_oferta']) ? (int)$reserva['id_oferta'] : 0;
// stock total del producto
$stProd = $pdo->prepare("SELECT cantidad FROM productos WHERE id = ?");
$stProd->execute([$productoId]);
$stockTotal = (int)$stProd->fetchColumn();

// sumatorio de reservas del mismo producto EXCLUYENDO esta
$stSum = $pdo->prepare("SELECT COALESCE(SUM(cantidad),0) FROM reservas WHERE producto_id = ? AND id <> ?");
$stSum->execute([$productoId, $id]);
$sumOtras = (int)$stSum->fetchColumn();

// lo máximo que puede quedar en esta línea = stockTotal - sumOtras (no negativo)
$maxEditable = max(0, $stockTotal - $sumOtras);

// corta si el usuario pide más de lo permitido
if ($nuevaCantidad > $maxEditable) {
  $pdo->rollBack();
  http_response_code(400);
  echo json_encode([
    'ok' => false,
    'error' => "Cantidad máxima permitida para esta reserva: $maxEditable",
    'max' => $maxEditable
  ]);
  exit;
}
  // 2) Resolver comercial: si llega ID, usarlo; si no, buscar por nombre exacto
  $nuevoComId = null;
  $nuevoComNom= null;

  if($comercialIdInput && $comercialIdInput>0){
    $st = $pdo->prepare("SELECT id, nombre FROM comerciales WHERE id=?");
    $st->execute([$comercialIdInput]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if(!$row){
      $pdo->rollBack();
      http_response_code(400);
      echo json_encode(['ok'=>false,'error'=>'comercial_id no existe']);
      exit;
    }
    $nuevoComId  = (int)$row['id'];
    $nuevoComNom = (string)$row['nombre'];
  }else{
    // buscar por nombre exacto (case-insensitive)
    $st = $pdo->prepare("SELECT id, nombre FROM comerciales WHERE nombre COLLATE utf8mb4_general_ci = ? LIMIT 1");
    $st->execute([$comercialNomIn]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if(!$row){
      $pdo->rollBack();
      http_response_code(400);
      echo json_encode(['ok'=>false,'error'=>'No se encontró el comercial por nombre exacto. Selecciónalo del autocompletado.']);
      exit;
    }
    $nuevoComId  = (int)$row['id'];
    $nuevoComNom = (string)$row['nombre'];
  }

  // 3) Actualizar reserva
  $stU = $pdo->prepare("
    UPDATE reservas
    SET comercial_id = ?, comercial_nombre = ?, cantidad = ?
    WHERE id = ?
  ");
  $ok = $stU->execute([$nuevoComId, $nuevoComNom, $nuevaCantidad, $id]);
  if(!$ok){
    throw new RuntimeException('No se pudo actualizar la reserva');
  }

  // 4) Ajustar productos.pedido aplicando delta
  $delta = $nuevaCantidad - $cantidadActual;
  if($productoId>0 && $delta!=0){
    $stP = $pdo->prepare("
      UPDATE productos
      SET pedido = GREATEST(pedido + :delta, 0)
      WHERE id = :pid
    ");
    $stP->execute([':delta'=>$delta, ':pid'=>$productoId]);
  }

  // Commit local antes de tocar la otra BD (mysqli)
  $pdo->commit();

  // 5) Sincronizar OFERTA si existe
  $ofertaSync = ['ok'=>true,'message'=>'No hay oferta que sincronizar'];
  if ($idOferta > 0) {
    try {
      require_once __DIR__ . '/api_verabuy.php'; // Debe exponer $conn (mysqli)
      if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new RuntimeException('Conexión a BD demanda no disponible ($conn).');
      }

      // Ajuste de cajas y disponible con límites
      // disponible := LEAST(cajas_nueva, GREATEST(disponible_actual + delta, 0))
      // cliente := nuevo nombre

      // 5.1 Leer disponible actual y cajas actuales
      $sqlSel = "SELECT cajas, disponible FROM ofertas WHERE id = ? LIMIT 1";
      $stmtSel = $conn->prepare($sqlSel);
      if(!$stmtSel) throw new RuntimeException('Error prepare SELECT oferta: '.$conn->error);
      $stmtSel->bind_param('i', $idOferta);
      $stmtSel->execute();
      $stmtSel->bind_result($cajasAct, $dispAct);
      if(!$stmtSel->fetch()){
        // oferta no encontrada; lo dejamos registrado pero no fallamos
        $stmtSel->close();
        throw new RuntimeException("Oferta id=$idOferta no encontrada para sincronizar");
      }
      $stmtSel->close();

      $cajasNueva = (float)$nuevaCantidad;
      $dispNueva  = $dispAct + (float)$delta;
      if ($dispNueva < 0) $dispNueva = 0;
      if ($dispNueva > $cajasNueva) $dispNueva = $cajasNueva;

      // 5.2 Update
      $sqlUp = "UPDATE ofertas
                SET cajas = ?, disponible = ?, cliente = ?
                WHERE id = ? LIMIT 1";
      $stmtUp = $conn->prepare($sqlUp);
      if(!$stmtUp) throw new RuntimeException('Error prepare UPDATE oferta: '.$conn->error);
      $stmtUp->bind_param('ddsi', $cajasNueva, $dispNueva, $nuevoComNom, $idOferta);
      if(!$stmtUp->execute()){
        $err = $stmtUp->error;
        $stmtUp->close();
        throw new RuntimeException('Error ejecutando UPDATE oferta: '.$err);
      }
      $stmtUp->close();

      $ofertaSync = [
        'ok'=>true,
        'message'=>'Oferta sincronizada',
        'oferta_id'=>$idOferta,
        'cajas'=>$cajasNueva,
        'disponible'=>$dispNueva,
        'cliente'=>$nuevoComNom
      ];
    } catch (Throwable $eSync) {
      // Si falla la sync externa NO rompemos la edición ya confirmada.
      @file_put_contents(__DIR__.'/editar_oferta.log',
        date('c').' [ERROR] '.$eSync->getMessage()."\n", FILE_APPEND);
      $ofertaSync = ['ok'=>false, 'message'=>$eSync->getMessage()];
    }
  }

  echo json_encode([
    'ok'=>true,
    'message'=>'Reserva actualizada',
    'applied_delta'=>$delta,
    'comercial'=>['id'=>$nuevoComId,'nombre'=>$nuevoComNom],
    'oferta_sync'=>$ofertaSync
  ]);

}catch(Throwable $e){
  if(isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
