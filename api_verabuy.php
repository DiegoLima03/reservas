<?php
//$host = "localhost";
$db = "demanda";
$user = "admin_compras";
$host = "localhost";
$pass = "";

// Principal (nombre estándar)
$conn = new mysqli($host, $user, $pass, $db);

// Alias para compatibilidad
$mysqli = $conn;

if ($conn->connect_error) {
    if (php_sapi_name() !== 'cli') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Conexión fallida: ' . $conn->connect_error]);
        exit;
    } else {
        die("Conexión fallida: " . $conn->connect_error);
    }
}
$conn->set_charset("utf8mb4");
// Si quieres, también para el alias (no es estrictamente necesario, pero por si acaso)
$mysqli->set_charset("utf8mb4");
?>
