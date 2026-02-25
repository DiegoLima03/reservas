<?php
// detalle_movimientos.php — Historial de compras (entradas) y asignaciones (salidas)
require_once __DIR__ . '/db.php';

@ini_set('display_errors','1');
@error_reporting(E_ALL);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function es_d($date){
  if (!$date) return '-';
  $t = strtotime($date);
  if ($t === false) return h($date);
  return date('d/m/Y', $t);
}
function es_dt($ts){
  if (!$ts) return '-';
  $t = strtotime($ts);
  if ($t===false) return h($ts);
  return date('d/m/Y H:i', $t);
}

try {
  $pdo = pdo();

  // --- Filtros GET compartidos ---
  $q      = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
  $f_ini  = isset($_GET['f_ini']) ? trim((string)$_GET['f_ini']) : '';
  $f_fin  = isset($_GET['f_fin']) ? trim((string)$_GET['f_fin']) : '';

  // ==========================
  //  COMPRAS (ENTRADAS)
  // ==========================
  $paramsC = [];
  $whereC  = ['1=1'];

  if ($q !== '') {
    $whereC[] = '(p.nombre LIKE :cq OR pr.nombre LIKE :cq2)';
    $paramsC[':cq']  = '%'.$q.'%';
    $paramsC[':cq2'] = '%'.$q.'%';
  }
  if ($f_ini !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_ini)) {
    $whereC[] = 'c.fecha_compra >= :c_fini';
    $paramsC[':c_fini'] = $f_ini;
  }
  if ($f_fin !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_fin)) {
    $whereC[] = 'c.fecha_compra <= :c_ffin';
    $paramsC[':c_ffin'] = $f_fin;
  }

  // Export CSV compras
  if (isset($_GET['export']) && $_GET['export']==='compras') {
    $sqlC = "
      SELECT c.id,
             p.nombre  AS producto,
             pr.nombre AS proveedor,
             c.cantidad_comprada,
             c.cantidad_disponible,
             c.fecha_compra,
             c.created_at
      FROM compras_stock c
      INNER JOIN productos   p  ON p.id  = c.producto_id
      INNER JOIN proveedores pr ON pr.id = c.proveedor_id
      WHERE ".implode(' AND ', $whereC)."
      ORDER BY c.fecha_compra DESC, c.id DESC
    ";
    $stC = $pdo->prepare($sqlC);
    foreach ($paramsC as $k=>$v) $stC->bindValue($k,$v);
    $stC->execute();
    $comprasCsv = $stC->fetchAll(PDO::FETCH_ASSOC);

    $filename = 'compras_'.date('Ymd_His').'.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output','w');

    $filters = [];
    if ($q!=='')     $filters[] = 'Buscar: '.$q;
    if ($f_ini!=='') $filters[] = 'F. inicio: '.$f_ini;
    if ($f_fin!=='') $filters[] = 'F. fin: '.$f_fin;
    $filters[] = 'Generado: '.date('Y-m-d H:i:s');
    fputcsv($out, ['Filtros', implode(' | ', $filters)], ';');
    fputcsv($out, [''], ';');

    fputcsv($out, ['ID','Producto','Proveedor','Cant. comprada','Cant. disponible','Fecha compra','Creada'], ';');
    foreach ($comprasCsv as $r) {
      fputcsv($out, [
        (int)$r['id'],
        (string)$r['producto'],
        (string)$r['proveedor'],
        (int)$r['cantidad_comprada'],
        (int)$r['cantidad_disponible'],
        es_d($r['fecha_compra']),
        es_dt($r['created_at']),
      ], ';');
    }
    fclose($out);
    exit;
  }

  // Consulta normal compras
  $sqlC = "
    SELECT c.id,
           p.nombre  AS producto,
           pr.nombre AS proveedor,
           c.cantidad_comprada,
           c.cantidad_disponible,
           c.fecha_compra,
           c.created_at
    FROM compras_stock c
    INNER JOIN productos   p  ON p.id  = c.producto_id
    INNER JOIN proveedores pr ON pr.id = c.proveedor_id
    WHERE ".implode(' AND ', $whereC)."
    ORDER BY c.fecha_compra DESC, c.id DESC
  ";
  $stC = $pdo->prepare($sqlC);
  foreach ($paramsC as $k=>$v) $stC->bindValue($k,$v);
  $stC->execute();
  $compras = $stC->fetchAll(PDO::FETCH_ASSOC);

  // Totales compras
  $totalComprada   = 0;
  $totalDisponible = 0;
  foreach ($compras as $c) {
    $totalComprada   += (int)$c['cantidad_comprada'];
    $totalDisponible += (int)$c['cantidad_disponible'];
  }

  // ==========================
  //  ASIGNACIONES (SALIDAS)
  // ==========================
  $paramsS = [];
  $whereS  = ['1=1'];

  if ($q !== '') {
    $whereS[] = '(p.nombre LIKE :sq OR pr.nombre LIKE :sq2 OR d.nombre LIKE :sq3)';
    $paramsS[':sq']  = '%'.$q.'%';
    $paramsS[':sq2'] = '%'.$q.'%';
    $paramsS[':sq3'] = '%'.$q.'%';
  }
  if ($f_ini !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_ini)) {
    $whereS[] = 'a.fecha_salida >= :s_fini';
    $paramsS[':s_fini'] = $f_ini;
  }
  if ($f_fin !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_fin)) {
    $whereS[] = 'a.fecha_salida <= :s_ffin';
    $paramsS[':s_ffin'] = $f_fin;
  }

  // Export CSV salidas
  if (isset($_GET['export']) && $_GET['export']==='salidas') {
    $sqlS = "
      SELECT a.id,
             p.nombre  AS producto,
             pr.nombre AS proveedor,
             d.nombre  AS delegacion,
             a.cantidad_asignada,
             a.fecha_salida,
             a.created_at
      FROM asignaciones a
      INNER JOIN productos    p  ON p.id  = a.producto_id
      INNER JOIN proveedores  pr ON pr.id = a.proveedor_id
      INNER JOIN delegaciones d  ON d.id  = a.delegacion_id
      WHERE ".implode(' AND ', $whereS)."
      ORDER BY a.fecha_salida DESC, a.id DESC
    ";
    $stS = $pdo->prepare($sqlS);
    foreach ($paramsS as $k=>$v) $stS->bindValue($k,$v);
    $stS->execute();
    $salidasCsv = $stS->fetchAll(PDO::FETCH_ASSOC);

    $filename = 'salidas_'.date('Ymd_His').'.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output','w');

    $filters = [];
    if ($q!=='')     $filters[] = 'Buscar: '.$q;
    if ($f_ini!=='') $filters[] = 'F. inicio: '.$f_ini;
    if ($f_fin!=='') $filters[] = 'F. fin: '.$f_fin;
    $filters[] = 'Generado: '.date('Y-m-d H:i:s');
    fputcsv($out, ['Filtros', implode(' | ', $filters)], ';');
    fputcsv($out, [''], ';');

    fputcsv($out, ['ID','Producto','Proveedor','Delegación','Cant. asignada','Fecha salida','Creada'], ';');
    foreach ($salidasCsv as $r) {
      fputcsv($out, [
        (int)$r['id'],
        (string)$r['producto'],
        (string)$r['proveedor'],
        (string)$r['delegacion'],
        (int)$r['cantidad_asignada'],
        es_d($r['fecha_salida']),
        es_dt($r['created_at']),
      ], ';');
    }
    fclose($out);
    exit;
  }

  // Consulta normal salidas
  $sqlS = "
    SELECT a.id,
           p.nombre  AS producto,
           pr.nombre AS proveedor,
           d.nombre  AS delegacion,
           a.cantidad_asignada,
           a.fecha_salida,
           a.created_at
    FROM asignaciones a
    INNER JOIN productos    p  ON p.id  = a.producto_id
    INNER JOIN proveedores  pr ON pr.id = a.proveedor_id
    INNER JOIN delegaciones d  ON d.id  = a.delegacion_id
    WHERE ".implode(' AND ', $whereS)."
    ORDER BY a.fecha_salida DESC, a.id DESC
  ";
  $stS = $pdo->prepare($sqlS);
  foreach ($paramsS as $k=>$v) $stS->bindValue($k,$v);
  $stS->execute();
  $salidas = $stS->fetchAll(PDO::FETCH_ASSOC);

  // Totales salidas
  $totalAsignada = 0;
  foreach ($salidas as $s) {
    $totalAsignada += (int)$s['cantidad_asignada'];
  }

} catch (Throwable $e) {
  http_response_code(500);
  echo "<pre>Error cargando movimientos: ".h($e->getMessage())."</pre>";
  exit;
}

