<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

require_login_json();

header('Content-Type: application/json; charset=UTF-8');

if (!current_user_is_admin()) {
  http_response_code(403);
  echo json_encode(
    ['ok' => false, 'error' => 'No tienes permisos para gestionar usuarios.'],
    JSON_UNESCAPED_UNICODE
  );
  exit;
}

// Admite form-data o JSON.
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

$usuarioId = (int)($input['usuario_id'] ?? 0);
$password = (string)($input['password'] ?? '');
$desbloquear = ((int)($input['desbloquear'] ?? 1) === 1);

$errors = [];
if ($usuarioId <= 0) {
  $errors[] = 'usuario_id requerido';
}
if ($password === '') {
  $errors[] = 'password requerido';
} elseif (strlen($password) < 8) {
  $errors[] = 'La password debe tener al menos 8 caracteres';
}

if ($errors) {
  http_response_code(422);
  echo json_encode(['ok' => false, 'errors' => $errors], JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  $pdo = pdo();

  $hasUsuarios = (bool)$pdo->query("SHOW TABLES LIKE 'usuarios'")->fetch(PDO::FETCH_NUM);
  if (!$hasUsuarios) {
    throw new RuntimeException("No existe la tabla 'usuarios'.");
  }

  $st = $pdo->prepare('SELECT id, nombre_usuario FROM usuarios WHERE id = ? LIMIT 1');
  $st->execute([$usuarioId]);
  $usuario = $st->fetch(PDO::FETCH_ASSOC);
  if (!$usuario) {
    http_response_code(404);
    echo json_encode(
      ['ok' => false, 'error' => 'Usuario no encontrado.'],
      JSON_UNESCAPED_UNICODE
    );
    exit;
  }

  // Bcrypt con coste 12.
  $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
  if (!is_string($hash) || $hash === '') {
    throw new RuntimeException('No se pudo generar el hash de la contraseña.');
  }

  if ($desbloquear) {
    $up = $pdo->prepare(
      'UPDATE usuarios
       SET pass = :pass, intentos = 0, bloqueado = 0
       WHERE id = :id
       LIMIT 1'
    );
  } else {
    $up = $pdo->prepare(
      'UPDATE usuarios
       SET pass = :pass
       WHERE id = :id
       LIMIT 1'
    );
  }

  $ok = $up->execute([
    ':pass' => $hash,
    ':id' => $usuarioId,
  ]);

  if (!$ok) {
    throw new RuntimeException('No se pudo actualizar la contraseña.');
  }

  echo json_encode(
    [
      'ok' => true,
      'id' => (int)$usuario['id'],
      'nombre_usuario' => (string)$usuario['nombre_usuario'],
      'desbloqueado' => $desbloquear ? 1 : 0,
      'hash_algoritmo' => 'bcrypt',
      'hash_coste' => 12,
    ],
    JSON_UNESCAPED_UNICODE
  );
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(
    [
      'ok' => false,
      'error' => $e->getMessage(),
    ],
    JSON_UNESCAPED_UNICODE
  );
}

