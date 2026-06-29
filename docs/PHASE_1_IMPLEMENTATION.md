# IRNB Migration - Phase 1 Implementation

## What is done

- Laravel 13 project scaffolded in `irnb-laravel/`.
- Inertia + Vue 3 + Tailwind stack installed via Breeze.
- Initial normalized SQL schema created for:
  - countries, states, communes
  - categories, member_jobs, positions
  - subscriptions + category_subscription pivot
  - players + emergency contacts + achievements
  - transactions
  - player_subscriptions
  - equipment_catalogs, equipment_items, equipment_histories
  - website_configs singleton
- `users` table extended with migration fields required by legacy data model.
- Eloquent models added with casts and relations for all new tables.
- Environment defaults switched to PostgreSQL in `.env.example`.
- Mongo JSON import command implemented as `irnb:import-mongo-json` with legacy ID mapping for:
  - lookups (countries, categories, jobs, positions, subscriptions)
  - users and players
  - transactions
  - player_subscriptions

## Key migration files

- `database/migrations/2026_04_07_073426_create_irnb_domain_tables.php`
- `database/migrations/2026_04_07_073427_add_irnb_fields_to_users_table.php`

## Run locally (PostgreSQL)

1. Copy environment

```bash
cp .env.example .env
```

2. Update DB credentials in `.env`

```dotenv
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=irnb_club
DB_USERNAME=postgres
DB_PASSWORD=postgres
```

3. Generate key and migrate

```bash
php artisan key:generate
php artisan migrate
```

4. Install frontend deps and build

```bash
npm install
npm run dev
```

5. Start app

```bash
php artisan serve
```

## Next implementation slice

- Add seeders for Algeria states, categories, jobs, positions, and default website config.
- Extend ETL coverage for equipment catalogs/items/histories and website config singleton.
- Add user migration hardening for password-reset onboarding workflow.
- Build remaining domain services:
  - Equipment purchase and history event logging.
- Create Inertia pages for Dashboard, Players, Subscriptions, Transactions, Equipment, Config.
