# Database

Sakala API memakai PostgreSQL sebagai source of truth untuk metadata control plane. Schema sengaja belum memasukkan custom domain, managed database, atau storage karena fitur tersebut belum termasuk kontrak MVP.

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
| `projects` | Metadata repository, generated domain, status runtime, deployment yang sedang melayani atau tidur (`active_deployment_id`), kapan router terakhir melayani request (`last_request_at`, dari heartbeat), jumlah replika untuk deployment berikutnya (`replicas`, default 1), dan hasil inspeksi stack terakhir (`inspection`, `inspection_status`, `inspection_error_code`, `inspected_at`). |
| `environment_variables` | Key dan value terenkripsi per project. |
| `retired_project_slugs` | Slug project yang sudah di-purge. Hanya slug dan waktunya, dan tidak pernah dihapus, supaya alamat lama tidak bisa dipakai project lain. |
| `deployments` | Satu attempt deployment, snapshot source yang dijalankan, replika yang diminta (`replicas`) dan yang dilaporkan node (`replicas_desired`, `replicas_ready`), node target, resource yang diterapkan agent, status finalisasi, dan keterangan kegagalan (`failure_code`, `failure_summary`, `failure_detail`, `failure_stage`). |
| `agent_nodes` | Identitas runtime node, token hash, capability, protocol revision, desired lifecycle state, dan heartbeat terakhir. |
| `agent_commands` | Durable command queue antara API dan agent. |
| `agent_command_reports` | Event/log append-only untuk command tanpa deployment (InspectProject, CleanupRuntime, DrainNode, ResumeNode). |
| `agent_node_control_requests` | Record idempotency operasi admin pada runtime node (drain, resume, cleanup); terpisah dari `project_control_requests` yang project-scoped. |
| `deployment_events` | Timeline state yang dilaporkan agent. |
| `deployment_logs` | Output redacted dari build/runtime. |
| `audit_events` | Jejak tindakan sensitif oleh user, agent, atau sistem. |
| `workspaces` | Pemilik project: satu workspace pribadi per user (`personal_user_id` unik) dan workspace tim. `slug` unik untuk keduanya. `limit_profile_id` opsional. |
| `workspace_members` | Anggota workspace dengan peran `owner`, `member`, atau `viewer`. Unik per workspace dan user; ikut terhapus bersama workspace atau user. |
| `workspace_invitations` | Undangan: email (huruf kecil), peran, `status` (`pending`, `accepted`, `declined`, `revoked`), hash SHA-256 token, pengundang, masa berlaku, dan `closed_at`. Diterima, ditolak, dicabut, atau diganti berarti barisnya ditutup, bukan dihapus, supaya tautannya bisa menjelaskan kenapa tidak berlaku. `workspaces:prune-invitations` menghapus setiap baris setelah `SAKALA_WORKSPACE_INVITATION_RETENTION_HOURS` lewat dari masa berlakunya. |
| `limit_profiles` | Profil batas yang bisa dipakai ulang: jumlah project, container berjalan, deployment berjalan, memory dan CPU per project, `max_builds_per_hour` (deployment per 60 menit, `null` mengikuti `SAKALA_PROJECT_MAX_BUILDS_PER_HOUR`), `idle_sleep_minutes` (`null` mengikuti `SAKALA_PROJECT_IDLE_SLEEP_MINUTES`, `0` tidak pernah tidur), dan `max_replicas` (`null` berarti 1 replika per project). Tidak bisa dihapus selama dipakai workspace. |
| `legal_acceptances` | Versi dokumen yang disetujui atau diketahui tiap user, dengan waktunya. Append-only, unik per user, dokumen, dan versi; index itu juga melayani pembacaan per user. Ikut terhapus bersama user. |
| `usage_signal_records` | Sinyal agregat penggunaan dan abuse (deployment attempts, agent failures, rejected limits, dll.) untuk observabilitas pilot. Append-only, diproses secara berkala oleh scheduler. |

