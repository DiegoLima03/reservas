<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

require_login_json();

header('Content-Type: application/json; charset=UTF-8');

if (!current_user_is_admin()) {
  http_response_code(403);
  echo json_encode(
    ['ok' => false, 'error' => 'No tienes permisos de administrador.'],
    JSON_UNESCAPED_UNICODE
  );
  exit;
}

/**
 * @return array<string, array<string, mixed>>
 */
function admin_tables_config(): array
{
  return [
    'tipos_producto' => [
      'label' => 'Tipos de producto',
      'hidden_list' => [],
      'readonly_columns' => ['created_at'],
    ],
    'productos' => [
      'label' => 'Productos',
      'hidden_list' => [],
      'readonly_columns' => ['created_at'],
    ],
    'proveedores' => [
      'label' => 'Proveedores',
      'hidden_list' => [],
      'readonly_columns' => ['created_at'],
    ],
    'delegaciones' => [
      'label' => 'Delegaciones',
      'hidden_list' => [],
      'readonly_columns' => ['created_at'],
    ],
    'usuarios' => [
      'label' => 'Usuarios',
      'hidden_list' => ['pass'],
      'readonly_columns' => [],
    ],
    'pre_reservas' => [
      'label' => 'Pre-reservas',
      'hidden_list' => [],
      'readonly_columns' => [],
    ],
    'compras_stock' => [
      'label' => 'Compras stock',
      'hidden_list' => [],
      'readonly_columns' => ['created_at'],
    ],
    'asignaciones' => [
      'label' => 'Asignaciones',
      'hidden_list' => [],
      'readonly_columns' => ['created_at'],
    ],
    'asignacion_lotes' => [
      'label' => 'Asignacion lotes',
      'hidden_list' => [],
      'readonly_columns' => ['created_at'],
    ],
    'reservas' => [
      'label' => 'Reservas',
      'hidden_list' => [],
      'readonly_columns' => ['created_at'],
    ],
  ];
}

/**
 * @param int $status
 * @param array<string, mixed> $payload
 */
function json_out(int $status, array $payload): void
{
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

/**
 * @return array<string, mixed>
 */
function read_input(): array
{
  $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
  $input = $method === 'GET' ? $_GET : $_POST;

  if ($method !== 'GET' && empty($input)) {
    $raw = file_get_contents('php://input');
    if ($raw) {
      $decoded = json_decode($raw, true);
      if (is_array($decoded)) {
        $input = $decoded;
      }
    }
  }

  return is_array($input) ? $input : [];
}

function quote_ident(string $name): string
{
  if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
    throw new RuntimeException('Identificador invalido.');
  }
  return '`' . $name . '`';
}

/**
 * @param PDO $pdo
 * @param array<string, array<string, mixed>> $config
 * @return array<string, array<string, mixed>>
 */
function existing_admin_tables(PDO $pdo, array $config): array
{
  $existing = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
  $lookup = [];
  foreach ($existing as $tableName) {
    if (is_string($tableName) && $tableName !== '') {
      $lookup[$tableName] = true;
    }
  }

  $out = [];
  foreach ($config as $table => $meta) {
    if (isset($lookup[$table])) {
      $out[$table] = $meta;
    }
  }
  return $out;
}

/**
 * @param string $sqlType
 * @return array<int, string>
 */
function parse_enum_options(string $sqlType): array
{
  if (!preg_match('/^enum\((.*)\)$/i', $sqlType, $m)) {
    return [];
  }
  $inside = (string)$m[1];
  if ($inside === '') {
    return [];
  }

  $opts = [];
  if (preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $inside, $matches)) {
    foreach ($matches[1] as $opt) {
      $opts[] = stripcslashes((string)$opt);
    }
  }
  return $opts;
}

/**
 * @param string $baseType
 */
function is_searchable_base_type(string $baseType): bool
{
  $allowed = [
    'char', 'varchar', 'text', 'mediumtext', 'longtext',
    'tinyint', 'smallint', 'mediumint', 'int', 'bigint',
    'decimal', 'float', 'double',
    'date', 'datetime', 'timestamp', 'time', 'year',
    'enum', 'set',
  ];
  return in_array($baseType, $allowed, true);
}

/**
 * @param PDO $pdo
 * @param string $table
 * @param array<string, mixed> $tableCfg
 * @return array<string, mixed>
 */
