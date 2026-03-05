<?php
// movimientos_audit.php — auditoria de usuario por movimiento (compra/asignacion)

if (!function_exists('ensure_movimientos_audit_table')) {
  function ensure_movimientos_audit_table(PDO $pdo): void
  {
    static $ready = false;
    if ($ready) {
      return;
    }

    $exists = (bool)$pdo->query("SHOW TABLES LIKE 'movimientos_usuario'")->fetchColumn();
    if ($exists) {
      $ready = true;
      return;
    }

    $sql = "
      CREATE TABLE IF NOT EXISTS movimientos_usuario (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        movimiento_tipo VARCHAR(20) NOT NULL,
        movimiento_id BIGINT UNSIGNED NOT NULL,
        usuario_id INT NULL,
        usuario_nombre VARCHAR(80) NOT NULL DEFAULT '',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uk_movimiento (movimiento_tipo, movimiento_id),
        KEY idx_usuario_id (usuario_id),
        KEY idx_created_at (created_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";
    $pdo->exec($sql);
    $ready = true;
  }
}

if (!function_exists('movimientos_audit_current_user')) {
  /**
   * @return array{usuario_id: ?int, usuario_nombre: string}
   */
  function movimientos_audit_current_user(): array
  {
    $u = function_exists('current_user') ? (array)current_user() : [];
    $id = (int)($u['id'] ?? 0);
    $nombre = trim((string)($u['username'] ?? ''));
    return [
      'usuario_id' => $id > 0 ? $id : null,
      'usuario_nombre' => $nombre,
    ];
  }
}

if (!function_exists('registrar_movimiento_usuario')) {
  function registrar_movimiento_usuario(PDO $pdo, string $tipo, int $movimientoId, ?int $usuarioId, string $usuarioNombre): void
  {
    if (!in_array($tipo, ['compra', 'asignacion'], true)) {
      return;
    }
    if ($movimientoId <= 0) {
      return;
    }

    $st = $pdo->prepare("
      INSERT INTO movimientos_usuario
        (movimiento_tipo, movimiento_id, usuario_id, usuario_nombre)
      VALUES
        (:tipo, :movimiento_id, :usuario_id, :usuario_nombre)
      ON DUPLICATE KEY UPDATE
        usuario_id = VALUES(usuario_id),
        usuario_nombre = VALUES(usuario_nombre)
    ");
    $st->execute([
      ':tipo' => $tipo,
      ':movimiento_id' => $movimientoId,
      ':usuario_id' => $usuarioId,
      ':usuario_nombre' => trim($usuarioNombre),
    ]);
  }
}
