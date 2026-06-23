# Sakala API

Sakala API adalah control plane API-first untuk platform deployment open-source Sakala. Repository ini mengelola kontrak aplikasi, autentikasi first-party, metadata project dan deployment, komunikasi agent, queue, event, serta integrasi provider.

## Batas Tanggung Jawab

- `sakala-console`: antarmuka Svelte/SvelteKit di `app.sakala.dev`.
- `sakala-api`: Laravel API/control plane di `api.sakala.dev`.
- `sakala-agent`: executor runtime berbasis Rust.
- `sakala-infra`: playground dan kontrak local runtime.
- `sakala-landing`: landing page dan dokumentasi publik.

API tidak merender UI dan tidak bergantung pada Inertia atau Fortify. Browser first-party nantinya menggunakan Sanctum SPA cookie authentication. Agent dan machine client akan memakai bearer token terpisah.

## Stack

- PHP 8.5 dan Laravel 13
- PostgreSQL, Redis, queue database
- Laravel Sanctum, Socialite, dan Reverb
- Scramble untuk dokumentasi OpenAPI 3.1
- Pest, Pint, Larastan, dan Laravel Boost
- Laravel Sail untuk runtime lokal

## Quickstart

```bash
cp .env.example .env
composer install
php artisan key:generate
./vendor/bin/sail up -d
./vendor/bin/sail artisan migrate
```

Untuk menambahkan fixture development yang aman dijalankan ulang:

```bash
./vendor/bin/sail artisan db:seed
```

Pastikan host lokal `api.sakala.localhost` dan `app.sakala.localhost` mengarah ke loopback. Endpoint awal tersedia di `http://api.sakala.localhost:8000/api/v1`.

Dokumentasi interaktif tersedia di `http://api.sakala.localhost:8000/docs/api` dan spesifikasi OpenAPI di `/docs/api.json`.

## Perintah

```bash
composer dev          # HTTP server tanpa Docker
composer queue        # queue worker lokal
composer reverb       # WebSocket server
composer lint         # format source
composer analyse      # static analysis
composer docs:analyse # validasi hasil generasi OpenAPI
composer docs:export  # export spesifikasi ke api.json
composer test         # test suite
composer check        # lint check + analysis + tests
composer audit        # security advisories
```

Padanan Sail tersedia melalui `make up`, `make test`, dan target lain di `Makefile`.

## Dokumentasi

- [Arsitektur](ARCHITECTURE.md)
- [Konfigurasi](docs/CONFIGURATION.md)
- [Konvensi API](docs/API_CONVENTIONS.md)
- [Database](docs/DATABASE.md)
- [Dokumentasi OpenAPI](docs/OPENAPI.md)
- [Autentikasi](docs/AUTHENTICATION.md)
- [Development](docs/DEVELOPMENT.md)
- [Security](SECURITY.md)

Sakala API menggunakan Apache License 2.0. GMEDIA mendukung Sakala sebagai founding sponsor dan initial infrastructure supporter; lihat [governance](GOVERNANCE.md) dan [sponsor](SPONSORS.md).
