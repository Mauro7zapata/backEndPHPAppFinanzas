# Ambientes: desarrollo (DEV) y producción (PRD)

| | DEV | PRD |
|---|---|---|
| Carpeta en Hostinger | `/DEV/BackEnd_PresupuestosApp` | `/PRD/BackEnd_PresupuestosApp` |
| URL | `https://<dominio>/DEV/BackEnd_PresupuestosApp/AppFinanzas/` | `https://<dominio>/PRD/BackEnd_PresupuestosApp/AppFinanzas/` |
| Base de datos | `..._dev` (copia de pruebas) | la real |
| `config.local.php` | propio, `'ambiente' => 'dev'` | propio, `'ambiente' => 'prd'` |
| App Android | variante `dev` (app «Presupuesto DEV», paquete `.dev`) | variante `prd` |

Dentro de cada `BackEnd_PresupuestosApp` va la carpeta `AppFinanzas/` completa (Auth/, Deudas/, db.php, lib.php, config.local.php…); la app apunta a esa subcarpeta.

## Montar el ambiente DEV (una vez)
1. Crea en Hostinger una base de datos nueva (p. ej. `..._presupuesto_dev`) y su usuario.
2. Copia la estructura de PRD: exporta PRD (phpMyAdmin → Exportar, «solo estructura» o con datos de prueba) e impórtala en la de DEV. Luego aplica ahí las migraciones nuevas.
3. Sube el código a `/DEV/BackEnd_PresupuestosApp/AppFinanzas` y copia `config.example.php` como `config.local.php` con los datos de la BD **de DEV**, `'ambiente' => 'dev'` y un `codigo_cuenta_inicial` distinto.
4. Verifica: cualquier endpoint responde con la cabecera `X-Ambiente: dev`.

## Flujo de trabajo
1. Todo cambio se sube primero a **DEV** y se prueba con la app variante `dev`.
2. Si hay migración SQL: se ejecuta en la BD de DEV, se prueba, y solo después (con respaldo) en la de PRD.
3. Cuando está probado, se sube **el mismo código** a PRD. No se copia `config.local.php` entre carpetas (cada una tiene el suyo).

Regla: `config.local.php` nunca se sube a git ni se sobrescribe al desplegar.

## App Android
- Android Studio → *Build Variants* → elegir `devDebug` (pruebas) o `prdDebug` / `prdRelease` (real).
- Las URLs están en `app/src/dev/.../Util/Config.kt` y `app/src/prd/.../Util/Config.kt`.
- La variante `dev` se instala junto a la real (sufijo `.dev`, nombre «Presupuesto DEV»); cada una con su sesión y datos.
- Google Sign-In: en Google Cloud agrega un cliente Android para `com.oruam.presupuestopersonal.dev` (mismo SHA-1) o en dev usa correo y contraseña.
- El APK/AAB que se publica o reparte es siempre `prdRelease`.
