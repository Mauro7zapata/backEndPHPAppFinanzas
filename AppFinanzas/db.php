<?php
$host = "silver-lyrebird-843414.hostingersite.com";
$user = "u214407853_mauro7dev";
$password = "oJwyd~VIHf[2";
$db = "u214407853_dbFinanzas";

$mysql = new mysqli($host, $user, $password, $db);

if ($mysql->connect_error) {
    die("Error de conexión: " . $mysql->connect_error);
}
?>
