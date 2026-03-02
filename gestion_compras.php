<?php
// gestion_compras.php — Gestión de compras (entradas) y stock por proveedor + asignaciones (salidas)
require_once __DIR__ . '/db.php';

@ini_set('display_errors','1');
@error_reporting(E_ALL);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function normalizeIsoWeek(?string $value): string {
  $value = trim((string)$value);
  if ($value === '') return '';
  if (preg_match('/^\d{4}-W\d{2}$/', $value)) return $value;
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
    $ts = strtotime($value);
    if ($ts !== false) {
      return date('o-\WW', $ts);
    }
  }
  return '';
}

try {
  $pdo = pdo();

  // =============================
  // Matriz de stock agrupada por producto + proveedor + semana
  // =============================
  $sqlMatriz = "
    SELECT
      p.id                 AS producto_id,
      p.tipo,
      p.nombre             AS producto_nombre,
      p.proveedor          AS producto_proveedor,
      pr.id                AS proveedor_id,
      pr.nombre            AS proveedor_nombre,
      c.semana AS fecha_compra,
      SUM(c.cantidad_comprada)                             AS cantidad_comprada,
      SUM(c.cantidad_comprada - c.cantidad_disponible)     AS cantidad_asignada,
      SUM(c.cantidad_disponible)                           AS cantidad_disponible
    FROM compras_stock c
    INNER JOIN productos   p  ON p.id  = c.producto_id
    INNER JOIN proveedores pr ON pr.id = c.proveedor_id
    GROUP BY
      p.id,
      p.tipo,
      p.nombre,
      p.proveedor,
      pr.id,
      pr.nombre,
      c.semana
    ORDER BY
      p.nombre ASC,
      pr.nombre ASC,
      c.semana DESC
  ";
  $matriz = $pdo->query($sqlMatriz)->fetchAll(PDO::FETCH_ASSOC);

  // Marcar qué líneas están completamente asignadas y son "antiguas"
  // para poder ocultarlas por defecto en la matriz y mostrarlas bajo demanda.
  // Regla: ocultar si restante = 0 y la semana de compra es anterior a la actual.
  $semanaActualIso = (new DateTimeImmutable('today'))->format('o-\WW');
  foreach ($matriz as &$row) {
    $row['cerrada_antigua'] = 0;
    $restante = (int)($row['cantidad_disponible'] ?? 0);
    $semanaFila = normalizeIsoWeek((string)($row['fecha_compra'] ?? ''));
    $row['semana_compra_norm'] = $semanaFila;
    if ($restante <= 0) {
      if ($semanaFila !== '') {
        // Comparación lexicográfica segura por formato fijo YYYY-WNN
        if ($semanaFila < $semanaActualIso) {
          $row['cerrada_antigua'] = 1;
        }
      }
    }
  }
  unset($row);

  // =============================
  // Listado básico de productos (para pestaña Productos)
  // =============================
  $hasCodigoVelneo = (bool)$pdo->query("SHOW COLUMNS FROM productos LIKE 'codigo_velneo'")->fetch(PDO::FETCH_ASSOC);
  $selectCodigoVelneo = $hasCodigoVelneo ? 'codigo_velneo' : "'' AS codigo_velneo";
  $hasPrecioProducto = (bool)$pdo->query("SHOW COLUMNS FROM productos LIKE 'precio'")->fetch(PDO::FETCH_ASSOC);
  $selectPrecioProducto = $hasPrecioProducto ? 'precio' : "NULL AS precio";
  $sqlProductos = "
    SELECT id, tipo, nombre, proveedor, {$selectCodigoVelneo}, {$selectPrecioProducto}
    FROM productos
    ORDER BY nombre
  ";
  $productos = $pdo->query($sqlProductos)->fetchAll(PDO::FETCH_ASSOC);

  // =============================
  // Tipos de producto (tabla tipos_producto)
  // =============================
  $sqlTipos = "
    SELECT id, nombre
    FROM tipos_producto
    ORDER BY nombre
  ";
  $tiposProducto = $pdo->query($sqlTipos)->fetchAll(PDO::FETCH_ASSOC);

  // =============================
  // Proveedores (para pestaña Proveedores)
  // =============================
  $sqlProveedores = "
    SELECT id, nombre
    FROM proveedores
    ORDER BY nombre
  ";
  $proveedores = $pdo->query($sqlProveedores)->fetchAll(PDO::FETCH_ASSOC);

  // =============================
  // Delegaciones (para pestaña Delegaciones)
  // =============================
  $sqlDelegaciones = "
    SELECT id, nombre
    FROM delegaciones
    ORDER BY id ASC
  ";
  $delegaciones = $pdo->query($sqlDelegaciones)->fetchAll(PDO::FETCH_ASSOC);

  // =============================
  // Pre-reservas pendientes
  // =============================
  $sqlPreReservas = "
    SELECT id, comercial_id, comercial_nombre, tipo, producto_deseado, cantidad, fecha_deseada, estado, created_at
    FROM pre_reservas
    WHERE estado = 'pendiente'
    ORDER BY fecha_deseada ASC, created_at ASC
  ";
  $preReservas = $pdo->query($sqlPreReservas)->fetchAll(PDO::FETCH_ASSOC);

  // Cantidad total pedida por producto (todas las pre-reservas pendientes)
  $preReservasPendPorProductoBruto = [];
  foreach ($preReservas as $pr) {
    $productoPre = trim((string)($pr['producto_deseado'] ?? ''));
    if ($productoPre === '') {
      continue;
    }
    $key = mb_strtolower($productoPre, 'UTF-8');
    $preReservasPendPorProductoBruto[$key] = (int)($preReservasPendPorProductoBruto[$key] ?? 0) + (int)($pr['cantidad'] ?? 0);
  }

  // Calcular stock libre tras cubrir las pre-reservas:
  // stock_restante (cantidad_disponible en compras_stock) - pre_reservas_pendientes
  $preReservasPendPorProducto = [];
  if ($preReservasPendPorProductoBruto) {
    $sqlComprasPorProducto = "
      SELECT
        p.nombre AS producto_nombre,
        SUM(c.cantidad_disponible) AS total_restante
      FROM compras_stock c
      INNER JOIN productos p ON p.id = c.producto_id
      GROUP BY p.nombre
    ";
    $comprasPorProducto = [];
    foreach ($pdo->query($sqlComprasPorProducto) as $rowCompra) {
      $nombreProd = trim((string)($rowCompra['producto_nombre'] ?? ''));
      if ($nombreProd === '') {
        continue;
      }
      $key = mb_strtolower($nombreProd, 'UTF-8');
      $comprasPorProducto[$key] = (int)($comprasPorProducto[$key] ?? 0) + (int)($rowCompra['total_restante'] ?? 0);
    }

    foreach ($preReservasPendPorProductoBruto as $key => $cantidadPreReservas) {
      $restante = (int)($comprasPorProducto[$key] ?? 0);
      // stock libre = stock restante - pre-reservas
      $libre = (int)$restante - (int)$cantidadPreReservas;
      if ($libre > 0) {
        $preReservasPendPorProducto[$key] = $libre;
      }
    }
  }

  // =============================
  // Reparto: matriz Producto (Y) x Delegación (X)
  // =============================
  $repartoSemanaFiltro = normalizeIsoWeek((string)($_GET['reparto_semana'] ?? ''));
  if ($repartoSemanaFiltro === '') {
    $repartoSemanaFiltro = (new DateTimeImmutable('today'))->format('o-\WW');
  }

  $sqlReparto = "
    SELECT
      p.tipo AS tipo_producto,
      p.nombre AS nombre_producto,
      p.proveedor AS proveedor_producto,
      a.delegacion_id,
      SUM(a.cantidad_asignada) AS cantidad_total
    FROM asignaciones a
    INNER JOIN productos p ON p.id = a.producto_id
    WHERE DATE_FORMAT(a.fecha_salida, '%x-W%v') = :reparto_semana
    GROUP BY p.tipo, p.nombre, p.proveedor, a.delegacion_id
  ";
  $stReparto = $pdo->prepare($sqlReparto);
  $stReparto->execute([':reparto_semana' => $repartoSemanaFiltro]);
  $rowsReparto = $stReparto->fetchAll(PDO::FETCH_ASSOC);
  $repartoMap = [];
  foreach ($rowsReparto as $rr) {
    $tipo = trim((string)($rr['tipo_producto'] ?? ''));
    $nombre = trim((string)($rr['nombre_producto'] ?? ''));
    $proveedor = trim((string)($rr['proveedor_producto'] ?? ''));
    $did = (int)($rr['delegacion_id'] ?? 0);
    $qty = (int)($rr['cantidad_total'] ?? 0);
    $keyProd = mb_strtolower($tipo . '|' . $nombre . '|' . $proveedor, 'UTF-8');
    if (!isset($repartoMap[$keyProd])) {
      $repartoMap[$keyProd] = [];
    }
    $repartoMap[$keyProd][$did] = $qty;
  }

  // Precio medio ponderado por producto (si existe columna precio_unitario)
  $preciosPorProducto = [];
  $hasPrecioReparto = (bool)$pdo->query("SHOW COLUMNS FROM compras_stock LIKE 'precio_unitario'")->fetch(PDO::FETCH_ASSOC);
  if ($hasPrecioReparto) {
    $sqlPrecios = "
      SELECT
        p.tipo,
        p.nombre,
        p.proveedor,
        SUM(c.cantidad_comprada) AS total_cantidad,
        SUM(c.cantidad_comprada * c.precio_unitario) AS total_importe
      FROM compras_stock c
      INNER JOIN productos p ON p.id = c.producto_id
      GROUP BY p.tipo, p.nombre, p.proveedor
    ";
    foreach ($pdo->query($sqlPrecios) as $rowPrecio) {
      $tipoP = trim((string)($rowPrecio['tipo'] ?? ''));
      $nombreP = trim((string)($rowPrecio['nombre'] ?? ''));
      $proveedorP = trim((string)($rowPrecio['proveedor'] ?? ''));
      $key = mb_strtolower($tipoP . '|' . $nombreP . '|' . $proveedorP, 'UTF-8');
      $totalCantidad = (float)($rowPrecio['total_cantidad'] ?? 0);
      $totalImporte  = (float)($rowPrecio['total_importe'] ?? 0);
      if ($totalCantidad > 0) {
        $preciosPorProducto[$key] = $totalImporte / $totalCantidad;
      }
    }
  }

  $productosEje = [];
  foreach ($productos as $p) {
    $tipo = trim((string)($p['tipo'] ?? ''));
    $nombre = trim((string)($p['nombre'] ?? ''));
    $proveedor = trim((string)($p['proveedor'] ?? ''));
    $codigoVelneo = $hasCodigoVelneo ? trim((string)($p['codigo_velneo'] ?? '')) : '';
    $keyProd = mb_strtolower($tipo . '|' . $nombre . '|' . $proveedor, 'UTF-8');
    if (!isset($productosEje[$keyProd])) {
      $productosEje[$keyProd] = [
        'tipo'            => $tipo,
        'nombre'          => $nombre,
        'proveedor'       => $proveedor,
        'codigo_velneo'   => $codigoVelneo,
        'precio_unitario' => $preciosPorProducto[$keyProd] ?? null,
      ];
    }
  }
  $productosEje = array_values($productosEje);

  // =============================
  // Exportación Excel por delegación (ZIP de CSVs)
  // =============================
  if (isset($_GET['export_reparto']) && $_GET['export_reparto'] === '1') {
    // Si no existe ZipArchive, dejamos el comportamiento antiguo (un solo CSV global)
    if (!class_exists('ZipArchive')) {
      $filename = 'reparto_' . str_replace('W', '', $repartoSemanaFiltro) . '_' . date('Ymd_His') . '.csv';
      header('Content-Type: text/csv; charset=UTF-8');
      header('Content-Disposition: attachment; filename="' . $filename . '"');
      echo "\xEF\xBB\xBF";

      $out = fopen('php://output', 'w');
      if ($out === false) {
        throw new RuntimeException('No se pudo abrir salida CSV.');
      }

      $header = ['Producto'];
      foreach ($delegaciones as $d) {
        $header[] = (string)($d['nombre'] ?? '');
      }
      $header[] = 'Total';
      fputcsv($out, $header, ';');

      $totalesPorDelegacion = [];
      $totalGeneral = 0;
      foreach ($delegaciones as $d) {
        $totalesPorDelegacion[(int)$d['id']] = 0;
      }

      foreach ($productosEje as $pe) {
        $tipo = trim((string)($pe['tipo'] ?? ''));
        $nombre = trim((string)($pe['nombre'] ?? ''));
        $proveedor = trim((string)($pe['proveedor'] ?? ''));
        $keyProd = mb_strtolower($tipo . '|' . $nombre . '|' . $proveedor, 'UTF-8');
        $labelProducto = trim($tipo . ' - ' . $nombre, ' -');
        if ($proveedor !== '') {
          $labelProducto .= ' (' . $proveedor . ')';
        }

        $row = [$labelProducto];
        $totalFila = 0;
        foreach ($delegaciones as $d) {
          $did = (int)$d['id'];
          $qty = (int)($repartoMap[$keyProd][$did] ?? 0);
          $row[] = $qty;
          $totalFila += $qty;
          $totalesPorDelegacion[$did] += $qty;
        }
        $row[] = $totalFila;
        $totalGeneral += $totalFila;
        fputcsv($out, $row, ';');
      }

      $footer = ['Totales'];
      foreach ($delegaciones as $d) {
        $footer[] = (int)($totalesPorDelegacion[(int)$d['id']] ?? 0);
      }
      $footer[] = (int)$totalGeneral;
      fputcsv($out, $footer, ';');

      fclose($out);
      exit;
    }

    // Nuevo comportamiento: un CSV por delegación dentro de un ZIP
    $zipFilename = 'reparto_' . str_replace('W', '', $repartoSemanaFiltro) . '_delegaciones_' . date('Ymd_His') . '.zip';
    $tmpZipPath = tempnam(sys_get_temp_dir(), 'reparto_zip_');
    $zip = new ZipArchive();
    if ($zip->open($tmpZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
      throw new RuntimeException('No se pudo crear el archivo ZIP de exportación.');
    }

    foreach ($delegaciones as $d) {
      $did = (int)$d['id'];
      $nombreDeleg = trim((string)($d['nombre'] ?? 'Delegacion_' . $did));

      // Crear CSV en memoria
      $csvHandle = fopen('php://temp', 'r+');
      if ($csvHandle === false) {
        continue;
      }

      // Escribir BOM UTF-8 para que Excel respete acentos
      fwrite($csvHandle, "\xEF\xBB\xBF");

      // Cabecera: Delegación, Código, Artículo, Proveedor, Cantidad
      $header = [
        'Delegación',
        'Código',
        'Artículo',
        'Proveedor',
        'Cantidad',
      ];
      fputcsv($csvHandle, $header, ';');

      $totalDelegacion = 0;
      $tieneFilas = false;

      foreach ($productosEje as $pe) {
        $tipo = trim((string)($pe['tipo'] ?? ''));
        $nombre = trim((string)($pe['nombre'] ?? ''));
        $proveedor = trim((string)($pe['proveedor'] ?? ''));
        $codigoVelneo = trim((string)($pe['codigo_velneo'] ?? ''));

        $keyProd = mb_strtolower($tipo . '|' . $nombre . '|' . $proveedor, 'UTF-8');
        $qty = (int)($repartoMap[$keyProd][$did] ?? 0);
        if ($qty <= 0) {
          continue;
        }

        $tieneFilas = true;
        $totalDelegacion += $qty;

        // Artículo: tipo + nombre (sin proveedor, que va en su propia columna)
        $labelProducto = trim($tipo . ' - ' . $nombre, ' -');

        $row = [
          $nombreDeleg,
          $codigoVelneo,
          $labelProducto,
          $proveedor,
          $qty,
        ];
        fputcsv($csvHandle, $row, ';');
      }

      if ($tieneFilas) {
        rewind($csvHandle);
        $csvContent = stream_get_contents($csvHandle);
        fclose($csvHandle);

        $safeName = preg_replace('/[^A-Za-z0-9_\-]/', '_', $nombreDeleg);
        $csvNameInZip = 'reparto_' . str_replace('W', '', $repartoSemanaFiltro) . '_delegacion_' . $did . '_' . $safeName . '.csv';
        $zip->addFromString($csvNameInZip, $csvContent);
      } else {
        fclose($csvHandle);
      }
    }

    $zip->close();

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zipFilename . '"');
    header('Content-Length: ' . filesize($tmpZipPath));

    readfile($tmpZipPath);
    @unlink($tmpZipPath);
    exit;
  }

} catch (Throwable $e) {
  http_response_code(500);
  echo "<pre>Error cargando gestión de compras: " . h($e->getMessage()) . "</pre>";
  exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Gestión de compras y distribución</title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link
    href="https://fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,100..900;1,14..32,100..900&family=Merriweather+Sans:ital,wght@0,300..800;1,300..800;1,300..800&family=Poppins:ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,100;1,200;1,300;1,400;1,500;1,600;1,700;1,800;1,900&display=swap"
    rel="stylesheet">
  <link rel="stylesheet" href="style.css">
  <link rel="icon" href="img/Veraleza.png" type="image/png" class="url-logo">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

  <style>
    :root {
      --vz-negro: #10180e;
      --vz-marron1: #46331f;
      --vz-marron2: #85725e;
      --vz-crema: #e5e2dc;
      --vz-verde: #8e8b30;
      --vz-rojo: #c83c32;
      --vz-blanco: #ffffff;
      --vz-verde-suave: rgba(142, 139, 48, 0.1);
    }

    body.gestion-compras {
      margin: 0;
      font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif !important;
      background: var(--vz-crema) !important;
      color: var(--vz-negro);
    }

    body.gestion-compras .compras-layout {
      width: 100%;
      margin: 8px 0 10px;
      padding: 0 10px 14px;
    }

    body.gestion-compras .page-headline {
      margin-bottom: 10px !important;
      align-items: center;
      gap: 8px;
    }

    body.gestion-compras .tit {
      margin: 0;
      font-size: 24px !important;
      color: var(--vz-negro);
    }

    body.gestion-compras .btn {
      border-radius: 8px;
      font-size: 13px;
      font-weight: 600;
      transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease, border-color 0.2s ease;
    }

    body.gestion-compras .btn:hover {
      transform: translateY(-1px);
    }

    body.gestion-compras .btn-primary,
    body.gestion-compras .btn-success {
      background: var(--vz-verde);
      border-color: var(--vz-verde);
      color: var(--vz-crema);
    }

    body.gestion-compras .btn-primary:hover,
    body.gestion-compras .btn-success:hover {
      background: #7c792b;
      border-color: #7c792b;
      box-shadow: 0 4px 12px rgba(142, 139, 48, 0.3);
    }

    body.gestion-compras .btn-outline-primary,
    body.gestion-compras .btn-outline-secondary,
    body.gestion-compras .btn-outline-success {
      border-color: var(--vz-marron2);
      color: var(--vz-marron1);
      background: var(--vz-crema);
    }

    body.gestion-compras .btn-outline-primary:hover,
    body.gestion-compras .btn-outline-secondary:hover,
    body.gestion-compras .btn-outline-success:hover {
      border-color: var(--vz-marron1);
      background: var(--vz-blanco);
      color: var(--vz-marron1);
      box-shadow: 0 4px 12px rgba(16, 24, 14, 0.14);
    }

    body.gestion-compras .btn-outline-danger {
      border-color: var(--vz-rojo);
      color: var(--vz-rojo);
      background: var(--vz-blanco);
    }

    body.gestion-compras .btn-outline-danger:hover {
      border-color: var(--vz-rojo);
      background: var(--vz-rojo);
      color: var(--vz-crema);
      box-shadow: 0 4px 12px rgba(200, 60, 50, 0.24);
    }

    body.gestion-compras .btn-secondary {
      border-color: var(--vz-marron2);
      background: var(--vz-blanco);
      color: var(--vz-marron2);
    }

    body.gestion-compras .btn-secondary:hover {
      border-color: var(--vz-marron1);
      background: var(--vz-crema);
      color: var(--vz-marron1);
    }

    body.gestion-compras .btn-link {
      color: var(--vz-verde);
      text-decoration: none;
    }

    body.gestion-compras .btn-link:hover {
      color: #7c792b;
    }

    body.gestion-compras #tabsCompras {
      margin: 0 0 10px;
      padding: 0;
      border: 0;
      display: flex;
      align-items: center;
      gap: 6px;
      flex-wrap: wrap;
    }

    body.gestion-compras #tabsCompras .nav-item {
      margin: 0;
    }

    body.gestion-compras #tabsCompras .nav-link {
      border: 1px solid var(--vz-marron2);
      border-radius: 8px;
      background: var(--vz-blanco);
      color: var(--vz-marron1);
      padding: 8px 12px;
      font-size: 13px;
      line-height: 1.2;
      transition: background 0.2s ease, color 0.2s ease, border-color 0.2s ease;
    }

    body.gestion-compras #tabsCompras .nav-link:hover {
      background: var(--vz-crema);
      border-color: var(--vz-marron1);
    }

    body.gestion-compras #tabsCompras .nav-link.active {
      background: var(--vz-verde);
      border-color: var(--vz-verde);
      color: var(--vz-crema);
      font-weight: 700;
    }

    body.gestion-compras #tabsComprasContent {
      background: var(--vz-blanco);
      border: 1px solid var(--vz-marron2);
      border-radius: 12px;
      box-shadow: 0 2px 8px rgba(16, 24, 14, 0.08);
      padding: 14px;
    }

    body.gestion-compras .master-filters {
      padding: 12px 12px 0;
      border-bottom: 1px solid var(--vz-marron2);
      margin-bottom: 12px !important;
    }

    body.gestion-compras .card {
      background: var(--vz-blanco);
      border: 1px solid var(--vz-marron2);
      border-radius: 12px;
      box-shadow: 0 2px 8px rgba(16, 24, 14, 0.08) !important;
      overflow: hidden;
    }

    body.gestion-compras .card-body {
      padding: 14px;
    }

    body.gestion-compras .form-label,
    body.gestion-compras .form-text,
    body.gestion-compras .text-muted,
    body.gestion-compras .small.text-muted {
      color: var(--vz-marron2) !important;
    }

    body.gestion-compras .form-control,
    body.gestion-compras .form-select,
    body.gestion-compras .input-group-text {
      border: 1px solid var(--vz-marron2);
      border-radius: 8px;
      background: var(--vz-blanco);
      color: var(--vz-negro);
      font-size: 13px;
    }

    body.gestion-compras .form-control:focus,
    body.gestion-compras .form-select:focus {
      border-color: var(--vz-verde);
      box-shadow: 0 0 0 0.2rem rgba(142, 139, 48, 0.2);
    }

    .cell-desc { min-width: 100px; }
    .cell-qty { text-align: right; white-space: nowrap; }
    .search-mini { max-width: 320px; }

    .autocomplete-wrap { position: relative; }
    .autocomplete-list {
      position: absolute;
      z-index: 1080;
      left: 0;
      right: 0;
      top: calc(100% - 1px);
      background: var(--vz-blanco);
      border: 1px solid var(--vz-marron2);
      border-top: 0;
      max-height: 40vh;
      overflow: auto;
    }

    .autocomplete-item {
      padding: 0.45rem 0.6rem;
      cursor: pointer;
    }

    .autocomplete-item.active,
    .autocomplete-item:hover {
      background: var(--vz-verde-suave);
    }

    body.gestion-compras .table-responsive {
      border: 1px solid var(--vz-marron2);
      border-radius: 10px;
      background: var(--vz-blanco);
    }

    body.gestion-compras .table {
      margin-bottom: 0;
      font-size: 14px;
      border-collapse: collapse;
      --bs-table-bg: transparent;
    }

    body.gestion-compras .table > :not(caption) > * > * {
      border-color: var(--vz-marron2);
      padding: 10px 12px;
      border-bottom-width: 1px;
      vertical-align: middle;
    }

    body.gestion-compras .table > thead {
      background: var(--vz-verde);
      color: var(--vz-crema);
      text-align: left;
    }

    body.gestion-compras .table > thead > tr {
      border-left: 3px solid var(--vz-verde);
    }

    body.gestion-compras .table > thead th {
      border-left: none;
      box-shadow: none;
      border-bottom: none;
      font-weight: 600;
      white-space: nowrap;
    }

    body.gestion-compras .table-bordered > :not(caption) > * > * {
      border-width: 0 0 1px;
    }

    body.gestion-compras .table-striped > tbody > tr:nth-of-type(odd) > * {
      --bs-table-accent-bg: transparent;
      color: inherit;
    }

    body.gestion-compras .table tbody tr {
      border-left: 3px solid transparent;
      transition: border-left 0.2s ease, background 0.2s ease;
    }

    body.gestion-compras .table tbody tr:hover {
      border-left: 3px solid var(--vz-verde);
      background: var(--vz-verde-suave);
    }

    .table-sticky thead th {
      position: sticky;
      top: 0;
      background: var(--vz-verde) !important;
      color: var(--vz-crema) !important;
      z-index: 2;
    }

    body.gestion-compras .table tfoot tr {
      background: var(--vz-crema) !important;
      color: var(--vz-marron1);
    }

    .row-resto0 td,
    .row-resto0 th {
      background: rgba(200, 60, 50, 0.08) !important;
    }

    .row-cerrada-antigua {
      opacity: 0.68;
    }

    body.gestion-compras .modal-content {
      border: 1px solid var(--vz-marron2);
      border-radius: 14px;
      overflow: hidden;
      box-shadow: 0 14px 30px rgba(16, 24, 14, 0.24);
    }

    body.gestion-compras .modal-header {
      border-bottom: 1px solid rgba(255, 255, 255, 0.2);
      background: var(--vz-verde);
      color: var(--vz-crema);
    }

    body.gestion-compras .modal-header .btn-close {
      filter: invert(1) grayscale(1);
      opacity: 0.88;
    }

    body.gestion-compras .modal-footer {
      border-top: 1px solid #e8e2d8;
    }

    @media (max-width: 991.98px) {
      body.gestion-compras .page-headline {
        align-items: flex-start;
      }

      body.gestion-compras #tabsComprasContent {
        padding: 10px;
      }

      body.gestion-compras .master-filters {
        padding: 10px 10px 0;
      }
    }
  </style>
