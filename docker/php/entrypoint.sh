#!/usr/bin/env bash
###############################################################################
# Entrypoint del contenedor de aplicación.
#   1. Espera a que PostgreSQL acepte conexiones.
#   2. Genera APP_KEY si falta.
#   3. Corre migraciones y seeders idempotentes (roles/permisos/super_admin).
#   4. Cachea configuración/rutas y enlaza el storage público.
# El worker de colas reutiliza esta misma imagen pero salta la inicialización
# (RUN_MIGRATIONS=false) para no competir por las migraciones.
###############################################################################
set -e

cd /var/www/html

# --- 0. Limpiar cachés de bootstrap ------------------------------------------
# El proyecto se monta como volumen, así que un packages.php/services.php
# cacheado en el host (generado con dev-deps, p. ej. Laravel Pail) se colaría
# al contenedor —construido con --no-dev— y haría fallar CUALQUIER 'php artisan'
# antes de arrancar. Se borran para que el descubrimiento de paquetes se
# regenere desde lo realmente instalado en la imagen.
rm -f bootstrap/cache/packages.php bootstrap/cache/services.php \
      bootstrap/cache/config.php bootstrap/cache/routes-v7.php 2>/dev/null || true

# --- 1. Esperar a la base de datos ------------------------------------------
if [ "${DB_CONNECTION:-pgsql}" = "pgsql" ]; then
    echo "⏳ Esperando a PostgreSQL en ${DB_HOST}:${DB_PORT} ..."
    until pg_isready -h "${DB_HOST}" -p "${DB_PORT}" -U "${DB_USERNAME}" >/dev/null 2>&1; do
        sleep 2
    done
    echo "✅ PostgreSQL disponible."
fi

# --- 1.5. Secreto del índice de PIN (M14.1) ---------------------------------
# A diferencia de APP_KEY, esta NO se autogenera: hacerlo la rotaría en cada arranque
# y los PIN de todos los autorizadores dejarían de resolver sin aviso. Debe venir del
# entorno y ser estable, así que si falta se detiene aquí con la instrucción exacta.
if [ -z "${POS_PIN_LOOKUP_KEY}" ]; then
    echo "❌ POS_PIN_LOOKUP_KEY no está definida."
    echo "   Genera una con:   php artisan pos:pin-key --show"
    echo "   y añádela a tu .env.docker (es un secreto: nunca al repositorio)."
    exit 1
fi

# --- 2/3/4. Inicialización (solo en el contenedor de app) --------------------
if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    # APP_KEY: la genera si viene vacía (útil en el primer arranque).
    if [ -z "${APP_KEY}" ] || [ "${APP_KEY}" = "base64:" ]; then
        # 'artisan key:generate' escribe la clave en el archivo .env, pero éste no
        # existe en la imagen (excluido a propósito en .dockerignore: es un secreto
        # y los valores reales ya llegan como variables de entorno vía env_file).
        # Sin un .env presente, el comando falla al intentar leerlo/reescribirlo.
        [ -f .env ] || touch .env
        echo "🔑 Generando APP_KEY ..."
        php artisan key:generate --force
    fi

    echo "📦 Ejecutando migraciones ..."
    php artisan migrate --force

    if [ "${RUN_SEED:-true}" = "true" ]; then
        echo "🌱 Sembrando catálogos, roles/permisos y super_admin (idempotente) ..."
        php artisan db:seed --force

        # Los roles se materializan POR ESTABLECIMIENTO y sus permisos se congelan al
        # crearlos, así que el seed (que solo toca las plantillas de team nulo) no basta:
        # sin esto, un rol o permiso nuevo del catálogo no llega a los tenants que YA
        # existen. Es idempotente; correrlo en cada arranque evita depender de que
        # alguien recuerde ejecutarlo a mano tras cambiar la matriz.
        echo "🔄 Resincronizando roles por establecimiento (idempotente) ..."
        php artisan roles:sincronizar --no-interaction
    fi

    php artisan storage:link || true

    if [ "${APP_ENV:-production}" = "production" ]; then
        echo "⚡ Cacheando configuración, rutas y vistas ..."
        php artisan config:cache
        php artisan route:cache
        php artisan view:cache
    else
        php artisan optimize:clear || true
    fi
fi

exec "$@"
