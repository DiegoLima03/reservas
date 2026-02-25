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

$input = getInputData();
$preReservaId = (int)($input['pre_reserva_id'] ?? 0);

if ($preReservaId <= 0) {
    http_response_code(422);
    echo json_encode(
        ['ok' => false, 'errors' => ['pre_reserva_id vacio o no valido']],
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

$pdo = null;

try {
    $pdo = pdo();
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        "UPDATE pre_reservas
         SET estado = 'cancelada'
         WHERE id = ? AND estado = 'pendiente'"
    );
    $stmt->execute([$preReservaId]);

    if ($stmt->rowCount() < 1) {
        throw new RuntimeException(
            'No se pudo eliminar la pre-reserva. Puede que ya no esté pendiente.'
        );
    }

    $pdo->commit();

    echo json_encode(
        ['ok' => true, 'message' => 'Pre-reserva eliminada correctamente.'],
        JSON_UNESCAPED_UNICODE
    );
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    echo json_encode(
        ['ok' => false, 'error' => $e->getMessage()],
        JSON_UNESCAPED_UNICODE
    );
}

