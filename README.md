# CareerEdge

Interview simulation and CV review marketplace. Candidates book timed chat or voice sessions with
freelance HR or technical recruiters, pay up front (the money is held until the session is done),
and get structured feedback.

**Stack:** Laravel 13 (PHP 8.3+) · PostgreSQL 16 · Sanctum · Reverb (WebSockets) · LiveKit (voice)

## Docs

| File | What it is |
|---|---|
| [`docs/PLAN.md`](docs/PLAN.md) | Scope, architecture, domain model, session lifecycle, business rules, milestones |
| [`docs/openapi.yaml`](docs/openapi.yaml) | **API contract.** Mobile and web clients are built from it, and it is the source of truth |
| [`docs/MOCK-API.md`](docs/MOCK-API.md) | How the app team runs a mock of the whole API before the backend is finished |

### For app developers

- **Start here:** [`docs/MOCK-API.md`](docs/MOCK-API.md). Run `docker compose up mock` to get a
  working API at `http://localhost:4010` with realistic data.
- View the contract by pasting `docs/openapi.yaml` into <https://editor.swagger.io>, or by
  running `npx @redocly/cli preview-docs docs/openapi.yaml`.
- Generate a client with `openapi-generator-cli generate -i docs/openapi.yaml -g dart-dio`.
  Other generators: `kotlin`, `swift5`, `typescript-axios`.
- Operations marked `x-phase: 2..5` are planned features. Their shape is fixed, but they return
  404 until the phase ships.
- The realtime chat events (WebSockets) are in `docs/PLAN.md` §5.

## Run the backend

Needs PHP 8.3+ with the `pdo_pgsql` extension, Composer, and a PostgreSQL 16 server.

```bash
composer install
cp .env.example .env && php artisan key:generate

docker compose up -d postgres          # PostgreSQL on localhost:5432 (or use your own server)
php artisan migrate --seed             # tables + the list of specializations
php artisan serve --port=8080
# → http://localhost:8080/health  and  http://localhost:8080/api/v1/...
```

On Ubuntu the PostgreSQL driver is `sudo apt install php8.5-pgsql` (match your PHP version).

**Upload size.** CVs can be up to 5 MB, but PHP's default limit is 2 MB, and PHP rejects a
bigger file before the app sees it (the API then answers `413 FILE_TOO_LARGE`). On every
server, set this in `php.ini`:

```ini
upload_max_filesize = 6M
post_max_size = 8M
```

CV files are stored on the `local` disk (`storage/app/private`). For S3 or MinIO, install
`league/flysystem-aws-s3-v3`, fill the `AWS_*` values in `.env`, and set `CV_DISK=s3`.

## Tests

```bash
php artisan test
```

Feature tests send real HTTP requests to the app and use a real PostgreSQL database named
`careeredge_test`. The `postgres` service in `docker-compose.yml` creates it. On your own
server, create it once: `createdb careeredge_test`.

Code style is Laravel Pint: `vendor/bin/pint`.

## Project layout

```
app/Modules/Identity/      users, access tokens (Sanctum), rotating refresh tokens, role check
app/Modules/Profiles/      candidate and recruiter profiles, specializations, CV files
app/Support/Http/          the contract's error format (problem+json) and date format
routes/api.php             lists each module's routes; everything is under /api/v1
tests/Feature/<Module>/    HTTP tests per module
```

Each module has its own service provider, routes, migrations, models and controllers.
The rules for modules are in [`docs/PLAN.md`](docs/PLAN.md) §2.

## Status

Phase 1 (Core MVP), milestones from [`docs/PLAN.md`](docs/PLAN.md) §6:

| Milestone | State |
|---|---|
| M0 Skeleton, error format, health check, CI | Done |
| M1 Identity: register, login, refresh, logout, me | Done |
| M2 Profiles, specializations, CV upload | Done |
| M3 Offerings, availability, recruiter search | Next |
| M4–M8 | Not started |
