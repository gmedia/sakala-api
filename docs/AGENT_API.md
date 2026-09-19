# Agent API

Kontrak machine protocol antara `sakala-api` dan `sakala-agent`. Dokumen ini
menjelaskan apa yang **diimplementasikan API**; wire type normatif berada di
`sakala-agent` (`crates/sakala-agent-protocol`, `docs/AGENT_API.md`,
`docs/COMMAND_LIFECYCLE.md`, `docs/COMPATIBILITY.md`). Target kompatibilitas
saat ini adalah **sakala-agent v0.1.0, protocol revision 4**.

Machine protocol berada di `/api/agent/v1/*`, sebuah route family yang
diversikan terpisah dari app API `/api/v1/*`. `v1` pada URL adalah versi
route family API, **bukan** protocol revision agent: saat ini family ini
melayani protocol revision **4**, dan admission revisi digate lewat
`metadata.protocol_version` pada heartbeat (`SAKALA_AGENT_SUPPORTED_PROTOCOL_VERSIONS`),
sehingga revisi protocol dapat berganti tanpa mengubah URL. Family yang sama
juga memuat endpoint admin node `/api/agent/v1/agents/*` (Sanctum, bukan
bearer agent) untuk provisioning, rotate, revoke, drain, resume, dan cleanup.
Perubahan endpoint, command type, atau wire field pada bagian machine harus
dilakukan bersama perubahan protocol Agent yang eksplisit; API tidak menambah
kontrak wire secara sepihak.

## Autentikasi

Setiap request machine memakai:

```text
Authorization: Bearer <agent-token>
X-Agent-Id: <agent_id>
Accept: application/json
```

- Token hilang, format bukan `Bearer`, `agent_id` tidak dikenal, atau hash tidak
  cocok → `401`.
- Agent dengan `auth_status` bukan `active` → `403`. Agent memperlakukan ini
  sebagai kegagalan bootstrap (fail-closed).

Lihat [Autentikasi](AUTHENTICATION.md) untuk provisioning, rotasi, dan revoke.

## Endpoint

| Method | Path | Kegunaan |
| --- | --- | --- |
| `POST` | `/heartbeat` | Telemetri node dan status yang dilaporkan agent. |
| `GET` | `/node-state` | Desired lifecycle state yang harus dipulihkan agent saat bootstrap. |
| `GET` | `/commands` | Polling command `Pending` yang eligible untuk node. |
| `POST` | `/commands/{id}/claim` | Klaim atomik satu command. |
| `POST` | `/commands/{id}/repository-credential` | Lease credential repository sekali pakai untuk command yang sudah diklaim. |
| `POST` | `/commands/{id}/events` | Laporan event. |
| `POST` | `/commands/{id}/logs` | Laporan log. |
| `POST` | `/commands/{id}/complete` | Command selesai sukses. |
| `POST` | `/commands/{id}/fail` | Command gagal. |

`{id}` adalah UUID `agent_commands.id`. Pada agent v0.1.0 hanya `node-state`
yang memiliki urutan tetap: dipanggil sekali saat bootstrap dan harus berhasil
sebelum worker apa pun dimulai. Setelah itu heartbeat worker dan command
poller berjalan berkala dan **independen** — tidak ada jaminan request
pertama setelah bootstrap adalah `heartbeat`. Untuk setiap command urutannya
`commands` (poll) → `claim` → [`repository-credential`] → `events`/`logs` →
`complete` | `fail`.

Endpoint admin yang mengendalikan sisi control plane (provisioning, rotate,
revoke, drain, resume, cleanup, stop, suspend, reconcile) dijelaskan di
[Konvensi API](API_CONVENTIONS.md) dan [Autentikasi](AUTHENTICATION.md).

### Heartbeat

Body divalidasi sesuai payload yang dikirim binary v0.1.0 (`status`,
`hostname`, `runtime_network`, `capabilities`, `metadata.*`, `sent_at`);
`metadata.detail_counts` (ditambahkan agent setelah v0.1.0) dan
`startup_reconciliation.compatibility_issues` bersifat opsional tetapi
divalidasi bila ada. Batas body 256 KiB → `413`. Response
`AgentHeartbeatResource`; agent tidak membaca body. Payload v0.1.0 direplay
apa adanya oleh test dari `tests/Fixtures/agent-protocol-v4/heartbeat/`.

