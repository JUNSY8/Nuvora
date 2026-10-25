# Desarrollo local

Nuvora Cloud se ejecuta como monolito modular. La especificación de producto sigue en el `README.md` de la raíz. Este documento solo describe cómo levantar la versión 0.5.

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

pgAdmin, para ver y consultar la base desde el navegador, queda en `http://localhost:8081`. Corre en modo escritorio, sin pantalla de ingreso propia, y trae registrado el servidor `Nuvora local` (`docker/pgadmin/servers.json`). Al abrirlo pide la contraseña del usuario `nuvora`: es `DB_PASSWORD` del `.env` (`nuvora_local_dev` si no está definida). Las tablas están en `Nuvora local > Databases > nuvora > Schemas > public > Tables`. El archivo de servidores solo se lee la primera vez; para recargarlo, borra el volumen `pgadmin_data`. Solo escucha en `127.0.0.1` y es una herramienta de desarrollo: no va a producción. Las tablas con RLS se ven completas porque el rol local es dueño de las tablas (ver ADR-012).

La cuenta de desarrollo que crea el seeder, solo con `APP_ENV=local`, es `admin@nuvora.test` con la contraseña `password`. Esa cuenta queda verificada y como propietaria de la empresa `Nuvora Demo`, con el parqueadero `Sede Centro` (4 zonas, 74 espacios, 2 puertas y 5 dispositivos), 6 clientes (personas, una empresa y un corporativo), 7 vehículos (uno bloqueado) y 9 vehículos dentro del parqueadero, uno de ellos sin espacio asignado. Los espacios ocupados vienen de sesiones reales, así que se liberan registrando la salida en `Entradas y salidas`. Si un espacio de la demo está fuera de servicio, el seeder omite esa entrada.

El servicio `frontend` vigila los archivos por sondeo (`VITE_USE_POLLING`), porque el volumen montado desde Windows o macOS no avisa los cambios dentro del contenedor. No ejecutes otro `vite` en el host en el puerto 5173: el navegador puede terminar en cualquiera de los dos. `FRONTEND_URL` apunta a la interfaz que recibe los enlaces de restablecimiento; la verificación de correo llega a la API y redirige a esa interfaz.

## Pruebas fuera de Docker

```bash
composer install
php artisan test

npm --prefix frontend install
npm --prefix frontend test
npm --prefix frontend run build
```

Las pruebas de backend usan SQLite en memoria. No usan la base de PostgreSQL del entorno local.

Para validar las restricciones, la RLS y el comportamiento transaccional de PostgreSQL, ejecuta la suite contra una base aparte. Dentro del contenedor las variables de `docker-compose.yml` ganan sobre `phpunit.xml`, así que hay que pasar la base de pruebas de forma explícita; de lo contrario `RefreshDatabase` borraría la base de desarrollo:

```bash
docker compose exec postgres psql -U nuvora -d nuvora -c "create database nuvora_test;"

docker compose exec -e DB_DATABASE=nuvora_test -e APP_ENV=testing -e SESSION_DRIVER=array -e CACHE_STORE=array -e QUEUE_CONNECTION=sync -e MAIL_MAILER=array app php artisan test
```
