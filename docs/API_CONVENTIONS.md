# API Conventions

## Versioning

Endpoint publik aplikasi dimulai dari `/api/v1`. Breaking change memakai versi mayor baru; penambahan field kompatibel tetap berada pada versi aktif.

## Representation

Response sukses wajib dibentuk melalui Laravel API Resource dan memakai object `data`. Collection memakai `Resource::collection()` sehingga dapat menambahkan `links` dan `meta`. Controller tidak boleh membangun payload domain dengan `response()->json()`. Satu-satunya pengecualian envelope adalah `POST /api/agent/v1/commands/{command}/repository-credential`, yang oleh kontrak agent mengembalikan object `{ "username", "token" }` langsung (Resource dengan `$wrap = null`); lihat [Agent API](AGENT_API.md#repository-credential).

Endpoint yang menerima body, query, atau input kompleks wajib menggunakan Form Request. Letakkan class di `app/Http/Requests/Api/V1/<Domain>`; jangan memanggil `$request->validate()` atau `Validator` langsung dari controller. Authorization berbasis resource tetap menggunakan Policy.

```json
{
  "data": {
    "service": "Sakala API",
    "status": "ok",
    "api_version": "v1"
  }
}
```

Gunakan ISO 8601 untuk waktu, UUID/ULID bila identifier publik membutuhkan non-sequential ID, dan idempotency key pada operasi deployment atau project control yang berisiko diduplikasi.

## Agent deployment reports

Endpoint machine agent berada di `/api/agent/v1` dan memakai `Authorization: Bearer <agent-token>` serta `X-Agent-Id`. Hanya agent yang tercatat sebagai `agent_node_id` pada command yang berstatus `Claimed` atau `Running` yang boleh melaporkan data:

- `POST /commands/{command}/events` menerima payload event tunggal yang kompatibel dengan agent saat ini, atau batch `{ "events": [...] }`.
- `POST /commands/{command}/logs` menerima payload log tunggal yang kompatibel dengan agent saat ini, atau batch `{ "logs": [...] }`.

Batch dibatasi oleh `pilot_limits.log_bounds`. `max_request_bytes` membatasi ukuran body setiap request dan ditolak dengan `413`; `max_line_length` dihitung dalam byte; `max_total_bytes` adalah budget kumulatif log untuk satu command. Field protocol yang tidak valid atau budget command yang habis ditolak dengan `422`. Server menetapkan `sequence` secara monotonik per deployment dan tidak menyediakan endpoint update/delete untuk record append-only ini.

Header `Idempotency-Key` bersifat opsional. Tanpa header, setiap request dianggap report baru dan append-only. Bila dikirim, key berlaku untuk command, jenis report, dan index item dalam batch. Retry dengan key dan payload logical yang sama mengembalikan range sequence yang sama dan menandai item sebagai duplicate; penggunaan key yang sama untuk payload logical berbeda menghasilkan `409`. Payload logical untuk idempotency dihitung sebelum redaction, sedangkan payload yang disimpan selalu diredaksi. Replay identik dengan key tetap dapat mengembalikan acknowledgement setelah command terminal; report baru pada command terminal tetap menghasilkan `409`.

Response sukses berbentuk Resource berikut:

```json
{
  "data": {
    "accepted_count": 2,
    "duplicate_count": 0,
    "first_sequence": 1,
    "last_sequence": 2
  }
}
```

Message dan metadata event selalu melewati redaction defense-in-depth API sebelum disimpan atau dibroadcast. Agent tetap wajib melakukan redaction lebih awal. Secret tidak pernah dikembalikan pada acknowledgement atau error response.

Untuk ketentuan lengkap durasi retensi, batasan akses (owner, admin, machine agent), keterbatasan teknis redaksi, serta mekanisme pembersihan log, lihat [Pilot Log Retention Policy](LOG_RETENTION.md).

## HTTP

Gunakan method dan status code sesuai semantik HTTP. Validation error memakai `422`, unauthenticated `401`, forbidden `403`, missing resource `404`, dan conflict `409` bila state tidak memungkinkan operasi.

## Error Bisnis

Error aturan bisnis yang dilihat console selalu memuat `code` yang stabil untuk dibaca mesin dan `message` berbahasa Indonesia untuk ditampilkan ke pengguna. Console memilih perilaku dari `code`, bukan dari isi `message`, karena teksnya boleh berubah. Pesan menyapa pengguna dengan `kamu` dan menyebut `proyek`, sedangkan istilah teknis seperti `deployment`, `repository`, dan `branch` tetap bahasa Inggris.

| `code` | HTTP | Kapan | Field tambahan |
| --- | --- | --- | --- |
| `PROJECT_LIMIT_EXCEEDED` | `422` | Kuota project selama pilot habis. | `limit`, `current` |
| `ACTIVE_DEPLOYMENT_LIMIT_EXCEEDED` | `422` | Batas deployment yang sedang berjalan tercapai. | `scope` (`project` atau `user`), `limit`, `current` |
| `RESOURCE_LIMIT_EXCEEDED` | `422` | Resource yang diminta di luar batas pilot. | `resource`, `requested`, `maximum` |
| `PROJECT_SUSPENDED` | `409` | Deploy atau inspeksi ulang pada project yang ditangguhkan admin. | - |
| `DEPLOYMENT_ALREADY_FINISHED` | `409` | Membatalkan deployment yang sudah `succeeded` atau `failed`. | `status` |
| `GITHUB_RATE_LIMITED` | `503` | GitHub membatasi permintaan Sakala dan tidak ada jawaban tersimpan. Header `Retry-After` berisi detik tunggu. | `retry_after` |
| `AVATAR_STORAGE_UNAVAILABLE` | `503` | Foto profil tidak bisa disimpan karena penyimpanan tidak tersedia. | - |
| `EMAIL_DELIVERY_UNAVAILABLE` | `503` | Endpoint yang tugasnya mengirim email dipanggil saat pengiriman email mati. Lihat [Autentikasi](AUTHENTICATION.md#pengiriman-email). | - |
| `REPOSITORY_ACCESS_REVOKED` | `409` | Deploy project GitHub App saat pemilik project tidak lagi punya akses ke repository. | - |
| `invalid_reset_token` | `422` | Token reset kata sandi salah atau kedaluwarsa, atau emailnya tidak dikenal. | - |
| `email_not_verified` | `403` | Login dengan kata sandi benar, tetapi email belum diverifikasi. Lihat [Autentikasi](AUTHENTICATION.md). | - |

Pemilik project yang ditangguhkan dan admin menerima `409 PROJECT_SUSPENDED`. Pengguna lain tetap menerima `403` generik tanpa `code`, sehingga tidak bisa mengetahui status project orang lain.

## Bahasa

Locale default adalah `id` (`APP_LOCALE`), dengan fallback `en`. Pesan validasi berasal dari `lang/id/validation.php`, dan nama kolom yang dilihat pengguna diatur di `attributes` pada file yang sama. Bentuk error validasi tetap `{ "message", "errors": { "<kolom>": ["..."] } }`. Pesan yang disusun oleh framework di luar katalog itu, seperti `Unauthenticated.` dan `Too Many Attempts.`, tetap berbahasa Inggris; console memetakannya dari status HTTP.

## Endpoint Implementation

Urutan dependency yang disarankan:

```text
Route -> Controller -> Form Request -> Action -> Model/Service
                    \-> API Resource -> JSON
```

Gunakan DTO di `app/Data/<Domain>` ketika payload melintasi lebih dari satu layer atau memerlukan type yang stabil. Jangan memakai array bebas sebagai kontrak internal untuk workflow domain.

## OpenAPI

Scramble menginfer dokumentasi dari route, validation rules pada Form Request, return type controller, dan API Resource. Jalankan `composer docs:analyse` setiap menambah atau mengubah endpoint. Lihat [panduan OpenAPI](OPENAPI.md).

## GitHub

`GET /api/v1/app/github/installations/{installation}/repositories` menerima `page`, `per_page` (maks. 100), dan `search`, dengan `meta` dan `links` pagination standar. GitHub tidak bisa memfilter endpoint ini, jadi `search` berjalan di API dan hanya memeriksa 1000 repository pertama.

Instalasi GitHub App selalu kembali ke `/projects/new` di console: `?github_installation=connected` saat berhasil, atau `?github_installation=error&error=<kode>` dengan kode `github_app_not_configured`, `github_invalid_state`, `github_invalid_installation`, `github_access_denied`, atau `github_provider_failure`. Tamu diarahkan ke `/login` di console, bukan ke halaman error API.

Webhook `push` membuat deployment untuk project yang `auto_deploy`-nya aktif bila repository, installation, dan branch cocok, memakai commit dari payload dengan `trigger = webhook`. Signature diverifikasi lebih dulu, dan `X-GitHub-Delivery` membuat pengiriman ulang tidak melakukan apa-apa. Project yang ditangguhkan atau yang kena batas deployment aktif dilewati: webhook tetap `200`, tindakan dicatat di audit (`project.auto_deploy_skipped`), dan pemilik mendapat notifikasi `project_issue`. Pengiriman yang dilewati tidak dicoba ulang. GitHub App harus berlangganan event `push`.

## Project Preview

`ProjectResource` dan `CreateProjectResource` menyertakan `preview_status` (`pending` | `succeeded` | `failed` | `unavailable`), `inspection` (hanya saat `succeeded`: `repository_url`, `commit_sha`, `dockerfile_found`, `env_example_found`, `compose_found`, `manifests`, `package_manager`, `inspected_at`), dan `inspection_error_code`. Preview diminta otomatis saat project dibuat dan tidak pernah menggagalkan pembuatan; `unavailable` dengan kode `branch_not_found`, `repository_access_denied`, atau `github_unavailable` berarti tidak ada command inspeksi yang dibuat. Inspeksi ulang tersedia lewat `POST /api/v1/app/projects/{project}/inspection`; lihat "Inspeksi".

## Deployment Representation

`DeploymentResource` menyertakan `agent_node_id` (node target yang dipin control plane), `applied_resources` (resource yang benar-benar diterapkan agent, `null` sampai command selesai), serta `finalization_deferred` dan `finalization_deferred_reason` (`grace_elapsed` atau `runtime_error`) bila agent men-commit route tetapi tidak sempat merapikan workload lama; control plane lalu mengirim `StopProject` untuk workload tersebut. `image_reference` diisi dari metadata event `deployment.runtime.ready`, event yang tidak lagi mengubah `status`.

`DeploymentResource` juga menyertakan `is_active` (boolean): `true` hanya untuk deployment yang sedang melayani project, yaitu `projects.active_deployment_id`. Klaim seperti "perubahan sudah live" harus memakai `is_active` bersama `runtime_status` project, bukan `status = succeeded`, karena deployment yang sudah tergantikan atau dihentikan tetap `succeeded`.

`failure` bernilai `null` selama `failure_code` kosong. Bila terisi:

| Field | Tipe | Keterangan |
| --- | --- | --- |
| `code` | string | Error code asli dari agent atau control plane. |
| `category` | enum | `checkout`, `build`, `start`, `health`, `route`, `timeout`, `resource`, `node`, `scheduling`, `unknown`. Nilainya stabil. |
| `summary` | string | Apa yang terjadi, dalam bahasa Indonesia. |
| `recovery_hint` | string | Apa yang bisa dilakukan pengguna. |
| `user_fixable` | boolean | `true` bila perbaikannya ada di repository atau akses GitHub milik pengguna; `false` bila gangguan ada di sisi Sakala dan cukup deploy ulang. |
| `detail` | string atau `null` | Pesan agent yang sudah diredaksi, paling panjang 500 karakter. `null` bila deployment ditutup control plane atau gagal sebelum field ini ada. |
| `stage` | enum atau `null` | Tahap saat gagal: `queued`, `cloning`, `analyzing`, `building`, `deploying`, `routing`, `health_checking`. `queued` berarti gagal sebelum tahap pertama dilaporkan. `null` hanya untuk deployment yang gagal sebelum field ini ada. |

`detail` dan `stage` disimpan pada `deployments`, jadi tetap terbaca setelah event dan log dipangkas oleh retensi 7 hari. Kategori `node` mencakup runtime node yang tidak siap dan deployment yang dibatalkan (`runtime_cancelled`), `timeout` mencakup `command_lease_expired` (node tidak menyelesaikan command sebelum lease habis), dan `scheduling` mencakup `command_expired` (command tidak diambil node sebelum batas ketersediaan).

## Pembacaan GitHub Publik

Alur URL publik memakai satu kuota GitHub untuk semua user, jadi jawabannya disimpan di cache: repository 5 menit, daftar branch 2 menit, branch head 30 detik, dan 404 30 detik. Setelah itu API bertanya ulang dengan `ETag`. Membuat deployment selalu bertanya ulang, supaya deployment tepat setelah push mendapat commit itu.

Saat GitHub membatasi, API memakai jawaban terakhir (disimpan sampai 24 jam). Deployment yang tidak punya jawaban tersimpan memakai commit deployment terakhir di branch yang sama, dan `commit_sha` menunjukkan commit yang dijalankan. Bila itu pun tidak ada, API menjawab `503 GITHUB_RATE_LIMITED`. Inspeksi tidak pernah menggagalkan pembuatan project: `preview_status` menjadi `unavailable` dengan `inspection_error_code = github_rate_limited`.

`GITHUB_PUBLIC_API_TOKEN` menaikkan kuota. Token installation GitHub App sengaja tidak dipakai di sini, karena token itu juga bisa membaca repository private milik pemasang App, dan alur ini menerima URL dari user mana pun.

## Log Deployment

`GET /api/v1/app/projects/{project}/deployments/{deployment}/logs` memakai cursor pagination dengan parameter berikut:

| Parameter | Nilai | Default | Keterangan |
| --- | --- | --- | --- |
| `per_page` | 1 sampai 200 | 6 | Baris per halaman. |
| `order` | `asc`, `desc` | `asc` | `desc` mulai dari baris terbaru, dan `next_cursor` berjalan ke baris yang lebih lama. Baca isi halaman terbalik untuk menampilkannya berurutan. |
| `cursor` | string | - | Dari `meta.next_cursor`. Pakai bersama `order` yang sama dengan halaman sebelumnya. |

Klien yang tidak mengirim `order` tetap menerima urutan lama. Endpoint ini memakai limiter `api` (`SAKALA_API_RATE_LIMIT`, default 240 request per menit per user), jadi `order=desc&per_page=200` menjangkau baris terakhir log gagal dalam satu request. Bila kena limit, response `429` membawa `Retry-After`, dan header itu terbaca lintas origin lewat `exposed_headers` di `config/cors.php`. Endpoint event tetap paling banyak 10 per halaman tanpa `order`.

Broadcast `deployment.log.created` membawa `sequence` untuk urutan semua event realtime deployment, dan `log_sequence` yang sama dengan `sequence` di endpoint ini. Pakai `log_sequence` untuk membuang baris yang sudah diambil.

## Membatalkan Deployment

`POST /api/v1/app/projects/{project}/deployments/{deployment}/cancel` (pemilik atau admin; tetap boleh saat project ditangguhkan, karena hanya menghentikan pekerjaan).

| Keadaan | Response | Hasil |
| --- | --- | --- |
| Belum ada node yang mengambil | `200` | Command `Cancelled`, deployment `cancelled`, kuota deployment aktif langsung kembali. |
| Node sedang mengerjakan | `202` | `cancel_requested_at` terisi, `status` belum berubah. Agent mengetahuinya dari poll berikutnya; lihat [Agent API](AGENT_API.md#pembatalan-command). |
| Sudah `cancelled` | `200` | Idempoten. |
| Sudah `succeeded` atau `failed` | `409` | `DEPLOYMENT_ALREADY_FINISHED` dengan `status`. |

Mengulang saat `202` tetap `202` dengan `cancel_requested_at` pertama. `202` bukan janji: agent yang sudah melewati cutover route boleh tetap menyelesaikan deployment menjadi `succeeded`, dan console harus mengikuti `status`. Bila node mati setelah diminta batal, deployment berakhir `cancelled` saat lease habis.

`queue_state` hanya terisi selama `status = queued`: `waiting_for_node` berarti belum ada node yang bisa mengambilnya, entah belum ada target atau targetnya sedang offline atau draining, dan deployment menunggu tanpa batas waktu; `assigned` berarti node yang siap sudah memegangnya. Console memakai ini untuk menjelaskan antrean dan menawarkan pembatalan.

## Project Runtime

`ProjectResource` menyertakan:

| Field | Tipe | Keterangan |
| --- | --- | --- |
| `active_deployment_id` | UUID atau `null` | Deployment yang sedang melayani: terakhir `succeeded` dan belum dihentikan. Tetap terisi saat redeploy gagal dan saat `crashed`; kosong setelah stop atau sleep selesai. |
| `detected_port` | integer atau `null` | Port hasil deteksi. Saat ini belum ada penulisnya. |
| `configured_port` | integer atau `null` | Port yang diisi pemilik, 1 sampai 65535. |
| `effective_port` | integer | Nilai `PORT` untuk aplikasi: `configured_port`, lalu `detected_port`, lalu 3000. Sama dengan `container_port` deployment berikutnya. |
| `serving_port` | integer atau `null` | Port yang sedang dipakai deployment aktif. Bisa berbeda dari `effective_port` sampai deployment berikutnya. `null` bila tidak ada yang melayani, termasuk saat `crashed`, dan untuk deployment lama yang port-nya tidak tercatat. |
| `build_command`, `start_command` | string atau `null` | Satu baris, paling panjang 1000 karakter. Dijalankan agent hanya di dalam build atau container. |
| `root_directory` | string atau `null` | Path relatif di dalam repository, misalnya `apps/web`. |
| `auto_deploy` | boolean | `true` membuat push ke branch project membuat deployment baru. Default `false`. Hanya untuk project dari GitHub App; project dari URL publik dijawab `422` karena webhook-nya tidak pernah datang. |
| `public_url` | string | Alamat publik project untuk instalasi ini, dari `SAKALA_PROJECT_URL_SCHEME` dan `SAKALA_PROJECT_URL_PORT`. Console memakai ini, bukan menyusun sendiri. |
| `redeploy_required` | boolean | `true` bila environment variable atau konfigurasi build berubah setelah deployment aktif dibuat. |
| `stack` | object atau `null` | Lihat "Inspeksi". |

Nilai `runtime_status` dan yang menuliskannya:

| Nilai | Arti | Ditulis oleh |
| --- | --- | --- |
| `not_deployed` | Belum pernah ada deployment. | Default kolom. |
| `deploying` | Ada deployment yang sudah melewati `queued`. Versi lama bisa tetap melayani: lihat `active_deployment_id`. | Transisi deployment. |
| `running` | `active_deployment_id` sedang melayani. | Deployment, restart, atau wake sukses; redeploy gagal saat versi lama masih melayani; heartbeat yang tidak lagi melaporkan crash. |
| `stopped` | Dihentikan dengan sengaja, tidak ada yang melayani. | Stop atau sleep sukses. |
| `failed` | Deployment terakhir gagal dan tidak ada versi yang melayani. | Deployment gagal tanpa `active_deployment_id`. |
| `crashed` | Workload aktif berhenti sendiri. | Heartbeat (lihat [Agent API](AGENT_API.md#heartbeat)). |

Mengganti port tidak mengubah workload yang sedang berjalan: `PORT` dan route baru berlaku pada deployment berikutnya, dan `redeploy_required` menandainya sampai saat itu. Aplikasi harus membaca `PORT`. Bila project sendiri punya environment variable `PORT` dengan nilai lain, deployment gagal dengan `runtime_port_conflict`.

Field konfigurasi diterima pada `POST` dan `PATCH /api/v1/app/projects`. Pada `PATCH`, field yang tidak dikirim tidak berubah dan `null` mengosongkannya. `thumbnail_url` belum punya generator, jadi console memakai gambar cadangannya bila kosong.

### Inspeksi

`POST /api/v1/app/projects/{project}/inspection` (pemilik; dibatasi `SAKALA_INSPECTION_RATE_LIMIT` per menit) menjawab `202` dengan `ProjectResource`. Hasilnya menyusul lewat `preview_status`. Inspeksi yang sedang berjalan atau sudah selesai untuk commit yang sama dipakai ulang; yang gagal, kedaluwarsa, atau dibatalkan dicoba lagi. Project yang ditangguhkan dijawab `409 PROJECT_SUSPENDED`.

`stack` ada pada `ProjectResource`, `CreateProjectResource`, dan daftar project, bernilai `null` sampai inspeksi berhasil:

| Field | Nilai | Keterangan |
| --- | --- | --- |
| `builder` | `dockerfile` atau `railpack` | Pilihan mode auto untuk direktori yang diinspeksi. |
| `provider` | string atau `null` | Provider pertama yang dideteksi railpack, misalnya `node`. |
| `framework` | string atau `null` | Dari `nodeSPAFramework` railpack, lalu `nodeRuntime` kecuali `node`, `bun`, dan `deno`. Selain itu `null`. |
| `package_manager` | string atau `null` | Dari lockfile yang ditemukan agent, lalu `metadata.nodePackageManager` railpack. |

`null` berarti tidak diketahui, bukan tebakan. Output railpack mentah tetap di server. Field yang dibaca sudah dicocokkan dengan output `railpack info` sungguhan (`detectedProviders`, `metadata.nodeSPAFramework`, `metadata.nodeRuntime`, `metadata.nodePackageManager`); contohnya ada di `tests/Fixtures/railpack/`.

Inspeksi dijalankan pada `root_directory` project. Setelah direktori itu diganti, `stack` kembali `null` karena hasil lama menggambarkan folder lain, dan console perlu meminta inspeksi ulang. Commit yang sama dengan direktori berbeda dihitung sebagai inspeksi baru. Agent v0.2.0 mengabaikan field ini dan selalu menginspeksi root repository.

Filter `GET /api/v1/app/projects?filter=` menerima `7_days`, `30_days`, `90_days`, dan `all`.

`GET /api/v1/app/projects/domain-preview?name=` menjawab `{ "data": { "slug", "domain" } }` memakai generator yang sama dengan pembuatan project, termasuk suffix bentrok dan domain lingkungan. Hasilnya tidak dipesan, jadi domain akhir bisa berbeda bila ada yang mengambilnya lebih dulu. Nama yang dicadangkan dijawab `422` pada `name`.

### Menghapus Project

`DELETE /api/v1/app/projects/{project}` menjawab `204` dan project langsung hilang dari API (soft delete, kuota kembali). Bila ada deployment aktif, API membuat `StopProject` yang menghapus route dan container di node. Antrean yang belum diambil dibatalkan, dan attempt yang sedang berjalan diminta batal; bila tetap sukses, workload-nya ikut dihentikan.

`StopProject` tidak punya batas waktu. Saat node offline, workload tetap melayani sampai node itu kembali dan menerima command-nya, jadi penghapusan di API bukan bukti container sudah berhenti. Slug project yang dihapus tidak pernah dipakai ulang, jadi route lama tidak bisa melayani project baru.

Data project dihapus permanen `SAKALA_DELETED_PROJECT_RETENTION_DAYS` hari setelah dihapus (default 30 hari) oleh `projects:purge-deleted`, yang berjalan sekali sehari. Selama masa itu tidak ada endpoint untuk memulihkan project. Yang dihapus: baris project, deployment, log, event, agent command (termasuk salinan environment variable terenkripsi di payload `DeployProject`), environment variable, dan notifikasi project itu.

Kalau node belum menyelesaikan pekerjaannya saat masa itu habis, baris project menunggu node tersebut, karena baris itulah satu-satunya jalan untuk menyuruh node menghentikan container. Environment variable dan salinannya tetap dihapus tepat waktu, karena menghentikan container tidak membutuhkannya. `StopProject` yang gagal atau kedaluwarsa dikirim ulang sekali sehari. Node yang di-revoke tidak bisa menerima command lagi, jadi tidak menahan penghapusan; container di node itu harus dibereskan operator. Perintah ini mencatat jumlah project yang menunggu, dan log aplikasi menyebut ID-nya.

Yang sengaja tetap ada: slug di `retired_project_slugs`, supaya alamat lama tidak bisa diambil orang lain; `audit_events`, yang hanya berisi ID dan bukan isi project; `feedbacks` milik user, dengan `project_id` dan `deployment_id` menjadi `null`; dan `usage_signal_records`, yang punya retensi sendiri. Image hasil build di node juga tidak disentuh, karena itu urusan `CleanupRuntime`. Tidak ada secret per project yang disimpan di cache aplikasi, jadi tidak ada yang perlu dibersihkan di sana.

### Environment Variable

`POST` menerima `is_secret` (default `true`). `PATCH .../environment-variables/{id}` menerima `value` dan `is_secret`, keduanya opsional. `value` ada di response hanya untuk variabel non-secret; variabel secret tidak punya key itu dan tetap dibaca lewat `GET .../value`. `POST` dan `PATCH` menyertakan `redeploy_required`, karena deployment berjalan memakai nilai saat ia dibuat.

`ProjectStatus` adalah status control plane dan terpisah dari runtime: `draft` (default) dan `active` (deployment pertama sukses) serta `suspended` (admin). Nilai `failed` masih ada di enum tetapi tidak pernah ditulis; kegagalan deploy hanya tampak di `runtime_status`.

## Project Control

Endpoint project control menyediakan kontrol runtime yang hanya dapat digunakan oleh admin tanpa melakukan operasi Docker atau Caddy secara langsung dari API. Operasi runtime dijalankan secara asynchronous oleh Agent melalui agent command yang bersifat durable.

### Stop vs Suspend

`Stop` menghentikan workload runtime yang sedang menjadi target pada sebuah project. Operasi ini tidak mengubah status control-plane project menjadi `Suspended`.

`Suspend` mengubah status control-plane project menjadi `Suspended` dan mencegah command yang dapat menjalankan atau mengekspos workload diproses untuk project tersebut. Jika project memiliki workload yang sedang berjalan, API membuat command `SleepProject` untuk Agent.

`Stop` dan `Suspend` merupakan operasi yang berbeda dan tidak boleh diperlakukan sebagai alias satu sama lain.

### Response Asynchronous

Operasi stop dan suspend mengembalikan `202 Accepted` ketika request control-plane telah diterima.

`202 Accepted` tidak berarti operasi runtime telah selesai. Runtime state diperbarui setelah Agent melaporkan hasil eksekusi command.

Jika command stop atau sleep berhasil, runtime state dari workload yang menjadi target berubah menjadi `Stopped` dan `active_deployment_id` dikosongkan.

Jika command gagal, control plane tidak boleh menganggap runtime telah berubah menjadi `Stopped`.

### Tanpa Live Workload

Suspend tetap valid meskipun project tidak memiliki live workload.

Dalam kondisi tersebut:

* status control-plane project diubah menjadi `Suspended`;
* tidak ada Agent command yang dibuat;
* API tidak melakukan operasi Docker atau Caddy.

Dengan demikian, suspend dapat berfungsi sebagai policy pada control plane tanpa bergantung pada keberadaan workload runtime saat ini.

Stop membutuhkan live workload yang dapat ditargetkan. Jika tidak terdapat live workload, operasi ditolak dengan `409 Conflict`.

### Deployment yang Masih Berjalan

Jika project di-suspend ketika terdapat deployment yang masih berjalan, deployment tersebut dapat selesai setelah request suspend diterima.

Jika deployment tersebut kemudian mencapai status `Succeeded`, control plane tetap mempertahankan project dalam status `Suspended` dan membuat command `SleepProject` yang menargetkan deployment yang baru selesai tersebut.

Runtime state project tetap `Running` sampai command sleep yang sesuai berhasil diselesaikan oleh Agent.

### Completion dan Failure

Penyelesaian Agent command menjadi sumber kebenaran untuk perubahan runtime state.

Jika command `StopProject` atau `SleepProject` untuk workload yang masih menjadi target berhasil diselesaikan, runtime state project berubah menjadi `Stopped`.

Jika command gagal, runtime state tidak diubah menjadi `Stopped`.

Penyelesaian command yang sudah stale tidak boleh menimpa runtime state dari workload yang lebih baru.

### Idempotency

Request `Stop` dan `Suspend` yang mendukung idempotency menggunakan idempotency key sebagai identifier untuk satu operasi control-plane.

Untuk project control, identity request ditentukan oleh:

* `project`;
* `action` (`Stop` atau `Suspend`);
* actor yang melakukan request;
* `reason`.

Idempotency key yang sama untuk request dengan identity yang sama diperlakukan sebagai retry. Retry tidak membuat `ProjectControlRequest`, Agent command, atau audit event baru dan mengembalikan response context dari request sebelumnya.

Idempotency key yang sama tidak boleh digunakan kembali untuk request dengan identity berbeda. Jika key sudah digunakan untuk project, action, actor, atau reason yang berbeda, request ditolak dengan `409 Conflict`.

`ProjectControlRequest` menjadi durable record untuk idempotency project control. Agent command hanya dibuat ketika operasi membutuhkan pekerjaan runtime; karena itu, `Suspend` tanpa live workload tetap memiliki record idempotency meskipun tidak menghasilkan Agent command.

## Agent Node Control

`POST /api/agent/v1/agents/{agent}/drain` dan `POST /api/agent/v1/agents/{agent}/resume` (Sanctum, hanya admin) mengubah intent lifecycle sebuah runtime node dan membuat command `DrainNode`/`ResumeNode` dalam satu transaksi. Body `reason` (wajib, ≤ 500). Response `202 Accepted` dengan `AgentNodeControlResource` (`agent_node_id`, `status` yang dilaporkan, `desired_state`, dan `command{id,type,status}`); `202` berarti intent tersimpan, bukan node sudah drained/aktif — itu dilaporkan agent lewat heartbeat dan completion command.

Header `Idempotency-Key` opsional dengan semantik yang sama dengan project control: identitas request = node + action + actor + reason; key yang sama mengembalikan command yang sama tanpa record/audit baru, key yang dipakai ulang untuk identitas berbeda → `409`. `AgentNodeControlRequest` adalah record durable-nya. Ditolak `409` bila node tidak `active` (auth), sudah berada di desired state yang diminta, atau masih ada lifecycle command yang belum selesai.

`AgentResource` menyertakan `desired_state`, `protocol_version`, dan `last_seen_at` untuk admin.

`POST /api/agent/v1/agents/{agent}/cleanup` (Sanctum, admin) membuat `CleanupRuntime` dengan body `reason` dan `targets[]` (`stale_workspaces`, `stale_images`, `stale_routes`; distinct, minimal satu). Field `approved` dilarang dari client — API yang menuliskannya. `409` bila node tidak aktif/eligible atau cleanup lain masih berjalan.

## Project Reconciliation

`POST /api/v1/admin/projects/{project}/reconcile` (admin) membuat `ReconcileWorkload` untuk node yang melayani deployment `succeeded` terakhir. Body `reason`, `desired_state` (`running`|`stopped`|`missing`), dan `actions[]` (boleh kosong; nilai `restart_log_follower`, `cleanup_failed_candidate`, `restore_route`). Payload dikirim apa adanya — API tidak menyimpulkan aksi mutatif. Response `202` `ProjectControlResource`; `ProjectControlRequest` dengan action `reconcile` menjadi record idempotency (identitas = project + actor + reason + desired_state + actions). `409` untuk project suspended, tanpa workload terlayani, deployment yang masih berjalan, atau reconciliation yang masih berjalan. Bila deployment baru menjadi current sebelum agent mengklaim, command dibatalkan (`Cancelled`, `reconcile_target_superseded`) alih-alih dieksekusi terhadap workload lama.

Header `Idempotency-Key` pada semua operasi control (stop, suspend, reconcile, drain, resume, cleanup) ditolak `409` bila sudah dipakai command mana pun, bukan hanya oleh record control yang sama.

## Notifikasi

Notifikasi in-app memakai database notification Laravel. Kategori: `deployment_success`, `deployment_failure`, `project_issue` (workload crash, auto-deploy dilewati), `account_security` (kata sandi dan email).

| Endpoint | Hasil |
| --- | --- |
| `GET /api/v1/app/notifications` | Terbaru dulu, `page`, `per_page` (maks. 50), `unread=1`. |
| `GET /api/v1/app/notifications/unread-count` | `{ "data": { "count" } }` |
| `POST /api/v1/app/notifications/{id}/read` | Resource notifikasi. Idempoten, `read_at` pertama dipertahankan. Milik orang lain `404`. |
| `POST /api/v1/app/notifications/read-all` | `204` |
| `GET`, `PATCH /api/v1/app/notification-preferences` | Satu boolean per kategori, default `true`. `PATCH` boleh sebagian. |

Satu crash menghasilkan satu notifikasi `project_issue`: heartbeat berikutnya yang masih melaporkan crash tidak mengirim lagi, begitu juga setelah redeploy gagal, dan pemulihan tidak mengirim apa pun. Crash baru setelah workload pulih mengirim lagi. Selama ada deployment baru yang berjalan, heartbeat tidak mengubah `runtime_status`.

Bentuk item: `id`, `category`, `title`, `message`, `project_id`, `deployment_id`, `read_at`, `created_at`. Kategori yang dimatikan tidak membuat notifikasi sama sekali. Notifikasi baru juga dibroadcast pada channel privat `users.{id}` dengan event `notification.created` dan field yang sama. Kiriman terjadi setelah transaksi commit, dan kegagalannya dilaporkan tanpa menggagalkan laporan agent yang memicunya.

Tidak ada data contoh di luar test. Notifikasi lebih tua dari `SAKALA_NOTIFICATION_RETENTION_DAYS` dihapus `notifications:prune`, dijadwalkan harian.

## Profile Contract

### Update Profile

`PATCH /api/v1/app/profile`

Authentication menggunakan authenticated session (`web` guard). Profile target selalu berasal dari authenticated user, sehingga user tidak dapat menentukan user/profile lain sebagai target update.

Request bersifat partial. Field yang dapat dikirim:

| Field      | Type   | Constraints                             |
| ---------- | ------ | --------------------------------------- |
| `name`     | string | Maks. 255 karakter                      |
| `username` | string | Unique, format valid, maks. 50 karakter |
| `avatar`   | file   | PNG, JPG, JPEG, WebP, maks. 1 MB        |

Satu atau beberapa field dapat diperbarui dalam satu request.

`email` tidak termasuk field yang dapat diperbarui melalui endpoint ini; lihat [Autentikasi](AUTHENTICATION.md#keamanan-akun).

`DELETE /api/v1/app/profile/avatar` menghapus foto yang diunggah sekaligus foto salinan dari provider OAuth, lalu mengembalikan `UserResource`. Foto dari provider tidak kembali saat login berikutnya.

`UserResource` juga menyertakan `created_at`, `has_password`, dan `pending_email`. Profil onboarding (`role`) menerima `developer`, `devops`, `architect`, `student`, `mentor`, dan `other`.

### Avatar Storage Contract

Avatar disimpan menggunakan configured filesystem melalui logical disk `avatars`.

Database menyimpan **object key** pada:

```text
users.avatar_path
```

Contoh:

```text
avatars/<generated-filename>.jpg
```

Database tidak menyimpan public URL sebagai source of truth.

`avatar_url` pada response dihasilkan oleh `AvatarUrlResolver` berdasarkan `avatar_path`. Jika `avatar_path` tidak tersedia, resolver menggunakan `avatar_url` lama sebagai fallback untuk identity yang berasal dari OAuth.

Ketika avatar diganti:

1. Avatar baru disimpan terlebih dahulu.
2. `avatar_path` user diperbarui setelah penyimpanan berhasil.
3. Setelah database update berhasil, avatar lama dihapus.
4. Jika database update gagal setelah avatar baru tersimpan, avatar baru dibersihkan.

Update `name` atau `username` tanpa mengirim avatar tidak akan memengaruhi avatar yang sudah tersimpan.

## Admin Signals Endpoint

Endpoint admin menyediakan ringkasan sinyal operasional agregat untuk keperluan observabilitas pilot:

### GET /api/v1/admin/signals

Hanya dapat diakses oleh user dengan role `admin`. Endpoint menerima parameter query opsional `from` dan `to` (ISO 8601 date). Response berbentuk Resource dengan field `data.signals` yang berisi array record sinyal agregat per window waktu.

Response structure:

```json
{
  "data": {
    "from": "2026-09-01T00:00:00+00:00",
    "to": "2026-09-14T12:00:00+00:00",
    "signals": [
      {
        "signal_type": "deployment_attempt",
        "count": 42,
        "scope": "global",
        "scope_id": null,
        "tags": {},
        "collected_at": "2026-09-14T11:00:00+00:00"
      }
    ]
  }
}
```

Signal types yang tersedia:
- `deployment_attempt`
- `successful_deployment`
- `active_projects`
- `rejected_limits`
- `agent_failure`
- `repeated_build_failure`
- `manual_intervention`

Endpoint ini tidak mengekspos data pribadi (nama, email) maupun secret. Field `tags` hanya berisi metadata operasional seperti `failure_code` atau `limit_name`.
