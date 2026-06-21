# Development

## Sail

```bash
cp .env.example .env
composer install
php artisan key:generate
./vendor/bin/sail up -d
./vendor/bin/sail artisan migrate
```

Services: API `:8000`, PostgreSQL `:5432`, Redis `:6379`, Mailpit UI `:8025`, dan port Reverb `:8081`. Jalankan worker/Reverb pada terminal terpisah saat dibutuhkan.

## Tanpa Docker

Sesuaikan host PostgreSQL/Redis/Mail di `.env`, lalu jalankan `composer dev`. PHP extension yang dibutuhkan harus tersedia pada host.

## Quality

```bash
composer lint
composer analyse
composer docs:analyse
composer test
composer audit --locked
```

Dokumentasi API lokal tersedia di `/docs/api`. Jalankan `composer docs:export` untuk menghasilkan `api.json`; file export bersifat generated dan tidak di-commit.

Test memakai SQLite in-memory agar cepat dan terisolasi. Tambahkan PostgreSQL-specific integration test jika mulai memakai fitur database yang tidak kompatibel dengan SQLite.
