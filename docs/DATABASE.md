# Database

Sakala API memakai PostgreSQL sebagai source of truth untuk metadata control plane. Schema MVP sengaja tidak memasukkan workspace/team, custom domain, managed database, atau storage karena fitur tersebut belum termasuk kontrak MVP.

## Identitas

`projects`, `deployments`, `agent_nodes`, dan `agent_commands` memakai UUIDv7. Identifier ini aman untuk diekspos melalui API, kompatibel dengan tipe `Uuid` pada Sakala Agent, dan lebih ramah terhadap B-tree dibanding UUID acak.

Tabel append-only berukuran besar seperti `deployment_events`, `deployment_logs`, dan `audit_events` memakai bigint berurutan. ID internal tersebut tidak menjadi kontrak publik.

## Tabel MVP

| Tabel | Tanggung jawab |
| --- | --- |
| `users` | Identitas console, role, onboarding, waktu login terakhir, email yang menunggu konfirmasi (`pending_email`), dan preferensi notifikasi. |
| `notifications` | Notifikasi in-app (database notification Laravel). `id` UUID, isi di `data`, retensi lewat `notifications:prune`. |
| `oauth_accounts` | Identitas provider OAuth dan token terenkripsi, terpisah dari user. |
| `github_installations` | Entity global GitHub App installation, metadata akun GitHub, dan status akses repository. |
| `github_installation_user` | Relasi user Sakala dengan installation GitHub yang telah diverifikasi. |
| `github_webhook_deliveries` | Delivery ID webhook GitHub untuk pemrosesan lifecycle yang idempoten. |
| `projects` | Metadata repository, generated domain, status runtime, deployment yang sedang melayani (`active_deployment_id`), dan hasil inspeksi stack terakhir (`inspection`, `inspection_status`, `inspection_error_code`, `inspected_at`). |
| `environment_variables` | Key dan value terenkripsi per project. |
| `retired_project_slugs` | Slug project yang sudah di-purge. Hanya slug dan waktunya, dan tidak pernah dihapus, supaya alamat lama tidak bisa dipakai project lain. |
| `deployments` | Satu attempt deployment, snapshot source yang dijalankan, node target, resource yang diterapkan agent, status finalisasi, dan keterangan kegagalan (`failure_code`, `failure_summary`, `failure_detail`, `failure_stage`). |
| `agent_nodes` | Identitas runtime node, token hash, capability, protocol revision, desired lifecycle state, dan heartbeat terakhir. |
| `agent_commands` | Durable command queue antara API dan agent. |
| `agent_command_reports` | Event/log append-only untuk command tanpa deployment (InspectProject, CleanupRuntime, DrainNode, ResumeNode). |
| `agent_node_control_requests` | Record idempotency operasi admin pada runtime node (drain, resume, cleanup); terpisah dari `project_control_requests` yang project-scoped. |
| `deployment_events` | Timeline state yang dilaporkan agent. |
| `deployment_logs` | Output redacted dari build/runtime. |
| `audit_events` | Jejak tindakan sensitif oleh user, agent, atau sistem. |
| `legal_acceptances` | Versi dokumen yang disetujui atau diketahui tiap user, dengan waktunya. Append-only, unik per user, dokumen, dan versi; index itu juga melayani pembacaan per user. Ikut terhapus bersama user. |
| `usage_signal_records` | Sinyal agregat penggunaan dan abuse (deployment attempts, agent failures, rejected limits, dll.) untuk observabilitas pilot. Append-only, diproses secara berkala oleh scheduler. |

Project dimiliki langsung oleh user selama MVP. Project dari GitHub App menyimpan installation UUID dan repository ID; project URL publik tidak memerlukan installation. Model workspace/team baru boleh ditambahkan setelah ownership dan policy multi-user disetujui.

## Status dan Enum

Nilai status disimpan sebagai string dan dicast melalui backed enum di `app/Enums`. Pendekatan ini menjaga type safety di aplikasi tanpa mengunci deployment schema pada native enum PostgreSQL yang lebih sulit diubah.

Nilai `AgentCommandType`, `AgentCommandStatus`, `DeploymentEventLevel`, dan `LogStream` harus tetap sama dengan `sakala-agent-protocol`. Perubahan kontrak wajib dilakukan pada kedua repository dan disertai test compatibility.

