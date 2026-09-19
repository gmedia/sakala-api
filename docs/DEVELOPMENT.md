# Development

## Sail

```bash
cp .env.example .env
composer install
php artisan key:generate
./vendor/bin/sail up -d
./vendor/bin/sail artisan migrate
```

Services: API `:8000`, PostgreSQL `:5432`, Valkey `:6379` (diakses lewat client Redis Laravel, `REDIS_HOST=valkey`), Mailpit UI `:8025`, dan port Reverb `:8081`. Jalankan worker/Reverb pada terminal terpisah saat dibutuhkan.

### Upgrade checkout lama dari Redis ke Valkey

Runtime lokal sebelumnya memakai service `redis`. `.env.example` sudah menunjuk ke Valkey, tetapi `.env` yang sudah ada tidak berubah otomatis dan container Redis lama menjadi *orphan* yang tidak dihapus `sail down` biasa — bila masih hidup ia menahan port `6379` sehingga Valkey gagal start. Setelah menarik perubahan ini:

1. Ubah `.env`: `REDIS_HOST=valkey`.
2. Bila `FORWARD_REDIS_PORT` pernah dikustom, ganti namanya menjadi `FORWARD_VALKEY_PORT` (nilai sama).
3. `./vendor/bin/sail down --remove-orphans` untuk menghentikan dan menghapus container Redis lama.
4. `./vendor/bin/sail up -d`.
5. Volume `sail-redis` hanya berisi cache dan boleh dihapus: `docker volume rm sakala-api_sail-redis` (nama prefix mengikuti nama direktori project; lihat `docker volume ls`).

## Scheduler

Beberapa proses control plane berjalan lewat scheduler Laravel (`agent:assign-commands`, `agent:expire-commands`, `agent:mark-offline-nodes`, prune/usage signals). Jalankan `php artisan schedule:work` (atau `sail artisan schedule:work`) di terminal terpisah saat menguji alur agent secara lokal.

## Tanpa Docker

Sesuaikan host PostgreSQL/Valkey (atau Redis)/Mail di `.env`, lalu jalankan `composer dev`. PHP extension yang dibutuhkan harus tersedia pada host.

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

Di luar PostgreSQL group ini otomatis `skipped` — dan `phpunit.xml` memaksa SQLite in-memory, sehingga perintah `sail artisan test` biasa **tidak** menjalankannya. Dengan Sail, override koneksi secara eksplisit ke database `testing` yang dibuat Sail (jangan arahkan ke database development, karena `migrate:fresh` menghapus isinya):

```bash
sail exec -e DB_CONNECTION=pgsql -e DB_HOST=pgsql -e DB_DATABASE=testing \
  -e DB_USERNAME=sail -e DB_PASSWORD=password laravel.test composer test:concurrency
```

Output yang benar menampilkan 8 test `passed`; `8 skipped` berarti koneksi PostgreSQL belum terpasang.
