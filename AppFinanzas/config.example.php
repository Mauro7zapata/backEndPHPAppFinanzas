<?php
/**
 * Copia este archivo como config.local.php (junto a db.php) y completa los valores.
 * config.local.php NO se sube a git (ver .gitignore).
 *
 * Generar valores aleatorios seguros, por ejemplo en terminal:
 *   php -r "echo bin2hex(random_bytes(16)), PHP_EOL;"
 */
return [
    'host'     => 'localhost',
    'user'     => 'USUARIO_BD',
    'password' => 'CLAVE_BD',
    'db'       => 'NOMBRE_BD',

    // --- Cuentas de usuario (migración 005) ---------------------------------------------------------
    // Código para que el dueño de los datos anteriores reclame la "cuenta inicial" (usuario 1) al registrarse
    // en la app con el mismo correo de la migración. Cámbialo por un valor aleatorio y bórralo cuando ya la reclames.
    'codigo_cuenta_inicial' => 'CAMBIAR_POR_UN_CODIGO_ALEATORIO',

    // ID(s) de cliente OAuth "Web" de Google Cloud para "Continuar con Google" (el mismo que va en la app,
    // secrets.properties -> google.web.client.id). Vacío = el acceso con Google queda desactivado.
    'google_client_ids' => [
        // 'XXXXXXXX.apps.googleusercontent.com',
    ],

    // --- Transición con versiones antiguas de la app (SOLO mientras se actualiza el teléfono) -------------
    // La app antigua enviaba una API key compartida. Para que siga funcionando unos días, define las dos
    // líneas siguientes (la clave antigua y el IdUsuario dueño de los datos, normalmente 1). Luego BÓRRALAS:
    // una clave compartida dentro de un APK no es segura para uso comercial.
    // 'api_key'         => 'LA_CLAVE_ANTIGUA_DE_AL_MENOS_32_CARACTERES',
    // 'api_key_usuario' => 1,
];
