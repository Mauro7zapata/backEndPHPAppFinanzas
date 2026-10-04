<?php
define('APPFINANZAS_SIN_SESION', true);
require_once("../db.php");
require_once("../libAuth.php");

// POST {refreshToken} -> {status:'ok', accessToken, refreshToken (nuevo), expiraEn}
// El token de renovación se usa UNA sola vez: se reemplaza por uno nuevo.
authSoloPost();
$d = authCuerpo();
$r = (string)($d['refreshToken'] ?? '');
if (strlen($r) !== 64 || !ctype_xdigit($r)) appfinanzas_responder_error(401, 'Sesión expirada');
if (authIntentos(authIp(), 'ip', 15) >= 120) appfinanzas_responder_error(429, 'Demasiados intentos');
authAnotarIntento(authIp(), 'ip');

$hR = hash('sha256', $r);
$mysql->begin_transaction();
$q = $mysql->prepare("SELECT s.IdSesion FROM sesiones s INNER JOIN usuarios u ON u.IdUsuario = s.IdUsuario
                      WHERE s.RefreshHash = ? AND s.ExpiraRefresh > NOW() AND u.Activo = 1 FOR UPDATE");
$q->bind_param('s', $hR);
$q->execute();
$fila = $q->get_result()->fetch_assoc();
$q->close();
if (!$fila) { $mysql->rollback(); appfinanzas_responder_error(401, 'Sesión expirada'); }

$token = bin2hex(random_bytes(32));
$nuevoRefresh = bin2hex(random_bytes(32));
$hT = hash('sha256', $token);
$hN = hash('sha256', $nuevoRefresh);
$q = $mysql->prepare("UPDATE sesiones SET TokenHash = ?, RefreshHash = ?, ExpiraToken = NOW() + INTERVAL " . AUTH_TOKEN_SEGUNDOS . " SECOND,
                      ExpiraRefresh = NOW() + INTERVAL " . AUTH_REFRESH_SEGUNDOS . " SECOND WHERE IdSesion = ?");
$q->bind_param('ssi', $hT, $hN, $fila['IdSesion']);
$q->execute();
$q->close();
$mysql->commit();
authResponder(['status' => 'ok', 'accessToken' => $token, 'refreshToken' => $nuevoRefresh, 'expiraEn' => AUTH_TOKEN_SEGUNDOS]);