## Index Strategy

Index dibuat dari query path yang sudah diketahui:

- daftar project user: `(user_id, status, created_at)`;
- project yang ikut auto-deploy pada satu repository: `(github_repository_id, auto_deploy)` pada `projects`;
- daftar notifikasi dan jumlah belum dibaca: `(notifiable_type, notifiable_id, created_at)` dan `(notifiable_type, notifiable_id, read_at)`; retensi: `(created_at)`;
- ringkasan runtime user: `(user_id, runtime_status)`;
- riwayat deployment: `(project_id, created_at)`;
- project yang dilayani sebuah deployment, dipakai heartbeat untuk status crash: `(active_deployment_id)` pada `projects`, bersama `(agent_node_id, status)` pada `deployments`;
- deployment aktif/bermasalah: `(status, created_at)` dan `(failure_code, created_at)`;
- cleanup log terminal: `(status, finished_at)` dan `(status, cancelled_at)`;
- polling command: `(status, available_at, created_at)`;
- polling node tertentu: `(agent_node_id, status, available_at)`;
- timeline/log: `(deployment_id, occurred_at|recorded_at)`;
- report retry: unique `(agent_command_id, idempotency_key)` pada event, log, dan command report;
- timeline command tanpa deployment: unique `(agent_command_id, sequence)` dan `(agent_command_id, occurred_at)` pada `agent_command_reports`;
- audit actor/subject: `(type, id, created_at)`.

### Pilot validation metrics

Pilot validation metrics uses the following additional query paths:

- activated users: `(onboarding_completed_at)` pada `users`;
- unique dan repeat deployers: partial `(created_at, requested_by)` pada `deployments` dengan `requested_by IS NOT NULL`.

Kedua index tersebut ditambahkan setelah query divalidasi menggunakan `EXPLAIN (ANALYZE, BUFFERS)` pada PostgreSQL. Index `(status, created_at)` yang sudah ada tetap digunakan untuk successful deployments dan failure-category metrics, sehingga tidak diperlukan index tambahan untuk metric tersebut. Existing feedback indexes juga sudah mencukupi untuk pilot feedback count.

Jangan menambahkan index untuk setiap kolom. Setiap index menambah biaya write dan storage. Query baru yang penting harus divalidasi dengan `EXPLAIN (ANALYZE, BUFFERS)` pada PostgreSQL sebelum menambah index.

## Concurrency dan Idempotensi

