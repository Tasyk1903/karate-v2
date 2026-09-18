# KarateRating v2

## Project Documentation

- [Project guide: code map, reusable components and business rules](docs/project-guide.md)
- [Remaining scope: organizer/secretary and mobile trainer](docs/remaining-scope.md)

Clean rewrite of the KarateRating platform.

Repository: [Tasyk1903/karate-v2](https://github.com/Tasyk1903/karate-v2).
This repository contains the Laravel backend and Vue web application. The legacy
project and Flutter application are separate sibling projects, not included here.
Local `.env` files, private keys, database dumps, uploaded media, dependencies and
generated builds must not be committed. Only sanitized environment examples are
versioned; install dependencies and build the frontend after cloning.

## Stack

- Laravel
- PHP `^8.5`
- Vue.js with Vite
- No Filament
- No Inertia

## Legacy Reference

The previous project lives at:

```text
/Users/artemyablochnyi/projects/karate/karaterating
```

Use it to understand existing behavior, roles, tournament flows, rating rules, PDF/offline logic, and data relationships. Port behavior intentionally into the new codebase instead of copying legacy structure blindly.

## Frontend

Vue is mounted from `resources/js/app.js` into the Blade shell at `resources/views/welcome.blade.php`.

Brand assets are stored in:

```text
public/brand
```

The initial palette is based on the KR logo: red, black, white, neutral gray, and warm gold accents.

## Local Docker

Docker files are included for PHP 8.5, nginx, and MySQL 8.4.

```bash
cp .env.docker.example .env
docker compose up -d --build
docker compose exec app php artisan key:generate
```

On first MySQL volume creation, Docker imports the legacy dump from:

```text
../karaterating/karate_production.sql
```

The app is exposed at:

```text
http://localhost:8080
```

## Production

The deployed site is https://karate-rating.ru. Use `compose.production.yaml`,
not the local Compose file. Production runs PHP 8.5/FPM, nginx, an isolated MySQL
8.4, a panel export worker and the Laravel scheduler. Only nginx is published,
on `127.0.0.1:18080`; the host nginx terminates HTTPS for this domain.

The server checkout is `/var/www/karate_ratin_usr/data/www/karate-rating.ru/karaterating`.
Its private `.env` supplies separate `DB_PASSWORD` and
`DOCKER_MYSQL_ROOT_PASSWORD`, `APP_KEY`, S3 and mail configuration. Never replace
it with a development example or reuse the host MySQL credentials. Database
files and deployment backups are under the ignored `.deployment/` directory.

```bash
docker compose -f compose.production.yaml ps
docker compose -f compose.production.yaml logs --tail=100 app panel-worker scheduler
docker compose -f compose.production.yaml exec --user 82:82 app php artisan migrate:status
```

See [production deployment and updates](docs/project-guide.md#production)
before changing code, rebuilding containers or running migrations. Never run
`migrate:fresh`, development seeders or volume cleanup on this deployment.
