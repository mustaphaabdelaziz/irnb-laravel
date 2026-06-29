# IRNB Club — Management System

Club-management system for an amateur sports association, rebuilt from an Express/EJS/MongoDB
app onto **Laravel 13 + Inertia.js + Vue 3 + Tailwind**. Arabic-first (RTL) with French and
English. Covers members/players, subscriptions & payments, finance (income/expense), equipment
inventory with a rental lifecycle, and a database-backed website/branding configuration.

## Stack

- **Backend:** Laravel 13 (PHP 8.3), Eloquent, Laravel Breeze auth (session-based), Sanctum.
- **Frontend:** Inertia.js + Vue 3 + Tailwind, Vite. i18n via `vue-i18n` (`resources/js/i18n/{ar,fr,en}.json`).
- **Images:** local `public` disk via `App\Services\Storage\FileStorageService` (resize/compress with `intervention/image`).
- **Charts:** Chart.js (`vue-chartjs`) on the dashboard.
- **PDF:** `mpdf/mpdf` (chosen for correct Arabic/RTL shaping) via `App\Services\Pdf\PdfService`.
- **Excel:** `phpoffice/phpspreadsheet` (player import template + subscription export).

## Setup

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate

# Pick a database in .env (sqlite for quick local dev, pgsql/mysql for production), then:
php artisan migrate --seed       # creates schema + lookups + the initial admin (see ADMIN_* in .env)
php artisan storage:link         # exposes uploaded files under /storage

# Run (two terminals, or `composer run dev`):
npm run dev
php artisan serve
```

Default admin (from `AdminUserSeeder`): **admin@irnb.local / password** — change `ADMIN_*` in `.env`
before seeding in production.

## Go-live checklist

1. Set `APP_ENV=production`, `APP_DEBUG=false`, a strong `APP_KEY`, and the real `APP_URL`.
2. Configure a production DB (`pgsql`/`mysql`) and run `php artisan migrate --force`.
3. Set strong `ADMIN_*` credentials, then `php artisan db:seed --force`.
4. Switch `MAIL_MAILER=smtp` and fill SMTP credentials (password-reset & verification mail).
5. `php artisan storage:link`, `npm run build`, then cache config/routes/views.

## Key features

- **Members/Players:** CRUD, photo upload, search/filter, per-player outstanding-debt column,
  bulk **XLSX/CSV import** with a downloadable RTL template (`Players → Import`).
- **Admin user/member management:** approve pending members, manage roles/status (`Members` in the
  sidebar, with a pending-approval badge).
- **Finance:** income/expense transactions with receipts, per-subscription payment tracking.
- **Equipment:** catalog + items, rental lifecycle (rent/return/repair/lost) with an immutable history view.
- **Dashboard:** player/finance stats, profit-loss status, and category/monthly/subscription charts.
- **PDF documents:** payment receipt, member card, and yearly financial summary (Arabic/RTL).
- **Settings:** categories, jobs, positions, and the website/branding configuration.

## Testing & code style

```bash
php artisan test            # feature + unit tests
./vendor/bin/pint           # code style (Laravel preset)
```

## Migrating data from the old MongoDB app (optional)

A one-off importer maps exported Mongo collections into the SQL schema:

```bash
# place JSON exports in storage/app/mongo-export/, then:
php artisan irnb:import-mongo-json
```

Legacy password hashes are intentionally not migrated — users reset their passwords after cutover.