- Nomor `deployments.sequence` unik per project. Action pembuat deployment harus mengalokasikannya di dalam transaction dan mengunci project row.
- `agent_commands.idempotency_key` mencegah command ganda akibat retry HTTP/job.
- Claim command harus atomic. Implementasi PostgreSQL dapat memakai transaction dengan `FOR UPDATE SKIP LOCKED` pada query polling. Claim wajib memvalidasi ulang eligibility node (status operasional dan capability) di dalam transaction, karena state node bisa berubah antara poll dan claim.
- Policy eligibility command (node status, capability, ownership) didefinisikan satu kali di `AgentCommandEligibilityService` dan dipakai bersama oleh poll dan claim agar aturan keduanya tidak drift.
- Sequence event/log unik per deployment. Action penerima report mengunci command dan deployment dalam transaction, lalu mengalokasikan sequence secara atomic. Setiap item dengan `Idempotency-Key` menyimpan HMAC `payload_hash` dari payload logical sebelum redaction; unique `(agent_command_id, idempotency_key)` membuat retry memakai sequence yang sama dan mencegah baris ganda. Request tanpa key sengaja tidak menyimpan idempotency key sehingga tetap append-only. `agent_commands.reported_log_bytes` menyimpan counter budget log kumulatif dan hanya ditambah di transaction setelah command di-lock.
- Status transition tidak boleh dilakukan langsung dari controller; gunakan Action yang memvalidasi state saat ini.
- `agent_commands.lease_expires_at` diisi saat claim (execution timeout + grace) dan hanya dibaca oleh sweep `agent:expire-commands` lewat index `(status, lease_expires_at)`; `expires_at` tetap berarti batas ketersediaan saat `Pending`. Sweep mengunci command satu per satu dan memeriksa ulang deadline di bawah lock supaya completion yang mendahului tidak ditimpa.
- Transisi `Claimed -> Running` dilakukan API pada report pertama yang diterima dari node pemilik (agent mengirim event `command.claimed` segera setelah claim dan tidak memanggil endpoint lain). `started_at` diisi sekali di transaction report yang sama.
- Status deployment pada jalur agent digerakkan oleh event fase (`deployment.checkout.started` → `cloning`, `deployment.analysis.started` → `analyzing`, `deployment.build.started` → `building`, `deployment.container.started` → `deploying`, `deployment.health.started` → `health_checking`, `deployment.route.started` → `routing`) di dalam transaction report yang sama, hanya maju dan tidak pernah mundur, sehingga retry event bersifat idempoten. `succeeded` hanya ditulis oleh `complete`; `failed` oleh `fail` atau control plane. `TransitionDeploymentAction` menerima state terminal dari state aktif mana pun karena laporan agent bersifat otoritatif.
- `projects.active_deployment_id` menunjuk deployment yang sedang melayani. FK nullable ke `deployments` dengan `nullOnDelete`, dan hanya ditulis di bawah row lock project: deployment `succeeded` mengisinya bila sequence-nya tidak lebih lama dari yang sekarang (completion terlambat tidak memundurkan pointer), restart atau wake pada workload terkini mengisinya, dan stop atau sleep yang sukses pada workload terkini mengosongkannya. Deployment gagal tidak menyentuhnya, sehingga redeploy yang gagal tidak menghapus fakta bahwa versi lama masih melayani. Pointer disimpan, bukan diturunkan, karena fakta "sudah dihentikan" tidak bisa dibaca dari status deployment dan daftar deployment serta heartbeat perlu membacanya tanpa query per baris.
- Heartbeat yang membawa `crashed_details` menyamakan `runtime_status` dalam transaction heartbeat. Pembacaan pertama tanpa lock menentukan apakah ada perubahan; hanya bila ada, deployment lalu project di-lock berurutan menurut `id` dan kondisinya diperiksa ulang. Urutan deployment sebelum project sama dengan `TransitionDeploymentAction`, sehingga heartbeat dan command yang selesai tidak bisa saling deadlock. Laporan yang sama tidak menulis apa pun.
- Membatalkan deployment mengikuti urutan lock lawannya supaya tidak bisa deadlock. Command `Pending` memakai urutan claim: deployment dikunci dulu, lalu `UPDATE ... WHERE status = 'Pending'` yang kalah bila node sudah mengklaim, dan alurnya turun ke jalur berikut. Command `Claimed`/`Running` memakai urutan complete dan fail: command dulu, lalu deployment, dan hanya menulis `cancel_requested_at` di keduanya. `agent_commands.cancel_requested_at` adalah yang dibaca poll; `deployments.cancel_requested_at` adalah yang dibaca console dan `TransitionDeploymentAction`.
- `projects.config_changed_at` ditulis saat environment variable atau konfigurasi build berubah. `redeploy_required` membandingkannya dengan `created_at` deployment aktif, karena deployment menyimpan snapshot keduanya saat dibuat. Presisinya detik, jadi nilai yang sama dianggap berubah: anjuran redeploy yang berlebihan lebih murah daripada yang terlewat.
- Baris project yang di-soft delete tetap dibaca jalur agent (`withTrashed`) supaya `StopProject` dan attempt yang masih berjalan bisa selesai.
- `projects:purge-deleted` mengunci baris project lalu memeriksa ulang di dalam transaction bahwa tidak ada command yang belum selesai, deployment aktif, atau workload yang belum berhenti. Jalur agent mengunci baris yang sama sebelum mengubah workload, jadi command baru tidak bisa muncul di antara pemeriksaan dan penghapusan. Foreign key menghapus sisanya: `deployment_logs`, `deployment_events`, `agent_command_reports`, `environment_variables`, dan `project_control_requests` ikut terhapus, sedangkan `agent_commands` dan `deployments` yang memakai `restrictOnDelete` dihapus lebih dulu oleh perintah ini. Pencarian kandidat memindai `projects` sekali sehari, dan tabelnya kecil karena kuota per user, jadi `deleted_at` tidak diberi index.
- `deployment.idempotency_key` untuk push webhook adalah `github-push:{delivery}`, di atas `github_webhook_deliveries` yang sudah mengunci pengiriman ulang.
- Saat `complete` DeployProject membawa `finalization_deferred = true`, API membuat `StopProject` untuk setiap deployment `succeeded` yang lebih lama pada project dan node yang sama dengan `idempotency_key` deterministik (`deferred-finalization:{baru}:{lama}`), sehingga retry completion tidak menggandakan command dan deployment baru tidak pernah menjadi target.

