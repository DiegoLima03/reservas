<?php
// reservas_gestion_data.php — API JSON para listado de productos
// Devuelve: id, tipo, nombre, cultivo, vuelo, cantidad, pedido (SUM(reservas.cantidad))
require_once __DIR__ . '/db.php';

@ini_set('display_errors','1');
@error_reporting(E_ALL);

// Cabeceras: JSON y NO CACHÉ
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

try {
  $pdo = pdo();

  // Un único SELECT: productos + total pedido (suma de reservas) por producto
  $sql = "
    SELECT
      p.id,
      p.tipo,
      p.nombre,
      p.cultivo,
      p.vuelo,
      p.fecha,
      p.cantidad,
      COALESCE(r.total, 0) AS pedido
    FROM productos p
    LEFT JOIN (
      SELECT producto_id, SUM(cantidad) AS total
      FROM reservas
      GROUP BY producto_id
    ) r ON r.producto_id = p.id
    ORDER BY p.nombre
  ";

  $st = $pdo->query($sql);
  $productos = [];
  while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
    $productos[] = [
      'id'       => (int)$row['id'],
      'tipo'     => (string)($row['tipo'] ?? ''),
      'nombre'   => (string)($row['nombre'] ?? ''),
      'cultivo'  => (string)($row['cultivo'] ?? ''),
      'vuelo'    => (string)($row['vuelo'] ?? ''),
      'fecha'    => (string)($row['fecha'] ?? ''),
      'cantidad' => (int)($row['cantidad'] ?? 0),
      'pedido'   => (int)($row['pedido'] ?? 0),
    ];
  }

  echo json_encode([
    'ok'          => true,
    'generated_at'=> date('Y-m-d H:i:s'),
    'productos'   => $productos,
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    'ok'    => false,
    'error' => $e->getMessage()
  ], JSON_UNESCAPED_UNICODE);
}