Project dimiliki workspace (`projects.workspace_id`), dan `projects.user_id` adalah pembuatnya. Keduanya nullable hanya untuk project yang sudah dihapus dan menunggu purge setelah akun atau timnya hilang; foreign key keduanya `RESTRICT`, jadi project hidup menahan penghapusan workspace dan user. Migrasi `move_projects_into_workspaces` membuat workspace pribadi untuk setiap user yang sudah ada (slug sama dengan username) dalam batch per 500 user, lalu memindahkan project dengan satu `UPDATE`; rollback ditolak setelah ada tim. Project dari GitHub App menyimpan installation UUID dan repository ID; project URL publik tidak memerlukan installation. Lihat [Workspace](API_CONVENTIONS.md#workspace).

## Status dan Enum

Nilai status disimpan sebagai string dan dicast melalui backed enum di `app/Enums`. Pendekatan ini menjaga type safety di aplikasi tanpa mengunci deployment schema pada native enum PostgreSQL yang lebih sulit diubah.

Nilai `AgentCommandType`, `AgentCommandStatus`, `DeploymentEventLevel`, dan `LogStream` harus tetap sama dengan `sakala-agent-protocol`. Perubahan kontrak wajib dilakukan pada kedua repository dan disertai test compatibility.

## Index Strategy

Index dibuat dari query path yang sudah diketahui:

- daftar project workspace dan jumlah project untuk kuota: `(workspace_id, created_at)` pada `projects`;
- daftar project lama per pembuat: `(user_id, status, created_at)`;
- cek peran di setiap policy: unique `(workspace_id, user_id)` pada `workspace_members`; workspace milik seorang user dan jumlah tim yang ia miliki: `(user_id, role)`;
- workspace pribadi seorang user: unique `personal_user_id` pada `workspaces`;
- undangan untuk email pemanggil: `(email)` pada `workspace_invitations`; satu undangan tertunda per alamat: partial unique `(workspace_id, email) WHERE status = 'pending'`, karena baris yang sudah tertutup untuk alamat yang sama boleh lebih dari satu sampai dibersihkan; lookup token: unique `token_hash`;
- project yang ikut auto-deploy pada satu repository: `(github_repository_id, auto_deploy)` pada `projects`;
- daftar notifikasi dan jumlah belum dibaca: `(notifiable_type, notifiable_id, created_at)` dan `(notifiable_type, notifiable_id, read_at)`; retensi: `(created_at)`;
- ringkasan runtime user: `(user_id, runtime_status)`;
- riwayat deployment: `(project_id, created_at)`. Index yang sama melayani batas build per jam, yang menghitung deployment dengan `project_id IN (project workspace, termasuk yang dihapus)` dan `created_at` di dalam jendela 60 menit di bawah row lock workspace, jadi tidak perlu index baru;
- deployment terbaru tiap project di daftar project: satu query tambahan per halaman, dengan `(project_id, status)` atau unique `(project_id, sequence)` yang sudah ada, jadi tidak perlu index baru. Lihat [Deployment Terbaru](#deployment-terbaru);
- alasan penangguhan di daftar project: satu query tambahan per halaman lewat `Project::latestSuspendRequest`, dengan `(project_id, action)` yang sudah ada pada `project_control_requests`, jadi tidak perlu index baru;
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
- Jeda tidur otomatis setelah sleep yang gagal dihitung dari riwayat `agent_commands`, tanpa kolom baru: satu query agregat per batch 100 project calon (`count` dan `max(failed_at)` per `deployment_id` untuk `SleepProject` idle yang `Failed` tanpa lifecycle command `Succeeded` sesudahnya), plus subquery `EXISTS` yang sama untuk `runtime_workload_not_found` di query sapuan dan daftar project admin. Keduanya memakai index `(deployment_id, status)` yang sudah ada. Riwayat ini sudah menjadi sumber kebenaran kegagalan, jadi kolom penghitung di `projects` hanya akan menjadi salinan yang bisa tertinggal.
- `agent_commands.lease_expires_at` diisi saat claim (execution timeout + grace) dan hanya dibaca oleh sweep `agent:expire-commands` lewat index `(status, lease_expires_at)`; `expires_at` tetap berarti batas ketersediaan saat `Pending`. Sweep mengunci command satu per satu dan memeriksa ulang deadline di bawah lock supaya completion yang mendahului tidak ditimpa.
- Transisi `Claimed -> Running` dilakukan API pada report pertama yang diterima dari node pemilik (agent mengirim event `command.claimed` segera setelah claim dan tidak memanggil endpoint lain). `started_at` diisi sekali di transaction report yang sama.
- Status deployment pada jalur agent digerakkan oleh event fase (`deployment.checkout.started` → `cloning`, `deployment.analysis.started` → `analyzing`, `deployment.build.started` → `building`, `deployment.container.started` → `deploying`, `deployment.health.started` → `health_checking`, `deployment.route.started` → `routing`) di dalam transaction report yang sama, hanya maju dan tidak pernah mundur, sehingga retry event bersifat idempoten. `succeeded` hanya ditulis oleh `complete`; `failed` oleh `fail` atau control plane. `TransitionDeploymentAction` menerima state terminal dari state aktif mana pun karena laporan agent bersifat otoritatif.
- `projects.active_deployment_id` menunjuk deployment yang sedang melayani. FK nullable ke `deployments` dengan `nullOnDelete`, dan hanya ditulis di bawah row lock project: deployment `succeeded` mengisinya bila sequence-nya tidak lebih lama dari yang sekarang (completion terlambat tidak memundurkan pointer), restart atau wake pada workload terkini mengisinya, stop atau sleep karena suspend yang sukses pada workload terkini mengosongkannya, dan sleep karena sepi mempertahankannya karena container-nya disimpan node. Deployment gagal tidak menyentuhnya, sehingga redeploy yang gagal tidak menghapus fakta bahwa versi lama masih melayani. Pointer disimpan, bukan diturunkan, karena fakta "sudah dihentikan" tidak bisa dibaca dari status deployment dan daftar deployment serta heartbeat perlu membacanya tanpa query per baris.
- Heartbeat yang membawa `crashed_details` menyamakan `runtime_status` dalam transaction heartbeat. Pembacaan pertama tanpa lock menentukan apakah ada perubahan; hanya bila ada, deployment lalu project di-lock berurutan menurut `id` dan kondisinya diperiksa ulang. Urutan deployment sebelum project sama dengan `TransitionDeploymentAction`, sehingga heartbeat dan command yang selesai tidak bisa saling deadlock. Laporan yang sama tidak menulis apa pun.
- Membatalkan deployment mengikuti urutan lock lawannya supaya tidak bisa deadlock. Command `Pending` memakai urutan claim: deployment dikunci dulu, lalu `UPDATE ... WHERE status = 'Pending'` yang kalah bila node sudah mengklaim, dan alurnya turun ke jalur berikut. Command `Claimed`/`Running` memakai urutan complete dan fail: command dulu, lalu deployment, dan hanya menulis `cancel_requested_at` di keduanya. `agent_commands.cancel_requested_at` adalah yang dibaca poll; `deployments.cancel_requested_at` adalah yang dibaca console dan `TransitionDeploymentAction`.
- `redeploy_required` membandingkan `deployments.config_fingerprint` milik deployment aktif dengan fingerprint konfigurasi project saat ini (`DeploymentConfigFingerprint`, satu-satunya definisi "relevan untuk deploy"): `branch`, `effective_port`, `build_command`, `start_command`, `root_directory`, `replicas` project, dan `projects.environment_fingerprint`. `name`, `thumbnail_url`, `auto_deploy`, `region`, dan `is_secret` tidak ikut. Fingerprint project dihitung saat dibaca dari kolom baris itu sendiri tanpa query, jadi tidak menambah query per project; deployment aktif dibaca lewat relasi `activeDeployment` yang sama dengan `serving_port`.
- `projects.environment_fingerprint` (varchar 64, nullable, disembunyikan dari serialization) adalah HMAC-SHA256 berkunci `APP_KEY` atas JSON kanonik pasangan key dan nilai plaintext environment variable, urut menurut key. HMAC berkunci dipakai karena nilainya secret: hash biasa dari secret pendek bisa ditebak dari dump database. Ciphertext tidak pernah di-hash, karena berbeda di setiap penulisan, sehingga mengembalikan nilai yang sama menghasilkan fingerprint yang sama. Kolom ini dihitung ulang oleh action create, update, dan delete environment variable di dalam transaction-nya, setelah mengunci baris project (`lockForUpdate`) lebih dulu, supaya dua perubahan yang bersamaan tidak menyimpan fingerprint yang basi. Mengganti `APP_KEY` mengubah semua fingerprint environment dan memunculkan satu anjuran redeploy yang tidak perlu per project; ini dapat diterima karena nilai environment juga membutuhkan kunci itu.
- `deployments.config_fingerprint` (varchar 64, nullable, disembunyikan dari serialization) ditulis `CreateDeploymentAction` dari baris project yang terkunci, pada saat yang sama dengan snapshot payload. Project yang `environment_fingerprint`-nya masih `null` (dibuat sebelum kolom ini ada) dihitung dan disimpan dulu di situ, jadi project dan deployment sepakat sejak itu tanpa migrasi backfill.
- `projects.config_changed_at` hanya dibaca untuk deployment aktif yang tidak punya `config_fingerprint` (dibuat sebelum kolom itu ada): `redeploy_required` lalu membandingkannya dengan `created_at` deployment itu. Kolom ini hanya ditulis saat fingerprint konfigurasi berubah karena `PATCH` project, dan saat environment variable dibuat, dihapus, atau nilainya berubah. Presisinya detik dan perubahan yang dikembalikan tetap dianggap berubah: untuk deployment lama, anjuran redeploy yang berlebihan lebih murah daripada yang terlewat.
- Baris project yang di-soft delete tetap dibaca jalur agent (`withTrashed`) supaya `StopProject` dan attempt yang masih berjalan bisa selesai.
- `projects.last_request_at` hanya maju, ditulis heartbeat node yang melayani deployment aktif dalam satu `UPDATE ... CASE` per heartbeat. Kolom ini tidak diberi index: `projects:sleep-idle` menyaring project `running` yang jumlahnya dibatasi kuota container, lalu memeriksa kolom ini per baris.
- `projects:purge-deleted` mengunci baris project lalu memeriksa ulang di dalam transaction bahwa tidak ada command yang belum selesai, deployment aktif, atau workload yang belum berhenti. Jalur agent mengunci baris yang sama sebelum mengubah workload, jadi command baru tidak bisa muncul di antara pemeriksaan dan penghapusan. Foreign key menghapus sisanya: `deployment_logs`, `deployment_events`, `agent_command_reports`, `environment_variables`, dan `project_control_requests` ikut terhapus, sedangkan `agent_commands` dan `deployments` yang memakai `restrictOnDelete` dihapus lebih dulu oleh perintah ini. Pencarian kandidat memindai `projects` sekali sehari, dan tabelnya kecil karena kuota per user, jadi `deleted_at` tidak diberi index.
- `deployment.idempotency_key` untuk push webhook adalah `github-push:{delivery}`, di atas `github_webhook_deliveries` yang sudah mengunci pengiriman ulang.
- Saat `complete` DeployProject membawa `finalization_deferred = true`, API membuat `StopProject` untuk setiap deployment `succeeded` yang lebih lama pada project dan node yang sama dengan `idempotency_key` deterministik (`deferred-finalization:{baru}:{lama}`), sehingga retry completion tidak menggandakan command dan deployment baru tidak pernah menjadi target.

## Keterangan Kegagalan Deployment

`deployments.failure_detail` (varchar 500, nullable) menyimpan pesan `fail` dari agent setelah dibersihkan, diredaksi, lalu dibatasi. Sebelumnya pesan ini hanya ada di `agent_commands.error_message`. `deployments.failure_stage` (varchar 32, nullable) menyimpan `DeploymentStatus` saat kegagalan terjadi, sebelum status berubah menjadi `failed`; sebelumnya tahap hanya bisa diturunkan dari `deployment_events`. Keduanya berada di `deployments` karena `pilot:prune-logs` menghapus event dan log tetapi tidak menyentuh baris deployment.

Migrasi `2026_11_05_143109` dan `2026_11_05_143110` hanya menambah kolom nullable, jadi kode lama tetap berjalan. Baris lama tidak diisi ulang untuk `failure_detail` dan `failure_stage`, dan API menyajikannya sebagai `null`. Migrasi kedua mengisi `active_deployment_id` dari deployment `succeeded` terbaru tiap project, kecuali project `stopped` atau workload yang punya `StopProject`/`SleepProject` sukses. Project `failed` yang ternyata masih punya deployment yang melayani dikembalikan ke `running`; heartbeat berikutnya mengoreksinya bila keadaan sebenarnya berbeda. Pada SQLite, `down` migrasi itu gagal bila tabel sudah berisi data karena SQLite harus membuat ulang tabel `projects`; `down` ditujukan untuk PostgreSQL.

`deployments.crash_notified_at` mencegah notifikasi ganda untuk crash yang sama dan dikosongkan saat agent berhenti melaporkan workload itu.

`deployments.container_port` menyimpan port yang dikirim ke agent saat deployment dibuat, sumber `serving_port`. Migrasinya mengisi baris lama dari payload `DeployProject`; yang tanpa command tetap `null`.

`deployments.replicas` (smallint, default 1) menyimpan replika yang diminta saat deployment dibuat, seperti `container_port`, dan menjadi dasar hitungan container berjalan workspace serta wake. `deployments.replicas_desired` dan `deployments.replicas_ready` (smallint, nullable) ditulis heartbeat node yang menjalankan deployment aktif, dalam satu `UPDATE ... CASE` per heartbeat. Laporan replika disimpan per deployment, bukan di `projects`, supaya deployment baru otomatis mulai dari "belum dilaporkan" tanpa jalur deploy, sleep, atau stop perlu mengosongkannya. Hitungan container berjalan membaca replika deployment aktif lewat subquery dan replika terbanyak deployment yang berjalan lewat `withMax`, dalam satu query per workspace; tidak ada index baru karena query itu sudah dibatasi `workspace_id` dan index `deployments (project_id, status)` yang ada. Migrasi `2026_11_18_000001` hanya menambah kolom berdefault 1 atau nullable, jadi setiap baris lama tetap satu container dan tidak ada backfill.

Migrasi `2026_11_19_000002` menambah `projects.environment_fingerprint` dan `deployments.config_fingerprint`, keduanya varchar 64 nullable tanpa index karena tidak ada query yang menyaring dengannya. Tidak ada backfill: lihat [Concurrency dan Idempotensi](#concurrency-dan-idempotensi) untuk cara keduanya terisi dan fallback `config_changed_at`.

Migrasi `2026_11_06_00000x` hanya menambah kolom nullable atau berdefault dan satu tabel baru, jadi kode lama tetap berjalan dan tidak ada backfill.

## Deployment Terbaru

`Project::latestDeployment` adalah `hasOne` dengan `orderByDesc('sequence')->limit(1)`. Saat eager load, Laravel mengubah limit itu menjadi `row_number()` per `project_id`, dan yang dibaca hanya deployment milik project di halaman itu, bukan seluruh tabel. Halaman detail membaca satu baris lewat unique `(project_id, sequence)`.

`latestOfMany('sequence')` tidak bisa dipakai karena Laravel selalu menambahkan `MAX(id)` sebagai penentu seri, sedangkan PostgreSQL tidak punya `max(uuid)`. SQLite menerimanya, jadi kesalahan ini hanya terlihat di PostgreSQL.

Diukur dengan `EXPLAIN (ANALYZE, BUFFERS)` di PostgreSQL 18 dengan 258.800 baris deployment: 3 ms untuk 6 project (ukuran maksimal satu halaman) dengan 200 deployment per project, dan 155 ms untuk 6 project dengan 10.000 deployment per project. Biayanya mengikuti riwayat project di halaman itu. Bila riwayat sebesar itu menjadi hal biasa, pilihan berikutnya adalah pointer tersimpan seperti `active_deployment_id`.

`deployments.commit_message` (varchar 500, nullable) menyimpan baris pertama pesan commit dari GitHub, yang didapat saat deployment dibuat, tanpa karakter kontrol. Satu-satunya penulisnya adalah `CreateDeploymentAction`, jadi batasnya berlaku untuk webhook push maupun deploy manual, dan agent tidak dibutuhkan. Baris lama yang pernah menyimpan beberapa baris dari webhook tidak ditulis ulang.

## Node Lifecycle dan Protocol

`agent_nodes.status` adalah state yang dilaporkan agent lewat heartbeat; `agent_nodes.desired_state` adalah intent control plane (`active`, `draining`, `drained`, `maintenance`) yang dibaca agent melalui `GET /api/agent/v1/node-state` saat bootstrap. Keduanya sengaja dipisah: `ChangeAgentNodeLifecycleAction` mengunci node dan menulis desired state dalam transaction yang sama dengan pembuatan `DrainNode`/`ResumeNode`, sedangkan status hanya ditulis oleh heartbeat — kecuali `offline`, yang diturunkan `agent:mark-offline-nodes` (lock per node, dilewati bila heartbeat masuk di antara scan dan lock) memakai index `(status, last_seen_at)` yang sudah ada. Index `agent_node_control_requests (agent_node_id, action)` dan `(actor_type, actor_id)` mengikuti pola `project_control_requests`.

`agent_nodes.protocol_version` diambil dari `metadata.protocol_version` heartbeat dan dibandingkan dengan `sakala.agent.supported_protocol_versions`. Node dengan revisi yang tidak didukung (atau belum pernah heartbeat) tetap bisa online tetapi tidak eligible menerima command. Tidak ada index tambahan untuk kedua kolom ini: jumlah node pada pilot kecil dan pemilihan node sudah memakai index `(status, last_seen_at)`.

Command dengan tipe *pinned* (`InspectProject`, `DeployProject`, `CleanupRuntime`, `DrainNode`, `ResumeNode`) hanya ditawarkan ke node yang tercatat pada `agent_node_id`; command pinned tanpa target tidak terlihat oleh node mana pun sampai control plane menetapkannya.

## Penjadwalan Node dan Secret

`DeployProject` dipin ke satu node saat deployment dibuat (`AgentNodeSchedulerService`): node harus `auth_status = active`, `desired_state = active`, status `ready`/`busy`/`degraded`, protocol revision didukung, heartbeat lebih baru dari `sakala.agent.offline_after_seconds`, dan memiliki capability yang dibutuhkan. Project yang sudah punya deployment `succeeded` **hanya** diarahkan ke node yang sama (route Caddy dan container bersifat node-local; workload lama di node A tidak bisa dibersihkan dari node B) — bila node itu sedang tidak eligible, command menunggu tanpa target dan tidak pernah dipindahkan diam-diam; migrasi lintas node adalah flow eksplisit tersendiri. Untuk project yang belum punya workload, kandidat dibatasi ke region project (`projects.region`, atau `limit_profiles.default_region` workspace-nya; region node adalah `agent_nodes.region_override` atau `metadata.runtime.region` heartbeat) lalu diurutkan menurut kapasitas: slot container kosong yang cukup, memory tersedia, slot kosong, lalu reservasi paling sedikit (command Pending yang sudah dipin, Claimed, dan Running). Deployment yang dipin tetapi belum selesai mengurangi slot dan memory perkiraan node, karena heartbeat belum memperlihatkannya. Region dan kapasitas dibaca dari `metadata` node di PHP, bukan lewat kolom ber-index: jumlah node kecil dan kandidat sudah dimuat untuk pengecekan capability. Lihat [Region dan Penempatan](API_CONVENTIONS.md#region-dan-penempatan). `deployments.agent_node_id` dan `agent_commands.agent_node_id` ditulis bersama dalam transaction pembuatan deployment.

Bila tidak ada node eligible, command tetap `Pending` tanpa target dan tidak terlihat oleh node mana pun. `DeployProject` dibuat dengan `expires_at = null`: `command_timeout_seconds` adalah deadline eksekusi yang dipakai agent setelah claim, bukan TTL antrean, sehingga deployment yang menunggu node tidak kedaluwarsa diam-diam (yang akan meninggalkan deployment `queued` permanen dan mengunci kuota). Expiry/recovery yang deterministik menyusul bersama lease handling. `AssignPendingCommandsAction` (dijadwalkan setiap menit lewat `agent:assign-commands`, dan dipanggil setelah setiap heartbeat untuk node tersebut) mengunci command satu per satu dan menetapkan target; `deployments.agent_node_id` diisi hanya bila masih `null`.

Nilai `environment` pada `agent_commands.payload` tetap ciphertext `APP_KEY` di database. Dekripsi terjadi hanya di `AgentCommandPayloadMaterializer` saat resource disusun untuk node yang tercatat sebagai target; node lain tidak pernah menerima payload (poll tidak mengembalikannya, claim menjawab `409` tanpa payload).

## Sakala Edge

`edge_tokens` menyimpan kredensial baca-saja setiap instance Edge sebagai HMAC `APP_KEY` (`token_hash`, unik) dengan `token_prefix` untuk dikenali; plaintext hanya dicetak sekali oleh `edge:token`. Index unik `token_hash` adalah satu-satunya jalur baca (setiap request Edge), dan `name` unik karena `edge:token {name}` mengganti token per nama. `revoked_at` mencabut tanpa menghapus baris.

Kolom pemantauan Edge (`2026_11_19_000003`, hanya menambah kolom): `last_used_at` (sudah ada sejak tabel dibuat, dipakai sebagai waktu terakhir terlihat dan ditampilkan API admin sebagai `last_seen_at`), `last_version`, `last_applied_revision`, dan `last_status` adalah request terakhir yang dicatat beserta isi header Edge-nya (`null` berarti tidak diketahui), ditulis hanya bila ada nilai yang berubah atau penulisan terakhir sudah lebih dari 60 detik. `status_since` adalah kapan `last_status` terakhir berganti nilai. `revision_mismatch_since` dan `unhealthy_since` hanya ditulis oleh `edge:check-health` di bawah row lock: kapan revisi yang disajikan Edge mulai berbeda dari revisi tabel, dan keadaan peringatan (`null` berarti sehat). Tidak ada index tambahan: command membaca semua baris, dan jumlahnya hanya sebanyak instance Edge. `last_used_at` sengaja tidak diganti namanya: container API rilis sebelumnya masih menulis kolom itu selama rolling deploy, dan rename akan membuat request Edge mereka gagal. Lihat [Pemantauan](EDGE_API.md#pemantauan).

`agent_nodes.edge_address_override` dan `region_override` adalah nilai admin yang menang atas `metadata.runtime` heartbeat. Tabel route Edge (`GET /api/edge/v1/routes`) disusun dari `ProjectWorkloadService::retainedWorkloads()` dalam empat query: deployment aktif yang berjalan di node, deployment `succeeded` terbaru (lewat `NOT EXISTS` deployment lebih baru, memakai unique index `(project_id, sequence)` yang sudah ada) bersama tipe command lifecycle sukses terakhirnya (subquery per deployment di index `agent_commands (deployment_id, status)`), project, dan node. Hasilnya di-cache singkat (`SAKALA_EDGE_ROUTES_CACHE_SECONDS`), bukan diinvalidasi per perubahan. Lihat [EDGE_API.md](EDGE_API.md).

## Secret dan Retention

Kolom token OAuth dan environment value memakai encrypted cast Laravel bila memang digunakan. Flow login GitHub App menyimpan user access token dan refresh token terenkripsi pada `oauth_accounts`; token tersebut hanya dipakai untuk memverifikasi akses user ke installation/repository. Installation token pendek untuk operasi service disimpan sementara di cache dalam bentuk terenkripsi dan tidak masuk database. Agent bearer token tidak disimpan; database hanya menyimpan SHA-256/HMAC hash dan prefix untuk identifikasi. Model menyembunyikan seluruh nilai sensitif dari serialization.

Log/event disimpan di database untuk MVP. Record report bersifat append-only (`updated_at` tidak ada); tidak ada endpoint update/delete dari API publik atau machine agent. Agent melakukan redaction sebelum pengiriman dan API mengulang redaction pada message serta metadata sebagai defense-in-depth sebelum persistensi dan broadcast.

Kebijakan retensi log fase pilot didefinisikan secara lengkap di [Pilot Log Retention Policy](LOG_RETENTION.md). Selama fase pilot, durasi retensi default adalah 7 hari (`SAKALA_LOG_RETENTION_DAYS=7`). Pembersihan data log dan event dijalankan oleh scheduler harian atau manual melalui `php artisan pilot:prune-logs` yang menargetkan deployment berstatus terminal (`succeeded`, `failed`, `cancelled`) berdasarkan `finished_at`/`cancelled_at`, dengan fallback `created_at` untuk record legacy. Query pembersihan memanfaatkan index timestamp terminal pada tabel `deployments` dan foreign key `deployment_id` pada `deployment_logs` serta `deployment_events`. Jangan membuat pencarian tanpa batas atas; endpoint timeline/log harus memakai cursor pagination dan urutan sequence. Evaluasi partitioning PostgreSQL atau object storage akan dilakukan sebelum transisi ke volume produksi.

## Admin Console

Migrasi `2026_11_13_000001` hanya menambah satu kolom berdefault dan index, jadi kode lama tetap berjalan:

- `feedbacks.status` (varchar 32, default `open`) dengan index `(status, created_at)` untuk daftar masukan admin yang difilter status. Masukan lama menjadi `open`.
- `users (created_at)` untuk user baru dan deret pertumbuhan harian di overview.
- `agent_commands (agent_node_id, created_at)` untuk riwayat command satu node, terbaru dulu. Index `(agent_node_id, status, available_at)` yang ada melayani poll, bukan urutan waktu.
- `audit_events (created_at)` untuk daftar audit tanpa filter pelaku, subjek, atau aksi. Filter itu memakai index `(actor_type, actor_id, created_at)`, `(subject_type, subject_id, created_at)`, dan `(action, created_at)` yang sudah ada.

Query overview lain sudah tertutup index yang ada: deployment per status dan rentang memakai `(status, created_at)`, kegagalan per kode `(failure_code, created_at)`, dan project serta workspace dihitung utuh karena tabelnya kecil. Hasil overview di-cache 60 detik. Hari dalam deret dihitung di SQL dengan menggeser `created_at` (UTC) tujuh jam, karena WIB tidak punya DST. Kategori kegagalan tidak disimpan: kode yang berbeda dikelompokkan di SQL lalu diklasifikasi di PHP, sehingga mapping kode ke kategori hanya ada di `DeploymentFailureClassifier`.

`DeleteLimitProfileAction` mengunci baris profil (`FOR UPDATE`) sebelum menghitung workspace yang memakainya, dan `AssignLimitProfileAction` mengambil shared lock pada baris profil yang sama, sehingga profil tidak bisa dipasang di antara pengecekan dan penghapusan. Foreign key `restrictOnDelete` tetap menjadi pengaman terakhir. `ChangeUserRoleAction` mengunci semua baris admin sebelum menurunkan peran, jadi dua admin yang saling menurunkan bersamaan tidak bisa meninggalkan Sakala tanpa admin.

## Data Lokal

Seeder hanya berjalan pada environment `local` dan `testing` serta aman dijalankan ulang:

```bash
php artisan migrate:fresh --seed
```

Fixture menyediakan user demo, OAuth account, dua project, node lokal, deployment sukses, command pending, event, log, environment variable, dan audit event. Tidak ada credential produksi di dalam fixture.
