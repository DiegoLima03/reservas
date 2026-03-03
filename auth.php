<?php
declare(strict_types=1);

if (!defined('AUTH_BYPASS')) {
  define('AUTH_BYPASS', true); // true = login desactivado temporalmente
}

if (session_status() !== PHP_SESSION_ACTIVE) {
  $isHttps = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
  if (PHP_VERSION_ID >= 70300) {
    session_set_cookie_params([
      'lifetime' => 0,
      'path' => '/',
      'secure' => $isHttps,
      'httponly' => true,
      'samesite' => 'Lax',
    ]);
  } else {
    session_set_cookie_params(0, '/; samesite=Lax', '', $isHttps, true);
  }
  session_start();
}

function sanitize_internal_path(?string $path, string $fallback = 'gestion_compras.php'): string
{
  $target = trim((string)$path);
  if ($target === '') {
    return $fallback;
  }

  $target = str_replace(["\r", "\n"], '', $target);
  if (preg_match('#^[a-z]+://#i', $target)) {
    return $fallback;
  }
  if (strpos($target, '//') === 0) {
    return $fallback;
  }

  if (substr($target, 0, 1) === '/') {
    $target = ltrim($target, '/');
  }

  if ($target === '' || strpos($target, '..') !== false) {
    return $fallback;
  }

  if (preg_match('/[^A-Za-z0-9_\-\.\/\?\=&%]/', $target)) {
    return $fallback;
  }

  if (strpos($target, 'login.php') === 0 || strpos($target, 'logout.php') === 0) {
    return $fallback;
  }

  return $target;
}

function is_logged_in(): bool
{
  return !empty($_SESSION['user']) && is_array($_SESSION['user']) && !empty($_SESSION['user']['id']);
}

function current_user(): array
{
  return is_logged_in() ? (array)$_SESSION['user'] : [];
}

/**
 * @param array<string, mixed> $user
 */
function login_user(array $user): void
{
  session_regenerate_id(true);
  $_SESSION['user'] = [
    'id' => (int)($user['id'] ?? 0),
    'username' => (string)($user['username'] ?? ''),
  ];
  $_SESSION['logged_at'] = date('c');
}

function logout_user(): void
{
  $_SESSION = [];
  if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
      session_name(),
      '',
      time() - 42000,
      $params['path'] ?? '/',
      $params['domain'] ?? '',
      (bool)($params['secure'] ?? false),
      (bool)($params['httponly'] ?? true)
    );
  }
  session_destroy();
}

function require_login(string $defaultTarget = 'gestion_compras.php'): void
{
  if (AUTH_BYPASS) {
    return;
  }

  if (is_logged_in()) {
    return;
  }

  $requestUri = $_SERVER['REQUEST_URI'] ?? $defaultTarget;
  $next = sanitize_internal_path((string)$requestUri, $defaultTarget);
  header('Location: login.php?next=' . rawurlencode($next));
  exit;
}

function require_login_json(): void
{
  if (AUTH_BYPASS) {
    return;
  }

  if (is_logged_in()) {
    return;
  }

  http_response_code(401);
  header('Content-Type: application/json; charset=UTF-8');
  echo json_encode(
    ['ok' => false, 'error' => 'No autorizado. Inicia sesión.'],
    JSON_UNESCAPED_UNICODE
  );
  exit;
}

function verify_password_compat(string $password, string $stored): bool
{
  if ($stored === '') {
    return false;
  }

  $isHash = preg_match('/^\$(2y|2a|2b|argon2i|argon2id)\$/', $stored) === 1;
  if ($isHash) {
    return password_verify($password, $stored);
  }

  return hash_equals($stored, $password);
}
