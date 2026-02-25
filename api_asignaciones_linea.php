<?php
// api_asignaciones_linea.php — devuelve asignaciones para un producto+proveedor concretos
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=UTF-8');

$producto_id  = isset($_GET['producto_id'])  ? (int)$_GET['producto_id']  : 0;
$proveedor_id = isset($_GET['proveedor_id']) ? (int)$_GET['proveedor_id'] : 0;
$fecha_compra = isset($_GET['fecha_compra']) ? trim((string)$_GET['fecha_compra']) : '';

if ($producto_id <= 0 || $proveedor_id <= 0 || $fecha_compra === '') {
  echo json_encode([]);
  exit;
}

try {
  $pdo = pdo();

  // 1) Lotes (compras_stock) correspondientes a esa fila de la matriz
  $sqlLotes = "
    SELECT id
    FROM compras_stock
    WHERE producto_id  = :pid
      AND proveedor_id = :prid
      AND fecha_compra = :fcompra
  ";
  $stL = $pdo->prepare($sqlLotes);
  $stL->execute([
    ':pid'     => $producto_id,
    ':prid'    => $proveedor_id,
    ':fcompra' => $fecha_compra,
  ]);
  $lotes = $stL->fetchAll(PDO::FETCH_COLUMN);

  if (!$lotes) {
    echo json_encode([]);
    exit;
  }

  // 2) Asignaciones que han consumido esos lotes (detalle por lote)
  $placeholders = implode(',', array_fill(0, count($lotes), '?'));

  $sql = "
    SELECT
      a.id,
      d.nombre        AS delegacion,
      al.cantidad     AS cantidad_asignada,
      a.fecha_salida,
      a.created_at
    FROM asignacion_lotes al
    INNER JOIN asignaciones a   ON a.id = al.asignacion_id
    INNER JOIN compras_stock cs ON cs.id = al.compra_stock_id
    INNER JOIN delegaciones d   ON d.id = a.delegacion_id
    WHERE cs.id IN ($placeholders)
    ORDER BY a.fecha_salida DESC, a.id DESC
  ";

  $st = $pdo->prepare($sql);
  $st->execute($lotes);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  // Si todavía no hay detalle por lote (asignacion_lotes vacío para estos lotes),
  // hacemos un fallback a las asignaciones por producto+proveedor (y fecha_salida >= fecha_compra)
  if (!$rows) {
    $sqlFb = "
      SELECT
        a.id,
        d.nombre        AS delegacion,
        a.cantidad_asignada,
        a.fecha_salida,
        a.created_at
      FROM asignaciones a
      INNER JOIN delegaciones d ON d.id = a.delegacion_id
      WHERE a.producto_id  = :pid
        AND a.proveedor_id = :prid
        AND a.fecha_salida >= :fdesde
      ORDER BY a.fecha_salida DESC, a.id DESC
    ";
    $stFb = $pdo->prepare($sqlFb);
    $stFb->execute([
      ':pid'    => $producto_id,
      ':prid'   => $proveedor_id,
      ':fdesde' => $fecha_compra,
    ]);
    $rows = $stFb->fetchAll(PDO::FETCH_ASSOC);
  }

  echo json_encode($rows, JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

