# Database

Sakala API memakai PostgreSQL sebagai source of truth untuk metadata control plane. Schema MVP sengaja tidak memasukkan workspace/team, custom domain, managed database, atau storage karena fitur tersebut belum termasuk kontrak MVP.

## Identitas

`projects`, `deployments`, `agent_nodes`, dan `agent_commands` memakai UUIDv7. Identifier ini aman untuk diekspos melalui API, kompatibel dengan tipe `Uuid` pada Sakala Agent, dan lebih ramah terhadap B-tree dibanding UUID acak.

Tabel append-only berukuran besar seperti `deployment_events`, `deployment_logs`, dan `audit_events` memakai bigint berurutan. ID internal tersebut tidak menjadi kontrak publik.

## Tabel MVP

| Tabel | Tanggung jawab |
| --- | --- |
| `users` | Identitas console, role, onboarding, dan waktu login terakhir. |
| `oauth_accounts` | Identitas provider OAuth dan token terenkripsi, terpisah dari user. |
| `github_installations` | Entity global GitHub App installation, metadata akun GitHub, dan status akses repository. |
| `github_installation_user` | Relasi user Sakala dengan installation GitHub yang telah diverifikasi. |
| `github_webhook_deliveries` | Delivery ID webhook GitHub untuk pemrosesan lifecycle yang idempoten. |
| `projects` | Metadata repository, generated domain, dan status runtime. |
| `environment_variables` | Key dan value terenkripsi per project. |
| `deployments` | Satu attempt deployment, snapshot source yang dijalankan, node target, resource yang diterapkan agent, dan status finalisasi. |
| `agent_nodes` | Identitas runtime node, token hash, capability, protocol revision, desired lifecycle state, dan heartbeat terakhir. |
| `agent_commands` | Durable command queue antara API dan agent. |
| `agent_command_reports` | Event/log append-only untuk command tanpa deployment (InspectProject, CleanupRuntime, DrainNode, ResumeNode). |
| `agent_node_control_requests` | Record idempotency operasi admin pada runtime node (drain, resume); terpisah dari `project_control_requests` yang project-scoped. |
| `deployment_events` | Timeline state yang dilaporkan agent. |
| `deployment_logs` | Output redacted dari build/runtime. |
| `audit_events` | Jejak tindakan sensitif oleh user, agent, atau sistem. |
| `usage_signal_records` | Sinyal agregat penggunaan dan abuse (deployment attempts, agent failures, rejected limits, dll.) untuk observabilitas pilot. Append-only, diproses secara berkala oleh scheduler. |

Project dimiliki langsung oleh user selama MVP. Project dari GitHub App menyimpan installation UUID dan repository ID; project URL publik tidak memerlukan installation. Model workspace/team baru boleh ditambahkan setelah ownership dan policy multi-user disetujui.

## Status dan Enum

Nilai status disimpan sebagai string dan dicast melalui backed enum di `app/Enums`. Pendekatan ini menjaga type safety di aplikasi tanpa mengunci deployment schema pada native enum PostgreSQL yang lebih sulit diubah.

Nilai `AgentCommandType`, `AgentCommandStatus`, `DeploymentEventLevel`, dan `LogStream` harus tetap sama dengan `sakala-agent-protocol`. Perubahan kontrak wajib dilakukan pada kedua repository dan disertai test compatibility.

## Index Strategy

Index dibuat dari query path yang sudah diketahui:

- daftar project user: `(user_id, status, created_at)`;
- ringkasan runtime user: `(user_id, runtime_status)`;
- riwayat deployment: `(project_id, created_at)`;
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
- Transisi `Claimed -> Running` dilakukan API pada report pertama yang diterima dari node pemilik (agent mengirim event `command.claimed` segera setelah claim dan tidak memanggil endpoint lain). `started_at` diisi sekali di transaction report yang sama.
- Status deployment pada jalur agent digerakkan oleh event fase (`deployment.checkout.started` → `cloning`, `deployment.build.started` → `building`, `deployment.container.started` → `deploying`, `deployment.runtime.ready` → `routing`) di dalam transaction report yang sama, hanya maju dan tidak pernah mundur, sehingga retry event bersifat idempoten. `succeeded` hanya ditulis oleh `complete`; `failed` oleh `fail` atau control plane. `TransitionDeploymentAction` menerima state terminal dari state aktif mana pun karena laporan agent bersifat otoritatif.
- Saat `complete` DeployProject membawa `finalization_deferred = true`, API membuat `StopProject` untuk setiap deployment `succeeded` yang lebih lama pada project dan node yang sama dengan `idempotency_key` deterministik (`deferred-finalization:{baru}:{lama}`), sehingga retry completion tidak menggandakan command dan deployment baru tidak pernah menjadi target.

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
