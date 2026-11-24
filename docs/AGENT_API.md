# Agent API

Kontrak machine protocol antara `sakala-api` dan `sakala-agent`. Dokumen ini
menjelaskan apa yang **diimplementasikan API**; wire type normatif berada di
`sakala-agent` (`crates/sakala-agent-protocol`, `docs/AGENT_API.md`,
`docs/COMMAND_LIFECYCLE.md`, `docs/COMPATIBILITY.md`). Target kompatibilitas
saat ini adalah **sakala-agent v0.2.0, protocol revision 4** (revisi
protocol tidak berubah dari v0.1.0; v0.2.0 mengadopsi kemampuan API yang
sebelumnya opsional).

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

`{id}` adalah UUID `agent_commands.id`. Pada agent v0.2.0 hanya `node-state`
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

Body divalidasi sesuai payload yang dikirim binary v0.2.0 (`status`,
`hostname`, `runtime_network`, `capabilities`, `metadata.*`, `sent_at`).
`metadata.detail_counts` (selalu dikirim sejak v0.2.0; v0.1.0 tidak
mengirimnya) dan `startup_reconciliation.compatibility_issues` bersifat
opsional tetapi divalidasi bila ada. Item `startup_reconciliation.stale_routes`
divalidasi per field: `path` wajib, `project_id` dan `deployment_id` UUID
nullable (`deployment_id` dikirim sejak v0.2.0, `null` untuk route legacy tanpa
identitas deployment; v0.1.0 tidak mengirim field ini). Batas body 256 KiB →
`413`. Response `AgentHeartbeatResource`; agent tidak membaca body. Payload
v0.2.0 direplay apa adanya oleh test dari
`tests/Fixtures/agent-protocol-v4/heartbeat/`.

`metadata.workloads.crashed_details` adalah tambahan opsional di dalam protocol
revision 4 (revisi tidak berubah; agent v0.2.0 tidak mengirimnya). Isinya daftar
workload yang berhenti sendiri, paling banyak 50 item, dan seluruh heartbeat
ditolak `422` bila ada item yang tidak sesuai:

| Field | Tipe | Keterangan |
| --- | --- | --- |
| `project_id` | UUID, wajib | Project pemilik workload. |
| `deployment_id` | UUID, wajib | Deployment yang container-nya berhenti. |
| `exit_code` | integer atau `null`, wajib ada | Exit code container. |
| `oom_killed` | boolean, wajib | `true` bila dihentikan karena kehabisan memory. |
| `finished_at` | RFC 3339 atau `null`, wajib ada | Waktu container berhenti. |

`metadata.detail_counts.crashed_details` adalah jumlah sebelum daftar dipotong
ke 50. Field ini wajib bila daftarnya berisi, tetapi tidak diwajibkan oleh
keberadaan `detail_counts` saja, karena v0.2.0 mengirim `detail_counts` tanpa
kunci ini.

Bila daftarnya ada, API menyamakan `projects.runtime_status` untuk project
yang deployment aktifnya berjalan di node pelapor:

- selama project punya deployment yang masih berjalan, `runtime_status` tidak
  diubah; hasil deployment itu yang menentukan.
- item yang cocok dengan `project_id` dan `active_deployment_id` project →
  `crashed`, kecuali project `suspended` atau `stopped`. Item untuk deployment
  yang sudah tergantikan atau untuk node lain diabaikan.
- project `crashed` yang tidak lagi tercantum → `running`, kecuali
  `detail_counts.crashed_details` lebih besar dari panjang daftar. Daftar yang
  terpotong tidak membuktikan project itu sudah pulih.
- daftarnya tidak dikirim → tidak ada perubahan apa pun.

Laporan yang sama boleh berulang: tanpa perubahan status tidak ada penulisan
atau broadcast, dan biayanya satu query baca. Setiap perubahan dibroadcast
sebagai `deployment.updated` pada channel deployment aktif, dengan tambahan
field `project_runtime_status`.

`metadata.workloads.traffic` juga tambahan opsional di dalam revision 4, dikirim
node ber-capability `idle-sleep` untuk tidur otomatis. Isinya kapan router terakhir
melayani request untuk setiap workload yang berjalan, paling banyak 500 item di sisi
API (agent membatasinya ke `SAKALA_MAX_ACTIVE_CONTAINERS`), dan seperti
`crashed_details`, seluruh heartbeat ditolak `422` bila ada item yang tidak sesuai:

| Field | Tipe | Keterangan |
| --- | --- | --- |
| `project_id` | UUID, wajib | Project pemilik workload. |
| `deployment_id` | UUID, wajib | Deployment pemilik container. |
| `last_request_at` | RFC 3339, wajib | Kapan agent pertama kali melihat counter request host ini berubah. |

`metadata.detail_counts.traffic` (integer ≥ 0, opsional) menyimpan jumlah sebelum
dipotong. Bila daftarnya ada, API memajukan `projects.last_request_at` hanya untuk
project yang `active_deployment_id`-nya adalah `deployment_id` item itu, deployment
itu berjalan di node pelapor, dan `project_id`-nya cocok. Nilainya hanya maju, tidak
pernah mundur, dan waktu di depan jam API disimpan sebagai waktu sekarang. Item yang
berulang untuk deployment yang sama memakai waktu terbaru. Biayanya satu query baca
dan paling banyak satu query tulis per heartbeat, berapa pun panjang daftarnya.
Workload yang tidak tercantum tidak berubah, karena tidak tercantum berarti belum ada
request yang dihitung, bukan sepi.

