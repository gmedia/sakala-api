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
| `PROJECT_LIMIT_EXCEEDED` | `422` | Kuota project workspace habis. | `limit`, `current` |
| `ACTIVE_DEPLOYMENT_LIMIT_EXCEEDED` | `422` | Batas deployment yang sedang berjalan tercapai. | `scope` (`project`, `user` untuk workspace pribadi, atau `workspace` untuk tim), `limit`, `current` |
| `ACTIVE_CONTAINER_LIMIT_EXCEEDED` | `422` | Deploy project yang belum melayani akan melewati batas container berjalan workspace. Redeploy project yang sudah melayani tidak pernah kena. | `limit`, `current` |
| `RESOURCE_LIMIT_EXCEEDED` | `422` | Resource yang diminta di luar batas pilot. | `resource`, `requested`, `maximum` |
| `PROJECT_SUSPENDED` | `409` | Deploy, inspeksi ulang, atau membangunkan lewat Console project yang ditangguhkan admin. | - |
| `PROJECT_NOT_SLEEPING` | `409` | Membangunkan lewat Console project yang `runtime_status`-nya bukan `sleeping`. | `runtime_status` |
| `PROJECT_WAKE_LIMIT_EXCEEDED` | `409` | Membangunkan project akan melewati batas container berjalan workspace. Endpoint publik tidak menyertakan angkanya. | `limit`, `current` (hanya lewat Console) |
| `DEPLOYMENT_ALREADY_FINISHED` | `409` | Membatalkan deployment yang sudah `succeeded` atau `failed`. | `status` |
| `GITHUB_RATE_LIMITED` | `503` | GitHub membatasi permintaan Sakala dan tidak ada jawaban tersimpan. Header `Retry-After` berisi detik tunggu. | `retry_after` |
| `GITHUB_UNAVAILABLE` | `503` | GitHub menjawab error (misalnya `5xx`), jawabannya tidak bisa dibaca, atau tidak bisa dihubungi saat membaca repository, branch, atau commit. Masalahnya bukan di repository kamu; aman diulang. Lihat [Pembacaan GitHub Publik](#pembacaan-github-publik). | - |
| `AVATAR_STORAGE_UNAVAILABLE` | `503` | Foto profil tidak bisa disimpan karena penyimpanan tidak tersedia. | - |
| `EMAIL_DELIVERY_UNAVAILABLE` | `503` | Endpoint yang tugasnya mengirim email dipanggil saat pengiriman email mati. Lihat [Autentikasi](AUTHENTICATION.md#pengiriman-email). | - |
| `REPOSITORY_ACCESS_REVOKED` | `409` | Deploy project GitHub App saat orang yang menjadi sandaran aksesnya tidak lagi punya akses ke repository atau sudah keluar dari tim. Lihat [Akses GitHub untuk project tim](#akses-github-untuk-project-tim). | - |
| `invalid_reset_token` | `422` | Token reset kata sandi salah atau kedaluwarsa, atau emailnya tidak dikenal. | - |
| `email_not_verified` | `403` | Login dengan kata sandi benar, tetapi email belum diverifikasi. Lihat [Autentikasi](AUTHENTICATION.md). | - |
| `LEGAL_ACCEPTANCE_REQUIRED` | `403` | Membuat proyek, inspeksi ulang, atau deploy sebelum menyetujui versi terbaru. Lihat [Persetujuan Dokumen](AUTHENTICATION.md#persetujuan-dokumen). | - |
| `REAUTHENTICATION_REQUIRED` | `403` | Akun tanpa kata sandi menghapus akun tanpa login baru di provider. Lihat [Menghapus Akun](AUTHENTICATION.md#menghapus-akun). | - |
| `ADMIN_ACCOUNT_CANNOT_BE_DELETED` | `409` | Admin menghapus akunnya sendiri. | - |
| `ACCOUNT_DELETION_CONFLICT` | `409` | Proyek baru terus muncul selama akun dihapus. Aman diulang. | - |
| `WORKSPACE_IS_PERSONAL` | `409` | Mengganti nama, menghapus, meninggalkan, atau mengundang ke workspace pribadi. | - |
| `LAST_WORKSPACE_OWNER` | `409` | Pemilik terakhir tim keluar, dikeluarkan, atau diturunkan perannya, atau menghapus akun selagi timnya masih punya proyek. | `workspaces` (`id`, `name`, `slug`) |
| `WORKSPACE_NOT_EMPTY` | `409` | Menghapus tim yang masih punya proyek. | `projects` |
| `TEAM_LIMIT_EXCEEDED` | `422` | Menjadi owner satu tim lagi akan melewati batas tim yang dimiliki: membuat tim, menerima undangan owner, atau dijadikan owner. | `limit`, `current` |
| `INVITATION_EMAIL_MISMATCH` | `403` | Menerima undangan sebagai `owner` dari akun yang email terverifikasinya bukan alamat undangan, walaupun membawa token. Saat membaca undangan, kode ini muncul di `accept_error_code`, bukan sebagai error. | - |
| `EMAIL_VERIFICATION_REQUIRED` | `403` | Membuat atau bergabung ke tim dengan email yang belum diverifikasi. | - |
| `ALREADY_WORKSPACE_MEMBER` | `409` | Mengundang alamat milik anggota, atau menerima undangan saat sudah menjadi anggota. | - |
| `INVITATION_EXPIRED` | `409` | Membaca, menerima, atau menolak undangan yang sudah kedaluwarsa, dengan bukti yang benar. | - |
| `INVITATION_ALREADY_ACCEPTED` | `409` | Membaca, menerima, atau menolak undangan yang sudah diterima, dengan bukti yang benar. | - |
| `INVITATION_DECLINED` | `409` | Membaca, menerima, atau menolak undangan yang sudah ditolak, dengan bukti yang benar. | - |
| `INVITATION_REVOKED` | `409` | Membaca, menerima, atau menolak undangan yang dicabut owner, diganti undangan baru ke alamat yang sama, atau ikut dicabut karena pengundangnya kehilangan peran owner, dengan bukti yang benar. | - |
| `ORIGIN_NOT_ALLOWED` | `403` | `/broadcasting/auth` dari origin selain Console. Lihat [Autentikasi](AUTHENTICATION.md#cookie-dan-aplikasi-pengguna). | - |
| `ADMIN_SELF_DEMOTION` | `409` | Admin mencabut peran admin dari akunnya sendiri lewat API admin. | - |
| `LAST_ADMIN` | `409` | Mencabut peran admin akan membuat Sakala tanpa admin. | - |
| `LIMIT_PROFILE_IN_USE` | `409` | Menghapus profil batas yang masih dipakai workspace. | `workspaces` |

Anggota workspace project yang ditangguhkan dan admin menerima `409 PROJECT_SUSPENDED`. Pengguna lain tetap menerima `403` generik tanpa `code`, sehingga tidak bisa mengetahui status project orang lain.

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

Webhook `push` membuat deployment untuk project yang `auto_deploy`-nya aktif bila repository, installation, dan branch cocok, memakai commit dari payload dengan `trigger = webhook`. Deployment itu atas nama pemilik workspace pribadi, atau di tim atas nama pembuat project selama ia masih owner atau member, lalu owner tim yang paling lama. Signature diverifikasi lebih dulu, dan `X-GitHub-Delivery` membuat pengiriman ulang tidak melakukan apa-apa. Project yang ditangguhkan atau yang kena batas deployment aktif dilewati: webhook tetap `200`, tindakan dicatat di audit (`project.auto_deploy_skipped`), dan owner serta member workspace mendapat notifikasi `project_issue`. Pengiriman yang dilewati tidak dicoba ulang. GitHub App harus berlangganan event `push`.

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

Bila GitHub menolak token itu sendiri, API mengulang permintaan yang sama sekali tanpa token dan memakai jawabannya. Penolakan itu berupa `401` (token dicabut atau kedaluwarsa) atau `403` yang bukan rate limit, misalnya organisasi melarang fine-grained token yang masa berlakunya lebih dari 366 hari, atau mewajibkan SAML SSO. `403` atau `429` karena rate limit (header `X-RateLimit-Remaining: 0`, `Retry-After`, atau pesan rate limit) tidak diulang tanpa token, karena kuota anonim lebih kecil; jawabannya tetap `GITHUB_RATE_LIMITED`. Penolakan token dicatat sebagai warning paling sering sekali per 5 menit untuk setiap pesan GitHub, tanpa token. Error lain dari GitHub dijawab `503 GITHUB_UNAVAILABLE`, bukan `500`.

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

`POST /api/v1/app/projects/{project}/deployments/{deployment}/cancel` (owner atau member workspace, atau admin; tetap boleh saat project ditangguhkan, karena hanya menghentikan pekerjaan).

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
| `active_deployment_id` | UUID atau `null` | Deployment yang sedang melayani: terakhir `succeeded` dan belum dihentikan. Tetap terisi saat redeploy gagal, saat `crashed`, dan saat `sleeping` (container-nya disimpan node dan bangun lagi dari situ); kosong setelah stop atau sleep karena suspend selesai. |
| `detected_port` | integer atau `null` | Port dari inspeksi terakhir, diisi hanya bila Dockerfile di direktori itu mengekspos tepat satu port TCP lewat `EXPOSE`. `null` bila tidak ada, ada beberapa, agent belum melaporkannya, atau `root_directory` berubah sesudah inspeksi. |
| `configured_port` | integer atau `null` | Port yang diisi pemilik, 1 sampai 65535. |
| `effective_port` | integer | Nilai `PORT` untuk aplikasi: `configured_port`, lalu `detected_port`, lalu 3000. Sama dengan `container_port` deployment berikutnya. |
| `serving_port` | integer atau `null` | Port yang sedang dipakai deployment aktif. Bisa berbeda dari `effective_port` sampai deployment berikutnya. `null` bila tidak ada yang melayani, termasuk saat `crashed` dan `sleeping`, dan untuk deployment lama yang port-nya tidak tercatat. |
| `build_command`, `start_command` | string atau `null` | Satu baris, paling panjang 1000 karakter. Dijalankan agent hanya di dalam build atau container. |
| `root_directory` | string atau `null` | Path relatif di dalam repository, misalnya `apps/web`. |
| `auto_deploy` | boolean | `true` membuat push ke branch project membuat deployment baru. Default `false`. Hanya untuk project dari GitHub App; project dari URL publik dijawab `422` karena webhook-nya tidak pernah datang. |
| `public_url` | string | Alamat publik project untuk instalasi ini, dari `SAKALA_PROJECT_URL_SCHEME` dan `SAKALA_PROJECT_URL_PORT`. Console memakai ini, bukan menyusun sendiri. Ada juga di `GET /api/v1/app/projects`. |
| `latest_deployment` | object atau `null` | Deployment dengan `sequence` terbesar, apa pun hasilnya, atau `null` bila belum pernah ada. Bisa berbeda dari `active_deployment_id`. Ada juga di `GET /api/v1/app/projects`, jadi daftar project tidak perlu request per kartu. Lihat tabel di bawah. |
| `suspension` | object atau `null` | Terisi selama `status` bernilai `suspended`, dan `null` di luar itu, termasuk setelah penangguhan dibuka. Ada juga di `GET /api/v1/app/projects`. Lihat "Pemberitahuan dan Membuka Penangguhan". |
| `redeploy_required` | boolean | `true` bila environment variable atau konfigurasi build berubah setelah deployment aktif dibuat. |
| `stack` | object atau `null` | Lihat "Inspeksi". |
| `workspace` | object atau `null` | Workspace pemilik project: `id`, `name`, `slug`, `type` (`personal` atau `team`). Ada juga di daftar project dan respons pembuatan project. `null` hanya untuk project yang sedang menunggu purge tanpa workspace. Peran pemanggil ada di `GET /api/v1/app/workspaces`. |

Isi `latest_deployment`:

| Field | Tipe | Keterangan |
| --- | --- | --- |
| `id` | UUID | Sama dengan `id` di `GET .../deployments/{deployment}`. |
| `sequence` | integer | Nomor urut per project. |
| `status` | string | Nilai `DeploymentStatus`. |
| `branch` | string | Branch saat deployment dibuat. |
| `commit_sha` | string atau `null` | SHA 40 karakter. |
| `commit_message` | string atau `null` | Baris pertama pesan commit, paling panjang 500 karakter, tanpa karakter kontrol. Asalnya dari GitHub saat deployment dibuat: `head_commit` pada webhook push, atau head branch pada deploy manual. Agent tidak terlibat. `null` bila GitHub tidak memberikannya. Teks ini ditulis siapa pun yang membuat commit dan markup di dalamnya tidak diubah, jadi tampilkan sebagai teks, jangan sebagai HTML. |
| `created_at` | string ISO 8601 | Waktu deployment dibuat. |
| `finished_at` | string ISO 8601 atau `null` | `null` selama belum selesai. |

Nilai `runtime_status` dan yang menuliskannya:

| Nilai | Arti | Ditulis oleh |
| --- | --- | --- |
| `not_deployed` | Belum pernah ada deployment. | Default kolom. |
| `deploying` | Ada deployment yang sudah melewati `queued`. Versi lama bisa tetap melayani: lihat `active_deployment_id`. | Transisi deployment. |
| `running` | `active_deployment_id` sedang melayani. | Deployment, restart, atau wake sukses; redeploy gagal saat versi lama masih melayani; heartbeat yang tidak lagi melaporkan crash. |
| `stopped` | Dihentikan dengan sengaja, tidak ada yang melayani. | Stop atau sleep karena suspend sukses. |
| `sleeping` | Ditidurkan karena sepi. Container `active_deployment_id` disimpan di node, dan request berikutnya membangunkannya. Ini perilaku normal, bukan error. | Sleep karena sepi sukses; redeploy yang gagal atau dibatalkan saat versi aktifnya sedang tidur. Lihat [Tidur Otomatis](#tidur-otomatis). |
| `failed` | Deployment terakhir gagal dan tidak ada versi yang melayani. | Deployment gagal tanpa `active_deployment_id`. |
| `crashed` | Workload aktif berhenti sendiri. | Heartbeat (lihat [Agent API](AGENT_API.md#heartbeat)). |

Mengganti port tidak mengubah workload yang sedang berjalan: `PORT` dan route baru berlaku pada deployment berikutnya, dan `redeploy_required` menandainya sampai saat itu. Aplikasi harus membaca `PORT`. Bila project sendiri punya environment variable `PORT` dengan nilai lain, deployment gagal dengan `runtime_port_conflict`.

Field konfigurasi diterima pada `POST` dan `PATCH /api/v1/app/projects`. Pada `PATCH`, field yang tidak dikirim tidak berubah dan `null` mengosongkannya. `thumbnail_url` belum punya generator, jadi console memakai gambar cadangannya bila kosong.

### Tidur Otomatis

Project yang `running` tanpa request selama jendela sepi workspace-nya ditidurkan oleh `projects:sleep-idle`, yang berjalan setiap menit di satu server. Jendela itu datang dari `idle_sleep_minutes` profil batas workspace; profil dengan `null` dan workspace tanpa profil memakai `SAKALA_PROJECT_IDLE_SLEEP_MINUTES` (default `0`). Nilai `0` berarti tidak pernah tidur, jadi tanpa konfigurasi tidak ada project yang ditidurkan. Nilai efektifnya ada di `project_idle_sleep_minutes` pada `GET /api/v1/app/pilot-limits?workspace_id=`, dan `0` di sana berarti project workspace itu tidak pernah tidur.

Sebuah project ditidurkan bila semua ini benar:

* `runtime_status` bernilai `running`, project tidak `suspended` dan tidak dihapus;
* node deployment aktifnya punya capability `idle-sleep`, sedang tidak `offline`, dan heartbeat terakhirnya membawa daftar `traffic` (lihat [Agent API](AGENT_API.md#heartbeat)). Tanpa daftar itu tidak ada yang tahu project sepi atau tidak, jadi tidak ada yang ditidurkan;
* `last_request_at`, waktu deployment aktif selesai, dan wake atau restart terakhir yang sukses semuanya lebih lama dari jendela sepi. Project yang belum pernah menerima request diukur dari waktu deployment selesai;
* tidak ada deployment yang sedang berjalan, dan tidak ada `SleepProject`, `WakeProject`, `StopProject`, atau `RestartProject` yang masih `Pending`, `Claimed`, atau `Running`.

API lalu membuat `SleepProject` ke node itu dengan `request_context` `{ "reason": "idle", "actor_type": "system", "actor_id": "scheduler:sleep-idle" }` dan mencatat `project.idle_sleep_requested`. Begitu node melaporkan selesai, `runtime_status` menjadi `sleeping`, `active_deployment_id` tetap terisi, `project.idle_sleep_completed` dicatat, dan `deployment.updated` dikirim dengan `project_runtime_status`. Bila gagal, project tetap `running`, `project.idle_sleep_failed` dicatat, dan sapuan berikutnya mencoba lagi. Deployment yang dimulai sebelum sleep selesai tetap memegang status; bila deployment itu gagal atau dibatalkan, project kembali `sleeping`.

Domain project yang tidur menampilkan halaman 503 dari node yang memanggil endpoint bangun publik lalu memuat ulang tiap 3 detik. Project yang tidur tidak dihitung sebagai container berjalan untuk kuota, sehingga membangunkannya, atau men-deploy ulang, memerlukan slot lagi.

`POST /api/v1/public/projects/{slug}/wake` dipanggil tanpa login, tanpa sesi, tanpa cookie, dan tanpa token CSRF, apa pun origin-nya, sebagai POST lintas origin sederhana (`mode: 'no-cors'`) yang tidak membaca jawabannya. Body tidak diperlukan. Jawabannya selalu `202` dengan body kosong, juga untuk slug yang tidak ada, project yang tidak tidur, project yang ditangguhkan atau dihapus, dan project yang tidak punya deployment di node, supaya jawabannya tidak membuka apa pun tentang project, termasuk alasan suspend. Pengecualiannya `409 PROJECT_WAKE_LIMIT_EXCEEDED` tanpa angka kuota dan `429` dari `SAKALA_PROJECT_WAKE_RATE_LIMIT` per IP dan slug. Domain project tidak boleh dimasukkan ke `SANCTUM_STATEFUL_DOMAINS`.

`POST /api/v1/app/projects/{project}/wake` dipakai tombol Bangunkan di Console, dengan hak yang sama seperti deploy: owner dan member workspace, viewer `403`, admin boleh. Jawabannya `202` dengan `ProjectResource`; `runtime_status` tetap `sleeping` sampai node melapor. Project yang ditangguhkan dijawab `409 PROJECT_SUSPENDED` (anggota dan admin), project yang tidak tidur `409 PROJECT_NOT_SLEEPING`, dan kuota penuh `409 PROJECT_WAKE_LIMIT_EXCEEDED` dengan `limit` dan `current`.

Kedua endpoint membuat `WakeProject` untuk node dan deployment yang menyimpan container-nya, dan mencatat `project.wake_requested` (`source` `public` dengan actor `system`/`public-wake` tanpa IP atau user agent, atau `console` dengan user-nya). Wake yang masih `Pending`, `Claimed`, atau `Running` dipakai ulang, jadi halaman yang memuat ulang berkali-kali hanya menghasilkan satu command. Wake sukses mengubah `runtime_status` menjadi `running`, mencatat `project.wake_completed`, dan mengirim `deployment.updated`. Wake gagal, termasuk `runtime_capacity_exceeded` saat node penuh, membiarkan project `sleeping` dan mencatat `project.wake_failed` dengan `error_code`. Wake atau restart yang sukses dihitung sebagai aktivitas, jadi project yang baru dibangunkan tidak langsung ditidurkan lagi.

Sleep karena sepi berbeda dari suspend. Sleep yang masih berjalan tidak menahan suspend atau unsuspend admin. Men-suspend project yang sedang tidur membuat `SleepProject` dengan alasan suspend seperti biasa; setelah selesai `runtime_status` menjadi `stopped` dan `active_deployment_id` kosong, sama seperti suspend lain, dan bangun ditolak sejak suspend diterima.

### Inspeksi

`POST /api/v1/app/projects/{project}/inspection` (owner atau member workspace; dibatasi `SAKALA_INSPECTION_RATE_LIMIT` per menit) menjawab `202` dengan `ProjectResource`. Hasilnya menyusul lewat `preview_status`. Inspeksi yang sedang berjalan atau sudah selesai untuk commit yang sama dipakai ulang; yang gagal, kedaluwarsa, atau dibatalkan dicoba lagi. Project yang ditangguhkan dijawab `409 PROJECT_SUSPENDED`.

`stack` ada pada `ProjectResource`, `CreateProjectResource`, dan daftar project, bernilai `null` sampai inspeksi berhasil:

| Field | Nilai | Keterangan |
| --- | --- | --- |
| `builder` | `dockerfile` atau `railpack` | Pilihan mode auto untuk direktori yang diinspeksi. |
| `provider` | string atau `null` | Provider pertama yang dideteksi railpack, misalnya `node`. |
| `framework` | string atau `null` | Dari `nodeSPAFramework` railpack, lalu `nodeRuntime` kecuali `node`, `bun`, dan `deno`. Selain itu `null`. |
| `package_manager` | string atau `null` | Dari lockfile yang ditemukan agent, lalu `metadata.nodePackageManager` railpack. |

`null` berarti tidak diketahui, bukan tebakan. Output railpack mentah tetap di server. Field yang dibaca sudah dicocokkan dengan output `railpack info` sungguhan (`detectedProviders`, `metadata.nodeSPAFramework`, `metadata.nodeRuntime`, `metadata.nodePackageManager`); contohnya ada di `tests/Fixtures/railpack/`.

Inspeksi dijalankan pada `root_directory` project. Setelah direktori itu diganti, `stack` kembali `null` karena hasil lama menggambarkan folder lain, dan console perlu meminta inspeksi ulang. Commit yang sama dengan direktori berbeda dihitung sebagai inspeksi baru. Agent v0.2.0 mengabaikan field ini dan selalu menginspeksi root repository.

Filter `GET /api/v1/app/projects?filter=` menerima `7_days`, `30_days`, `90_days`, dan `all`. Tanpa `workspace_id`, daftar berisi project dari semua workspace pemanggil, jadi pengguna tanpa tim melihat daftar yang sama seperti sebelum workspace. `workspace_id` membatasinya ke satu workspace dan dijawab `403` bila pemanggil bukan anggotanya.

`GET /api/v1/app/projects/domain-preview?name=` menjawab `{ "data": { "slug", "domain" } }` memakai generator yang sama dengan pembuatan project, termasuk suffix bentrok dan domain lingkungan. Hasilnya tidak dipesan, jadi domain akhir bisa berbeda bila ada yang mengambilnya lebih dulu. Nama yang dicadangkan dijawab `422` pada `name`.

### Menghapus Project

`DELETE /api/v1/app/projects/{project}` menjawab `204` dan project langsung hilang dari API (soft delete, kuota kembali). Bila node masih menyimpan container project, API membuat `StopProject` dengan `remove_images: true` yang menghapus route, container, dan image project di node. Itu berlaku untuk deployment aktif yang melayani, crash, atau tidur, dan juga untuk project yang suspend-nya sudah selesai: sleep menyimpan container walaupun `active_deployment_id` sudah kosong, jadi deployment `succeeded` terakhir yang terakhir kali di-sleep dan belum di-stop ikut dihapus. Purge memakai aturan yang sama saat mengirim ulang `StopProject`. Antrean yang belum diambil dibatalkan, dan attempt yang sedang berjalan diminta batal; bila tetap sukses, workload-nya ikut dihentikan.

`StopProject` tidak punya batas waktu. Saat node offline, workload tetap melayani sampai node itu kembali dan menerima command-nya, jadi penghapusan di API bukan bukti container sudah berhenti. Slug project yang dihapus tidak pernah dipakai ulang, jadi route lama tidak bisa melayani project baru.

Data project dihapus permanen `SAKALA_DELETED_PROJECT_RETENTION_DAYS` hari setelah dihapus (default 30 hari) oleh `projects:purge-deleted`, yang berjalan sekali sehari. Angka yang berlaku tersedia sebagai `deleted_project_retention_days` di `GET /api/v1/app/pilot-limits`, untuk console yang perlu menyebutkannya. Selama masa itu tidak ada endpoint untuk memulihkan project. Yang dihapus: baris project, deployment, log, event, agent command (termasuk salinan environment variable terenkripsi di payload `DeployProject`), environment variable, dan notifikasi project itu.

Kalau node belum menyelesaikan pekerjaannya saat masa itu habis, baris project menunggu node tersebut, karena baris itulah satu-satunya jalan untuk menyuruh node menghentikan container. Environment variable dan salinannya tetap dihapus tepat waktu, karena menghentikan container tidak membutuhkannya. `StopProject` yang gagal atau kedaluwarsa dikirim ulang sekali sehari. Node yang di-revoke tidak bisa menerima command lagi, jadi tidak menahan penghapusan; container di node itu harus dibereskan operator. Perintah ini mencatat jumlah project yang menunggu, dan log aplikasi menyebut ID-nya.

Yang sengaja tetap ada: slug di `retired_project_slugs`, supaya alamat lama tidak bisa diambil orang lain; `audit_events`, yang hanya berisi ID dan bukan isi project; `feedbacks` milik user, dengan `project_id` dan `deployment_id` menjadi `null`; dan `usage_signal_records`, yang punya retensi sendiri. Image hasil build di node juga tidak disentuh, karena itu urusan `CleanupRuntime`. Tidak ada secret per project yang disimpan di cache aplikasi, jadi tidak ada yang perlu dibersihkan di sana.

### Environment Variable

Semua anggota workspace membaca daftar variabel; menambah, mengubah, menghapus, dan membaca nilai secret lewat `GET .../value` hanya untuk owner dan member, jadi viewer tidak pernah melihat nilai secret. `POST` menerima `is_secret` (default `true`). `PATCH .../environment-variables/{id}` menerima `value` dan `is_secret`, keduanya opsional. `value` ada di response hanya untuk variabel non-secret; variabel secret tidak punya key itu dan tetap dibaca lewat `GET .../value`. `POST` dan `PATCH` menyertakan `redeploy_required`, karena deployment berjalan memakai nilai saat ia dibuat.

Setiap `GET .../value` untuk variabel secret mencatat `environment_variable.value_revealed` di `audit_events` sebelum nilainya dikirim: pelaku, project, `variable_id`, `variable_name`, dan `by_project_owner` (`true` bila pembacanya owner atau member workspace project), tidak pernah nilainya. Bila catatan gagal ditulis, nilainya tidak dikirim. Alamat IP dan user agent hanya disimpan untuk admin yang membaca project dari workspace yang bukan miliknya. Variabel non-secret tidak dicatat, karena nilainya sudah ada di daftar.

`ProjectStatus` adalah status control plane dan terpisah dari runtime: `draft` (default) dan `active` (deployment pertama sukses) serta `suspended` (admin). Nilai `failed` masih ada di enum tetapi tidak pernah ditulis; kegagalan deploy hanya tampak di `runtime_status`.

## Project Control

Endpoint project control menyediakan kontrol runtime yang hanya dapat digunakan oleh admin tanpa melakukan operasi Docker atau Caddy secara langsung dari API. Operasi runtime dijalankan secara asynchronous oleh Agent melalui agent command yang bersifat durable.

### Stop vs Suspend

`Stop` menghentikan workload runtime yang sedang menjadi target pada sebuah project. Operasi ini tidak mengubah status control-plane project menjadi `Suspended`.

`Suspend` mengubah status control-plane project menjadi `Suspended` dan mencegah command yang dapat menjalankan atau mengekspos workload diproses untuk project tersebut. Jika project memiliki workload yang sedang berjalan, API membuat command `SleepProject` untuk Agent.

`Stop` dan `Suspend` merupakan operasi yang berbeda dan tidak boleh diperlakukan sebagai alias satu sama lain.

### Pemberitahuan dan Membuka Penangguhan

Suspend baru mengirim notifikasi `project_issue` ke owner dan member workspace project berisi `reason` apa adanya dan kontak `SAKALA_ABUSE_EMAIL`, juga email bila `sakala.email_delivery_enabled` aktif. Notifikasi ini mengabaikan preferensi kategori, dan tidak dikirim ulang saat request diulang dengan `Idempotency-Key` yang sama. Karena `reason` sampai ke mereka, jangan menulis identitas pelapor di dalamnya.

Selama ditangguhkan, anggota workspace dan admin juga membaca alasannya dari `suspension` di `ProjectResource` dan `GET /api/v1/app/projects`. Pengguna lain tetap menerima `403` pada detail project, dan daftar project hanya berisi project dari workspace-nya.

| Field | Tipe | Keterangan |
| --- | --- | --- |
| `reason` | string atau `null` | `reason` dari permintaan suspend terbaru, apa adanya. Teks ini ditulis admin dan markup di dalamnya tidak diubah, jadi tampilkan sebagai teks, jangan sebagai HTML. `null` bila catatan permintaannya tidak ada. |
| `suspended_at` | string ISO 8601 atau `null` | Waktu permintaan suspend terbaru diterima, bukan waktu workload berhenti. `null` bila catatan permintaannya tidak ada. |

`suspension` menjadi `null` begitu penangguhan dibuka. Catatannya tetap ada di `project_control_requests` untuk audit, dan `reason` dari unsuspend, stop, atau reconcile tidak pernah tampil di field ini.

`POST /api/v1/admin/projects/{project}/unsuspend` (admin, body `reason`) menjawab `202 ProjectControlResource` dengan `command: null`. Status menjadi `active` bila project pernah punya deployment `succeeded`, selain itu `draft`. API tidak menjalankan workload lagi: pemilik diberi tahu lalu men-deploy ulang. Ditolak `409` bila project tidak `suspended` atau `SleepProject` masih berjalan, termasuk saat menunggu node yang offline. Idempotency sama dengan operasi control lain, audit event-nya `project.unsuspended`.

### Response Asynchronous

Operasi stop dan suspend mengembalikan `202 Accepted` ketika request control-plane telah diterima.

`202 Accepted` tidak berarti operasi runtime telah selesai. Runtime state diperbarui setelah Agent melaporkan hasil eksekusi command.

Jika command stop atau sleep karena suspend berhasil, runtime state dari workload yang menjadi target berubah menjadi `Stopped` dan `active_deployment_id` dikosongkan. Sleep karena sepi berbeda; lihat [Tidur Otomatis](#tidur-otomatis).

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

Jika command `StopProject` atau `SleepProject` karena suspend untuk workload yang masih menjadi target berhasil diselesaikan, runtime state project berubah menjadi `Stopped`. `SleepProject` dengan alasan `idle` dari sistem mengubahnya menjadi `sleeping` kecuali project sudah `suspended`.

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

## Workspace

Setiap project dimiliki satu workspace. Setiap user punya tepat satu workspace pribadi (`type = personal`) yang dibuat bersama akunnya; user lama mendapatkannya lewat migrasi, dan project mereka pindah ke sana. `projects.user_id` tetap ada sebagai pembuat project. Tim adalah workspace `type = team` dengan anggota dalam peran:

| Peran | Boleh |
| --- | --- |
| `owner` | Semua yang boleh dilakukan member, ditambah mengganti nama dan menghapus tim, mengundang, mengubah peran, dan mengeluarkan anggota. |
| `member` | Membuat, men-deploy, membatalkan deployment, meminta inspeksi, mengubah, dan menghapus project tim, termasuk environment variable dan nilai secret. |
| `viewer` | Membaca project, deployment, event, dan log. Tidak pernah melihat nilai secret dan tidak pernah mengubah apa pun. |

Admin tetap punya semua kuasanya atas project. Pengguna di luar workspace menerima `403` generik tanpa `code` pada project dan workspace itu, sama seperti project orang lain sebelumnya; ID yang tidak ada menjadi `404`. Akses diperiksa ulang di setiap request HTTP, jadi anggota yang keluar atau dikeluarkan langsung kehilangan akses, sementara project tetap milik workspace.

Langganan realtime berbeda: channel privat `deployment.{id}` diotorisasi sekali, saat berlangganan lewat `/broadcasting/auth`, dan server realtime tidak memeriksa ulang di setiap event. Anggota yang dikeluarkan tetap menerima event dari langganan yang sudah terbuka sampai koneksinya tersambung ulang, lalu ditolak. Isinya sama dengan yang sudah bisa ia baca sebelum dikeluarkan, dan log sudah diredaksi.

Tim selalu punya setidaknya satu owner: pemilik terakhir tidak bisa keluar, dikeluarkan, atau diturunkan (`409 LAST_WORKSPACE_OWNER`). Keputusan itu diambil di bawah row lock workspace, juga saat menghapus akun, yang mengunci semua tim tempat akun itu menjadi owner sebelum menghitung ulang siapa pemilik lainnya, sehingga dua owner yang keluar bersamaan tidak bisa meninggalkan tim tanpa owner. Owner yang keluar, dikeluarkan, diturunkan, atau menghapus akunnya ikut mencabut undangan tertunda yang ia kirim (`workspace.invitation_revoked` dengan `reason`). Workspace pribadi tidak bisa diganti namanya, dihapus, ditinggalkan, atau diberi anggota (`409 WORKSPACE_IS_PERSONAL`).

| Endpoint | Siapa | Hasil |
| --- | --- | --- |
| `GET /api/v1/app/workspaces` | Pengguna | Workspace pemanggil, yang pribadi lebih dulu, masing-masing dengan `role` pemanggil, `projects_count`, dan `active_deployments_count`. |
| `POST /api/v1/app/workspaces` | Email terverifikasi | `201`. Body `name` (maks. 100) dan `slug` opsional (format username, maks. 50, unik; dibuat dari nama bila kosong). Pemanggil menjadi owner. |
| `GET /api/v1/app/workspaces/{workspace}` | Anggota | Workspace dengan `role` pemanggil. |
| `PATCH /api/v1/app/workspaces/{workspace}` | Owner tim | Body `name`. `slug` tidak berubah. |
| `DELETE /api/v1/app/workspaces/{workspace}` | Owner tim | `204`, hanya bila tidak ada project; selain itu `409 WORKSPACE_NOT_EMPTY`. Project yang sudah dihapus menunggu purge tanpa workspace, dan environment variable-nya langsung dihapus. |
| `POST /api/v1/app/workspaces/{workspace}/leave` | Anggota tim | `204`. |
| `GET /api/v1/app/workspaces/{workspace}/members` | Anggota | `id` (ID keanggotaan), `role`, `joined_at`, `user` (`id`, `name`, `username`, `avatar_url`). `user.email` hanya untuk owner. |
| `PATCH /api/v1/app/workspaces/{workspace}/members/{member}` | Owner tim | Body `role`. |
| `DELETE /api/v1/app/workspaces/{workspace}/members/{member}` | Owner tim | `204`. Keanggotaan dari workspace lain dijawab `404`. |
| `GET /api/v1/app/workspaces/{workspace}/invitations` | Owner tim | Undangan yang masih tertunda, termasuk yang kedaluwarsa sampai dibersihkan. Yang sudah diterima, ditolak, atau dicabut tidak ikut. |
| `POST /api/v1/app/workspaces/{workspace}/invitations` | Owner tim | `201`. Body `email` dan `role`. Lihat "Undangan". |
| `DELETE /api/v1/app/workspaces/{workspace}/invitations/{invitation}` | Owner tim | `204`. Tautan di email langsung tidak berlaku dan menjawab `INVITATION_REVOKED`. Undangan yang sudah tidak tertunda dijawab `404`. |
| `GET /api/v1/app/workspace-invitations` | Pengguna | Undangan tertunda yang belum kedaluwarsa untuk email terverifikasi pemanggil. |
| `GET /api/v1/app/workspace-invitations/{invitation}` | Pemegang tautan | Detail undangan tanpa alamat email. Query `token` opsional. Lihat "Membaca undangan". |
| `POST /api/v1/app/workspace-invitations/{invitation}/accept` | Penerima | `WorkspaceResource` dengan peran barunya. Body `token` opsional. |
| `POST /api/v1/app/workspace-invitations/{invitation}/decline` | Penerima | `204`. Body `token` opsional. |

`WorkspaceResource` berisi `id`, `name`, `slug`, `type`, `role` (peran pemanggil), `limit_profile` (`id`, `name`, atau `null` untuk default tipenya), dan `created_at`.

### Membuat Tim

User dengan email terverifikasi boleh membuat tim (`403 EMAIL_VERIFICATION_REQUIRED` bila belum). Jumlah tim yang dimiliki dibatasi `SAKALA_MAX_OWNED_TEAMS_PER_USER` (default 2); `users.max_owned_teams` menaikkannya per user dan diatur lewat API admin. Yang dihitung adalah tim tempat user menjadi owner, di bawah row lock user, dan batas ini berlaku di setiap jalan menuju kepemilikan: membuat tim, menerima undangan owner, dan dijadikan owner oleh owner lain (`422 TEAM_LIMIT_EXCEEDED`). Dua request tidak bisa sama-sama lolos dari slot terakhir. Admin tidak dibatasi, sama seperti kuota project.

### Undangan

Owner mengundang alamat email dengan peran. API mengirim email lewat mailer yang sama dengan email akun, jadi endpoint ini dijawab `503 EMAIL_DELIVERY_UNAVAILABLE` saat pengiriman email mati, dan dibatasi `SAKALA_WORKSPACE_INVITATION_RATE_LIMIT` per jam per user. Email berisi tautan `SAKALA_CONSOLE_URL/workspace-invitations/{id}?token=...`; token hanya ada di email, dan baris undangan menyimpan hash SHA-256-nya. Undangan berlaku `SAKALA_WORKSPACE_INVITATION_TTL_HOURS` (default 168 jam). Mengundang alamat yang sama lagi mengganti undangan yang tertunda: token, masa berlaku, peran, dan emailnya baru, dan tautan lama menjawab `INVITATION_REVOKED`. Alamat milik anggota dijawab `409 ALREADY_WORKSPACE_MEMBER`.

Menerima, menolak, mencabut, atau mengganti undangan menutup barisnya (`status` menjadi `accepted`, `declined`, atau `revoked`, dengan `closed_at`), tidak menghapusnya, supaya pemegang tautan tahu kenapa tautannya tidak berlaku lagi. Setiap undangan, tertutup atau tidak, dihapus `workspaces:prune-invitations` (harian) setelah lewat `SAKALA_WORKSPACE_INVITATION_RETENTION_HOURS` (default 168 jam) dari masa berlakunya. Setelah itu tautannya menjawab `404`.

Akun yang sudah memakai alamat itu dengan email terverifikasi juga menerima notifikasi `workspace` berisi `invitation_id`, tanpa token, kecuali kategori `workspace` dimatikan. Email undangan selalu dikirim, karena email itulah undangannya. Undangan bisa diterima dengan dua cara:

- dengan `token` dari email, dari akun terverifikasi mana pun, karena tautan itu buktinya, seperti undangan email pada umumnya;
- tanpa token, oleh akun yang email terverifikasinya sama dengan alamat undangan.

Undangan sebagai `owner` lebih ketat: hanya akun yang email terverifikasinya sama dengan alamat undangan (tanpa membedakan huruf besar dan kecil) yang bisa menerimanya, dengan atau tanpa token. Tautan yang diteruskan ke orang lain tidak boleh menyerahkan tim; akun lain yang membawa token dijawab `403 INVITATION_EMAIL_MISMATCH`. Undangan `member` dan `viewer` tetap bisa diterima dengan token dari akun terverifikasi mana pun.

Membaca, menerima, dan menolak memeriksa dengan urutan yang sama:

1. Tanpa token yang benar (dibandingkan dalam waktu konstan) dan bukan alamat undangan yang terverifikasi: `404` dengan body yang sama persis seperti ID yang tidak ada, apa pun status undangannya. Pemegang token yang salah tidak bisa tahu undangan itu ada.
2. Akun yang belum terverifikasi: `403 EMAIL_VERIFICATION_REQUIRED`.
3. Sudah diterima, ditolak, atau dicabut: `409 INVITATION_ALREADY_ACCEPTED`, `INVITATION_DECLINED`, atau `INVITATION_REVOKED`.
4. Lewat masa berlaku: `409 INVITATION_EXPIRED`.

Setelah itu, menerima masih bisa dijawab `403 INVITATION_EMAIL_MISMATCH` (undangan `owner` dari akun lain), `409 ALREADY_WORKSPACE_MEMBER`, atau `422 TEAM_LIMIT_EXCEEDED` (undangan `owner`). Menerima atau menolak menutup undangan. Menolak undangan yang sudah kedaluwarsa kini dijawab `409 INVITATION_EXPIRED`, karena tidak ada lagi yang perlu ditolak.

#### Membaca undangan

`GET /api/v1/app/workspace-invitations/{invitation}?token=` dipakai halaman tautan undangan di Console, sehingga undangan tetap bisa dibuka walaupun dikirim ke alamat lain dan tidak muncul di daftar undangan akun itu. Endpoint ini memakai bukti dan urutan jawaban di atas, dan rate limit yang sama dengan menerima dan menolak (`SAKALA_ACCOUNT_SECURITY_RATE_LIMIT` per menit per user, dihitung bersama). Jawabannya `WorkspaceInvitationDetailsResource`:

| Field | Tipe | Arti |
| --- | --- | --- |
| `id` | string (UUID) | ID undangan. |
| `role` | `owner`, `member`, atau `viewer` | Peran yang ditawarkan. |
| `expires_at` | string (ISO 8601) | Batas waktu menerima. |
| `created_at` | string (ISO 8601) atau `null` | Waktu undangan dibuat. |
| `workspace` | object | `id`, `name`, `slug` tim. |
| `invited_by` | object atau `null` | `id`, `name`, `username` pengundang; `null` bila akunnya sudah dihapus. |
| `restricted_to_invited_email` | boolean | `true` untuk undangan `owner`: hanya akun dengan alamat undangan yang terverifikasi yang bisa menerimanya. Alamatnya tidak pernah disertakan. |
| `can_accept` | boolean | Apakah akun ini bisa menerimanya sekarang. |
| `accept_error_code` | `INVITATION_EMAIL_MISMATCH`, `ALREADY_WORKSPACE_MEMBER`, `TEAM_LIMIT_EXCEEDED`, atau `null` | Kode yang akan dijawab bila akun ini menerima sekarang; `null` bila `can_accept` bernilai `true`. Menerima tetap memeriksa ulang di bawah row lock. |

### Batas Workspace

Batas dihitung per workspace, tidak pernah per anggota, dan datang dari profil batas (`limit_profiles`) yang dipasang admin ke workspace. Workspace tanpa profil memakai default tipenya:

| Batas | Workspace pribadi tanpa profil | Tim tanpa profil |
| --- | --- | --- |
| Jumlah project | `SAKALA_MAX_PROJECTS_PER_USER` (3) | `SAKALA_TEAM_MAX_PROJECTS` (2) |
| Container berjalan | `SAKALA_MAX_ACTIVE_CONTAINERS_PER_WORKSPACE`, bila kosong sama dengan jumlah project | `SAKALA_TEAM_MAX_ACTIVE_CONTAINERS` (2) |
| Deployment berjalan | `SAKALA_MAX_ACTIVE_DEPLOYMENTS_PER_USER` (2) | `SAKALA_TEAM_MAX_ACTIVE_DEPLOYMENTS` (1) |
| Memory per project | `SAKALA_MAX_CONTAINER_MEMORY_MB` (512) | `SAKALA_TEAM_MAX_CONTAINER_MEMORY_MB`, bila kosong sama dengan pribadi |
| CPU per project | `SAKALA_MAX_CONTAINER_CPU_MILLIS` (1000) | `SAKALA_TEAM_MAX_CONTAINER_CPU_MILLIS`, bila kosong sama dengan pribadi |
| Build per jam | belum ditegakkan | belum ditegakkan |
| Tidur setelah sepi | `SAKALA_PROJECT_IDLE_SLEEP_MINUTES` (0, mati) | sama dengan pribadi |

Dengan default ini tidak ada yang berubah bagi user yang sudah ada. Default tim sengaja kecil dan tidak mengikuti nilai pribadi, supaya membuat tim tidak menggandakan kuota; tim yang butuh lebih mendapat profil batas dari admin. Nilai env yang kosong memakai default di tabel. Container berjalan adalah project yang melayani (`active_deployment_id` terisi dan tidak `sleeping`) atau sedang di-deploy; batasnya berlaku saat men-deploy project yang belum melayani atau sedang tidur, dan saat membangunkan project. `idle_sleep_minutes` profil (0 sampai 10080, atau `null`) mengatur jendela sepi: `null` mengikuti `SAKALA_PROJECT_IDLE_SLEEP_MINUTES`, `0` berarti project workspace itu tidak pernah ditidurkan, dan nilai lain menidurkannya setelah sekian menit, juga saat nilai global `0`. Default memory dan CPU tidak pernah melebihi batas profil. `max_builds_per_hour` disiapkan untuk batas build (#124). Batas deployment per project (`SAKALA_MAX_ACTIVE_DEPLOYMENTS_PER_PROJECT`) tetap global.

Membuat project mengunci baris user lalu baris workspace, dan membuat deployment mengunci baris workspace lalu baris project, sehingga dua request di workspace yang sama dihitung bergantian. Admin yang bertindak tidak dibatasi, sama seperti sebelumnya.

`POST /api/v1/app/projects` menerima `workspace_id` opsional; tanpa itu project masuk ke workspace pribadi pemanggil, jadi Console lama tetap bekerja. Workspace yang tidak ada atau bukan UUID dijawab `422`; workspace yang tidak boleh diisi pemanggil (viewer atau bukan anggota) dijawab `403`. `GET /api/v1/app/pilot-limits?workspace_id=` menjawab untuk workspace itu, atau workspace pribadi bila kosong. Field lamanya tetap, dengan `_per_user` berarti per workspace, ditambah `workspace_id`, `limit_profile`, `quotas.max_active_containers`, `quotas.current_active_containers_count` (tanpa project yang `sleeping`), dan `project_idle_sleep_minutes` (jendela sepi efektif, `0` berarti tidak pernah tidur); `runtime_defaults` dan `runtime_maximums` untuk memory dan CPU mengikuti profil.

### Akses GitHub untuk Project Tim

Project dari URL publik tidak memakai credential, jadi deploy-nya tetap berjalan walaupun pembuatnya keluar. Project dari GitHub App selalu bersandar pada satu orang yang masih bisa bertindak atas project:

| Workspace | Sandaran akses | Yang diperiksa |
| --- | --- | --- |
| Pribadi | Pemiliknya, seperti sebelumnya. | Masih terhubung ke installation dan masih bisa membaca repository dengan token GitHub-nya. |
| Tim | Owner atau member saat ini yang punya identitas GitHub tertaut dan terhubung ke installation project. Urutannya: pembuat project selama ia masih owner atau member, lalu owner yang paling lama, lalu member yang paling lama. | Pada installation di akun organisasi GitHub, orang itu hanya jangkar: token installation yang membaca repository, dan organisasi yang menentukan repository mana yang dilihat installation. Pada installation di akun GitHub pribadi, token orang itu juga harus masih bisa membaca repository. |

Bila tidak ada lagi orang yang memenuhi syarat, misalnya semua yang menghubungkan installation sudah keluar dari tim, aksesnya dianggap dicabut: deploy dijawab `409 REPOSITORY_ACCESS_REVOKED`, webhook `push` dilewati dengan alasan `repository_access_revoked`, dan lease credential ditolak `409`. Tim memulihkannya dengan menghubungkan installation lewat akun owner atau member yang masih ada. GitHub yang menolak mencetak token untuk repository yang sudah dikeluarkan dari installation juga membuat lease ditolak `409 repository_access_revoked`.

Aturan yang sama dipakai saat deploy manual, webhook `push`, dan lease credential agent.

### Menghapus Akun dan Tim

Menghapus akun menghapus workspace pribadi dan project-nya seperti sebelumnya, juga semua keanggotaannya dan undangan tertunda yang dikirimnya. Tim yang dimiliki sendirian ikut dihapus bila kosong; bila masih punya project, penghapusan ditolak `409 LAST_WORKSPACE_OWNER` dengan daftar tim itu, sebelum apa pun dihapus. Project tim yang dibuat akun itu tetap milik timnya dengan `user_id` kosong. Lihat [Menghapus Akun](AUTHENTICATION.md#menghapus-akun).

### Audit

Perubahan dicatat di `audit_events` dengan subject workspace, hanya berisi ID dan peran, tanpa email atau nama: `workspace.created`, `workspace.renamed`, `workspace.deleted`, `workspace.member_joined`, `workspace.member_left`, `workspace.member_removed`, `workspace.member_role_changed`, `workspace.invitation_created`, `workspace.invitation_revoked`, `workspace.invitation_declined`, dan `workspace.limit_profile_changed`.

## Notifikasi

Notifikasi in-app memakai database notification Laravel. Kategori: `deployment_success`, `deployment_failure`, `project_issue` (workload crash, auto-deploy dilewati), `account_security` (kata sandi dan email), dan `workspace` (tim). `GET` dan `PATCH /notification-preferences` ikut punya `workspace`, default `true`.

Kategori `workspace` dikirim untuk:

- undangan ke alamat terverifikasi akun, dengan `invitation_id`;
- undangan yang diterima, ke pengundangnya, dengan `invitation_id` dan `workspace_id`;
- perubahan peran oleh owner lain, ke anggota itu, dengan `workspace_id`;
- dikeluarkan dari tim oleh owner, ke anggota itu, tanpa `workspace_id` karena timnya tidak bisa dibuka lagi.

Keluar sendiri atau mengubah peran sendiri tidak mengirim apa pun. Email undangan tidak terpengaruh preferensi ini.

Notifikasi project sampai ke owner dan member workspace-nya, tidak ke viewer; di workspace pribadi itu berarti pemiliknya, sama seperti sebelumnya. Kabar deployment (`deployment_success`, `deployment_failure`) hanya sampai ke orang yang memintanya selama ia masih owner atau member, dan ke semua owner serta member bila tidak.

| Endpoint | Hasil |
| --- | --- |
| `GET /api/v1/app/notifications` | Terbaru dulu, `page`, `per_page` (maks. 50), `unread=1`. |
| `GET /api/v1/app/notifications/unread-count` | `{ "data": { "count" } }` |
| `POST /api/v1/app/notifications/{id}/read` | Resource notifikasi. Idempoten, `read_at` pertama dipertahankan. Milik orang lain `404`. |
| `POST /api/v1/app/notifications/read-all` | `204` |
| `GET`, `PATCH /api/v1/app/notification-preferences` | Satu boolean per kategori, default `true`. `PATCH` boleh sebagian. |

Satu crash menghasilkan satu notifikasi `project_issue`: heartbeat berikutnya yang masih melaporkan crash tidak mengirim lagi, begitu juga setelah redeploy gagal, dan pemulihan tidak mengirim apa pun. Crash baru setelah workload pulih mengirim lagi. Selama ada deployment baru yang berjalan, heartbeat tidak mengubah `runtime_status`.

Bentuk item: `id`, `category`, `title`, `message`, `project_id`, `deployment_id`, `invitation_id`, `workspace_id`, `read_at`, `created_at`. Empat ID itu string atau `null`; `invitation_id` dan `workspace_id` hanya diisi notifikasi `workspace`, dan notifikasi lama tidak memilikinya. Kategori yang dimatikan tidak membuat notifikasi sama sekali, kecuali pemberitahuan penangguhan. Notifikasi baru juga dibroadcast pada channel privat `users.{id}` dengan event `notification.created` dan field yang sama. Kiriman terjadi setelah transaksi commit, dan kegagalannya dilaporkan tanpa menggagalkan laporan agent yang memicunya.

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

## API Admin

Semua endpoint admin ada di `/api/v1/admin` (`routes/api/v1/admin.php`) dan dijaga middleware `auth:web` lalu `can:admin`. Gate `admin` hanya lolos untuk `users.role = admin`, dan peran dibaca ulang di setiap request, jadi admin yang diturunkan kehilangan akses di request berikutnya. Tamu menerima `401`, pengguna biasa `403`. ID yang tidak ada tetap `404`, karena route model binding berjalan sebelum gate. Console hanya menyembunyikan menu admin; API yang menentukan.

Setiap aksi yang mengubah sesuatu wajib membawa `reason` (maks. 500) dan menulis `audit_events` dengan pelaku, alamat IP, user agent, dan `metadata.reason`. Mengulang aksi yang tidak mengubah apa pun (peran yang sama, profil yang sama, status yang sama, revoke kedua) tidak menulis audit baru. Secret, token, hash kata sandi, dan nilai environment variable tidak pernah ada di response maupun metadata audit.

Perubahan peran lewat `php artisan user:admin` di server juga diaudit (`user.role_changed`, dan `user.admin_created` untuk akun baru), dengan `actor.type` `system`, `actor.id` `cli`, `metadata.os_user`, dan tanpa alamat IP atau user agent. Lihat `docs/DEVELOPMENT.md#akun-admin`.

### Daftar, Pagination, dan Rentang Waktu

Semua daftar memakai pagination server: `page`, `per_page` (1 sampai 100, default 25), dengan `meta` dan `links` standar, urut dari yang terbaru. `search` mencocokkan beberapa kolom tanpa membedakan huruf besar dan kecil (`ILIKE` di PostgreSQL).

Endpoint dengan rentang waktu menerima `range`: `7d`, `30d`, `90d`, `custom` (dengan `from` dan `to` berformat `Y-m-d`, paling panjang 366 hari), dan `all` bila disebutkan. Rentang selalu berupa hari penuh WIB (`Asia/Jakarta`, UTC+7 tanpa DST): `7d` adalah hari ini ditambah enam hari sebelumnya, dan `to` adalah tengah malam WIB berikutnya (eksklusif). `range` di response memuat `key`, `from`, `to` (UTC, ISO 8601), dan `timezone`. Hitungan deployment memakai `created_at`.

### Endpoint

| Endpoint | Hasil |
| --- | --- |
| `GET overview` | Dashboard. `range` (default `30d`, tanpa `all`). Lihat "Overview". |
| `GET users` | `search` (nama, username, email), `role`, `verified`. |
| `GET users/{user}` | Akun, `workspaces` dengan peran dan jumlah project, `personal_workspace_quota` (batas efektif dan pemakaian), `owned_teams_count`, `effective_max_owned_teams`, 20 audit event terbaru tempat akun ini pelaku atau subjek. |
| `PATCH users/{user}/role` | Body `role` (`user`, `admin`), `reason`. `409 ADMIN_SELF_DEMOTION` atau `LAST_ADMIN`. |
| `PATCH users/{user}/team-limit` | Body `max_owned_teams` (0 sampai 1000, atau `null` untuk default konfigurasi), `reason`. Batas di bawah jumlah tim yang sudah dimiliki hanya menahan tim baru. |
| `PUT users/{user}/limit-profile` | Body `limit_profile_id` (atau `null`), `reason`. Dipasang ke workspace pribadi user. |
| `GET workspaces` | `search` (nama, slug), `type`, `limit_profile_id`. |
| `GET workspaces/{workspace}` | Workspace, `members` (dengan email), `projects`, `quota`. |
| `PUT workspaces/{workspace}/limit-profile` | Body `limit_profile_id` (atau `null`), `reason`. Audit `workspace.limit_profile_changed`. |
| `GET`, `POST limit-profiles`; `GET`, `PATCH`, `DELETE limit-profiles/{limitProfile}` | Profil batas dengan `workspaces_count` dan `idle_sleep_minutes` (`null` mengikuti global, `0` tidak pernah tidur, 1 sampai 10080 menit). Perubahannya tercatat di `limit_profile.created` dan `limit_profile.updated` seperti field lain. `GET workspaces/{workspace}` menyajikan nilai efektifnya di `quota.limits.idle_sleep_minutes`. `PATCH` sebagian. `DELETE` membawa `reason` di body dan dijawab `409 LIMIT_PROFILE_IN_USE` (dengan `workspaces`) selama masih dipakai. |
| `GET projects` | `search` (nama, slug, `owner/repo`), `status`, `runtime_status` (termasuk `sleeping`), `workspace_id`, `suspended`. Project yang dihapus tidak ikut. |
| `GET projects/{project}` | Project, `deployments_count`, `effective_port`, 20 control request terbaru dengan `reason`. Aksi stop, suspend, unsuspend, dan reconcile tetap seperti di "Project Control". |
| `GET deployments/failures` | Deployment `failed` lintas project. `range` (default `30d`, boleh `all`), `category`, `code`, `search` (nama atau slug project, termasuk project yang menunggu purge). |
| `GET deployments/failure-summary` | Jumlah per kategori dan per kode untuk `range`, dengan `previous_count` untuk jendela sebelumnya (`null` untuk `all`). |
| `GET deployments/{deployment}` | `deployment` (sama dengan `DeploymentResource`), `project`, `requested_by`, `agent_node`, `duration_seconds`. |
| `GET deployments/{deployment}/events`, `/logs` | Sama dengan endpoint member untuk deployment itu, termasuk parameter cursor dan `order`. |
| `GET agents` | `search` (nama, agent ID, hostname), `status`, `auth_status`, `never_seen`. Lihat "Runtime Node". |
| `POST agents` | Body `name`, `description`, `reason`. `201` dengan `agent` dan `token`. |
| `GET agents/{agent}` | Node. |
| `POST agents/{agent}/rotate` | Body `reason`. Token baru sekali tampil; token lama langsung tidak berlaku. |
| `POST agents/{agent}/revoke` | Body `reason`. Node tidak bisa autentikasi atau menerima command lagi. Idempoten. |
| `GET agents/{agent}/commands` | Riwayat command node: `type`, `status`, project, `deployment_id`, waktu, `error_code`, `error_message` (sudah diredaksi), `reports_count`. Filter `type`, `status`. |
| `GET agents/{agent}/commands/{command}` | Command dengan sampai 200 report terbaru. Command milik node lain `404`. |
| `GET feedback` | `status` (`open`, `acknowledged`, `resolved`), `category`, `search` (isi pesan). |
| `PATCH feedback/{feedback}` | Body `status`, `reason`. |
| `GET audit-events` | `actor_type` + `actor_id`, `action` (persis), `subject_type` + `subject_id`, `range` (default `all`). |
| `GET metrics/pilot-validation`, `GET signals` | Tetap seperti sebelumnya. |

### Overview

`totals` dihitung sepanjang waktu: `users`, `projects` (yang masih ada), dan `active_projects` (project dengan deployment yang melayani, `active_deployment_id` terisi dan tidak `sleeping`). `period` dihitung untuk rentang: `new_users`, `new_projects` (termasuk yang sudah dihapus), dan `deployments` (`succeeded`, `failed`, `cancelled`, `in_progress`, `total`). `previous_period` berisi hitungan yang sama untuk jendela sepanjang rentang tepat sebelumnya, untuk badge tren. `user_growth` adalah user baru per hari WIB, setiap hari dalam rentang ada, termasuk yang nol.

`failure_categories` memuat semua kategori `DeploymentFailureCategory` dan `top_failure_codes` sepuluh kode terbanyak. Kategori tidak disimpan; ia diturunkan dari `failure_code` dengan `DeploymentFailureClassifier` yang sama dengan field `failure` di deployment, jadi angkanya selalu sama dengan yang dilihat pemilik project. Kegagalan tanpa kode dihitung `unknown` dengan `code: null`. Filter `category` di daftar kegagalan memakai daftar kode yang tercatat untuk kategori itu.

`new_user_sources` adalah jawaban pertanyaan onboarding "dari mana kamu tahu Sakala" (`users.onboarding_source`) untuk user baru di rentang. Semua nilai `OnboardingSource` selalu ada, dan entri terakhir `source: null` menghitung user yang belum menjawab. `pilot` memuat `activated_users`, `unique_deployers`, `repeat_deployers`, dan `feedback_count` untuk rentang yang sama.

Hasil overview disimpan di cache 60 detik, dan `generated_at` menunjukkan kapan angkanya dihitung.

### Runtime Node

`AdminAgentResource` menambah `agent_version` (dari heartbeat terakhir), `hostname`, `runtime_network` (nama network Docker di node, bukan alamat IP), `capabilities`, `registered_at`, `active_workload_count` (project yang deployment aktifnya ada di node ini, termasuk yang `sleeping` karena container-nya tetap disimpan di node), dan `open_commands_count` (`Pending`, `Claimed`, `Running`). Node yang belum pernah heartbeat punya `last_seen_at: null`; `never_seen=1` menampilkannya. Token dan hash-nya tidak pernah ada di response.

`POST agents` memakai action yang sama dengan `artisan agent:provision`, yang dipanggil `provision-agent.sh` di repository deployment. Token hanya ada di response pembuatan atau rotate (`Cache-Control: no-store`); API hanya menyimpan HMAC-nya, jadi token yang hilang diganti dengan rotate. Command `DeployProject` melaporkan output sebagai log deployment, dibaca lewat `deployments/{deployment}/logs`; report command lain ada di detail command. Log proses `sakala-agent` sendiri tidak dikirim ke API dan tidak disimpan, jadi tidak ada endpoint untuknya. Restart agent juga tidak punya command; yang ada drain, resume, dan cleanup di `/api/agent/v1/agents/{agent}`.

### Yang Belum Ada

Tidak ada model laporan abuse: sinyal `manual_intervention` di `signals` dan audit `project.suspend_requested` adalah catatan yang ada sekarang. Akun pengguna juga tidak punya status suspend; yang bisa ditangguhkan adalah project.