function describe_table(PDO $pdo, string $table, array $tableCfg): array
{
  $hiddenList = [];
  if (isset($tableCfg['hidden_list']) && is_array($tableCfg['hidden_list'])) {
    foreach ($tableCfg['hidden_list'] as $name) {
      if (is_string($name)) {
        $hiddenList[$name] = true;
      }
    }
  }

  $readonlyColumns = [];
  if (isset($tableCfg['readonly_columns']) && is_array($tableCfg['readonly_columns'])) {
    foreach ($tableCfg['readonly_columns'] as $name) {
      if (is_string($name)) {
        $readonlyColumns[$name] = true;
      }
    }
  }

  $sql = 'SHOW COLUMNS FROM ' . quote_ident($table);
  $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
  if (!$rows) {
    throw new RuntimeException('No se pudo leer el esquema de la tabla.');
  }

  $columns = [];
  $primaryColumns = [];
  foreach ($rows as $row) {
    $field = (string)($row['Field'] ?? '');
    if ($field === '') {
      continue;
    }
    $sqlType = strtolower((string)($row['Type'] ?? ''));
    $baseType = $sqlType;
    if (($pos = strpos($baseType, '(')) !== false) {
      $baseType = substr($baseType, 0, $pos);
    }
    if (($pos2 = strpos($baseType, ' ')) !== false) {
      $baseType = substr($baseType, 0, $pos2);
    }
    $nullable = strtoupper((string)($row['Null'] ?? 'NO')) === 'YES';
    $key = strtoupper((string)($row['Key'] ?? ''));
    $extra = strtolower((string)($row['Extra'] ?? ''));
    $isPrimary = $key === 'PRI';
    $isAutoIncrement = strpos($extra, 'auto_increment') !== false;
    $isBoolean = $baseType === 'tinyint' && preg_match('/^tinyint\(1\)/i', $sqlType) === 1;
    $enumOptions = parse_enum_options($sqlType);
    $searchable = is_searchable_base_type($baseType);

    if ($isPrimary) {
      $primaryColumns[] = $field;
    }

    $columns[] = [
      'name' => $field,
      'type' => $sqlType,
      'base_type' => $baseType,
      'nullable' => $nullable,
      'default' => $row['Default'] ?? null,
      'extra' => $extra,
      'is_primary' => $isPrimary,
      'is_auto_increment' => $isAutoIncrement,
      'is_boolean' => $isBoolean,
      'enum_options' => $enumOptions,
      'searchable' => $searchable,
      'hidden_in_list' => isset($hiddenList[$field]),
      'readonly' => isset($readonlyColumns[$field]),
    ];
  }

  return [
    'columns' => $columns,
    'primary_columns' => $primaryColumns,
    'primary_column' => count($primaryColumns) === 1 ? $primaryColumns[0] : null,
    'write_enabled' => count($primaryColumns) === 1,
  ];
}

/**
 * @param array<int, array<string, mixed>> $columns
 * @return array<string, array<string, mixed>>
 */
function column_map(array $columns): array
{
  $out = [];
  foreach ($columns as $col) {
    $name = (string)($col['name'] ?? '');
    if ($name !== '') {
      $out[$name] = $col;
    }
  }
  return $out;
}

/**
 * @param mixed $value
 * @param array<string, mixed> $column
 * @param bool $isUpdate
 * @param string $table
 * @return array{include: bool, value: mixed}
 */
function normalize_value($value, array $column, bool $isUpdate, string $table): array
{
  $name = (string)($column['name'] ?? '');
  $nullable = (bool)($column['nullable'] ?? false);
  $isBoolean = (bool)($column['is_boolean'] ?? false);
  $baseType = (string)($column['base_type'] ?? '');

  if (is_string($value)) {
    $value = trim($value);
  }

  if ($table === 'usuarios' && $name === 'pass') {
    $pass = (string)$value;
    if ($isUpdate && $pass === '') {
      return ['include' => false, 'value' => null];
    }
    if ($pass !== '' && preg_match('/^\$(2y|2a|2b|argon2i|argon2id)\$/', $pass) !== 1) {
      $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
      if (!is_string($hash) || $hash === '') {
        throw new RuntimeException('No se pudo generar hash para la clave del usuario.');
      }
      $value = $hash;
    }
  }

  if ($isBoolean) {
    if (is_string($value) && $value === '') {
      $value = 0;
    }
    if (is_bool($value)) {
      $value = $value ? 1 : 0;
    } else {
      $value = ((int)$value === 1) ? 1 : 0;
    }
  } elseif ($value === '' && $nullable) {
    $value = null;
  }

  if (is_string($value) && ($baseType === 'datetime' || $baseType === 'timestamp')) {
    // Acepta formato HTML datetime-local.
    if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value)) {
      $value = str_replace('T', ' ', $value) . ':00';
    }
  }

  return ['include' => true, 'value' => $value];
}

