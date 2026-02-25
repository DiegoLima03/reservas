<?php
// detalle.php — Listado de reservas con filtros, export y borrado
require_once __DIR__ . '/db.php';

@ini_set('display_errors','1');
@error_reporting(E_ALL);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function es_dt($ts){
  if (!$ts) return '-';
  $t = strtotime($ts);
  if ($t===false) return h($ts);
  return date('d/m/Y H:i', $t);
}

try {
  $pdo = pdo();

  // --- Filtros GET ---
  $q      = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
  $f_ini  = isset($_GET['f_ini']) ? trim((string)$_GET['f_ini']) : '';
  $f_fin  = isset($_GET['f_fin']) ? trim((string)$_GET['f_fin']) : '';

  // Normalizo fechas si vienen en formato YYYY-MM-DD
  $params = [];
  $where  = ['1=1'];

	if ($q !== '') {
	  $where[] = '(r.comercial_nombre LIKE :q1 OR r.producto_nombre LIKE :q2)';
	  $params[':q1'] = '%'.$q.'%';
	  $params[':q2'] = $params[':q1'];
	}
  if ($f_ini !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_ini)) {
    $where[] = 'r.created_at >= :fini';
    $params[':fini'] = $f_ini.' 00:00:00';
  }
  if ($f_fin !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_fin)) {
    $where[] = 'r.created_at <= :ffin';
    $params[':ffin'] = $f_fin.' 23:59:59';
  }

  // --- Export CSV ---
  if (isset($_GET['export']) && $_GET['export']==='1') {
    $sql = "
      SELECT r.id,r.id_oferta, r.comercial_nombre, r.producto_nombre, r.cantidad, r.created_at
      FROM reservas r
      WHERE ".implode(' AND ', $where)."
      ORDER BY r.created_at DESC, r.id DESC
    ";
    $st = $pdo->prepare($sql);
    foreach ($params as $k=>$v) $st->bindValue($k, $v);
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $filename = 'detalle_reservas_'.date('Ymd_His').'.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output','w');

    // Fila 1: filtros
    $filters = [];
    if ($q!=='')     $filters[] = 'Buscar: '.$q;
    if ($f_ini!=='') $filters[] = 'F. inicio: '.$f_ini;
    if ($f_fin!=='') $filters[] = 'F. fin: '.$f_fin;
    $filters[] = 'Generado: '.date('Y-m-d H:i:s');
    fputcsv($out, ['Filtros', implode(' | ', $filters)], ';');
    fputcsv($out, [''], ';');

    // Cabecera
    fputcsv($out, ['ID','ID_Oferta','Comercial','Producto','Cantidad','Creada (dd/mm/aaaa hh:mm)'], ';');

    foreach ($rows as $r) {
 fputcsv($out, [
   (int)$r['id'],
   ($r['id_oferta'] === null ? '' : (int)$r['id_oferta']),
   (string)$r['comercial_nombre'],
   (string)$r['producto_nombre'],
   (int)$r['cantidad'],
   es_dt($r['created_at']),
 ], ';');   
    }
    fclose($out);
    exit;
  }

  // --- Consulta normal ---
  $sql = "
    SELECT r.id, r.id_oferta, r.comercial_nombre, r.producto_nombre, r.cantidad, r.created_at
    FROM reservas r
    WHERE ".implode(' AND ', $where)."
    ORDER BY r.created_at DESC, r.id DESC
  ";
  $st = $pdo->prepare($sql);
  foreach ($params as $k=>$v) $st->bindValue($k, $v);
  $st->execute();
  $reservas = $st->fetchAll(PDO::FETCH_ASSOC);

  // Total cantidades (con filtros aplicados)
  $totalCantidad = 0;
  foreach ($reservas as $r) $totalCantidad += (int)$r['cantidad'];

} catch (Throwable $e) {
  http_response_code(500);
  echo "<pre>Error cargando detalle: ".h($e->getMessage())."</pre>";
  exit;
}