</head>
<body class="gestion-compras">
<div class="container-fluid py-4 compras-layout">

  <div class="d-flex align-items-center justify-content-between mb-3 page-headline">
    <h1 class="h4 mb-0 tit">Módulo de Compras y Distribución</h1>
    <div class="d-flex gap-2">
      <a href="detalle_movimientos.php" class="btn btn-sm btn-outline-primary">Detalle movimientos</a>
    </div>
  </div>

  <!-- Pestañas -->
  <ul class="nav nav-tabs mb-3 compras-tabs" id="tabsCompras" role="tablist">
    <li class="nav-item" role="presentation">
      <button class="nav-link" id="tab-compra-tab" data-bs-toggle="tab" data-bs-target="#tab-compra" type="button" role="tab">
        Registrar compra
      </button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link" id="tab-prereservas-tab" data-bs-toggle="tab" data-bs-target="#tab-prereservas" type="button" role="tab">
        Pre-reservas
      </button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link active" id="tab-stock-tab" data-bs-toggle="tab" data-bs-target="#tab-stock" type="button" role="tab">
        Matriz de stock
      </button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link" id="tab-reparto-tab" data-bs-toggle="tab" data-bs-target="#tab-reparto" type="button" role="tab">
        Reparto
      </button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link" id="tab-productos-tab" data-bs-toggle="tab" data-bs-target="#tab-productos" type="button" role="tab">
        Productos
      </button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link" id="tab-proveedores-tab" data-bs-toggle="tab" data-bs-target="#tab-proveedores" type="button" role="tab">
        Proveedores
      </button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link" id="tab-delegaciones-tab" data-bs-toggle="tab" data-bs-target="#tab-delegaciones" type="button" role="tab">
        Delegaciones
      </button>
    </li>
  </ul>

  <div class="tab-content compras-content" id="tabsComprasContent">

    <!-- TAB 1: Registrar compra -->
    <div class="tab-pane fade" id="tab-compra" role="tabpanel" aria-labelledby="tab-compra-tab">
      <div class="card shadow-sm mb-3">
        <div class="card-body">
          <form id="formCompra" autocomplete="off" class="row g-3">
            <div class="col-md-3">
              <label class="form-label"><b>Producto</b></label>
              <div class="autocomplete-wrap">
                <input type="text" class="form-control" id="c_producto_nombre" placeholder="Escribe para buscar…">
                <input type="hidden" id="c_producto_id" name="producto_id">
                <div class="autocomplete-list d-none" id="c_producto_list"></div>
              </div>
            </div>
            <div class="col-md-3">
              <label class="form-label"><b>Proveedor</b></label>
              <select id="c_proveedor_id" name="proveedor_id" class="form-select">
                <option value="">Selecciona proveedor…</option>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label"><b>Cantidad comprada</b></label>
              <input type="number" min="1" step="1" class="form-control" id="c_cantidad" name="cantidad">
              <div class="form-text" id="c_hint_prereserva"></div>
            </div>
            <div class="col-md-2">
              <label class="form-label"><b>Precio unitario</b></label>
              <div class="input-group">
                <span class="input-group-text">€</span>
                <input type="number" min="0" step="0.01" class="form-control" id="c_precio" name="precio">
              </div>
            </div>
            <div class="col-md-2">
              <label class="form-label"><b>Semana compra</b></label>
              <input type="week" class="form-control" id="c_semana" name="semana">
            </div>
            <div class="col-12 d-flex align-items-center justify-content-between pt-2">
              <div class="text-danger small" id="c_error" style="display:none;"></div>
              <button type="submit" class="btn btn-success ms-auto">Guardar compra</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- TAB 2: Matriz de stock -->
    <div class="tab-pane fade show active" id="tab-stock" role="tabpanel" aria-labelledby="tab-stock-tab">
      <div class="d-flex align-items-end justify-content-between mb-2 flex-wrap gap-2 master-filters">
        <div class="d-flex flex-column">
          <label for="ftexto" class="form-label mb-1 small text-muted"><b>Buscar</b></label>
          <input type="text" id="ftexto" class="form-control form-control-sm search-mini" placeholder="Producto / proveedor">
        </div>
        <div class="d-flex gap-2 align-items-end">
          <button type="button" id="btnToggleCerradas" class="btn btn-sm btn-outline-secondary">
            Mostrar líneas cerradas antiguas
          </button>
          <button type="button" id="btnRefrescar" class="btn btn-sm btn-outline-secondary">Refrescar</button>
        </div>
      </div>

      <div id="matrizContainer">
        <div class="card shadow-sm">
          <div class="card-body">
            <div class="table-responsive" style="max-height:70vh;">
              <table class="table table-striped table-bordered table-sm align-middle table-sticky" id="tablaMatriz">
                <thead class="table-success">
                  <tr>
                    <th style="width:36px;" class="text-center"></th>
                    <th>Tipo</th>
                    <th style="width:70px;">ID</th>
                    <th class="cell-desc">Producto</th>
                    <th>Proveedor</th>
                    <th>Semana compra</th>
                    <th class="cell-qty">Total comprado</th>
                    <th class="cell-qty">Asignado</th>
                    <th class="cell-qty">Restante</th>
                    <th class="text-center">Acciones</th>
                  </tr>
                </thead>
                <tbody>
                <?php
                $totalCompradoSum = 0;
                $totalAsignadoSum = 0;
                $totalRestanteSum = 0;
                foreach ($matriz as $row):
                  $semanaCompra = (string)($row['semana_compra_norm'] ?? '');
                  $totalComprado  = (int)$row['cantidad_comprada'];
                  $asignadoLote   = (int)$row['cantidad_asignada'];
                  $restante       = (int)$row['cantidad_disponible'];
                  $totalCompradoSum += $totalComprado;
                  $totalAsignadoSum += $asignadoLote;
                  $totalRestanteSum += $restante;
                  $rowClassParts  = [];
                  if ($restante === 0) {
                    $rowClassParts[] = 'row-resto0';
                  }
                  if (!empty($row['cerrada_antigua'])) {
                    $rowClassParts[] = 'row-cerrada-antigua';
                  }
                  $rowClass = implode(' ', $rowClassParts);
                ?>
                  <tr
                    class="<?= $rowClass ?>"
                    data-comprado="<?= $totalComprado ?>"
                    data-asignado="<?= $asignadoLote ?>"
                    data-restante="<?= $restante ?>"
                  >
                    <td class="text-center align-middle">
                      <button
                        type="button"
                        class="btn btn-link btn-sm p-0 btn-lineas"
                        data-producto-id="<?= (int)$row['producto_id'] ?>"
                        data-proveedor-id="<?= (int)$row['proveedor_id'] ?>"
                        data-fecha-compra-raw="<?= h($semanaCompra) ?>"
                        title="Ver líneas de asignación"
                      >+</button>
                    </td>
                    <td><?= h($row['tipo']) ?></td>
                    <td><?= (int)$row['producto_id'] ?></td>
                    <td class="cell-desc"><?= h($row['producto_nombre']) ?></td>
                    <td><?= h($row['proveedor_nombre']) ?></td>
                    <td><?= h($semanaCompra) ?></td>
                    <td class="cell-qty"><?= number_format($totalComprado, 0, ',', '.') ?></td>
                    <td class="cell-qty"><?= number_format($asignadoLote, 0, ',', '.') ?></td>
                    <td class="cell-qty"><?= number_format($restante, 0, ',', '.') ?></td>
                    <td class="text-center">
                      <button
                        type="button"
                        class="btn btn-primary btn-sm btn-asignar"
                        data-producto-id="<?= (int)$row['producto_id'] ?>"
                        data-producto-nombre="<?= h($row['producto_nombre']) ?>"
                        data-proveedor-id="<?= (int)$row['proveedor_id'] ?>"
                        data-proveedor-nombre="<?= h($row['proveedor_nombre']) ?>"
                        data-restante="<?= max(0,$restante) ?>"
                        <?= $restante === 0 ? 'disabled title="Sin stock disponible"' : '' ?>
                      >Asignar</button>
                    </td>
                  </tr>
                <?php endforeach; ?>
                <?php if (!$matriz): ?>
                  <tr>
                    <td colspan="10" class="text-center text-muted">No hay stock registrado.</td>
                  </tr>
                <?php endif; ?>
                </tbody>
                <?php if ($matriz): ?>
                <tfoot>
                  <tr class="table-secondary fw-semibold">
                    <td colspan="6" class="text-end">Totales:</td>
                    <td class="cell-qty"><?= number_format($totalCompradoSum, 0, ',', '.') ?></td>
                    <td class="cell-qty"><?= number_format($totalAsignadoSum, 0, ',', '.') ?></td>
                    <td class="cell-qty"><?= number_format($totalRestanteSum, 0, ',', '.') ?></td>
                    <td></td>
                  </tr>
                </tfoot>
                <?php endif; ?>
              </table>
            </div>
          </div>
        </div>
      </div>

    </div>

    <!-- TAB 3: Reparto -->
    <div class="tab-pane fade" id="tab-reparto" role="tabpanel" aria-labelledby="tab-reparto-tab">
      <div class="card shadow-sm mb-3">
        <div class="card-body">
          <div class="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-2">
            <h2 class="h6 mb-0">Reparto: productos por delegación</h2>
            <div class="d-flex align-items-center gap-2 flex-wrap">
              <form method="get" class="d-flex align-items-center gap-2 mb-0">
                <label for="reparto_semana" class="form-label mb-0 small text-muted"><b>Semana</b></label>
                <input
                  type="week"
                  id="reparto_semana"
                  name="reparto_semana"
                  class="form-control form-control-sm"
                  value="<?= h($repartoSemanaFiltro) ?>"
                  onchange="this.form.submit()"
                >
              </form>
              <a href="gestion_compras.php?export_reparto=1&reparto_semana=<?= urlencode($repartoSemanaFiltro) ?>" class="btn btn-sm btn-outline-success">Exportar Excel por delegación</a>
            </div>
          </div>
          <div class="table-responsive" style="max-height:70vh;">
            <table class="table table-striped table-bordered table-sm align-middle table-sticky" id="tablaReparto">
              <thead class="table-success">
                <tr>
                  <th style="min-width:140px;">Código ERP</th>
                  <th style="min-width:220px;">Producto</th>
                  <th class="cell-qty" style="min-width:120px;">Precio unitario</th>
                  <?php foreach ($delegaciones as $d): ?>
                    <th class="cell-qty" style="min-width:120px;"><?= h($d['nombre']) ?></th>
                  <?php endforeach; ?>
                  <th class="cell-qty" style="min-width:120px;">Total</th>
                </tr>
              </thead>
              <tbody>
              <?php if (empty($productosEje)): ?>
                <tr>
                  <td colspan="<?= count($delegaciones) + 4 ?>" class="text-center text-muted">No hay productos.</td>
                </tr>
              <?php else: ?>
                <?php
                  $totalesPorDelegacion = [];
                  $totalGeneral = 0;
                  foreach ($delegaciones as $d) {
                    $totalesPorDelegacion[(int)$d['id']] = 0;
                  }
                ?>
                <?php foreach ($productosEje as $pe): ?>
                  <?php
                    $tipo = trim((string)($pe['tipo'] ?? ''));
                    $nombre = trim((string)($pe['nombre'] ?? ''));
                    $proveedor = trim((string)($pe['proveedor'] ?? ''));
                    $keyProd = mb_strtolower($tipo . '|' . $nombre . '|' . $proveedor, 'UTF-8');
                    $labelProducto = trim($tipo . ' - ' . $nombre, ' -');
                    if ($proveedor !== '') {
                      $labelProducto .= ' (' . $proveedor . ')';
                    }
                    $totalFila = 0;
                  ?>
                  <tr>
                    <td><?= h($pe['codigo_velneo'] ?? '') ?></td>
                    <td><?= h($labelProducto) ?></td>
                    <td class="cell-qty">
                      <?php if (isset($pe['precio_unitario']) && $pe['precio_unitario'] !== null): ?>
                        <?= number_format((float)$pe['precio_unitario'], 2, ',', '.') ?> €
                      <?php endif; ?>
                    </td>
                    <?php foreach ($delegaciones as $d): ?>
                      <?php
                        $did = (int)$d['id'];
                        $qty = (int)($repartoMap[$keyProd][$did] ?? 0);
                        $totalFila += $qty;
                        $totalesPorDelegacion[$did] += $qty;
                      ?>
                      <td class="cell-qty"><?= $qty > 0 ? number_format($qty, 0, ',', '.') : '' ?></td>
                    <?php endforeach; ?>
                    <?php $totalGeneral += $totalFila; ?>
                    <td class="cell-qty fw-semibold"><?= $totalFila > 0 ? number_format($totalFila, 0, ',', '.') : '' ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
              </tbody>
              <?php if (!empty($productosEje)): ?>
                <tfoot>
                  <tr class="table-secondary fw-semibold">
                    <td colspan="3" class="text-end">Totales:</td>
                    <?php foreach ($delegaciones as $d): ?>
                      <td class="cell-qty"><?= number_format((int)($totalesPorDelegacion[(int)$d['id']] ?? 0), 0, ',', '.') ?></td>
                    <?php endforeach; ?>
                    <td class="cell-qty"><?= number_format((int)$totalGeneral, 0, ',', '.') ?></td>
                  </tr>
                </tfoot>
              <?php endif; ?>
            </table>
          </div>
        </div>
      </div>
    </div>
    <!-- TAB 4: Pre-reservas -->
    <div class="tab-pane fade" id="tab-prereservas" role="tabpanel" aria-labelledby="tab-prereservas-tab">
      <div class="card shadow-sm mb-3">
        <div class="card-body">
          <div class="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-2">
            <div class="d-flex flex-column">
              <h2 class="h6 mb-1">Pre-reservas pendientes</h2>
              <input
                type="text"
                id="pr_filtro"
                class="form-control form-control-sm search-mini"
                placeholder="Delegación / tipo / producto / semana / cantidad"
              >
            </div>
            <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modalPreReserva">Nueva Pre-reserva</button>
          </div>

          <div class="table-responsive" style="max-height:70vh;">
            <table class="table table-striped table-bordered table-sm align-middle table-sticky" id="tablaPreReservas">
              <thead class="table-success">
                <tr>
                  <th style="width:70px;">ID</th>
                  <th>Delegación</th>
                  <th>Tipo</th>
                  <th>Producto</th>
                  <th style="width:120px;" class="cell-qty">Cantidad</th>
                  <th style="width:130px;">Semana deseada</th>
                  <th style="width:110px;" class="text-center">Convertir</th>
                  <th style="width:110px;" class="text-center">Eliminar</th>
                </tr>
              </thead>
              <tbody>
              <?php $preCantidadTotal = 0; ?>
              <?php if (!$preReservas): ?>
                <tr>
                  <td colspan="8" class="text-center text-muted">No hay pre-reservas pendientes.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($preReservas as $pr): ?>
                  <?php
                    $fechaDeseadaRaw = (string)($pr['fecha_deseada'] ?? '');
                    $semanaDeseada   = $fechaDeseadaRaw !== '' ? date('o-\WW', strtotime($fechaDeseadaRaw)) : '';
                    $cantidadPre = (int)$pr['cantidad'];
                    $preCantidadTotal += $cantidadPre;
                  ?>
                  <tr data-cantidad="<?= $cantidadPre ?>">
                    <td><?= (int)$pr['id'] ?></td>
                    <td><?= h($pr['comercial_nombre']) ?></td>
                    <td><?= h($pr['tipo']) ?></td>
                    <td><?= h($pr['producto_deseado']) ?></td>
                    <td class="cell-qty"><?= number_format($cantidadPre, 0, ',', '.') ?></td>
                    <td><?= h($semanaDeseada) ?></td>
                    <td class="text-center">
                      <button
                        type="button"
                        class="btn btn-sm btn-outline-primary btn-convertir-pr"
                        data-prereserva-id="<?= (int)$pr['id'] ?>"
                        data-prereserva-delegacion="<?= h($pr['comercial_nombre']) ?>"
                        data-prereserva-tipo="<?= h($pr['tipo']) ?>"
                        data-prereserva-variedad="<?= h($pr['producto_deseado']) ?>"
                        data-prereserva-cantidad="<?= (int)$pr['cantidad'] ?>"
                      >Convertir</button>
                    </td>
                    <td class="text-center">
                      <button
                        type="button"
                        class="btn btn-sm btn-outline-danger btn-eliminar-pr"
                        data-prereserva-id="<?= (int)$pr['id'] ?>"
                      >Eliminar</button>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
              </tbody>
              <?php if ($preReservas): ?>
              <tfoot>
                <tr class="table-secondary fw-semibold">
                  <td colspan="4" class="text-end">Totales:</td>
                  <td class="cell-qty" id="pr_total_cantidad"><?= number_format($preCantidadTotal, 0, ',', '.') ?></td>
                  <td colspan="3"></td>
                </tr>
              </tfoot>
              <?php endif; ?>
            </table>
          </div>
        </div>
      </div>
    </div>

    <div class="tab-pane fade" id="tab-productos" role="tabpanel" aria-labelledby="tab-productos-tab">
      <div class="row g-3 mb-3">
        <div class="col-lg-5">
          <div class="card shadow-sm">
            <div class="card-body">
              <h2 class="h6 mb-3">Nuevo producto</h2>
              <form id="formProducto" autocomplete="off">
                <div class="mb-2">
                  <label class="form-label mb-1"><b>Tipo</b></label>
                  <select id="p_tipo_id" name="tipo_id" class="form-select form-select-sm">
                    <option value="">Selecciona tipo…</option>
                    <?php foreach ($tiposProducto as $t): ?>
                      <option value="<?= (int)$t['id'] ?>"><?= h($t['nombre']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="mb-2">
                  <label class="form-label mb-1"><b>Nombre</b></label>
                  <input type="text" id="p_nombre" name="nombre" class="form-control form-control-sm" required>
                </div>
                <div class="mb-2">
                  <label class="form-label mb-1"><b>Código ERP (Velneo)</b></label>
                  <input type="text" id="p_codigo_velneo" name="codigo_velneo" class="form-control form-control-sm" maxlength="64">
                </div>
                <div class="mb-2">
                  <label class="form-label mb-1"><b>Proveedor</b></label>
                  <div class="autocomplete-wrap">
                    <input type="text" id="p_proveedor_nombre" class="form-control form-control-sm" placeholder="Escribe para buscar…">
                    <input type="hidden" id="p_proveedor_id" name="proveedor_id">
                    <div class="autocomplete-list d-none" id="p_proveedor_list"></div>
                  </div>
                </div>
                <div class="mb-2">
                  <label class="form-label mb-1"><b>Precio</b></label>
                  <div class="input-group input-group-sm">
                    <span class="input-group-text">€</span>
                    <input type="number" step="0.01" min="0" id="p_precio" name="precio" class="form-control form-control-sm">
                  </div>
                </div>
                <div class="d-flex align-items-center mt-3">
                  <div class="text-danger small" id="p_error" style="display:none;"></div>
                  <button type="submit" class="btn btn-success btn-sm ms-auto">Guardar producto</button>
                </div>
              </form>
            </div>
          </div>
        </div>

        <div class="col-lg-7">
          <div class="card shadow-sm">
            <div class="card-body">
              <div class="d-flex align-items-end justify-content-between mb-2 flex-wrap gap-2">
                <div class="d-flex flex-column">
                  <label for="p_filtro" class="form-label mb-1 small text-muted"><b>Buscar productos</b></label>
                  <input type="text" id="p_filtro" class="form-control form-control-sm search-mini" placeholder="Código / tipo / nombre / proveedor">
                </div>
              </div>
              <div class="table-responsive" style="max-height:70vh;">
                <table class="table table-striped table-bordered table-sm align-middle table-sticky" id="tablaProductos">
                  <thead class="table-success">
                    <tr>
                      <th style="width:70px;">ID</th>
                      <th style="width:170px;">Código ERP</th>
                      <th>Tipo</th>
                      <th class="cell-desc">Nombre</th>
                      <th>Proveedor</th>
                      <th class="cell-qty" style="width:110px;">Precio</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php if (!$productos): ?>
                    <tr>
                      <td colspan="6" class="text-center text-muted">No hay productos.</td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($productos as $p): ?>
                      <tr>
                        <td><?= (int)$p['id'] ?></td>
                        <td><?= h($p['codigo_velneo'] ?? '') ?></td>
                        <td><?= h($p['tipo']) ?></td>
                        <td class="cell-desc"><?= h($p['nombre']) ?></td>
                        <td><?= h($p['proveedor']) ?></td>
                        <td class="cell-qty">
                          <?php if (isset($p['precio']) && $p['precio'] !== null): ?>
                            <?= number_format((float)$p['precio'], 2, ',', '.') ?> €
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div><!-- /tab-productos -->

    <!-- TAB 4: Gestión de proveedores -->
    <div class="tab-pane fade" id="tab-proveedores" role="tabpanel" aria-labelledby="tab-proveedores-tab">
      <div class="row g-3 mb-3">
        <div class="col-lg-5">
          <div class="card shadow-sm">
            <div class="card-body">
              <h2 class="h6 mb-3">Nuevo proveedor</h2>
              <form id="formProveedor" autocomplete="off">
                <div class="mb-2">
                  <label class="form-label mb-1"><b>Nombre</b></label>
                  <input type="text" id="v_nombre" name="nombre" class="form-control form-control-sm" required>
                </div>
                <div class="d-flex align-items-center mt-3">
                  <div class="text-danger small" id="v_error" style="display:none;"></div>
                  <button type="submit" class="btn btn-success btn-sm ms-auto">Guardar proveedor</button>
                </div>
              </form>
            </div>
          </div>
        </div>

        <div class="col-lg-7">
          <div class="card shadow-sm">
            <div class="card-body">
              <div class="d-flex align-items-end justify-content-between mb-2 flex-wrap gap-2">
                <div class="d-flex flex-column">
                  <label for="v_filtro" class="form-label mb-1 small text-muted"><b>Buscar proveedores</b></label>
                  <input type="text" id="v_filtro" class="form-control form-control-sm search-mini" placeholder="Nombre">
                </div>
              </div>
              <div class="table-responsive" style="max-height:70vh;">
                <table class="table table-striped table-bordered table-sm align-middle table-sticky" id="tablaProveedores">
                  <thead class="table-success">
                    <tr>
                      <th style="width:70px;">ID</th>
                      <th>Nombre</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php if (!$proveedores): ?>
                    <tr>
                      <td colspan="2" class="text-center text-muted">No hay proveedores.</td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($proveedores as $pr): ?>
                      <tr>
                        <td><?= (int)$pr['id'] ?></td>
                        <td><?= h($pr['nombre']) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div><!-- /tab-proveedores -->

    <!-- TAB 5: Gestión de delegaciones -->
    <div class="tab-pane fade" id="tab-delegaciones" role="tabpanel" aria-labelledby="tab-delegaciones-tab">
      <div class="row g-3 mb-3">
        <div class="col-lg-5">
          <div class="card shadow-sm">
            <div class="card-body">
              <h2 class="h6 mb-3">Nueva delegación</h2>
              <form id="formDelegacion" autocomplete="off">
                <div class="mb-2">
                  <label class="form-label mb-1"><b>Nombre</b></label>
                  <input type="text" id="d_nombre" name="nombre" class="form-control form-control-sm" required>
                </div>
                <div class="d-flex align-items-center mt-3">
                  <div class="text-danger small" id="d_error" style="display:none;"></div>
                  <button type="submit" class="btn btn-success btn-sm ms-auto">Guardar delegación</button>
                </div>
              </form>
            </div>
          </div>
        </div>

        <div class="col-lg-7">
          <div class="card shadow-sm">
            <div class="card-body">
              <div class="d-flex align-items-end justify-content-between mb-2 flex-wrap gap-2">
                <div class="d-flex flex-column">
                  <label for="d_filtro" class="form-label mb-1 small text-muted"><b>Buscar delegaciones</b></label>
                  <input type="text" id="d_filtro" class="form-control form-control-sm search-mini" placeholder="Nombre">
                </div>
              </div>
              <div class="table-responsive" style="max-height:70vh;">
                <table class="table table-striped table-bordered table-sm align-middle table-sticky" id="tablaDelegaciones">
                  <thead class="table-success">
                    <tr>
                      <th style="width:70px;">ID</th>
                      <th>Nombre</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php if (!$delegaciones): ?>
                    <tr>
                      <td colspan="2" class="text-center text-muted">No hay delegaciones.</td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($delegaciones as $dg): ?>
                      <tr>
                        <td><?= (int)$dg['id'] ?></td>
                        <td><?= h($dg['nombre']) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div><!-- /tab-delegaciones -->

  </div><!-- /tab-content -->
</div>

<!-- MODAL ASIGNACIÓN A DELEGACIÓN -->
<div class="modal fade" id="modalAsignacion" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form id="formAsignacion" autocomplete="off">
        <div class="modal-header">
          <h5 class="modal-title">Asignar a delegación</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body">
          <div class="mb-2">
            <label class="form-label mb-0"><b>Producto</b></label>
            <input type="text" id="a_producto_nombre" class="form-control" readonly>
            <input type="hidden" id="a_producto_id" name="producto_id">
          </div>
          <div class="mb-2">
            <label class="form-label mb-0"><b>Proveedor</b></label>
            <input type="text" id="a_proveedor_nombre" class="form-control" readonly>
            <input type="hidden" id="a_proveedor_id" name="proveedor_id">
          </div>
          <div class="mb-3 small text-muted">
            <span><b>Stock restante:</b> <span id="a_restante_label">0</span></span>
          </div>
          <div class="mb-3">
            <label class="form-label"><b>Delegación</b></label>
            <div class="autocomplete-wrap">
              <input type="text" id="a_delegacion_nombre" class="form-control" placeholder="Escribe para buscar…">
              <input type="hidden" id="a_delegacion_id" name="delegacion_id">
              <div class="autocomplete-list d-none" id="a_delegacion_list"></div>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label"><b>Cantidad a asignar</b></label>
            <input type="number" min="1" step="1" id="a_cantidad" name="cantidad" class="form-control" required>
          </div>
          <div class="mb-0">
            <label class="form-label"><b>Semana salida</b></label>
            <input type="week" id="a_semana" name="semana_salida" class="form-control">
          </div>
        </div>
        <div class="modal-footer">
          <div class="me-auto text-danger small" id="a_error" style="display:none;"></div>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button class="btn btn-primary" type="submit">Guardar asignación</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- MODAL NUEVA PRE-RESERVA -->
<div class="modal fade" id="modalPreReserva" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form id="formPreReserva" autocomplete="off">
        <div class="modal-header">
          <h5 class="modal-title">Nueva pre-reserva</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label"><b>Delegación</b></label>
            <div class="autocomplete-wrap">
              <input type="text" id="pr_delegacion_nombre" class="form-control" placeholder="Escribe para buscar…">
              <input type="hidden" id="pr_delegacion_id" name="delegacion_id">
              <div class="autocomplete-list d-none" id="pr_delegacion_list"></div>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label"><b>Producto</b></label>
            <div class="autocomplete-wrap">
              <input type="text" id="pr_producto_nombre" class="form-control" placeholder="Escribe para buscar…">
              <input type="hidden" id="pr_producto_id" name="producto_id">
              <div class="autocomplete-list d-none" id="pr_producto_list"></div>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label"><b>Cantidad</b></label>
            <input type="number" min="1" step="1" id="pr_cantidad" name="cantidad" class="form-control" required>
          </div>
          <div class="mb-0">
            <label class="form-label"><b>Semana deseada</b></label>
            <input type="week" id="pr_semana_deseada" name="semana_deseada" class="form-control" required>
          </div>
        </div>
        <div class="modal-footer">
          <div class="me-auto text-danger small" id="pr_error" style="display:none;"></div>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button class="btn btn-primary" type="submit">Guardar pre-reserva</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- MODAL CONVERTIR PRE-RESERVA -->
<div class="modal fade" id="modalConvertirPreReserva" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form id="formConvertirPreReserva" autocomplete="off">
        <div class="modal-header">
          <h5 class="modal-title">Convertir pre-reserva</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" id="cp_pre_reserva_id" name="pre_reserva_id">
          <div class="mb-2 small text-muted">
            <div><b>Delegación:</b> <span id="cp_delegacion_label">-</span></div>
            <div><b>Tipo:</b> <span id="cp_tipo_label">-</span></div>
            <div><b>Variedad:</b> <span id="cp_variedad_label">-</span></div>
            <div><b>Cantidad:</b> <span id="cp_cantidad_label">0</span></div>
          </div>
          <div class="mt-2">
            <label class="form-label"><b>Reparto por proveedor</b></label>
            <div id="cp_split_rows" class="d-flex flex-column gap-2"></div>
            <div class="mt-2 d-flex align-items-center justify-content-between">
              <button type="button" class="btn btn-sm btn-outline-secondary" id="cp_add_split_row">Añadir proveedor</button>
              <span class="small text-muted" id="cp_split_total_info"></span>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <div class="me-auto text-danger small" id="cp_error" style="display:none;"></div>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button class="btn btn-primary" type="submit">Convertir</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
const API_SEARCH = new URL('api_search.php', location.href).toString();
const nf         = new Intl.NumberFormat('es-ES');
const PRE_RESERVA_BY_PRODUCT = <?= json_encode($preReservasPendPorProducto ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
let mostrarCerradasAntiguas = false;

function debounce(fn, ms){
  let t; return (...a)=>{ clearTimeout(t); t=setTimeout(()=>fn(...a), ms); };
}
function getCurrentIsoWeek(){
  const now = new Date();
  const weekStart = new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate()));
  const day = weekStart.getUTCDay() || 7;
  weekStart.setUTCDate(weekStart.getUTCDate() + 4 - day);
  const yearStart = new Date(Date.UTC(weekStart.getUTCFullYear(), 0, 1));
  const weekNo = Math.ceil((((weekStart - yearStart) / 86400000) + 1) / 7);
  return `${weekStart.getUTCFullYear()}-W${String(weekNo).padStart(2, '0')}`;
}
function updateCompraGhostForProducto(productoNombre){
  const cantidadInput = document.getElementById('c_cantidad');
  const hint = document.getElementById('c_hint_prereserva');
  if (!cantidadInput || !hint) return;

  const nombre = (productoNombre || '').trim();
  if (!nombre) {
    cantidadInput.placeholder = '';
    hint.textContent = '';
    return;
  }

  const key = nombre.toLocaleLowerCase('es-ES');
  const pendiente = parseInt(PRE_RESERVA_BY_PRODUCT[key] || 0, 10) || 0;

  if (pendiente > 0) {
    cantidadInput.placeholder = `Stock libre: ${nf.format(pendiente)}`;
    hint.textContent = `Stock libre tras pre-reservas de ${nombre}: ${nf.format(pendiente)}`;
  } else {
    cantidadInput.placeholder = '';
    hint.textContent = `Sin stock libre adicional para ${nombre}.`;
  }
}
function createAutocomplete({input, hidden, list, type}) {
  let current = [], active = -1;
  function clear(){
    list.innerHTML = '';
    list.classList.add('d-none');
    list.style.display = 'none';
    active = -1;
  }
  function render(items){
    current = items || [];
    list.innerHTML = '';
    if (!current.length){ clear(); return; }
    const frag = document.createDocumentFragment();
    current.forEach((it, i)=>{
      const div = document.createElement('div');
      div.className = 'autocomplete-item';
      div.textContent = it.nombre;
      div.addEventListener('mousedown', e=>{
        e.preventDefault();
        e.stopPropagation();
        input.value = it.nombre;
        // Para productos en el alta de compras no fijamos todavía el id definitivo:
        // primero se elige el nombre (agrupado) y luego el proveedor.
        if (type === 'producto' && input.id === 'c_producto_nombre') {
          hidden.value = ''; // se resolverá en el backend con nombre+proveedor
          loadProveedoresForProducto(it.nombre);
          updateCompraGhostForProducto(it.nombre);
        } else {
          hidden.value = it.id;
        }
        clear();
        input.blur();
      });
      frag.appendChild(div);
    });
    list.appendChild(frag);
    list.classList.remove('d-none');
    list.style.display = 'block';
  }
  const search = debounce(async ()=>{
    const q = input.value.trim();
    hidden.value = '';
    if (!q){ clear(); return; }
    try {
      const url = API_SEARCH + '?type='+encodeURIComponent(type)+'&q='+encodeURIComponent(q)+'&_=' + Date.now();
      const res = await fetch(url, {headers:{'Accept':'application/json'}});
      if (!res.ok) throw new Error('HTTP '+res.status);
      render(await res.json());
    } catch(e) {
      console.error('Autocomplete error', e);
      clear();
    }
  }, 160);
  input.addEventListener('input', search);
  input.addEventListener('keydown', (e)=>{
    const items = Array.from(list.querySelectorAll('.autocomplete-item'));
    if(e.key==='ArrowDown'){ e.preventDefault(); active=Math.min(active+1, items.length-1); }
    else if(e.key==='ArrowUp'){ e.preventDefault(); active=Math.max(active-1, 0); }
    else if(e.key==='Enter'){
      if(active>=0 && items[active]){
        e.preventDefault();
        items[active].dispatchEvent(new Event('mousedown'));
      }
    }else if(e.key==='Escape'){ clear(); }
    items.forEach((el,idx)=> el.classList.toggle('active', idx===active));
  });
  document.addEventListener('click', (e)=>{
    if(!list.contains(e.target) && e.target!==input) clear();
  });
}

