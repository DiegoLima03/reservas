<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=UTF-8');

/**
 * @return array<string, mixed>
 */
function getInputData(): array
{
    if (!empty($_POST)) {
        return $_POST;
    }

    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function isValidDate(string $date): bool
{
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    return $dt !== false && $dt->format('Y-m-d') === $date;
}

function isValidIsoWeek(string $week): bool
{
    return preg_match('/^\d{4}-W\d{2}$/', $week) === 1;
}

function isoWeekToMondayDate(string $week): ?string
{
    if (!isValidIsoWeek($week)) {
        return null;
    }

    [$year, $weekNum] = explode('-W', $week);
    $yearInt = (int)$year;
    $weekInt = (int)$weekNum;
    if ($weekInt < 1 || $weekInt > 53) {
        return null;
    }

    $dt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    return $dt->setISODate($yearInt, $weekInt, 1)->format('Y-m-d');
}

$input = getInputData();

$delegacionId = (int)($input['delegacion_id'] ?? 0);
$productoId = (int)($input['producto_id'] ?? 0);
$productoDeseadoInput = trim((string)($input['producto_deseado'] ?? ''));
$cantidad = (int)($input['cantidad'] ?? 0);
$semanaDeseada = trim((string)($input['semana_deseada'] ?? ''));
$fechaDeseada = trim((string)($input['fecha_deseada'] ?? '')); // compat legacy

$errors = [];
if ($delegacionId <= 0) {
    $errors[] = 'delegacion_id vacio o no valido';
}
if ($productoId <= 0 && $productoDeseadoInput === '') {
    $errors[] = 'producto_deseado vacio o no valido';
}
if ($cantidad <= 0) {
    $errors[] = 'cantidad debe ser > 0';
}

$fechaDeseadaDb = '';
if ($semanaDeseada !== '') {
    $fechaFromWeek = isoWeekToMondayDate($semanaDeseada);
    if ($fechaFromWeek === null) {
        $errors[] = 'semana_deseada invalida (formato esperado: YYYY-WNN)';
    } else {
        $fechaDeseadaDb = $fechaFromWeek;
    }
} elseif ($fechaDeseada !== '') {
    if (!isValidDate($fechaDeseada)) {
        $errors[] = 'fecha_deseada invalida (formato esperado: YYYY-MM-DD)';
    } else {
        $fechaDeseadaDb = $fechaDeseada;
    }
} else {
    $errors[] = 'semana_deseada requerida (YYYY-WNN)';
}

if ($errors !== []) {
    http_response_code(422);
    echo json_encode(
        ['ok' => false, 'errors' => $errors],
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

$pdo = null;

try {
    $pdo = pdo();
    $pdo->beginTransaction();

    $stmtDelegacion = $pdo->prepare(
        'SELECT nombre FROM delegaciones WHERE id = ? LIMIT 1'
    );
    $stmtDelegacion->execute([$delegacionId]);
    $delegacionNombre = $stmtDelegacion->fetchColumn();

    if (!is_string($delegacionNombre) || trim($delegacionNombre) === '') {
        throw new RuntimeException('No existe delegacion con el id indicado.');
    }

    if ($productoId > 0) {
        $stmtProducto = $pdo->prepare(
            'SELECT tipo, nombre FROM productos WHERE id = ? LIMIT 1'
        );
        $stmtProducto->execute([$productoId]);
    } else {
        $stmtProducto = $pdo->prepare(
            'SELECT tipo, nombre
             FROM productos
             WHERE nombre = ?
             ORDER BY id ASC
             LIMIT 1'
        );
        $stmtProducto->execute([$productoDeseadoInput]);
    }
    $producto = $stmtProducto->fetch(PDO::FETCH_ASSOC);
    if (!is_array($producto)) {
        throw new RuntimeException('No existe producto con el id indicado.');
    }

    $tipo = trim((string)($producto['tipo'] ?? ''));
    $productoDeseado = trim((string)($producto['nombre'] ?? ''));
    if ($tipo === '' || $productoDeseado === '') {
        throw new RuntimeException('El producto seleccionado no tiene tipo/nombre válidos.');
    }

    $stmtInsert = $pdo->prepare(
        'INSERT INTO pre_reservas
            (comercial_id, comercial_nombre, tipo, producto_deseado, cantidad, fecha_deseada, estado)
         VALUES
            (?, ?, ?, ?, ?, ?, ?)'
    );

    $stmtInsert->execute([
        $delegacionId,
        $delegacionNombre,
        $tipo,
        $productoDeseado,
        $cantidad,
        $fechaDeseadaDb,
        'pendiente',
    ]);

    $preReservaId = (int)$pdo->lastInsertId();

    $pdo->commit();

    echo json_encode(
        [
            'ok' => true,
            'id' => $preReservaId,
            'message' => 'Pre-reserva guardada correctamente.',
        ],
        JSON_UNESCAPED_UNICODE
    );
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    echo json_encode(
        [
            'ok' => false,
            'error' => $e->getMessage(),
        ],
        JSON_UNESCAPED_UNICODE
    );
}
