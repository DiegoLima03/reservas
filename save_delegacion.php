<?php
// save_delegacion.php — alta sencilla de delegaciones
require_once __DIR__ . '/db.php';

@ini_set('display_errors','1');
@ini_set('display_startup_errors','1');
@error_reporting(E_ALL);

header('Content-Type: application/json; charset=UTF-8');

// Recibir datos POST (form o JSON)
$input = $_POST;
if (empty($input)) {
  $raw = file_get_contents('php://input');
  if ($raw) {
    $parsed = json_decode($raw, true);
    if (is_array($parsed)) {
      $input = $parsed;
    }
  }
}

$nombre = trim((string)($input['nombre'] ?? ''));

$errors = [];
if ($nombre === '') {
  $errors[] = 'nombre requerido';
}

if ($errors) {
  echo json_encode(['ok' => false, 'errors' => $errors], JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  $pdo = pdo();

  $sql = "INSERT INTO delegaciones (nombre) VALUES (:nombre)";
  $stmt = $pdo->prepare($sql);
  $ok = $stmt->execute([':nombre' => $nombre]);

  if (!$ok) {
    throw new RuntimeException('No se pudo insertar la delegación.');
  }

  $id = (int)$pdo->lastInsertId();

  echo json_encode([
    'ok'      => true,
    'message' => 'Delegación creada correctamente.',
    'id'      => $id,
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    'ok'    => false,
    'error' => $e->getMessage(),
  ], JSON_UNESCAPED_UNICODE);
}

