<?php
/**
 * Punto de entrada común de TODOS los endpoints (cada archivo lo incluye con require_once).
 *  1. Carga la configuración desde config.local.php (fuera de git).
 *  2. Conecta a MySQL y deja la conexión en $mysql.
 *  3. Identifica al usuario ($uid) por su token de sesión (Authorization: Bearer); falla cerrado.
 *
 * Contrato con la app: sin cambios en rutas, parámetros ni mensajes de éxito.
 */

// Nunca mostrar errores de PHP al cliente (rompen el JSON y filtran rutas); se registran en el log.
// Las fechas (vencimientos, alertas, "hoy") se calculan en hora de Colombia, no en la del servidor.
date_default_timezone_set('America/Bogota');
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
    // Con 'depurar' => true en config.local.php (solo para diagnosticar) el mensaje incluye la causa real.
    $detalle = !empty($GLOBALS['appfinanzas_depurar']) ? ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')' : '';
    appfinanzas_responder_error(500, 'Error interno del servidor' . $detalle);
});

header('X-Content-Type-Options: nosniff');

// --- 1. Configuración -------------------------------------------------------------------------
$rutaConfig = getenv('APPFINANZAS_CONFIG') ?: __DIR__ . '/config.local.php';
if (!is_file($rutaConfig)) {
    error_log('[AppFinanzas] Falta el archivo de configuración: ' . $rutaConfig);
    appfinanzas_responder_error(500, 'Servidor sin configurar');
}
$config = require $rutaConfig;
$GLOBALS['appfinanzas_depurar'] = !empty($config['depurar']);
// Ambiente informado en cada respuesta (X-Ambiente) para comprobar a qué servidor está hablando la app.
if (!headers_sent()) header('X-Ambiente: ' . preg_replace('/[^a-z]/', '', strtolower((string)($config['ambiente'] ?? 'prd'))));
foreach (['host', 'user', 'password', 'db'] as $clave) {
    if (!isset($config[$clave]) || $config[$clave] === '') {
        error_log('[AppFinanzas] Configuración incompleta, falta: ' . $clave);
        appfinanzas_responder_error(500, 'Servidor sin configurar');
    }
}
// API key compartida: SOLO para la transición con versiones antiguas de la app (ver 'api_key_usuario').
$apiKeyLegacy = (string)($config['api_key'] ?? '');
if ($apiKeyLegacy !== '' && strlen($apiKeyLegacy) < 32) {
    error_log('[AppFinanzas] api_key demasiado corta (mínimo 32 caracteres)');
    appfinanzas_responder_error(500, 'Servidor sin configurar');
}
$apiKeyUsuario = (int)($config['api_key_usuario'] ?? 0);   // 0 = la API key compartida ya no se acepta

// Ajustes que necesitan los endpoints de autenticación (no son credenciales de BD).
$appConfig = [
    'google_client_ids'     => array_values(array_filter((array)($config['google_client_ids'] ?? []))),
    'codigo_cuenta_inicial' => (string)($config['codigo_cuenta_inicial'] ?? ''),
];

// --- 2. Base de datos -------------------------------------------------------------------------
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

// --- 3. Autenticación -------------------------------------------------------------------------
// Cada petición pertenece a UN usuario: $uid. Todos los endpoints deben filtrar por $uid.
//  - Authorization: Bearer <token de acceso>  (sesión iniciada con Auth/Login.php, Registro.php o Google.php)
//  - X-API-Key (solo transición): si 'api_key_usuario' está configurado, esa clave actúa como ese usuario.
// Los endpoints de Auth/ que no necesitan sesión definen APPFINANZAS_SIN_SESION antes de incluir db.php.
$uid = 0;
$sesionActual = 0;
function appfinanzas_cabecera($nombre)
{
    $clave = 'HTTP_' . strtoupper(str_replace('-', '_', $nombre));
    if (!empty($_SERVER[$clave])) return (string)$_SERVER[$clave];
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $n => $v) {
            if (strcasecmp($n, $nombre) === 0) return (string)$v;
        }
    }
    return '';
}

if (!defined('APPFINANZAS_SIN_SESION')) {
    $auth = appfinanzas_cabecera('Authorization');
    if (stripos($auth, 'Bearer ') === 0) {
        $hash = hash('sha256', trim(substr($auth, 7)));
        $q = $mysql->prepare("SELECT s.IdSesion, s.IdUsuario FROM sesiones s INNER JOIN usuarios u ON u.IdUsuario = s.IdUsuario
                              WHERE s.TokenHash = ? AND s.ExpiraToken > NOW() AND u.Activo = 1 LIMIT 1");
        $q->bind_param('s', $hash);
        $q->execute();
        $fila = $q->get_result()->fetch_assoc();
        $q->close();
        if (!$fila) {
            appfinanzas_responder_error(401, 'Sesión expirada');
        }
        $uid = (int)$fila['IdUsuario'];
        $sesionActual = (int)$fila['IdSesion'];
    } elseif ($apiKeyLegacy !== '' && $apiKeyUsuario > 0 && hash_equals($apiKeyLegacy, appfinanzas_cabecera('X-API-Key'))) {
        $uid = $apiKeyUsuario;
    } else {
        appfinanzas_responder_error(401, 'No autorizado');
    }
}
unset($apiKeyLegacy, $apiKeyUsuario);

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
