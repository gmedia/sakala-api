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

## Scheduler

Beberapa proses control plane berjalan lewat scheduler Laravel (`agent:assign-commands`, `agent:expire-commands`, `agent:mark-offline-nodes`, prune/usage signals). Jalankan `php artisan schedule:work` (atau `sail artisan schedule:work`) di terminal terpisah saat menguji alur agent secara lokal.

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

Test memakai SQLite in-memory agar cepat dan terisolasi (`composer test`). CI juga menjalankan suite yang sama di PostgreSQL (job `quality-pgsql`), jadi test tidak boleh bergantung pada kelonggaran SQLite (mis. presisi detik pada timestamp, `DROP TABLE` tanpa memperhatikan foreign key).

Test concurrency multi-sesi (`FOR UPDATE`, `lock_timeout`, proses paralel) berada di `tests/Feature/Concurrency`, group Pest `concurrency`. Mereka tidak memakai `RefreshDatabase` — baris harus ter-commit agar terlihat sesi kedua — sehingga skema dibuat ulang (`migrate:fresh`) sebelum tiap test dan group ini **dikecualikan** dari `composer test`. Jalankan terpisah di PostgreSQL:

```bash
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_DATABASE=sakala_test DB_USERNAME=sail DB_PASSWORD=password \
  composer test:concurrency
```

Di luar PostgreSQL group ini otomatis `skipped`. Dengan Sail: `sail artisan test --group=concurrency` setelah membuat database kosong khusus test (jangan arahkan ke database development, karena `migrate:fresh` menghapus isinya).
