# =============================================================================
#  SISTEMA CORDES — una sola imagen que sirve el SPA y la API
#
#  El repositorio tiene dos proyectos (backend/ Laravel y frontend/ React) y
#  Railway no adivina cómo se arman. Esto lo dice explícito.
#
#  Van juntos en un solo servicio a propósito: mismo dominio para el SPA y
#  para /api. Así no hay CORS que configurar, ni una segunda URL que darle a
#  CORDES, ni dos servicios que se puedan desincronizar.
# =============================================================================


# -----------------------------------------------------------------------------
#  1. El SPA
#
#  VITE_API_URL se hornea en el build: Vite reemplaza el valor dentro del
#  bundle, no lo lee al arrancar. Va "/api" —relativo— porque la API sale por
#  este mismo dominio.
# -----------------------------------------------------------------------------
FROM node:22-alpine AS spa

WORKDIR /spa

# Primero los lock, así el `npm ci` se cachea mientras no cambien.
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci

COPY frontend/ ./

ENV VITE_API_URL=/api
ENV VITE_APP_NAME=CORDES

RUN npm run build


# -----------------------------------------------------------------------------
#  2. Las dependencias de PHP
#
#  --no-dev: phpunit y compañía no hacen falta para servir.
# -----------------------------------------------------------------------------
FROM composer:2 AS vendor

WORKDIR /app

COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY backend/ ./
RUN composer dump-autoload --optimize --no-dev --no-interaction


# -----------------------------------------------------------------------------
#  3. El servidor
#
#  FrankenPHP: un binario que sirve public/ y cae a index.php. Los archivos
#  del SPA (index.html, assets/) salen directo sin pasar por PHP.
# -----------------------------------------------------------------------------
FROM dunglas/frankenphp:php8.4

# pdo_mysql para la base; gd y zip los pide dompdf para armar los PDF.
RUN install-php-extensions pdo_mysql gd zip bcmath opcache

WORKDIR /app

COPY --from=vendor /app ./

# El build del SPA se mezcla con public/: index.html y assets/ quedan al lado
# del index.php de Laravel, que atiende lo que no sea un archivo real.
COPY --from=spa /spa/dist/ ./public/

RUN chmod -R 775 storage bootstrap/cache

EXPOSE 8080

# SERVER_NAME toma el puerto que asigna Railway. config:cache acelera el
# arranque; route:cache no se puede porque las rutas usan closures.
CMD ["sh", "-c", "export SERVER_NAME=\":${PORT:-8080}\" && php artisan config:cache && php artisan migrate --force && exec frankenphp run --config /etc/caddy/Caddyfile --adapter caddyfile"]
