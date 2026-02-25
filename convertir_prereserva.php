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

function dateToIsoWeek(string $date): ?string
{
    $ts = strtotime($date);
    if ($ts === false) {
        return null;
    }
    return date('o-\\WW', $ts);
}

function isoWeekToMondayDate(string $week): ?string
{
    if (!preg_match('/^\d{4}-W\d{2}$/', $week)) {
        return null;
    }
    [$year, $num] = explode('-W', $week);
    $y = (int)$year;
    $w = (int)$num;
    if ($w < 1 || $w > 53) {
        return null;
    }
    $dt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    return $dt->setISODate($y, $w, 1)->format('Y-m-d');
}

$input = getInputData();

$preReservaId = (int)($input['pre_reserva_id'] ?? 0);
$proveedorIdLegacy = (int)($input['proveedor_id'] ?? 0);
$asignacionesInput = $input['asignaciones'] ?? null;

$errors = [];
if ($preReservaId <= 0) {
    $errors[] = 'pre_reserva_id vacio o no valido';
}

$asignacionesMap = [];
if (is_array($asignacionesInput)) {
    foreach ($asignacionesInput as $item) {
        if (!is_array($item)) {
            continue;
        }
        $proveedorId = (int)($item['proveedor_id'] ?? 0);
        $cantidad = (int)($item['cantidad'] ?? 0);
        if ($proveedorId <= 0 && $cantidad <= 0) {
            continue;
        }
        if ($proveedorId <= 0 || $cantidad <= 0) {
            $errors[] = 'Cada linea debe incluir proveedor_id y cantidad > 0';
            continue;
        }
        $asignacionesMap[$proveedorId] = (int)($asignacionesMap[$proveedorId] ?? 0) + $cantidad;
    }
}

if (!$asignacionesMap && $proveedorIdLegacy > 0) {
    // compatibilidad con payload antiguo (un solo proveedor)
    $asignacionesMap[$proveedorIdLegacy] = 0; // se completa tras leer pre-reserva
}

if (!$asignacionesMap) {
    $errors[] = 'Debes indicar al menos una asignacion de proveedor con cantidad';
}

if ($errors !== []) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'errors' => $errors], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = null;