## Keterangan Kegagalan Deployment

`deployments.failure_detail` (varchar 500, nullable) menyimpan pesan `fail` dari agent setelah dibersihkan, diredaksi, lalu dibatasi. Sebelumnya pesan ini hanya ada di `agent_commands.error_message`. `deployments.failure_stage` (varchar 32, nullable) menyimpan `DeploymentStatus` saat kegagalan terjadi, sebelum status berubah menjadi `failed`; sebelumnya tahap hanya bisa diturunkan dari `deployment_events`. Keduanya berada di `deployments` karena `pilot:prune-logs` menghapus event dan log tetapi tidak menyentuh baris deployment.

Migrasi `2026_11_05_143109` dan `2026_11_05_143110` hanya menambah kolom nullable, jadi kode lama tetap berjalan. Baris lama tidak diisi ulang untuk `failure_detail` dan `failure_stage`, dan API menyajikannya sebagai `null`. Migrasi kedua mengisi `active_deployment_id` dari deployment `succeeded` terbaru tiap project, kecuali project `stopped` atau workload yang punya `StopProject`/`SleepProject` sukses. Project `failed` yang ternyata masih punya deployment yang melayani dikembalikan ke `running`; heartbeat berikutnya mengoreksinya bila keadaan sebenarnya berbeda. Pada SQLite, `down` migrasi itu gagal bila tabel sudah berisi data karena SQLite harus membuat ulang tabel `projects`; `down` ditujukan untuk PostgreSQL.

`deployments.crash_notified_at` mencegah notifikasi ganda untuk crash yang sama dan dikosongkan saat agent berhenti melaporkan workload itu.

`deployments.container_port` menyimpan port yang dikirim ke agent saat deployment dibuat, sumber `serving_port`. Migrasinya mengisi baris lama dari payload `DeployProject`; yang tanpa command tetap `null`.

Migrasi `2026_11_06_00000x` hanya menambah kolom nullable atau berdefault dan satu tabel baru, jadi kode lama tetap berjalan dan tidak ada backfill.

## Node Lifecycle dan Protocol

`agent_nodes.status` adalah state yang dilaporkan agent lewat heartbeat; `agent_nodes.desired_state` adalah intent control plane (`active`, `draining`, `drained`, `maintenance`) yang dibaca agent melalui `GET /api/agent/v1/node-state` saat bootstrap. Keduanya sengaja dipisah: `ChangeAgentNodeLifecycleAction` mengunci node dan menulis desired state dalam transaction yang sama dengan pembuatan `DrainNode`/`ResumeNode`, sedangkan status hanya ditulis oleh heartbeat — kecuali `offline`, yang diturunkan `agent:mark-offline-nodes` (lock per node, dilewati bila heartbeat masuk di antara scan dan lock) memakai index `(status, last_seen_at)` yang sudah ada. Index `agent_node_control_requests (agent_node_id, action)` dan `(actor_type, actor_id)` mengikuti pola `project_control_requests`.

`agent_nodes.protocol_version` diambil dari `metadata.protocol_version` heartbeat dan dibandingkan dengan `sakala.agent.supported_protocol_versions`. Node dengan revisi yang tidak didukung (atau belum pernah heartbeat) tetap bisa online tetapi tidak eligible menerima command. Tidak ada index tambahan untuk kedua kolom ini: jumlah node pada pilot kecil dan pemilihan node sudah memakai index `(status, last_seen_at)`.

Command dengan tipe *pinned* (`InspectProject`, `DeployProject`, `CleanupRuntime`, `DrainNode`, `ResumeNode`) hanya ditawarkan ke node yang tercatat pada `agent_node_id`; command pinned tanpa target tidak terlihat oleh node mana pun sampai control plane menetapkannya.

## Penjadwalan Node dan Secret

