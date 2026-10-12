# Opmoni

## Project structure

- `frontend/`: complete Nuxt application, including its existing sample server handlers.
- `backend/`: Laravel 13 backend without a frontend starter kit, Blade welcome page, Vite, or Node dependencies.
- `docs/` and `openspec/`: product decisions and planning artifacts.
- `services/fiscal/`: planned separate PHP fiscal service; not implemented.

## Development

Start Nuxt in one terminal:

```bash
cd frontend
pnpm install
pnpm dev
```

Start Laravel in another terminal:

```bash
cd backend
composer install
# On a fresh clone:
cp .env.example .env
php artisan key:generate
php artisan migrate
composer run dev
```

Laravel runs at `http://localhost:8000`, with a health check at `/up`. SQLite is the default local database. API routes belong in `backend/routes/api.php` and use the `/api` prefix. No authentication or frontend-to-backend integration has been implemented.

## Validation

```bash
pnpm --dir frontend lint
pnpm --dir frontend typecheck
pnpm --dir frontend build
cd backend
composer test
vendor/bin/pint
```

The earlier Nuxt-only access proposal remains a pending planning artifact. The subsequent request to initialize a Laravel backend supersedes its backend-location decision; product features still require their own implementation.
