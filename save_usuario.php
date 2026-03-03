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

$nombreUsuario = trim((string)($input['nombre_usuario'] ?? ''));
$password = (string)($input['password'] ?? '');
$esAdmin = (int)($input['es_admin'] ?? 0) === 1 ? 1 : 0;

$errors = [];
if ($nombreUsuario === '') {
  $errors[] = 'nombre_usuario requerido';
} elseif (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $nombreUsuario)) {
  $errors[] = 'nombre_usuario invalido. Usa 3-50 caracteres: letras, numeros, punto, guion o guion bajo';
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
  $hasUsuariosAdminCol = (bool)$pdo->query("SHOW COLUMNS FROM usuarios LIKE 'es_admin'")->fetch(PDO::FETCH_ASSOC);

  $st = $pdo->prepare('SELECT id FROM usuarios WHERE nombre_usuario = ? LIMIT 1');
  $st->execute([$nombreUsuario]);
  if ($st->fetchColumn()) {
    http_response_code(409);
    echo json_encode(
      ['ok' => false, 'error' => 'El nombre de usuario ya existe.'],
      JSON_UNESCAPED_UNICODE
    );
    exit;
  }

  // Bcrypt con coste 12 (12 vueltas).
  $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
  if (!is_string($hash) || $hash === '') {
    throw new RuntimeException('No se pudo generar el hash de la contraseña.');
  }

  if ($hasUsuariosAdminCol) {
    $ins = $pdo->prepare(
      'INSERT INTO usuarios (nombre_usuario, pass, intentos, bloqueado, es_admin)
       VALUES (:nombre_usuario, :pass, 0, 0, :es_admin)'
    );
    $ok = $ins->execute([
      ':nombre_usuario' => $nombreUsuario,
      ':pass' => $hash,
      ':es_admin' => $esAdmin,
    ]);
  } else {
    $ins = $pdo->prepare(
      'INSERT INTO usuarios (nombre_usuario, pass, intentos, bloqueado)
       VALUES (:nombre_usuario, :pass, 0, 0)'
    );
    $ok = $ins->execute([
      ':nombre_usuario' => $nombreUsuario,
      ':pass' => $hash,
    ]);
  }

  if (!$ok) {
    throw new RuntimeException('No se pudo crear el usuario.');
  }

  echo json_encode(
    [
      'ok' => true,
      'id' => (int)$pdo->lastInsertId(),
      'nombre_usuario' => $nombreUsuario,
      'es_admin' => $hasUsuariosAdminCol ? $esAdmin : 0,
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
