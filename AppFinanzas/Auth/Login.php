<?php
define('APPFINANZAS_SIN_SESION', true);
require_once("../db.php");
require_once("../libAuth.php");

// POST {correo, clave} -> {status:'ok', accessToken, refreshToken, expiraEn, usuario}
authSoloPost();
$d = authCuerpo();
$correo = authNormalizarCorreo($d['correo'] ?? '');
$clave = (string)($d['clave'] ?? '');
if ($correo === '' || $clave === '' || strlen($clave) > 1024) appfinanzas_responder_error(400, 'Escribe tu correo y contraseña');
authExigirCupo($correo);

$u = authBuscarPorCorreo($correo);
// Siempre se ejecuta un password_verify (también con correo inexistente) para no revelar si existe por el tiempo de respuesta.
$hashBase = ($u && !empty($u['ClaveHash'])) ? $u['ClaveHash'] : '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
$ok = password_verify($clave, $hashBase) && $u && !empty($u['ClaveHash']) && (int)$u['Activo'] === 1;
if (!$ok) {
    authFalloIntento($correo);
    if ($u && empty($u['ClaveHash']) && !empty($u['GoogleSub'])) {
        appfinanzas_responder_error(401, 'Esta cuenta usa Google. Pulsa "Continuar con Google".');
    }
    appfinanzas_responder_error(401, 'Correo o contraseña incorrectos');
}
authLimpiarIntentos($correo, 'correo');
if (password_needs_rehash($u['ClaveHash'], PASSWORD_DEFAULT)) {
    $nh = password_hash($clave, PASSWORD_DEFAULT);
    $q = $mysql->prepare("UPDATE usuarios SET ClaveHash = ? WHERE IdUsuario = ?");
    $q->bind_param('si', $nh, $u['IdUsuario']);
    $q->execute();
    $q->close();
}
authRespuestaSesion($u);