function updateMatrizTotals(){
  const table = document.getElementById('tablaMatriz');
  if (!table) return;
  const tbody = table.querySelector('tbody');
  const tfoot = table.querySelector('tfoot');
  if (!tbody || !tfoot) return;

  let sumComprado = 0;
  let sumAsignado = 0;
  let sumRestante = 0;

  tbody.querySelectorAll('tr').forEach(tr=>{
    // ignorar filas de detalle
    if (tr.classList.contains('fila-lineas-asig')) return;
    // ignorar filas ocultas por el filtro
    if (getComputedStyle(tr).display === 'none') return;

    const comprado = parseInt(tr.getAttribute('data-comprado') || '0', 10) || 0;
    const asignado = parseInt(tr.getAttribute('data-asignado') || '0', 10) || 0;
    const restante = parseInt(tr.getAttribute('data-restante') || '0', 10) || 0;

    sumComprado += comprado;
    sumAsignado += asignado;
    sumRestante += restante;
  });

  const qtyCells = tfoot.querySelectorAll('td.cell-qty');
  if (qtyCells[0]) qtyCells[0].textContent = nf.format(sumComprado);
  if (qtyCells[1]) qtyCells[1].textContent = nf.format(sumAsignado);
  if (qtyCells[2]) qtyCells[2].textContent = nf.format(sumRestante);
}