`DeployProject` dipin ke satu node saat deployment dibuat (`AgentNodeSchedulerService`): node harus `auth_status = active`, `desired_state = active`, status `ready`/`busy`/`degraded`, protocol revision didukung, heartbeat lebih baru dari `sakala.agent.offline_after_seconds`, dan memiliki capability yang dibutuhkan. Project yang sudah punya deployment `succeeded` **hanya** diarahkan ke node yang sama (route Caddy dan container bersifat node-local; workload lama di node A tidak bisa dibersihkan dari node B) — bila node itu sedang tidak eligible, command menunggu tanpa target dan tidak pernah dipindahkan diam-diam; migrasi lintas node adalah flow eksplisit tersendiri. Untuk project baru dipilih node dengan reservasi paling sedikit (command Pending yang sudah dipin, Claimed, dan Running). `deployments.agent_node_id` dan `agent_commands.agent_node_id` ditulis bersama dalam transaction pembuatan deployment.

Bila tidak ada node eligible, command tetap `Pending` tanpa target dan tidak terlihat oleh node mana pun. `DeployProject` dibuat dengan `expires_at = null`: `command_timeout_seconds` adalah deadline eksekusi yang dipakai agent setelah claim, bukan TTL antrean, sehingga deployment yang menunggu node tidak kedaluwarsa diam-diam (yang akan meninggalkan deployment `queued` permanen dan mengunci kuota). Expiry/recovery yang deterministik menyusul bersama lease handling. `AssignPendingCommandsAction` (dijadwalkan setiap menit lewat `agent:assign-commands`, dan dipanggil setelah setiap heartbeat untuk node tersebut) mengunci command satu per satu dan menetapkan target; `deployments.agent_node_id` diisi hanya bila masih `null`.

Nilai `environment` pada `agent_commands.payload` tetap ciphertext `APP_KEY` di database. Dekripsi terjadi hanya di `AgentCommandPayloadMaterializer` saat resource disusun untuk node yang tercatat sebagai target; node lain tidak pernah menerima payload (poll tidak mengembalikannya, claim menjawab `409` tanpa payload).

## Secret dan Retention

Kolom token OAuth dan environment value memakai encrypted cast Laravel bila memang digunakan. Flow login GitHub App menyimpan user access token dan refresh token terenkripsi pada `oauth_accounts`; token tersebut hanya dipakai untuk memverifikasi akses user ke installation/repository. Installation token pendek untuk operasi service disimpan sementara di cache dalam bentuk terenkripsi dan tidak masuk database. Agent bearer token tidak disimpan; database hanya menyimpan SHA-256/HMAC hash dan prefix untuk identifikasi. Model menyembunyikan seluruh nilai sensitif dari serialization.

Log/event disimpan di database untuk MVP. Record report bersifat append-only (`updated_at` tidak ada); tidak ada endpoint update/delete dari API publik atau machine agent. Agent melakukan redaction sebelum pengiriman dan API mengulang redaction pada message serta metadata sebagai defense-in-depth sebelum persistensi dan broadcast.

Kebijakan retensi log fase pilot didefinisikan secara lengkap di [Pilot Log Retention Policy](LOG_RETENTION.md). Selama fase pilot, durasi retensi default adalah 7 hari (`SAKALA_LOG_RETENTION_DAYS=7`). Pembersihan data log dan event dijalankan oleh scheduler harian atau manual melalui `php artisan pilot:prune-logs` yang menargetkan deployment berstatus terminal (`succeeded`, `failed`, `cancelled`) berdasarkan `finished_at`/`cancelled_at`, dengan fallback `created_at` untuk record legacy. Query pembersihan memanfaatkan index timestamp terminal pada tabel `deployments` dan foreign key `deployment_id` pada `deployment_logs` serta `deployment_events`. Jangan membuat pencarian tanpa batas atas; endpoint timeline/log harus memakai cursor pagination dan urutan sequence. Evaluasi partitioning PostgreSQL atau object storage akan dilakukan sebelum transisi ke volume produksi.

## Data Lokal

Seeder hanya berjalan pada environment `local` dan `testing` serta aman dijalankan ulang:

```bash
php artisan migrate:fresh --seed
```

Fixture menyediakan user demo, OAuth account, dua project, node lokal, deployment sukses, command pending, event, log, environment variable, dan audit event. Tidak ada credential produksi di dalam fixture.