/**
 * @param PDO $pdo
 * @param string $table
 * @param array<string, mixed> $schema
 * @param string $query
 * @param int $limit
 * @return array<string, mixed>
 */
function fetch_table_data(PDO $pdo, string $table, array $schema, string $query, int $limit): array
{
  $columns = is_array($schema['columns'] ?? null) ? $schema['columns'] : [];
  if (!$columns) {
    throw new RuntimeException('No hay columnas para listar.');
  }

  $columnNames = [];
  $searchColumns = [];
  foreach ($columns as $col) {
    if (!is_array($col)) {
      continue;
    }
    $name = (string)($col['name'] ?? '');
    if ($name === '') {
      continue;
    }
    $columnNames[] = $name;
    if (!empty($col['searchable'])) {
      $searchColumns[] = $name;
    }
  }

  if (!$columnNames) {
    throw new RuntimeException('No hay columnas validas para listar.');
  }

  $qTable = quote_ident($table);
  $params = [];
  $where = '';
  if ($query !== '' && $searchColumns) {
    $parts = [];
    foreach ($searchColumns as $idx => $colName) {
      $param = ':q' . $idx;
      $parts[] = 'CAST(' . quote_ident($colName) . ' AS CHAR) LIKE ' . $param;
      $params[$param] = '%' . $query . '%';
    }
    if ($parts) {
      $where = ' WHERE ' . implode(' OR ', $parts);
    }
  }

  $countSql = 'SELECT COUNT(*) AS total FROM ' . $qTable . $where;
  $stCount = $pdo->prepare($countSql);
  $stCount->execute($params);
  $total = (int)$stCount->fetchColumn();

  $selectCols = [];
  foreach ($columnNames as $name) {
    $selectCols[] = quote_ident($name);
  }

  $primaryColumn = (string)($schema['primary_column'] ?? '');
  $orderBy = $primaryColumn !== ''
    ? ' ORDER BY ' . quote_ident($primaryColumn) . ' DESC'
    : '';

  $sql = 'SELECT ' . implode(', ', $selectCols)
       . ' FROM ' . $qTable
       . $where
       . $orderBy
       . ' LIMIT ' . (int)$limit;

  $stRows = $pdo->prepare($sql);
  $stRows->execute($params);
  $rows = $stRows->fetchAll(PDO::FETCH_ASSOC);

  return [
    'rows' => $rows,
    'total' => $total,
    'limit' => $limit,
    'truncated' => $total > count($rows),
  ];
}

/**
 * @param PDO $pdo
 * @param string $table
 * @param array<string, mixed> $schema
 * @param mixed $pkValue
 * @return array<string, mixed>|null
 */
