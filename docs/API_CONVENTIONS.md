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
| `ACTIVE_CONTAINER_LIMIT_EXCEEDED` | `422` | Deploy akan melewati batas container berjalan workspace: project yang belum melayani butuh slot untuk semua replikanya, dan project yang melayani hanya untuk replika tambahannya. Redeploy dengan replika sama atau lebih sedikit tidak pernah kena. | `limit`, `current` (container yang berjalan di workspace, termasuk milik project ini), `requested` (replika deployment ini) |
| `BUILD_RATE_LIMIT_EXCEEDED` | `422` | Workspace sudah membuat deployment yang mem-build sebanyak batas build per jamnya dalam 60 menit terakhir. Setiap deployment yang mem-build dihitung, apa pun statusnya; deployment yang memakai ulang image tidak dihitung dan tidak pernah ditolak dengan kode ini. Lihat [Batas Workspace](#batas-workspace). | `limit`, `current` (deployment workspace yang mem-build dalam 60 menit terakhir, termasuk milik project yang dihapus; bisa lebih dari `limit` bila admin menurunkan batasnya), `retry_after_seconds` (detik sampai ada slot kosong, minimal `1`) |
| `RESOURCE_LIMIT_EXCEEDED` | `422` | Resource yang diminta di luar batas pilot. | `resource`, `requested`, `maximum` |
| `PROJECT_SUSPENDED` | `409` | Deploy, inspeksi ulang, atau membangunkan lewat Console project yang ditangguhkan admin. | - |
| `PROJECT_NOT_SLEEPING` | `409` | Membangunkan lewat Console project yang `runtime_status`-nya bukan `sleeping`. | `runtime_status` |
| `PROJECT_WAKE_LIMIT_EXCEEDED` | `409` | Membangunkan project, dengan semua replika deployment yang tidur, akan melewati batas container berjalan workspace. Endpoint publik tidak menyertakan angkanya. | `limit`, `current` (container project lain), `requested` (replika yang dinyalakan); hanya lewat Console |
| `PROJECT_REPLICAS_UNSUPPORTED` | `409` | Deploy project dengan lebih dari 1 replika yang tidak bisa dijalankan node mana pun: node-nya belum punya capability `replicas`, atau batas replika per project node itu lebih kecil. Lihat [Replika](#replika). | `replicas`, `node_maximum` (batas terbesar yang dilaporkan node, atau `null` bila tidak ada node yang bisa menjalankan replika) |
| `PROJECT_RELEASE_COMMAND_UNSUPPORTED` | `409` | Deploy project dengan `release_command` yang tidak bisa dijalankan node mana pun: node tempat project berjalan, atau untuk project baru semua node aktif, belum punya capability `release-command`. Kosongkan `release_command` atau tunggu operator memperbarui agent. Lihat [Perintah Rilis](#perintah-rilis). | - |
| `PROJECT_REGION_UNAVAILABLE` | `409` | Deploy project yang harus berjalan di region (miliknya sendiri atau default profil workspace) yang tidak punya node yang diizinkan menerima pekerjaan sama sekali. Node region itu yang hanya offline ditunggu. Lihat [Region dan Penempatan](#region-dan-penempatan). | `region` |
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
| `LAST_WORKSPACE_OWNER` | `409` | Pemilik terakhir tim keluar, dikeluarkan, atau diturunkan perannya, atau menghapus akun selagi timnya masih punya proyek atau database. | `workspaces` (`id`, `name`, `slug`) |
| `WORKSPACE_NOT_EMPTY` | `409` | Menghapus tim yang masih punya proyek atau database. | `projects`, `databases` |
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
| `DATABASE_LIMIT_EXCEEDED` | `422` | Workspace sudah punya database sebanyak `max_databases` efektifnya. `limit` `0` berarti workspace itu belum boleh membuat database sama sekali. Lihat [Database](#database). | `limit`, `current` |
| `DATABASE_STORAGE_LIMIT_EXCEEDED` | `422` | Membuat database saat ukuran database workspace sudah mencapai `max_database_storage_mb`; database baru akan langsung dikunci. | `limit_mb`, `current_bytes` |
| `DATABASE_REGION_UNAVAILABLE` | `409` | Membuat database di region yang tidak punya node `managed-postgres` yang diizinkan menerima pekerjaan, atau di mana pun bila `region` `null`. | `region` (string atau `null`) |
| `DATABASE_CROSS_REGION_UNSUPPORTED` | `409` | Memasang database ke proyek yang berjalan, atau harus berjalan, di region lain, atau membuat database untuk proyek itu (`project_id`) dengan `region` lain. | `database_region`, `project_region` |
| `DATABASE_NOT_READY` | `409` | Memasang, membaca kredensial, atau merotasi password database yang masih `provisioning`, `failed`, atau `deleting`. Juga deploy proyek yang database terpasangnya masih `provisioning` atau `failed`; push webhook dilewati dengan `reason` `database_not_ready`. Lihat [Membuat Database untuk Proyek](#membuat-database-untuk-proyek). | `status` |
| `DATABASE_LOCKED` | `409` | Memasang database yang `locked` karena penyimpanan workspace penuh. | - |
| `DATABASE_IN_USE` | `409` | Menghapus database yang masih terpasang ke proyek. | `projects` (`id`, `name`, `slug`) |
| `DATABASE_BUSY` | `409` | Merotasi password saat rotasi sebelumnya belum selesai, atau menghapus database yang sedang dibuat node. Aman diulang setelahnya. | - |
| `PROJECT_DATABASE_ALREADY_ATTACHED` | `409` | Memasang database ke proyek, atau membuat database untuk proyek (`project_id`), yang sudah memakai database lain. | `database` (`id`, `name`) |
| `DATABASE_UNAVAILABLE` | `409` | Membuat database untuk proyek (`project_id`) saat tidak ada server database yang bisa menerima database baru (`databases.available` `false`). Membuat database tanpa proyek tidak kena. | - |
| `DATABASE_ENDPOINT_UNAVAILABLE` | `409` | Deploy proyek yang database terpasangnya ada di node yang belum melaporkan alamatnya (`metadata.database.endpoint`). | `database` (`id`, `name`) |

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

Webhook `push` membuat deployment untuk project yang `auto_deploy`-nya aktif bila repository, installation, dan branch cocok, memakai commit dari payload dengan `trigger = webhook`. Deployment itu atas nama pemilik workspace pribadi, atau di tim atas nama pembuat project selama ia masih owner atau member, lalu owner tim yang paling lama. Signature diverifikasi lebih dulu, dan `X-GitHub-Delivery` membuat pengiriman ulang tidak melakukan apa-apa. Project yang ditangguhkan, yang kena batas deployment aktif, yang workspace-nya sudah mencapai batas build per jam, atau yang `release_command`-nya tidak bisa dijalankan node mana pun dilewati: webhook tetap `200`, tindakan dicatat di audit (`project.auto_deploy_skipped`, dengan `reason` seperti `build_rate_limit`, `release_command_unsupported`, atau `database_not_ready`), dan owner serta member workspace mendapat notifikasi `project_issue`. Notifikasi batas build menyebut kapan bisa deploy lagi. Pengiriman yang dilewati tidak dicoba ulang. GitHub App harus berlangganan event `push`.

### Push di Monorepo

Beberapa project di satu workspace boleh memakai repository dan branch yang sama, masing-masing dengan `root_directory` sendiri (misalnya `apps/web`, `apps/api`, `apps/landing`). Push hanya men-deploy project yang terkena perubahan, supaya perubahan di satu folder tidak mengantrekan build semua aplikasi. Belum ada setelan untuk aturan ini; daftar path yang diawasi (watch paths) sengaja ditunda.

Aturannya:

- File yang berubah adalah gabungan `added`, `removed`, dan `modified` dari semua `commits[]` di payload push.
- Setiap project di workspace yang sama dengan repository (`github_repository_id`) dan branch yang sama memiliki folder `root_directory`-nya, apa pun setelan `auto_deploy`-nya. Project yang sudah dihapus, project di branch lain, dan project di workspace lain tidak memiliki folder di sini. `root_directory` dinormalkan tanpa `./` di depan dan tanpa `/` di depan atau belakang.
- Sebuah file milik root yang paling spesifik yang memuatnya: dengan `apps/web` dan `apps/web/admin`, file `apps/web/admin/x.ts` milik `apps/web/admin`. Pencocokan per segmen path, jadi `apps/webhooks/x.ts` bukan milik `apps/web`.
- File yang tidak berada di folder project mana pun adalah file bersama, misalnya lockfile, `README.md` di root, `turbo.json`, atau `packages/ui`.
- Project ber-`auto_deploy` di-deploy bila ada file yang berubah di foldernya sendiri atau ada file bersama yang berubah.
- Project tanpa `root_directory` (null, kosong, atau `.`) membangun seluruh repository: selalu di-deploy, dan tidak memiliki folder, jadi tidak membuat file lain berhenti menjadi file bersama.

Kalau ragu, push di-deploy seperti sebelumnya (fail-open). Itu berlaku untuk seluruh push bila `forced` atau `created` bernilai `true`, `commits` tidak ada atau kosong, jumlah `commits` mencapai 2048 (GitHub menulis bahwa array itu memuat paling banyak 2048 commit), satu commit mencapai 3000 file di `added`, `removed`, dan `modified` (batas file per commit yang ditulis GitHub), ada commit tanpa salah satu daftar file itu, isinya bukan daftar string, atau tidak ada file yang berubah sama sekali (commit kosong yang sering dipakai untuk memicu deploy). Push juga di-deploy untuk satu project bila project itu belum pernah punya deployment `succeeded`, deployment terbarunya `failed` atau `cancelled` atau berasal dari branch lain, atau ada push yang dilewati karena batas (`project.auto_deploy_skipped`) sejak deployment terbarunya, karena versi yang berjalan mungkin belum memuat perubahan sebelumnya. Deploy manual, redeploy, dan trigger lain tidak terpengaruh.

Push yang dilewati karena aturan ini dicatat sebagai audit event `project.push_skipped` (actor `github_app`, subjek project) di transaksi yang sama dengan catatan delivery, jadi pengiriman ulang dengan `X-GitHub-Delivery` yang sama tidak mencatatnya dua kali. Catatan itu bukan deployment: tidak memakai nomor urut, tidak dihitung dalam batas build per jam atau batas deployment aktif, tidak masuk metrik, tidak mengubah deployment terbaru project, dan tidak mengirim notifikasi atau email. Webhook tetap `200`.

`GET /api/v1/app/projects/{project}/skipped-pushes` menampilkan catatan itu, terbaru dulu, dengan hak yang sama seperti melihat project: owner, member, dan viewer workspace, serta admin. Selain itu `403`, dan tanpa sesi `401`. Pagination standar (`data`, `links`, `meta`).

| Query | Nilai | Keterangan |
| --- | --- | --- |
| `page` | integer ≥ 1 | Opsional, default 1. |
| `per_page` | integer 1 sampai 100 | Opsional, default 20. Di luar itu `422` pada `per_page`. |
| `since` | tanggal-waktu ISO 8601 | Opsional. Hanya push yang dilewati pada atau setelah waktu ini. Nilai yang bukan tanggal `422` pada `since`. |

| Field | Tipe | Keterangan |
| --- | --- | --- |
| `delivery_id` | string (UUID), selalu ada | `X-GitHub-Delivery` push yang dilewati. Unik per project. |
| `reason` | string enum, selalu ada | Saat ini hanya `no_changes_in_root_directory`: semua file yang berubah ada di folder project lain. |
| `commit_sha` | string, 40 karakter heksadesimal, selalu ada | Commit `after` dari push. |
| `commit_message` | string atau `null` | Baris pertama pesan `head_commit`, paling panjang 500 karakter, sama seperti `commit_message` deployment. `null` bila tidak ada. |
| `branch` | string, selalu ada | Branch push. |
| `root_directory` | string, selalu ada, tidak pernah kosong | `root_directory` project saat push datang, sudah dinormalkan. |
| `changed_path_count` | integer ≥ 1, selalu ada | Jumlah file berbeda yang berubah di push itu. |
| `example_paths` | array string, 1 sampai 5 item | Contoh file yang berubah, urut abjad; semuanya milik project lain. |
| `after_deployment_id` | string (UUID), selalu ada | Deployment terbaru project (urutan `sequence`) saat push datang. Console menaruh catatan ini tepat di atas deployment itu di riwayat. |
| `created_at` | string tanggal-waktu ISO 8601, selalu ada | Kapan push dilewati. |

Console memanggilnya dengan `since` berisi `created_at` deployment tertua di halaman riwayat yang sedang tampil, lalu mengelompokkan hasilnya per `after_deployment_id`.

## Project Preview

`ProjectResource` dan `CreateProjectResource` menyertakan `preview_status` (`pending` | `succeeded` | `failed` | `unavailable`), `inspection` (hanya saat `succeeded`: `repository_url`, `commit_sha`, `dockerfile_found`, `env_example_found`, `compose_found`, `manifests`, `package_manager`, `inspected_at`, `env_example`, `database_hint`; dua yang terakhir dijelaskan di "Inspeksi"), dan `inspection_error_code`. Preview diminta otomatis saat project dibuat dan tidak pernah menggagalkan pembuatan; `unavailable` dengan kode `branch_not_found`, `repository_access_denied`, atau `github_unavailable` berarti tidak ada command inspeksi yang dibuat. Inspeksi ulang tersedia lewat `POST /api/v1/app/projects/{project}/inspection`; lihat "Inspeksi".

## Deployment Representation

`DeploymentResource` menyertakan `agent_node_id` (node target yang dipin control plane), `applied_resources` (resource yang benar-benar diterapkan agent, `null` sampai command selesai), serta `finalization_deferred` dan `finalization_deferred_reason` (`grace_elapsed` atau `runtime_error`) bila agent men-commit route tetapi tidak sempat merapikan workload lama; control plane lalu mengirim `StopProject` untuk workload tersebut. `image_reference` diisi dari metadata event `deployment.runtime.ready`, event yang tidak lagi mengubah `status`.

`DeploymentResource` juga menyertakan `is_active` (boolean): `true` hanya untuk deployment yang sedang melayani project, yaitu `projects.active_deployment_id`. Klaim seperti "perubahan sudah live" harus memakai `is_active` bersama `runtime_status` project, bukan `status = succeeded`, karena deployment yang sudah tergantikan atau dihentikan tetap `succeeded`.

`failure` bernilai `null` selama `failure_code` kosong. Bila terisi:

| Field | Tipe | Keterangan |
| --- | --- | --- |
| `code` | string | Error code asli dari agent atau control plane. |
| `category` | enum | `checkout`, `build`, `release`, `start`, `health`, `route`, `timeout`, `resource`, `node`, `scheduling`, `unknown`. Nilainya stabil; kategori baru bisa ditambahkan, jadi tampilkan nilai yang tidak dikenal sebagai kegagalan umum. |
| `summary` | string | Apa yang terjadi, dalam bahasa Indonesia. |
| `recovery_hint` | string | Apa yang bisa dilakukan pengguna. |
| `user_fixable` | boolean | `true` bila perbaikannya ada di repository atau akses GitHub milik pengguna; `false` bila gangguan ada di sisi Sakala dan cukup deploy ulang. |
| `detail` | string atau `null` | Pesan agent yang sudah diredaksi, paling panjang 500 karakter. `null` bila deployment ditutup control plane atau gagal sebelum field ini ada. |
| `stage` | enum atau `null` | Tahap saat gagal: `queued`, `cloning`, `analyzing`, `building`, `deploying`, `routing`, `health_checking`. `queued` berarti gagal sebelum tahap pertama dilaporkan. `null` hanya untuk deployment yang gagal sebelum field ini ada. |

`detail` dan `stage` disimpan pada `deployments`, jadi tetap terbaca setelah event dan log dipangkas oleh retensi 7 hari. Kategori `release` mencakup `release_command_failed` dan `release_command_timeout` (lihat [Perintah Rilis](#perintah-rilis)). Kategori `start` mencakup `runtime_app_host_blocked`: aplikasi menjawab, tetapi framework-nya menolak domain project (server preview atau dev Vite menjawab 403, halaman debug Django menjawab 400), jadi setiap pengunjung akan ditolak. Kode ini `user_fixable: true`, versi sebelumnya tetap melayani, dan `detail` memuat domain, framework, dan perbaikan dari agent. `recovery_hint` menyebut `preview.allowedHosts` (atau `server.allowedHosts`) dengan `.` diikuti `SAKALA_PROJECT_DEFAULT_DOMAIN`, mengosongkan Start command supaya hasil build disajikan sebagai situs statis (di monorepo dengan Root directory folder package), dan `ALLOWED_HOSTS` untuk Django. Kategori `node` mencakup runtime node yang tidak siap dan deployment yang dibatalkan (`runtime_cancelled`), `timeout` mencakup `command_lease_expired` (node tidak menyelesaikan command sebelum lease habis), dan `scheduling` mencakup `command_expired` (command tidak diambil node sebelum batas ketersediaan).

### Redeploy tanpa build

Deployment yang hanya mengubah konfigurasi runtime tidak perlu mem-build ulang image. `POST /api/v1/app/projects/{project}/deployments` menerima `force_build` (boolean, opsional, default `false`; nilai selain boolean dijawab `422` pada `force_build`, otorisasinya sama dengan deploy biasa sehingga viewer tetap `403`). `true` selalu mem-build. Tanpa itu, deployment baru memakai ulang image deployment aktif project bila **semua** syarat ini terpenuhi (`PlanDeploymentBuildAction`, satu-satunya tempat keputusan ini dibuat, di bawah row lock project):

1. `force_build` tidak `true`;
2. project punya deployment aktif (`active_deployment_id`), apa pun status runtime-nya: melayani, tidur, atau crash, image-nya masih ada di node;
3. `commit_sha` deployment aktif sama dengan commit deployment baru;
4. build fingerprint deployment aktif ada dan sama dengan yang baru (lihat di bawah);
5. deployment baru dipin ke node deployment aktif, dan node itu mengiklankan capability `image-reuse`;
6. deployment yang mem-build image itu diketahui: deployment aktif sendiri, atau sumbernya bila deployment aktif juga memakai ulang image, sehingga rantai pakai ulang selalu menunjuk deployment yang mem-build.

Selain itu deployment di-build seperti biasa. Jadi deploy ulang setelah rotasi password database (`database_password_rotated`), setelah memasang atau melepas database, setelah mengubah variabel runtime server biasa, dan tombol Redeploy selagi head branch masih commit yang live, memakai ulang image. Commit baru, perubahan yang memengaruhi build, atau `force_build: true` mem-build. Pemanggil lain (deploy ulang setelah rotasi, webhook `push`) tidak mengirim `force_build` dan ikut keputusan otomatis ini.

Yang memengaruhi build, dan masuk build fingerprint (`deployments.build_fingerprint`, HMAC-SHA256 dengan `APP_KEY` seperti fingerprint konfigurasi): `root_directory`, `build_command`, `start_command` (Railpack menanamnya di image; string kosong sama dengan tidak diisi), dan variabel build-time project beserta nilainya. Variabel mana yang build-time bergantung pada stack project, lihat [Variabel yang memengaruhi build](#variabel-yang-memengaruhi-build). Menambah, mengubah, atau menghapus variabel build-time membuat deployment berikutnya mem-build. Project yang menanam variabel lain ke image saat build memakai "Build ulang dari awal" (`force_build: true`). `builder` di payload selalu `auto`, dan tidak ada setelan path Dockerfile, jadi keduanya tidak ikut. Deployment dari sebelum image reuse (v0.9.0) tidak punya build fingerprint, sehingga redeploy konfigurasi pertama setelah upgrade ke versi itu mem-build sekali.

#### Variabel yang memengaruhi build

Satu kelas memutuskan ini, `VariableBuildPolicy`, dari data yang sudah disimpan API (tanpa perubahan agent). Ada lima aturan:

1. **Situs statis** (`static_site`): disajikan sebagai file tanpa proses server, jadi semua variabel dibaca saat build. Perubahan variabel project apa pun mem-build.
2. **Framework prerender** (`prerender_framework`): framework yang bisa membuat halaman saat build, misalnya Astro `import.meta.env.X`, Next.js `getStaticProps`, Nuxt generate, atau SvelteKit `$env/static/*`. Perubahan variabel project apa pun mem-build.
3. **Server biasa**: variabel dibaca saat runtime dan image dipakai ulang, kecuali variabel yang key-nya diawali `NEXT_PUBLIC_`, `NUXT_PUBLIC_`, `VITE_`, `REACT_APP_`, `VUE_APP_`, `GATSBY_`, `EXPO_PUBLIC_`, `PUBLIC_` (SvelteKit, Astro), atau `RAILPACK_`, atau tepat `NODE_ENV` (`BuildTimeVariable`, peka huruf besar-kecil). Framework menanam awalan itu ke hasil build, `RAILPACK_` mengatur builder, dan `NODE_ENV` mengubah cara project JavaScript di-build.
4. **Variabel koneksi database** yang disuntikkan Sakala dari database terkelola yang terpasang (`DATABASE_URL`, `DB_*`; termasuk memasang, melepas, dan rotasi password) tidak pernah memengaruhi build, untuk stack apa pun. Build memang tidak bisa menjangkau database: `pg_hba` host database hanya menerima jaringan runtime, bukan jaringan build, jadi variabel itu runtime-only sejak awal. Variabel project yang di-set user sendiri dengan nama yang sama tetap variabel project dan mengikuti aturan 1 sampai 3 atau 5.
5. **Stack tidak dikenal** (`unknown_stack`): tanpa inspeksi yang bisa dipakai, atau stack yang tidak bisa diklasifikasikan, semua variabel dianggap build-time. Kalau ragu, build.

Klasifikasi hanya membaca `projects.inspection`, dan hanya bila inspeksi itu bisa dipakai menurut aturan yang sama dengan `stack` (status `succeeded` dan `root_directory`-nya sama dengan milik project); selain itu `unknown_stack`. Label dibaca dengan sanitasi dan huruf kecil yang sama dengan `stack`. Sinyalnya dari Railpack v0.23.0, versi yang di-pin agent: `railpack.detectedProviders[0]` (provider), serta `railpack.metadata.nodeRuntime`, `nodeSPAFramework`, dan `nodeIsSPA` (string `"true"`/`"false"`; boolean juga diterima). Urutan evaluasinya:

| Urutan | Kondisi | Hasil |
| --- | --- | --- |
| a | `inspection.dockerfile_found` `true` (builder `dockerfile`) | Server biasa (aturan 3). Sakala tidak pernah mengirim variabel sebagai build arg; Dockerfile hanya melihat variabel lewat `RUN --mount=type=secret,id=NAME` yang eksplisit. Awalan build-time tetap berlaku. |
| b | `start_command` project kosong, dan salah satu dari: `nodeIsSPA` true; provider `staticfile`; `nodeRuntime` `static`; `inspection.workspace.static_output_directory` terisi (agent menyajikan paket Vite di workspace JavaScript sebagai file) | `static_site`. `start_command` yang terisi mematikan baris ini, karena Railpack tidak menyajikan SPA lewat Caddy bila start command di-set; evaluasi lanjut ke c. |
| c | Provider `node`, dan `nodeRuntime` atau `nodeSPAFramework` salah satu dari `next`, `nuxt`, `remix`, `react-router`, `tanstack-start`, `astro`, `vite` | `prerender_framework`. `vite` tanpa SPA mencakup SvelteKit, yang dilaporkan Railpack sebagai vite, dan `vite preview`. |
| d | Provider `node` dengan `nodeRuntime` `node`, `bun`, atau tanpa `nodeRuntime`; atau provider `php`, `golang`, `java`, `rust`, `ruby`, `elixir`, `python`, `deno`, `dotnet`, `gleam`, `cpp` | Server biasa (aturan 3). |
| e | Selain itu: provider `shell`, provider tidak dikenal, atau tanpa provider | `unknown_stack`. |

Celah yang diketahui: Railpack melaporkan Gatsby dan Astro mode server sebagai `node` biasa, jadi keduanya masuk aturan 3 sampai agent melaporkan dependency framework (lanjutan setelah agent PR #117). Untuk project seperti itu, "Build ulang dari awal" tetap jalan keluarnya.

Build fingerprint mengikuti keputusan ini. Untuk situs statis, framework prerender, dan stack tidak dikenal, fingerprint meng-hash semua variabel project (key dan nilai plaintext, urut menurut key) dengan penanda scope tersendiri. Untuk server biasa, input fingerprint persis sama dengan v0.9.0, jadi deployment server biasa yang sedang live tetap cocok dan tidak di-build tanpa perlu setelah upgrade. Fingerprint situs statis, framework prerender, dan stack tidak dikenal berubah sekali, sehingga redeploy pertama mereka setelah upgrade ke rilis ini mem-build sekali (sengaja, demi aman). Hal yang sama terjadi bila inspeksi ulang memindahkan project ke kelas lain. Variabel koneksi database tidak pernah masuk fingerprint (aturan 4).

`ProjectResource.variable_changes` selalu ada, supaya Console bisa memberi tahu sebelum user menyimpan perubahan variabel apakah redeploy berikutnya akan mem-build. Daftar project dan response pembuatan project tidak memuatnya.

| Field | Tipe | Keterangan |
| --- | --- | --- |
| `requires_build` | boolean | `true` bila perubahan apa pun pada variabel project membuat deployment berikutnya mem-build (aturan 1, 2, 5). `false` untuk server biasa (aturan 3). |
| `reason` | enum `static_site`, `prerender_framework`, `unknown_stack`, atau `null` | Alasan `requires_build` bernilai `true`; `null` bila `false`. Enum OpenAPI `VariableBuildReason` juga memuat `build_time_prefix`, yang tidak pernah muncul di sini: itu alasan per variabel yang ditampilkan Console untuk server biasa. |
| `build_time_prefixes` | array string | Awalan aturan 3, sama dan seurutan dengan `BuildTimeVariable::PREFIXES`. Dicocokkan peka huruf besar-kecil di awal key. |
| `build_time_keys` | array string | Key aturan 3 yang build-time secara utuh, saat ini `["NODE_ENV"]`. |

Untuk server biasa, Console menentukan sendiri apakah satu key build-time dari dua daftar itu, lalu menampilkan alasan `build_time_prefix`. Variabel koneksi database tidak pernah didaftarkan; Console menganggapnya runtime-only.

```json
"variable_changes": {
  "requires_build": true,
  "reason": "static_site",
  "build_time_prefixes": ["NEXT_PUBLIC_", "NUXT_PUBLIC_", "VITE_", "REACT_APP_", "VUE_APP_", "GATSBY_", "EXPO_PUBLIC_", "PUBLIC_", "RAILPACK_"],
  "build_time_keys": ["NODE_ENV"]
}
```

Deployment yang memakai ulang image tetap menjalankan `release_command` (memasang database baru berarti migrasinya harus berjalan di database itu), health check, semua replika, dan pergantian tanpa downtime seperti biasa, dan tetap tunduk pada batas deployment berjalan dan container berjalan. Agent melewati checkout, analisis, dan build, jadi `status` melompat dari `queued` ke `deploying`, dan timeline memuat event `deployment.image.reused`.

Bila image itu ternyata tidak ada lagi di node (dipangkas, node lain, atau tidak pernah ada), labelnya tidak cocok, atau dibuat agent sebelum label metadata ada, agent mem-build seperti biasa dan melaporkan `deployment.image.reuse_skipped`. Itu bukan kegagalan: `build.mode` menjadi `built`, `build.fallback_reason` terisi, dan sumbernya tetap disebut. Tidak ada retensi image tambahan untuk fitur ini.

Batas build per jam hanya menghitung deployment yang mem-build: deployment yang direncanakan memakai ulang image tidak diperiksa terhadap batas itu dan tidak dihitung di jendelanya. Bila ia jatuh ke build, ia dihitung sejak saat itu, tetapi tidak diperiksa ulang. Pengiriman ulang dengan `Idempotency-Key` yang sama mengembalikan deployment yang sudah ada, apa pun nilai `force_build`-nya.

`DeploymentResource.build` selalu ada:

| Field | Tipe | Keterangan |
| --- | --- | --- |
| `mode` | enum `built` atau `reused` | `reused` untuk pakai ulang yang direncanakan atau sudah dikonfirmasi agent. `built` untuk deployment yang di-build, termasuk setelah fallback dan semua deployment dari sebelum rilis ini. |
| `image_source_deployment_id` | string UUID atau `null` | Deployment yang mem-build image yang diminta dipakai ulang, selalu deployment project yang sama. `null` bila pakai ulang tidak pernah direncanakan, atau bila deployment sumber sudah dihapus (foreign key `ON DELETE SET NULL`; hanya terjadi saat project dihapus permanen). Tetap terisi setelah fallback. |
| `image_source_sequence` | integer atau `null` | `sequence` deployment sumber itu, untuk ditampilkan sebagai `#N`. `null` bersamaan dengan `image_source_deployment_id`. |
| `fallback_reason` | enum atau `null` | Hanya terisi bila pakai ulang direncanakan dan agent mem-build: `image_missing` (image tidak ada di node), `image_not_owned` (label image menyebut project atau deployment lain), `image_metadata_missing` (image dibuat agent sebelum rilis ini), `invalid_reference` (referensi yang dikirim tidak valid), atau `image_unavailable` (alasan lain yang tidak dikenal API ini). |

Daftar deployment memuat sumbernya sekaligus (eager load), jadi `build` tidak menambah query per baris.

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
| `replicas` | integer | Jumlah container yang dijalankan deployment berikutnya di belakang router node, 1 sampai `max_replicas`. Default 1. Lihat [Replika](#replika). |
| `max_replicas` | integer | Replika terbanyak per project di workspace ini, dari `max_replicas` profil batasnya; 1 bila profilnya tidak mengisi atau workspace tanpa profil. |
| `serving_replicas` | integer atau `null` | Replika yang dijalankan deployment aktif: laporan node, atau jumlah yang diminta sampai node melapor. Bisa berbeda dari `replicas` sampai deployment berikutnya. `null` kapan pun `serving_port` `null`. |
| `replicas_ready` | integer atau `null` | Replika deployment aktif yang lolos health check router, dari heartbeat. `null` bila tidak ada yang melayani atau node belum melapor (agent tanpa capability `replicas` tidak pernah melapor). |
| `region` | string atau `null` | Region tempat project dijalankan, salah satu dari `GET /api/v1/app/regions`. `null` mengikuti `default_region` profil batas workspace, atau region mana pun bila profilnya juga kosong. Lihat [Region dan Penempatan](#region-dan-penempatan). |
| `build_command`, `start_command` | string atau `null` | Satu baris, paling panjang 1000 karakter. Dijalankan agent hanya di dalam build atau container. |
| `release_command` | string atau `null` | Perintah yang dijalankan sekali per deployment sebelum versi baru menerima traffic, misalnya migrasi database. Aturannya sama dengan `start_command`. `null` berarti tidak ada. Lihat [Perintah Rilis](#perintah-rilis). |
| `root_directory` | string atau `null` | Path relatif di dalam repository, misalnya `apps/web`. |
| `auto_deploy` | boolean | `true` membuat push ke branch project membuat deployment baru. Default `false`. Hanya untuk project dari GitHub App; project dari URL publik dijawab `422` karena webhook-nya tidak pernah datang. |
| `public_url` | string | Alamat publik project untuk instalasi ini, dari `SAKALA_PROJECT_URL_SCHEME` dan `SAKALA_PROJECT_URL_PORT`. Console memakai ini, bukan menyusun sendiri. Ada juga di `GET /api/v1/app/projects`. |
| `latest_deployment` | object atau `null` | Deployment dengan `sequence` terbesar, apa pun hasilnya, atau `null` bila belum pernah ada. Bisa berbeda dari `active_deployment_id`. Ada juga di `GET /api/v1/app/projects`, jadi daftar project tidak perlu request per kartu. Lihat tabel di bawah. |
| `suspension` | object atau `null` | Terisi selama `status` bernilai `suspended`, dan `null` di luar itu, termasuk setelah penangguhan dibuka. Ada juga di `GET /api/v1/app/projects`. Lihat "Pemberitahuan dan Membuka Penangguhan". |
| `redeploy_required` | boolean | `true` bila deployment aktif dibuat dengan konfigurasi yang relevan untuk deploy yang berbeda dari sekarang: `branch`, port (`effective_port`), konfigurasi build (`build_command`, `start_command`, `release_command`, `root_directory`), `replicas`, environment variable (key dan nilai), atau database terpasang (pasang, lepas, dan password yang dirotasi). Mengembalikan nilainya seperti semula membuatnya `false` lagi. `name`, `thumbnail_url`, `auto_deploy`, `region`, dan `is_secret` tidak pernah membuatnya `true`. Selalu `false` bila tidak ada deployment aktif. |
| `stack` | object atau `null` | Lihat "Inspeksi". |
| `variable_changes` | object | Apakah perubahan variabel project membuat redeploy berikutnya mem-build, dan kenapa. Selalu ada. Lihat [Variabel yang memengaruhi build](#variabel-yang-memengaruhi-build). |
| `databases` | array | Database terkelola yang terpasang, paling banyak satu: `id`, `name`, `status`, `region`, `variables` (nama variabel yang diterima deployment berikutnya dari database itu, tanpa nilai), dan `overridden_variables` (nama yang di-set proyek sendiri, sehingga nilai proyek yang dipakai; Console sebaiknya memperingatkannya). `[]` tanpa database. Daftar project (`GET /api/v1/app/projects`) memuat versi ringan dengan `id`, `name`, dan `status` saja, supaya dialog pemasangan bisa menandai proyek yang sudah memakai database. Lihat [Database](#database). |
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

Mengganti port tidak mengubah workload yang sedang berjalan: `PORT` dan route baru berlaku pada deployment berikutnya, dan `redeploy_required` menandainya sampai saat itu. Ini termasuk port baru yang ditemukan inspeksi selama `configured_port` kosong, karena `effective_port` ikut berubah. Aplikasi harus membaca `PORT`. Bila project sendiri punya environment variable `PORT` dengan nilai lain, deployment gagal dengan `runtime_port_conflict`.

Field konfigurasi diterima pada `POST` dan `PATCH /api/v1/app/projects`. Pada `PATCH`, field yang tidak dikirim tidak berubah dan `null` mengosongkannya. `thumbnail_url` belum punya generator, jadi console memakai gambar cadangannya bila kosong.

### Replika

Project bisa berjalan sebagai beberapa container (replika) di node yang sama. Router node membagi request ke replika yang lolos health check, jadi satu replika yang mati tidak memutus aplikasi. Replika hanya cocok untuk aplikasi stateless: file yang ditulis ke disk container dan sesi yang disimpan di memori tidak terbagi antar-replika, jadi simpan keduanya di layanan luar seperti database atau object storage.

`replicas` diubah lewat `PATCH /api/v1/app/projects/{project}` dengan hak yang sama seperti deploy: owner dan member workspace, viewer `403`. Nilainya bilangan bulat 1 sampai `max_replicas`; di luar itu dijawab `422` pada `replicas`. Perubahan tidak menyentuh deployment yang sedang berjalan: replika baru berlaku pada deployment berikutnya, dan `redeploy_required` menandainya sampai saat itu. Wake dan restart menyalakan semua replika deployment yang disimpan node, berapa pun nilai `replicas` sekarang. Bila admin menurunkan `max_replicas` profil, nilai project tidak diubah, tetapi deployment berikutnya menjalankan paling banyak `max_replicas`.

Deployment dengan lebih dari 1 replika hanya dikirim ke node yang mengiklankan capability `replicas` dan batas replika per project-nya cukup (agent lama mengabaikan field itu dan menjalankan satu container). Bila tidak ada node yang bisa menjalankannya, yaitu node tempat project berjalan, atau untuk project baru semua node aktif, deploy langsung ditolak `409 PROJECT_REPLICAS_UNSUPPORTED` dengan `replicas` dan `node_maximum`; turunkan `replicas` sampai `node_maximum` (atau 1 bila `null`) atau tunggu node diperbarui. Bila node yang sanggup hanya sedang offline, deployment menunggu di antrean (`queue_state` `waiting_for_node`). Agent yang menolak karena batasnya lebih kecil menggagalkan deployment dengan `failure.code` `runtime_replicas_exceeded`. `max_replicas` yang ditampilkan tetap dari profil batas, tidak dari batas node.

Setiap replika dihitung sebagai satu container berjalan untuk batas workspace (lihat [Batas Workspace](#batas-workspace)). Deploy project yang belum melayani atau sedang tidur butuh slot untuk semua replikanya; redeploy project yang melayani hanya butuh slot untuk replika tambahan, jadi naik dari 1 ke 3 replika butuh 2 slot kosong. Deploy bergulir sebentar menjalankan replika lama dan baru bersamaan di node; batas workspace menghitung jumlah setelah deploy selesai, sedangkan kapasitas node diperiksa agent sendiri.

### Perintah Rilis

`release_command` dijalankan agent sekali per deployment, sebelum versi baru menerima traffic. Cocok untuk migrasi database, misalnya `php artisan migrate --force`. Perintahnya harus selesai sendiri: jangan menjalankan server atau menunggu input. Field ini diterima pada `POST` dan `PATCH /api/v1/app/projects` dengan hak yang sama seperti field konfigurasi lain, viewer `403`. Nilainya satu baris tanpa karakter kontrol, paling panjang 1000 karakter; di luar itu `422` pada `release_command`. `null` atau string kosong mengosongkannya. Perubahan berlaku pada deployment berikutnya, dan `redeploy_required` menandainya sampai saat itu. Wake, restart, dan reconcile tidak pernah menjalankannya.

Deployment dengan `release_command` hanya dikirim ke node yang mengiklankan capability `release-command`, karena agent lama akan mengabaikan field itu dan menjalankan versi baru tanpa migrasinya. Bila tidak ada node yang bisa menjalankannya, yaitu node tempat project berjalan, atau untuk project baru semua node aktif, deploy langsung ditolak `409 PROJECT_RELEASE_COMMAND_UNSUPPORTED`; kosongkan `release_command` untuk deploy sekarang, atau tunggu operator memperbarui agent. Bila node yang sanggup hanya sedang offline, deployment menunggu di antrean (`queue_state` `waiting_for_node`). Project yang juga meminta lebih dari 1 replika butuh satu node yang sanggup keduanya; bila tidak ada node yang sanggup menjalankan replikanya, jawabannya `PROJECT_REPLICAS_UNSUPPORTED`.

Perintah rilis yang keluar dengan kode selain 0 menggagalkan deployment dengan `failure.code` `release_command_failed`, dan yang melewati batas waktu dengan `release_command_timeout`. Keduanya berkategori `release` dan `user_fixable: true`; `failure.detail` memuat pesan agent yang sudah diredaksi, termasuk kode keluar perintahnya. Versi baru tidak menerima traffic, dan versi sebelumnya, kalau ada, tetap melayani. Log perintah rilis ada di log deployment.

### Region dan Penempatan

Node melaporkan region-nya lewat heartbeat (`SAKALA_AGENT_REGION`), dan admin bisa menimpanya per node dengan `php artisan agent:node-region`. `GET /api/v1/app/regions` (sesi Console) mengembalikan `{"data": [{"slug": "jkt"}, ...]}`: region berbeda dari node yang diizinkan menerima pekerjaan (`auth_status` `active`, `desired_state` `active`), online atau tidak, urut abjad. Daftar kosong berarti belum ada node yang punya region, dan project tetap `region: null`.

Project memilih region lewat `PATCH /api/v1/app/projects/{project}` field `region` (hak yang sama seperti mengubah project; viewer `403`). Nilainya harus salah satu region yang tersedia saat itu, atau `null`; selain itu `422` pada `region`. Format region: huruf kecil dan angka dalam kelompok yang dipisah satu tanda hubung, paling panjang 32 karakter. Mengganti region tidak membuat `redeploy_required` dan tidak memindahkan workload yang sudah ada. Region bawaan workspace diatur admin lewat `default_region` profil batas, dengan format yang sama; nilai project yang terisi selalu menang.

Node untuk command sebuah project dipilih (`AgentNodeSchedulerService::placementFor()`) dengan urutan:

1. Hanya node yang eligible (aktif, online, revisi protocol didukung, punya capability yang dibutuhkan) dan sanggup menjalankan semua replika (lihat [Replika](#replika)).
2. Project yang workload-nya sudah ada di sebuah node tetap di node itu, apa pun region dan kapasitasnya, atau menunggunya. Region baru berlaku setelah workload-nya dihapus (stop), karena migrasi lintas node belum ada.
3. Selain itu hanya node yang region efektifnya sama dengan region project, bila project punya region. Node tanpa region hanya menerima project tanpa region.
4. Dari kandidat itu, node dengan kapasitas paling longgar: lebih dulu node yang slot container kosongnya (`maximum_active_workloads` dikurangi `active` dari heartbeat) cukup untuk semua replika atau tidak melaporkan batas; lalu memory tersedia terbesar; lalu slot kosong terbanyak; lalu reservasi command paling sedikit dan heartbeat paling baru. Deployment yang sudah dipin ke node tetapi belum selesai dihitung memakai satu slot dan memory container default (`SAKALA_DEFAULT_CONTAINER_MEMORY_MB`), karena heartbeat belum memperlihatkannya.

Semua replika satu project masih ditempatkan di satu node. Menyebar replika ke beberapa node menunggu Sakala Edge membagi trafik antar-node (ADR-019 tahap 4); tabel route Edge dan `ReplicaPlacementData` sudah bisa memuat beberapa node. Bila region project tidak punya node yang diizinkan menerima pekerjaan, deploy ditolak `409 PROJECT_REGION_UNAVAILABLE`.

### Tidur Otomatis

Project yang `running` tanpa request selama jendela sepi workspace-nya ditidurkan oleh `projects:sleep-idle`, yang berjalan setiap menit di satu server. Jendela itu datang dari `idle_sleep_minutes` profil batas workspace; profil dengan `null` dan workspace tanpa profil memakai `SAKALA_PROJECT_IDLE_SLEEP_MINUTES` (default `0`). Nilai `0` berarti tidak pernah tidur, jadi tanpa konfigurasi tidak ada project yang ditidurkan. Nilai efektifnya ada di `project_idle_sleep_minutes` pada `GET /api/v1/app/pilot-limits?workspace_id=`, dan `0` di sana berarti project workspace itu tidak pernah tidur.

Sebuah project ditidurkan bila semua ini benar:

* `runtime_status` bernilai `running`, project tidak `suspended` dan tidak dihapus;
* node deployment aktifnya punya capability `idle-sleep`, sedang tidak `offline`, dan heartbeat terakhirnya membawa daftar `traffic` (lihat [Agent API](AGENT_API.md#heartbeat)). Tanpa daftar itu tidak ada yang tahu project sepi atau tidak, jadi tidak ada yang ditidurkan;
* `last_request_at`, waktu deployment aktif selesai, dan wake atau restart terakhir yang sukses semuanya lebih lama dari jendela sepi. Project yang belum pernah menerima request diukur dari waktu deployment selesai;
* tidak ada deployment yang sedang berjalan, dan tidak ada `SleepProject`, `WakeProject`, `StopProject`, atau `RestartProject` yang masih `Pending`, `Claimed`, atau `Running`.

API lalu membuat `SleepProject` ke node itu dengan `request_context` `{ "reason": "idle", "actor_type": "system", "actor_id": "scheduler:sleep-idle" }` dan mencatat `project.idle_sleep_requested`. Begitu node melaporkan selesai, `runtime_status` menjadi `sleeping`, `active_deployment_id` tetap terisi, `project.idle_sleep_completed` dicatat, dan `deployment.updated` dikirim dengan `project_runtime_status`. Bila gagal, project tetap `running` dan `project.idle_sleep_failed` dicatat sekali per command dengan `error_code`-nya.

Sleep yang gagal tidak langsung diminta lagi. Kegagalan beruntun dihitung per deployment aktif, sejak lifecycle command terakhir yang sukses untuk deployment itu (sleep, wake, restart, atau stop), jadi deployment baru mulai dari nol. Setelah kegagalan pertama sapuan menunggu satu jendela sepi, dan setiap kegagalan berikutnya menggandakan jedanya sampai paling lama 24 jam. Dengan jendela 30 menit, jedanya 30, 60, 120 menit, dan seterusnya. Bila node menjawab `runtime_workload_not_found`, artinya container deployment itu tidak ada di node, sapuan berhenti meminta sleep untuk deployment itu sampai ada deployment baru yang sukses atau wake atau restart-nya sukses. Admin melihat project seperti ini lewat `idle_sleep_blocked` di daftar dan detail project admin. Deployment yang dimulai sebelum sleep selesai tetap memegang status; bila deployment itu gagal atau dibatalkan, project kembali `sleeping`.

Domain project yang tidur menampilkan halaman 503 dari node yang memanggil endpoint bangun publik lalu memuat ulang tiap 3 detik. Domain project yang ditangguhkan menampilkan halaman statis bahwa project tidak tersedia, yang tidak meminta bangun: API meminta `page: unavailable` pada sleep karena suspend ke node ber-capability `idle-sleep` (lihat [SleepProject](AGENT_API.md#sleepproject)), dan agent v0.7.1 ke atas melayaninya. Agent v0.7.0 belum mengenal field itu dan tetap menampilkan halaman bangun. Project yang tidur tidak dihitung sebagai container berjalan untuk kuota, sehingga membangunkannya, atau men-deploy ulang, memerlukan slot lagi.

`POST /api/v1/public/projects/{slug}/wake` dipanggil tanpa login, tanpa sesi, tanpa cookie, dan tanpa token CSRF, apa pun origin-nya, sebagai POST lintas origin sederhana (`mode: 'no-cors'`) yang tidak membaca jawabannya. Body tidak diperlukan. Jawabannya selalu `202` dengan body kosong, juga untuk slug yang tidak ada, project yang tidak tidur, project yang ditangguhkan atau dihapus, dan project yang tidak punya deployment di node, supaya jawabannya tidak membuka apa pun tentang project, termasuk alasan suspend. Pengecualiannya `409 PROJECT_WAKE_LIMIT_EXCEEDED` tanpa angka kuota dan `429` dari `SAKALA_PROJECT_WAKE_RATE_LIMIT` per IP dan slug. Domain project tidak boleh dimasukkan ke `SANCTUM_STATEFUL_DOMAINS`.

`POST /api/v1/app/projects/{project}/wake` dipakai tombol Bangunkan di Console, dengan hak yang sama seperti deploy: owner dan member workspace, viewer `403`, admin boleh. Jawabannya `202` dengan `ProjectResource`; `runtime_status` tetap `sleeping` sampai node melapor. Project yang ditangguhkan dijawab `409 PROJECT_SUSPENDED` (anggota dan admin), project yang tidak tidur `409 PROJECT_NOT_SLEEPING`, dan kuota penuh `409 PROJECT_WAKE_LIMIT_EXCEEDED` dengan `limit` dan `current`.

Kedua endpoint membuat `WakeProject` untuk node dan deployment yang menyimpan container-nya, dan mencatat `project.wake_requested` (`source` `public` dengan actor `system`/`public-wake` tanpa IP atau user agent, atau `console` dengan user-nya). Wake yang masih `Pending`, `Claimed`, atau `Running` dipakai ulang, jadi halaman yang memuat ulang berkali-kali hanya menghasilkan satu command. Wake sukses mengubah `runtime_status` menjadi `running`, mencatat `project.wake_completed`, dan mengirim `deployment.updated`. Wake gagal, termasuk `runtime_capacity_exceeded` saat node penuh, membiarkan project `sleeping` dan mencatat `project.wake_failed` dengan `error_code`. Wake atau restart yang sukses dihitung sebagai aktivitas, jadi project yang baru dibangunkan tidak langsung ditidurkan lagi.

Sleep karena sepi berbeda dari suspend. Sleep yang masih berjalan tidak menahan suspend atau unsuspend admin. Men-suspend project yang sedang tidur membuat `SleepProject` dengan alasan suspend seperti biasa; setelah selesai `runtime_status` menjadi `stopped` dan `active_deployment_id` kosong, sama seperti suspend lain, dan bangun ditolak sejak suspend diterima.

### Metrik

`GET /api/v1/app/projects/{project}/metrics?range=1h` mengembalikan pemakaian CPU, memory, replika, dan restart sebuah project, dengan hak yang sama seperti melihat project: owner, member, dan viewer workspace, serta admin. Selain itu `403`. Query:

| Query | Nilai | Keterangan |
| --- | --- | --- |
| `range` | `1h`, `6h`, `24h`, `7d`, `30d` | Opsional, default `1h`. Nilai lain `422` pada `range`. |
| `per_replica` | `1` atau `0` | Opsional. `1` menambahkan `series.per_replica`. Nilai lain `422`. |

Datanya datang dari heartbeat node ber-capability `workload-metrics` (lihat [Agent API](AGENT_API.md#heartbeat)). Node tanpa capability itu tidak pernah mengirim sampel, jadi grafiknya kosong; response tidak membedakan "node belum mendukung" dari "belum ada data".

| Range | `step_seconds` | Sumber | Jumlah titik paling banyak |
| --- | --- | --- | --- |
| `1h` | 60 | baris per menit | 60 |
| `6h` | 300 | baris per menit | 72 |
| `24h` | 900 | baris per menit | 96 |
| `7d` | 3600 | rollup per jam | 168 |
| `30d` | 14400 | rollup per jam | 180 |

Titik terakhir adalah step yang sedang berjalan, jadi nilainya masih bisa berubah. Step disejajarkan ke kelipatan `step_seconds` sejak Unix epoch dalam UTC, misalnya step 4 jam dimulai pukul 00.00, 04.00, dan seterusnya UTC (07.00, 11.00 WIB). Rollup per jam diperbarui `projects:roll-up-metrics` setiap 5 menit, jadi `7d` dan `30d` bisa tertinggal sampai 5 menit dari `1h`.

Isi `data`:

| Field | Tipe | Keterangan |
| --- | --- | --- |
| `range` | string | Range yang diminta. |
| `step_seconds` | integer | Lebar satu titik dalam detik. |
| `series.cpu_percent` | array `{t, avg, max}` | Persen dari satu core, dijumlah dari semua replika, jadi bisa lebih dari 100. `avg` dan `max` berupa angka dengan paling banyak 2 desimal. |
| `series.memory_bytes` | array `{t, avg, max}` | Memory terpakai dalam byte, dijumlah dari semua replika. `avg` dan `max` berupa integer. |
| `series.memory_limit_bytes` | integer atau `null` | Jumlah batas memory replika pada data terbaru di range itu. `null` bila belum ada data atau ada replika tanpa batas. Satu angka, bukan deret. |
| `series.replicas` | array `{t, count}` | Replika yang melapor pada step itu: terbanyak dalam satu menit (satu jam untuk `7d` dan `30d`). |
| `series.restarts` | array `{t, count}` | Restart container pada step itu, dijumlah dari semua replika. |
| `series.per_replica` | array, hanya bila `per_replica=1` | Satu item per indeks replika, urut dari yang terkecil: `{replica, cpu_percent, memory_bytes, restarts}` dengan bentuk deret yang sama. |

`t` adalah awal step dalam ISO 8601 UTC. Semua deret memakai `t` yang sama dan berurutan dari yang terlama. Step tanpa data tidak punya titik sama sekali, bukan nol, jadi dua `t` berurutan yang jaraknya lebih dari `step_seconds` berarti ada celah dan grafik sebaiknya memutus garisnya di situ. Tanpa data, semua deret berupa array kosong.

Cara menghitungnya: setiap baris menyimpan satu replika satu deployment selama satu menit (atau satu jam). Untuk setiap menit (atau jam), rata-rata dan maksimum semua replika dijumlahkan. Maksimum gabungan itu batas atas, karena replika jarang memuncak di menit yang sama. Untuk step yang lebih panjang dari satu menit (atau satu jam), `avg` adalah rata-rata dari total per menit (atau per jam) yang ada datanya, dan `max` adalah total terbesar. Restart dihitung per replika dari counter kumulatifnya: selisih antara dua pembacaan berurutan, dan bila counter turun (container dibuat ulang) pembacaan barunya dihitung dari nol. Pembacaan pertama sebuah replika tidak menambah apa pun; untuk range dari baris per menit, API membaca 10 menit sebelum range sebagai pembanding supaya restart di menit pertama ikut terhitung.

Hanya deployment aktif yang dicatat (aturan yang sama dengan trafik), jadi selama deploy bergulir hanya versi yang sedang melayani yang terlihat. Replika deployment baru memakai indeks yang sama dengan replika yang ia gantikan di `per_replica`.

Retensi: baris per menit disimpan `SAKALA_METRICS_MINUTE_RETENTION_HOURS` (default 24, paling kecil 2) dan rollup per jam `SAKALA_METRICS_HOURLY_RETENTION_DAYS` (default 30). Nilai yang lebih kecil dari panjang range membuat awal range kosong. Lihat [Database](DATABASE.md#metrik-project) untuk tabel dan perkiraan volumenya.

### Inspeksi

`POST /api/v1/app/projects/{project}/inspection` (owner atau member workspace; dibatasi `SAKALA_INSPECTION_RATE_LIMIT` per menit) menjawab `202` dengan `ProjectResource`. Hasilnya menyusul lewat `preview_status`. Inspeksi yang sedang berjalan atau sudah selesai untuk commit yang sama dipakai ulang; yang gagal, kedaluwarsa, atau dibatalkan dicoba lagi. Project yang ditangguhkan dijawab `409 PROJECT_SUSPENDED`.

`stack` ada pada `ProjectResource`, `CreateProjectResource`, dan daftar project, bernilai `null` sampai inspeksi berhasil:

| Field | Nilai | Keterangan |
| --- | --- | --- |
| `builder` | `dockerfile` atau `railpack` | Pilihan mode auto untuk direktori yang diinspeksi. |
| `provider` | string atau `null` | Provider pertama yang dideteksi railpack, misalnya `node`. |
| `framework` | string atau `null` | Dari `nodeSPAFramework` railpack, lalu `nodeRuntime` kecuali `node`, `bun`, dan `deno`. Selain itu `null`. |
| `package_manager` | string atau `null` | Dari lockfile yang ditemukan agent, lalu `metadata.nodePackageManager` railpack. |
| `monorepo` | object atau `null` | Ada bila root directory project adalah package workspace JavaScript, jadi build berjalan dari root workspace. `null` tanpa workspace dan untuk inspeksi oleh agent sebelum sakala-agent #113. Isinya di bawah. |

`monorepo` adalah objek `workspace` dari agent, dengan field yang selalu ada:

| Field | Nilai | Keterangan |
| --- | --- | --- |
| `manager` | `npm`, `pnpm`, `yarn`, atau `bun` | Package manager workspace. |
| `root_directory` | string | Root workspace relatif terhadap root repository, `.` untuk root repository. Ini konteks build. |
| `package_directory` | string | Root directory project, relatif terhadap root workspace. |
| `package_name` | string atau `null` | `name` di `package.json` package. |
| `build_command` | string atau `null` | Perintah build yang berjalan di root workspace, diturunkan dari script `build` package. `null` tanpa script `build`. |
| `start_command` | string atau `null` | Perintah start image. `null` bila hasil build disajikan sebagai situs statis, atau tidak ada script `start`. |
| `static_output_directory` | string atau `null` | Hasil build SPA Vite relatif terhadap root workspace, yang disajikan sebagai situs statis. |

Perintah di inspeksi adalah turunan dari script package, karena inspeksi tidak membawa `build_command` dan `start_command` project. Perintah yang benar-benar dipakai sebuah deployment ada di metadata event `deployment.workspace.detected` pada timeline deployment, dengan bentuk yang sama. Event itu tidak mengubah status (tetap `analyzing`).

#### Contoh environment dan petunjuk database

`inspection.env_example` dan `inspection.database_hint` selalu ada saat `preview_status` `succeeded`, supaya Console bisa menyarankan variabel yang belum diisi dan menawarkan database saat proyek disiapkan. Keduanya `null` untuk inspeksi oleh agent sebelum field ini (lihat [Agent API](AGENT_API.md#inspectproject)).

`env_example` berisi kunci dari file contoh environment di direktori yang diinspeksi, atau `null` bila tidak ada file seperti itu atau file-nya tidak bisa dibaca dengan aman. `.env` tidak pernah dibaca.

| Field | Tipe | Keterangan |
| --- | --- | --- |
| `file` | string | Salah satu dari `.env.example`, `.env.sample`, `.env.dist`: yang pertama ditemukan. |
| `entries` | array | Paling banyak 200, urut seperti di file. Setiap item `{ key, example_value }`. |
| `entries[].key` | string | Nama variabel yang lolos aturan variabel proyek (`^[A-Z_][A-Z0-9_]*$`, paling panjang 128). Kunci lain dibuang, dan kunci yang berulang hanya diambil yang pertama. |
| `entries[].example_value` | string atau `null` | Nilai contoh, paling panjang 200 karakter. `null` bila kosong, multi-baris, atau terlihat seperti kredensial (agent membuangnya). Ini contoh dari repository, bukan rahasia, tetapi Console sebaiknya tidak mengisinya otomatis sebagai nilai. |
| `truncated` | boolean | `true` bila ada lebih banyak kunci dari yang dikirim. |

`database_hint` menjawab apakah proyek tampaknya butuh database:

| Field | Tipe | Keterangan |
| --- | --- | --- |
| `likely` | boolean | `true` bila `signals` tidak kosong. Dihitung ulang oleh API. |
| `engine` | string | `postgresql`, `mysql`, `sqlite`, atau `unknown`. Hanya petunjuk: database terkelola selalu PostgreSQL. |
| `signals` | array | Paling banyak 16 item `{ kind, file, name }`: `kind` string, `file` path relatif terhadap direktori yang diinspeksi, `name` string atau `null`. |
| `suggested_release_command` | string atau `null` | Hanya salah satu dari `php artisan migrate --force`, `python manage.py migrate`, `npx prisma migrate deploy`, `bin/rails db:migrate`, untuk dipakai sebagai `release_command` lewat `PATCH /projects/{project}`. |

| `kind` | `name` |
| --- | --- |
| `env_variable` | Kunci seperti `DATABASE_URL`, `DB_HOST`, atau `PGHOST` di `env_example`. |
| `laravel_migrations` | `null`; `file` adalah `database/migrations`. |
| `prisma_schema` | Provider `datasource`, misalnya `postgresql`. |
| `django_settings` | `DATABASES` atau `dj_database_url`. |
| `rails_database_config` | `adapter` pertama di `config/database.yml`, atau `null`. |
| `package_dependency` | Nama package, misalnya `pg` atau `@prisma/client`. |

Nilai `kind` stabil, tetapi agent boleh menambah jenis baru; API membuang jenis yang belum dikenalnya, dan Console mengabaikan jenis yang tidak dikenal. API tidak memercayai hasil agent: kunci, jumlah, panjang, `engine`, dan `suggested_release_command` diperiksa ulang saat disimpan dan saat dibaca, jadi nilai di luar aturan di atas tidak pernah sampai ke Console.

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

Semua anggota workspace membaca daftar variabel; menambah, mengubah, menghapus, dan membaca nilai secret lewat `GET .../value` hanya untuk owner dan member, jadi viewer tidak pernah melihat nilai secret. `POST` menerima `is_secret` (default `true`). `PATCH .../environment-variables/{id}` menerima `value` dan `is_secret`, keduanya opsional. `value` ada di response hanya untuk variabel non-secret; variabel secret tidak punya key itu dan tetap dibaca lewat `GET .../value`. `POST` dan `PATCH` menyertakan `redeploy_required`, karena deployment berjalan memakai nilai saat ia dibuat. Mengubah `is_secret` saja tidak mengubahnya, sedangkan menghapus variabel lalu menambahkannya lagi dengan nilai yang sama membuatnya kembali `false`.

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

`POST /api/v1/admin/projects/{project}/unsuspend` (admin, body `reason`) menjawab `202 ProjectControlResource`. Status menjadi `active` bila project pernah punya deployment `succeeded`, selain itu `draft`. Ditolak `409` bila project tidak `suspended` atau `SleepProject` masih berjalan, termasuk saat menunggu node yang offline. Idempotency sama dengan operasi control lain, audit event-nya `project.unsuspended`.

Sleep karena suspend menyimpan container di node. Bila project `stopped` dan container itu masih ada (deployment `succeeded` terakhir yang terakhir kali di-sleep, bukan di-stop) di node yang punya capability `idle-sleep`, unsuspend membuat satu `WakeProject` untuk node dan deployment itu dengan `request_context` `{ "reason": "unsuspended", "source": "unsuspend" }` atas nama admin, dan mencatat `project.wake_requested` dengan `source` `unsuspend`. Wake yang masih antre dari sebelum suspend dipakai ulang, bukan digandakan. `command` di response adalah wake itu, dan `runtime_status` tetap `stopped` sampai node melapor. Wake yang sukses membuat project `running` dan mengisi lagi `active_deployment_id` dengan deployment itu. Kuota container workspace tetap berlaku: bila sudah penuh, tidak ada wake, project tetap `stopped`, dan pemilik diberi tahu untuk men-deploy ulang setelah ada slot. Tanpa container yang tersimpan, API tidak menjalankan apa pun dan pemilik diberi tahu untuk men-deploy ulang.

`wake` di response (juga di metadata audit `project.unsuspended`, bersama `wake_command_id`) menjelaskan hasilnya. Untuk aksi control lain nilainya `null`.

| Nilai | Arti |
| --- | --- |
| `requested` | `WakeProject` dibuat; `command` berisi command itu. |
| `container_limit_reached` | Kuota container workspace penuh; project tetap `stopped`. |
| `node_cannot_wake` | Node yang menyimpan container tidak punya capability `idle-sleep`; project tetap `stopped`. |
| `nothing_to_wake` | Tidak ada container yang tersimpan (belum pernah deploy, atau sudah di-stop), atau project tidak `stopped` karena sleep suspend-nya gagal. |

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
| `DELETE /api/v1/app/workspaces/{workspace}` | Owner tim | `204`, hanya bila tidak ada project dan database (database yang sedang dihapus tidak dihitung); selain itu `409 WORKSPACE_NOT_EMPTY`. Project yang sudah dihapus menunggu purge tanpa workspace, dan environment variable-nya langsung dihapus. |
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

`WorkspaceResource` berisi `id`, `name`, `slug`, `type`, `role` (peran pemanggil), `limit_profile` (`id`, `name`, atau `null` untuk default tipenya), `default_region` (region yang dipakai proyek dan database workspace ini bila tidak memilih sendiri, dari profil batas; `null` berarti region mana pun, termasuk untuk workspace tanpa profil), `projects_count`, `active_deployments_count`, dan `created_at`. Semua anggota, termasuk viewer, menerimanya.

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
| Build per jam | `SAKALA_PROJECT_MAX_BUILDS_PER_HOUR` (20) | sama dengan pribadi |
| Tidur setelah sepi | `SAKALA_PROJECT_IDLE_SLEEP_MINUTES` (0, mati) | sama dengan pribadi |
| Replika per project | 1 | 1 |
| Database terkelola | `SAKALA_DATABASE_DEFAULT_MAX_COUNT` (2) | sama dengan pribadi |
| Total ukuran database | `SAKALA_DATABASE_DEFAULT_MAX_STORAGE_MB` (512 MB) | sama dengan pribadi |

Dengan default ini tidak ada yang berubah bagi user yang sudah ada, kecuali build per jam yang sekarang ditegakkan (lihat di bawah). Default tim sengaja kecil dan tidak mengikuti nilai pribadi, supaya membuat tim tidak menggandakan kuota; tim yang butuh lebih mendapat profil batas dari admin. Nilai env yang kosong memakai default di tabel. Container berjalan adalah jumlah replika project yang melayani (`active_deployment_id` terisi dan tidak `sleeping`) atau sedang di-deploy; project yang sedang di-deploy dihitung dengan replika terbanyak antara deployment aktif dan deployment yang berjalan. Batasnya berlaku saat men-deploy project yang belum melayani atau sedang tidur, saat redeploy dengan replika lebih banyak, dan saat membangunkan project (lihat [Replika](#replika)). `max_replicas` profil (1 sampai 10, atau `null`) mengatur replika terbanyak per project; `null` dan workspace tanpa profil berarti 1, jadi replika mati sampai admin mengizinkannya. `idle_sleep_minutes` profil (0 sampai 10080, atau `null`) mengatur jendela sepi: `null` mengikuti `SAKALA_PROJECT_IDLE_SLEEP_MINUTES`, `0` berarti project workspace itu tidak pernah ditidurkan, dan nilai lain menidurkannya setelah sekian menit, juga saat nilai global `0`. Default memory dan CPU tidak pernah melebihi batas profil. Batas deployment per project (`SAKALA_MAX_ACTIVE_DEPLOYMENTS_PER_PROJECT`) tetap global.

Build per jam membatasi berapa deployment yang mem-build image yang boleh dibuat workspace dalam 60 menit terakhir (jendela bergulir, bukan per jam kalender). Setiap deployment yang mem-build dihitung: deploy manual, redeploy, dan webhook `push`, apa pun statusnya, termasuk yang gagal atau dibatalkan. Deployment yang memakai ulang image deployment aktif tidak diperiksa dan tidak dihitung; yang jatuh ke build dihitung sejak saat itu tanpa diperiksa ulang (lihat [Redeploy tanpa build](#redeploy-tanpa-build)). Deployment milik project yang sudah dihapus tetap dihitung, jadi menghapus lalu membuat ulang project tidak mengosongkan jendela. Membangunkan atau me-restart project bukan deployment, jadi tidak dihitung. `max_builds_per_hour` profil (1 sampai 1000, atau `null`) mengatur batasnya; `null` dan workspace tanpa profil, pribadi maupun tim, mengikuti `SAKALA_PROJECT_MAX_BUILDS_PER_HOUR` (default 20, `0` berarti tanpa batas). Deploy yang melewatinya ditolak `422 BUILD_RATE_LIMIT_EXCEEDED` dengan `retry_after_seconds`: slot kosong lagi saat build tertua yang masih menahan batas keluar dari jendela. Bila admin menurunkan batas di bawah jumlah build yang sudah berjalan, yang ditunggu adalah build ke-(`current` − `limit` + 1) dari yang tertua. Pemeriksaannya berjalan di bawah row lock workspace, sama seperti container berjalan, jadi dua deploy bersamaan tidak bisa sama-sama memakai slot terakhir. Pengiriman ulang dengan `Idempotency-Key` yang sama tetap mengembalikan deployment yang sudah ada tanpa diperiksa lagi. Webhook `push` yang kena batas ini dilewati dan dilaporkan, tidak dicoba ulang (lihat [GitHub](#github)).

Membuat project mengunci baris user lalu baris workspace, dan membuat deployment mengunci baris workspace lalu baris project, sehingga dua request di workspace yang sama dihitung bergantian. Admin yang bertindak tidak dibatasi, sama seperti sebelumnya, termasuk oleh batas build per jam; deploy admin tetap ikut dihitung untuk deploy anggota berikutnya.

`POST /api/v1/app/projects` menerima `workspace_id` opsional; tanpa itu project masuk ke workspace pribadi pemanggil, jadi Console lama tetap bekerja. Workspace yang tidak ada atau bukan UUID dijawab `422`; workspace yang tidak boleh diisi pemanggil (viewer atau bukan anggota) dijawab `403`. `GET /api/v1/app/pilot-limits?workspace_id=` menjawab untuk workspace itu, atau workspace pribadi bila kosong. Field lamanya tetap, dengan `_per_user` berarti per workspace, ditambah `workspace_id`, `limit_profile`, `quotas.max_active_containers`, `quotas.current_active_containers_count` (jumlah replika, tanpa project yang `sleeping`), `quotas.max_replicas`, `quotas.max_builds_per_hour` (batas build per jam efektif, `null` berarti tanpa batas), `quotas.max_databases`, `quotas.current_databases_count`, `quotas.max_database_storage_mb`, `quotas.current_database_storage_bytes` (lihat [Database](#database)), `project_idle_sleep_minutes` (jendela sepi efektif, `0` berarti tidak pernah tidur), dan `databases.available` (lihat [Ketersediaan](#ketersediaan)); `runtime_defaults` dan `runtime_maximums` untuk memory dan CPU mengikuti profil.

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

## Database

Database PostgreSQL terkelola mengikuti ADR-020: database milik workspace (pribadi atau tim), tinggal di instance PostgreSQL bersama milik satu node database per region, dan dipasang ke nol, satu, atau beberapa proyek di workspace yang sama. Database bisa dibuat tanpa proyek dan tetap ada saat proyek yang memakainya dihapus. Node database adalah node agent yang mengiklankan capability `managed-postgres` (lihat [Agent API](AGENT_API.md#perintah-database)).

### Endpoint

Semua endpoint berada di `/api/v1/app/workspaces/{workspace}/databases` dan memakai sesi Console. Peran mengikuti peran workspace, sama seperti project: semua anggota membaca, owner dan member mengubah, hanya owner yang membaca kredensial. Admin tetap punya semua kuasa, dan tidak dibatasi kuota jumlah database, sama seperti kuota project. Pengguna di luar workspace menerima `403` generik; database atau proyek milik workspace lain menerima `404`.

| Endpoint | Owner | Member | Viewer | Admin | Hasil |
| --- | --- | --- | --- | --- | --- |
| `GET .../databases` | ya | ya | ya | ya | Database workspace, terbaru dulu, tanpa pagination (jumlahnya dibatasi kuota). |
| `POST .../databases` | ya | ya | `403` | ya | `201`. Body `name` (wajib tanpa `project_id`, paling panjang 100, unik di antara database workspace yang masih ada), `region` opsional (format region, `null` memakai `default_region` profil workspace, atau region mana pun bila kosong), `project_id` opsional (uuid atau `null`), dan `deploy_when_ready` opsional (boolean; `true` hanya bersama `project_id`). Status awal `provisioning`. Lihat "Membuat Database untuk Proyek". |
| `GET .../databases/{database}` | ya | ya | ya | ya | Satu database. |
| `PATCH .../databases/{database}` | ya | ya | `403` | ya | Body `name`. Nama di node tidak pernah berubah. |
| `DELETE .../databases/{database}` | ya | ya | `403` | ya | `202` dengan status `deleting`, lalu hilang dari API setelah node menghapusnya. Lihat "Menghapus". |
| `POST .../databases/{database}/attachments/{project}` | ya | ya | `403` | ya | `201` dengan `DatabaseAttachmentResource`, atau `200` bila sudah terpasang. Lihat "Memasang ke Proyek". |
| `DELETE .../databases/{database}/attachments/{project}` | ya | ya | `403` | ya | `204`. Data tetap ada. `404` bila tidak terpasang. |
| `GET .../databases/{database}/credentials` | ya | `403` | `403` | ya | Kredensial, `Cache-Control: no-store`, diaudit. Lihat "Kredensial". |
| `POST .../databases/{database}/rotate-password` | ya | ya | `403` | ya | `202`. Body `redeploy_attached` opsional. Lihat "Rotasi Password". |
| `POST .../databases/{database}/extensions` | ya | ya | `403` | ya | `202`. Body `extension`. Lihat "Extension". |

Memasang, melepas, dan membuat database dengan `project_id` juga memeriksa hak mengubah proyek itu, yang di workspace yang sama berarti owner atau member.

`DatabaseResource` berisi `id`, `name`, `engine` (`postgresql`), `status`, `region` (region node database, `null` bila node itu tidak punya region), `size_bytes` dan `size_checked_at` (dari pembacaan ukuran terakhir node database, `null` sampai node melaporkan; database kosong sekitar 7,5 MiB karena katalog sistem ikut terhitung), `projects` (proyek yang terpasang: `id`, `name`, `slug`, urut nama), `connection`, `last_failure`, `password_rotation` (lihat "Rotasi Password"), `created_at`, dan `updated_at`.

`connection` berisi `host`, `port`, `database`, dan `username`: nilai yang sama dengan `DB_HOST`, `DB_PORT`, `DB_DATABASE`, dan `DB_USERNAME` di proyek yang terpasang. Semua yang boleh melihat database menerimanya, termasuk viewer, karena nilai ini bukan rahasia dan host-nya ada di jaringan privat node database yang hanya terjangkau dari workload Sakala. `connection` bernilai `null` kecuali status `ready` atau `locked` dan node database sudah melaporkan alamatnya. Password dan `DATABASE_URL` tetap hanya di endpoint kredensial.

`last_failure` terisi selama permintaan terbaru ke node database untuk database itu gagal atau kedaluwarsa, dan `null` begitu ada permintaan baru, entah berjalan atau sukses:

| Field | Tipe | Keterangan |
| --- | --- | --- |
| `command` | string | Command agent yang gagal, misalnya `CreateDatabase` atau `InstallDatabaseExtension`. |
| `code` | string | Kode dari agent atau control plane. Lihat tabel di bawah. |
| `user_fixable` | boolean | `true` hanya bila pengguna bisa memperbaikinya sendiri. |
| `summary` | string | Penjelasan berbahasa Indonesia. |
| `failed_at` | string ISO 8601 atau `null` | Waktu gagal. |

| `code` | Bisa diperbaiki pengguna | Arti |
| --- | --- | --- |
| `database_extension_not_allowed` | ya | Extension di luar daftar yang diizinkan. API sudah menolaknya lebih awal, jadi kode ini praktis tidak muncul. |
| `database_extension_unavailable` | tidak | Extension diizinkan tetapi belum terpasang di server database, misalnya `vector`. Urusan operator. |
| `database_admin_unavailable` | tidak | Agent tidak bisa menghubungi instance. Aman diulang nanti. |
| `database_not_found`, `database_conflict`, `database_operation_failed` | tidak | Keadaan di node tidak sesuai, atau PostgreSQL menolak statement. Operator perlu memeriksa. |
| `database_name_invalid`, `database_password_invalid`, `invalid_runtime_command`, `unsupported_runtime_command` | tidak | Bug di API atau penempatan; seharusnya tidak pernah muncul. |
| `command_lease_expired`, `command_expired` | tidak | Node tidak menyelesaikan permintaan tepat waktu. | Nama database dan role di node, password, dan node-nya tidak pernah ada di resource ini.

| `status` | Arti |
| --- | --- |
| `provisioning` | Menunggu atau sedang dibuat oleh node database. |
| `ready` | Sudah dibuat dan menerima koneksi. |
| `failed` | Pembuatan gagal atau kedaluwarsa. Tidak bisa dipakai; hapus lalu buat lagi. |
| `deleting` | Menunggu atau sedang dihapus oleh node. |
| `locked` | Penyimpanan database workspace mencapai kuota: node membuat role database itu `NOLOGIN` dan mengakhiri sesinya, jadi aplikasi tidak bisa terhubung sampai pemakaiannya turun atau kuotanya naik. Backup malam tetap berjalan, karena sesi superuser tidak terkena. |

`deleted` hanya ada di baris yang sudah di-soft delete dan tidak pernah terlihat lewat API.

### Ketersediaan

`databases.available` di `GET app/pilot-limits` bernilai `true` selama ada setidaknya satu node yang online, boleh menerima `CreateDatabase` (capability `managed-postgres`, kelayakan yang sama dengan penempatan), dan pada pembacaan terakhirnya melaporkan `metadata.database.available: true`. Instalasi tanpa server database, node database yang offline atau di-drain, dan instance yang tidak menjawab membuatnya `false`; node yang belum pernah melaporkan `available` juga belum dihitung. Nilai ini dipakai Console untuk menyembunyikan tombol buat database dan menjelaskan bahwa database terkelola belum tersedia. API tidak menolak pembuatan database tanpa proyek karenanya (penempatan tetap mengikuti aturan di bawah), dan database yang sudah ada tetap tampil. Pembuatan untuk proyek (`project_id`) ditolak `409 DATABASE_UNAVAILABLE` selama nilainya `false`, karena proyek yang menunggu deployment pertamanya tidak boleh tertahan di belakang instance yang diketahui mati. Dihitung setiap request tanpa cache.

### Penempatan

Database ditempatkan saat dibuat di node ber-capability `managed-postgres` pada region yang diminta (`AgentNodeSchedulerService::selectDatabaseNode()`). Dari node yang online dan eligible, yang menampung database paling sedikit menang, lalu heartbeat paling baru. Bila tidak ada yang online, node region itu yang diizinkan menerima pekerjaan dan terakhir mengiklankan capability tetap dipilih, dan database menunggunya seperti deployment menunggu node-nya. Tanpa node seperti itu sama sekali, pembuatan ditolak `409 DATABASE_REGION_UNAVAILABLE`. Database tidak pernah pindah node, dan `region`-nya adalah region efektif node itu saat dibuat.

Nama database dan role di node acak dan berbeda satu sama lain (masing-masing `p_` diikuti 20 huruf kecil dan angka; agent menerima `^p_[a-z0-9]{16,40}$` dan menolak role yang sudah memiliki database lain), tidak pernah diturunkan dari nama yang diketik orang dan tidak pernah dipakai ulang, karena penyewa lain di instance yang sama bisa melihat daftar nama. Password terdiri dari 40 huruf dan angka (agent menerima 24 sampai 128 karakter ASCII yang bisa dicetak), dan tetap di-encode di `DATABASE_URL`.

Node yang melaporkan `metadata.database.available: false` (instance-nya tidak menjawab agent) didahulukan paling akhir, tetapi tetap bisa dipilih bila hanya itu yang ada; pembuatannya lalu bisa gagal dengan `database_admin_unavailable`.

### Memasang ke Proyek

Proyek menerima variabel koneksi dari deployment berikutnya. Variabel ini tidak disimpan sebagai environment variable proyek; API menyusunnya saat deployment dibuat dan menyimpannya terenkripsi di payload `DeployProject`, seperti variabel lain:

| Variabel | Isi |
| --- | --- |
| `DATABASE_URL` | `postgresql://<username>:<password>@<host>:<port>/<database>?sslmode=require`. Host IPv6 ditulis dalam kurung siku. |
| `DB_CONNECTION` | `pgsql` |
| `DB_HOST`, `DB_PORT` | Alamat instance seperti dicapai node runtime, dari `metadata.database.endpoint` heartbeat node database. |
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Nama database, role, dan password di node. |

Environment variable proyek dengan nama yang sama menang, per nama: proyek yang mengisi `DB_HOST` sendiri tetap menerima `DB_PASSWORD` dari database. `databases[].overridden_variables` di `ProjectResource` menyebut nama-nama itu. Nilai yang disuntik ikut diredaksi dari log deployment dan laporan command.

Aturan memasang:

* Proyek dan database harus satu workspace; selain itu `404`.
* Satu proyek memakai satu database pada satu waktu, karena nama variabelnya tetap: `409 PROJECT_DATABASE_ALREADY_ATTACHED`. Satu database boleh dipasang ke beberapa proyek, dan `CONNECTION LIMIT` database (5) berlaku bersama untuk semua replika semua proyek itu, termasuk sesi superuser: backup yang sedang berjalan memakai satu dari lima.
* Database harus `ready`: `locked` dijawab `409 DATABASE_LOCKED`, `provisioning`, `failed`, dan `deleting` dijawab `409 DATABASE_NOT_READY`. Database yang masih dibuat hanya terpasang lewat pembuatan untuk proyek ("Membuat Database untuk Proyek").
* Region proyek, yaitu region node yang melayani deployment `succeeded` terakhirnya, atau bila belum ada, region yang harus ditempatinya (`region` proyek, lalu `default_region` profil), harus sama dengan region database: `409 DATABASE_CROSS_REGION_UNSUPPORTED` sampai ada jaringan privat antar region. Region yang tidak diketahui di salah satu sisi tidak memblokir. Mengganti region proyek setelah dipasang tidak diperiksa ulang.

Memasang, melepas, dan rotasi password yang sukses mengubah fingerprint deploy proyek (`projects.database_fingerprint`), jadi `redeploy_required` menjadi `true` sampai deployment berikutnya; melepas database yang baru dipasang mengembalikannya ke `false`. Alamat node database tidak termasuk fingerprint: alamat itu dibaca dari heartbeat saat deployment dibuat. Bila node database belum pernah melaporkan alamatnya, deploy proyek yang database-nya terpasang ditolak `409 DATABASE_ENDPOINT_UNAVAILABLE`, dan push webhook dilewati dengan `reason` `database_endpoint_unavailable`, supaya aplikasi tidak berjalan tanpa database-nya. Menghapus proyek melepas database-nya (audit `database.detached` dengan `reason` `project_deleted`); database-nya tetap ada.

### Membuat Database untuk Proyek

`POST .../databases` dengan `project_id` membuat database dan langsung memasangnya ke proyek itu dalam satu transaksi: baris database, command `CreateDatabase`, baris attachment, fingerprint proyek, dan audit tersimpan bersama atau tidak sama sekali. Ini satu-satunya cara memasang database yang masih `provisioning`. Kunci diambil berurutan: workspace, lalu proyek, sama seperti deployment. Aturannya:

* Proyek harus milik workspace yang sama dan masih ada; selain itu `404`. Pemanggil harus boleh mengubah proyek itu (owner atau member, atau admin); viewer menerima `403`.
* Proyek belum memakai database: `409 PROJECT_DATABASE_ALREADY_ATTACHED`.
* `name` boleh kosong: dipakai nama proyek, ditambah ` 2`, ` 3`, dan seterusnya bila sudah dipakai database lain di workspace yang masih ada, dan dipotong supaya tetap paling panjang 100 karakter.
* `region` kosong atau `null` memakai region proyek (region node deployment `succeeded` terakhirnya, lalu `region` proyek), lalu `default_region` profil workspace. `region` yang diisi dan berbeda dari region proyek yang diketahui dijawab `409 DATABASE_CROSS_REGION_UNSUPPORTED`.
* Selama `databases.available` `false` dijawab `409 DATABASE_UNAVAILABLE`, tanpa field tambahan.
* Kuota sama persis dengan pembuatan biasa (`DATABASE_LIMIT_EXCEEDED`, `DATABASE_STORAGE_LIMIT_EXCEEDED`, `DATABASE_REGION_UNAVAILABLE`), diperiksa di bawah row lock workspace.

Selama database terpasang masih `provisioning` atau `failed`, membuat deployment untuk proyek itu (manual atau push webhook) ditolak `409 DATABASE_NOT_READY` dengan `status`, karena aplikasi akan berjalan tanpa database-nya. Push webhook dilewati dengan `reason` `database_not_ready`, dicatat di audit `project.auto_deploy_skipped`, dan owner serta member mendapat notifikasi `project_issue`, sama seperti `database_endpoint_unavailable`. Database `ready` dan `locked` tidak menahan deployment. Database yang `failed` perlu dilepas dulu sebelum proyek bisa di-deploy lagi.

#### Deploy setelah database siap

`deploy_when_ready: true` (boolean, default `false`; `true` tanpa `project_id` atau nilai bukan boolean dijawab `422`) mencatat di attachment bahwa pemanggil ingin proyek di-deploy begitu database siap (audit `database.project_deploy_requested`). Saat node melaporkan `CreateDatabase` sukses, job antrean membuat deployment lewat jalur deploy biasa, atas nama orang yang meminta, dari head branch proyek, dengan `trigger` `manual` dan semua batas yang berlaku. Laporan node tidak menunggu GitHub. Setiap permintaan diselesaikan sekali: idempotency key deployment-nya per attachment (`database-ready:{id attachment}`), jadi job yang berjalan dua kali tidak membuat deployment kedua. Hasilnya dicatat di audit `database.project_deploy_started` (dengan `deployment_id`) atau `database.project_deploy_skipped` (dengan `reason`).

Bila pembuatan database gagal atau kedaluwarsa, permintaan diselesaikan sebagai dilewati dengan `database_provisioning_failed`, dan orang yang meminta mendapat notifikasi in-app `project_issue` ("Database gagal dibuat") selama ia masih owner atau member; bila tidak lagi, owner dan member workspace yang mendapatkannya. Melepas database sebelum siap menghapus permintaannya bersama attachment, jadi tidak ada yang di-deploy.

`databases[].deploy_when_ready` di `ProjectResource` menunjukkan permintaan itu, atau `null` bila tidak ada:

| Field | Tipe | Keterangan |
| --- | --- | --- |
| `status` | string | `waiting` selama database dibuat, `started` setelah deployment dibuat, `skipped` bila tidak ada deployment. |
| `requested_at` | string ISO 8601 | Waktu permintaan. |
| `deployment_id` | string atau `null` | Deployment yang dibuat; hanya saat `started`. |
| `skipped_reason` | string atau `null` | Hanya saat `skipped`: nilai yang sama dengan `skipped_reason` di "Rotasi Password" (kecuali `no_active_deployment`), ditambah `database_provisioning_failed`. |

Console cukup memeriksa ulang proyek selama `status` `waiting`.

### Kredensial

`GET .../credentials` menjawab `host`, `port`, `database`, `username`, `password`, `url` (sama dengan `DATABASE_URL`), dan `sslmode` (selalu `require`). `host`, `port`, dan `url` bernilai `null` selama node database belum melaporkan alamatnya. Database yang tidak `ready` atau `locked` dijawab `409 DATABASE_NOT_READY`.

Hanya owner workspace dan admin yang boleh membaca; `host`, `port`, `database`, dan `username` tanpa password juga ada di `connection` pada `DatabaseResource` untuk semua anggota. Ini menjauhkan password dari layar member dan viewer, bukan batas keamanan: member tetap bisa memasang database ke proyek, dan kode yang mereka deploy menerima variabel koneksinya. Setiap pembacaan mencatat `database.credentials_viewed` sebelum password dikirim, tanpa password; bila catatan gagal ditulis, password tidak dikirim. Alamat IP dan user agent hanya disimpan untuk admin yang bukan anggota workspace itu.

### Rotasi Password

`POST .../rotate-password` membuat `RotateDatabasePassword` dengan password baru untuk node database dan menjawab `202` dengan `password_rotation.status` `pending`. Node langsung mengganti password begitu menjalankan permintaan itu, biasanya dalam beberapa detik, dan password lama berhenti diterima saat itu juga, karena password yang bocor harus segera tidak berlaku. Container yang sedang berjalan masih membawa password lama, jadi aplikasi yang terpasang tidak bisa membuka koneksi baru sampai di-deploy ulang. API menyimpan password baru saat node melaporkan sukses; sebelum itu password lama yang tersimpan dan disuntikkan. Setelah itu semua proyek yang terpasang menunjukkan `redeploy_required`. Rotasi yang gagal mempertahankan password lama. Rotasi kedua selagi yang pertama belum selesai dijawab `409 DATABASE_BUSY`. Database `locked` tetap bisa dirotasi.

Body `redeploy_attached` (boolean, opsional, default `false`; nilai lain `422`) meminta API men-deploy ulang proyek yang terpasang begitu node melaporkan sukses. Setiap proyek yang punya deployment aktif mendapat deployment baru lewat jalur deploy biasa, atas nama orang yang meminta rotasi, dengan `trigger` `database_password_rotated`, dari commit yang sedang live (atau head branch bila branch proyek sudah diganti sejak deployment itu), dan dengan semua batas yang berlaku. Karena commit dan setelan build-nya sama, deploy ulang ini biasanya memakai ulang image yang live tanpa build (lihat [Redeploy tanpa build](#redeploy-tanpa-build)), jadi aplikasi cepat tersambung lagi dengan password baru. Deployment itu dibuat oleh job antrean setelah laporan node tersimpan, jadi laporan node tidak menunggu GitHub. Proyek yang tidak bisa di-deploy ulang dilewati dengan alasan dan tetap menunjukkan `redeploy_required`; rotasinya tetap selesai. Notifikasi dan email deployment mengikuti aturan biasa. Hasilnya dicatat di audit `database.password_rotation_redeploys_queued`.

`password_rotation` di `DatabaseResource` menggambarkan rotasi terbaru, atau `null` bila password belum pernah dirotasi:

| Field | Tipe | Keterangan |
| --- | --- | --- |
| `status` | string | `pending` selama node belum melapor (password lama bisa jadi sudah tidak diterima), `completed` setelah node melaporkan sukses, `failed` bila node gagal atau tidak selesai tepat waktu (password lama tetap berlaku; alasannya di `last_failure`). |
| `requested_at` | string ISO 8601 | Waktu rotasi diminta. |
| `completed_at` | string ISO 8601 atau `null` | Hanya terisi saat `completed`. |
| `failed_at` | string ISO 8601 atau `null` | Hanya terisi saat `failed`. |
| `redeploy_attached` | boolean | Nilai yang diminta saat rotasi. Rotasi sebelum field ini ada bernilai `false`. |
| `redeploys` | array atau `null` | `null` sampai API selesai mengantrekan deploy ulang setelah `completed`, dan selalu `null` tanpa `redeploy_attached`. Setelah itu satu item per proyek yang terpasang saat itu: `project_id` (string), `deployment_id` (string deployment yang diantrekan, atau `null`), dan `skipped_reason` (string atau `null`, terisi bila tidak ada deployment). |

| `skipped_reason` | Arti |
| --- | --- |
| `no_active_deployment` | Proyek belum pernah di-deploy atau sedang dihentikan; deployment berikutnya otomatis memakai password baru. |
| `requester_cannot_deploy` | Akun yang meminta rotasi (atau deploy setelah database siap) sudah tidak ada atau tidak lagi boleh men-deploy proyek itu. |
| `legal_acceptance_required` | Akun itu harus menyetujui syarat terbaru dulu. |
| `project_suspended` | Proyek ditangguhkan. |
| `active_deployment_limit`, `active_container_limit`, `build_rate_limit` | Batas workspace atau proyek tercapai, misalnya karena deployment yang diantrekan sebelum rotasi masih berjalan. |
| `repository_access_revoked`, `database_endpoint_unavailable`, `release_command_unsupported` | Sama artinya dengan error deploy bernama sama. |
| `failed` | Kesalahan lain, misalnya GitHub tidak menjawab; dilaporkan ke error tracker. |
| `database_provisioning_failed` | Hanya untuk `deploy_when_ready`: pembuatan database gagal atau kedaluwarsa. |

Console cukup memeriksa ulang database selama `status` `pending`, atau selama `completed` dengan `redeploy_attached` dan `redeploys` masih `null`.

### Extension

`POST .../extensions` dengan body `extension`, salah satu dari `pgcrypto`, `citext`, `pg_trgm`, `unaccent`, `hstore`, dan `vector` (pgvector), meminta node database memasangnya di schema `public` database itu sebagai superuser, karena `vector` tidak ditandai trusted oleh PostgreSQL. Nilai lain dijawab `422` pada `extension`. Jawabannya `202` dengan `DatabaseResource`. Database harus `ready` atau `locked` (superuser tetap bisa terhubung ke database yang terkunci); selain itu `409 DATABASE_NOT_READY`. Permintaan yang sama selagi pemasangan sebelumnya masih berjalan tidak membuat command baru, dan memasang extension yang sudah ada tetap sukses. Hasilnya dicatat di audit (`database.extension_installed` atau `database.extension_install_failed`), dan kegagalannya tampil di `last_failure`, misalnya `database_extension_unavailable` bila server database belum memasang pgvector. Extension di luar daftar ini, termasuk PostGIS, belum tersedia.

### Menghapus

Database yang masih terpasang ke proyek tidak bisa dihapus (`409 DATABASE_IN_USE` dengan daftar proyeknya), supaya aplikasi yang berjalan tidak kehilangan datanya tanpa sengaja. Selain itu status menjadi `deleting` dan API membuat `DropDatabase`; setelah node melaporkan sukses, baris di-soft delete, password-nya dikosongkan, dan salinan password di payload command dihapus. Mengulang selagi drop masih berjalan tidak membuat apa pun. Drop yang gagal membiarkan status `deleting`, dan menghapus lagi mengirim drop baru. Database `failed` juga dikirim drop, karena pembuatannya bisa gagal di tengah jalan.

Database `provisioning` yang `CreateDatabase`-nya belum diambil node langsung dihapus: command-nya dibatalkan dan tidak ada yang dikirim ke node. Bila node sudah mengambilnya, dijawab `409 DATABASE_BUSY` sampai pembuatan selesai.

Menghapus tim membutuhkan database-nya dihapus lebih dulu (`409 WORKSPACE_NOT_EMPTY` dengan `databases`); database yang sedang dihapus tidak menahan tim, dan drop-nya selesai tanpa workspace. Menghapus akun meminta drop untuk semua database workspace pribadinya, dan pemilik tunggal tim yang masih punya database menerima `409 LAST_WORKSPACE_OWNER`. Database yang sedang dibuat node membuat penghapusan akun dijawab `409 DATABASE_BUSY`; ulangi sebentar lagi.

### Kuota dan Penguncian

Profil batas mengatur dua angka per workspace, keduanya dengan arti `0` yang sama, yaitu tidak boleh sama sekali:

| Field profil | Global (`null` dan workspace tanpa profil) | `0` |
| --- | --- | --- |
| `max_databases` | `SAKALA_DATABASE_DEFAULT_MAX_COUNT` (2) | Workspace tidak bisa membuat database (`422 DATABASE_LIMIT_EXCEEDED` dengan `limit: 0`). |
| `max_database_storage_mb` | `SAKALA_DATABASE_DEFAULT_MAX_STORAGE_MB` (512) | Tanpa penyimpanan: semua database workspace dikunci, dan database baru ditolak `422 DATABASE_STORAGE_LIMIT_EXCEEDED`. |

Nilai env yang kosong memakai default di tabel. Tidak ada nilai "tanpa batas". Jumlah database menghitung semua database yang masih ada kecuali yang `failed`, termasuk yang sedang dihapus sampai node menghapusnya. Penyimpanan adalah jumlah `size_bytes` semua database workspace yang masih ada, termasuk yang sedang dihapus; database yang belum pernah diukur dihitung 0. Pemeriksaan pembuatan berjalan di bawah row lock workspace, jadi dua request tidak bisa sama-sama memakai slot terakhir.

PostgreSQL tidak punya kuota per database, jadi `databases:enforce-storage-quota` (setiap menit, satu server) membandingkan ukuran dari heartbeat dengan kuota setiap workspace yang punya database:

* Pada 80% atau lebih, owner dan member workspace menerima notifikasi kategori `workspace` sekali per lintasan ("Penyimpanan database hampir penuh"). `workspaces.database_storage_warned_at` mencatatnya dan dikosongkan saat pemakaian turun di bawah 80%. Workspace yang langsung penuh hanya menerima notifikasi penguncian. Audit `workspace.database_storage_warning`.
* Pada 100% atau lebih, setiap database `ready` di workspace itu mendapat `LockDatabase` (audit `database.lock_requested`): node membuat role-nya `NOLOGIN` dan mengakhiri sesinya, sementara backup tetap berjalan, dan owner serta member menerima notifikasi "Database dikunci karena penyimpanan penuh" yang mengabaikan preferensi kategori, seperti pemberitahuan penangguhan. Status menjadi `locked` saat node melaporkan sukses (`database.locked`).
* Di bawah 100%, termasuk karena admin menaikkan kuota, setiap database `locked` mendapat `UnlockDatabase` (`database.unlock_requested`), dan kembali `ready` saat node melapor (`database.unlocked`).

Database yang lock atau unlock-nya masih berjalan tidak dimintai lagi; yang gagal diminta lagi pada sapuan berikutnya. Ukuran hanya berubah saat node mengirim pembacaan baru (paling sering sekali per menit); heartbeat tanpa daftar ukuran tidak mengubah apa pun. Database `provisioning`, `failed`, dan `deleting` tidak dikunci atau dibuka. Selama terkunci, aplikasi tidak bisa terhubung, jadi data di dalamnya tidak bisa dikurangi dari aplikasi: hapus database yang tidak dipakai, atau minta admin menaikkan kuota. Ukuran yang turun setelah `DELETE` atau `VACUUM` baru terlihat setelah node melaporkannya.

### Audit

Setiap perubahan dicatat di `audit_events` dengan subject database (`App\Models\ManagedDatabase`), berisi ID, nama di node, `command_id`, dan kode, tanpa password atau URL: `database.created` (dengan `project_id` bila dibuat untuk proyek), `database.provisioned`, `database.provisioning_failed`, `database.renamed`, `database.delete_requested`, `database.deleted`, `database.drop_failed`, `database.attached`, `database.detached`, `database.project_deploy_requested` (dengan `project_id` dan `attachment_id`, actor orang yang meminta), `database.project_deploy_started` (dengan `deployment_id`), `database.project_deploy_skipped` (dengan `reason`; actor node atau `system`/`agent:expire-commands` bila pembuatan gagal), `database.credentials_viewed`, `database.password_rotation_requested` (dengan `redeploy_attached`), `database.password_rotated`, `database.password_rotation_failed`, `database.password_rotation_redeploys_queued` (dengan `redeploys`, actor orang yang meminta rotasi), `database.lock_requested`, `database.locked`, `database.lock_failed`, `database.unlock_requested`, `database.unlocked`, `database.unlock_failed`, `database.extension_install_requested`, `database.extension_installed`, dan `database.extension_install_failed`. Peringatan 80% dicatat dengan subject workspace (`workspace.database_storage_warning`). Hasil dari node dicatat dengan actor node itu; command yang kedaluwarsa dengan actor `system`/`agent:expire-commands`, dan sapuan kuota dengan `system`/`databases:enforce-storage-quota`.

## Notifikasi

Notifikasi in-app memakai database notification Laravel; sebagian juga dikirim lewat email (lihat [Email notifikasi](#email-notifikasi)). Kategori: `deployment_success`, `deployment_failure`, `project_issue` (workload crash, auto-deploy dilewati, database yang diminta untuk deploy proyek gagal dibuat), `account_security` (kata sandi dan email), dan `workspace` (tim). `GET` dan `PATCH /notification-preferences` ikut punya `workspace`, default `true`.

Kategori `workspace` dikirim untuk:

- undangan ke alamat terverifikasi akun, dengan `invitation_id`;
- undangan yang diterima, ke pengundangnya, dengan `invitation_id` dan `workspace_id`;
- perubahan peran oleh owner lain, ke anggota itu, dengan `workspace_id`;
- dikeluarkan dari tim oleh owner, ke anggota itu, tanpa `workspace_id` karena timnya tidak bisa dibuka lagi.
- penyimpanan database workspace mencapai 80% kuota, dan database workspace dikunci karena kuota penuh, ke owner dan member, dengan `workspace_id`. Pemberitahuan penguncian mengabaikan preferensi ini. Lihat [Database](#database).

Keluar sendiri atau mengubah peran sendiri tidak mengirim apa pun. Email undangan tidak terpengaruh preferensi ini.

Notifikasi project sampai ke owner dan member workspace-nya, tidak ke viewer; di workspace pribadi itu berarti pemiliknya, sama seperti sebelumnya. Kabar deployment (`deployment_success`, `deployment_failure`) hanya sampai ke orang yang memintanya selama ia masih owner atau member, dan ke semua owner serta member bila tidak.

| Endpoint | Hasil |
| --- | --- |
| `GET /api/v1/app/notifications` | Terbaru dulu, `page`, `per_page` (maks. 50), `unread=1`. |
| `GET /api/v1/app/notifications/unread-count` | `{ "data": { "count" } }` |
| `POST /api/v1/app/notifications/{id}/read` | Resource notifikasi. Idempoten, `read_at` pertama dipertahankan. Milik orang lain `404`. |
| `POST /api/v1/app/notifications/read-all` | `204` |
| `GET`, `PATCH /api/v1/app/notification-preferences` | Satu boolean in-app per kategori (default `true`), plus objek `email` untuk kategori yang bisa diemail. `PATCH` boleh sebagian. Lihat [Email notifikasi](#email-notifikasi). |

Satu crash menghasilkan satu notifikasi `project_issue`: heartbeat berikutnya yang masih melaporkan crash tidak mengirim lagi, begitu juga setelah redeploy gagal, dan pemulihan tidak mengirim apa pun. Crash baru setelah workload pulih mengirim lagi. Selama ada deployment baru yang berjalan, heartbeat tidak mengubah `runtime_status`.

Bentuk item: `id`, `category`, `title`, `message`, `project_id`, `deployment_id`, `invitation_id`, `workspace_id`, `read_at`, `created_at`. Empat ID itu string atau `null`; `invitation_id` dan `workspace_id` hanya diisi notifikasi `workspace`, dan notifikasi lama tidak memilikinya. Kategori yang dimatikan tidak membuat notifikasi sama sekali, kecuali pemberitahuan penangguhan. Notifikasi baru juga dibroadcast pada channel privat `users.{id}` dengan event `notification.created` dan field yang sama. Kiriman terjadi setelah transaksi commit, dan kegagalannya dilaporkan tanpa menggagalkan laporan agent yang memicunya.

Tidak ada data contoh di luar test. Notifikasi lebih tua dari `SAKALA_NOTIFICATION_RETENTION_DAYS` dihapus `notifications:prune`, dijadwalkan harian.

### Email notifikasi

Deployment gagal, aplikasi yang berhenti sendiri, dan push yang dilewati auto-deploy juga dikirim lewat email, supaya orang yang sedang tidak membuka Console tetap tahu. Email adalah channel kedua yang diatur terpisah dari lonceng: mematikan lonceng satu kategori tidak mematikan email-nya, dan sebaliknya.

Preferensi ada di respons `GET` dan `PATCH /api/v1/app/notification-preferences`, di objek `email`:

```json
{
  "data": {
    "deployment_success": true,
    "deployment_failure": true,
    "project_issue": true,
    "account_security": true,
    "workspace": true,
    "email": {
      "deployment_success": false,
      "deployment_failure": true,
      "project_issue": true
    }
  }
}
```

| Field | Tipe | Default | Isi |
| --- | --- | --- | --- |
| `email.deployment_failure` | boolean, selalu ada | `true` | Deployment gagal. |
| `email.project_issue` | boolean, selalu ada | `true` | Aplikasi yang sedang berjalan berhenti sendiri (crash), dan push yang tidak di-deploy auto-deploy (batas workspace, build per jam, akses repository, proyek ditangguhkan, dan sebab lain di `project.auto_deploy_skipped`). |
| `email.deployment_success` | boolean, selalu ada | `false` | Deployment berhasil. Mati secara default karena hanya kabar baik yang cukup dilihat di lonceng. |

`account_security` dan `workspace` tidak punya preferensi email dan tidak muncul di `email`. Email yang penting untuk keduanya sudah dikirim tanpa melihat preferensi (ganti email ke alamat lama, undangan ke alamat yang diundang). Nilai `null` di database berarti default di atas.

`PATCH` menerima flat boolean untuk lonceng dan `email` untuk email, boleh sebagian, boleh keduanya dalam satu request, misalnya `{ "email": { "deployment_success": true } }`. Respons selalu berisi semua field. `email` harus objek; kunci selain tiga di atas (misalnya `email.workspace`) dan nilai yang bukan boolean dijawab `422` dengan error di `email` atau `email.<kategori>`, tanpa mengubah apa pun.

Siapa yang dikirimi email:

- deployment gagal atau berhasil: orang yang meminta deployment selama ia masih owner atau member workspace, dan semua owner workspace;
- aplikasi berhenti sendiri: orang yang meminta deployment yang sedang berjalan, dengan syarat yang sama, dan semua owner;
- push dilewati: akun yang dipakai push untuk deploy (pembuat proyek selama masih bisa deploy, atau owner tertua), dan semua owner.

Viewer tidak pernah dikirimi email. Di workspace pribadi semuanya berarti pemiliknya. Penerima dengan email kategori itu mati, atau alamat yang belum terverifikasi, dilewati. Tidak ada email sama sekali bila `sakala.email_delivery_enabled` mati. Penerima lonceng tidak berubah: kabar deployment ke peminta (atau semua owner dan member bila ia sudah tidak bisa mengelola proyek), crash dan push dilewati ke semua owner dan member.

Email tetap dikirim walaupun peminta sedang membuka halaman deployment itu. API tidak tahu siapa yang sedang menonton, deployment bisa gagal beberapa menit setelah tab ditutup, dan penggabungan di bawah sudah membatasi jumlahnya.

Penggabungan: untuk setiap penerima, project, dan jenis kabar (`deployment_failed`, `deployment_succeeded`, `app_crashed`, `auto_deploy_skipped`), email dikirim paling banyak sekali dalam `SAKALA_NOTIFICATION_EMAIL_COALESCE_MINUTES` menit (default 30). Kabar di dalam jendela itu tidak diemail, tetapi dihitung, dan email berikutnya setelah jendela lewat menyebut jumlahnya ("Ada 2 kegagalan lagi di proyek ini sejak email terakhir."). Jenisnya dipisah supaya push yang dilewati tidak menahan email crash. Lonceng tetap mendapat setiap kabar. Kalau tidak ada kabar lagi setelah jendela lewat, jumlah yang ditahan tidak dikirim tersendiri.

Email dikirim lewat queue setelah transaksi commit. Isinya nama proyek, nomor deployment, tahap yang gagal, ringkasan dan saran dari classifier kegagalan (`failure.summary`, `failure.recovery_hint`), atau alasan push dilewati. Log dan `failure.detail` tidak pernah masuk email. Tombolnya membuka halaman deployment di Console (`/projects/{id}/deployments/{deploymentId}`), atau halaman proyek untuk push yang dilewati.

#### Berhenti berlangganan tanpa login

Setiap email punya tautan bertanda tangan yang mematikan email kategori itu untuk penerimanya, tanpa sesi:

| Endpoint | Hasil |
| --- | --- |
| `POST /api/v1/public/notifications/unsubscribe?category={kategori}&user={id}&signature={hmac}` | `200 { "data": { "category": "deployment_failure", "email": false } }`. Idempoten. Hanya `email.<kategori>` yang berubah; lonceng dan kategori lain tetap. Tanda tangan salah atau query diubah `403 { "message": "Invalid signature." }`; kategori tanpa email `422` di `category`; akun yang sudah dihapus `404`. Dibatasi `SAKALA_NOTIFICATION_UNSUBSCRIBE_RATE_LIMIT` per IP. |
| `GET` di alamat yang sama | `302` ke Console `/notifications/unsubscribe?{query yang sama}`. Tidak mengubah apa pun, karena pemindai tautan di mailbox sekolah dan kantor membuka setiap tautan. |

Header `List-Unsubscribe` berisi alamat `POST` itu, dan `List-Unsubscribe-Post: List-Unsubscribe=One-Click`, sehingga Gmail dan klien lain bisa menampilkan tombol berhenti berlangganan sendiri (RFC 8058). Tautan di footer email membuka halaman Console. Halaman itu menampilkan nama kategori dari `category`, meminta konfirmasi, lalu mengirim `POST` ke `{API}/api/v1/public/notifications/unsubscribe?{query}` dengan query string persis seperti yang diterimanya: tanda tangan menghitung query byte per byte, jadi urutan dan encoding-nya tidak boleh diubah. Route ini stateless, tanpa cookie dan CSRF.

Tautan tidak kedaluwarsa, supaya email lama tetap bisa dipakai. Mengganti `APP_KEY` membatalkan semuanya.

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
| `GET`, `POST limit-profiles`; `GET`, `PATCH`, `DELETE limit-profiles/{limitProfile}` | Profil batas dengan `workspaces_count`, `idle_sleep_minutes` (`null` mengikuti global, `0` tidak pernah tidur, 1 sampai 10080 menit), `max_replicas` (`null` berarti 1, atau 1 sampai 10), `max_builds_per_hour` (`null` mengikuti `SAKALA_PROJECT_MAX_BUILDS_PER_HOUR`, atau 1 sampai 1000 deployment yang mem-build per 60 menit; deployment yang memakai ulang image tidak dihitung), `default_region` (`null` berarti region mana pun, lihat [Region dan Penempatan](#region-dan-penempatan)), `max_databases` (`null` mengikuti `SAKALA_DATABASE_DEFAULT_MAX_COUNT`, `0` tidak boleh punya database, atau sampai 1000), dan `max_database_storage_mb` (`null` mengikuti `SAKALA_DATABASE_DEFAULT_MAX_STORAGE_MB`, `0` tanpa penyimpanan sehingga semua database workspace dikunci, atau sampai 1048576 MB). Perubahannya tercatat di `limit_profile.created` dan `limit_profile.updated` seperti field lain. `GET workspaces/{workspace}` menyajikan nilai efektifnya di `quota.limits.idle_sleep_minutes`, `quota.limits.max_replicas`, dan `quota.limits.max_builds_per_hour` (`null` berarti tanpa batas), dan `quota.usage.active_containers` menghitung replika. `quota.limits` juga memuat `max_databases` dan `max_database_storage_mb` efektif, dan `quota.usage` memuat `databases` dan `database_storage_bytes`. `PATCH` sebagian. `DELETE` membawa `reason` di body dan dijawab `409 LIMIT_PROFILE_IN_USE` (dengan `workspaces`) selama masih dipakai. |
| `GET projects` | `search` (nama, slug, `owner/repo`), `status`, `runtime_status` (termasuk `sleeping`), `workspace_id`, `suspended`, `idle_sleep_blocked`. Project yang dihapus tidak ikut. Setiap project membawa `idle_sleep_blocked` (boolean): `true` bila sapuan tidur otomatis berhenti mencoba deployment aktifnya karena node menjawab `runtime_workload_not_found` (lihat "Tidur Otomatis"). |
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
| `GET edges` | Semua instance Sakala Edge (token Edge, termasuk yang dicabut), urut menurut `name`, tanpa pagination: versi, revisi yang disajikan dan revisi sekarang, status, `healthy`, `unhealthy_reasons`, `unhealthy_since`. Lihat [EDGE_API.md](EDGE_API.md#get-apiv1adminedges). |
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

Node juga punya `region`, `region_override`, `reported_region`, `edge_address`, `edge_address_override`, `reported_edge_address`, dan `capacity` (`memory_total_bytes`, `memory_available_bytes`, `active_workloads`, `maximum_active_workloads`, `free_workload_slots`, masing-masing `null` bila node tidak melaporkannya). Nilai efektif adalah override admin bila ada, selain itu laporan heartbeat. Override diatur dengan `php artisan agent:node-region {node} {region}` dan `agent:node-edge-address {node} {host:port}` (`--clear` untuk kembali ke laporan node, `--reason` dicatat di audit `agent_node.region_changed` atau `agent_node.edge_address_changed`). `GET /api/agent/v1/agents` dan detailnya juga menyertakan `region` dan `edge_address` efektif.

`POST agents` memakai action yang sama dengan `artisan agent:provision`, yang dipanggil `provision-agent.sh` di repository deployment. Token hanya ada di response pembuatan atau rotate (`Cache-Control: no-store`); API hanya menyimpan HMAC-nya, jadi token yang hilang diganti dengan rotate. Command `DeployProject` melaporkan output sebagai log deployment, dibaca lewat `deployments/{deployment}/logs`; report command lain ada di detail command. Log proses `sakala-agent` sendiri tidak dikirim ke API dan tidak disimpan, jadi tidak ada endpoint untuknya. Restart agent juga tidak punya command; yang ada drain, resume, dan cleanup di `/api/agent/v1/agents/{agent}`.

### Yang Belum Ada

Tidak ada model laporan abuse: sinyal `manual_intervention` di `signals` dan audit `project.suspend_requested` adalah catatan yang ada sekarang. Akun pengguna juga tidak punya status suspend; yang bisa ditangguhkan adalah project.