function applyTextFilter(){
  const term = (document.getElementById('ftexto')?.value || '').trim().toLowerCase();
  const table = document.getElementById('tablaMatriz');
  if (!table) return;
  table.querySelectorAll('tbody tr').forEach(tr=>{
    // no filtramos las filas de detalle de líneas
    if (tr.classList.contains('fila-lineas-asig')) {
      return;
    }
    const tds = tr.querySelectorAll('td,th');
    if (tds.length < 10){ tr.style.display=''; return; }
    // índice 0 = columna del "+"
    const tipo        = (tds[1].textContent||'').toLowerCase();
    const idProd      = (tds[2].textContent||'').toLowerCase();
    const prod        = (tds[3].textContent||'').toLowerCase();
    const prov        = (tds[4].textContent||'').toLowerCase();
    const match = !term ||
                  tipo.includes(term) ||
                  idProd.includes(term) ||
                  prod.includes(term) ||
                  prov.includes(term);

    if (!match) {
      tr.style.display = 'none';
      return;
    }

    // Si no queremos mostrar las cerradas antiguas, se ocultan aunque coincidan con el filtro
    if (!mostrarCerradasAntiguas && tr.classList.contains('row-cerrada-antigua')) {
      tr.style.display = 'none';
      return;
    }

    tr.style.display = '';
  });

  updateMatrizTotals();
}

