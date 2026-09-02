# IronCore API

Laravel 13 API foundation for IronCore's multi-tenant gym-management platform.

## Requirements

- PHP 8.3+
- Composer 2
- PostgreSQL
- Redis

## Local setup

```bash
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

Fresh installations no longer create a fixed demo login. Before opening the site, generate a high-entropy one-time owner setup key, place only its SHA-256 hex digest in `INITIAL_SUPER_ADMIN_SETUP_KEY_HASH`, and give the plaintext key directly to the owner. The web app will guide the owner through creating the first Super Admin; the setup endpoint closes permanently as soon as a Super Admin exists. Tenant accounts created before platform ownership do not block this setup.

The API is available below `/api/v1`. Authentication uses Laravel Sanctum. Every tenant request must resolve a gym and pass server-side membership checks; sending a different `X-Gym-ID` is never sufficient to gain access.

## Current endpoints

- `GET|POST /api/v1/setup/super-admin` — first owner only, while no Super Admin exists
- `POST /api/v1/auth/login`
- `GET /api/v1/auth/me`
- `POST /api/v1/auth/logout`
- `GET /api/v1/gyms`
- `POST /api/v1/gyms` — super admin only
- `GET /api/v1/gyms/{gym}`
- `PATCH /api/v1/gyms/{gym}` — authorised management roles

## Tests

```bash
composer test
```

The backend feature tests cover credential handling, token issuance, tenant isolation and super-admin access. Root-level contract tests also verify tenancy, audit, role, currency and database-index invariants without requiring a PHP runtime.
