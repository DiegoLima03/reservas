<?php
// delete_reserva.php — Borra una reserva por ID (JSON) y ajusta productos.pedido
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

try {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false, 'error'=>'Método no permitido']);
    exit;
  }

  $raw = file_get_contents('php://input');
  $j = json_decode($raw, true);
  $id = isset($j['id']) ? (int)$j['id'] : 0;
  if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['ok'=>false, 'error'=>'ID inválida']);
    exit;
  }

  $pdo = pdo();
  $pdo->beginTransaction();

  // 1) Leer y BLOQUEAR la reserva
  $st = $pdo->prepare("
    SELECT id, producto_id, cantidad, COALESCE(id_oferta, 0) AS id_oferta
    FROM reservas
    WHERE id = :id
    FOR UPDATE
  ");
  $st->execute([':id' => $id]);
  $res = $st->fetch(PDO::FETCH_ASSOC);

  if (!$res) {
    $pdo->rollBack();
    http_response_code(404);
    echo json_encode(['ok'=>false, 'error'=>'Reserva no encontrada']);
    exit;
  }

  $productoId = (int)$res['producto_id'];
  $cantidad   = (int)$res['cantidad'];
  $idOferta   = (int)$res['id_oferta'];

  // 2) Descontar del campo 'pedido' del producto (evitar negativos)
  if ($productoId > 0 && $cantidad > 0) {
    $stU = $pdo->prepare("
      UPDATE productos
      SET pedido = GREATEST(pedido - :cant, 0)
      WHERE id = :pid
    ");
    $stU->execute([':cant' => $cantidad, ':pid' => $productoId]);
  }

  // 3) Borrar la reserva
  $stD = $pdo->prepare("DELETE FROM reservas WHERE id = :id LIMIT 1");
  $stD->execute([':id' => $id]);

  // 4) Commit local
  $pdo->commit();

  // 5) Borrar la oferta en la BD de demanda (fuera de la transacción local)
  $ofertaDeleted = null;
  $ofertaError   = null;

  if ($idOferta > 0) {
    try {
      // Usa la misma conexión que en crear_oferta.php
      require_once __DIR__ . '/api_verabuy.php';  // define $conn (mysqli)

      if (!isset($conn) || !$conn) {
        throw new Exception('Conexión demanda no disponible ($conn).');
      }

      $stmt = $conn->prepare("DELETE FROM ofertas WHERE id = ?");
      if (!$stmt) {
        throw new Exception('Error preparando DELETE oferta: ' . $conn->error);
      }
      $stmt->bind_param('i', $idOferta);
      $ok = $stmt->execute();
      if (!$ok) {
        $err = $stmt->error;
        $stmt->close();
        throw new Exception('Error ejecutando DELETE oferta: ' . $err);
      }
      $stmt->close();
      $ofertaDeleted = true;

    } catch (Throwable $eOferta) {
      $ofertaDeleted = false;
      $ofertaError = $eOferta->getMessage();
      // Log no bloqueante
      @file_put_contents(__DIR__ . '/delete_oferta.log',
        date('c') . " [ERROR] id_oferta=$idOferta :: " . $eOferta->getMessage() . "\n",
        FILE_APPEND
      );
    }
  }

  echo json_encode([
    'ok'            => true,
    'deleted'       => 1,
    'id_oferta'     => $idOferta ?: null,
    'oferta_deleted'=> $ofertaDeleted,
    'oferta_error'  => $ofertaError
  ]);

} catch (Throwable $e) {
  if (isset($pdo) && $pdo->inTransaction()) {
    $pdo->rollBack();
  }
  http_response_code(500);
  echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
