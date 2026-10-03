<?php
/**
 * Punto de entrada común de TODOS los endpoints (cada archivo lo incluye con require_once).
 *  1. Carga la configuración desde config.local.php (fuera de git).
 *  2. Exige la API key en la cabecera X-API-Key (falla cerrado si no hay configuración).
 *  3. Conecta a MySQL y deja la conexión en $mysql.
 *
 * Contrato con la app: sin cambios en rutas, parámetros ni mensajes de éxito.
 */

// Nunca mostrar errores de PHP al cliente (rompen el JSON y filtran rutas); se registran en el log.
ini_set('display_errors', '0');
error_reporting(E_ALL);

function appfinanzas_responder_error($codigo, $mensaje)
{
    if (!headers_sent()) {
        http_response_code($codigo);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(["status" => "error", "message" => $mensaje]);
    exit;
}

// Cualquier excepción no controlada (p. ej. error SQL) se registra y responde un error genérico.
set_exception_handler(function ($e) {
    error_log('[AppFinanzas] ' . get_class($e) . ': ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
    appfinanzas_responder_error(500, 'Error interno del servidor');
});

header('X-Content-Type-Options: nosniff');

// --- 1. Configuración -------------------------------------------------------------------------
$rutaConfig = getenv('APPFINANZAS_CONFIG') ?: __DIR__ . '/config.local.php';
if (!is_file($rutaConfig)) {
    error_log('[AppFinanzas] Falta el archivo de configuración: ' . $rutaConfig);
    appfinanzas_responder_error(500, 'Servidor sin configurar');
}
$config = require $rutaConfig;
foreach (['host', 'user', 'password', 'db', 'api_key'] as $clave) {
    if (!isset($config[$clave]) || $config[$clave] === '') {
        error_log('[AppFinanzas] Configuración incompleta, falta: ' . $clave);
        appfinanzas_responder_error(500, 'Servidor sin configurar');
    }
}
if (strlen($config['api_key']) < 32) {
    error_log('[AppFinanzas] api_key demasiado corta (mínimo 32 caracteres)');
    appfinanzas_responder_error(500, 'Servidor sin configurar');
}

// --- 2. Autenticación -------------------------------------------------------------------------
$claveRecibida = $_SERVER['HTTP_X_API_KEY'] ?? '';
if ($claveRecibida === '' && function_exists('getallheaders')) {
    foreach (getallheaders() as $nombre => $valor) {
        if (strcasecmp($nombre, 'X-API-Key') === 0) {
            $claveRecibida = $valor;
        }
    }
}
if (!hash_equals($config['api_key'], (string)$claveRecibida)) {
    appfinanzas_responder_error(401, 'No autorizado');
}

// --- 3. Base de datos -------------------------------------------------------------------------
// Lanza excepciones ante errores SQL (comportamiento uniforme en todas las versiones de PHP).
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli($config['host'], $config['user'], $config['password'], $config['db']);
} catch (mysqli_sql_exception $e) {
    error_log('[AppFinanzas] No se pudo conectar a la BD: ' . $e->getMessage());
    appfinanzas_responder_error(500, 'No se pudo conectar a la base de datos');
}

// La configuración ya no se necesita; no dejar credenciales en memoria global.
unset($config);

// --- Utilidades de validación compartidas ------------------------------------------------------
// Monto en pesos válido: numérico, no negativo y que quepa en DECIMAL(10,2).
function appfinanzas_monto_valido($valor)
{
    return is_numeric($valor) && $valor >= 0 && $valor < 100000000;
}

// Entero a partir de texto como "7150000" o "7150000.0" (la app envía Double.toString()). null si no es válido.
function appfinanzas_entero($valor)
{
    if (!is_numeric($valor) || floor((float)$valor) != (float)$valor) {
        return null;
    }
    return (int)$valor;
}

// Fecha en formato YYYY-MM-DD real (rechaza 2025-02-30).
function appfinanzas_fecha_valida($fecha)
{
    $d = DateTime::createFromFormat('Y-m-d', (string)$fecha);
    return $d && $d->format('Y-m-d') === $fecha;
}