API menyimpan `metadata.protocol_version` ke kolom `agent_nodes.protocol_version`
dan membandingkannya dengan `sakala.agent.supported_protocol_versions`. Node
dengan revisi tidak didukung tetap tercatat online, tetapi tidak menerima
command apa pun. Heartbeat tidak pernah mengubah `desired_state`.

### Node state

```json
{ "data": { "desired_state": "active" } }
```

Nilai: `active`, `draining`, `drained`, `maintenance`. Agent memanggil endpoint
ini sekali sebelum polling dimulai dan berhenti bila gagal. Nilai berasal dari
`agent_nodes.desired_state` (intent control plane), bukan dari status yang
dilaporkan heartbeat.

Intent diubah admin lewat `POST /api/agent/v1/agents/{agent}/drain` dan
`/resume` (Sanctum, admin): `desired_state` disimpan dalam transaksi yang sama
dengan pembuatan `DrainNode`/`ResumeNode` (pinned ke node, `project_id`/
`deployment_id` null, payload `{}`), sehingga `node-state` tidak pernah
berbeda dari command yang akan diterima node. Saat draining/drained — baik
menurut intent maupun status yang dilaporkan — node hanya ditawari kedua
command itu; workload lain menunggu sampai node kembali `active`. Setelah
`DrainNode` selesai, agent sendiri berpindah ke `drained` ketika tidak ada
pekerjaan berjalan dan melaporkannya lewat heartbeat; `desired_state` tetap
`draining` sampai admin me-resume. `ResumeNode` yang gagal preflight
(`runtime_preflight_failed`) mengembalikan `desired_state` ke `drained`.
`capacity` pada result `ResumeNode` hanya telemetri, bukan izin melewati
batas node. `maintenance` belum dapat diminta lewat API.

Status `offline` tidak pernah dikirim agent; control plane menurunkannya bila
heartbeat lebih lama dari `SAKALA_AGENT_OFFLINE_AFTER_SECONDS`
(`agent:mark-offline-nodes`, tiap menit). Node offline tidak ditawari command
apa pun; heartbeat berikutnya memulihkan status yang dilaporkan.

### Polling

```json
{
  "data": [
    {
      "id": "…",
      "type": "DeployProject",
      "status": "Pending",
      "project_id": "…",
      "deployment_id": "…",
      "payload": { }
    }
  ]
}
```

Polling **tidak** memberi kepemilikan. Sebuah command ditawarkan ke node bila:

- node `auth_status = active`, protocol revision didukung, dan status yang
  dilaporkan bukan `offline`;
- untuk command workload: status yang dilaporkan `ready`/`busy`/`degraded`
  dan `desired_state = active`; `DrainNode`/`ResumeNode` juga ditawarkan saat
  draining/drained/maintenance;
- command `Pending`, `available_at <= now`, dan `expires_at` belum lewat;
- command terikat ke node (`agent_node_id = node`), atau belum terikat dan
  tipenya bukan *pinned* (`InspectProject`, `DeployProject`, `CleanupRuntime`,
  `DrainNode`, `ResumeNode` selalu pinned; command pinned tanpa target tidak
  terlihat oleh node mana pun sampai control plane menetapkannya);
- node memiliki capability yang dibutuhkan tipe command (lihat tabel);
- project tidak `suspended`, kecuali tipe yang tidak pernah menghidupkan atau
  mengekspos workload (`StopProject`, `SleepProject`, `HealthCheck`,
  `CleanupRuntime`, `DrainNode`, `ResumeNode`). `ReconcileWorkload` ikut
  diblokir karena payload-nya dapat meminta `desired_state = running` atau
  `restore_route`.

Command tanpa payload selalu diserialisasi sebagai `{}`. Hanya
`InspectProject`, `DeployProject`, `ReconcileWorkload`, dan `CleanupRuntime`
yang membawa payload; lifecycle command hanya membawa identitas.

| Command type | Capability yang dibutuhkan (salah satu) |
| --- | --- |
| `InspectProject` | `project-inspection` |
| `DeployProject` | `dockerfile-build`, `railpack-build` |
| `RestartProject`, `StopProject`, `SleepProject`, `WakeProject`, `HealthCheck`, `ReconcileWorkload`, `CleanupRuntime` | `docker-runtime` |
| `RefreshRoute` | `caddy-file-routing` |
| `DrainNode`, `ResumeNode` | — |