// URLs export
$baseParams = [];
if ($q!=='')     $baseParams['q']     = $q;
if ($f_ini!=='') $baseParams['f_ini'] = $f_ini;
if ($f_fin!=='') $baseParams['f_fin'] = $f_fin;

$pCompras = $baseParams;  $pCompras['export'] = 'compras';
$pSalidas = $baseParams;  $pSalidas['export'] = 'salidas';

$exportComprasUrl = 'detalle_movimientos.php?'.http_build_query($pCompras);
$exportSalidasUrl = 'detalle_movimientos.php?'.http_build_query($pSalidas);
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Detalle de movimientos</title>
  <link rel="stylesheet" href="style.css">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="icon" href="img/Veraleza.png" type="image/png" class="url-logo">
  <style>
    .table-sticky thead th { position: sticky; top: 0; z-index: 2; }
    .search-mini { max-width: 320px; }
    thead.table-success th { color: #000; }
  </style>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link
    href="https://fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,100..900;1,14..32,100..900&family=Merriweather+Sans:ital,wght@0,300..800;1,300..800&family=Poppins:ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,100;1,200;1,300;1,400;1,500;1,600;1,700;1,800;1,900&display=swap"
    rel="stylesheet">
</head>
<body class="bg-light">
<div class="container-fluid py-4">

  <div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0">Detalle de movimientos</h1>
    <div class="d-flex gap-2">
      <a class="btn btn-sm btn-outline-primary" href="gestion_compras.php">Volver a gestión</a>
      <a class="btn btn-sm btn-outline-success" href="<?= h($exportComprasUrl) ?>">Exportar compras</a>
      <a class="btn btn-sm btn-outline-success" href="<?= h($exportSalidasUrl) ?>">Exportar salidas</a>
    </div>
  </div>

  <form class="row gy-2 gx-2 align-items-end mb-3" method="get" action="detalle_movimientos.php" id="formFiltros">
    <div class="col-auto">
      <label for="ftexto" class="form-label mb-1 small text-muted"><b>Buscar</b></label>
      <input type="text" id="ftexto" name="q" value="<?= h($q) ?>" class="form-control form-control-sm search-mini" placeholder="Producto / proveedor / delegación">
    </div>
    <div class="col-auto">
      <label for="f_ini" class="form-label mb-1 small text-muted"><b>Fecha inicio</b></label>
      <input type="date" id="f_ini" name="f_ini" value="<?= h($f_ini) ?>" class="form-control form-control-sm">
    </div>
    <div class="col-auto">
      <label for="f_fin" class="form-label mb-1 small text-muted"><b>Fecha fin</b></label>
      <input type="date" id="f_fin" name="f_fin" value="<?= h($f_fin) ?>" class="form-control form-control-sm">
    </div>
    <div class="col-auto d-flex gap-2">
      <button class="btn btn-sm btn-primary">Filtrar</button>
      <button type="button" id="btnClear" class="btn btn-sm btn-outline-secondary">Limpiar</button>
    </div>
  </form>

  <!-- COMPRAS -->
  <div class="mb-4">
    <h2 class="h6 mb-2">Compras (entradas)</h2>
    <?php if (!$compras): ?>
      <div class="alert alert-info">No hay compras para los filtros aplicados.</div>
    <?php else: ?>
    <div class="card shadow-sm mb-3">
      <div class="card-body">
        <div class="table-responsive" style="max-height:60vh;">
          <table class="table table-bordered table-sm align-middle table-sticky table-striped" id="tablaCompras">
            <thead class="table-success">
              <tr>
                <th style="width:70px;">ID</th>
                <th>Producto</th>
                <th>Proveedor</th>
                <th class="text-end" style="width:120px;">Cant. comprada</th>
                <th class="text-end" style="width:120px;">Cant. disponible</th>
                <th style="width:120px;">Fecha compra</th>
                <th style="width:170px;">Creada</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($compras as $c): ?>
              <tr>
                <td><?= (int)$c['id'] ?></td>
                <td><?= h($c['producto']) ?></td>
                <td><?= h($c['proveedor']) ?></td>
                <td class="text-end"><?= number_format((int)$c['cantidad_comprada'], 0, ',', '.') ?></td>
                <td class="text-end"><?= number_format((int)$c['cantidad_disponible'], 0, ',', '.') ?></td>
                <td><?= es_d($c['fecha_compra']) ?></td>
                <td><?= es_dt($c['created_at']) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr>
                <th colspan="3" class="text-end">TOTAL</th>
                <th class="text-end"><?= number_format($totalComprada,   0, ',', '.') ?></th>
                <th class="text-end"><?= number_format($totalDisponible, 0, ',', '.') ?></th>
                <th colspan="2"></th>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- SALIDAS -->
  <div>
    <h2 class="h6 mb-2">Asignaciones (salidas)</h2>
    <?php if (!$salidas): ?>
      <div class="alert alert-info">No hay salidas para los filtros aplicados.</div>
    <?php else: ?>
    <div class="card shadow-sm">
      <div class="card-body">
        <div class="table-responsive" style="max-height:60vh;">
          <table class="table table-bordered table-sm align-middle table-sticky table-striped" id="tablaSalidas">
            <thead class="table-success">
              <tr>
                <th style="width:70px;">ID</th>
                <th>Producto</th>
                <th>Proveedor</th>
                <th>Delegación</th>
                <th class="text-end" style="width:130px;">Cant. asignada</th>
                <th style="width:120px;">Fecha salida</th>
                <th style="width:170px;">Creada</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($salidas as $s): ?>
              <tr>
                <td><?= (int)$s['id'] ?></td>
                <td><?= h($s['producto']) ?></td>
                <td><?= h($s['proveedor']) ?></td>
                <td><?= h($s['delegacion']) ?></td>
                <td class="text-end"><?= number_format((int)$s['cantidad_asignada'], 0, ',', '.') ?></td>
                <td><?= es_d($s['fecha_salida']) ?></td>
                <td><?= es_dt($s['created_at']) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr>
                <th colspan="4" class="text-end">TOTAL</th>
                <th class="text-end"><?= number_format($totalAsignada, 0, ',', '.') ?></th>
                <th colspan="2"></th>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>

</div>

<script>
document.getElementById('btnClear')?.addEventListener('click', ()=>{
  const f = document.getElementById('formFiltros');
  document.getElementById('ftexto').value = '';
  document.getElementById('f_ini').value  = '';
  document.getElementById('f_fin').value  = '';
  f.submit();
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
