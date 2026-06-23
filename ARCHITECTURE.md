# Architecture

## Peran Sistem

Sakala API adalah control plane. Ia menyimpan state dan metadata, melakukan autentikasi/authorization, menerima webhook, membuat command untuk agent, serta mempublikasikan event. Ia tidak menjalankan Docker, build aplikasi, Caddy, atau operasi host.

```text
sakala-console -> HTTPS/JSON -> sakala-api -> database/queue
                                      |
                                      +-> command records <-poll/report-> sakala-agent
```

## Boundary Repository

Frontend berada di `sakala-console`. Eksekusi runtime berada di `sakala-agent`. Referensi jaringan lokal berada di `sakala-infra`. Boundary ini mencegah browser atau Laravel mendapat akses Docker socket.

## API-first

Semua kontrak baru memakai JSON dan versi mayor di URL (`/api/v1`). Route dikelompokkan berdasarkan audience:

- App API untuk console first-party.
- Agent API untuk polling, claim, heartbeat, logs, dan completion.
- Admin API untuk operasi internal yang diautorisasi.
- Webhook endpoint untuk provider seperti GitHub.

Fondasi saat ini baru mengekspos service status. Domain route ditambahkan setelah kontrak MVP disetujui.

## Persistence

PostgreSQL menyimpan metadata control plane dan durable agent command. Entitas yang melewati boundary API/agent memakai UUIDv7, sedangkan event/log/audit append-only memakai bigint internal. Nilai status didefinisikan sebagai backed enum PHP dan disimpan sebagai string agar kontrak dapat berkembang tanpa migrasi native enum database.

Project dimiliki user secara langsung pada MVP. Workspace/team dan custom domain sengaja belum dimodelkan. Lihat [desain database](docs/DATABASE.md) untuk relasi, pola query, idempotensi, dan strategi index.

## Authentication

Console memakai Sanctum stateful SPA authentication melalui cookie HTTP-only dan proteksi CSRF. Agent tidak memakai session browser; ia memakai bearer token dan identitas agent. GitHub OAuth akan diorkestrasi melalui Socialite tanpa Fortify.

## Asynchronous Work

Queue digunakan untuk operasi yang tidak perlu menyelesaikan request secara sinkron. Reverb disiapkan untuk status deployment real-time, tetapi broadcaster default tetap `log` sampai credential dan channel domain tersedia.

## Struktur Bertumbuh

Struktur backend dibagi berdasarkan tanggung jawab, lalu dikelompokkan lagi per domain:

```text
app/
├── Actions/                # satu use case atau perubahan state
├── Data/                   # DTO immutable antar-layer
├── Enums/                  # state dan type domain
├── Events/                 # event domain/application
├── Http/
│   ├── Controllers/Api/V1/ # transport HTTP dan orchestration tipis
│   ├── Requests/Api/V1/    # authorization dan validation input
│   └── Resources/Api/V1/   # representasi JSON keluar
├── Jobs/                   # pekerjaan asynchronous
├── Models/                 # persistence dan relationship
├── Policies/               # authorization terhadap resource
├── Services/               # integrasi eksternal atau workflow panjang
└── Support/                # primitive lintas domain yang benar-benar umum
```

Folder hanya dibuat ketika memiliki implementasi nyata. Jangan menambahkan class kosong untuk meniru arsitektur. Domain baru mengikuti vertical slice seperti contoh `System`: controller memanggil Action, Action menghasilkan DTO, lalu Resource membentuk kontrak JSON.

### Pemisahan Tanggung Jawab

- **Controller** menerjemahkan request HTTP ke use case dan mengembalikan Resource. Controller tidak berisi query, validation rules, atau business logic.
- **Form Request** wajib untuk endpoint yang menerima input. Rules, normalisasi input, dan authorization request berada di `Http/Requests/Api/V1/<Domain>`.
- **Action** menjalankan satu use case yang namanya eksplisit, misalnya `CreateProjectAction` atau `ClaimAgentCommandAction`.
- **Data object** membawa payload typed dan immutable tanpa bergantung pada HTTP.
- **Resource** adalah boundary output API. Controller tidak menyusun payload domain dengan `response()->json()` secara manual.
- **Service** dipakai untuk integrasi eksternal atau workflow yang dipakai beberapa Action. Service bukan tempat menampung logic yang belum jelas kepemilikannya.
- **Policy** menjadi sumber keputusan authorization berbasis model; controller dan Action tidak menyalin aturan akses.

## Dokumentasi API

Scramble menghasilkan OpenAPI 3.1 langsung dari route, Form Request, return type controller, dan API Resource. UI berada di `/docs/api`; dokumen JSON berada di `/docs/api.json`. Dokumentasi hanya terbuka otomatis pada environment `local`. Environment lain harus mengaktifkan `SCRAMBLE_ENABLED` secara eksplisit.

Spesifikasi ikut diperiksa oleh `composer check`. Endpoint baru dianggap belum selesai jika OpenAPI tidak dapat digenerasikan atau kontraknya tidak tercermin dengan benar.
