<?php
// gestion_compras.php — Gestión de compras (entradas) y stock por proveedor + asignaciones (salidas)
require_once __DIR__ . '/db.php';

@ini_set('display_errors','1');
@error_reporting(E_ALL);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

try {
  $pdo = pdo();

  // =============================
  // Matriz de stock agrupada por producto + proveedor + fecha_compra
  // =============================
  $sqlMatriz = "
    SELECT
      p.id                 AS producto_id,
      p.tipo,
      p.nombre             AS producto_nombre,
      p.proveedor          AS producto_proveedor,
      pr.id                AS proveedor_id,
      pr.nombre            AS proveedor_nombre,
      c.fecha_compra,
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
      c.fecha_compra
    ORDER BY
      p.nombre ASC,
      pr.nombre ASC,
      c.fecha_compra DESC
  ";
  $matriz = $pdo->query($sqlMatriz)->fetchAll(PDO::FETCH_ASSOC);

  // Marcar qué líneas están completamente asignadas y son "antiguas"
  // para poder ocultarlas por defecto en la matriz y mostrarlas bajo demanda.
  $DIAS_OCULTAR_CERRADAS = 7; // líneas cerradas con más de 7 días
  $hoy = new DateTimeImmutable('today');
  foreach ($matriz as &$row) {
    $row['cerrada_antigua'] = 0;
    $restante = (int)($row['cantidad_disponible'] ?? 0);
    if ($restante <= 0) {
      $fechaRaw = (string)($row['fecha_compra'] ?? '');
      if ($fechaRaw !== '') {
        try {
          $fecha = new DateTimeImmutable($fechaRaw);
          $diff  = $hoy->diff($fecha)->days;
          if ($diff > $DIAS_OCULTAR_CERRADAS) {
            $row['cerrada_antigua'] = 1;
          }
        } catch (Throwable $e) {
          // Si la fecha es inválida, dejamos la fila visible por seguridad
        }
      }
    }
  }
  unset($row);

  // =============================
  // Listado básico de productos (para pestaña Productos)
  // =============================
  $sqlProductos = "
    SELECT id, tipo, nombre, proveedor
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
    ORDER BY nombre
  ";
  $delegaciones = $pdo->query($sqlDelegaciones)->fetchAll(PDO::FETCH_ASSOC);

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
    .table-sticky thead th {
      position: sticky;
      top: 0;
      background: var(--bs-success-bg-subtle);
      color: var(--bs-success-text-emphasis);
      z-index: 2;
    }
    .cell-desc { min-width: 260px; }
    .cell-qty  { text-align: right; white-space: nowrap; }
    .search-mini { max-width: 320px; }

    .autocomplete-wrap { position: relative; }
    .autocomplete-list {
      position:absolute; z-index:1080; left:0; right:0; top:calc(100% - 1px);
      background:#fff; border:1px solid #ced4da; border-top:0;
      max-height:40vh; overflow:auto;
    }
    .autocomplete-item { padding:.45rem .6rem; cursor:pointer; }
    .autocomplete-item.active,
    .autocomplete-item:hover { background:#f1f3f5; }

    .row-resto0 td, .row-resto0 th {
      background: rgba(220, 53, 69, .10);
    }
  </style>
</head>
<body class="bg-light">
<div class="container-fluid py-4">

  <div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0 tit">Módulo de Compras y Distribución</h1>
    <div class="d-flex gap-2">
      <a href="detalle_movimientos.php" class="btn btn-sm btn-outline-primary">Detalle movimientos</a>
    </div>
  </div>

  <!-- Pestañas -->
  <ul class="nav nav-tabs mb-3" id="tabsCompras" role="tablist">
    <li class="nav-item" role="presentation">
      <button class="nav-link" id="tab-compra-tab" data-bs-toggle="tab" data-bs-target="#tab-compra" type="button" role="tab">
        Registrar compra
      </button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link active" id="tab-stock-tab" data-bs-toggle="tab" data-bs-target="#tab-stock" type="button" role="tab">
        Matriz de stock
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

  <div class="tab-content" id="tabsComprasContent">

    <!-- TAB 1: Registrar compra -->
    <div class="tab-pane fade" id="tab-compra" role="tabpanel" aria-labelledby="tab-compra-tab">
      <div class="card shadow-sm mb-3">
        <div class="card-body">
          <form id="formCompra" autocomplete="off" class="row g-3">
            <div class="col-md-4">
              <label class="form-label"><b>Producto</b></label>
              <div class="autocomplete-wrap">
                <input type="text" class="form-control" id="c_producto_nombre" placeholder="Escribe para buscar…">
                <input type="hidden" id="c_producto_id" name="producto_id">
                <div class="autocomplete-list d-none" id="c_producto_list"></div>
              </div>
            </div>
            <div class="col-md-4">
              <label class="form-label"><b>Proveedor</b></label>
              <select id="c_proveedor_id" name="proveedor_id" class="form-select">
                <option value="">Selecciona proveedor…</option>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label"><b>Cantidad comprada</b></label>
              <input type="number" min="1" step="1" class="form-control" id="c_cantidad" name="cantidad">
            </div>
            <div class="col-md-2">
              <label class="form-label"><b>Fecha compra</b></label>
              <input type="date" class="form-control" id="c_fecha" name="fecha_compra">
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
      <div class="d-flex align-items-end justify-content-between mb-2 flex-wrap gap-2">
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
                    <th>Fecha compra</th>
                    <th class="cell-qty">Total comprado</th>
                    <th class="cell-qty">Asignado</th>
                    <th class="cell-qty">Restante</th>
                    <th class="text-center">Acciones</th>
                  </tr>
                </thead>
                <tbody>
                <?php foreach ($matriz as $row):
                  $fechaCompraRaw = (string)($row['fecha_compra'] ?? '');
                  $fechaCompra    = $fechaCompraRaw !== '' ? date('d/m/Y', strtotime($fechaCompraRaw)) : '';
                  $totalComprado  = (int)$row['cantidad_comprada'];
                  $asignadoLote   = (int)$row['cantidad_asignada'];
                  $restante       = (int)$row['cantidad_disponible'];
                  $rowClassParts  = [];
                  if ($restante === 0) {
                    $rowClassParts[] = 'row-resto0';
                  }
                  if (!empty($row['cerrada_antigua'])) {
                    $rowClassParts[] = 'row-cerrada-antigua';
                  }
                  $rowClass = implode(' ', $rowClassParts);
                ?>
                  <tr class="<?= $rowClass ?>">
                    <td class="text-center align-middle">
                      <button
                        type="button"
                        class="btn btn-link btn-sm p-0 btn-lineas"
                        data-producto-id="<?= (int)$row['producto_id'] ?>"
                        data-proveedor-id="<?= (int)$row['proveedor_id'] ?>"
                        data-fecha-compra-raw="<?= h($row['fecha_compra'] ?? '') ?>"
                        title="Ver líneas de asignación"
                      >+</button>
                    </td>
                    <td><?= h($row['tipo']) ?></td>
                    <td><?= (int)$row['producto_id'] ?></td>
                    <td class="cell-desc"><?= h($row['producto_nombre']) ?></td>
                    <td><?= h($row['proveedor_nombre']) ?></td>
                    <td><?= h($fechaCompra) ?></td>
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
                    <td colspan="9" class="text-center text-muted">No hay stock registrado.</td>
                  </tr>
                <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

    </div>
    <!-- TAB 3: Gestión de productos -->
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
                  <label class="form-label mb-1"><b>Proveedor</b></label>
                  <div class="autocomplete-wrap">
                    <input type="text" id="p_proveedor_nombre" class="form-control form-control-sm" placeholder="Escribe para buscar…">
                    <input type="hidden" id="p_proveedor_id" name="proveedor_id">
                    <div class="autocomplete-list d-none" id="p_proveedor_list"></div>
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
                  <input type="text" id="p_filtro" class="form-control form-control-sm search-mini" placeholder="Tipo / nombre / proveedor">
                </div>
              </div>
              <div class="table-responsive" style="max-height:70vh;">
                <table class="table table-striped table-bordered table-sm align-middle table-sticky" id="tablaProductos">
                  <thead class="table-success">
                    <tr>
                      <th style="width:70px;">ID</th>
                      <th>Tipo</th>
                      <th class="cell-desc">Nombre</th>
                      <th>Proveedor</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php if (!$productos): ?>
                    <tr>
                      <td colspan="5" class="text-center text-muted">No hay productos.</td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($productos as $p): ?>
                      <tr>
                        <td><?= (int)$p['id'] ?></td>
                        <td><?= h($p['tipo']) ?></td>
                        <td class="cell-desc"><?= h($p['nombre']) ?></td>
                        <td><?= h($p['proveedor']) ?></td>
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
            <label class="form-label"><b>Fecha salida</b></label>
            <input type="date" id="a_fecha" name="fecha_salida" class="form-control">
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

<script>
const API_SEARCH = new URL('api_search.php', location.href).toString();
const nf         = new Intl.NumberFormat('es-ES');
let mostrarCerradasAntiguas = false;

function debounce(fn, ms){
  let t; return (...a)=>{ clearTimeout(t); t=setTimeout(()=>fn(...a), ms); };
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
  const fechaCompra = btn.getAttribute('data-fecha-compra-raw') || '';

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
    if (fechaCompra) {
      url.searchParams.set('fecha_compra', fechaCompra);
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
                <th style="width:120px;">Fecha salida</th>
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
      const fs   = a.fecha_salida || '';
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
      const fecha        = (document.getElementById('c_fecha').value||'').trim();

      const errs=[];
      if(!producto_nombre)  errs.push('Selecciona un producto válido.');
      if(!proveedor_id) errs.push('Selecciona un proveedor válido.');
      if(!cantidad || cantidad<=0) errs.push('Cantidad debe ser > 0.');
      if(!fecha) errs.push('Selecciona una fecha de compra.');

      if(errs.length){
        cErrEl.textContent = errs.join(' | ');
        cErrEl.style.display='block';
        return;
      }
      try {
        const res = await fetch('save_compra.php', {
          method:'POST',
          headers:{'Content-Type':'application/json'},
          body: JSON.stringify({ producto_nombre, proveedor_id, cantidad, fecha_compra: fecha })
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
  const aFecha    = document.getElementById('a_fecha');
  const aErrEl    = document.getElementById('a_error');

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
      aFecha.value = new Date().toISOString().slice(0,10);

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
    const fecha_salida  = (aFecha.value||'').trim();

    const errs = [];
    if(!producto_id)   errs.push('Producto inválido.');
    if(!proveedor_id)  errs.push('Proveedor inválido.');
    if(!delegacion_id) errs.push('Selecciona una delegación.');
    if(!cantidad || cantidad<=0) errs.push('Cantidad debe ser > 0.');
    if(!fecha_salida) errs.push('Selecciona una fecha de salida.');

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
        body: JSON.stringify({ producto_id, proveedor_id, delegacion_id, cantidad, fecha_salida })
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

  // ====== Pestaña Productos: filtro y alta ======
  const pFiltro = document.getElementById('p_filtro');
  const tablaProductos = document.getElementById('tablaProductos');
  const pErrEl = document.getElementById('p_error');

  function applyProductosFilter(){
    if (!tablaProductos) return;
    const term = (pFiltro?.value || '').trim().toLowerCase();
    tablaProductos.querySelectorAll('tbody tr').forEach(tr=>{
      const tds = tr.querySelectorAll('td,th');
      if (tds.length < 4){ tr.style.display=''; return; }
      const id    = (tds[0].textContent||'').toLowerCase();
      const tipo  = (tds[1].textContent||'').toLowerCase();
      const nombre= (tds[2].textContent||'').toLowerCase();
      const prov  = (tds[3].textContent||'').toLowerCase();
      const match = !term || id.includes(term) || tipo.includes(term) || nombre.includes(term) || prov.includes(term);
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
      const proveedor_id = parseInt(document.getElementById('p_proveedor_id')?.value || '0', 10) || 0;

      const errs = [];
      if(!tipo_id) errs.push('Selecciona un tipo de producto.');
      if(!nombre) errs.push('El nombre es obligatorio.');
      if(!proveedor_id) errs.push('Selecciona un proveedor.');

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
          body: JSON.stringify({ tipo_id, nombre, proveedor_id })
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

