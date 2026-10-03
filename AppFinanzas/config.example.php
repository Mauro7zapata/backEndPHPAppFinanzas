<?php
/**
 * Copia este archivo como config.local.php (junto a db.php) y completa los valores.
 * config.local.php NO se sube a git (ver .gitignore).
 *
 * Generar una API key segura (64 caracteres hexadecimales), por ejemplo en terminal:
 *   php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
 * La misma clave va en la app Android (local.properties -> api.key).
 */
return [
    'host'     => 'localhost',
    'user'     => 'USUARIO_BD',
    'password' => 'CLAVE_BD',
    'db'       => 'NOMBRE_BD',
    'api_key'  => 'CAMBIAR_POR_UNA_CLAVE_ALEATORIA_DE_AL_MENOS_32_CARACTERES',
];