// Cargar proveedores en el desplegable de compras según nombre de producto
async function loadProveedoresForProducto(productoNombre){
  const sel = document.getElementById('c_proveedor_id');
  if (!sel) return;

  sel.innerHTML = '<option value="">Cargando proveedores…</option>';

  if (!productoNombre){
    sel.innerHTML = '<option value="">Selecciona proveedor…</option>';
    return;
  }

  try{
    const url = new URL('api_proveedores_producto.php', location.href);
    url.searchParams.set('producto_nombre', productoNombre);
    const res = await fetch(url.toString(), {headers:{'Accept':'application/json'}});
    if (!res.ok) throw new Error('HTTP '+res.status);
    const data = await res.json();

    sel.innerHTML = '<option value="">Selecciona proveedor…</option>';
    (data || []).forEach(p=>{
      const opt = document.createElement('option');
      opt.value = p.id;
      opt.textContent = p.nombre;
      sel.appendChild(opt);
    });

  }catch(e){
    console.error('Error cargando proveedores para producto', e);
    sel.innerHTML = '<option value="">Error cargando proveedores</option>';
  }
}

// Cargar proveedores para conversión de pre-reserva según nombre de producto
async function loadProveedoresForConversion(productoNombre){
  if (!window.__cpProvidersCache) window.__cpProvidersCache = [];

  if (!productoNombre){
    window.__cpProvidersCache = [];
    return;
  }

  try{
    const url = new URL('api_proveedores_producto.php', location.href);
    url.searchParams.set('producto_nombre', productoNombre);
    const res = await fetch(url.toString(), {headers:{'Accept':'application/json'}});
    if (!res.ok) throw new Error('HTTP '+res.status);
    const data = await res.json();
    window.__cpProvidersCache = Array.isArray(data) ? data : [];
    refreshConvertSplitProviderOptions();
  }catch(e){
    console.error('Error cargando proveedores para conversión', e);
    window.__cpProvidersCache = [];
    refreshConvertSplitProviderOptions();
  }
}