// URL export con filtros vigentes
$exportParams = [];
if ($q!=='')     $exportParams['q']     = $q;
if ($f_ini!=='') $exportParams['f_ini'] = $f_ini;
if ($f_fin!=='') $exportParams['f_fin'] = $f_fin;
$exportParams['export']='1';
$exportUrl = 'detalle.php?'.http_build_query($exportParams);
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Detalle de reservas</title>
<link rel="stylesheet" href="style.css">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="icon" href="img/Veraleza.png" type="image/png" class="url-logo">
<style>
  .table-sticky thead th { position: sticky; top: 0; z-index: 2; }
  .search-mini { max-width: 320px; }
  thead.table-success th { color: #000; } /* texto negro en cabecera verde clara */
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
    <h1 class="h4 mb-0">Detalle de reservas</h1>
    <div class="d-flex gap-2">
      <a class="btn btn-sm btn-outline-primary" href="reservas_gestion.php">Gestión reservas</a>
      <a class="btn btn-sm btn-outline-success" href="<?= h($exportUrl) ?>">Exportar Excel</a>
    </div>
  </div>

  <form class="row gy-2 gx-2 align-items-end mb-3" method="get" action="detalle.php" id="formFiltros">
    <div class="col-auto">
      <label for="ftexto" class="form-label mb-1 small text-muted"><b>Buscar (Comercial/Producto)</b></label>
      <input type="text" id="ftexto" name="q" value="<?= h($q) ?>" class="form-control form-control-sm search-mini" placeholder="Escribe para filtrar…">
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

  <?php if (!$reservas): ?>
    <div class="alert alert-info">No hay reservas para los filtros aplicados.</div>
  <?php else: ?>

  <div class="card shadow-sm">
    <div class="card-body">
      <div class="table-responsive" style="max-height:70vh;">
        <table class="table table-bordered table-sm align-middle table-sticky table-striped" id="tablaDetalle">
          <thead class="table-success">
            <tr>
              <th style="width:80px;">ID</th>
              <th style="width:100px;">ID Oferta</th>
              <th>Comercial/Cliente</th>
              <th>Producto</th>
              <th class="text-end" style="width:110px;">Cantidad</th>
              <th style="width:190px;">Creada</th>
              <th style="width:110px;" class="text-center">Acciones</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($reservas as $r): ?>
  <tr data-id="<?= (int)$r['id'] ?>">
    <td><?= (int)$r['id'] ?></td>
   <td><?= $r['id_oferta'] === null ? '-' : (int)$r['id_oferta'] ?></td>
    <td><?= h($r['comercial_nombre']) ?></td>
    <td><?= h($r['producto_nombre']) ?></td>
    <td class="text-end"><?= number_format((int)$r['cantidad'], 0, ',', '.') ?></td>
    <td><?= es_dt($r['created_at']) ?></td>
    <td class="text-center text-nowrap">
  <button
    type="button"
    class="btn btn-sm btn-outline-primary btn-edit me-1"
    data-id="<?= (int)$r['id'] ?>"
    data-comercial="<?= h($r['comercial_nombre']) ?>"
    data-cantidad="<?= (int)$r['cantidad'] ?>"
  >Editar</button>

  <button
    type="button"
    class="btn btn-sm btn-danger btn-del"
    data-id="<?= (int)$r['id'] ?>"
    data-idoferta="<?= $r['id_oferta'] === null ? '' : (int)$r['id_oferta'] ?>"
    data-comercial="<?= h($r['comercial_nombre']) ?>"
    data-producto="<?= h($r['producto_nombre']) ?>"
    data-cantidad="<?= (int)$r['cantidad'] ?>"
    data-created="<?= es_dt($r['created_at']) ?>"
  >Borrar</button>
</td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <th colspan="4" class="text-end">TOTAL</th>
              <th class="text-end"><?= number_format($totalCantidad, 0, ',', '.') ?></th>
              <th colspan="2"></th>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  </div>

  <?php endif; ?>
</div>

<!-- Modal confirmación borrado -->
<div class="modal fade" id="modalDel" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Borrar reserva</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <div id="delAlert" class="alert alert-danger d-none"></div>
        <p>¿Seguro que deseas borrar esta reserva?</p>
        <ul class="mb-0">
          <li><b>ID:</b> <span id="delId"></span></li>
          <li><b>Comercial/Cliente:</b> <span id="delCom"></span></li>
          <li><b>Producto:</b> <span id="delProd"></span></li>
          <li><b>Cantidad:</b> <span id="delCant"></span></li>
          <li><b>Creada:</b> <span id="delDate"></span></li>
        </ul>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">No</button>
        <button class="btn btn-danger" id="btnDelYes">Sí, borrar</button>
      </div>
    </div>
  </div>
</div>
<!-- Modal editar reserva -->
<div class="modal fade" id="modalEdit" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form id="formEdit" autocomplete="off">
        <div class="modal-header">
          <h5 class="modal-title">Editar reserva</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body">
          <div id="editAlert" class="alert alert-danger d-none"></div>

          <input type="hidden" id="edit_id">

          <div class="mb-3">
            <label class="form-label"><b>Comercial/Cliente</b></label>
            <div class="position-relative">
              <input type="text" id="edit_comercial_nombre" class="form-control" placeholder="Escribe para buscar…">
              <input type="hidden" id="edit_comercial_id">
              <div class="autocomplete-list d-none" id="edit_comercial_list" style="position:absolute;left:0;right:0;z-index:1080;background:#fff;border:1px solid #ced4da;border-top:0;max-height:40vh;overflow:auto;"></div>
            </div>
          </div>

          <div class="mb-0">
            <label class="form-label"><b>Cantidad</b></label>
            <input type="number" min="1" step="1" id="edit_cantidad" class="form-control" required>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Cancelar</button>
          <button class="btn btn-primary" type="submit">Guardar cambios</button>
        </div>
      </form>
    </div>
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

let delModal, delIdEl, delComEl, delProdEl, delCantEl, delDateEl, delAlertEl, currentId=null;
document.addEventListener('DOMContentLoaded', ()=>{
  delModal  = new bootstrap.Modal(document.getElementById('modalDel'));
  delIdEl   = document.getElementById('delId');
  delComEl  = document.getElementById('delCom');
  delProdEl = document.getElementById('delProd');
  delCantEl = document.getElementById('delCant');
  delDateEl = document.getElementById('delDate');
  delAlertEl= document.getElementById('delAlert');

  document.querySelectorAll('.btn-del').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      currentId = btn.dataset.id;
      delIdEl.textContent   = btn.dataset.id;
      delComEl.textContent  = btn.dataset.comercial || '';
      delProdEl.textContent = btn.dataset.producto || '';
      delCantEl.textContent = btn.dataset.cantidad || '0';
      delDateEl.textContent = btn.dataset.created  || '';
      delAlertEl.classList.add('d-none');
      delAlertEl.textContent = '';
      delModal.show();
    });
  });

  document.getElementById('btnDelYes').addEventListener('click', async ()=>{
    if(!currentId) return;
    try{
      const res = await fetch('delete_reserva.php', {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ id: parseInt(currentId,10) || 0 })
      });
      const data = await res.json().catch(()=>null);
      if(res.ok && data && data.ok){
        // Recarga para refrescar lista y TOTAL
        location.reload();
      }else{
        delAlertEl.textContent = (data && (data.error||data.errors)) ? (data.error||data.errors) : 'No se pudo borrar.';
        delAlertEl.classList.remove('d-none');
      }
    }catch(e){
      delAlertEl.textContent = 'Error de red.';
      delAlertEl.classList.remove('d-none');
    }
  });
});
let lastMaxEditable = null;
// ====== EDITAR RESERVA ======
let editModal, editForm, editAlertEl, editIdEl, editComNomEl, editComIdEl, editComListEl, editCantidadEl;

