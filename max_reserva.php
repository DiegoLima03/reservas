<?php
require_once __DIR__ . '/db.php';
header('Content-Type: application/json; charset=UTF-8');

try {
  $raw = file_get_contents('php://input');
  $in = json_decode($raw, true);
  if (!is_array($in)) $in = $_POST;

  $id = (int)($in['id'] ?? 0);
  if ($id <= 0) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'ID inválido']); exit; }

  $pdo = pdo();

  $st = $pdo->prepare("SELECT id, producto_id, cantidad FROM reservas WHERE id=?");
  $st->execute([$id]);
  $r = $st->fetch(PDO::FETCH_ASSOC);
  if(!$r){ http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Reserva no encontrada']); exit; }

  $productoId = (int)$r['producto_id'];

  // stock del producto
  $stP = $pdo->prepare("SELECT cantidad FROM productos WHERE id=?");
  $stP->execute([$productoId]);
  $stockTotal = (int)$stP->fetchColumn();

  // suma de otras reservas del mismo producto
  $stS = $pdo->prepare("SELECT COALESCE(SUM(cantidad),0) FROM reservas WHERE producto_id=? AND id<>?");
  $stS->execute([$productoId, $id]);
  $sumOtras = (int)$stS->fetchColumn();

  $maxEditable = max(0, $stockTotal - $sumOtras);

  echo json_encode([
    'ok'=>true,
    'max'=>$maxEditable,
    'stock_total'=>$stockTotal,
    'suma_otras'=>$sumOtras,
    'cantidad_actual'=>(int)$r['cantidad']
  ]);
} catch(Throwable $e){
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