try {
    $pdo = pdo();
    $pdo->beginTransaction();

    $stmtPre = $pdo->prepare(
        'SELECT id, comercial_id, comercial_nombre, tipo, producto_deseado, cantidad, fecha_deseada, estado
         FROM pre_reservas
         WHERE id = ?
         LIMIT 1
         FOR UPDATE'
    );
    $stmtPre->execute([$preReservaId]);
    $pre = $stmtPre->fetch(PDO::FETCH_ASSOC);

    if (!$pre) {
        throw new RuntimeException('La pre-reserva no existe.');
    }
    if (($pre['estado'] ?? '') !== 'pendiente') {
        throw new RuntimeException('La pre-reserva no esta en estado pendiente.');
    }

    $cantidadReserva = (int)($pre['cantidad'] ?? 0);
    if ($cantidadReserva <= 0) {
        throw new RuntimeException('La cantidad de la pre-reserva no es valida.');
    }

    if ($proveedorIdLegacy > 0 && count($asignacionesMap) === 1 && isset($asignacionesMap[$proveedorIdLegacy]) && $asignacionesMap[$proveedorIdLegacy] === 0) {
        $asignacionesMap[$proveedorIdLegacy] = $cantidadReserva;
    }

    $totalAsignado = array_sum($asignacionesMap);
    if ($totalAsignado !== $cantidadReserva) {
        throw new RuntimeException('El reparto debe sumar exactamente la cantidad de la pre-reserva: ' . $cantidadReserva);
    }

    $fechaDeseada = (string)($pre['fecha_deseada'] ?? '');
    $semanaReserva = $fechaDeseada !== '' ? dateToIsoWeek($fechaDeseada) : null;
    if (!is_string($semanaReserva) || $semanaReserva === '') {
        throw new RuntimeException('La semana de la pre-reserva no es valida.');
    }

    $fechaSalidaDb = isoWeekToMondayDate($semanaReserva);
    if (!is_string($fechaSalidaDb) || $fechaSalidaDb === '') {
        throw new RuntimeException('No se pudo convertir semana de pre-reserva a fecha_salida.');
    }

    $productoDeseadoPre = trim((string)($pre['producto_deseado'] ?? ''));
    if ($productoDeseadoPre === '') {
        throw new RuntimeException('La pre-reserva no tiene producto_deseado valido.');
    }

    $delegacionId = (int)($pre['comercial_id'] ?? 0);
    if ($delegacionId <= 0) {
        throw new RuntimeException('La pre-reserva no tiene delegacion valida.');
    }
    $stmtDel = $pdo->prepare('SELECT id FROM delegaciones WHERE id = ? LIMIT 1');
    $stmtDel->execute([$delegacionId]);
    if (!$stmtDel->fetchColumn()) {
        throw new RuntimeException('La delegacion de la pre-reserva no existe.');
    }

    // Compatibilidad con esquema actual: reservas.comercial_id referencia proveedores.id
    $stmtFk = $pdo->prepare(
        "SELECT REFERENCED_TABLE_NAME
         FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'reservas'
           AND COLUMN_NAME = 'comercial_id'
           AND REFERENCED_TABLE_NAME IS NOT NULL
         LIMIT 1"
    );
    $stmtFk->execute();
    $fkTarget = $stmtFk->fetchColumn();

    $stmtProv = $pdo->prepare('SELECT nombre FROM proveedores WHERE id = ? LIMIT 1');
    $stmtProd = $pdo->prepare(
        'SELECT id, nombre
         FROM productos
         WHERE nombre = ? AND proveedor = ?
         ORDER BY id ASC
         LIMIT 1
         FOR UPDATE'
    );
    $stmtDisp = $pdo->prepare(
        'SELECT id, cantidad_disponible
         FROM compras_stock
         WHERE producto_id = ? AND proveedor_id = ?
         ORDER BY semana ASC, id ASC
         FOR UPDATE'
    );

    $detalles = [];
    foreach ($asignacionesMap as $proveedorId => $cantidadAsignar) {
        $stmtProv->execute([$proveedorId]);
        $proveedorNombre = $stmtProv->fetchColumn();
        if (!is_string($proveedorNombre) || trim($proveedorNombre) === '') {
            throw new RuntimeException('El proveedor indicado no existe: ' . $proveedorId);
        }

        $stmtProd->execute([$productoDeseadoPre, $proveedorNombre]);
        $producto = $stmtProd->fetch(PDO::FETCH_ASSOC);
        if (!$producto) {
            throw new RuntimeException('No existe el producto ' . $productoDeseadoPre . ' para el proveedor ' . $proveedorNombre);
        }

        $productoId = (int)$producto['id'];
        $stmtDisp->execute([$productoId, (int)$proveedorId]);
        $rowsDisp = $stmtDisp->fetchAll(PDO::FETCH_ASSOC);

        $disponible = 0;
        foreach ($rowsDisp as $r) {
            $disponible += (int)($r['cantidad_disponible'] ?? 0);
        }

        if ($disponible < $cantidadAsignar) {
            throw new RuntimeException(
                'Stock insuficiente para ' . $proveedorNombre . '. Disponible: ' . $disponible . ', solicitado: ' . $cantidadAsignar
            );
        }

        $detalles[] = [
            'proveedor_id' => (int)$proveedorId,
            'proveedor_nombre' => $proveedorNombre,
            'cantidad' => (int)$cantidadAsignar,
            'producto_id' => $productoId,
            'producto_nombre' => (string)$producto['nombre'],
            'rows_disp' => $rowsDisp,
        ];
    }

    $stmtInsReserva = $pdo->prepare(
        'INSERT INTO reservas
            (comercial_id, comercial_nombre, producto_id, producto_nombre, cantidad, semana)
         VALUES
            (?, ?, ?, ?, ?, ?)'
    );
    $stmtInsAsign = $pdo->prepare(
        'INSERT INTO asignaciones
            (producto_id, proveedor_id, delegacion_id, cantidad_asignada, fecha_salida)
         VALUES
            (?, ?, ?, ?, ?)'
    );
    $stmtUpdCompra = $pdo->prepare(
        'UPDATE compras_stock
         SET cantidad_disponible = cantidad_disponible - :quita
         WHERE id = :id'
    );
    $stmtInsLote = $pdo->prepare(
        'INSERT INTO asignacion_lotes (asignacion_id, compra_stock_id, cantidad)
         VALUES (:asignacion_id, :compra_stock_id, :cantidad)'
    );

    $reservaIds = [];
    $asignacionIds = [];
    $productosTocados = [];

    foreach ($detalles as $det) {
        $comercialIdReserva = (int)$pre['comercial_id'];
        $comercialNombreReserva = (string)$pre['comercial_nombre'];
        if ($fkTarget === 'proveedores') {
            $comercialIdReserva = (int)$det['proveedor_id'];
            $comercialNombreReserva = (string)$det['proveedor_nombre'];
        }

        $stmtInsReserva->execute([
            $comercialIdReserva,
            $comercialNombreReserva,
            (int)$det['producto_id'],
            (string)$det['producto_nombre'],
            (int)$det['cantidad'],
            $semanaReserva,
        ]);
        $reservaIds[] = (int)$pdo->lastInsertId();

        $stmtInsAsign->execute([
            (int)$det['producto_id'],
            (int)$det['proveedor_id'],
            $delegacionId,
            (int)$det['cantidad'],
            $fechaSalidaDb,
        ]);
        $asignacionId = (int)$pdo->lastInsertId();
        $asignacionIds[] = $asignacionId;

        $restanteAsignar = (int)$det['cantidad'];
        foreach ($det['rows_disp'] as $rowDisp) {
            if ($restanteAsignar <= 0) {
                break;
            }
            $disp = (int)($rowDisp['cantidad_disponible'] ?? 0);
            if ($disp <= 0) {
                continue;
            }
            $quita = min($disp, $restanteAsignar);
            $compraStockId = (int)$rowDisp['id'];
            $stmtUpdCompra->execute([
                ':quita' => $quita,
                ':id' => $compraStockId,
            ]);
            $stmtInsLote->execute([
                ':asignacion_id' => $asignacionId,
                ':compra_stock_id' => $compraStockId,
                ':cantidad' => $quita,
            ]);
            $restanteAsignar -= $quita;
        }

        if ($restanteAsignar > 0) {
            throw new RuntimeException('Inconsistencia al consumir stock de compras_stock.');
        }

        $productosTocados[(int)$det['producto_id']] = true;
    }

    $stmtUpdatePedido = $pdo->prepare(
        'UPDATE productos p
         JOIN (
            SELECT producto_id, SUM(cantidad) AS total
            FROM reservas
            WHERE producto_id = ?
            GROUP BY producto_id
         ) r ON p.id = r.producto_id
         SET p.pedido = r.total'
    );
    foreach (array_keys($productosTocados) as $productoIdTocado) {
        $stmtUpdatePedido->execute([(int)$productoIdTocado]);
    }

    $stmtUpdatePre = $pdo->prepare(
        "UPDATE pre_reservas SET estado = 'convertida' WHERE id = ?"
    );
    $stmtUpdatePre->execute([$preReservaId]);

    $pdo->commit();

    echo json_encode(
        [
            'ok' => true,
            'message' => 'Pre-reserva convertida correctamente.',
            'reserva_ids' => $reservaIds,
            'asignacion_ids' => $asignacionIds,
            'pre_reserva_id' => $preReservaId,
            'lineas' => count($detalles),
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