document.addEventListener('DOMContentLoaded', ()=>{
  // refs
  editModal     = new bootstrap.Modal(document.getElementById('modalEdit'));
  editForm      = document.getElementById('formEdit');
  editAlertEl   = document.getElementById('editAlert');
  editIdEl      = document.getElementById('edit_id');
  editComNomEl  = document.getElementById('edit_comercial_nombre');
  editComIdEl   = document.getElementById('edit_comercial_id');
  editComListEl = document.getElementById('edit_comercial_list');
  editCantidadEl= document.getElementById('edit_cantidad');

  // abre modal con datos actuales
document.querySelectorAll('.btn-edit').forEach(btn=>{
  btn.addEventListener('click', async ()=>{
    const id        = parseInt(btn.dataset.id,10) || 0;
    const comercial = btn.dataset.comercial || '';
    const cantidad  = parseInt(btn.dataset.cantidad,10) || 0;

    editIdEl.value       = id;
    editComNomEl.value   = comercial;
    editComIdEl.value    = '';
    editCantidadEl.value = cantidad;
    editCantidadEl.removeAttribute('max');
    editCantidadEl.placeholder = '';

    editAlertEl.classList.add('d-none');
    editAlertEl.textContent = '';

    try{
      // === NUEVO: pedir máximo editable al servidor
      const res = await fetch('max_reserva.php', {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ id })
      });
      const data = await res.json();
      if(res.ok && data.ok){
        lastMaxEditable = parseInt(data.max, 10) || 0;
        editCantidadEl.max = String(lastMaxEditable);
        editCantidadEl.placeholder = `Máximo ${lastMaxEditable}`;
      }else{
        lastMaxEditable = null; // si falla, no bloqueamos el modal, pero habrá validación server-side
      }
    }catch(_){ lastMaxEditable = null; }

    editModal.show();
    editComNomEl.focus();
  });
});

  // autocomplete comerciales
  const deb = (fn,ms)=>{ let t; return (...a)=>{ clearTimeout(t); t=setTimeout(()=>fn(...a),ms); }; };
  async function searchCom(q){
    if(!q.trim()){ editComListEl.innerHTML=''; editComListEl.classList.add('d-none'); editComIdEl.value=''; return; }
    try{
      const url = new URL('api_search.php', location.href);
      url.searchParams.set('type','comercial');
      url.searchParams.set('q', q);
      const res = await fetch(url.toString(), {headers:{'Accept':'application/json'}});
      const arr = res.ok ? await res.json() : [];
      renderComList(arr);
    }catch(e){ renderComList([]); }
  }
  function renderComList(items){
    editComListEl.innerHTML='';
    if(!items || !items.length){ editComListEl.classList.add('d-none'); return; }
    const frag = document.createDocumentFragment();
    items.forEach(it=>{
      const div = document.createElement('div');
      div.className = 'autocomplete-item';
      div.style.padding = '.45rem .6rem';
      div.textContent = it.nombre;
      div.addEventListener('mousedown', e=>{
        e.preventDefault();
        editComNomEl.value = it.nombre;
        editComIdEl.value  = it.id;
        editComListEl.innerHTML='';
        editComListEl.classList.add('d-none');
      });
      frag.appendChild(div);
    });
    editComListEl.appendChild(frag);
    editComListEl.classList.remove('d-none');
  }
  editComNomEl.addEventListener('input', deb(()=>searchCom(editComNomEl.value), 160));
  document.addEventListener('click', (e)=>{ if(!editComListEl.contains(e.target) && e.target!==editComNomEl){ editComListEl.innerHTML=''; editComListEl.classList.add('d-none'); } });

  // submit edición
  editForm.addEventListener('submit', async (e)=>{
    e.preventDefault();
    editAlertEl.classList.add('d-none');
    editAlertEl.textContent = '';

    const id        = parseInt(editIdEl.value,10) || 0;
    const cantidad  = parseInt(editCantidadEl.value,10) || 0;
    const com_id    = parseInt(editComIdEl.value,10) || 0;
    const com_nom   = (editComNomEl.value||'').trim();

    const errs=[];
    if(!id) errs.push('ID inválido');
    if(!cantidad || cantidad<=0) errs.push('Cantidad debe ser > 0');
    // Permitimos dos caminos: o se elige del autocomplete (id) o se mantiene el nombre original.
    // Si no hay com_id, intentamos resolver por nombre exacto en servidor.
    if(!com_nom) errs.push('Comercial requerido');

    if(errs.length){
      editAlertEl.textContent = errs.join(' | ');
      editAlertEl.classList.remove('d-none');
      return;
    }
// en el submit de editForm, justo antes del fetch:
if (typeof lastMaxEditable === 'number' && lastMaxEditable >= 0) {
  if (cantidad > lastMaxEditable) {
    editAlertEl.textContent = `No puedes superar el máximo disponible: ${lastMaxEditable}`;
    editAlertEl.classList.remove('d-none');
    return;
  }
}
    try{
      const res = await fetch('editar_reserva.php', {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({
          id,
          cantidad,
          comercial_id: com_id || null,
          comercial_nombre: com_nom
        })
      });
      const data = await res.json().catch(()=>null);
      if(res.ok && data && data.ok){
        location.reload();
      }else{
        editAlertEl.textContent = (data && (data.error||data.errors)) ? (data.error||data.errors) : 'No se pudo editar.';
        editAlertEl.classList.remove('d-none');
      }
    }catch(e){
      editAlertEl.textContent = 'Error de red.';
      editAlertEl.classList.remove('d-none');
    }
  });
});

</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
