<?php
define('APPFINANZAS_SIN_SESION', true);
require_once("../db.php");
require_once("../libAuth.php");

// POST {idToken} (ID token de Google obtenido en la app) -> igual que Login.php
// Crea la cuenta si no existe; si ya existe una con el mismo correo (verificado por Google) la vincula.
authSoloPost();
$d = authCuerpo();
if (authIntentos(authIp(), 'ip', 15) >= 30) appfinanzas_responder_error(429, 'Demasiados intentos. Espera unos minutos.');
authAnotarIntento(authIp(), 'ip');

if (!$appConfig['google_client_ids']) appfinanzas_responder_error(503, 'El acceso con Google no está configurado en el servidor');
$g = authVerificarGoogle($d['idToken'] ?? '');
if (!$g) appfinanzas_responder_error(401, 'No se pudo verificar tu cuenta de Google');

// 1) por identificador de Google
$q = $mysql->prepare("SELECT * FROM usuarios WHERE GoogleSub = ? LIMIT 1");
$q->bind_param('s', $g['sub']);
$q->execute();
$u = $q->get_result()->fetch_assoc();
$q->close();

// 2) por correo (vincula)
if (!$u) {
    $u = authBuscarPorCorreo($g['correo']);
    if ($u) {
        if (!empty($u['GoogleSub']) && $u['GoogleSub'] !== $g['sub']) appfinanzas_responder_error(409, 'Ese correo ya está vinculado a otra cuenta de Google');
        $q = $mysql->prepare("UPDATE usuarios SET GoogleSub = ?, Nombre = COALESCE(Nombre, NULLIF(?, '')) WHERE IdUsuario = ?");
        $q->bind_param('ssi', $g['sub'], $g['nombre'], $u['IdUsuario']);
        $q->execute();
        $q->close();
        $u = authBuscarPorId((int)$u['IdUsuario']);
    }
}
// 3) cuenta nueva
if (!$u) {
    $u = authCrearUsuario($g['correo'], null, $g['sub'], $g['nombre'] !== '' ? $g['nombre'] : null);
}
if ((int)$u['Activo'] !== 1) appfinanzas_responder_error(403, 'Cuenta desactivada');
authRespuestaSesion($u);
