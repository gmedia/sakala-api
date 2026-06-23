# AGENTS.md

Panduan ini berlaku untuk AI agent dan Codex yang bekerja di `sakala-api`.

## Product Boundary

- Repository ini adalah Laravel API/control plane, bukan frontend.
- Jangan menambahkan Blade UI, Inertia, Fortify starter flow, Vite, Tailwind, atau package JavaScript.
- Jangan menambahkan Team/Membership sebelum kontrak domain disetujui.
- Console browser memakai Sanctum SPA cookie; agent/machine client memakai bearer token terpisah.
- Console dan API tidak boleh mengakses Docker socket. Runtime operation hanya milik `sakala-agent`.
- Jangan hardcode secret, credential, token, atau origin produksi.

## Architecture

- Semua endpoint aplikasi berada di `/api/v1` dan dikelompokkan per domain.
- Controller harus tipis; validasi endpoint yang menerima input wajib memakai Form Request di `Http/Requests/Api/V1/<Domain>`.
- Orkestrasi satu use case memakai Action. Gunakan Service hanya untuk integrasi atau workflow lintas Action, bukan sebagai penampung logic generik.
- Gunakan DTO immutable di `Data` untuk payload antar-layer dan Policy untuk authorization berbasis resource.
- Semua response sukses domain wajib memakai API Resource di `Http/Resources/Api/V1/<Domain>`. Jangan menyusun payload domain dengan `response()->json()` di controller.
- Pastikan Scramble dapat menginfer input dan output endpoint; gunakan annotation hanya jika inference dari type/rules tidak cukup.
- Pisahkan route app, admin, agent, webhook, dan auth ketika domain tersebut mulai dibuat.
- Hindari abstraction prematur dan package baru tanpa kebutuhan konkret.

## Database

- Gunakan UUIDv7 untuk identifier domain yang melintasi API atau agent; gunakan bigint untuk data append-only internal.
- Status/type domain wajib memakai backed enum di `app/Enums` dan kolom string, bukan native database enum.
- Tambahkan index berdasarkan query path yang nyata dan dokumentasikan alasannya di `docs/DATABASE.md`.
- Alokasi deployment sequence, claim command, dan perubahan state yang bersaing wajib dilakukan dalam transaction.
- Secret memakai encrypted cast atau hash satu arah dan harus disembunyikan dari serialization.
- Seeder development harus idempotent, bebas credential nyata, dan dibatasi ke environment `local`/`testing`.
- Jangan menambahkan workspace/team, custom domain, atau layanan managed sebelum kontrak MVP berubah.

## Laravel Conventions

- Gunakan `php artisan make:* --no-interaction` untuk file framework.
- Gunakan explicit return type, constructor property promotion, dan kurung kurawal untuk control structure.
- Gunakan Eloquent relationship dan eager loading; hindari raw query jika API framework cukup.
- Baca config melalui `config()`, bukan `env()` di luar file konfigurasi.
- Background work yang lambat harus menggunakan queue.

## Quality Gate

Sebelum menyelesaikan perubahan, jalankan:

```bash
composer lint
composer analyse
composer docs:analyse
composer test
```

Gunakan Pest untuk test. Setiap perubahan perilaku memerlukan happy path, validation/authorization failure, dan regression test yang relevan.

## Documentation and Commits

- Perbarui dokumentasi jika mengubah route, config, boundary, atau workflow.
- Ikuti Conventional Commits, misalnya `feat(api): add project endpoint`.
- Jangan mengubah governance, sponsorship, atau license tanpa persetujuan maintainer.
