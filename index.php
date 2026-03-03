<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

if (is_logged_in()) {
  header('Location: gestion_compras.php');
  exit;
}

header('Location: login.php');
exit;
