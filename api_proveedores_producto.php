<?php
// api_proveedores_producto.php — devuelve proveedores que ofrecen un producto
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=UTF-8');

$producto_id      = isset($_GET['producto_id']) ? (int)$_GET['producto_id'] : 0;
$producto_nombre  = isset($_GET['producto_nombre'])
  ? trim((string)$_GET['producto_nombre'])
  : '';

try {
  $pdo = pdo();

  // 1) Determinar el nombre de producto a partir de los parámetros
  if ($producto_nombre !== '') {
    $productoNombre = $producto_nombre;
  } elseif ($producto_id > 0) {
    $st = $pdo->prepare("SELECT nombre FROM productos WHERE id = :pid");
    $st->execute([':pid' => $producto_id]);
    $productoNombre = $st->fetchColumn();
  } else {
    echo json_encode([]);
    exit;
  }

  $proveedores = [];

  if ($productoNombre !== false && $productoNombre !== '') {
    // 2) Proveedores que tienen productos con ese mismo nombre (catálogo)
    $sqlCat = "
      SELECT DISTINCT pr.id, pr.nombre
      FROM productos p
      INNER JOIN proveedores pr
        ON pr.nombre COLLATE utf8mb4_general_ci = p.proveedor COLLATE utf8mb4_general_ci
      WHERE p.nombre = :nombre
      ORDER BY pr.nombre ASC
    ";
    $stCat = $pdo->prepare($sqlCat);
    $stCat->execute([':nombre' => $productoNombre]);
    $proveedores = $stCat->fetchAll(PDO::FETCH_ASSOC);
  }

  // 3) Si no hay proveedores por catálogo, miramos compras_stock del producto_id concreto
  if (!$proveedores) {
    $sqlStock = "
      SELECT DISTINCT pr.id, pr.nombre
      FROM compras_stock c
      INNER JOIN proveedores pr ON pr.id = c.proveedor_id
      WHERE c.producto_id = :pid
      ORDER BY pr.nombre ASC
    ";
    $stStock = $pdo->prepare($sqlStock);
    $stStock->execute([':pid' => $producto_id]);
    $proveedores = $stStock->fetchAll(PDO::FETCH_ASSOC);
  }

  // 4) Si sigue sin haber proveedores específicos, devolvemos todos como último recurso
  if (!$proveedores) {
    $proveedores = $pdo->query("
      SELECT id, nombre
      FROM proveedores
      ORDER BY nombre ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
  }

  echo json_encode($proveedores, JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

