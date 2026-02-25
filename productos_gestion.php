<?php
// productos_gestion.php — Alta y listado básico de productos
require_once __DIR__ . '/db.php';

@ini_set('display_errors','1');
@error_reporting(E_ALL);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

try {
  $pdo = pdo();

  $q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

  $sql = "
    SELECT id, tipo, nombre, proveedor, fecha
    FROM productos
    ORDER BY nombre
  ";
  $productos = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
  http_response_code(500);
  echo "<pre>Error cargando productos: " . h($e->getMessage()) . "</pre>";
  exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Gestión de productos</title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link
    href="https://fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,100..900;1,14..32,100..900&family=Merriweather+Sans:ital,wght@0,300..800;1,300..800&family=Poppins:ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,100;1,200;1,300;1,400;1,500;1,600;1,700;1,800;1,900&display=swap"
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
    .cell-desc  { min-width: 260px; }
    .search-mini{ max-width: 320px; }
  </style>
</head>
<body class="bg-light">
<div class="container-fluid py-4">

  <div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0 tit">Gestión de productos</h1>
    <div class="d-flex gap-2">
      <a href="gestion_compras.php" class="btn btn-sm btn-outline-primary">Compras / Stock</a>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-lg-5">
      <div class="card shadow-sm">
        <div class="card-body">
          <h2 class="h6 mb-3">Nuevo producto</h2>
          <form id="formProducto" autocomplete="off">
            <div class="mb-2">
              <label class="form-label mb-1"><b>Tipo</b></label>
              <input type="text" id="p_tipo" name="tipo" class="form-control form-control-sm" placeholder="Ej: Fruta, Verdura…">
            </div>
            <div class="mb-2">
              <label class="form-label mb-1"><b>Nombre</b></label>
              <input type="text" id="p_nombre" name="nombre" class="form-control form-control-sm" required>
            </div>
            <div class="mb-2">
              <label class="form-label mb-1"><b>Proveedor (texto)</b></label>
              <input type="text" id="p_proveedor" name="proveedor" class="form-control form-control-sm" placeholder="Proveedor de origen del producto">
            </div>
            <div class="mb-2">
              <label class="form-label mb-1"><b>Fecha</b></label>
              <input type="date" id="p_fecha" name="fecha" class="form-control form-control-sm">
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
              <label for="ftexto" class="form-label mb-1 small text-muted"><b>Buscar</b></label>
              <input type="text" id="ftexto" class="form-control form-control-sm search-mini" placeholder="Tipo / nombre / proveedor">
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
                  <th style="width:130px;">Fecha</th>
                </tr>
              </thead>
              <tbody>
              <?php if (!$productos): ?>
                <tr>
                  <td colspan="5" class="text-center text-muted">No hay productos.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($productos as $p):
                  $fechaRaw = (string)($p['fecha'] ?? '');
                  $fechaFmt = $fechaRaw !== '' ? date('d/m/Y', strtotime($fechaRaw)) : '';
                ?>
                  <tr>
                    <td><?= (int)$p['id'] ?></td>
                    <td><?= h($p['tipo']) ?></td>
                    <td class="cell-desc"><?= h($p['nombre']) ?></td>
                    <td><?= h($p['proveedor']) ?></td>
                    <td><?= h($fechaFmt) ?></td>
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
</div>

<script>
const nf = new Intl.NumberFormat('es-ES');

function applyTextFilter(){
  const term = (document.getElementById('ftexto')?.value || '').trim().toLowerCase();
  const table = document.getElementById('tablaProductos');
  if (!table) return;
  table.querySelectorAll('tbody tr').forEach(tr=>{
    const tds = tr.querySelectorAll('td,th');
    if (tds.length < 5){ tr.style.display=''; return; }
    const id    = (tds[0].textContent||'').toLowerCase();
    const tipo  = (tds[1].textContent||'').toLowerCase();
    const nombre= (tds[2].textContent||'').toLowerCase();
    const prov  = (tds[3].textContent||'').toLowerCase();
    const match = !term || id.includes(term) || tipo.includes(term) || nombre.includes(term) || prov.includes(term);
    tr.style.display = match ? '' : 'none';
  });
}

function debounce(fn, ms){
  let t; return (...a)=>{ clearTimeout(t); t=setTimeout(()=>fn(...a), ms); };
}

document.addEventListener('DOMContentLoaded', ()=>{
  const ftexto = document.getElementById('ftexto');
  if (ftexto){
    ftexto.addEventListener('input', debounce(applyTextFilter, 120));
  }
  applyTextFilter();

  const form  = document.getElementById('formProducto');
  const errEl = document.getElementById('p_error');

  form.addEventListener('submit', async (e)=>{
    e.preventDefault();
    errEl.style.display='none'; errEl.textContent='';

    const tipo      = (document.getElementById('p_tipo').value || '').trim();
    const nombre    = (document.getElementById('p_nombre').value || '').trim();
    const proveedor = (document.getElementById('p_proveedor').value || '').trim();
    const fecha     = (document.getElementById('p_fecha').value || '').trim();

    const errs = [];
    if(!nombre) errs.push('El nombre es obligatorio.');

    if(errs.length){
      errEl.textContent = errs.join(' | ');
      errEl.style.display='block';
      return;
    }

    try{
      const res = await fetch('save_producto.php', {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ tipo, nombre, proveedor, fecha })
      });
      let data=null, txt='';
      try { data = await res.json(); } catch(_){ txt = await res.text(); }

      if(res.ok && data && data.ok){
        location.reload();
      }else{
        const msg = (data && (data.errors||data.error))
          ? (Array.isArray(data.errors)? data.errors.join(' | ') : data.error)
          : (txt || 'No se pudo guardar el producto.');
        errEl.textContent = msg;
        errEl.style.display='block';
      }
    }catch(e){
      console.error(e);
      errEl.textContent = 'Error de red.';
      errEl.style.display='block';
    }
  });
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