### DeployProject

Payload mengikuti `DeployProjectPayload` protocol v4:

```json
{
  "repository_url": "https://github.com/example/app.git",
  "commit_sha": "0123456789abcdef0123456789abcdef01234567",
  "repository_access": "public",
  "domain": "app.run.sakala.dev",
  "container_port": 3000,
  "builder": "auto",
  "environment": { "APP_ENV": "production" },
  "resources": { "memory_mb": 256, "cpu_millis": 500, "pids_limit": 128 },
  "timeouts": { "build_timeout_seconds": 600, "start_timeout_seconds": 120, "command_timeout_seconds": 900 },
  "log_bounds": { "max_line_length": 4096, "max_batch_lines": 500, "max_total_bytes": 10485760 }
}
```

- `repository_access` adalah `public` untuk project dari URL publik dan
  `temporary_credential` untuk project yang terhubung lewat GitHub App
  installation; untuk yang terakhir agent meminta credential lewat endpoint
  `repository-credential` sebelum checkout.
- `environment` dikirim **plaintext** hanya kepada node target. Di database
  nilainya tetap terenkripsi; node lain tidak pernah menerima payload ini.
  Map kosong diserialisasi sebagai `{}`.
- Node target dipilih control plane saat deployment dibuat (lihat
  [Database](DATABASE.md#penjadwalan-node-dan-secret)). Project yang sudah
  berjalan di suatu node hanya akan dideploy ulang ke node itu. Tanpa node
  eligible, command menunggu tanpa target dan tanpa `expires_at`, lalu
  ditetapkan oleh sweep `agent:assign-commands` atau heartbeat berikutnya.

Event fase dari agent menggerakkan status deployment (maju saja, retry aman):

| Event | `deployments.status` |
| --- | --- |
| `deployment.checkout.started` | `cloning` |
| `deployment.build.started` | `building` |
| `deployment.container.started` | `deploying` |
| `deployment.runtime.ready` | `routing` (+ `image_reference` dari `metadata.image`) |

`succeeded` hanya ditulis oleh `complete`; `failed` oleh `fail`. Result
`complete` dibaca sebagai `DeployProjectResult`:
`requested_resources`, `applied_resources` (disimpan ke
`deployments.applied_resources`), serta `finalization_deferred` dan
`finalization_deferred_reason` (`grace_elapsed` | `runtime_error`). Bila
deferred, API membuat `StopProject` idempoten untuk setiap deployment
`succeeded` lama pada project dan node yang sama — tidak pernah untuk
deployment baru. Completion pada deployment yang sudah ditutup control plane
(mis. kedaluwarsa) tetap `204`, dicatat di audit, dan tidak mengubah status.

### InspectProject

Dibuat saat project dibuat (`RequestProjectInspectionAction`) untuk preview
stack di console; pinned ke node ber-capability `project-inspection`,
`deployment_id` null, tanpa `expires_at`, idempoten per commit
(`inspect:{project}:{sha}`). Payload mengikuti `InspectProjectPayload`:

```json
{ "repository_url": "https://github.com/example/app.git", "commit_sha": "…", "repository_access": "public" }
```

Event `project.inspection.started`/`completed` dan log-nya disimpan sebagai
command report. Result `complete` dibaca sebagai `ProjectInspection`
(`repository_url`, `commit_sha`, `dockerfile_found`, `env_example_found`,
`compose_found`, `manifests[]`, `package_manager`, `railpack`) dan disimpan ke
`projects.inspection`; `fail` menyimpan `error_code` sebagai
`inspection_error_code`. Hanya command inspeksi terbaru untuk sebuah project
yang boleh menulis hasil — completion command yang sudah disusul diabaikan.
`detected_port` tidak diturunkan dari hasil karena `ProjectInspection` v4 tidak
memuat port.

### ReconcileWorkload dan CleanupRuntime

Keduanya hanya dibuat atas permintaan admin; API tidak pernah menyimpulkan
aksi mutatif dari state.

- `POST /api/v1/admin/projects/{project}/reconcile` → `ReconcileWorkload`
  pinned ke node yang menjalankan deployment `succeeded` terakhir, payload
  persis seperti diminta: `{ "desired_state": "running|stopped|missing",
  "actions": [restart_log_follower|cleanup_failed_candidate|restore_route] }`;
  `actions` kosong berarti hanya melaporkan drift. Ditolak `409` untuk project
  suspended (command akan ditahan poll), tanpa workload terlayani, bila
  deployment lain masih berjalan, atau bila reconciliation lain masih
  berjalan. Karena route Caddy bersifat per project, claim memeriksa ulang
  bahwa deployment target masih yang terkini; bila sudah disusul, command
  menjadi `Cancelled` (`reconcile_target_superseded`) dan claim dijawab `409`
  supaya `restore_route` tidak pernah menunjuk container lama. Result completion (`desired_state`,
  `actual_state`, `in_sync`, `drift_reason`, `actions_applied`) dicatat ke
  audit `project.reconcile_completed`; tidak ada command lanjutan otomatis.
- `POST /api/agent/v1/agents/{agent}/cleanup` → `CleanupRuntime` node-level
  dengan payload `{ "approved": true, "targets": [stale_workspaces|
  stale_images|stale_routes] }`. `approved` hanya ditulis control plane dan
  ditolak (`422`) bila dikirim client. Node harus aktif dan eligible; satu
  cleanup in-flight per node. Counter hasil (`cleaned_workspaces`,
  `cleaned_routes`, `reclaimed_image_bytes`) dicatat ke audit.

Keduanya menerima `Idempotency-Key` dengan semantik project/node control.

### Repository credential

Dipanggil agent setelah claim dan sebelum checkout, hanya bila payload
`InspectProject`/`DeployProject` memakai `repository_access:
"temporary_credential"`. Body `{}`. Response **tanpa** envelope `data`:

```json
{ "username": "x-access-token", "token": "ghs_…" }
```

Syarat, diperiksa di bawah row lock command **sebelum** token diminta ke
GitHub dan **sekali lagi** setelah token diterima, sebelum dikembalikan (state
bisa berubah selama I/O ke GitHub; token yang gagal revalidasi tidak pernah
diserahkan dan kedaluwarsa sendiri):

- node pemanggil adalah `agent_node_id` command; command `Claimed` atau
  `Running` — selain itu `409` dengan bentuk konflik standar;
- type `InspectProject`/`DeployProject` dan `repository_access =
  temporary_credential` — selain itu `422`;
- `payload.repository_url` sama dengan repository project (binding repository
  command tidak boleh bergeser), project masih terikat ke GitHub App
  installation yang `active` dengan repository yang sama, dan user pemilik
  project masih terhubung ke installation tersebut — selain itu `409`
  "GitHub App no longer has access…".

Token adalah installation token GitHub App yang dibatasi ke **satu
repository** (`repository_ids`) dengan permission **`contents:read`** saja,
berumur ±1 jam (ditentukan GitHub), tidak di-cache, tidak disimpan pada
command, dan tidak muncul di log maupun response lain. Setiap lease dicatat di
`audit_events` (`agent.repository_credential_leased`) tanpa token. Kegagalan
GitHub tidak menghasilkan credential parsial; agent menandai command gagal
dengan `repository_credential_unavailable`.

### Claim

Body `{}`. Claim adalah `UPDATE … WHERE status = 'Pending'` dalam transaction;
hanya satu node yang menang. Eligibility node divalidasi ulang dengan node yang
dibaca segar di bawah row lock (protocol, auth, status, desired state,
capability, scope). Sukses → `200` dengan resource command; gagal → `409`
dengan bentuk di bawah.

Claim memberi **lease**: `lease_expires_at = now + command_timeout_seconds
(dari payload, atau default config) + SAKALA_AGENT_LEASE_GRACE_SECONDS`.

### Lease expiry dan recovery

`agent:expire-commands` (tiap menit) menutup command yang ditinggalkan secara
deterministik, dengan lock per command dan pengecekan ulang sebelum menulis:

- `Pending` dengan `expires_at` lewat → `Expired`, `error_code =
  command_expired` (deployment terkait → `failed`, kategori `scheduling`).
  `DeployProject` dan `InspectProject` yang menunggu node dibuat tanpa
  `expires_at` sehingga tidak pernah kedaluwarsa di antrean.
- `Claimed`/`Running` dengan `lease_expires_at` lewat → `Expired`,
  `error_code = command_lease_expired` (deployment aktif → `failed`, kategori
  `timeout`; inspeksi → `failed`; `DrainNode`/`ResumeNode` hanya diaudit,
  `desired_state` tidak diubah).
- Deployment yang sudah terminal tidak pernah disentuh.

Setelah expired, `complete`/`fail` dari agent dijawab `409 {status:
"Expired"}` — agent memperlakukannya sebagai konflik terminal dan berhenti.
Agent sendiri menegakkan `command_timeout_seconds`, jadi lease normalnya
hanya kedaluwarsa bila node mati atau kehilangan koneksi.

### Claimed → Running

Agent tidak memanggil endpoint untuk transisi ini. Segera setelah claim, agent
mengirim event `command.claimed`; report pertama yang diterima dari node
pemilik menggeser command `Claimed → Running` dan mengisi `started_at` dalam
transaction yang sama.

### Events dan logs

Menerima satu objek (yang dipakai agent) atau batch `{ "events": [...] }` /
`{ "logs": [...] }`. Aturan idempotency, redaction, bounds (`413`/`422`), dan
budget kumulatif log dijelaskan di [Konvensi API](API_CONVENTIONS.md).

Untuk command yang memiliki `deployment_id`, report masuk ke timeline
deployment (`deployment_events`/`deployment_logs`) dan dibroadcast. Untuk
command tanpa deployment (`InspectProject`, `CleanupRuntime`, `DrainNode`,
`ResumeNode`), report disimpan di `agent_command_reports` dengan sequence per
command dan acknowledgement yang sama, tanpa broadcast.

Hanya node pemilik (`agent_node_id`) yang boleh melapor; node lain → `409`.

Log (bukan event) tetap diterima untuk `DeployProject` yang sudah `Succeeded`:
agent terus mengikuti output container (`docker logs --follow`) di bawah
identitas command deploy, juga setelah agent restart. Budget kumulatif
`max_total_bytes` tetap berlaku (`422` bila habis). Command `Failed`,
`Cancelled`, atau `Expired`, dan command lain yang terminal, menolak log
dengan `409`.

### Complete dan fail

- `complete`: body `{ "result": <json|null> }`. `Claimed`/`Running` → `Succeeded`
  (`204`). Sudah `Succeeded` → `204` (idempoten). `Failed`/`Cancelled`/`Expired`
  → `409`.
- `fail`: body `{ "error_code", "error_message" }`. `Claimed`/`Running` → `Failed`
  (`204`). Sudah `Failed` → `204`. `Succeeded` → `409`. `error_code` disanitasi ke
  `[A-Za-z0-9._-]` (≤ 64) dan `error_message` dibersihkan dari control/bidi
  character (≤ 1000).
- Kedua endpoint menolak node yang bukan pemilik dengan `409`, termasuk pada
  command yang sudah terminal.
- Untuk command node-level, penyelesaian/kegagalan dicatat ke `audit_events`
  (`agent.command.completed` / `agent.command.failed`) tanpa menyalin payload.

### Bentuk respons 409 yang ditetapkan

```json
{ "status": "Succeeded", "terminal_at": "2026-09-14T10:00:00+00:00" }
```

`status` adalah `AgentCommandStatus` saat ini; `terminal_at` adalah
`completed_at` untuk `Succeeded`, `failed_at` untuk `Failed`, selain itu `null`.
Payload command dan credential tidak pernah dipantulkan. Konflik
`Idempotency-Key` pada report memakai `{ "message": "…" }` dengan `409`.

## Enum

Nilai berikut harus identik dengan `sakala-agent-protocol` dan dikunci oleh
`tests/Unit/AgentProtocolEnumTest.php`:

- `AgentCommandType`: `InspectProject`, `DeployProject`, `RestartProject`,
  `StopProject`, `SleepProject`, `WakeProject`, `HealthCheck`, `RefreshRoute`,
  `ReconcileWorkload`, `CleanupRuntime`, `DrainNode`, `ResumeNode`.
- `AgentCommandStatus`: `Pending`, `Claimed`, `Running`, `Succeeded`, `Failed`,
  `Cancelled`, `Expired`.
- `AgentNodeStatus` (dilaporkan): `ready`, `busy`, `degraded`, `draining`,
  `drained`, `maintenance`; `offline` hanya diturunkan control plane.
- `AgentNodeDesiredState`: `active`, `draining`, `drained`, `maintenance`.
- `RepositoryAccess`: `public`, `temporary_credential`.
- `DesiredWorkloadState`: `running`, `stopped`, `missing`;
  `ReconcileWorkloadAction`: `restart_log_follower`,
  `cleanup_failed_candidate`, `restore_route`; `RuntimeCleanupTarget`:
  `stale_workspaces`, `stale_images`, `stale_routes`;
  `FinalizationDeferredReason`: `grace_elapsed`, `runtime_error`.
- `DeploymentEventLevel`: `info`, `warning`, `error`; `LogStream`: `stdout`,
  `stderr`, `system`.

## Error code dan klasifikasi

`error_code` dari `fail` disanitasi (`[A-Za-z0-9._-]`, ≤ 64) lalu, untuk
`DeployProject`, dipetakan `DeploymentFailureClassifier` ke kategori yang
dilihat console (`failure.category`):

| Kategori | Error code |
| --- | --- |
| `checkout` | `repository_checkout_failed`, `repository_not_found`, `repository_access_denied`, `repository_auth_failed`, `repository_credential_expired`, `repository_credential_unavailable`, `repository_commit_not_found`, `runtime_repository_failed` |
| `build` | `runtime_build_failed` |
| `start` | `runtime_execution_failed`, `runtime_container_failed`, `runtime_workload_not_found`, `runtime_workload_not_running`, `runtime_reporting_failed`, `runtime_filesystem_failed` |
| `health` | `runtime_health_check_failed` |
| `route` | `runtime_routing_failed` |
| `timeout` | `runtime_timeout`, `command_lease_expired` (control plane) |
| `resource` | `runtime_capacity_exceeded`, `runtime_disk_pressure` |
| `node` | `runtime_preflight_failed`, `runtime_dependency_failed`, `invalid_runtime_configuration`, `invalid_runtime_command`, `unsupported_runtime_command` |
| `scheduling` | `command_expired` (control plane) |
| `unknown` | lainnya, termasuk `runtime_cancelled` |

## Fixture

`tests/Fixtures/agent-protocol-v4/` berisi payload wire dari `sakala-agent`
v0.1.0: `commands/*.json` disalin dari `examples/commands/`, sedangkan
`heartbeat/*.json` diambil dari contoh dokumentasi dan output builder heartbeat
pada tag tersebut. Test mereplay payload ini apa adanya (poll harus menyajikan
bentuk yang sama; heartbeat harus diterima) tanpa Agent atau Docker sungguhan.
Lihat README di folder tersebut.

## Belum tersedia pada API

Seluruh kontrak protocol v4 yang diperlukan #55 telah diimplementasikan. Belum
tersedia: `desired_state = maintenance` (agent v0.1.0 hanya mencapainya lewat
bootstrap), endpoint re-inspect project, dan migrasi workload lintas node.

## Catatan sinkronisasi untuk sakala-agent

Bagian ini mencatat di mana API sudah lebih maju dari agent v0.1.0, atau di
mana dokumentasi agent perlu dikoreksi, supaya rilis agent berikutnya dapat
menyesuaikan. Tidak ada yang memblokir kompatibilitas v0.1.0.

Sudah dikerjakan agent di `main`, tinggal rilis tag:

1. `metadata.detail_counts` pada heartbeat (agent commit `320b6a6`, #48,
   mengikuti batas 256 KiB API). API menerimanya sebagai opsional dan
   memvalidasi lengkap bila ada. `CHANGELOG.md` `[Unreleased]` agent belum
   mencatat #48; `docs/AGENT_API.md` agent perlu memuat shape-nya bila
   dianggap bagian kontrak.

Kemampuan API yang belum dipakai agent (opsional, disarankan diadopsi):

2. Batch report `{ "events": [...] }` / `{ "logs": [...] }` dengan header
   `Idempotency-Key` per request (HMAC per item), acknowledgement
   `{ "data": { "accepted_count", "duplicate_count", "first_sequence",
   "last_sequence" } }`, dan retry aman. Agent mengirim satu objek per
   request tanpa retry; `support/retry.rs` (`exponential_delay`) sudah ada
   tetapi tidak dipakai client. Retry dengan key yang sama aman terhadap
   command yang sudah terminal (duplikat tetap di-ack).
3. Response `claim` membawa resource command penuh (termasuk payload yang
   sudah dimaterialisasi). Agent mengabaikan body; kelak dapat dipakai untuk
   materialisasi secret tanpa pinning di sisi poll.
4. Body `409` membawa `terminal_at` (ISO-8601) selain `status`.

Semantik control plane yang perlu diketahui agent:

5. `Claimed -> Running` dilakukan API pada report pertama dari node pemilik;
   agent tidak perlu memanggil apa pun.
6. Lease: `lease_expires_at = claimed_at + command_timeout_seconds +
   SAKALA_AGENT_LEASE_GRACE_SECONDS` (60). Setelah lewat, command menjadi
   `Expired`; `complete`/`fail` berikutnya dijawab `409 {"status":"Expired"}`
   dan harus diperlakukan sebagai konflik terminal (agent v0.1.0 sudah
   melakukannya untuk status selain `Succeeded`/`Failed`).
7. Node diturunkan `offline` bila tidak heartbeat selama
   `SAKALA_AGENT_OFFLINE_AFTER_SECONDS` (60) dan tidak ditawari command apa
   pun sampai heartbeat berikutnya. Interval heartbeat agent (10 s) aman.
8. `DeployProject`/`InspectProject` adalah *pinned command type*: keduanya
   harus punya node target yang deterministik sebelum ditawarkan atau
   diklaim. Bila saat dibuat belum ada node eligible, command dibuat dengan
   `agent_node_id = null`, tidak terlihat oleh node mana pun, dan menunggu
   penugasan oleh sweep `agent:assign-commands` atau heartbeat node yang
   menjadi eligible. Payload sensitif (`environment` plaintext) hanya
   dimaterialisasi untuk node target tersebut. Project yang sudah berjalan
   di sebuah node hanya dideploy ulang ke node itu.
9. Log runtime setelah `complete` diterima hanya untuk `DeployProject`
   (follower `docker logs --follow`), tetap dibatasi `max_total_bytes`
   (`422` bila habis); event setelah terminal dijawab `409`. Follower yang
   menerima `422` sebaiknya berhenti tanpa retry, seperti pada `409`.
10. `error_code` disanitasi ke `[A-Za-z0-9._-]` (≤ 64) dan `error_message`
    dibersihkan dari control/bidi/zero-width character (≤ 1000). Body report
    lebih besar dari `SAKALA_LOG_MAX_REQUEST_BYTES` (1 MiB) dijawab `413`.
11. `payload` command lifecycle selalu `{}` (object), `environment` kosong
    selalu `{}`, dan `repository_access` selalu dikirim eksplisit.
12. `ReconcileWorkload` diblokir untuk project suspended (fail-closed);
    `CleanupRuntime` hanya dibuat atas persetujuan admin dengan
    `approved: true` yang ditulis API.

Koreksi untuk dokumentasi agent (tag v0.1.0):

13. `docs/AGENT_API.md:185` — heading `## Polling and Claim Semantics` kosong;
    isinya berada di bawah "Desired versus actual workload state" (`:249-289`).
14. `docs/AGENT_API.md:205-223` — shape laporan reconciliation
    (`{project_id, deployment_id, actual_state, reason}`, `actual_state`
    termasuk `unhealthy`) tidak sama dengan yang dikembalikan kode
    (`executor/docker.rs:883-948`: `{desired_state, actual_state, in_sync,
    drift_reason, container_id, actions_applied}`, `actual_state` hanya
    `running|stopped|missing`). API mengikuti kode.
15. `docs/AGENT_API.md:338` — `NodeStatus::busy` tidak pernah dikirim builder
    heartbeat (`heartbeat/worker.rs:77-94`); API tetap menerimanya.
16. Item `stale_routes` pada heartbeat tidak memuat `deployment_id` walau
    `RuntimeStaleRoute` memilikinya (`ports/runtime.rs:74-78` vs
    `worker.rs:160-163`).
17. Perilaku follower log setelah `complete` hanya ada di `docs/LOGGING.md`
    dan `docs/RUNTIME_HARDENING.md`; `docs/AGENT_API.md` tidak menyebut bahwa
    `/logs` dipanggil untuk command yang sudah `Succeeded`.
18. Contoh heartbeat di `docs/AGENT_API.md:339-404` tidak memuat
    `startup_reconciliation.compatibility_issues` yang selalu dikirim builder.