function getConvertProviders(){
  return Array.isArray(window.__cpProvidersCache) ? window.__cpProvidersCache : [];
}

function refreshConvertSplitProviderOptions(){
  const providers = getConvertProviders();
  document.querySelectorAll('#cp_split_rows .cp-proveedor').forEach(sel=>{
    const selected = sel.value || '';
    sel.innerHTML = '<option value="">Selecciona proveedor…</option>';
    providers.forEach(p=>{
      const opt = document.createElement('option');
      opt.value = String(p.id);
      const disponible = parseInt(p.disponible || 0, 10) || 0;
      opt.textContent = `${p.nombre} (disp: ${nf.format(disponible)})`;
      sel.appendChild(opt);
    });
    if (selected) {
      sel.value = selected;
    }
  });
}

function addConvertSplitRow(defaultProveedorId = '', defaultCantidad = ''){
  const rowsEl = document.getElementById('cp_split_rows');
  if (!rowsEl) return;

  const row = document.createElement('div');
  row.className = 'cp-split-row row g-2 align-items-center';
  row.innerHTML = `
    <div class="col-7">
      <select class="form-select cp-proveedor">
        <option value="">Selecciona proveedor…</option>
      </select>
    </div>
    <div class="col-3">
      <input type="number" min="1" step="1" class="form-control cp-cantidad" placeholder="Cantidad">
    </div>
    <div class="col-2 text-end">
      <button type="button" class="btn btn-sm btn-outline-danger cp-remove-row">Quitar</button>
    </div>
  `;
  rowsEl.appendChild(row);
  refreshConvertSplitProviderOptions();
  row.querySelector('.cp-proveedor').value = defaultProveedorId ? String(defaultProveedorId) : '';
  row.querySelector('.cp-cantidad').value = defaultCantidad ? String(defaultCantidad) : '';
  updateConvertSplitInfo();
}

function updateConvertSplitInfo(){
  const infoEl = document.getElementById('cp_split_total_info');
  const objetivoLbl = document.getElementById('cp_cantidad_label');
  if (!infoEl || !objetivoLbl) return;

  const objetivo = parseInt((objetivoLbl.textContent || '0').replace(/[^\d]/g, ''), 10) || 0;
  let asignado = 0;
  document.querySelectorAll('#cp_split_rows .cp-cantidad').forEach(inp=>{
    asignado += parseInt(inp.value || '0', 10) || 0;
  });
  infoEl.textContent = `Asignado: ${nf.format(asignado)} / ${nf.format(objetivo)}`;
  infoEl.classList.toggle('text-danger', asignado !== objetivo);
}

// Insertar / quitar fila de detalle de líneas de asignación en la matriz
async function toggleLineasForRow(btn){
  const tr = btn.closest('tr');
  if (!tr) return;

  // Si ya hay una fila de detalle justo debajo, la eliminamos (toggle)
  const next = tr.nextElementSibling;
  if (next && next.classList.contains('fila-lineas-asig')) {
    next.remove();
    return;
  }

  const productoId  = parseInt(btn.getAttribute('data-producto-id')  || '0', 10) || 0;
  const proveedorId = parseInt(btn.getAttribute('data-proveedor-id') || '0', 10) || 0;
  const semanaCompra = btn.getAttribute('data-fecha-compra-raw') || '';

  const detalleTr = document.createElement('tr');
  detalleTr.className = 'fila-lineas-asig';
  const td = document.createElement('td');
  td.colSpan = 10;
  td.innerHTML = '<div class="text-muted small">Cargando líneas...</div>';
  detalleTr.appendChild(td);
  tr.after(detalleTr);

  if (!productoId || !proveedorId) {
    td.innerHTML = '<div class="text-danger small">IDs de producto/proveedor no válidos.</div>';
    return;
  }

  try{
    const url = new URL('api_asignaciones_linea.php', location.href);
    url.searchParams.set('producto_id',  String(productoId));
    url.searchParams.set('proveedor_id', String(proveedorId));
    if (semanaCompra) {
      url.searchParams.set('semana', semanaCompra);
      // compatibilidad con backend legacy
      url.searchParams.set('fecha_compra', semanaCompra);
    }
    const res = await fetch(url.toString(), {headers:{'Accept':'application/json'}});
    if (!res.ok) throw new Error('HTTP '+res.status);
    const data = await res.json();

    if (!data || !data.length){
      td.innerHTML = '<div class="text-muted small">No hay asignaciones para esta línea todavía.</div>';
      return;
    }

    let html = `
      <div class="small">
        <div class="fw-semibold mb-1">Líneas de asignación:</div>
        <div class="table-responsive">
          <table class="table table-sm table-bordered mb-0 align-middle">
            <thead class="table-light">
              <tr>
                <th style="width:70px;">ID</th>
                <th>Delegación</th>
                <th class="text-end" style="width:120px;">Cantidad</th>
                <th style="width:120px;">Semana salida</th>
                <th style="width:170px;">Creada</th>
              </tr>
            </thead>
            <tbody>
    `;

    const nfLocal = new Intl.NumberFormat('es-ES');
    data.forEach(a=>{
      const id   = a.id || '';
      const del  = a.delegacion || '';
      const cant = nfLocal.format(Number(a.cantidad_asignada||0));
      const fs   = a.semana_salida || '';
      const cr   = a.created_at || '';
      html += `
        <tr>
          <td>${id}</td>
          <td>${del}</td>
          <td class="text-end">${cant}</td>
          <td>${fs}</td>
          <td>${cr}</td>
        </tr>
      `;
    });

    html += `
            </tbody>
          </table>
        </div>
      </div>
    `;
    td.innerHTML = html;

  }catch(e){
    console.error('Error cargando líneas de asignación', e);
    td.innerHTML = '<div class="text-danger small">Error cargando las líneas de asignación.</div>';
  }
}

