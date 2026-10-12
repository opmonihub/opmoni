# Opmoni backend

Laravel 13 backend template. The Nuxt application lives in `../frontend/`. This directory has no frontend starter kit, Blade welcome page, Vite configuration, or Node dependencies.

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
```

SQLite is the default local database. Configure a different database through `.env` when needed.

## Development

```bash
composer run dev
```

The backend runs at `http://localhost:8000`. Its health check is `/up`; application API routes belong in `routes/api.php` and use the `/api` prefix. Authentication and application endpoints have not been implemented.

## Tests and formatting

```bash
composer test
vendor/bin/pint
```
