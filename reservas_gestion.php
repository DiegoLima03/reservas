<?php
// reservas_gestion.php — MATRIZ Productos x Comerciales (sin comprador/pedido/pendiente) + Modal NUEVA RESERVA
require_once __DIR__ . '/db.php';

@ini_set('display_errors','1');
@error_reporting(E_ALL);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

try {
  $pdo = pdo();

	// === Filtros GET (solo texto para filtrar en la vista) ===
	$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

	// === Productos: mostramos TODO el catálogo ===
	$sqlProd = "
	  SELECT p.id, p.tipo, p.nombre, p.cultivo, p.vuelo, p.cantidad, p.fecha
	  FROM productos p
	  ORDER BY p.nombre
	";
	$productos = $pdo->query($sqlProd)->fetchAll(PDO::FETCH_ASSOC);

	// === 'pedido' = SUM(reservas.cantidad) por producto (total de reservas de la línea) ===
	$stPed = $pdo->query("
	  SELECT r.producto_id, SUM(r.cantidad) AS total
	  FROM reservas r
	  GROUP BY r.producto_id
	");
	$pedidoPor = [];
	while ($r = $stPed->fetch(PDO::FETCH_ASSOC)) {
	  $pedidoPor[(int)$r['producto_id']] = (int)$r['total'];
	}

	// ============================
	//  EXPORTACIÓN CSV (nuevo formato)
	// ============================
	if (isset($_GET['export']) && $_GET['export'] === '1') {
	  $filename = 'productos_' . date('Ymd_His') . '.csv';
	  header('Content-Type: text/csv; charset=UTF-8');
	  header('Content-Disposition: attachment; filename="'.$filename.'"');
	  echo "\xEF\xBB\xBF";
	  $out = fopen('php://output', 'w');

	  // Fila 1: filtros básicos
	  $filters = [];
	  if ($q !== '') $filters[] = 'Buscar: '.$q;
	  $filters[] = 'Fecha: ' . date('Y-m-d H:i:s');
	  fputcsv($out, ['Filtros', implode(' | ', $filters)], ';');
	  fputcsv($out, [''], ';'); // línea en blanco

	  // Cabecera
	  fputcsv($out, ['Tipo','Nombre','Cultivo','Vuelo','Cantidad','Fecha','Pedido','Restante'], ';');


foreach ($productos as $p) {
  $pid      = (int)$p['id'];
  $tipo     = (string)$p['tipo'];
  $nombre   = (string)$p['nombre'];
  $cultivo  = (string)$p['cultivo'];
  $vuelo    = (string)$p['vuelo'];
  $cantidad = (int)$p['cantidad'];
  $fechaRaw = (string)($p['fecha'] ?? '');
  $fechaCSV = $fechaRaw !== '' ? date('Y-m-d', strtotime($fechaRaw)) : '';
  $pedido   = (int)($pedidoPor[$pid] ?? 0);

  // filtro local...
  if ($q !== '') {
    $hay = function($s) use($q){return mb_strpos(mb_strtolower($s,'UTF-8'), mb_strtolower($q,'UTF-8')) !== false;};
    if (!($hay($tipo)||$hay($nombre)||$hay($cultivo)||$hay($vuelo))) continue;
  }

  $restante = $cantidad - $pedido;
  fputcsv($out, [$tipo, $nombre, $cultivo, $vuelo, $fechaCSV, $cantidad, $pedido, $restante], ';');
}


	  fclose($out);
	  exit;
	}


} catch (Throwable $e) {
  http_response_code(500);
  echo "<pre>Error cargando matriz: " . h($e->getMessage()) . "</pre>";
  exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Gestión de reservas</title>
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
	  background: var(--bs-success-bg-subtle);   /* verde claro (#d1e7dd) */
	  color: var(--bs-success-text-emphasis);    /* texto verde oscuro */
	  z-index: 2;
	}
    .cell-ref  { min-width: 120px; white-space: nowrap; }
    .cell-desc { min-width: 260px; }
    .cell-qty  { text-align: right; white-space: nowrap; }
    .search-mini { max-width: 320px; }

    /* Autocomplete */
    .autocomplete-wrap { position: relative; }
    .autocomplete-list { position:absolute; z-index:1080; left:0; right:0; top:calc(100% - 1px); background:#fff; border:1px solid #ced4da; border-top:0; max-height:40vh; overflow:auto; }
    .autocomplete-item { padding:.45rem .6rem; cursor:pointer; }
    .autocomplete-item.active, .autocomplete-item:hover { background:#f1f3f5; }
	  /* Fila con restante = 0 (rojo suave) */
	.table tbody tr.row-resto0 td,
	.table tbody tr.row-resto0 th { background: rgba(220, 53, 69, .10); }

  </style>
</head>
<body class="bg-light">
<div class="container-fluid py-4">


	<div class="d-flex align-items-center justify-content-between mb-3">
	  <h1 class="h4 mb-0 tit">Gestión de reservas</h1>
	  <div class="d-flex gap-2">
		<a href="detalle.php" class="btn btn-sm btn-outline-primary">Detalle reservas</a>
		<?php
		  $exportParams = [];
		  if (isset($_GET['q']) && $_GET['q']!=='') $exportParams['q']=$_GET['q'];
		  $exportParams['export']='1';
		  $exportUrl = 'reservas_gestion.php'.'?'.http_build_query($exportParams);
		?>
		<a href="<?= h($exportUrl) ?>" id="btnExport" class="btn btn-sm btn-outline-success">Exportar Excel</a>
  		</div>
	</div>
	
	
	<?php if (!$productos): ?>
	  <div class="alert alert-info">
		No hay productos que mostrar.
	  </div>
	<?php else: ?>


  <!-- Barra búsqueda en vivo -->
  <div class="d-flex align-items-end justify-content-between mb-2 flex-wrap gap-2">
    <div class="d-flex flex-column">
      <label for="ftexto" class="form-label mb-1 small text-muted"><b>Buscar</b></label>
      <input type="text" id="ftexto" class="form-control form-control-sm search-mini" placeholder="Escribe para filtrar…">
    </div>
  </div>

  <div id="matrizContainer">
    <div class="card shadow-sm">
      <div class="card-body">
        <div class="table-responsive" style="max-height:70vh;">
          <table class="table table-striped table-bordered table-sm align-middle table-sticky" id="tablaMatriz">
			<thead class="table-success">
			  <tr>
				<th>Tipo</th>
				<th class="cell-desc">Nombre</th>
				<th>Cultivo</th>
				<th>Vuelo</th>
        <th>Fecha</th>
				<th class="cell-qty">Cantidad</th>
				<th class="cell-qty">Pedido</th>
				<th class="cell-qty">Restante</th> <!-- NUEVA -->
				<th class="text-center">Pedir</th>
			  </tr>
			</thead>



			<tbody>
		<?php
  foreach ($productos as $p):
    $pid      = (int)$p['id'];
    $tipo     = trim((string)($p['tipo'] ?? ''));
    $nombre   = trim((string)($p['nombre'] ?? ''));
    $cultivo  = trim((string)($p['cultivo'] ?? ''));
    $vuelo    = trim((string)($p['vuelo'] ?? ''));
    $fechaRaw = trim((string)($p['fecha'] ?? ''));
    $fechaFmt = $fechaRaw !== '' ? date('d/m/Y', strtotime($fechaRaw)) : '';
    $cantidad = (int)($p['cantidad'] ?? 0);
    $pedido   = (int)($pedidoPor[$pid] ?? 0);
    $restante = $cantidad - $pedido;
    $rowClass = ($restante === 0) ? 'row-resto0' : '';

    // filtro local q...
    if ($q !== '') {
      $q_l = mb_strtolower($q,'UTF-8');
      $hay = function($s) use($q_l){ return mb_strpos(mb_strtolower($s,'UTF-8'), $q_l) !== false; };
      if (!($hay($tipo)||$hay($nombre)||$hay($cultivo)||$hay($vuelo))) continue;
    }
?>
<tr class="<?= $rowClass ?>">
  <td><?= h($tipo) ?></td>
  <td class="cell-desc"><?= h($nombre) ?></td>
  <td><?= h($cultivo) ?></td>
  <td><?= h($vuelo) ?></td>
  <td><?= h($fechaFmt) ?></td> <!-- NUEVA -->
  <td class="cell-qty"><?= number_format($cantidad, 0, ',', '.') ?></td>
  <td class="cell-qty"><?= number_format($pedido,   0, ',', '.') ?></td>
  <td class="cell-qty"><?= number_format($restante, 0, ',', '.') ?></td>
  <td class="text-center">
<button
  class="btn btn-sm btn-primary btn-pedir"
  data-producto-id="<?= $pid ?>"
  data-producto-nombre="<?= h($nombre) ?>"
  data-restante="<?= max(0, $restante) ?>"
  data-producto-fecha="<?= h($fechaRaw) ?>"  
  <?= $restante === 0 ? 'disabled title="Sin restante"' : '' ?>
>Pedir</button>
  </td>
</tr>
<?php endforeach; ?>


			<!--  <?php if (!$printed): ?> 
				<tr>
				  <td colspan="8" class="text-center text-muted">
					No hay datos.
				  </td>
				</tr>
			  <?php endif; ?>
			</tbody>


          </table>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>-->
</div>

<!-- MODAL NUEVA RESERVA -->
<div class="modal fade" id="modalReserva" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form id="formReserva" autocomplete="off">
        <div class="modal-header">
          <h5 class="modal-title">Nueva reserva</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body">
			<div class="mb-3">
			  <label class="form-label"><b>Producto</b></label>
			  <div class="autocomplete-wrap">
				<input type="text" class="form-control" id="m_producto_nombre" placeholder="Escribe R para buscar…">
				<input type="hidden" id="m_producto_id" name="producto_id">
				<div class="autocomplete-list d-none" id="m_producto_list"></div>
			  </div>
			</div>


          <div class="mb-3">
            <label class="form-label"><b>Comercial/Cliente</b></label>
            <div class="autocomplete-wrap">
              <input type="text" id="m_comercial_input" class="form-control" placeholder="Escribe R (Rutas) o E (Eloy) para buscar…">
              <input type="hidden" id="m_comercial_id" name="comercial_id">
              <div class="autocomplete-list d-none" id="m_comercial_list"></div>
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label"><b>Cantidad</b></label>
            <input type="number" min="1" step="1" id="m_cantidad" name="cantidad" class="form-control" required>
          </div>

          <div class="mb-0">
            <label class="form-label"><b>Fecha Expedición</b></label>
            <input type="date" id="m_fecha" name="fecha" class="form-control">
          </div>
        </div>
        <div class="modal-footer">
          <div class="me-auto text-danger small" id="m_error" style="display:none;"></div>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button class="btn btn-primary" type="submit">Guardar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
const nf = new Intl.NumberFormat('es-ES');
const inputTexto = document.getElementById('ftexto');
const container = document.getElementById('matrizContainer');
const API_SEARCH = new URL('api_search.php', location.href).toString();

function debounce(fn, ms){ let t; return (...a)=>{ clearTimeout(t); t=setTimeout(()=>fn(...a), ms); }; }
function escapeHtml(s){ return (s||'').replace(/[&<>"']/g, m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m])); }
function setURLParam(name, value){
  const url = new URL(location.href);
  if (value !== undefined && value !== null && String(value).trim() !== '') url.searchParams.set(name, value);
  else url.searchParams.delete(name);
  history.replaceState(null, '', url.toString());
}

// === Filtro de texto local (ref + desc)
function getSearchTerm(){ return (inputTexto?.value || '').trim().toLowerCase(); }
function applyTextFilter(scope){
  const term = getSearchTerm();
  const table = (scope || document).querySelector('#tablaMatriz');
  if(!table) return;
  table.querySelectorAll('tbody tr').forEach(tr=>{
    const tds = tr.querySelectorAll('td,th');
    if (tds.length < 8) { tr.style.display=''; return; }
    const tipo   = (tds[0].textContent||'').toLowerCase();
    const nombre = (tds[1].textContent||'').toLowerCase();
    const cultivo= (tds[2].textContent||'').toLowerCase();
    const vuelo  = (tds[3].textContent||'').toLowerCase();
    const match = !term || tipo.includes(term) || nombre.includes(term) || cultivo.includes(term) || vuelo.includes(term);
    tr.style.display = match ? '' : 'none';
  });
}

function initTextFilter(){
  const url = new URL(location.href);
  const q = url.searchParams.get('q') || '';
  if (inputTexto) {
    inputTexto.value = q;
    const deb = debounce(()=>{ setURLParam('q', inputTexto.value.trim()); applyTextFilter(document); }, 120);
    inputTexto.addEventListener('input', deb);
  }
  applyTextFilter(document);
}

// === (Re)render con endpoint compacto (sin comprador/pedido/pendiente)
async function fetchAndRender(){
  try{
    const url = new URL('reservas_gestion_data.php', location.href);
    const res = await fetch(url.toString(), {cache:'no-store'});
    const data = await res.json();
    if(!res.ok){ console.error(data); return; }
    container.innerHTML = buildTableHTML(data);
    wirePedirButtons(container);
    applyTextFilter(container);
  }catch(e){ console.error('Error poll:', e); }
}

function cellQty(val){ return nf.format((Number(val)||0)); }


// === Autocomplete para Comercial (modal)
function createAutocomplete({input, hidden, list, type}){
  let current=[], active=-1;
  function clear(){ list.innerHTML=''; list.classList.add('d-none'); active=-1; }
  function render(items){
    current = items||[]; list.innerHTML='';
    if(!current.length){ clear(); return; }
    const frag = document.createDocumentFragment();
    current.forEach((it,i)=>{
      const div=document.createElement('div');
      div.className='autocomplete-item';
      div.textContent = it.nombre;
      div.addEventListener('mousedown', e=>{ e.preventDefault(); input.value=it.nombre; hidden.value=it.id; clear(); });
      frag.appendChild(div);
    });
    list.appendChild(frag); list.classList.remove('d-none');
  }
  const search = debounce(async ()=>{
    const q = input.value.trim(); hidden.value = '';
    if(!q){ clear(); return; }
    try{
      const url = API_SEARCH + '?type=' + encodeURIComponent(type) + '&q=' + encodeURIComponent(q) + '&_=' + Date.now();
      const res = await fetch(url, {headers:{'Accept':'application/json'}});
      if(!res.ok) throw new Error('HTTP '+res.status);
      render(await res.json());
    }catch(e){ console.error('Autocomplete error',e); clear(); }
  }, 160);
  input.addEventListener('input', search);
  input.addEventListener('keydown', (e)=>{
    const items = Array.from(list.querySelectorAll('.autocomplete-item'));
    if(e.key==='ArrowDown'){ e.preventDefault(); active=Math.min(active+1, items.length-1); }
    else if(e.key==='ArrowUp'){ e.preventDefault(); active=Math.max(active-1, 0); }
    else if(e.key==='Enter'){ if(active>=0 && items[active]){ e.preventDefault(); items[active].dispatchEvent(new Event('mousedown')); } }
    else if(e.key==='Escape'){ clear(); }
    items.forEach((el,idx)=> el.classList.toggle('active', idx===active));
  });
  document.addEventListener('click', (e)=>{ if(!list.contains(e.target) && e.target!==input) clear(); });
}

let modal, mForm, mErr, mProdNombre, mProdId, mProdList, mComInput, mComId, mComList, mQty, mFecha;


document.addEventListener('DOMContentLoaded', ()=>{
  const nf = new Intl.NumberFormat('es-ES');
  const inputTexto = document.getElementById('ftexto');
  const container  = document.getElementById('matrizContainer');
  const API_SEARCH = new URL('api_search.php', location.href).toString();

  const debounce = (fn, ms)=>{ let t; return (...a)=>{ clearTimeout(t); t=setTimeout(()=>fn(...a), ms); }; };
  const escapeHtml = (s)=> (s||'').replace(/[&<>"']/g, m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));
  function setURLParam(name, value){
    const url = new URL(location.href);
    if (value !== undefined && value !== null && String(value).trim() !== '') url.searchParams.set(name, value);
    else url.searchParams.delete(name);
    history.replaceState(null, '', url.toString());
  }

  function getSearchTerm(){ return (inputTexto?.value || '').trim().toLowerCase(); }
  function applyTextFilter(scope){
    const term = getSearchTerm();
    const table = (scope || document).querySelector('#tablaMatriz');
    if(!table) return;
    table.querySelectorAll('tbody tr').forEach(tr=>{
      const tds = tr.querySelectorAll('td,th');
      if (tds.length < 8) { tr.style.display=''; return; }
      const tipo   = (tds[0].textContent||'').toLowerCase();
      const nombre = (tds[1].textContent||'').toLowerCase();
      const cultivo= (tds[2].textContent||'').toLowerCase();
      const vuelo  = (tds[3].textContent||'').toLowerCase();
      const match = !term || tipo.includes(term) || nombre.includes(term) || cultivo.includes(term) || vuelo.includes(term);
      tr.style.display = match ? '' : 'none';
    });
  }
  function initTextFilter(){
    const url = new URL(location.href);
    const q = url.searchParams.get('q') || '';
    if (inputTexto) {
      inputTexto.value = q;
      const deb = debounce(()=>{ setURLParam('q', inputTexto.value.trim()); applyTextFilter(document); }, 120);
      inputTexto.addEventListener('input', deb);
    }
    applyTextFilter(document);
  }

  function cellQty(val){ return nf.format((Number(val)||0)); }
function buildTableHTML(data){
  const prods = data.productos || [];

  let thead = `
    <thead class="table-light">
      <tr>
        <th>Tipo</th>
        <th class="cell-desc">Nombre</th>
        <th>Cultivo</th>
        <th>Vuelo</th>
        <th>Fecha</th>
        <th class="cell-qty">Cantidad</th>
        <th class="cell-qty">Pedido</th>
        <th class="cell-qty">Restante</th>
        <th class="text-center">Pedir</th>
      </tr>
    </thead>`;

  let tbody = '<tbody>';
  if (!prods.length){
    tbody += `<tr><td colspan="8" class="text-center text-muted">No hay datos.</td></tr>`;
  } else {
    prods.forEach(p=>{
      const pid      = p.id;
      const tipo     = p.tipo || '';
      const nombre   = p.nombre || '';
      const cultivo  = p.cultivo || '';
      const vuelo    = p.vuelo || '';
      const cantidad = Number(p.cantidad||0);
      const pedido   = Number(p.pedido||0);
      const restante = cantidad - pedido;
      const rowClass = (restante === 0) ? 'row-resto0' : '';
      const disabledAttr = (restante === 0) ? 'disabled title="Sin restante"' : '';

const fechaRaw = p.fecha || '';
const fechaFmt = (()=>{
  if(!fechaRaw) return '';
  // formato DD/MM/YYYY si viene YYYY-MM-DD
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(fechaRaw);
  return m ? `${m[3]}/${m[2]}/${m[1]}` : escapeHtml(fechaRaw);
})();

tbody += `
  <tr class="${rowClass}">
    <td>${escapeHtml(tipo)}</td>
    <td class="cell-desc">${escapeHtml(nombre)}</td>
    <td>${escapeHtml(cultivo)}</td>
    <td>${escapeHtml(vuelo)}</td>
    <td>${fechaFmt}</td>
    <td class="cell-qty">${nf.format(cantidad)}</td>
    <td class="cell-qty">${nf.format(pedido)}</td>
    <td class="cell-qty">${nf.format(restante)}</td>
    <td class="text-center">
      <button class="btn btn-sm btn-primary btn-pedir"
              data-producto-id="${pid}"
              data-producto-nombre="${escapeHtml(nombre)}"
              data-restante="${Math.max(0, restante)}"
              data-producto-fecha="${escapeHtml(fechaRaw)}"
              ${disabledAttr}>Pedir</button>
    </td>
  </tr>`;
    });
  }
  tbody += '</tbody>';

  return `
    <div class="card shadow-sm">
      <div class="card-body">
        <div class="table-responsive" style="max-height:70vh;">
          <table class="table table-striped table-bordered table-sm align-middle table-sticky" id="tablaMatriz">
            ${thead}${tbody}
          </table>
        </div>
      </div>
    </div>`;
}


  function createAutocomplete({input, hidden, list, type}){
    let current=[], active=-1;
    function clear(){ list.innerHTML=''; list.classList.add('d-none'); active=-1; }
    function render(items){
      current = items||[]; list.innerHTML='';
      if(!current.length){ clear(); return; }
      const frag = document.createDocumentFragment();
      current.forEach((it,i)=>{
        const div=document.createElement('div');
        div.className='autocomplete-item';
        div.textContent = it.nombre;
        div.addEventListener('mousedown', e=>{ e.preventDefault(); input.value=it.nombre; hidden.value=it.id; clear(); });
        frag.appendChild(div);
      });
      list.appendChild(frag); list.classList.remove('d-none');
    }
    const search = debounce(async ()=>{
      const q = input.value.trim(); hidden.value = '';
      if(!q){ clear(); return; }
      try{
        const url = API_SEARCH + '?type=' + encodeURIComponent(type) + '&q=' + encodeURIComponent(q) + '&_=' + Date.now();
        const res = await fetch(url, {headers:{'Accept':'application/json'}});
        if(!res.ok) throw new Error('HTTP '+res.status);
        render(await res.json());
      }catch(e){ console.error('Autocomplete error',e); clear(); }
    }, 160);
    input.addEventListener('input', search);
    input.addEventListener('keydown', (e)=>{
      const items = Array.from(list.querySelectorAll('.autocomplete-item'));
      if(e.key==='ArrowDown'){ e.preventDefault(); active=Math.min(active+1, items.length-1); }
      else if(e.key==='ArrowUp'){ e.preventDefault(); active=Math.max(active-1, 0); }
      else if(e.key==='Enter'){ if(active>=0 && items[active]){ e.preventDefault(); items[active].dispatchEvent(new Event('mousedown')); } }
      else if(e.key==='Escape'){ clear(); }
      items.forEach((el,idx)=> el.classList.toggle('active', idx===active));
    });
    document.addEventListener('click', (e)=>{ if(!list.contains(e.target) && e.target!==input) clear(); });
  }

  let modal, mForm, mErr, mProdNombre, mProdId, mProdList, mComInput, mComId, mComList, mQty, mFecha;

  async function fetchAndRender(){
    try{
      const url = new URL('reservas_gestion_data.php', location.href);
      const res = await fetch(url.toString(), {cache:'no-store'});
      const data = await res.json();
      if(!res.ok){ console.error(data); return; }
      container.innerHTML = buildTableHTML(data);
      wirePedirButtons(container);    // enlaza botones de la tabla generada
      applyTextFilter(container);
    }catch(e){ console.error('Error poll:', e); }
  }

	function wirePedirButtons(scope){
	  (scope||document).querySelectorAll('.btn-pedir').forEach(btn=>{
		btn.addEventListener('click', (ev)=>{
		  if (btn.disabled) { ev.preventDefault(); return; }

		  mProdNombre.value   = btn.getAttribute('data-producto-nombre')||'';
		  mProdId.value       = btn.getAttribute('data-producto-id')||'';
		  mProdNombre.readOnly = true;
		  if (mProdList){ mProdList.innerHTML=''; mProdList.classList.add('d-none'); }

		  // >>> NUEVO: restante de esa línea
		  const restante = parseInt(btn.getAttribute('data-restante')||'0', 10) || 0;
		  mQty.value = '';
		  mQty.max = Math.max(0, restante);
		  mQty.dataset.restante = String(Math.max(0, restante));
		  mQty.placeholder = restante > 0 ? `Máximo ${restante}` : 'Sin restante';
		  // <<<

		  // Prefiere la fecha del producto (contenedor). Si no es válida, usa hoy.
 const isoHoy = new Date().toISOString().slice(0,10);
 const fechaProd = (btn.getAttribute('data-producto-fecha') || '').trim();
 const esISO = /^\d{4}-\d{2}-\d{2}$/.test(fechaProd) && fechaProd !== '0000-00-00';
 mFecha.value = esISO ? fechaProd : isoHoy;
		  mComInput.value=''; mComId.value='';
		  mErr.style.display='none'; mErr.textContent='';
		  modal.show();
		});
	  });
	}


	// Dentro de document.addEventListener('DOMContentLoaded', ...)
	// REEMPLAZA por completo esta función:
	async function guardarReserva(e){
	  e.preventDefault();
	  mErr.style.display='none'; 
	  mErr.textContent='';

	  const producto_id  = parseInt(mProdId.value||'0',10);
	  const comercial_id = parseInt(mComId.value ||'0',10);
	  const cantidad     = parseInt(mQty.value   ||'0',10);
	  const fecha        = (mFecha.value||'').trim();

	  const errs=[];
	  if(!producto_id)  errs.push('Selecciona un producto válido.');
	  if(!comercial_id) errs.push('Selecciona un comercial/cliente.');
	  if(!cantidad || cantidad<=0) errs.push('Cantidad debe ser > 0.');
	  if(!fecha) errs.push('Selecciona una fecha.');

	  if(errs.length){
		mErr.textContent = errs.join(' | ');
		mErr.style.display='block';
		return;
	  }

	  // >>> NUEVO: tope por restante (evita pedir más de lo disponible)
	  const restanteSel = parseInt(mQty.dataset.restante || '0', 10) || 0;
	  if (cantidad > restanteSel) {
		mErr.textContent = `No se pueden pedir más de ${restanteSel} cajas.`;
		mErr.style.display = 'block';
		return; // detenemos el envío
	  }
	  // <<< FIN NUEVO

	  try{
		const res = await fetch('save_reserva.php', {
		  method:'POST',
		  headers:{'Content-Type':'application/json'},
		  body: JSON.stringify({ comercial_id, producto_id, cantidad, fecha })
		});

		let data=null, serverText='';
		try{ data = await res.json(); }catch(_){ serverText = await res.text(); }

		if(res.ok && data && data.ok){
		  modal.hide();
		  await fetchAndRender();
		}else{
		  const msg = (data && (data.errors||data.error))
			? (Array.isArray(data.errors)? data.errors.join(' | ') : data.error)
			: (serverText || 'No se pudo guardar la reserva.');
		  mErr.textContent = msg;
		  mErr.style.display='block';
		}
	  }catch(e){
		mErr.textContent = 'Error de red.';
		mErr.style.display='block';
		console.error(e);
	  }
	}


  // Inicialización
  initTextFilter();

  // Modal bootstrap + refs
  modal       = new bootstrap.Modal(document.getElementById('modalReserva'));
  mForm       = document.getElementById('formReserva');
  mErr        = document.getElementById('m_error');
  mProdNombre = document.getElementById('m_producto_nombre');
  mProdId     = document.getElementById('m_producto_id');
  mProdList   = document.getElementById('m_producto_list');
  mComInput   = document.getElementById('m_comercial_input');
  mComId      = document.getElementById('m_comercial_id');
  mComList    = document.getElementById('m_comercial_list');
  mQty        = document.getElementById('m_cantidad');
  mFecha      = document.getElementById('m_fecha');

  // Autocomplete
  createAutocomplete({input:mComInput,   hidden:mComId,   list:mComList,   type:'comercial'});
  createAutocomplete({input:mProdNombre, hidden:mProdId,  list:mProdList,  type:'producto'});

  // Submit del modal
  mForm.addEventListener('submit', guardarReserva);

  // Enlaza los botones de la tabla server-side inicial (antes del primer fetch)
  wirePedirButtons(document);

  // Primera carga + auto-refresh
  fetchAndRender();
  setInterval(fetchAndRender, 10000);
});
</script>



<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