document.addEventListener('DOMContentLoaded', ()=>{
  const cSemanaInput = document.getElementById('c_semana');
  if (cSemanaInput && !cSemanaInput.value) {
    cSemanaInput.value = getCurrentIsoWeek();
  }
  const cProductoInput = document.getElementById('c_producto_nombre');
  if (cProductoInput) {
    updateCompraGhostForProducto(cProductoInput.value);
    cProductoInput.addEventListener('input', ()=>{
      updateCompraGhostForProducto(cProductoInput.value);
    });
  }

  const ACTIVE_TAB_KEY = 'gestion_compras_active_tab';
  const savedTabTarget = sessionStorage.getItem(ACTIVE_TAB_KEY);
  if (savedTabTarget) {
    const savedTabBtn = document.querySelector(`[data-bs-target="${savedTabTarget}"]`);
    if (savedTabBtn) {
      const tab = bootstrap.Tab.getOrCreateInstance(savedTabBtn);
      tab.show();
    }
  }
  document.querySelectorAll('#tabsCompras [data-bs-toggle="tab"]').forEach(tabBtn => {
    tabBtn.addEventListener('shown.bs.tab', (ev) => {
      const target = ev.target?.getAttribute('data-bs-target');
      if (target) {
        sessionStorage.setItem(ACTIVE_TAB_KEY, target);
      }
    });
  });

  // Autocomplete registrar compra
  createAutocomplete({
    input:  document.getElementById('c_producto_nombre'),
    hidden: document.getElementById('c_producto_id'),
    list:   document.getElementById('c_producto_list'),
    type:   'producto'
  });

  // Autocomplete delegación en modal de asignación
  createAutocomplete({
    input:  document.getElementById('a_delegacion_nombre'),
    hidden: document.getElementById('a_delegacion_id'),
    list:   document.getElementById('a_delegacion_list'),
    type:   'delegacion'
  });

  // Autocomplete proveedor en alta de producto
  createAutocomplete({
    input:  document.getElementById('p_proveedor_nombre'),
    hidden: document.getElementById('p_proveedor_id'),
    list:   document.getElementById('p_proveedor_list'),
    type:   'proveedor'
  });

  // Autocomplete delegación y producto en modal de pre-reserva
  createAutocomplete({
    input:  document.getElementById('pr_delegacion_nombre'),
    hidden: document.getElementById('pr_delegacion_id'),
    list:   document.getElementById('pr_delegacion_list'),
    type:   'delegacion'
  });
  createAutocomplete({
    input:  document.getElementById('pr_producto_nombre'),
    hidden: document.getElementById('pr_producto_id'),
    list:   document.getElementById('pr_producto_list'),
    type:   'producto'
  });

  // Filtro de texto para matriz
  const ftexto = document.getElementById('ftexto');
  if (ftexto){
    ftexto.addEventListener('input', debounce(applyTextFilter, 120));
  }
  applyTextFilter();

  // Mostrar / ocultar líneas cerradas antiguas
  const btnToggleCerradas = document.getElementById('btnToggleCerradas');
  if (btnToggleCerradas){
    btnToggleCerradas.addEventListener('click', ()=>{
      mostrarCerradasAntiguas = !mostrarCerradasAntiguas;
      btnToggleCerradas.classList.toggle('btn-outline-secondary', !mostrarCerradasAntiguas);
      btnToggleCerradas.classList.toggle('btn-primary', mostrarCerradasAntiguas);
      btnToggleCerradas.textContent = mostrarCerradasAntiguas
        ? 'Ocultar líneas cerradas antiguas'
        : 'Mostrar líneas cerradas antiguas';
      applyTextFilter();
    });
  }

  // Refrescar (por ahora recarga página)
  document.getElementById('btnRefrescar')?.addEventListener('click', ()=>location.reload());

  // Registrar compra (Fetch -> save_compra.php)
  const cForm  = document.getElementById('formCompra');
  const cErrEl = document.getElementById('c_error');
  if (cForm){
    cForm.addEventListener('submit', async (e)=>{
      e.preventDefault();
      cErrEl.style.display='none'; cErrEl.textContent='';
      const producto_nombre = (document.getElementById('c_producto_nombre').value||'').trim();
      const proveedor_id = parseInt(document.getElementById('c_proveedor_id').value||'0',10);
      const cantidad     = parseInt(document.getElementById('c_cantidad').value||'0',10);
      const precio       = parseFloat(document.getElementById('c_precio').value||'0') || 0;
      const semana       = (document.getElementById('c_semana').value||'').trim();

      const errs=[];
      if(!producto_nombre)  errs.push('Selecciona un producto válido.');
      if(!proveedor_id) errs.push('Selecciona un proveedor válido.');
      if(!cantidad || cantidad<=0) errs.push('Cantidad debe ser > 0.');
      if(precio < 0) errs.push('El precio no puede ser negativo.');
      if(!semana) errs.push('Selecciona una semana de compra.');

      if(errs.length){
        cErrEl.textContent = errs.join(' | ');
        cErrEl.style.display='block';
        return;
      }
      try {
        const res = await fetch('save_compra.php', {
          method:'POST',
          headers:{'Content-Type':'application/json'},
          body: JSON.stringify({ producto_nombre, proveedor_id, cantidad, precio, semana })
        });
        let data=null, txt='';
        try { data = await res.json(); } catch(_){ txt = await res.text(); }

        if(res.ok && data && data.ok){
          cForm.reset();
          location.reload();
        } else {
          const msg = (data && (data.errors||data.error))
            ? (Array.isArray(data.errors)?data.errors.join(' | '):data.error)
            : (txt || 'No se pudo guardar la compra.');
          cErrEl.textContent = msg;
          cErrEl.style.display='block';
        }
      } catch(e) {
        console.error(e);
        cErrEl.textContent = 'Error de red.';
        cErrEl.style.display='block';
      }
    });
  }

  // Modal asignación
  const modalAsignacion = new bootstrap.Modal(document.getElementById('modalAsignacion'));
  const aProdNom  = document.getElementById('a_producto_nombre');
  const aProdId   = document.getElementById('a_producto_id');
  const aProvNom  = document.getElementById('a_proveedor_nombre');
  const aProvId   = document.getElementById('a_proveedor_id');
  const aRestLbl  = document.getElementById('a_restante_label');
  const aCant     = document.getElementById('a_cantidad');
  const aSemana   = document.getElementById('a_semana');
  const aErrEl    = document.getElementById('a_error');
  const modalPreReserva = new bootstrap.Modal(document.getElementById('modalPreReserva'));
  const formPreReserva  = document.getElementById('formPreReserva');
  const prErrEl         = document.getElementById('pr_error');
  const prDelegacionIdEl= document.getElementById('pr_delegacion_id');
  const prProductoIdEl  = document.getElementById('pr_producto_id');
  const prProductoNombreEl = document.getElementById('pr_producto_nombre');
  const prCantidadEl    = document.getElementById('pr_cantidad');
  const prSemanaEl      = document.getElementById('pr_semana_deseada');
  const modalConvertirPre = new bootstrap.Modal(document.getElementById('modalConvertirPreReserva'));
  const formConvertirPre  = document.getElementById('formConvertirPreReserva');
  const cpErrEl           = document.getElementById('cp_error');
  const cpPreIdEl         = document.getElementById('cp_pre_reserva_id');
  const cpDelegacionLbl   = document.getElementById('cp_delegacion_label');
  const cpTipoLbl         = document.getElementById('cp_tipo_label');
  const cpVariedadLbl     = document.getElementById('cp_variedad_label');
  const cpCantidadLbl     = document.getElementById('cp_cantidad_label');
  const cpSplitRowsEl     = document.getElementById('cp_split_rows');
  const cpAddSplitRowBtn  = document.getElementById('cp_add_split_row');

  // Apertura explícita del modal de pre-reserva (fallback robusto)
  document.querySelectorAll('[data-bs-target="#modalPreReserva"]').forEach(btn=>{
    btn.addEventListener('click', (e)=>{
      e.preventDefault();
      prErrEl.style.display = 'none';
      prErrEl.textContent = '';
      formPreReserva?.reset();
      document.getElementById('pr_delegacion_id').value = '';
      document.getElementById('pr_producto_id').value = '';
      if (prSemanaEl && !prSemanaEl.value) {
        const now = new Date();
        const weekStart = new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate()));
        const day = weekStart.getUTCDay() || 7;
        weekStart.setUTCDate(weekStart.getUTCDate() + 4 - day);
        const yearStart = new Date(Date.UTC(weekStart.getUTCFullYear(), 0, 1));
        const weekNo = Math.ceil((((weekStart - yearStart) / 86400000) + 1) / 7);
        prSemanaEl.value = `${weekStart.getUTCFullYear()}-W${String(weekNo).padStart(2, '0')}`;
      }
      modalPreReserva.show();
    });
  });

  cpAddSplitRowBtn?.addEventListener('click', ()=>{
    addConvertSplitRow();
  });
  cpSplitRowsEl?.addEventListener('input', (ev)=>{
    if (ev.target && ev.target.classList.contains('cp-cantidad')) {
      updateConvertSplitInfo();
    }
  });
  cpSplitRowsEl?.addEventListener('click', (ev)=>{
    const btn = ev.target?.closest('.cp-remove-row');
    if (!btn) return;
    const rows = cpSplitRowsEl.querySelectorAll('.cp-split-row');
    if (rows.length <= 1) {
      return;
    }
    btn.closest('.cp-split-row')?.remove();
    updateConvertSplitInfo();
  });

  document.querySelectorAll('.btn-asignar').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const prodNombre  = btn.getAttribute('data-producto-nombre') || '';
      const prodId      = btn.getAttribute('data-producto-id') || '';
      const provNombre  = btn.getAttribute('data-proveedor-nombre') || '';
      const provId      = btn.getAttribute('data-proveedor-id') || '';
      const restante    = parseInt(btn.getAttribute('data-restante')||'0',10) || 0;

      aProdNom.value = prodNombre;
      aProdId.value  = prodId;
      aProvNom.value = provNombre;
      aProvId.value  = provId;
      aRestLbl.textContent = nf.format(restante);
      aCant.value = '';
      aCant.max   = String(Math.max(0, restante));
      aCant.dataset.restante = String(Math.max(0, restante));
      aCant.placeholder = restante>0 ? `Máximo ${restante}` : 'Sin stock';
      const now = new Date();
      const weekStart = new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate()));
      const day = weekStart.getUTCDay() || 7;
      weekStart.setUTCDate(weekStart.getUTCDate() + 4 - day);
      const yearStart = new Date(Date.UTC(weekStart.getUTCFullYear(), 0, 1));
      const weekNo = Math.ceil((((weekStart - yearStart) / 86400000) + 1) / 7);
      aSemana.value = `${weekStart.getUTCFullYear()}-W${String(weekNo).padStart(2, '0')}`;

      aErrEl.style.display='none'; aErrEl.textContent='';
      document.getElementById('a_delegacion_nombre').value='';
      document.getElementById('a_delegacion_id').value='';

      modalAsignacion.show();
    });
  });

  // Botones "Líneas" en matriz de stock
  document.querySelectorAll('.btn-lineas').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      toggleLineasForRow(btn);
    });
  });

  // Guardar asignación (Fetch -> save_asignacion.php)
  const aForm = document.getElementById('formAsignacion');
  aForm.addEventListener('submit', async (e)=>{
    e.preventDefault();
    aErrEl.style.display='none'; aErrEl.textContent='';

    const producto_id   = parseInt(aProdId.value||'0',10);
    const proveedor_id  = parseInt(aProvId.value||'0',10);
    const delegacion_id = parseInt(document.getElementById('a_delegacion_id').value||'0',10);
    const cantidad      = parseInt(aCant.value||'0',10);
    const semana_salida = (aSemana.value||'').trim();

    const errs = [];
    if(!producto_id)   errs.push('Producto inválido.');
    if(!proveedor_id)  errs.push('Proveedor inválido.');
    if(!delegacion_id) errs.push('Selecciona una delegación.');
    if(!cantidad || cantidad<=0) errs.push('Cantidad debe ser > 0.');
    if(!semana_salida) errs.push('Selecciona una semana de salida.');

    const restanteSel = parseInt(aCant.dataset.restante || '0', 10) || 0;
    if (cantidad > restanteSel) {
      errs.push(`No puedes asignar más de ${restanteSel} unidades.`);
    }

    if(errs.length){
      aErrEl.textContent = errs.join(' | ');
      aErrEl.style.display='block';
      return;
    }

    try {
      const res = await fetch('save_asignacion.php', {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ producto_id, proveedor_id, delegacion_id, cantidad, semana_salida })
      });
      let data=null, txt='';
      try { data = await res.json(); } catch(_){ txt = await res.text(); }

      if(res.ok && data && data.ok){
        modalAsignacion.hide();
        location.reload();
      } else {
        const msg = (data && (data.errors||data.error))
          ? (Array.isArray(data.errors)?data.errors.join(' | '):data.error)
          : (txt || 'No se pudo guardar la asignación.');
        aErrEl.textContent = msg;
        aErrEl.style.display='block';
      }
    } catch(e) {
      console.error(e);
      aErrEl.textContent = 'Error de red.';
      aErrEl.style.display='block';
    }
  });

  // Guardar pre-reserva (Fetch -> save_prereserva.php)
  if (formPreReserva){
    formPreReserva.addEventListener('submit', async (e)=>{
      e.preventDefault();
      prErrEl.style.display = 'none';
      prErrEl.textContent = '';

      const delegacion_id = parseInt(prDelegacionIdEl.value || '0', 10);
      const producto_id = parseInt(prProductoIdEl.value || '0', 10);
      const producto_deseado = (prProductoNombreEl.value || '').trim();
      const cantidad = parseInt(prCantidadEl.value || '0', 10);
      const semana_deseada = (prSemanaEl.value || '').trim();

      const errs = [];
      if (!delegacion_id) errs.push('Selecciona una delegación válida.');
      if (!producto_deseado) errs.push('Indica un producto.');
      if (!cantidad || cantidad <= 0) errs.push('Cantidad debe ser > 0.');
      if (!semana_deseada) errs.push('Semana deseada requerida.');

      if (errs.length) {
        prErrEl.textContent = errs.join(' | ');
        prErrEl.style.display = 'block';
        return;
      }

      try {
        const res = await fetch('save_prereserva.php', {
          method:'POST',
          headers:{'Content-Type':'application/json'},
          body: JSON.stringify({ delegacion_id, producto_id, producto_deseado, cantidad, semana_deseada })
        });
        let data = null, txt = '';
        try { data = await res.json(); } catch(_) { txt = await res.text(); }

        if (res.ok && data && data.ok) {
          modalPreReserva.hide();
          sessionStorage.setItem(ACTIVE_TAB_KEY, '#tab-prereservas');
          location.reload();
        } else {
          const msg = (data && (data.errors || data.error))
            ? (Array.isArray(data.errors) ? data.errors.join(' | ') : data.error)
            : (txt || 'No se pudo guardar la pre-reserva.');
          prErrEl.textContent = msg;
          prErrEl.style.display = 'block';
        }
      } catch (err) {
        console.error(err);
        prErrEl.textContent = 'Error de red.';
        prErrEl.style.display = 'block';
      }
    });
  }

  // Abrir modal de conversión desde tabla de pre-reservas
  document.querySelectorAll('.btn-convertir-pr').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      cpErrEl.style.display = 'none';
      cpErrEl.textContent = '';
      cpPreIdEl.value = btn.getAttribute('data-prereserva-id') || '';
      cpDelegacionLbl.textContent = btn.getAttribute('data-prereserva-delegacion') || '-';
      cpTipoLbl.textContent = btn.getAttribute('data-prereserva-tipo') || '-';
      cpVariedadLbl.textContent = btn.getAttribute('data-prereserva-variedad') || '-';
      cpCantidadLbl.textContent = btn.getAttribute('data-prereserva-cantidad') || '0';
      if (cpSplitRowsEl) {
        cpSplitRowsEl.innerHTML = '';
      }
      addConvertSplitRow('', cpCantidadLbl.textContent || '');
      const variedad = (btn.getAttribute('data-prereserva-variedad') || '').trim();
      if (variedad) {
        loadProveedoresForConversion(variedad);
      } else {
        refreshConvertSplitProviderOptions();
      }
      updateConvertSplitInfo();
      modalConvertirPre.show();
    });
  });

  // Eliminar pre-reserva (cancelación lógica)
  document.querySelectorAll('.btn-eliminar-pr').forEach(btn=>{
    btn.addEventListener('click', async ()=>{
      const pre_reserva_id = parseInt(btn.getAttribute('data-prereserva-id') || '0', 10);
      if (!pre_reserva_id) return;

      const ok = window.confirm(`¿Eliminar la pre-reserva #${pre_reserva_id}?`);
      if (!ok) return;

      try {
        const res = await fetch('delete_prereserva.php', {
          method:'POST',
          headers:{'Content-Type':'application/json'},
          body: JSON.stringify({ pre_reserva_id })
        });
        let data = null, txt = '';
        try { data = await res.json(); } catch(_) { txt = await res.text(); }

        if (res.ok && data && data.ok) {
          location.reload();
        } else {
          const msg = (data && (data.errors || data.error))
            ? (Array.isArray(data.errors) ? data.errors.join(' | ') : data.error)
            : (txt || 'No se pudo eliminar la pre-reserva.');
          alert(msg);
        }
      } catch (err) {
        console.error(err);
        alert('Error de red al eliminar la pre-reserva.');
      }
    });
  });

  // Convertir pre-reserva (Fetch -> convertir_prereserva.php)
  if (formConvertirPre){
    formConvertirPre.addEventListener('submit', async (e)=>{
      e.preventDefault();
      cpErrEl.style.display = 'none';
      cpErrEl.textContent = '';

      const pre_reserva_id = parseInt(cpPreIdEl.value || '0', 10);
      const cantidadObjetivo = parseInt((cpCantidadLbl.textContent || '0').replace(/[^\d]/g, ''), 10) || 0;
      const resumen = new Map();
      (cpSplitRowsEl?.querySelectorAll('.cp-split-row') || []).forEach(row=>{
        const proveedor_id = parseInt(row.querySelector('.cp-proveedor')?.value || '0', 10);
        const cantidad = parseInt(row.querySelector('.cp-cantidad')?.value || '0', 10);
        if (!proveedor_id && !cantidad) return;
        if (!proveedor_id || cantidad <= 0) return;
        resumen.set(proveedor_id, (resumen.get(proveedor_id) || 0) + cantidad);
      });
      const asignaciones = Array.from(resumen.entries()).map(([proveedor_id, cantidad]) => ({ proveedor_id, cantidad }));
      const totalAsignado = asignaciones.reduce((acc, x) => acc + (x.cantidad || 0), 0);
      const errs = [];
      if (!pre_reserva_id) errs.push('Pre-reserva invalida.');
      if (!asignaciones.length) errs.push('Añade al menos un proveedor con cantidad.');
      if (totalAsignado !== cantidadObjetivo) {
        errs.push(`El reparto debe sumar exactamente ${cantidadObjetivo}.`);
      }

      if (errs.length) {
        cpErrEl.textContent = errs.join(' | ');
        cpErrEl.style.display = 'block';
        return;
      }

      try {
        const res = await fetch('convertir_prereserva.php', {
          method:'POST',
          headers:{'Content-Type':'application/json'},
          body: JSON.stringify({ pre_reserva_id, asignaciones })
        });
        let data = null, txt = '';
        try { data = await res.json(); } catch(_) { txt = await res.text(); }

        if (res.ok && data && data.ok) {
          modalConvertirPre.hide();
          location.reload();
        } else {
          const msg = (data && (data.errors || data.error))
            ? (Array.isArray(data.errors) ? data.errors.join(' | ') : data.error)
            : (txt || 'No se pudo convertir la pre-reserva.');
          cpErrEl.textContent = msg;
          cpErrEl.style.display = 'block';
        }
      } catch (err) {
        console.error(err);
        cpErrEl.textContent = 'Error de red.';
        cpErrEl.style.display = 'block';
      }
    });
  }

  // ====== Pestaña Productos: filtro y alta ======
  const prFiltro = document.getElementById('pr_filtro');
  const tablaPreReservas = document.getElementById('tablaPreReservas');
  const prTotalCantidadEl = document.getElementById('pr_total_cantidad');

  function updatePreReservasTotals(){
    if (!tablaPreReservas || !prTotalCantidadEl) return;
    let totalCantidad = 0;

    tablaPreReservas.querySelectorAll('tbody tr').forEach(tr=>{
      const tds = tr.querySelectorAll('td,th');
      if (tds.length < 8) return;
      if (getComputedStyle(tr).display === 'none') return;
      totalCantidad += parseInt(tr.getAttribute('data-cantidad') || '0', 10) || 0;
    });

    prTotalCantidadEl.textContent = nf.format(totalCantidad);
  }

  function applyPreReservasFilter(){
    if (!tablaPreReservas) return;
    const term = (prFiltro?.value || '').trim().toLowerCase();

    tablaPreReservas.querySelectorAll('tbody tr').forEach(tr=>{
      const tds = tr.querySelectorAll('td,th');
      // Fila de estado vacía: "No hay pre-reservas pendientes."
      if (tds.length < 8) {
        tr.style.display = term ? 'none' : '';
        return;
      }

      const id = (tds[0].textContent || '').toLowerCase();
      const delegacion = (tds[1].textContent || '').toLowerCase();
      const tipo = (tds[2].textContent || '').toLowerCase();
      const producto = (tds[3].textContent || '').toLowerCase();
      const cantidad = (tds[4].textContent || '').toLowerCase();
      const semana = (tds[5].textContent || '').toLowerCase();

      const match = !term
        || id.includes(term)
        || delegacion.includes(term)
        || tipo.includes(term)
        || producto.includes(term)
        || cantidad.includes(term)
        || semana.includes(term);

      tr.style.display = match ? '' : 'none';
    });
    updatePreReservasTotals();
  }

  if (prFiltro){
    prFiltro.addEventListener('input', debounce(applyPreReservasFilter, 120));
  }
  applyPreReservasFilter();

  const pFiltro = document.getElementById('p_filtro');
  const tablaProductos = document.getElementById('tablaProductos');
  const pErrEl = document.getElementById('p_error');

  function applyProductosFilter(){
    if (!tablaProductos) return;
    const term = (pFiltro?.value || '').trim().toLowerCase();
    tablaProductos.querySelectorAll('tbody tr').forEach(tr=>{
      const tds = tr.querySelectorAll('td,th');
      if (tds.length < 6){ tr.style.display=''; return; }
      const id    = (tds[0].textContent||'').toLowerCase();
      const codigo= (tds[1].textContent||'').toLowerCase();
      const tipo  = (tds[2].textContent||'').toLowerCase();
      const nombre= (tds[3].textContent||'').toLowerCase();
      const prov  = (tds[4].textContent||'').toLowerCase();
      const precio= (tds[5].textContent||'').toLowerCase();
      const match = !term
        || id.includes(term)
        || codigo.includes(term)
        || tipo.includes(term)
        || nombre.includes(term)
        || prov.includes(term)
        || precio.includes(term);
      tr.style.display = match ? '' : 'none';
    });
  }

  if (pFiltro){
    pFiltro.addEventListener('input', debounce(applyProductosFilter, 120));
  }
  applyProductosFilter();

  const pForm = document.getElementById('formProducto');
  if (pForm){
    pForm.addEventListener('submit', async (e)=>{
      e.preventDefault();
      if (pErrEl){ pErrEl.style.display='none'; pErrEl.textContent=''; }

      const tipo_id   = parseInt(document.getElementById('p_tipo_id')?.value || '0', 10) || 0;
      const nombre    = (document.getElementById('p_nombre')?.value || '').trim();
      const codigo_velneo = (document.getElementById('p_codigo_velneo')?.value || '').trim();
      const proveedor_id = parseInt(document.getElementById('p_proveedor_id')?.value || '0', 10) || 0;
      const precioRaw = (document.getElementById('p_precio')?.value || '').trim();
      const precio = precioRaw === '' ? null : parseFloat(precioRaw.replace(',', '.'));

      const errs = [];
      if(!tipo_id) errs.push('Selecciona un tipo de producto.');
      if(!nombre) errs.push('El nombre es obligatorio.');
      if(!proveedor_id) errs.push('Selecciona un proveedor.');
      if(precio !== null && (isNaN(precio) || precio < 0)) errs.push('El precio debe ser un número mayor o igual que 0.');

      if(errs.length){
        if (pErrEl){
          pErrEl.textContent = errs.join(' | ');
          pErrEl.style.display='block';
        }
        return;
      }

      try{
        const res = await fetch('save_producto.php', {
          method:'POST',
          headers:{'Content-Type':'application/json'},
          body: JSON.stringify({ tipo_id, nombre, codigo_velneo, proveedor_id, precio })
        });
        let data=null, txt='';
        try { data = await res.json(); } catch(_){ txt = await res.text(); }

        if(res.ok && data && data.ok){
          location.reload();
        }else{
          const msg = (data && (data.errors||data.error))
            ? (Array.isArray(data.errors)? data.errors.join(' | ') : data.error)
            : (txt || 'No se pudo guardar el producto.');
          if (pErrEl){
            pErrEl.textContent = msg;
            pErrEl.style.display='block';
          }
        }
      }catch(e){
        console.error(e);
        if (pErrEl){
          pErrEl.textContent = 'Error de red.';
          pErrEl.style.display='block';
        }
      }
    });
  }

  // ====== Pestaña Proveedores: filtro y alta ======
  const vFiltro = document.getElementById('v_filtro');
  const tablaProveedores = document.getElementById('tablaProveedores');
  const vErrEl = document.getElementById('v_error');

  function applyProveedoresFilter(){
    if (!tablaProveedores) return;
    const term = (vFiltro?.value || '').trim().toLowerCase();
    tablaProveedores.querySelectorAll('tbody tr').forEach(tr=>{
      const tds = tr.querySelectorAll('td,th');
      if (tds.length < 2){ tr.style.display=''; return; }
      const id    = (tds[0].textContent||'').toLowerCase();
      const nombre= (tds[1].textContent||'').toLowerCase();
      const match = !term || id.includes(term) || nombre.includes(term);
      tr.style.display = match ? '' : 'none';
    });
  }

  if (vFiltro){
    vFiltro.addEventListener('input', debounce(applyProveedoresFilter, 120));
  }
  applyProveedoresFilter();

  const vForm = document.getElementById('formProveedor');
  if (vForm){
    vForm.addEventListener('submit', async (e)=>{
      e.preventDefault();
      if (vErrEl){ vErrEl.style.display='none'; vErrEl.textContent=''; }

      const nombre = (document.getElementById('v_nombre')?.value || '').trim();
      const errs = [];
      if(!nombre) errs.push('El nombre es obligatorio.');

      if(errs.length){
        if (vErrEl){
          vErrEl.textContent = errs.join(' | ');
          vErrEl.style.display='block';
        }
        return;
      }

      try{
        const res = await fetch('save_proveedor.php', {
          method:'POST',
          headers:{'Content-Type':'application/json'},
          body: JSON.stringify({ nombre })
        });
        let data=null, txt='';
        try { data = await res.json(); } catch(_){ txt = await res.text(); }

        if(res.ok && data && data.ok){
          location.reload();
        }else{
          const msg = (data && (data.errors||data.error))
            ? (Array.isArray(data.errors)? data.errors.join(' | ') : data.error)
            : (txt || 'No se pudo guardar el proveedor.');
          if (vErrEl){
            vErrEl.textContent = msg;
            vErrEl.style.display='block';
          }
        }
      }catch(e){
        console.error(e);
        if (vErrEl){
          vErrEl.textContent = 'Error de red.';
          vErrEl.style.display='block';
        }
      }
    });
  }

  // ====== Pestaña Delegaciones: filtro y alta ======
  const dFiltro = document.getElementById('d_filtro');
  const tablaDelegaciones = document.getElementById('tablaDelegaciones');
  const dErrEl = document.getElementById('d_error');

  function applyDelegacionesFilter(){
    if (!tablaDelegaciones) return;
    const term = (dFiltro?.value || '').trim().toLowerCase();
    tablaDelegaciones.querySelectorAll('tbody tr').forEach(tr=>{
      const tds = tr.querySelectorAll('td,th');
      if (tds.length < 2){ tr.style.display=''; return; }
      const id    = (tds[0].textContent||'').toLowerCase();
      const nombre= (tds[1].textContent||'').toLowerCase();
      const match = !term || id.includes(term) || nombre.includes(term);
      tr.style.display = match ? '' : 'none';
    });
  }

  if (dFiltro){
    dFiltro.addEventListener('input', debounce(applyDelegacionesFilter, 120));
  }
  applyDelegacionesFilter();

  const dForm = document.getElementById('formDelegacion');
  if (dForm){
    dForm.addEventListener('submit', async (e)=>{
      e.preventDefault();
      if (dErrEl){ dErrEl.style.display='none'; dErrEl.textContent=''; }

      const nombre = (document.getElementById('d_nombre')?.value || '').trim();
      const errs = [];
      if(!nombre) errs.push('El nombre es obligatorio.');

      if(errs.length){
        if (dErrEl){
          dErrEl.textContent = errs.join(' | ');
          dErrEl.style.display='block';
        }
        return;
      }

      try{
        const res = await fetch('save_delegacion.php', {
          method:'POST',
          headers:{'Content-Type':'application/json'},
          body: JSON.stringify({ nombre })
        });
        let data=null, txt='';
        try { data = await res.json(); } catch(_){ txt = await res.text(); }

        if(res.ok && data && data.ok){
          location.reload();
        }else{
          const msg = (data && (data.errors||data.error))
            ? (Array.isArray(data.errors)? data.errors.join(' | ') : data.error)
            : (txt || 'No se pudo guardar la delegación.');
          if (dErrEl){
            dErrEl.textContent = msg;
            dErrEl.style.display='block';
          }
        }
      }catch(e){
        console.error(e);
        if (dErrEl){
          dErrEl.textContent = 'Error de red.';
          dErrEl.style.display='block';
        }
      }
    });
  }
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>