Daftar yang tidak dikirim tidak mengubah apa pun. `projects:sleep-idle` hanya
menidurkan project di node yang punya capability `idle-sleep`, tidak `offline`, dan
heartbeat terakhirnya (`agent_nodes.metadata`) membawa `workloads.traffic`, supaya
node yang belum bisa membaca trafik tidak membuat project tampak sepi. Sleep karena
sepi dikirim sebagai `SleepProject` biasa dengan payload kosong; perbedaannya hanya
di `request_context` (`reason: idle`, actor `system`) yang tidak dikirim ke agent.
Sleep karena sepi yang gagal diminta lagi dengan jeda yang makin panjang, dan bila
node menjawab `fail` dengan `error_code` `runtime_workload_not_found`, API berhenti
memintanya untuk deployment itu sampai ada deployment baru atau wake/restart yang
sukses (lihat [Tidur Otomatis](API_CONVENTIONS.md#tidur-otomatis)).
Bangunnya lewat `WakeProject` ke node dan deployment yang sama. Container yang tidur
tetap muncul di `startup_reconciliation.orphans` sebagai container deployment yang
berhenti; API tidak menganggapnya sampah. Laporan crash untuk deployment yang
`sleeping` diabaikan, sama seperti `stopped`. Lihat
[Tidur Otomatis](API_CONVENTIONS.md#tidur-otomatis).

`metadata.workloads.replicas` juga tambahan opsional di dalam revision 4, dikirim
node ber-capability `replicas`. Isinya satu item per deployment yang berjalan dengan
replika: berapa container yang dijalankan node dan berapa yang lolos health check
router. Batasnya 500 item, dan seperti `traffic`, seluruh heartbeat ditolak `422`
bila ada item yang tidak sesuai:

| Field | Tipe | Keterangan |
| --- | --- | --- |
| `project_id` | UUID, wajib | Project pemilik workload. |
| `deployment_id` | UUID, wajib | Deployment pemilik replika. |
| `desired` | integer 1 sampai 1000, wajib | Container yang dijalankan node untuk deployment itu. |
| `ready` | integer 0 sampai 1000, wajib | Dari jumlah itu, yang lolos health check router saat ini. |

`metadata.detail_counts.replicas` (integer ≥ 0, opsional) menyimpan jumlah sebelum
dipotong. API menyimpan `desired` dan `ready` ke `deployments.replicas_desired` dan
`deployments.replicas_ready` hanya untuk deployment yang menjadi
`active_deployment_id` project yang melayani (tidak `sleeping`), berjalan di node
pelapor, dan `project_id`-nya cocok. Item yang berulang untuk deployment yang sama
memakai item terakhir. Biayanya satu query baca dan paling banyak satu query tulis
per heartbeat, dan laporan yang sama tidak menulis apa pun. Deployment yang tidak
tercantum menyimpan laporan terakhirnya.

Node ber-capability `replicas` juga mengirim
`metadata.workloads.maximum_replicas_per_project` di setiap heartbeat: replika
terbanyak per project yang ia jalankan (`SAKALA_MAX_REPLICAS_PER_PROJECT`, integer 1
sampai 64), atau `null` bila driver-nya tidak bisa menjalankan replika. Nilai di luar
itu menolak heartbeat `422`. API menyimpannya bersama metadata node dan memakainya
saat memilih node untuk `DeployProject` dengan `replicas` lebih dari 1: node harus
punya capability `replicas` dan maksimumnya paling sedikit sebanyak replika yang
diminta. Node ber-capability yang tidak mengirim field ini dianggap sanggup berapa
pun.

`metadata.workloads.metrics` juga tambahan opsional di dalam revision 4, dikirim
node ber-capability `workload-metrics`. Isinya sampel pemakaian resource container
yang berjalan, satu item per replika per sampel. Batasnya 500 item, dan seperti
`traffic`, seluruh heartbeat ditolak `422` bila ada item yang bentuknya tidak sesuai:

| Field | Tipe | Keterangan |
| --- | --- | --- |
| `project_id` | UUID, wajib | Project pemilik workload. |
| `deployment_id` | UUID, wajib | Deployment pemilik container. |
| `replica` | integer 0 sampai 999, wajib | Indeks replika di deployment itu, mulai dari 0. |
| `sampled_at` | RFC 3339, wajib | Kapan sampel diambil. Disimpan dengan presisi detik. |
| `cpu_percent` | angka ≥ 0, wajib | Persen dari satu core, jadi container yang memakai dua core penuh melapor 200. Paling besar 102400. |
| `memory_bytes` | integer ≥ 0, wajib | Memory yang dipakai container, dalam byte. Paling besar 1 PiB. |
| `memory_limit_bytes` | integer ≥ 0 atau `null`, wajib ada | Batas memory container dalam byte, `null` bila tanpa batas. Paling besar 1 PiB. |
| `restart_count` | integer ≥ 0, wajib | Jumlah restart kumulatif container itu, seperti `RestartCount` Docker. Boleh mulai dari 0 lagi saat container dibuat ulang. |

`metadata.detail_counts.metrics` (integer ≥ 0, opsional) menyimpan jumlah sebelum
dipotong. API hanya menyimpan sampel untuk deployment yang menjadi
`active_deployment_id` project-nya, berjalan di node pelapor, dan `project_id`-nya
cocok, sama dengan aturan `traffic`. Sampel untuk project atau deployment lain,
termasuk ID yang tidak dikenal dan deployment yang sudah tergantikan, diabaikan
tanpa menolak heartbeat. Sampel yang lebih tua dari 10 menit dari jam API juga
diabaikan, dan waktu di depan jam API disimpan sebagai waktu sekarang.

Sampel digabung per replika per menit ke `project_metric_minutes`: jumlah sampel,
jumlah dan maksimum `cpu_percent` serta `memory_bytes`, `memory_limit_bytes` dari
sampel terbaru, dan `restart_count` tertinggi. Sampel yang `sampled_at`-nya sama
atau lebih tua dari sampel terakhir yang sudah tersimpan untuk replika dan menit
itu tidak dihitung lagi, jadi node boleh mengirim ulang sampel terakhirnya kalau
belum mengambil yang baru. Biayanya satu query baca project, satu query baca baris
menit, dan satu upsert per heartbeat, berapa pun panjang daftarnya. Daftar ini
tidak ikut disimpan di `agent_nodes.metadata`, tetapi `detail_counts.metrics`
tetap tersimpan. Hasilnya dibaca Console lewat
[Metrik](API_CONVENTIONS.md#metrik).

`metadata.runtime` juga tambahan opsional di dalam revision 4, untuk Sakala Edge
dan region (ADR-019). Kedua kuncinya opsional dan boleh `null`; nilai yang tidak
sesuai menolak seluruh heartbeat `422`, seperti daftar di atas:

| Field | Tipe | Keterangan |
| --- | --- | --- |
| `metadata.runtime.region` | string atau `null` | Region node, dari `SAKALA_AGENT_REGION`. Huruf kecil dan angka dalam kelompok yang dipisah satu tanda hubung (`^[a-z0-9]+(?:-[a-z0-9]+)*$`), paling panjang 32 karakter, misalnya `jkt` atau `id-jog-1`. |
| `metadata.runtime.edge_address` | string atau `null` | Alamat router runtime node seperti dicapai Sakala Edge, `host:port` tanpa skema atau path: IPv4 (`10.0.47.201:8180`), IPv6 dalam kurung siku (`[fd00::21]:8180`), atau nama DNS (`runtime-01.internal:8180`); port 1 sampai 65535; paling panjang 255 karakter. Usulan nama env di agent: `SAKALA_AGENT_EDGE_ADDRESS`. |

`metadata.database` dan `metadata.databases` juga tambahan opsional di dalam
revision 4, dikirim node ber-capability `managed-postgres` (ADR-020; contoh di
sakala-agent `examples/heartbeat/database-host.json`). Region node database memakai
`metadata.runtime.region` yang sudah ada. Nilai yang tidak sesuai menolak seluruh
heartbeat `422`, seperti daftar di atas:

| Field | Tipe | Keterangan |
| --- | --- | --- |
| `metadata.database` | object, opsional | Dikirim di setiap heartbeat node database. Disimpan di `agent_nodes.metadata`. |
| `metadata.database.endpoint` | object atau `null` | Alamat instance PostgreSQL seperti dicapai node runtime lewat jaringan privat (`SAKALA_DATABASE_PUBLIC_HOST`, `SAKALA_DATABASE_PUBLIC_PORT` di agent). API memakainya untuk `DB_HOST`, `DB_PORT`, dan `DATABASE_URL` proyek yang terpasang saat deployment dibuat. Tanpa alamat, deploy proyek yang database-nya ada di node ini ditolak `409 DATABASE_ENDPOINT_UNAVAILABLE`. |
| `metadata.database.endpoint.host` | string, wajib bila `endpoint` ada | IPv4, IPv6 tanpa kurung siku (`fd00::30`), atau nama DNS, tanpa port, skema, atau path; paling panjang 253 karakter. |
| `metadata.database.endpoint.port` | integer 1 sampai 65535, wajib bila `endpoint` ada | Port TCP instance. |
| `metadata.database.available` | boolean, opsional | Apakah pembacaan ukuran terakhir agent berhasil. `false` membuat node itu dipilih paling akhir untuk database baru (node juga melaporkan `degraded`). Tidak dikirim berarti tersedia. |
| `metadata.databases` | array, paling banyak 1000 item, opsional | Ukuran setiap database terkelola. Agent hanya mengirimnya pada heartbeat pertama setelah pembacaan baru yang berhasil, paling sering sekali per 60 detik. Tidak dikirim berarti tidak ada pembacaan baru, bukan database hilang atau ukuran nol. Tidak disimpan di `agent_nodes.metadata`. |
| `metadata.databases[].name` | string, wajib | Nama database di node, `^[a-z_][a-z0-9_]{0,62}$` (agent hanya mengirim `^p_[a-z0-9]{16,40}$`). Nama yang tidak dikenal API di node ini diabaikan tanpa menolak heartbeat. |
| `metadata.databases[].size_bytes` | integer 0 sampai 1 PiB, wajib | `pg_database_size()` dalam byte, termasuk katalog sistem (database kosong sekitar 7,5 MiB). |
| `metadata.detail_counts.databases` | integer ≥ 0, opsional | Jumlah sebelum daftar dipotong; dikirim bersama `databases`. |

API menulis `size_bytes` dan `size_checked_at` hanya untuk database yang `node_id`-nya
node pelapor dan nama di node-nya cocok, termasuk yang sedang dihapus, dengan satu
`UPDATE` per heartbeat berapa pun panjang daftarnya. Database yang tidak tercantum,
dan semua database saat daftar tidak dikirim, menyimpan ukuran terakhirnya. Ukuran
ini yang dibandingkan sapuan kuota penyimpanan setiap menit (lihat
[Database](API_CONVENTIONS.md#kuota-dan-penguncian)).

`metadata.object_storage` dan `metadata.buckets` juga tambahan opsional di dalam
revision 4, dikirim node ber-capability `managed-s3` (ADR-021; contoh di sakala-agent
`examples/heartbeat/object-storage-host.json`). Region penempatan Sakala tetap dari
`metadata.runtime.region`; `object_storage.region` adalah region signing S3. Nilai
yang tidak sesuai menolak seluruh heartbeat `422`:

| Field | Tipe | Keterangan |
| --- | --- | --- |
| `metadata.object_storage` | object, opsional | Dikirim di setiap heartbeat node penyimpanan. Disimpan di `agent_nodes.metadata`. |
| `metadata.object_storage.endpoint` | string, wajib bila `object_storage` ada | Endpoint S3 publik (`SAKALA_OBJECT_STORAGE_PUBLIC_ENDPOINT`): `https://host[:port]` tanpa path, query, fragment, atau user info, paling panjang 255 karakter. `http://` hanya untuk host loopback (`localhost`, `127.0.0.1`, `[::1]`), untuk pengembangan lokal. API memakainya untuk `AWS_ENDPOINT_URL_S3` dan `AWS_ENDPOINT`. Tanpa `object_storage`, deploy proyek yang bucket-nya ada di node ini ditolak `409 BUCKET_ENDPOINT_UNAVAILABLE`. |
| `metadata.object_storage.region` | string, wajib bila `object_storage` ada | Region signing S3, `^[a-z0-9]+(-[a-z0-9]+)*$`, paling panjang 32. Dipakai untuk `AWS_DEFAULT_REGION` dan `AWS_REGION`. |
| `metadata.object_storage.capacity_mb` | integer ≥ 1, wajib bila `object_storage` ada | MB yang boleh dialokasikan node ke kuota bucket (`SAKALA_OBJECT_STORAGE_TOTAL_QUOTA_MB`). API mengurangi jumlah `reserved_quota_mb` bucket node itu saat menempatkan bucket. |
| `metadata.object_storage.available` | boolean, opsional | Apakah pembacaan pemakaian terakhir berhasil. `false` membuat node dipilih paling akhir untuk bucket baru dan `buckets.available` tidak dihitung dari node itu. Tidak dikirim berarti tersedia. |
| `metadata.buckets` | array, paling banyak 1000 item, opsional | Pemakaian setiap bucket terkelola, urut nama. Hanya dikirim pada heartbeat pertama setelah pembacaan baru yang berhasil, paling sering sekali per 60 detik. Tidak dikirim berarti tidak ada pembacaan baru; array kosong berarti tidak ada bucket terkelola. Tidak disimpan di `agent_nodes.metadata`. |
| `metadata.buckets[].name` | string, wajib | Nama bucket di node, `^b-[a-z0-9]{16,40}$`. Nama yang tidak dikenal API di node ini diabaikan tanpa menolak heartbeat. |
| `metadata.buckets[].size_bytes` | integer 0 sampai 1 PiB, wajib | `usage` dari `rc quota info`, dalam byte. |
| `metadata.detail_counts.buckets` | integer ≥ 0, opsional | Jumlah sebelum daftar dipotong; dikirim bersama `buckets`. |

API menulis `size_bytes` dan `usage_checked_at` hanya untuk bucket yang `node_id`-nya
node pelapor dan nama di node-nya cocok, termasuk yang sedang dihapus, dengan satu
`UPDATE` per heartbeat. Bucket yang tidak tercantum menyimpan nilai terakhirnya.
API yang lebih lama dari fitur ini menyimpan `object_storage` dan `buckets` apa adanya
di `agent_nodes.metadata` tanpa menolaknya, karena `metadata` tidak dibatasi key-nya.

`metadata.workloads.maximum_active_workloads` (integer ≥ 0 atau `null`, opsional,
tambahan di revision 4) adalah container terbanyak yang dijalankan node sekaligus
(`SAKALA_MAX_ACTIVE_CONTAINERS`, nilai yang sama dengan `RuntimeCapacity.maximum_active_workloads`
di agent). Bersama `metadata.workloads.active` dan `metadata.resources.memory_available_bytes`,
nilai ini dipakai scheduler untuk memilih node dengan kapasitas paling longgar.

API menyimpan semuanya di `agent_nodes.metadata` seperti dilaporkan. Admin bisa
menimpa region dan alamat Edge per node (`agent:node-region`,
`agent:node-edge-address`); override admin selalu menang dan heartbeat tidak pernah
mengubahnya. Region efektif menentukan penempatan project yang memilih region;
alamat Edge efektif menjadi `upstreams` di tabel route Edge
([EDGE_API.md](EDGE_API.md)). Node tanpa alamat Edge membuat project-nya masuk
`skipped_routes`.

`ready < desired` tidak pernah mengubah `runtime_status`: project dengan sebagian
replika mati tetap melayani lewat replika yang sehat. Agent hanya mencantumkan
deployment di `crashed_details` bila semua replikanya crash, dan hanya daftar itu
yang membuat project `crashed`.

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

Polling **tidak** memberi kepemilikan. Field `cancel_requested` dijelaskan di
[Pembatalan command](#pembatalan-command). Sebuah command ditawarkan ke node bila:

- node `auth_status = active`, protocol revision didukung, dan status yang
  dilaporkan bukan `offline`;
- untuk command workload: status yang dilaporkan `ready`/`busy`/`degraded`
  dan `desired_state = active`; `DrainNode`/`ResumeNode` juga ditawarkan saat
  draining/drained/maintenance;
- command `Pending`, `available_at <= now`, dan `expires_at` belum lewat;
- untuk `DeployProject`: deployment-nya tidak sedang menunggu inspeksi project
  (`deployments.awaiting_inspection_until` kosong). Control plane mengisi port
  di payload saat melepasnya; claim yang datang lebih dulu dijawab `409`
  karena claim mengunci baris deployment yang sama;
- command terikat ke node (`agent_node_id = node`), atau belum terikat dan
  tipenya bukan *pinned* (`InspectProject`, `DeployProject`, `CleanupRuntime`,
  `DrainNode`, `ResumeNode`, dan keenam command database selalu pinned; command
  pinned tanpa target tidak terlihat oleh node mana pun sampai control plane
  menetapkannya);
- node memiliki capability yang dibutuhkan tipe command (lihat tabel);
- project tidak `suspended`, kecuali tipe yang tidak pernah menghidupkan atau
  mengekspos workload (`StopProject`, `SleepProject`, `HealthCheck`,
  `CleanupRuntime`, `DrainNode`, `ResumeNode`). `ReconcileWorkload` ikut
  diblokir karena payload-nya dapat meminta `desired_state = running` atau
  `restore_route`.

Command tanpa payload selalu diserialisasi sebagai `{}`. Hanya
`InspectProject`, `DeployProject`, `StopProject`, `SleepProject`,
`ReconcileWorkload`, `CleanupRuntime`, dan command database (lihat
[Perintah database](#perintah-database)) yang membawa payload. `StopProject`
paling banyak membawa `remove_images` (lihat [StopProject](#stopproject)) dan
`SleepProject` paling banyak membawa `page` (lihat [SleepProject](#sleepproject));
lifecycle command lain hanya membawa identitas.

| Command type | Capability yang dibutuhkan (salah satu) |
| --- | --- |
| `InspectProject` | `project-inspection` |
| `DeployProject` | `dockerfile-build`, `railpack-build` |
| `RestartProject`, `StopProject`, `SleepProject`, `WakeProject`, `HealthCheck`, `ReconcileWorkload`, `CleanupRuntime` | `docker-runtime` |
| `RefreshRoute` | `caddy-file-routing` |
| `CreateDatabase`, `DropDatabase`, `RotateDatabasePassword`, `LockDatabase`, `UnlockDatabase`, `InstallDatabaseExtension` | `managed-postgres` |
| `CreateBucket`, `DeleteBucket`, `RotateBucketKey`, `SetBucketQuota`, `LockBucket`, `UnlockBucket` | `managed-s3` |
| `DrainNode`, `ResumeNode` | — |

`DeployProject` yang payload-nya membawa `replicas` lebih dari 1 juga butuh
capability `replicas`, dan yang membawa `release_command` juga butuh capability
`release-command`; lihat [DeployProject](#deployproject). API hanya mengirim
`reuse_image_from_deployment_id` ke node yang mengiklankan capability
`image-reuse`, tetapi capability ini tidak memengaruhi penempatan: tanpa itu
deployment tetap dibuat dan di-build seperti biasa. Capability `image-rollback`
juga tidak memengaruhi penempatan; API hanya memakainya untuk memperkirakan
apakah rollback bisa tanpa build (lihat [Image rollback](#image-rollback)).

### Image rollback

Capability `image-rollback` (driver `docker` saja) adalah janji agent tentang
image yang disimpannya, tanpa field payload baru dan tanpa command baru:

- Setelah `DeployProject` sukses (workload baru live, workload lama sudah
  dihapus), di bawah lock project, agent menyimpan tepat dua image project itu:
  image yang live dan image *previous*, lalu menghapus image
  `sakala/project-<project>:*` lain yang tidak dipakai container mana pun.
- *Previous* adalah image workload yang digantikan deploy sukses terakhir, bila
  berbeda dari image live yang baru. Deploy yang memakai ulang image live
  (redeploy konfigurasi) tidak memindahkannya. Setelah rollback dari I5 ke I3,
  previous adalah I5.
- Penandanya tag Docker di repository yang sama,
  `sakala/project-<project>:previous`. Image tetap memegang tag aslinya, jadi
  pencarian pakai ulang lewat nama yang diturunkan tetap bekerja. Memindahkan tag
  tidak pernah menghapus image dengan sendirinya.
- GC image berkala memakai aturan yang sama untuk project yang masih punya
  container: simpan setiap image yang dipakai container (status apa pun, jadi
  yang tidur dan semua replika ikut dihitung) ditambah image bertag `previous`;
  bila tidak ada image yang memegang tag itu (image dari agent lama), simpan image
  terbaru yang lebih tua dari image terbaru yang dipakai, seperti sebelumnya.
  `image_gc_max_age` tidak lagi melindungi image project seperti itu. Project
  tanpa container apa pun tetap memakai aturan masa tenggang yatim seperti
  sebelumnya.
- Kepemilikan: hanya image yang setiap referensinya
  `sakala/project-<project itu>:<tag>` dan yang label `dev.sakala.project-id`-nya
  menyebut project itu yang boleh dihapus atau diberi tag (aturan `stale_image`
  yang sudah ada).
- Deploy yang gagal tidak pernah memindahkan `previous` dan tetap hanya menghapus
  image kandidat yang dibangunnya.

API mencerminkan *previous* di `projects.previous_active_deployment_id`: saat
`active_deployment_id` berpindah dari A ke D dan deployment yang mem-build image
D berbeda dari milik A, nilainya menjadi A. Rollback ke deployment yang
image-nya adalah image live atau previous, di node deployment aktif yang
mengiklankan `image-rollback` dan `image-reuse`, ditampilkan sebagai tanpa build
(`rollback_target.image_expected`). Rollback ke image yang sudah dihapus tetap
benar: agent tidak menemukan image-nya, mem-build commit itu, dan melaporkan
`deployment.image.reuse_skipped` dengan `image_missing` seperti biasa.

`RestartProject` dari anggota workspace (`request_context.reason`
`user_restart`) hanya dikirim API saat `runtime_status` project `running` atau
`crashed`. Agent dengan capability ini juga menyalakan ulang project yang semua
container-nya sudah keluar (crash, crash loop), bukan hanya yang masih berjalan;
project yang tidur tetap ditolak dengan `WorkloadNotRunning`. Restart yang
sukses dihitung API sebagai satu restart per replika di metrik project.

### Pembatalan command

Kontrak ini tambahan di dalam protocol revision 4: arahnya tetap outbound, tidak
ada command type baru, tidak ada endpoint baru, dan agent v0.2.0 yang belum
mengenalnya tetap bekerja (lihat poin terakhir).

Response `GET /commands` memuat array UUID di samping `data` selama ada command
yang diminta batal:

```json
{ "data": [], "cancel_requested": ["0198a2f0-6a51-7c3e-9d6e-1b2c3d4e5f60"] }
```

| Field | Tipe | Arti |
| --- | --- | --- |
| `cancel_requested` | array UUID, opsional, tidak pernah kosong, paling banyak 100 | `agent_commands.id` milik node pemanggil yang berstatus `Claimed` atau `Running` dan diminta batal oleh pemilik project. Field ini tidak ada bila tidak ada yang diminta batal, jadi agent harus menganggapnya `[]`. |

Array ini tidak terpengaruh `command_batch_size` dan tetap dikirim saat node
sedang draining, drained, atau sudah penuh, jadi agent harus tetap melakukan poll
selama ada eksekusi berjalan. ID yang sama diulang pada setiap poll sampai command
terminal, sehingga agent harus memperlakukannya idempoten. ID yang tidak sedang
dijalankan agent diabaikan.

Yang harus dilakukan agent untuk tiap ID yang sedang dijalankannya:

1. Membatalkan eksekusinya lewat cancellation token yang sudah ada.
2. Bila pembatalan terjadi, mengirim `POST /commands/{id}/fail` dengan
   `error_code = runtime_cancelled`. API mengakhiri command sebagai `Cancelled`
   (bukan `Failed`) dan deployment sebagai `cancelled`. Retry dengan kode yang sama
   dijawab `204`.
3. Bila deployment sudah melewati cutover route dan harus diselesaikan, mengirim
   `complete` seperti biasa. Deployment berakhir `succeeded`, karena permintaan
   batal bukan jaminan.
4. Bila eksekusi gagal karena sebab lain sebelum sempat dibatalkan, mengirim `fail`
   dengan kode aslinya. Deployment berakhir `failed` dengan kode itu.

Command `Pending` tidak pernah muncul di `cancel_requested`: API langsung
membuatnya `Cancelled`, dan `claim` setelahnya dijawab `409` bentuk standar dengan
`status = Cancelled`. Agent yang mengirim `runtime_cancelled` tanpa diminta, misalnya
saat node berhenti, juga berakhir `Cancelled`, tetapi deployment menyimpan
`failure.code = runtime_cancelled` supaya pemiliknya tahu penyebabnya.

Bila agent tidak pernah menanggapi, command berjalan sampai selesai atau sampai lease
habis. Saat lease habis pada deployment yang sudah diminta batal, deployment berakhir
`cancelled`, bukan `failed`.

### DeployProject

Payload mengikuti `DeployProjectPayload` protocol v4:

```json
{
  "repository_url": "https://github.com/example/app.git",
  "commit_sha": "0123456789abcdef0123456789abcdef01234567",
  "repository_access": "public",
  "domain": "portfolio.sakala.app",
  "container_port": 3000,
  "builder": "auto",
  "environment": { "APP_ENV": "production" },
  "resources": { "memory_mb": 256, "cpu_millis": 500, "pids_limit": 128 },
  "timeouts": { "build_timeout_seconds": 600, "start_timeout_seconds": 120, "command_timeout_seconds": 900 },
  "log_bounds": { "max_line_length": 4096, "max_batch_lines": 500, "max_total_bytes": 10485760 }
}
```

- `build_command`, `start_command`, dan `root_directory` adalah field opsional
  tambahan (revisi protocol tetap 4), hanya dikirim bila project mengisinya.
  Agent yang belum mengenalnya mengabaikannya, jadi project itu dibangun dengan
  deteksi otomatis. Agent menjalankan `build_command` hanya di dalam proses build
  dan `start_command` hanya di dalam container, tidak pernah di shell host.
  `root_directory` adalah path relatif terhadap root repository (tanpa `..`, tanpa
  `/` di awal) yang menjadi konteks build. API menjamin ketiganya satu baris,
  terbatas 1000 karakter untuk command dan 255 untuk direktori.
- `release_command` adalah field opsional tambahan (revisi protocol tetap 4),
  hanya dikirim bila project mengisinya, dan hanya ke node yang mengiklankan
  capability `release-command`. Agent menjalankannya sekali per deployment,
  sebelum versi baru menerima traffic (misalnya migrasi database), di dalam
  container dan tidak pernah di shell host. API menjamin satu baris, paling
  panjang 1000 karakter. Agent lama akan mengabaikan field ini dan menjalankan
  versi baru tanpa perintah rilis, jadi deployment ditolak saat dibuat dengan
  `409 PROJECT_RELEASE_COMMAND_UNSUPPORTED` bila tidak ada node yang bisa
  menjalankannya: node tempat project berjalan (apa pun statusnya) tidak punya
  capability itu, atau, untuk project yang belum pernah berjalan, tidak ada node
  aktif yang punya. Bila node yang sanggup hanya sedang offline, command menunggu
  node itu. Perintah yang keluar dengan kode selain 0 menggagalkan command dengan
  `release_command_failed` (sertakan kode keluarnya di `error_message`), dan yang
  melewati batas waktu dengan `release_command_timeout`; dalam kedua kasus versi
  baru tidak menerima traffic dan versi sebelumnya tetap melayani. Field ini tidak
  pernah ada di payload `WakeProject`, `RestartProject`, atau `ReconcileWorkload`.
- `reuse_image_from_deployment_id` adalah field opsional tambahan (revisi
  protocol tetap 4): UUID deployment yang **mem-build** image yang dipakai ulang,
  tidak pernah deployment yang dirinya sendiri memakai ulang image. API hanya
  mengirimnya ke node yang mengiklankan capability `image-reuse`, dan hanya bila
  `PlanDeploymentBuildAction` memutuskan pakai ulang: tidak ada `force_build`,
  commit sama dengan deployment aktif project (apa pun status runtime-nya), build
  fingerprint deployment aktif ada dan sama, dan deployment baru dipin ke node
  deployment aktif (lihat
  [Redeploy tanpa build](API_CONVENTIONS.md#redeploy-tanpa-build)). Tanpa field
  ini, atau bernilai `null`, agent mem-build seperti biasa. Agent membacanya
  longgar: nilai yang bukan string UUID tidak menggagalkan payload, agent
  mem-build dan melaporkan alasan `invalid_reference`. Agent lama mengabaikannya
  dan mem-build. Agent menurunkan nama image sendiri dari project yang meminta,
  `sakala/project-<project_id>:<commit12>-<source8>`, dan hanya memakainya bila
  label `dev.sakala.project-id` sama dengan `project_id` command, label
  `dev.sakala.deployment-id` sama dengan field ini (UUID penuh), dan label
  `dev.sakala.builder` bernilai `dockerfile` atau `railpack`; referensi image
  tidak pernah diterima dari payload. Pakai ulang melewati checkout, analisis,
  dan build, tetapi tetap menjalankan `release_command`, health check, replika,
  dan pergantian tanpa downtime seperti biasa. Deployment pakai ulang yang gagal
  tidak boleh menghapus image itu, karena image itu milik deployment yang sedang
  melayani. Rollback memakai field yang sama tanpa tambahan apa pun: `commit_sha`
  adalah commit deployment tujuan dan field ini deployment yang mem-build image
  tujuan, sehingga deployment rollback tidak harus sama commit-nya dengan
  deployment aktif. Payload-nya juga membawa `build_command`, `start_command`,
  `release_command`, `root_directory`, dan `container_port` yang tercatat di
  deployment tujuan, bukan nilai project saat ini. Lihat
  [Image rollback](#image-rollback).
- `domain` adalah `projects.default_domain` saat deployment dibuat, yaitu
  `<slug>.<SAKALA_PROJECT_DEFAULT_DOMAIN>` (hosted: `<slug>.sakala.app`,
  staging: `<slug>.staging.sakala.app`). Agent hanya menerima domain satu label
  di bawah suffix yang ia layani (`SAKALA_PROJECT_DOMAIN_SUFFIXES`). Wake dan
  restart memakai domain yang tersimpan di container, bukan payload baru, jadi
  setelah `projects:move-domain` setiap project harus dideploy ulang supaya
  route-nya pindah.
- `replicas` adalah field opsional tambahan (revisi protocol tetap 4): jumlah
  container yang dijalankan untuk deployment ini di node yang sama, di belakang
  router node. API hanya mengirimnya bila nilainya lebih dari 1, dan hanya ke node
  yang mengiklankan capability `replicas` dengan
  `maximum_replicas_per_project` yang cukup; tanpa field ini agent menjalankan satu
  container. Agent lama akan mengabaikan field ini dan diam-diam menjalankan satu
  container, jadi deployment ditolak saat dibuat dengan `409
  PROJECT_REPLICAS_UNSUPPORTED` bila tidak ada node yang bisa menjalankannya: node
  tempat project berjalan (apa pun statusnya) tidak sanggup, atau, untuk project
  yang belum pernah berjalan, tidak ada node aktif yang sanggup. Bila node yang
  sanggup hanya sedang offline, command menunggu node itu. Agent yang tetap menolak
  karena batasnya lebih kecil gagal dengan `runtime_replicas_exceeded`. Nilainya adalah `replicas` project saat deployment
  dibuat, dibatasi `max_replicas` workspace, dan disimpan di `deployments.replicas`.
  Wake dan restart menjalankan semua replika yang disimpan node, bukan nilai baru.
- `container_port` adalah `effective_port` project saat deployment dibuat:
  `configured_port`, lalu `detected_port`, lalu 3000. Agent mengisi `PORT` dengan
  nilai ini, dan `runtime_port_conflict` muncul bila environment project menulis
  `PORT` dengan nilai lain.
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
| `deployment.analysis.started` | `analyzing` (hanya build Railpack) |
| `deployment.build.started` | `building` |
| `deployment.container.started` | `deploying` |
| `deployment.health.started` | `health_checking` |
| `deployment.route.started` | `routing` |
| `deployment.runtime.ready` | tidak mengubah status; mengisi `image_reference` dari `metadata.image` |
| `deployment.workspace.detected` | tidak mengubah status; disimpan di timeline dengan metadata berbentuk `workspace` hasil `InspectProject`, berisi perintah yang benar-benar dipakai (sakala-agent #113, hanya build Railpack dari package workspace JavaScript) |
| `deployment.image.reused` | tidak mengubah status; disimpan di timeline. Level `info`, metadata `{ "image": "<ref>", "source_deployment_id": "<uuid>", "builder": "dockerfile" \| "railpack" }`. Dikirim sebagai ganti `checkout.started`, `analysis.started`, `workspace.detected`, dan `build.started` pada deployment yang memakai ulang image, sehingga statusnya melompat dari `queued` ke `deploying` |
| `deployment.image.reuse_skipped` | tidak mengubah status; disimpan di timeline. Level `warning`, metadata `{ "source_deployment_id": "<uuid>" \| null, "reason": "<alasan>" }`, dikirim sebelum `checkout.started` lalu build berjalan normal. Pada deployment `build_mode = reused` API mengubahnya menjadi `built` dan mengisi `build_fallback_reason`; `image_source_deployment_id` tetap. Alasan: `image_missing` (image tidak ada di node ini), `image_not_owned` (label menyebut project atau deployment lain), `image_metadata_missing` (tanpa label `dev.sakala.builder` yang dikenal, dibuat agent sebelum rilis ini), `invalid_reference` (field bukan string UUID; `source_deployment_id` `null`). Alasan lain disimpan sebagai `image_unavailable`. Bukan kegagalan, dan tidak ada kode error baru |

Urutan statusnya `queued`, `cloning`, `analyzing`, `building`, `deploying`,
`health_checking`, `routing`, karena agent memeriksa kesehatan aplikasi sebelum
mengaktifkan route. Event yang tidak dikenal disimpan di timeline tanpa mengubah
status. Agent lama tidak mengirim event analysis, health, dan route, jadi
deployment-nya melompati status itu: `deploying` langsung ke `succeeded`.

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
stack di console, dan lagi lewat `POST /api/v1/app/projects/{project}/inspection`;
pinned ke node ber-capability `project-inspection`, `deployment_id` null, tanpa
`expires_at`, idempoten per commit (`inspect:{project}:{sha}`, ditambah suffix
saat percobaan sebelumnya gagal, kedaluwarsa, atau dibatalkan). Payload mengikuti `InspectProjectPayload`:

```json
{ "repository_url": "https://github.com/example/app.git", "commit_sha": "…", "repository_access": "public", "root_directory": "apps/web" }
```

`root_directory` opsional dan tambahan (revisi protocol tetap 4), hanya dikirim bila
project mengisinya, dengan aturan yang sama seperti pada `DeployProject`. Agent yang
belum mengenalnya mengabaikannya dan menginspeksi root repository. API menyimpan nilai
yang dikirim bersama hasil inspeksi, karena `ProjectInspection` tidak memuatnya.

Event `project.inspection.started`/`completed` dan log-nya disimpan sebagai
command report. Result `complete` dibaca sebagai `ProjectInspection`
(`repository_url`, `commit_sha`, `dockerfile_found`, `env_example_found`,
`compose_found`, `manifests[]`, `package_manager`, `railpack`) dan disimpan ke
`projects.inspection`; `fail` menyimpan `error_code` sebagai
`inspection_error_code`. Hanya command inspeksi terbaru untuk sebuah project
yang boleh menulis hasil — completion command yang sudah disusul diabaikan.
Result juga boleh memuat `exposed_ports`, tambahan pada revisi 4: daftar port TCP
dari `EXPOSE` di stage terakhir Dockerfile, `[]` bila tidak ada. Agent yang mengirimnya
selalu mengirim field ini, jadi result tanpa field berasal dari agent lama. API
menurunkan `detected_port` darinya:

- Tepat satu port: port itu menjadi `detected_port`.
- Kosong, lebih dari satu port, atau inspeksi atas `root_directory` yang bukan nilai
  project saat ini: `detected_port` menjadi `null`.
- Field tidak ada, atau result `null` dari noop runtime: `detected_port` tidak diubah.

Entri yang bukan integer 1 sampai 65535 dibuang, dan duplikat digabung. Mengubah
`root_directory` project juga mengosongkan `detected_port`, karena port itu milik
direktori yang diinspeksi. `DeployProject.container_port` memakai
`Project::effectivePort()`: `configured_port`, lalu `detected_port`, lalu 3000.

Result juga boleh memuat `workspace`, tambahan pada revisi 4 (sakala-agent #113):
`null` tanpa workspace, atau `{ manager, root_directory, package_directory,
package_name, build_command, start_command, static_output_directory }` bila
`root_directory` adalah package workspace pnpm, npm, Yarn, atau Bun yang root-nya ada
di atasnya. Result tanpa field berasal dari agent lama. API menyimpannya di
`projects.inspection.workspace` dan menampilkannya sebagai `stack.monorepo`
(lihat [API Conventions](API_CONVENTIONS.md#inspeksi)). Nilai dari agent tidak
dipercaya: `manager` di luar `npm`, `pnpm`, `yarn`, `bun`, atau `root_directory` dan
`package_directory` yang bukan path relatif tanpa segmen `.`/`..`, membuang seluruh
objek; field opsional yang tidak cocok (perintah multi-baris atau di atas 2000
karakter, nama package di atas 214 karakter, path tidak aman) menjadi `null`.

Result juga boleh memuat `env_example` dan `database_hint`, tambahan pada revisi 4
(kontrak bersama sakala-agent untuk wizard env dan database). Agent yang mengenalnya
selalu mengirim keduanya, jadi result tanpa field berasal dari agent lama dan disimpan
sebagai `null`. API menyimpannya di `projects.inspection` dan menampilkannya di
`inspection` (lihat [API Conventions](API_CONVENTIONS.md#contoh-environment-dan-petunjuk-database)).
Yang dibaca API:

```json
"env_example": {
  "file": ".env.example",
  "entries": [{ "key": "APP_NAME", "example_value": "Buku tamu Sakala" }, { "key": "APP_KEY", "example_value": null }],
  "truncated": false
},
"database_hint": {
  "likely": true,
  "engine": "postgresql",
  "signals": [{ "kind": "env_variable", "file": ".env.example", "name": "DATABASE_URL" }],
  "suggested_release_command": "npx prisma migrate deploy"
}
```

- `env_example` (objek atau `null`): `file` harus salah satu dari `.env.example`,
  `.env.sample`, `.env.dist`, dan `entries` harus array; selain itu seluruh objek
  dibuang. Item yang `key`-nya tidak cocok dengan aturan variabel proyek
  (`^[A-Z_][A-Z0-9_]*$`, paling panjang 128; agent boleh mengirim huruf kecil, API
  membuangnya) atau mengulang kunci sebelumnya dibuang. `example_value` yang bukan
  string menjadi `null`, yang lebih dari 200 karakter dipotong. Paling banyak 200 item;
  `truncated` menjadi `true` bila agent mengirimnya atau masih ada kunci valid setelah
  item ke-200.
- `database_hint` (objek atau `null`): `engine` di luar `postgresql`, `mysql`,
  `sqlite`, `unknown` menjadi `unknown`. Signal dengan `kind` yang tidak dikenal
  (`env_variable`, `laravel_migrations`, `prisma_schema`, `django_settings`,
  `rails_database_config`, `package_dependency`), atau `file` yang bukan string satu
  baris paling panjang 255, dibuang; `name` yang bukan string satu baris paling panjang
  214 menjadi `null`; duplikat digabung; paling banyak 16. `suggested_release_command`
  di luar `php artisan migrate --force`, `python manage.py migrate`,
  `npx prisma migrate deploy`, `bin/rails db:migrate` menjadi `null`. `likely` selalu
  dihitung ulang dari signal yang tersisa.

Result juga boleh memuat `frameworks`, tambahan pada revisi 4: framework yang bisa
membuat halaman saat build, yang namanya ada di `dependencies` atau `devDependencies`
`package.json` direktori yang diinspeksi (direktori yang sama yang dibaca
`database_hint`). Agent yang mengenalnya selalu mengirimnya, `[]` bila tidak ada atau
`package.json` tidak terbaca, jadi result tanpa field berasal dari agent lama dan
disimpan sebagai `null`. API menyimpannya di `projects.inspection.frameworks` untuk
[`VariableBuildPolicy`](API_CONVENTIONS.md#variabel-yang-memengaruhi-build); field ini
tidak ditampilkan ke Console.

```json
"frameworks": ["astro", "gatsby"]
```

| Nilai | Nama package |
| --- | --- |
| `next` | `next` |
| `nuxt` | `nuxt`, `nuxt3` |
| `astro` | `astro` |
| `sveltekit` | `@sveltejs/kit` |
| `gatsby` | `gatsby` |
| `remix` | `@remix-run/dev` |
| `react-router` | `@react-router/dev` (framework mode; library `react-router` saja tidak) |
| `tanstack-start` | `@tanstack/react-start`, `@tanstack/solid-start`, `@tanstack/start` |
| `eleventy` | `@11ty/eleventy` |
| `docusaurus` | `@docusaurus/core` |
| `vitepress` | `vitepress` |

- `frameworks` (array string atau `null`): nilai di luar tabel (enum `DetectedFramework`)
  dan item yang bukan string dibuang, duplikat digabung, lalu diurutkan. Nilai yang bukan
  array disimpan sebagai `null`, sama seperti agent lama. Array yang tersisa kosong
  berarti tidak ada framework yang dikenal, dan klasifikasi lanjut ke sinyal Railpack.

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
  Aksi `restart_log_follower` memasang follower di bawah identitas
  `DeployProject` asli milik workload, bukan command reconcile: item
  `actions_applied` membawa `command_id` deploy tersebut, dan log setelahnya
  masuk ke `POST /commands/{deploy-id}/logs` — yaitu timeline deployment dari
  command `DeployProject` yang sudah `Succeeded` (lihat "Events dan logs").
  Audit menyimpan daftar ini sebagai `log_follower_command_ids`.
- `POST /api/agent/v1/agents/{agent}/cleanup` → `CleanupRuntime` node-level
  dengan payload `{ "approved": true, "targets": [stale_workspaces|
  stale_images|stale_routes] }`. `approved` hanya ditulis control plane dan
  ditolak (`422`) bila dikirim client. Node harus aktif dan eligible; satu
  cleanup in-flight per node. Counter hasil (`cleaned_workspaces`,
  `cleaned_routes`, `reclaimed_image_bytes`) dicatat ke audit.

Keduanya menerima `Idempotency-Key` dengan semantik project/node control.

### StopProject

Lifecycle command yang membawa identitas (`project_id`, `deployment_id`) dan
payload opsional berikut. Field ini tambahan di dalam protocol revision 4:

| Field | Tipe | Arti |
| --- | --- | --- |
| `remove_images` | boolean, opsional, default `false` | Bila `true`, setelah container hilang agent juga menghapus semua image `sakala/project-<project_id>` yang tidak dipakai container mana pun. Result-nya lalu menambah `removed_images` dan `reclaimed_image_bytes`. |

API hanya mengirim `{ "remove_images": true }` pada stop yang dibuat karena
project dihapus: `DELETE /api/v1/app/projects/{project}` dan penghapusan akun
(`DeleteProjectAction`), attempt yang tetap sukses setelah project dihapus
(`TransitionDeploymentAction`), dan stop ulang harian dari `projects:purge-deleted`.
Project yang sedang tidur, atau yang container-nya disimpan setelah suspend, juga
mendapat stop ini saat dihapus, karena `SleepProject` tidak menghapus container.
Stop lain (admin stop, suspend, finalisasi deploy yang tertunda) mempertahankan
image supaya project bisa di-rollback atau dijalankan lagi, dan dikirim sebagai `{}`.
API tidak pernah mengirim `false` atau nilai lain.

Agent sampai v0.3.0 tidak membaca payload `StopProject` sama sekali (payload
di-deserialisasi sebagai JSON bebas dan tidak dipakai untuk stop), jadi field ini
diabaikan tanpa error dan image project yang dihapus tetap tertinggal seperti
sebelumnya. Karena itu protocol revision tidak dinaikkan.

### SleepProject

Lifecycle command yang membawa identitas (`project_id`, `deployment_id`) dan
payload opsional berikut. Field ini tambahan di dalam protocol revision 4:

| Field | Tipe | Arti |
| --- | --- | --- |
| `page` | string, opsional, `waking` atau `unavailable`, default `waking` | Halaman yang dilayani node di domain project selama tidur. `waking` adalah halaman 503 yang memanggil endpoint bangun publik lalu memuat ulang. `unavailable` adalah halaman statis bahwa project tidak tersedia, yang tidak pernah meminta bangun. |

API hanya mengirim `{ "page": "unavailable" }` untuk sleep karena suspend
(`POST /api/v1/admin/projects/{project}/suspend` dan sleep untuk deployment yang
selesai setelah suspend), dan hanya ke node yang punya capability `idle-sleep`.
Node tanpa capability itu, dan sleep karena sepi, menerima `{}`. API tidak pernah
mengirim `waking`.

Agent v0.7.0 adalah agent pertama yang mengiklankan `idle-sleep`. Ia
men-deserialisasi payload command sebagai JSON bebas (`serde_json::Value`) dan
tidak membaca payload `SleepProject` sama sekali, jadi `page` diabaikan tanpa error
dan suspend tetap menampilkan halaman bangun miliknya. Agent v0.7.1 membaca
`page`. Agent sebelum v0.7.0 tidak pernah menerima field ini. Karena itu protocol
revision tidak dinaikkan dan tidak ada capability baru.

### Perintah database

Tambahan di dalam protocol revision 4 untuk database PostgreSQL terkelola (ADR-020), cocok dengan sakala-agent `docs/AGENT_API.md` bagian "Database host" dan `examples/commands/database-commands.json`. Enam command type baru, `CreateDatabase`, `DropDatabase`, `RotateDatabasePassword`, `LockDatabase`, `UnlockDatabase`, dan `InstallDatabaseExtension`, hanya ditawarkan ke node yang mengiklankan capability `managed-postgres`. Agent lama gagal men-deserialisasi command type yang tidak dikenalnya, dan ia tidak pernah mengiklankan capability ini, jadi ia tidak pernah menerimanya; revisi protocol tidak dinaikkan. Keenamnya:

- dipin saat dibuat ke node yang menampung database itu (dipilih sekali saat database dibuat, lihat [Database](API_CONVENTIONS.md#penempatan)) dan tidak pernah ditetapkan ulang oleh `agent:assign-commands`;
- tidak punya `expires_at`, jadi menunggu node itu selama apa pun ia offline;
- membawa `project_id` dan `deployment_id` `null`; report-nya disimpan di `agent_command_reports`;
- memakai lease biasa (`SAKALA_COMMAND_TIMEOUT_SECONDS` + grace, karena payload tidak membawa `command_timeout_seconds`), dan lease yang habis berarti gagal (`command_lease_expired`);
- tidak pernah diblokir oleh proyek yang ditangguhkan.

Payload:

| Command | Payload | Result agent (tidak dibaca API) |
| --- | --- | --- |
| `CreateDatabase` | `{ database, role, password }` | `{ database, role, created }` |
| `DropDatabase` | `{ database, role }` | `{ database, role, dropped }` |
| `RotateDatabasePassword` | `{ role, password }` | `{ role }` |
| `LockDatabase` | `{ database }` | `{ database, role, locked: true, terminated_connections }` |
| `UnlockDatabase` | `{ database }` | `{ database, role, locked: false, terminated_connections: 0 }` |
| `InstallDatabaseExtension` | `{ database, extension }` | `{ database, extension, created }` |

- `database` dan `role` adalah dua nilai acak yang berbeda, masing-masing `p_` diikuti 20 huruf kecil dan angka (agent menerima `^p_[a-z0-9]{16,40}$`). Satu role memiliki tepat satu database, dan nama tidak pernah dipakai ulang.
- `password` adalah 40 huruf dan angka (agent menerima 24 sampai 128 karakter ASCII yang bisa dicetak, tanpa spasi). Ia dikirim **plaintext** hanya kepada node target, seperti `environment` di `DeployProject`; di `agent_commands.payload` nilainya terenkripsi, dan node lain yang meminta payload itu ditolak. API meredaksi password database (yang tersimpan dan yang dibawa command) dari `error_message`, event, dan log command ini, dan menghapus salinannya dari payload command setelah database di-drop. Result agent tidak pernah memuat password.
- `extension` adalah salah satu dari `pgcrypto`, `citext`, `pg_trgm`, `unaccent`, `hstore`, `vector`. API menolak nilai lain sebelum membuat command.
- Lock memakai `NOLOGIN` pada role owner dan mengakhiri sesi role itu, bukan `ALLOW_CONNECTIONS false`, supaya backup malam sebagai superuser tetap berjalan. `CreateDatabase` yang diulang tidak membuka lock. `CONNECTION LIMIT` (5) di role dan database ikut menghitung sesi superuser, jadi backup yang berjalan memakai satu slot.

Efek di API:

| Command | `complete` | `fail` atau kedaluwarsa |
| --- | --- | --- |
| `CreateDatabase` | `provisioning` → `ready`, audit `database.provisioned` | `provisioning` → `failed`, audit `database.provisioning_failed` dengan `error_code` |
| `DropDatabase` | `deleted`, baris di-soft delete, password dikosongkan, audit `database.deleted` | tetap `deleting`, audit `database.drop_failed` |
| `RotateDatabasePassword` | password baru disimpan, proyek yang terpasang menunjukkan `redeploy_required`, audit `database.password_rotated` | password lama dipertahankan, audit `database.password_rotation_failed` |
| `LockDatabase` | `ready` → `locked`, audit `database.locked` | tidak berubah, audit `database.lock_failed`; sapuan kuota meminta lagi |
| `UnlockDatabase` | `locked` → `ready`, audit `database.unlocked` | tidak berubah, audit `database.unlock_failed`; sapuan kuota meminta lagi |
| `InstallDatabaseExtension` | audit `database.extension_installed` | audit `database.extension_install_failed` |

`RotateDatabasePassword` harus dilaporkan `complete` hanya bila password baru sudah berlaku, dan `fail` hanya bila password lama masih berlaku: API menyimpan password baru tepat saat `complete`. Hasil yang datang setelah database berpindah ke status lain tidak mengembalikannya: lock yang terlambat tidak mengunci database yang sedang dihapus. Kegagalan command terbaru sebuah database tampil di `last_failure` resource database (lihat [Database](API_CONVENTIONS.md#database)); kode agent dan klasifikasinya:

| `error_code` | Bisa diperbaiki pengguna | Keterangan |
| --- | --- | --- |
| `database_extension_not_allowed` | ya | Extension di luar allow-list. API sudah menolaknya lebih awal. |
| `database_extension_unavailable` | tidak | File extension tidak ada di host database, misalnya `vector` di `postgres:18-alpine`. |
| `database_admin_unavailable` | tidak | Agent tidak bisa terhubung ke instance; node juga melaporkan `degraded`. Aman diulang nanti. |
| `database_not_found` | tidak | Role atau database tidak ada di node. |
| `database_conflict` | tidak | Database dimiliki role lain, role memiliki database lain, atau sejenisnya. Tidak ada yang diubah; periksa manual. |
| `database_operation_failed` | tidak | PostgreSQL menolak statement; pesan berisi SQLSTATE. |
| `database_name_invalid`, `database_password_invalid` | tidak | Bug di API. |
| `invalid_runtime_command`, `unsupported_runtime_command` | tidak | Payload tidak terbaca, atau node tanpa peran database; bug penempatan. |

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
  installation yang `active` dengan repository yang sama, dan orang yang menjadi
  sandaran akses project masih terhubung ke installation tersebut — selain itu
  `409` "GitHub App no longer has access…". Sandaran akses adalah pemilik untuk
  project di workspace pribadi, dan untuk project tim owner atau member saat ini
  yang terhubung ke installation dan punya identitas GitHub tertaut (pembuat
  project lebih dulu); tanpa orang seperti itu lease ditolak. Pada installation
  di akun organisasi GitHub, orang itu hanya jangkar dan tokennya tidak dipakai
  untuk membaca repository. Lihat
  [Akses GitHub untuk project tim](API_CONVENTIONS.md#akses-github-untuk-project-tim).

Kecuali untuk project tim pada installation organisasi, API juga memeriksa ke GitHub
dengan token milik orang itu bahwa ia masih bisa membaca repository (grant disimpan 5 menit, penolakan 1 menit). Bila tidak, command
langsung ditutup `Failed` dengan `error_code = repository_access_revoked`, lalu API
menjawab `409` dengan `code` yang sama. Jawaban yang sama berlaku bila GitHub menolak
mencetak token (`404` atau `422`) karena repository sudah tidak termasuk installation;
kegagalan GitHub lain tetap menjadi error server. Bila GitHub tidak bisa ditanya, jawaban terakhir
berlaku sampai 1 jam; setelah itu kodenya `repository_access_check_failed`. `fail` dari
agent setelahnya tetap `204` dan tidak mengganti kode itu.

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

Menerima satu objek atau batch `{ "events": [...] }` / `{ "logs": [...] }`.
Agent v0.2.0 **hanya** mengirim bentuk batch dengan header `Idempotency-Key`
(UUID v4 per request, dipakai ulang saat retry); bentuk satu objek tetap
diterima untuk v0.1.0. Aturan idempotency, redaction, bounds (`413`/`422`), dan
budget kumulatif log dijelaskan di [Konvensi API](API_CONVENTIONS.md).

Response `200` **wajib** membawa acknowledgement penuh
`{ "data": { "accepted_count", "duplicate_count", "first_sequence",
"last_sequence" } }` dengan `accepted_count == jumlah item batch`,
`duplicate_count <= accepted_count`, dan `last_sequence >= first_sequence`.
Perlakuan agent v0.2.0 terhadap response report:

- `200` dengan acknowledgement valid, atau `204` tanpa body: terkirim.
- Kegagalan transport atau body-read, `408`, `429`, `5xx`: retry dengan
  `Idempotency-Key` yang sama (backoff terbatas). Bila response pertama
  hilang setelah API mempersist batch, retry ini dijawab
  `duplicate_count == accepted_count` dengan sequence yang sama.
- `200` yang body-nya terbaca tetapi parsial atau tidak sesuai kontrak
  (`InvalidReportAcknowledgement`): batch dianggap *tidak terkirim* dan
  delivery log command tersebut **dihentikan tanpa retry**.
- `409`, `422`, `413`: delivery log command tersebut dihentikan tanpa retry.

Batch kosong ditolak `422` sebelum acknowledgement apa pun dibentuk.
Fixture body batch dari v0.2.0 direplay oleh
`tests/Feature/Api/V1/Agent/AgentReportBatchTest.php`.

Untuk command yang memiliki `deployment_id`, report masuk ke timeline
deployment (`deployment_events`/`deployment_logs`) dan dibroadcast. Untuk
command tanpa deployment (`InspectProject`, `CleanupRuntime`, `DrainNode`,
`ResumeNode`, dan command database), report disimpan di `agent_command_reports` dengan sequence per
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
  (`204`), atau `Cancelled` bila `error_code = runtime_cancelled`. Sudah `Failed`
  → `204`. Sudah `Cancelled` dan kodenya `runtime_cancelled` → `204`. `Succeeded` → `409`. `error_code` disanitasi ke
  `[A-Za-z0-9._-]` (≤ 64); `error_message` dibersihkan dari control/bidi
  character, diredaksi, lalu dibatasi (≤ 1000). Untuk `DeployProject`, pesan ini
  dibatasi lagi ke 500 karakter dan ditampilkan ke console sebagai
  `failure.detail`, jadi agent harus mengirim pesan yang aman dibaca student.
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
Agent v0.2.0 membaca `terminal_at` bila ada dan menyertakannya dalam pesan
konflik terminal; `null` ditoleransi, sehingga field ini adalah bagian kontrak.
Payload command dan credential tidak pernah dipantulkan. Konflik
`Idempotency-Key` pada report memakai `{ "message": "…" }` dengan `409`.

### Perintah bucket

Tambahan di dalam protocol revision 4 untuk bucket S3 terkelola (ADR-021), cocok dengan kontrak `OBJECT_STORAGE_CONTRACT_2026-11-23.md` bagian 3.4 dan sakala-agent `examples/commands/bucket-commands.json`. Enam command type baru, `CreateBucket`, `DeleteBucket`, `RotateBucketKey`, `SetBucketQuota`, `LockBucket`, dan `UnlockBucket`, hanya ditawarkan ke node yang mengiklankan capability `managed-s3`. Agent lama tidak pernah mengiklankannya, jadi tidak pernah menerima command type yang tidak dikenalnya; revisi protocol tidak dinaikkan. Aturan pengiriman sama dengan [Perintah database](#perintah-database): dipin ke node bucket saat dibuat, tidak pernah ditetapkan ulang oleh `agent:assign-commands`, tanpa `expires_at`, `project_id` dan `deployment_id` `null`, lease biasa, dan tidak pernah diblokir oleh proyek yang ditangguhkan.

| Command | Payload | Result agent (tidak dibaca API) |
| --- | --- | --- |
| `CreateBucket` | `{ bucket: string, access_key_id: string, secret_access_key: string, quota_mb: integer }` | `{ bucket, access_key_id, quota_mb, created: bool }` |
| `DeleteBucket` | `{ bucket, access_key_id }` | `{ bucket, deleted: bool }` |
| `RotateBucketKey` | `{ bucket, access_key_id, secret_access_key }` | `{ bucket, access_key_id }` |
| `SetBucketQuota` | `{ bucket, quota_mb: integer }` | `{ bucket, quota_mb }` |
| `LockBucket` | `{ bucket, access_key_id }` | `{ bucket, access_key_id, locked: true }` |
| `UnlockBucket` | `{ bucket, access_key_id }` | `{ bucket, access_key_id, locked: false }` |

- `bucket` adalah `b-` diikuti 20 huruf kecil dan angka acak (agent menerima `^b-[a-z0-9]{16,40}$`), tidak pernah diturunkan dari input pengguna dan tidak pernah dipakai ulang. Nama policy RustFS sama dengan nama bucket.
- `access_key_id` adalah `SK` diikuti 18 huruf besar dan angka (agent menerima `^SK[A-Z0-9]{16,38}$`), sekaligus nama user RustFS. Nilainya tetap saat rotasi.
- `secret_access_key` adalah 40 huruf dan angka (agent menerima `^[A-Za-z0-9]{32,128}$`), dikirim **plaintext** hanya ke node target; di `agent_commands.payload` nilainya terenkripsi, dan node lain yang meminta payload itu ditolak `409`. API meredaksi secret yang tersimpan dan yang dibawa command dari `error_message`, event, dan log command bucket, dan menghapus salinannya dari payload setelah bucket dihapus.
- `quota_mb` adalah integer 1 sampai 1048576.
- Agent tidak melaporkan ukuran upload multipart yang belum selesai (`buckets[].incomplete_bytes` tidak ada), jadi `size_bytes` hanya berisi objek yang sudah selesai.
- RustFS menjawab tulisan pertama setelah setiap perubahan kuota (`CreateBucket`, `SetBucketQuota`) dengan satu `503 SlowDown`; SDK S3 mengulangnya sendiri.
- `CreateBucket` idempoten: mengulanginya mengatur ulang secret ke nilai payload, tidak pernah mengaktifkan key yang dinonaktifkan, jadi bucket `locked` tetap terkunci. Karena itu API tidak membuat `RotateBucketKey` selama `CreateBucket` masih terbuka, dan `buckets:reconcile` melewati bucket dengan rotasi terbuka.

Efek di API:

| Command | `complete` | `fail` atau kedaluwarsa |
| --- | --- | --- |
| `CreateBucket` | `provisioning` → `ready`, audit `bucket.provisioned`; pada bucket `ready` atau `locked` (reconcile) tidak berubah, audit `bucket.reconciled` | `provisioning` → `failed`, audit `bucket.provisioning_failed` |
| `DeleteBucket` | `deleted`, baris di-soft delete, secret dikosongkan dan dihapus dari payload command, audit `bucket.deleted` | tetap `deleting`, audit `bucket.delete_failed`; `DELETE` lagi mengirim ulang |
| `RotateBucketKey` | secret baru dari payload disimpan, fingerprint proyek terpasang dihitung ulang, audit `bucket.key_rotated`, deploy ulang diantrekan bila diminta | kegagalan pasti: secret lama dipertahankan, audit `bucket.key_rotation_failed`. `bucket_outcome_unknown` atau lease habis: `RotateBucketKey` baru dengan payload yang sama (secret baru yang sama) dikirim ulang dengan jeda `available_at`, audit `bucket.key_rotation_resent`. Setelah ada kiriman ulang, `bucket_admin_unavailable` juga dikirim ulang dan kode lain berakhir `unknown`, tidak pernah `failed`; paling banyak 10 kiriman |
| `SetBucketQuota` | `quota_mb` = `reserved_quota_mb` = payload, audit `bucket.quota_changed` | `reserved_quota_mb` = `quota_mb`, audit `bucket.quota_change_failed` |
| `LockBucket` | `ready` → `locked`, audit `bucket.locked` | tidak berubah, audit `bucket.lock_failed` |
| `UnlockBucket` | `locked` → `ready`, audit `bucket.unlocked` | tidak berubah, audit `bucket.unlock_failed` |

Urutan lock hasil: command, lalu bucket, lalu proyek yang terpasang. Hasil yang terlambat tidak pernah memindahkan bucket keluar dari `deleting`. `RotateBucketKey` harus dilaporkan `complete` hanya bila secret baru sudah berlaku, dan `fail` hanya bila secret lama masih berlaku. Kegagalan terbaru tampil di `last_failure` resource bucket, semuanya `user_fixable: false`:

| `error_code` | Keterangan |
| --- | --- |
| `bucket_capacity_exceeded` | Total kuota node atau batas sisa disk akan terlewati. Operator menaikkan kapasitas atau memindahkan; tidak dicoba ulang otomatis. |
| `bucket_admin_unavailable` | `docker exec` gagal atau `rc` tidak bisa menjangkau RustFS; node melaporkan `degraded`. Aman diulang nanti. |
| `bucket_not_found` | Bucket atau user tidak ada (rotasi, kuota, kunci, buka kunci); jalankan `buckets:reconcile`. |
| `bucket_conflict` | User RustFS sudah membawa policy lain. Tidak ada yang diubah; periksa manual. |
| `bucket_operation_failed` | `rc` menolak karena alasan lain; pesan berisi ekor stderr yang sudah diredaksi. |
| `bucket_outcome_unknown` | Agent tidak bisa memastikan apakah RustFS sudah menerapkan command. Untuk `RotateBucketKey`, API mengirim ulang command yang sama. Nama kode mengikuti sakala-agent PR #138; samakan dengan rilis agent. |
| `bucket_name_invalid`, `bucket_key_invalid`, `bucket_quota_invalid` | Bug di API. |
| `invalid_runtime_command`, `unsupported_runtime_command` | Payload tidak terbaca, atau node tanpa peran object storage; bug penempatan. |

### Proses worker dan RunJob (ADR-022)

Tambahan di dalam protocol revision 4 untuk worker dan jadwal, cocok dengan sakala-agent main `1f41585` (PR #140, rilis berikutnya v0.18.0): `docs/AGENT_API.md` bagian "Proses worker" dan "Job (RunJob)", serta `docs/COMPATIBILITY.md` bagian "Revision 4 additions for worker processes and scheduled jobs". Keduanya hanya berlaku bila `SAKALA_WORKERS_ENABLED` dan `SAKALA_SCHEDULES_ENABLED` dinyalakan; dengan keduanya mati (default) API tidak pernah mengirim `processes` atau `RunJob`.

**`DeployProject.processes`.** Bila proyek punya definisi worker, payload membawa `processes`, daftar `{ name, command, replicas, resources: { memory_mb, cpu_millis, pids_limit } }` yang diurutkan menurut nama. `memory_mb` dan `cpu_millis` `null` berarti ikut `resources` deployment; `pids_limit` selalu `null`. Definisi dengan `replicas: 0` tetap dikirim. Proyek tanpa definisi tidak mengirim key ini sama sekali. Deployment yang membawa `processes` hanya dipin ke node yang mengiklankan `worker-processes`, baik saat dibuat maupun oleh `agent:assign-commands` (dibaca dari payload lewat `AgentCommand::requestsWorkerProcesses()`). Bila tidak ada node yang mungkin menjalankannya, deploy ditolak `409 PROJECT_WORKER_PROCESSES_UNSUPPORTED`. Rollback memakai `processes` rekaman payload target, dan redeploy yang hanya mengubah worker memakai ulang image live karena worker tidak masuk fingerprint build. `deployments.worker_containers` (jumlah `replicas` worker) dihitung terhadap `max_active_containers` dan `max_worker_containers` saat deploy dan wake.

**`RunJob`.** Command type terakhir di enum. Dibuat oleh `schedules:dispatch` dan "Run now", dipin saat dibuat ke node deployment live (`isPinnedAtCreation`), hanya bila node itu mengiklankan `scheduled-jobs`, dan diblokir untuk proyek yang ditangguhkan. `project_id` dan `deployment_id` (deployment live) selalu terisi. Payload:

| Field | Sumber |
| --- | --- |
| `command`, `timeout_seconds` | Jadwal saat run dibuat (`timeout_seconds` 1 sampai 600, default 300). |
| `commit_sha`, `container_port` | Deployment live. |
| `reuse_image_from_deployment_id` | Hanya bila deployment live memakai ulang image (`Deployment::imageBuilderId()` bukan dirinya sendiri). |
| `environment`, `resources`, `log_bounds` | Disalin dari payload `DeployProject` deployment live; `environment` tetap terenkripsi di database dan hanya didekripsi untuk node yang dipin, sama seperti `DeployProject`. |

`idempotency_key` adalah `schedule-run:{schedule_id}:{slot UTC ISO 8601}` untuk slot, dan `schedule-run:{schedule_id}:manual:{run_id}` untuk "Run now". `expires_at` adalah tenggat mulai run (10 menit setelah slot, atau slot berikutnya bila lebih cepat). Lease setelah klaim adalah `timeout_seconds` + 30 detik (waktu agent menghentikan, menghapus, dan melaporkan container) + `SAKALA_AGENT_LEASE_GRACE_SECONDS`, bukan `command_timeout_seconds`, jadi job panjang tidak pernah kedaluwarsa saat masih berjalan.

Efek di API, pada run jadwal (`schedule_runs`):

| Laporan | Run |
| --- | --- |
| `claim` | `queued` → `running`, `started_at`. |
| Event `job.finished` | `exit_code` (`null` setelah timeout), `duration_ms`, `oom_killed`, `timed_out` disimpan sebagai kolom. Event `RunJob` disimpan di laporan command (`agent_command_reports`), tidak di timeline deployment live. |
| Log | Disimpan di log deployment live dengan `process: "job"` dan `agent_command_id` command itu; tersedia lewat `GET .../runs/{run}/logs`. |
| `complete` `{ exit_code, duration_ms, oom_killed }` | `succeeded`. |
| `fail` `job_timeout` | `timed_out`. |
| `fail` `runtime_cancelled` | `cancelled` (Stop run, `StopProject`, atau agent berhenti); bukan kegagalan. |
| `fail` kode lain (`job_failed`, `job_image_missing`, `runtime_capacity_exceeded`, `invalid_runtime_command`, `runtime_port_conflict`, ...) | `failed`, dengan `error_code` dan `error_message` yang sudah diredaksi. |
| `expires_at` lewat sebelum klaim | `missed`, `reason: not_started`. |
| Lease habis | `lost`. |

`failed`, `timed_out`, dan `lost` dihitung sebagai kegagalan beruntun jadwal. Stop run memakai `cancel_requested` yang sudah ada.

**Log `process`.** Item log boleh membawa `process` (nama worker atau `job`, pola `^[a-z][a-z0-9-]*$`, paling panjang 30); nilai lain menolak batch dengan `422`. Baris tanpa `process` tetap seperti sebelumnya. Budget `max_total_bytes` dihitung terpisah per `process` (`agent_commands.process_log_bytes`), di samping `reported_log_bytes` untuk baris deployment sendiri, sehingga `422` budget satu worker tidak menghentikan baris lain. Hash idempotensi baris tanpa `process` tidak berubah.

**Heartbeat `process`.** `crashed_details[]` dan `metrics[]` boleh membawa `process` (pola yang sama); `crashed_details[]` juga boleh membawa `restart_count` dan `running`. Item dengan `process` adalah worker: tidak pernah mengubah `runtime_status` proyek, dicatat di `deployments.crashed_processes` (per nama, dengan `since`), ditampilkan sebagai `crash` di `GET .../processes`, dan diberitahukan sekali per worker yang mulai crash (in-app dan email `project_issue`). Worker yang hilang dari daftar lengkap dianggap pulih; daftar terpotong tidak memulihkan apa pun. Sampel `metrics[]` dengan `process` tidak masuk metrik proyek, karena replika 0 worker akan tercampur dengan replika 0 web; sampel itu tetap tersimpan di `metadata` node. `unhealthy_details[].process` diterima tanpa diproses.

**Kegagalan deploy `worker_start_failed`.** Kategori `start`, bisa diperbaiki pengguna; petunjuknya membaca log proses worker itu.

## Enum

Nilai berikut harus identik dengan `sakala-agent-protocol` dan dikunci oleh
`tests/Unit/AgentProtocolEnumTest.php`:

- `AgentCommandType`: `InspectProject`, `DeployProject`, `RestartProject`,
  `StopProject`, `SleepProject`, `WakeProject`, `HealthCheck`, `RefreshRoute`,
  `ReconcileWorkload`, `CleanupRuntime`, `DrainNode`, `ResumeNode`,
  `CreateDatabase`, `DropDatabase`, `RotateDatabasePassword`, `LockDatabase`,
  `UnlockDatabase`, `InstallDatabaseExtension`, `CreateBucket`, `DeleteBucket`,
  `RotateBucketKey`, `SetBucketQuota`, `LockBucket`, `UnlockBucket`, `RunJob`
  (urutan ini juga urutan enum `CommandType` di agent).
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

| Kategori | Bisa diperbaiki student | Error code |
| --- | --- | --- |
| `checkout` | ya | `repository_not_found`, `repository_access_denied`, `repository_auth_failed`, `repository_credential_unavailable`, `repository_commit_not_found`, `repository_access_revoked` (control plane) |
| `checkout` | tidak | `repository_credential_expired`, `repository_checkout_failed`, `runtime_repository_failed`, `repository_access_check_failed` (control plane), `inspection_failed` (control plane: inspeksi yang ditunggu deployment gagal dengan kode yang tidak dikenali) |
| `build` | ya | `runtime_build_failed`, `runtime_root_directory_invalid` |
| `release` | ya | `release_command_failed` (perintah rilis keluar dengan kode selain 0), `release_command_timeout` (perintah rilis melewati batas waktu); versi sebelumnya tetap melayani |
| `start` | ya | `runtime_app_crashed`, `runtime_app_exec_denied`, `runtime_app_host_blocked` (framework menolak domain project: `allowedHosts` Vite atau `ALLOWED_HOSTS` Django; versi sebelumnya tetap melayani), `runtime_port_conflict`, `worker_start_failed` (worker keluar sebelum route pindah; versi sebelumnya tetap melayani bersama worker-nya) |
| `start` | tidak | `runtime_execution_failed`, `runtime_container_failed`, `runtime_workload_not_found`, `runtime_workload_not_running`, `runtime_reporting_failed`, `runtime_filesystem_failed` |
| `health` | ya | `runtime_app_not_listening`, `runtime_app_unhealthy`, `runtime_health_check_failed` |
| `route` | tidak | `runtime_routing_failed` |
| `timeout` | ya | `runtime_timeout` |
| `timeout` | tidak | `command_lease_expired` (control plane) |
| `resource` | ya | `runtime_app_out_of_memory`, `runtime_replicas_exceeded` (replika melebihi batas node; kurangi di pengaturan proyek) |
| `resource` | tidak | `runtime_capacity_exceeded`, `runtime_disk_pressure` |
| `node` | tidak | `runtime_preflight_failed`, `runtime_dependency_failed`, `invalid_runtime_configuration`, `invalid_runtime_command`, `unsupported_runtime_command`, `runtime_cancelled`, serta kode lain yang belum dikenali dan berawalan `runtime_registry` atau `runtime_network` (node gagal menjangkau registry image atau jaringan) |
| `scheduling` | tidak | `command_expired` (control plane), `inspection_timeout` (control plane: inspeksi yang ditunggu deployment belum selesai sampai batas waktunya) |
| `unknown` | tidak | lainnya |

Kolom kedua muncul di console sebagai `failure.user_fixable`. Kode `runtime_app_*`
dan `runtime_port_conflict` dikirim agent setelah v0.2.0, bersama health check
HTTP di agent (status di bawah 500 dianggap sehat). Kontrak `PORT` tidak berubah:
Sakala mengisi `PORT`, dan aplikasi harus listen di `0.0.0.0:$PORT`.
`runtime_port_conflict` berarti environment variable `PORT` milik project berbeda
dengan `container_port`, jadi student cukup menghapus atau menyamakan variabel itu.

`failure.summary` dan `failure.recovery_hint` adalah teks polos. Console
menampilkannya apa adanya, jadi keduanya tidak memakai markdown seperti backtick.

## Fixture

`tests/Fixtures/agent-protocol-v4/` berisi payload wire dari `sakala-agent`
v0.2.0: `commands/*.json` disalin dari `examples/commands/`, `heartbeat/*.json`
diambil dari contoh dokumentasi dan output builder heartbeat pada tag tersebut
(termasuk `detail_counts` dan `stale_routes[].deployment_id`), dan
`reports/*.json` adalah body batch `events`/`logs` dari dokumentasi tag. Test
mereplay payload ini apa adanya (poll harus menyajikan bentuk yang sama;
heartbeat harus diterima; batch harus di-ack penuh dan retry-nya diduplikasi)
tanpa Agent atau Docker sungguhan. Lihat README di folder tersebut.
`commands/bucket-commands.json`, `heartbeat/object-storage-host.json`, dan
`failures/bucket-failures.json` disalin dari `examples/` sakala-agent main `f1fbe78`
(PR #138) sebelum ada tag rilis; periksa ulang terhadap tag rilis agent. Test
memastikan setiap kode di `bucket-failures.json` dikenal API dan ditangani sesuai
bagian 13 kontrak (kirim ulang, `unknown`, atau `failed`).
`commands/deploy-project-processes.json`, `commands/run-job.json`,
`failures/job-failures.json`, `failures/deploy-failures.json`, dan
`heartbeat/worker-processes.json` disalin dari sakala-agent main `1f41585` (PR #140)
sebelum ada tag rilis (v0.18.0); periksa ulang terhadap tag rilis. Test
membandingkan `processes` payload deploy dengan contoh agent byte per byte,
memastikan payload `RunJob` memakai key contoh agent, memetakan setiap body di
`job-failures.json` ke status run, dan mereplay heartbeat worker.

## Belum tersedia pada API

Seluruh kontrak protocol v4 yang diperlukan #55 telah diimplementasikan. Belum
tersedia: `desired_state = maintenance` (agent v0.2.0 hanya mencapainya lewat
bootstrap), migrasi workload lintas node, dan replika satu project yang tersebar ke
beberapa node (semua replika masih di satu node; lihat
[Penempatan](API_CONVENTIONS.md#region-dan-penempatan)).

## Catatan sinkronisasi untuk sakala-agent

Bagian ini mencatat keadaan sinkronisasi pada agent v0.2.0. Seluruh catatan
sinkronisasi yang ditujukan ke v0.1.0 (batch report + `Idempotency-Key`,
`terminal_at`, `Claimed -> Running`, lease, offline, pinning, log setelah
`complete`, sanitasi `fail`, `stale_routes[].deployment_id`, dan koreksi
dokumentasi agent) telah diadopsi atau diputuskan oleh agent di v0.2.0
(`CHANGELOG.md` `[0.2.0]`, `docs/COMPATIBILITY.md`). Tidak ada yang memblokir
kompatibilitas v0.2.0.

Keputusan agent yang tetap berlaku:

1. Response `claim` yang membawa resource command penuh **tidak diadopsi**:
   agent tetap memakai record hasil poll sebagai sumber payload
   (`docs/COMPATIBILITY.md` agent). API tetap mengembalikannya; materialisasi
   `environment` tetap dilakukan pada poll untuk node target.
2. `NodeStatus::busy` tidak pernah dikirim builder heartbeat; API tetap
   menerimanya.
3. `desired_state = maintenance` hanya dicapai agent lewat bootstrap; API
   belum mengeksposnya sebagai control request.
4. Agent menerima `204` tanpa body pada report untuk control plane lama; API
   selalu menjawab `200` dengan acknowledgement penuh.

Tambahan untuk Sakala Edge dan region (belum dikirim agent mana pun saat API ini
dirilis; semuanya opsional sehingga agent lama tetap kompatibel):

5. Kirim `metadata.runtime.region` dari `SAKALA_AGENT_REGION` dan
   `metadata.runtime.edge_address` (usulan env `SAKALA_AGENT_EDGE_ADDRESS`) di setiap
   heartbeat, dengan aturan format di bagian Heartbeat. Validasi di agent saat start
   dengan aturan yang sama, karena nilai salah membuat heartbeat ditolak `422`.
6. Kirim `metadata.workloads.maximum_active_workloads` dari
   `RuntimeCapacity.maximum_active_workloads`.

Tambahan untuk perintah rilis (opsional; agent lama tetap kompatibel karena API
tidak mengirim `release_command` ke node tanpa capability `release-command`):

7. Iklankan capability `release-command`, jalankan `release_command` sesuai
   [DeployProject](#deployproject), dan gagalkan command dengan
   `release_command_failed` atau `release_command_timeout`.

Tambahan untuk metrik project (opsional; node tanpa capability ini tidak
mengirimnya dan grafiknya kosong):

8. Iklankan capability `workload-metrics` dan kirim
   `metadata.workloads.metrics` beserta `metadata.detail_counts.metrics` di setiap
   heartbeat, satu sampel terbaru per replika yang berjalan, dengan aturan di
   bagian Heartbeat.

Tambahan untuk database terkelola (opsional; tanpa node `managed-postgres` API
menolak pembuatan database dengan `409 DATABASE_REGION_UNAVAILABLE`):

9. Sudah diimplementasikan di sakala-agent PR #109 (branch `feat/database-role`):
   capability `managed-postgres`, `metadata.database` di setiap heartbeat,
   `metadata.databases` setelah pembacaan baru, dan keenam command di
   [Perintah database](#perintah-database), dengan urutan `CommandType` yang sama
   seperti di bagian Enum.

Tambahan untuk redeploy tanpa build (opsional; node tanpa capability ini tidak
pernah menerima field-nya dan selalu mem-build):

10. Iklankan capability `image-reuse` (driver `docker` saja), tulis label
    `dev.sakala.builder` dan, untuk build dari root workspace JavaScript,
    `dev.sakala.package-directory` pada setiap image, pakai ulang image sesuai
    `reuse_image_from_deployment_id` di [DeployProject](#deployproject), dan
    kirim `deployment.image.reused` atau `deployment.image.reuse_skipped`.

Tambahan untuk rollback (opsional; tanpa capability ini rollback tetap bekerja,
hanya tidak dijanjikan tanpa build):

11. Iklankan capability `image-rollback` (driver `docker` saja) dan simpan image
    sesuai [Image rollback](#image-rollback): image live, image bertag
    `previous`, dan GC dengan aturan yang sama.

Tambahan untuk bucket terkelola (opsional; tanpa node `managed-s3` API menolak
pembuatan bucket dengan `409 BUCKET_REGION_UNAVAILABLE` dan `buckets.available`
bernilai `false`):

12. Sudah di sakala-agent main `f1fbe78` (PR #138), belum bertag: capability `managed-s3`, `metadata.object_storage` di setiap heartbeat,
    `metadata.buckets` setelah pembacaan baru, dan keenam command di
    [Perintah bucket](#perintah-bucket), dengan urutan `CommandType` yang sama
    seperti di bagian Enum.
