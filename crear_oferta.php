<?php
require_once __DIR__ . '/api_verabuy.php';
/**
 * @param array $datos Array con los datos de la reserva:
 * @return array ['ok' => bool, 'message' => string, 'oferta_id' => int|null]
 */
function crearOfertaDesdeReserva($datos) {
    global $conn;
    
    try {
        // Validar que tengamos los datos mínimos
        if (!isset($datos['tipo']) || !isset($datos['nombre'])) {
            return ['ok' => false, 'message' => 'Faltan datos mínimos (tipo, nombre)'];
        }
        
        // Mapear el tipo a articulo (enum válido)
        // Lista de valores válidos según el enum
        $articulosValidos = [
            'Rosa', 'Clavel', 'Alstromelia', 'Paniculata', 'Limonium',
            'Hortensia', 'Uniflor', 'Crisantemo', 'Rosa Ramificada', 'Rosa Teñida'
        ];
        
        $articulo = trim($datos['tipo']);
        // Si el tipo no está en el enum, intentar encontrar coincidencia parcial o usar 'Rosa' por defecto
        if (!in_array($articulo, $articulosValidos)) {
            // Intentar encontrar coincidencia parcial (case-insensitive)
            $encontrado = false;
            foreach ($articulosValidos as $valido) {
                if (stripos($valido, $articulo) !== false || stripos($articulo, $valido) !== false) {
                    $articulo = $valido;
                    $encontrado = true;
                    break;
                }
            }
            // Si no hay coincidencia, usar valor por defecto
            if (!$encontrado) {
                $articulo = 'Rosa'; // valor por defecto
            }
        }
        
        // Preparar los datos
        $variedad = trim($datos['nombre']);
        $cultivo = isset($datos['cultivo']) ? trim($datos['cultivo']) : null;
        $vuelo = isset($datos['vuelo']) ? trim($datos['vuelo']) : null;
        $cajas = isset($datos['cantidad']) ? (float)$datos['cantidad'] : 0;
$fecha            = isset($datos['fecha']) ? ($datos['fecha'] ?: null) : null;                   // p.fecha
$fecha_expedicion  = isset($datos['fecha_expedicion']) ? ($datos['fecha_expedicion'] ?: null) : null; // input usuario
$cliente          = isset($datos['cliente']) ? trim($datos['cliente']) : null;
$ubicacion        = 'Tránsito'; // por defecto

$sql = "INSERT INTO ofertas 
        (articulo, variedad, cultivo, fecha, fecha_expedicion, vuelo, cliente, ubicacion, cajas, disponible, reservado, es_outlet, preparado) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 0)";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    return ['ok' => false, 'message' => 'Error preparando consulta: ' . $conn->error];
}

// 8 strings + 2 números (cajas y disponible)
$disponible = $cajas;
$stmt->bind_param(
    'ssssssssdd',
    $articulo,         // s
    $variedad,         // s
    $cultivo,          // s
    $fecha,            // s (nullable permitido)
    $fecha_expedicion,  // s (nullable permitido)
    $vuelo,            // s
    $cliente,          // s
    $ubicacion,        // s
    $cajas,            // d
    $disponible        // d
);

if ($stmt->execute()) {
    $ofertaId = $stmt->insert_id;
    $stmt->close();
    return ['ok' => true, 'message' => 'Oferta creada correctamente en BD demanda', 'oferta_id' => $ofertaId];
} else {
    $error = $stmt->error;
    $stmt->close();
    return ['ok' => false, 'message' => 'Error ejecutando INSERT: ' . $error];
}

        
    } catch (Exception $e) {
        return ['ok' => false, 'message' => 'Excepción: ' . $e->getMessage()];
    }
}

?>

