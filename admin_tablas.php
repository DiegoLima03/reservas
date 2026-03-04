<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

require_login();

if (!current_user_is_admin()) {
  http_response_code(403);
  echo 'Acceso restringido a administradores.';
  exit;
}

/**
 * @param mixed $v
 */
function h($v): string
{
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$authUser = current_user();
$embedded = ((string)($_GET['embedded'] ?? '') === '1');
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Gestion integral de tablas</title>
  <link rel="stylesheet" href="style.css">
  <link rel="icon" href="img/logo.png" type="image/png" class="url-logo">
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

    body.admin-tablas {
      margin: 0;
      font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif !important;
      background: var(--vz-crema) !important;
      color: var(--vz-negro);
    }

    body.admin-tablas.embedded {
      background: transparent !important;
    }

    .admin-layout {
      width: 100%;
      margin: 8px 0 12px;
      padding: 0 10px 14px;
    }

    body.admin-tablas.embedded .admin-layout {
      margin: 0;
      padding: 0;
    }

    body.admin-tablas.embedded .card {
      box-shadow: none !important;
    }

    .page-headline {
      margin-bottom: 8px;
      align-items: center;
      gap: 8px;
    }

    .page-title {
      margin: 0;
      font-size: 24px !important;
      color: var(--vz-negro);
    }

    .btn {
      border-radius: 8px;
      font-size: 13px;
      font-weight: 600;
      transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease, border-color 0.2s ease;
    }

    .btn:hover { transform: translateY(-1px); }

    .btn-primary,
    .btn-success {
      background: var(--vz-verde);
      border-color: var(--vz-verde);
      color: var(--vz-crema);
    }

    .btn-primary:hover,
    .btn-success:hover {
      background: #7c792b;
      border-color: #7c792b;
      box-shadow: 0 4px 12px rgba(142, 139, 48, 0.3);
    }

    .btn-outline-secondary,
    .btn-outline-primary {
      border-color: var(--vz-marron2);
      color: var(--vz-marron1);
      background: var(--vz-crema);
    }

    .btn-outline-secondary:hover,
    .btn-outline-primary:hover {
      border-color: var(--vz-marron1);
      background: var(--vz-blanco);
      color: var(--vz-marron1);
      box-shadow: 0 4px 12px rgba(16, 24, 14, 0.14);
    }

    .btn-outline-danger {
      border-color: var(--vz-rojo);
      color: var(--vz-rojo);
      background: var(--vz-blanco);
    }

    .btn-outline-danger:hover {
      border-color: var(--vz-rojo);
      background: var(--vz-rojo);
      color: var(--vz-crema);
      box-shadow: 0 4px 12px rgba(200, 60, 50, 0.24);
    }

    .card {
      background: var(--vz-blanco);
      border: 1px solid var(--vz-marron2);
      border-radius: 12px;
      box-shadow: 0 2px 8px rgba(16, 24, 14, 0.08) !important;
      overflow: hidden;
    }

    .card-body { padding: 14px; }

    .form-control,
    .form-select,
    .input-group-text {
      border: 1px solid var(--vz-marron2);
      border-radius: 8px;
      background: var(--vz-blanco);
      color: var(--vz-negro);
      font-size: 13px;
    }

    .form-control:focus,
    .form-select:focus {
      border-color: var(--vz-verde);
      box-shadow: 0 0 0 0.2rem rgba(142, 139, 48, 0.2);
    }

    .table-responsive {
      border: 1px solid var(--vz-marron2);
      border-radius: 10px;
      background: var(--vz-blanco);
    }

    .table {
      margin-bottom: 0;
      font-size: 14px;
      border-collapse: collapse;
      --bs-table-bg: transparent;
    }

    .table > :not(caption) > * > * {
      border-color: var(--vz-marron2);
      padding: 9px 10px;
      border-bottom-width: 1px;
      vertical-align: middle;
    }

    .table > thead {
      background: var(--vz-verde);
      color: var(--vz-crema);
      text-align: left;
    }

    .table > thead > tr { border-left: 3px solid var(--vz-verde); }
    .table > thead th { border-bottom: none; font-weight: 600; white-space: nowrap; }
    .table-bordered > :not(caption) > * > * { border-width: 0 0 1px; }
    .table tbody tr { border-left: 3px solid transparent; transition: border-left 0.2s ease, background 0.2s ease; }
    .table tbody tr:hover { border-left: 3px solid var(--vz-verde); background: var(--vz-verde-suave); }
    .table-sticky thead th { position: sticky; top: 0; background: var(--vz-verde) !important; color: var(--vz-crema) !important; z-index: 2; }

    .cell-actions { width: 146px; }
    .cell-actions .btn { min-width: 64px; }

    .badge-soft {
      background: #f7f3eb;
      color: var(--vz-marron1);
      border: 1px solid #e0d5c8;
      border-radius: 999px;
      padding: 2px 8px;
      font-size: 11px;
      font-weight: 600;
    }

    .modal-content {
      border: 1px solid var(--vz-marron2);
      border-radius: 14px;
      overflow: hidden;
      box-shadow: 0 14px 30px rgba(16, 24, 14, 0.24);
    }

    .modal-header {
      border-bottom: 1px solid rgba(255, 255, 255, 0.2);
      background: var(--vz-verde);
      color: var(--vz-crema);
    }

    .modal-header .btn-close {
      filter: invert(1) grayscale(1);
      opacity: 0.88;
    }

    @media (max-width: 991.98px) {
      .page-headline { align-items: flex-start; }
      .cell-actions { width: 132px; }
    }
  </style>
</head>
<body class="admin-tablas<?= $embedded ? ' embedded' : '' ?>">
<div class="container-fluid py-3 admin-layout">
  <?php if (!$embedded): ?>
  <div class="d-flex align-items-center justify-content-between page-headline">
    <div>
      <h1 class="h4 mb-0 page-title">Gestion integral de tablas</h1>
      <div class="small text-muted mt-1">Administrador: <?= h($authUser['username'] ?? 'admin') ?></div>
    </div>
    <div class="d-flex gap-2">
      <a href="gestion_compras.php" class="btn btn-sm btn-outline-secondary">Volver a compras</a>
      <a href="logout.php" class="btn btn-sm btn-outline-danger">Cerrar sesion</a>
    </div>
  </div>
  <?php endif; ?>

  <div class="card shadow-sm mb-3">
    <div class="card-body">
      <div class="row g-2 align-items-end">
        <div class="col-lg-3 col-md-4">
          <label for="adm_table" class="form-label mb-1 small text-muted"><b>Tabla</b></label>
          <select id="adm_table" class="form-select form-select-sm"></select>
        </div>
        <div class="col-lg-4 col-md-4">
          <label for="adm_q" class="form-label mb-1 small text-muted"><b>Buscar</b></label>
          <input type="text" id="adm_q" class="form-control form-control-sm" placeholder="Texto libre en columnas">
        </div>
        <div class="col-lg-2 col-md-2">
          <label for="adm_limit" class="form-label mb-1 small text-muted"><b>Limite filas</b></label>
          <select id="adm_limit" class="form-select form-select-sm">
            <option value="50">50</option>
            <option value="100" selected>100</option>
            <option value="200">200</option>
          </select>
        </div>
        <div class="col-lg-3 col-md-2 d-flex gap-2 justify-content-md-end">
          <button type="button" id="adm_refresh" class="btn btn-sm btn-outline-primary">Recargar</button>
          <button type="button" id="adm_new" class="btn btn-sm btn-success">Nueva fila</button>
        </div>
      </div>
      <div id="adm_status" class="small text-muted mt-2"></div>
      <div id="adm_error" class="small text-danger mt-2" style="display:none;"></div>
    </div>
  </div>

  <div class="card shadow-sm">
    <div class="card-body">
      <div class="table-responsive" style="max-height:70vh;">
        <table class="table table-striped table-bordered table-sm align-middle table-sticky" id="adm_grid">
          <thead>
            <tr>
              <th>Tabla</th>
            </tr>
          </thead>
          <tbody>
            <tr><td class="text-muted">Cargando menu de tablas...</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="adm_modal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <form id="adm_form" autocomplete="off">
        <div class="modal-header">
          <h5 class="modal-title" id="adm_modal_title">Editar fila</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body">
          <div id="adm_form_fields" class="row g-3"></div>
          <div id="adm_form_error" class="small text-danger mt-3" style="display:none;"></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-sm btn-success">Guardar cambios</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
(() => {
  'use strict';

  const API_URL = 'api_admin_tablas.php';
  const state = {
    tables: [],
    table: '',
    tableLabel: '',
    schema: null,
    rows: [],
    total: 0,
    limit: 100,
    query: ''
  };

  const els = {
    tableSelect: document.getElementById('adm_table'),
    q: document.getElementById('adm_q'),
    limit: document.getElementById('adm_limit'),
    refresh: document.getElementById('adm_refresh'),
    newBtn: document.getElementById('adm_new'),
    status: document.getElementById('adm_status'),
    error: document.getElementById('adm_error'),
    grid: document.getElementById('adm_grid'),
    modalEl: document.getElementById('adm_modal'),
    modalTitle: document.getElementById('adm_modal_title'),
    form: document.getElementById('adm_form'),
    formFields: document.getElementById('adm_form_fields'),
    formError: document.getElementById('adm_form_error')
  };

  const modal = new bootstrap.Modal(els.modalEl);
  let searchTimer = null;

  function escapeHtml(v) {
    return String(v ?? '')
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }

  function setError(msg) {
    const text = (msg || '').trim();
    if (!text) {
      els.error.style.display = 'none';
      els.error.textContent = '';
      return;
    }
    els.error.textContent = text;
    els.error.style.display = 'block';
  }

  function setStatus(msg) {
    els.status.textContent = msg || '';
  }

  async function api(action, payload = {}) {
    const res = await fetch(API_URL, {
      method: 'POST',
      headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
      body: JSON.stringify(Object.assign({action}, payload))
    });
    let data = null;
    let txt = '';
    try {
      data = await res.json();
    } catch (_) {
      txt = await res.text();
    }
    if (!res.ok || !data || data.ok !== true) {
      const msg = (data && (data.error || data.errors))
        ? (Array.isArray(data.errors) ? data.errors.join(' | ') : data.error)
        : (txt || ('Error HTTP ' + res.status));
      throw new Error(msg);
    }
    return data;
  }

  function tableByName(name) {
    return state.tables.find(t => t.name === name) || null;
  }

  function visibleColumns() {
    if (!state.schema || !Array.isArray(state.schema.columns)) return [];
    return state.schema.columns.filter(col => !col.hidden_in_list);
  }

  function boolText(v) {
    return Number(v) === 1 ? 'Si' : 'No';
  }

  function renderValue(value, col) {
    if (value === null || value === '') {
      return '<span class="badge-soft">NULL</span>';
    }
    if (col && col.is_boolean) {
      return escapeHtml(boolText(value));
    }
    return escapeHtml(value);
  }

  function getPkColumn() {
    return state.schema && state.schema.primary_column ? state.schema.primary_column : null;
  }

  function writeEnabled() {
    return !!(state.schema && state.schema.write_enabled && getPkColumn());
  }

  function renderGrid() {
    const cols = visibleColumns();
    const canWrite = writeEnabled();

    const theadCols = [];
    for (const col of cols) {
      theadCols.push('<th>' + escapeHtml(col.name) + '</th>');
    }
    if (canWrite) {
      theadCols.push('<th class="text-center cell-actions">Acciones</th>');
    }

    const bodyRows = [];
    if (!state.rows.length) {
      const colspan = cols.length + (canWrite ? 1 : 0);
      bodyRows.push('<tr><td colspan="' + colspan + '" class="text-center text-muted">No hay filas.</td></tr>');
    } else {
      state.rows.forEach((row, idx) => {
        const tds = [];
        for (const col of cols) {
          tds.push('<td>' + renderValue(row[col.name], col) + '</td>');
        }
        if (canWrite) {
          tds.push(
            '<td class="text-center cell-actions">' +
              '<div class="d-inline-flex gap-1">' +
                '<button type="button" class="btn btn-sm btn-outline-primary js-edit" data-row-index="' + idx + '">Editar</button>' +
                '<button type="button" class="btn btn-sm btn-outline-danger js-del" data-row-index="' + idx + '">Eliminar</button>' +
              '</div>' +
            '</td>'
          );
        }
        bodyRows.push('<tr>' + tds.join('') + '</tr>');
      });
    }

    els.grid.innerHTML =
      '<thead><tr>' + theadCols.join('') + '</tr></thead>' +
      '<tbody>' + bodyRows.join('') + '</tbody>';
  }

  function toDateTimeLocal(value) {
    const v = String(value || '').trim();
    if (!v) return '';
    const m = v.match(/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})/);
    if (m) return m[1] + 'T' + m[2];
    return '';
  }

  function resolveInputKind(col) {
    if (state.table === 'usuarios' && col.name === 'pass') return 'password';
    if (Array.isArray(col.enum_options) && col.enum_options.length) return 'enum';
    if (col.is_boolean) return 'boolean';
    if (['text', 'mediumtext', 'longtext'].includes(col.base_type)) return 'textarea';
    if (col.base_type === 'date') return 'date';
    if (col.base_type === 'datetime' || col.base_type === 'timestamp') return 'datetime-local';
    if (['tinyint', 'smallint', 'mediumint', 'int', 'bigint', 'decimal', 'float', 'double'].includes(col.base_type)) {
      return 'number';
    }
    return 'text';
  }

  function createField(col, value, isUpdate) {
    const wrap = document.createElement('div');
    wrap.className = 'col-md-6';

    const inputKind = resolveInputKind(col);
    const isPk = !!col.is_primary;
    const isReadonly = !!col.readonly;
    const isAuto = !!col.is_auto_increment;
    const required = !col.nullable && !isReadonly && !(isAuto && !isUpdate);
    const isDisabled = isReadonly || (isUpdate && isPk);

    const label = document.createElement('label');
    label.className = 'form-label mb-1';
    label.innerHTML = '<b>' + escapeHtml(col.name) + '</b>' + (isPk ? ' <span class="badge-soft">PK</span>' : '');
    wrap.appendChild(label);

    let input;
    if (inputKind === 'textarea') {
      input = document.createElement('textarea');
      input.className = 'form-control form-control-sm';
      input.rows = 3;
      input.value = value == null ? '' : String(value);
    } else if (inputKind === 'enum') {
      input = document.createElement('select');
      input.className = 'form-select form-select-sm';
      const optNull = document.createElement('option');
      optNull.value = '';
      optNull.textContent = col.nullable ? '(NULL)' : '-- Selecciona --';
      input.appendChild(optNull);
      for (const opt of col.enum_options) {
        const o = document.createElement('option');
        o.value = String(opt);
        o.textContent = String(opt);
        input.appendChild(o);
      }
      if (value != null) {
        input.value = String(value);
      }
    } else if (inputKind === 'boolean') {
      const ckWrap = document.createElement('div');
      ckWrap.className = 'form-check mt-1';
      const ck = document.createElement('input');
      ck.type = 'checkbox';
      ck.className = 'form-check-input';
      ck.checked = Number(value || 0) === 1;
      ck.id = 'fld_' + col.name;
      ck.dataset.column = col.name;
      ck.dataset.kind = inputKind;
      ck.dataset.nullable = col.nullable ? '1' : '0';
      if (isDisabled) ck.disabled = true;
      const ckLabel = document.createElement('label');
      ckLabel.className = 'form-check-label';
      ckLabel.setAttribute('for', ck.id);
      ckLabel.textContent = 'Activo';
      ckWrap.appendChild(ck);
      ckWrap.appendChild(ckLabel);
      wrap.appendChild(ckWrap);
      return wrap;
    } else {
      input = document.createElement('input');
      input.className = 'form-control form-control-sm';
      input.type = inputKind === 'password' ? 'password' : inputKind;
      if (inputKind === 'date') {
        input.value = value ? String(value).slice(0, 10) : '';
      } else if (inputKind === 'datetime-local') {
        input.value = toDateTimeLocal(value);
      } else {
        input.value = value == null ? '' : String(value);
      }

      if (inputKind === 'number') {
        if (['decimal', 'float', 'double'].includes(col.base_type)) {
          input.step = 'any';
        } else {
          input.step = '1';
        }
        if (String(col.type || '').includes('unsigned')) {
          input.min = '0';
        }
      }
      if (inputKind === 'password' && isUpdate) {
        input.placeholder = 'Dejar vacio para no cambiar';
      }
    }

    input.dataset.column = col.name;
    input.dataset.kind = inputKind;
    input.dataset.nullable = col.nullable ? '1' : '0';
    input.dataset.pk = isPk ? '1' : '0';
    if (required && !isDisabled && (inputKind !== 'password' || !isUpdate)) input.required = true;
    if (isDisabled) input.disabled = true;

    wrap.appendChild(input);
    return wrap;
  }

  function clearFormError() {
    els.formError.style.display = 'none';
    els.formError.textContent = '';
  }

  function setFormError(msg) {
    const text = (msg || '').trim();
    if (!text) {
      clearFormError();
      return;
    }
    els.formError.textContent = text;
    els.formError.style.display = 'block';
  }

  function openEditor(row) {
    const isUpdate = !!row;
    const pk = getPkColumn();
    if (!state.schema || !Array.isArray(state.schema.columns)) return;

    clearFormError();
    els.formFields.innerHTML = '';
    els.modalTitle.textContent = (isUpdate ? 'Editar' : 'Nueva') + ' fila: ' + state.tableLabel;
    els.form.dataset.mode = isUpdate ? 'update' : 'insert';
    els.form.dataset.pk = pk || '';
    els.form.dataset.pkValue = isUpdate && pk ? String(row[pk] ?? '') : '';

    for (const col of state.schema.columns) {
      if (!isUpdate && (col.is_auto_increment || col.readonly)) {
        continue;
      }
      const field = createField(col, isUpdate ? row[col.name] : (col.default ?? null), isUpdate);
      els.formFields.appendChild(field);
    }

    modal.show();
  }

  function collectFormRow() {
    const row = {};
    const fields = els.formFields.querySelectorAll('[data-column]');

    fields.forEach((el) => {
      if (!(el instanceof HTMLInputElement || el instanceof HTMLTextAreaElement || el instanceof HTMLSelectElement)) return;
      if (el.disabled) return;

      const name = el.dataset.column || '';
      if (!name) return;

      const kind = el.dataset.kind || 'text';
      const nullable = el.dataset.nullable === '1';
      let value;

      if (kind === 'boolean') {
        value = el.checked ? 1 : 0;
      } else {
        value = el.value;
        if (typeof value === 'string') value = value.trim();
        if (kind === 'datetime-local' && typeof value === 'string' && value !== '') {
          // El backend convierte "T" a formato SQL.
        }
        if (value === '' && nullable) {
          value = null;
        }
      }

      row[name] = value;
    });

    if (els.form.dataset.mode === 'update') {
      const pk = els.form.dataset.pk || '';
      const pkValue = els.form.dataset.pkValue || '';
      if (pk && pkValue !== '') {
        row[pk] = pkValue;
      }
    }

    return row;
  }

  async function loadMeta() {
    setError('');
    setStatus('Cargando tablas...');
    const data = await api('meta');
    state.tables = Array.isArray(data.tables) ? data.tables : [];
    els.tableSelect.innerHTML = '';
    for (const tbl of state.tables) {
      const op = document.createElement('option');
      op.value = tbl.name;
      op.textContent = tbl.label + ' (' + tbl.name + ')';
      els.tableSelect.appendChild(op);
    }
    if (!state.tables.length) {
      throw new Error('No hay tablas gestionables configuradas.');
    }
    state.table = state.tables[0].name;
    state.tableLabel = state.tables[0].label;
    els.tableSelect.value = state.table;
  }

  async function loadTableData() {
    if (!state.table) return;
    setError('');
    setStatus('Cargando datos de ' + state.table + '...');
    state.query = (els.q.value || '').trim();
    state.limit = parseInt(els.limit.value || '100', 10) || 100;

    const data = await api('table_data', {
      table: state.table,
      q: state.query,
      limit: state.limit
    });

    state.schema = data.schema || null;
    state.rows = (data.data && Array.isArray(data.data.rows)) ? data.data.rows : [];
    state.total = (data.data && Number.isFinite(Number(data.data.total))) ? Number(data.data.total) : state.rows.length;
    state.tableLabel = data.table_label || (tableByName(state.table)?.label ?? state.table);

    renderGrid();
    const trunc = data.data && data.data.truncated ? ' (mostrando solo el limite)' : '';
    setStatus(
      state.tableLabel + ': ' +
      state.rows.length + ' fila(s) visibles de ' + state.total + trunc
    );
    els.newBtn.disabled = !writeEnabled();
  }

  async function refreshTable() {
    try {
      await loadTableData();
    } catch (e) {
      console.error(e);
      setError(e instanceof Error ? e.message : 'No se pudieron cargar los datos.');
      setStatus('');
    }
  }

  els.tableSelect.addEventListener('change', () => {
    state.table = els.tableSelect.value;
    const tbl = tableByName(state.table);
    state.tableLabel = tbl ? tbl.label : state.table;
    refreshTable();
  });

  els.limit.addEventListener('change', () => {
    refreshTable();
  });

  els.q.addEventListener('input', () => {
    if (searchTimer) window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => {
      refreshTable();
    }, 220);
  });

  els.refresh.addEventListener('click', () => {
    refreshTable();
  });

  els.newBtn.addEventListener('click', () => {
    if (!writeEnabled()) return;
    openEditor(null);
  });

  els.grid.addEventListener('click', async (e) => {
    const btn = e.target instanceof HTMLElement ? e.target.closest('button') : null;
    if (!btn) return;
    const rowIdx = parseInt(btn.getAttribute('data-row-index') || '-1', 10);
    if (!Number.isInteger(rowIdx) || rowIdx < 0 || rowIdx >= state.rows.length) return;
    const row = state.rows[rowIdx];
    if (!row) return;

    if (btn.classList.contains('js-edit')) {
      openEditor(row);
      return;
    }

    if (btn.classList.contains('js-del')) {
      const pk = getPkColumn();
      if (!pk) return;
      const pkValue = row[pk];
      const ok = window.confirm('¿Eliminar fila ' + pk + '=' + pkValue + ' de ' + state.table + '?');
      if (!ok) return;
      setError('');
      try {
        await api('delete', {table: state.table, pk_value: pkValue});
        await refreshTable();
      } catch (err) {
        console.error(err);
        setError(err instanceof Error ? err.message : 'No se pudo eliminar la fila.');
      }
    }
  });

  els.form.addEventListener('submit', async (e) => {
    e.preventDefault();
    clearFormError();
    setError('');

    try {
      const row = collectFormRow();
      await api('save', {table: state.table, row});
      modal.hide();
      await refreshTable();
    } catch (err) {
      console.error(err);
      setFormError(err instanceof Error ? err.message : 'No se pudo guardar.');
    }
  });

  (async () => {
    try {
      await loadMeta();
      await loadTableData();
    } catch (e) {
      console.error(e);
      setError(e instanceof Error ? e.message : 'No se pudo inicializar la gestion de tablas.');
      setStatus('');
      els.newBtn.disabled = true;
    }
  })();
})();
</script>
</body>
</html>
