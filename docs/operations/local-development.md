# Desarrollo local

Nuvora Cloud se ejecuta como monolito modular. La especificación de producto sigue en el `README.md` de la raíz. Este documento solo describe cómo levantar la versión 0.2.

## Requisitos

Docker, Docker Compose, Git. PHP 8.4 y Node.js 22 solo hacen falta si se ejecutan pruebas fuera de los contenedores.

## Arranque

```bash
cp .env.example .env

docker compose up -d --build

docker compose exec app php artisan key:generate

docker compose exec app php artisan migrate --seed
```

La interfaz queda en `http://localhost:5173` y la API en `http://localhost:8080`. Mailpit queda en `http://localhost:8025`.

La cuenta de desarrollo que crea el seeder, solo con `APP_ENV=local`, es `admin@nuvora.test` con la contraseña `password`. Esa cuenta queda verificada y como propietaria de la empresa `Nuvora Demo`. `FRONTEND_URL` apunta a la interfaz que recibe los enlaces de restablecimiento; la verificación de correo llega a la API y redirige a esa interfaz.

## Pruebas fuera de Docker

```bash
composer install
php artisan test

npm --prefix frontend install
npm --prefix frontend test
npm --prefix frontend run build
```

Las pruebas de backend usan SQLite en memoria. No usan la base de PostgreSQL del entorno local.