function fetch_one_by_pk(PDO $pdo, string $table, array $schema, $pkValue): ?array
{
  $columns = is_array($schema['columns'] ?? null) ? $schema['columns'] : [];
  $pk = (string)($schema['primary_column'] ?? '');
  if (!$columns || $pk === '') {
    return null;
  }

  $selectCols = [];
  foreach ($columns as $col) {
    if (!is_array($col)) {
      continue;
    }
    $name = (string)($col['name'] ?? '');
    if ($name !== '') {
      $selectCols[] = quote_ident($name);
    }
  }
  if (!$selectCols) {
    return null;
  }

  $sql = 'SELECT ' . implode(', ', $selectCols)
       . ' FROM ' . quote_ident($table)
       . ' WHERE ' . quote_ident($pk) . ' = :pk LIMIT 1';
  $st = $pdo->prepare($sql);
  $st->execute([':pk' => $pkValue]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return is_array($row) ? $row : null;
}

try {
  $pdo = pdo();
  $config = admin_tables_config();
  $tables = existing_admin_tables($pdo, $config);
  $input = read_input();
  $action = trim((string)($input['action'] ?? 'meta'));

  if ($action === 'meta') {
    $list = [];
    foreach ($tables as $name => $meta) {
      $list[] = [
        'name' => $name,
        'label' => (string)($meta['label'] ?? $name),
      ];
    }
    json_out(200, ['ok' => true, 'tables' => $list]);
  }

  $table = trim((string)($input['table'] ?? ''));
  if ($table === '' || !isset($tables[$table])) {
    json_out(422, ['ok' => false, 'error' => 'Tabla no permitida o inexistente.']);
  }

  $tableCfg = $tables[$table];
  $schema = describe_table($pdo, $table, $tableCfg);
  $pk = (string)($schema['primary_column'] ?? '');

  if ($action === 'table_data') {
    $q = trim((string)($input['q'] ?? ''));
    if (mb_strlen($q, 'UTF-8') > 120) {
      $q = mb_substr($q, 0, 120, 'UTF-8');
    }
    $limit = (int)($input['limit'] ?? 100);
    if ($limit < 1) {
      $limit = 1;
    } elseif ($limit > 250) {
      $limit = 250;
    }

    $data = fetch_table_data($pdo, $table, $schema, $q, $limit);
    json_out(200, [
      'ok' => true,
      'table' => $table,
      'table_label' => (string)($tableCfg['label'] ?? $table),
      'schema' => $schema,
      'data' => $data,
    ]);
  }

  if ($action === 'save') {
    if (empty($schema['write_enabled'])) {
      json_out(422, ['ok' => false, 'error' => 'La tabla no admite escritura en modo generico.']);
    }

    $row = $input['row'] ?? null;
    if (!is_array($row)) {
      json_out(422, ['ok' => false, 'error' => 'Falta el objeto row con los datos de la fila.']);
    }

    $columns = is_array($schema['columns'] ?? null) ? $schema['columns'] : [];
    $colMap = column_map($columns);
    if (!$colMap) {
      json_out(422, ['ok' => false, 'error' => 'No se pudo interpretar el esquema de la tabla.']);
    }
    if ($pk === '' || !isset($colMap[$pk])) {
      json_out(422, ['ok' => false, 'error' => 'No hay clave primaria valida para guardar.']);
    }

    $pkValue = $row[$pk] ?? null;
    $isUpdate = $pkValue !== null && $pkValue !== '';
    $payload = [];
    $errors = [];

    foreach ($colMap as $name => $metaCol) {
      $isPk = !empty($metaCol['is_primary']);
      $isAuto = !empty($metaCol['is_auto_increment']);
      $isReadonly = !empty($metaCol['readonly']);

      if ($isReadonly) {
        continue;
      }
      if ($isUpdate && $isPk) {
        continue;
      }
      if (!$isUpdate && $isAuto) {
        // Permite inserts sin id explicito.
        continue;
      }

      if (!array_key_exists($name, $row)) {
        continue;
      }

      $rawValue = $row[$name];
      $normalized = normalize_value($rawValue, $metaCol, $isUpdate, $table);
      if (!$normalized['include']) {
        continue;
      }

      $value = $normalized['value'];
      $nullable = (bool)($metaCol['nullable'] ?? false);
      $hasDefault = array_key_exists('default', $metaCol) && $metaCol['default'] !== null;
      $baseType = (string)($metaCol['base_type'] ?? '');
      $isBoolean = (bool)($metaCol['is_boolean'] ?? false);

      if ($value === '' && !$nullable && !$hasDefault && !$isBoolean) {
        $errors[] = "El campo {$name} es obligatorio.";
      } elseif ($value === '' && !$nullable && $baseType !== 'text') {
        $errors[] = "El campo {$name} no puede ir vacio.";
      }

      $payload[$name] = $value;
    }

    if ($errors) {
      json_out(422, ['ok' => false, 'errors' => $errors]);
    }

    if (!$isUpdate) {
      // Validar obligatorios minimos para INSERT.
      foreach ($colMap as $name => $metaCol) {
        $isPk = !empty($metaCol['is_primary']);
        $isAuto = !empty($metaCol['is_auto_increment']);
        $isReadonly = !empty($metaCol['readonly']);
        $nullable = (bool)($metaCol['nullable'] ?? false);
        $hasDefault = array_key_exists('default', $metaCol) && $metaCol['default'] !== null;
        $isBoolean = (bool)($metaCol['is_boolean'] ?? false);

        if ($isReadonly || $isAuto || $isPk) {
          continue;
        }
        if ($nullable || $hasDefault) {
          continue;
        }
        if (!array_key_exists($name, $payload)) {
          if ($table === 'usuarios' && $name === 'pass') {
            $errors[] = 'La clave del usuario es obligatoria en altas.';
          } elseif (!$isBoolean) {
            $errors[] = "Falta el campo obligatorio {$name}.";
          }
        }
      }
    }

    if ($errors) {
      json_out(422, ['ok' => false, 'errors' => $errors]);
    }

    if ($table === 'usuarios' && $isUpdate) {
      $currentId = (int)(current_user()['id'] ?? 0);
      $targetId = (int)$pkValue;
      if ($currentId > 0 && $targetId === $currentId) {
        if (isset($payload['bloqueado']) && (int)$payload['bloqueado'] === 1) {
          json_out(422, ['ok' => false, 'error' => 'No puedes bloquear tu propio usuario.']);
        }
        if (isset($payload['es_admin']) && (int)$payload['es_admin'] !== 1) {
          json_out(422, ['ok' => false, 'error' => 'No puedes quitarte permisos de administrador.']);
        }
      }
    }

    if ($isUpdate) {
      if (!$payload) {
        json_out(422, ['ok' => false, 'error' => 'No hay cambios para guardar.']);
      }

      $setSql = [];
      $params = [':pk' => $pkValue];
      $i = 0;
      foreach ($payload as $colName => $value) {
        $param = ':v' . $i++;
        $setSql[] = quote_ident($colName) . ' = ' . $param;
        $params[$param] = $value;
      }

      $sql = 'UPDATE ' . quote_ident($table)
           . ' SET ' . implode(', ', $setSql)
           . ' WHERE ' . quote_ident($pk) . ' = :pk LIMIT 1';
      $st = $pdo->prepare($sql);
      $st->execute($params);

      $saved = fetch_one_by_pk($pdo, $table, $schema, $pkValue);
      json_out(200, [
        'ok' => true,
        'mode' => 'update',
        'table' => $table,
        'pk' => $pk,
        'pk_value' => $pkValue,
        'row' => $saved,
      ]);
    }

    if (!$payload) {
      json_out(422, ['ok' => false, 'error' => 'No hay campos validos para insertar.']);
    }

    $cols = array_keys($payload);
    $colsSql = [];
    $valsSql = [];
    $params = [];
    foreach ($cols as $idx => $colName) {
      $param = ':v' . $idx;
      $colsSql[] = quote_ident($colName);
      $valsSql[] = $param;
      $params[$param] = $payload[$colName];
    }

    $sql = 'INSERT INTO ' . quote_ident($table)
         . ' (' . implode(', ', $colsSql) . ') VALUES (' . implode(', ', $valsSql) . ')';
    $st = $pdo->prepare($sql);
    $st->execute($params);

    $insertedPk = $pdo->lastInsertId();
    if ($insertedPk === '' && isset($payload[$pk])) {
      $insertedPk = (string)$payload[$pk];
    }

    $saved = null;
    if ($insertedPk !== '') {
      $saved = fetch_one_by_pk($pdo, $table, $schema, $insertedPk);
    }

    json_out(200, [
      'ok' => true,
      'mode' => 'insert',
      'table' => $table,
      'pk' => $pk,
      'pk_value' => $insertedPk,
      'row' => $saved,
    ]);
  }

  if ($action === 'delete') {
    if (empty($schema['write_enabled'])) {
      json_out(422, ['ok' => false, 'error' => 'La tabla no admite borrado en modo generico.']);
    }
    if ($pk === '') {
      json_out(422, ['ok' => false, 'error' => 'No hay clave primaria valida para borrar.']);
    }

    $pkValue = $input['pk_value'] ?? ($input['pk'] ?? null);
    if ($pkValue === null || $pkValue === '') {
      json_out(422, ['ok' => false, 'error' => 'Falta pk_value para borrar.']);
    }

    if ($table === 'usuarios') {
      $currentId = (int)(current_user()['id'] ?? 0);
      if ($currentId > 0 && (int)$pkValue === $currentId) {
        json_out(422, ['ok' => false, 'error' => 'No puedes eliminar tu propio usuario.']);
      }
    }

    $sql = 'DELETE FROM ' . quote_ident($table)
         . ' WHERE ' . quote_ident($pk) . ' = :pk LIMIT 1';
    $st = $pdo->prepare($sql);
    $st->execute([':pk' => $pkValue]);

    json_out(200, [
      'ok' => true,
      'table' => $table,
      'pk' => $pk,
      'pk_value' => $pkValue,
      'affected' => $st->rowCount(),
    ]);
  }

  json_out(400, ['ok' => false, 'error' => 'Accion no valida.']);
} catch (PDOException $e) {
  $code = (string)$e->getCode();
  if ($code === '23000') {
    json_out(409, ['ok' => false, 'error' => 'Operacion rechazada por integridad referencial o dato duplicado.']);
  }
  json_out(500, ['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
  json_out(500, ['ok' => false, 'error' => $e->getMessage()]);
}

