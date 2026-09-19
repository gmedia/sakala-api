# Agent API

Kontrak machine protocol antara `sakala-api` dan `sakala-agent`. Dokumen ini
menjelaskan apa yang **diimplementasikan API**; wire type normatif berada di
`sakala-agent` (`crates/sakala-agent-protocol`, `docs/AGENT_API.md`,
`docs/COMMAND_LIFECYCLE.md`, `docs/COMPATIBILITY.md`). Target kompatibilitas
saat ini adalah **sakala-agent v0.1.0, protocol revision 4**.

Semua endpoint berada di `/api/agent/v1/*`, terpisah dari app API `/api/v1/*`.
Perubahan endpoint, command type, atau wire field harus dilakukan bersama
perubahan protocol Agent yang eksplisit; API tidak menambah kontrak wire secara
sepihak.

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

`{id}` adalah UUID `agent_commands.id`.

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
  dilaporkan `ready`/`busy`/`degraded`;
- `desired_state = active`, atau command bertipe `DrainNode`/`ResumeNode`;
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

### Repository credential

Dipanggil agent setelah claim dan sebelum checkout, hanya bila payload
`InspectProject`/`DeployProject` memakai `repository_access:
"temporary_credential"`. Body `{}`. Response **tanpa** envelope `data`:

```json
{ "username": "x-access-token", "token": "ghs_…" }
```

Syarat, diperiksa di bawah row lock command:

- node pemanggil adalah `agent_node_id` command; command `Claimed` atau
  `Running` — selain itu `409` dengan bentuk konflik standar;
- type `InspectProject`/`DeployProject` dan `repository_access =
  temporary_credential` — selain itu `422`;
- project masih terikat ke GitHub App installation yang `active`, dan user
  pemilik project masih terhubung ke installation tersebut — selain itu `409`
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
dibaca segar (protocol, auth, status, desired state, capability, scope).
Sukses → `200` dengan resource command; gagal → `409` dengan bentuk di bawah.

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
- `DeploymentEventLevel`: `info`, `warning`, `error`; `LogStream`: `stdout`,
  `stderr`, `system`.

## Fixture

`tests/Fixtures/agent-protocol-v4/` berisi payload wire dari `sakala-agent`
v0.1.0: `commands/*.json` disalin dari `examples/commands/`, sedangkan
`heartbeat/*.json` diambil dari contoh dokumentasi dan output builder heartbeat
pada tag tersebut. Test mereplay payload ini apa adanya (poll harus menyajikan
bentuk yang sama; heartbeat harus diterima) tanpa Agent atau Docker sungguhan.
Lihat README di folder tersebut.

## Belum tersedia pada API

Bagian kontrak v4 berikut belum diimplementasikan dan akan menyusul pada
milestone #55: alur `InspectProject` saat membuat project, lease
expiry/recovery, admin drain/resume/cleanup/reconcile, serta log runtime
setelah `complete`.
