<?php
$host = "localhost";
$user = "u214407853_mauro7dev";
$password = "Oruam917.";
$db = "u214407853_dbFinanzas";

$mysql = new mysqli($host, $user, $password, $db);

if ($mysql->connect_error) {
    die("Error de conexión: " . $mysql->connect_error);
}
?>
