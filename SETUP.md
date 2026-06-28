# Zain Ticket System — Setup Guide

The project has **two repositories** that run together:

| Part | Folder | Stack | URL (dev) |
|------|--------|-------|-----------|
| Backend (API) | `tktzainbackend` | Laravel 12 (PHP) + SQLite | http://localhost:8000 |
| Frontend (UI) | `zain-tkt` | Vue 3 + Vite | http://localhost:5173 |

Run the **backend first**, then the frontend.

---

## 1. Prerequisites

Install these once:

- **PHP 8.2+** with the `sqlite3`/`pdo_sqlite` extensions (bundled with most PHP installs)
- **Composer** (https://getcomposer.org)
- **Node.js 22.18+** (or 24.12+) and npm — for the frontend

Quick check:

```bash
php -v        # >= 8.2
composer -V
node -v       # >= 22.18
```

---

## 2. Backend (`tktzainbackend`)

```bash
cd tktzainbackend

# 1. Install PHP dependencies
composer install

# 2. Create your environment file
cp .env.example .env

# 3. Generate the app key
php artisan key:generate

# 4. Create the SQLite database file (it is NOT committed to git)
touch database/database.sqlite

# 5. Create the tables
php artisan migrate

# 6. Seed the default login accounts (IMPORTANT)
php artisan db:seed

# 7. Run the API server
php artisan serve
```

The API is now at **http://localhost:8000** (base path `/api`).

> Leave this terminal running.

### Default login accounts (from the seeder)

You can sign in with the **email or the username** (the name), plus the password:

| Role | Username | Email | Password |
|------|----------|-------|----------|
| Super Admin | `Admin` | `admin@example.com` | `admin` |
| User | `User` | `user@example.com` | `user` |
| Staff | `Staff` | `staff@example.com` | `staff` |

---

## 3. Frontend (`zain-tkt`)

In a **second terminal**:

```bash
cd zain-tkt

# 1. Install dependencies
npm install

# 2. Start the dev server
npm run dev
```

Open **http://localhost:5173** and log in with one of the accounts above.

> The frontend talks to `http://localhost:8000/api` by default. If your backend
> runs on a different URL, create a `.env` file in `zain-tkt`:
>
> ```
> VITE_API_URL=http://localhost:8000/api
> ```

---

## 4. Common tasks

```bash
# Reset the database and re-seed (wipes all tickets/users)
php artisan migrate:fresh --seed

# Re-run only the seeders
php artisan db:seed

# List all API routes
php artisan route:list --path=api

# Build the frontend for production (in zain-tkt)
npm run build
```

---

## 5. Troubleshooting

- **`database … does not exist` on migrate** — you skipped step 4. Run `touch database/database.sqlite`, then `php artisan migrate`.
- **Login fails / "Invalid credentials"** — you skipped the seeder. Run `php artisan db:seed`.
- **CORS errors in the browser** — make sure the frontend runs on `http://localhost:5173` (allowed in `config/cors.php`) and the backend on `http://localhost:8000`.
- **Port already in use** — run the API on another port with `php artisan serve --port=8001` and set `VITE_API_URL=http://localhost:8001/api` in `zain-tkt/.env`.
- **"vite: command not found"** — you ran an npm command in the wrong folder. Frontend npm commands run inside `zain-tkt`.

---

## 6. Roles overview

- **Super Admin** — manage users (create/edit/delete, activate, change passwords), create tickets, edit/delete/complete/reopen any ticket.
- **User** — create tickets, view pending & completed, edit their own *pending* tickets, reply to *reopened* tickets. Cannot change status.
- **Staff** — change ticket status (complete / reopen with a reason) and fill the Alwaseet Company. Cannot edit ticket content.
