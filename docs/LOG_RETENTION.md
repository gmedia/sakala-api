# Pilot Log Retention Policy

Dokumen ini mendefinisikan kebijakan retensi log, batasan akses, tanggung jawab redaksi rahasia, batas kapasitas, ketiadaan garansi SLA durabilitas, serta mekanisme pembersihan (cleanup) untuk fase Pilot MVP pada Sakala API.

---

## 1. Latar Belakang dan Tujuan

Pada fase Pilot MVP, Sakala API bertindak sebagai control plane yang menyimpan log proses build aplikasi, log output runtime container, serta event timeline deployment di database PostgreSQL.

Tujuan kebijakan ini adalah:
1. Memberikan batasan yang jujur dan transparan bagi pengguna pilot sebelum mereka mengandalkan penyimpanan log.
2. Mencegah kejenuhan kapasitas storage database (*disk exhaustion*) akibat append-only logging tak terbatas.
3. Menetapkan batasan akses yang tegas antara pengguna pemilik project (*owner*), administrator platform (*admin*), dan mesin agen (*agent*).
4. Menjelaskan kemampuan dan limitasi teknis dari sistem penyaringan rahasia (*secret redaction*).
5. Menyediakan prosedur pembersihan terjadwal dan manual serta panduan respon insiden bila terjadi kebocoran credential.

---

## 2. Klasifikasi Jenis Log

Sakala API membagi log operasional menjadi tiga jenis:

| Jenis Log | Sumber Penghasil | Stream / Level | Deskripsi & Kegunaan |
| --- | --- | --- | --- |
| **Build Logs** | Agent runner selama fase build/cloning | `stdout`, `stderr` | Output kompilasi kode, resolusi dependensi, dan pembuatan container image. Digunakan terutama untuk mendiagnosis kegagalan deployment (*build failure*). |
| **Runtime Logs** | Container aplikasi yang sedang berjalan | `stdout`, `stderr` | Output standar dari proses aplikasi pengguna (request log, application log, error traces). Digunakan untuk monitoring dan debugging langsung dari Console. |
| **Deployment Events** | Agent lifecycle transitions & API state machine | Level: `info`, `warn`, `error` | Rekaman audit transisi state deployment (`queued`, `cloning`, `analyzing`, `building`, `deploying`, `routing`, `health_checking`, `succeeded`, `failed`, `cancelled`) beserta metadata eksekusi. |

Semua record log disimpan pada tabel `deployment_logs`, sedangkan record event disimpan pada tabel `deployment_events`. Keduanya bersifat append-only dengan nomor urut (`sequence`) yang bertambah secara monotonik per deployment.

---

## 3. Durasi Retensi Pilot

Selama fase Pilot MVP, durasi retensi log diatur sebagai berikut:

- **Durasi Retensi Default**: **7 hari** (dikonfigurasi via `SAKALA_LOG_RETENTION_DAYS`, default: `7`).
- **Sifat Retensi**: *Bounded rolling window*. Data log dan event dari deployment terminal yang telah melewati 7 hari sejak waktu terminal memenuhi syarat (*eligible*) untuk dipangkas (*pruned*). Waktu terminal memakai `finished_at`, lalu `cancelled_at`; record legacy yang tidak memiliki keduanya memakai `created_at`.
- **Syarat Status Terminal**: Pemangkasan **hanya** menargetkan deployment yang berada pada status terminal:
  - `succeeded`
  - `failed`
  - `cancelled`
- **Proteksi Deployment Aktif**: Deployment yang masih berstatus aktif (`queued`, `cloning`, `analyzing`, `building`, `deploying`, `routing`, `health_checking`) **tidak akan dipangkas**, terlepas dari usianya, guna menjamin integritas observabilitas runtime yang sedang berjalan.

---

## 4. Batasan Akses (Access Boundaries)

Batasan hak akses terhadap log dan event ditegakkan secara ketat pada layer Policy dan Route Middleware:

```text
+---------------------+-------------------+-----------------------------------+
| Persona / Actor     | Mode Akses        | Boundary & Otorisasi              |
+---------------------+-------------------+-----------------------------------+
| Project Owner       | Read-only         | Terbatas hanya pada project milik  |
| (Console User)      |                   | sendiri ($user->id === project->user_id). |
|                     |                   | Endpoint: GET /api/v1/app/projects/.../logs |
|                     |                   | WebSocket: private-deployment.{id}|
+---------------------+-------------------+-----------------------------------+
| Platform Admin      | Read-only         | Akses investigasi lintas project  |
|                     | (Investigation)   | via ProjectPolicy::before().       |
|                     |                   | Metrik agregasi tanpa raw logs.   |
+---------------------+-------------------+-----------------------------------+
| Infrastructure /    | Operational       | Scheduler, Artisan prune, dan     |
| DB Operator         |                   | SQL manual sesuai akses           |
|                     |                   | operasional infrastrukturnya.     |
+---------------------+-------------------+-----------------------------------+
| Machine Agent       | Write-only        | Terbatas hanya pada command aktif |
| (Runtime Node)      | (Append)          | yang di-claim (agent_node_id).    |
|                     |                   | Endpoint: POST /api/agent/v1/.../logs |
|                     |                   | Tidak memiliki akses GET log/user.|
+---------------------+-------------------+-----------------------------------+
```

### Detail Persona

1. **Project Owner (Pengguna Console)**:
   - Mengakses log melalui endpoint `GET /api/v1/app/projects/{project}/deployments/{deployment}/logs` dan `GET /api/v1/app/projects/{project}/deployments/{deployment}/events`.
   - Menggunakan autentikasi session cookie Sanctum SPA.
   - Hak akses divalidasi oleh `ProjectPolicy@view`. Akses terhadap project milik pengguna lain ditolak dengan respons `403 Forbidden`.
   - Dapat menerima stream log real-time melalui private channel Reverb `deployment.{deployment_id}`.
   - **Tidak memiliki hak write, edit, atau delete** log melalui API.

2. **Platform Admin**:
   - Memiliki otorisasi penuh membaca deployment logs seluruh pengguna untuk keperluan *incident response* dan *troubleshooting* operasional melalui bypass `ProjectPolicy::before()` (`$user->isAdmin()`).
   - Dapat mengakses endpoint metrik agregasi platform (`GET /api/v1/admin/metrics/pilot-validation`) yang menyajikan statistik kegagalan tanpa membocorkan payload log mentah.

3. **Infrastructure / DB Operator**:
   - Menjalankan scheduler runtime yang memicu `php artisan pilot:prune-logs` sesuai akses operasional infrastrukturnya.
   - Dapat menjalankan Artisan prune secara manual dan prosedur SQL cleanup langsung pada database sesuai akses shell/database yang diberikan.
   - Capability ini terpisah dari role `UserRole::Admin` dan tidak diberikan oleh `ProjectPolicy` atau endpoint admin aplikasi.

4. **Machine Agent (Sakala Agent)**:
   - Menggunakan autentikasi Bearer token mesin (`Authorization: Bearer <agent-token>` dan header `X-Agent-Id`).
   - Hanya diizinkan melaporkan (*write/append*) log dan event baru pada endpoint `POST /api/agent/v1/commands/{command}/logs` dan `POST /api/agent/v1/commands/{command}/events`.
   - Hanya dapat melaporkan ke command yang berstatus `Claimed` atau `Running` serta tercatat sebagai pemilik klaim (`agent_node_id === $agent->id`).
   - **Agent tidak memiliki endpoint untuk membaca log** yang sudah tersimpan di API (*write-only contract*).
   - Agent tidak dapat menghapus atau mengubah log yang telah dilaporkan.

---

## 5. Batas Maksimum dan Kuota (Bounds & Quotas)

Untuk menjaga stabilitas performa control plane, payload log dibatasi oleh konfigurasi `sakala.pilot_limits.log_bounds`:

1. **Batas Panjang Baris (`max_line_length`)**: Maksimum **4.096 byte** (4 KB) per baris pesan log. Pesan yang melebihi batas ini ditolak dengan `422 Unprocessable Entity`.
2. **Batas Ukuran Batch (`max_batch_lines`)**: Maksimum **500 baris** per HTTP request report.
3. **Batas Request Body (`max_request_bytes`)**: Maksimum **1 MB** (1.048.576 byte) per payload HTTP request, ditegakkan oleh middleware `LimitAgentReportPayload`. Request yang melampaui batas ini ditolak dengan `413 Payload Too Large`.
4. **Batas Anggaran Kumulatif (`max_total_bytes`)**: Budget akumulasi log maksimum sebesar **10 MB** (10.485.760 byte) per command (`AgentCommand`), bukan per deployment. Penghitungan dilakukan secara atomic pada counter `reported_log_bytes` di tabel `agent_commands`; beberapa command dalam deployment yang sama memiliki budget masing-masing. Jika budget command habis, pelaporan log baru ditolak dengan `422 Unprocessable Entity`.

---

## 6. Tanggung Jawab dan Batasan Redaksi Rahasia (Secret Redaction)

### Model Tanggung Jawab Bersama (*Shared Responsibility*)

Keamanan credential dan data rahasia menerapkan prinsip pertahanan berlapis (*defense-in-depth*):

1. **Layer 1 - Agent Runtime (Primary Scrubber)**: Agent wajib memfilter dan menyensor nilai sensitif di tingkat lokal sebelum payload dikirimkan melalui jaringan ke API.
2. **Layer 2 - API Control Plane (Defense-in-Depth)**: API mengulang proses sanitasi menggunakan `SecretRedactionService` pada setiap baris pesan (`message`) dan struktur `metadata` sebelum persistensi ke PostgreSQL dan sebelum dipublikasikan ke private WebSocket channel Reverb.

### Pola yang Didukung

Penyaringan otomatis oleh `SecretRedactionService` mencakup:
- Prefix token GitHub: `ghp_`, `gho_`, `ghs_`, `github_pat_`.
- Format HTTP header: `Bearer <token>`.
- Format URL dengan basic auth: `https://user:password@host...`.
- Pasangan key-value sensitif (case-insensitive, mendukung snake_case, camelCase, kebab-case): `password`, `token`, `secret`, `app_key`, `authorization`, `api_key`, `access_token`, `refresh_token`, `client_secret`, `database_url`.
- Seluruh key sensitif pada objek bersarang (*nested arrays*) di kolom `metadata`.

### Keterbatasan Teknis yang Harus Dipahami

> [!WARNING]
> **Limitasi Redaksi**: Penyaringan berbasis pattern regex tidak dapat menjamin 100% deteksi seluruh rahasia.

Pengguna dan operator harus memahami keterbatasan berikut:
- **Token Tanpa Pola Standar**: API key dari penyedia pihak ketiga yang tidak memiliki prefix terdaftar (misalnya AWS Secret Access Keys acak, API key custom, string UUID tanpa nama key) tidak dapat dideteksi secara otomatis.
- **Fragmentasi Baris**: Rahasia yang terpotong menjadi beberapa paket stream atau terpecah di tengah string oleh newline tidak akan cocok dengan pola regex tunggal.
- **Payload Terenkripsi atau Terenkode**: Nilai rahasia dalam bentuk Base64, Hexadecimal, URL encoded, atau format terenkripsi tidak didecode untuk inspeksi konten.
- **Tanggung Jawab Kode Aplikasi**: Developer bertanggung jawab penuh memastikan aplikasi dan build script mereka tidak mencetak password, private key, sertifikat TLS, atau file `.env` ke stream `stdout`/`stderr`.
- **Imutabilitas Sisi Klien WebSocket**: Log yang sudah terlanjur dibroadcast ke browser yang sedang membuka console tidak dapat ditarik kembali secara retroaktif dari memori tab browser pengguna jika terjadi kebocoran sebelum deteksi.

---

## 7. Ketiadaan Garansi SLA Durabilitas (No Production Durability/SLA Claim)

> [!IMPORTANT]
> **Pernyataan Pilot MVP**: Sakala API pada fase pilot disediakan sebagai lingkungan pengujian kelayakan sistem dan **BUKAN** layanan produksi ber-SLA tinggi.

1. **Tidak Ada Garansi Arsip Permanen (*No Permanent Archival Guarantee*)**: Database PostgreSQL Sakala API adalah *operational metadata store*, bukan sistem *cold storage* atau *data lake*. Data log tidak disimpan secara permanen.
2. **Ketiadaan SLA Durabilitas**: Sakala tidak memberikan komitmen ketersediaan log 99.9% atau garansi pemulihan data log jika terjadi kegagalan hardware, kerusakan node, atau bencana infrastruktur.
3. **Pembersihan Tanpa Pemberitahuan Sebelumnya**: Pada kondisi darurat kapasitas penyimpanan, migrasi arsitektur, atau kegagalan node, log historis dapat dipangkas sewaktu-waktu demi menjaga kelangsungan operasional API control plane.
4. **Bukan Catatan Kepatuhan (*Compliance*)**: Pengguna dilarang keras mengandalkan log Sakala API sebagai satu-satunya catatan audit hukum, finansial, atau regulasi industri.
5. **Backup dan Restore**: Kebijakan backup database berada pada operator infrastruktur. Backup dapat mempertahankan salinan log setelah cleanup database, dan Sakala tidak menjamin durasi retensi backup, keberhasilan restore, atau penghapusan salinan backup pada waktu yang sama dengan data operasional.

---

## 8. Mekanisme Pembersihan (Cleanup Mechanisms)

Pembersihan log dilakukan secara terukur menggunakan dua pendekatan: command yang dijalankan scheduler harian dan prosedur SQL manual oleh Infrastructure / DB Operator. Runtime wajib menjalankan `php artisan schedule:work` atau memanggil `php artisan schedule:run` secara berkala agar cleanup terjadwal benar-benar berjalan.

### 8.1. Perintah Terjadwal: `php artisan pilot:prune-logs`

Sakala API menyediakan Artisan console command yang didukung oleh `PruneDeploymentLogsAction`:

```bash
# Menjalankan simulasi pembersihan (tanpa menghapus data)
php artisan pilot:prune-logs --dry-run

# Menjalankan pembersihan dengan retensi default (7 hari)
php artisan pilot:prune-logs

# Menjalankan pembersihan dengan batas hari khusus (misal 14 hari)
php artisan pilot:prune-logs --days=14

# Menjalankan pembersihan dengan ukuran batch kustom
php artisan pilot:prune-logs --days=7 --batch=500
```

#### Karakteristik Teknis Action:
- Menghitung tanggal cutoff: `now()->subDays($days)`.
- Mengidentifikasi deployment terminal (`succeeded`, `failed`, `cancelled`) yang waktu terminalnya sebelum tanggal cutoff menggunakan index `(status, finished_at)` atau `(status, cancelled_at)` pada tabel `deployments`. Record legacy tanpa timestamp terminal memakai `created_at` sebagai fallback.
- Melakukan penghapusan secara bertahap (*chunked batches*) untuk mencegah *table lock escalation* pada PostgreSQL.
- Scheduler menjalankan command sekali sehari dengan `withoutOverlapping()` dan `onOneServer()`.
- Cleanup aman untuk diulang. Jika proses terhenti setelah sebagian batch terhapus, proses berikutnya hanya menghapus record eligible yang tersisa.
- Mengembalikan ringkasan data DTO `PruneDeploymentLogsResultData`.

### 8.2. Prosedur SQL Manual untuk Infrastructure / DB Operator

Jika operator perlu melakukan audit kapasitas atau melakukan pembersihan langsung di PostgreSQL:

#### Langkah 1: Evaluasi Kandidat Data dan Estimasi Kapasitas
```sql
-- Periksa ukuran fisik tabel log dan event saat ini
SELECT
    relname AS table_name,
    pg_size_pretty(pg_total_relation_size(relid)) AS total_size,
    pg_size_pretty(pg_relation_size(relid)) AS table_size,
    pg_size_pretty(pg_indexes_size(relid)) AS index_size
FROM pg_catalog.pg_statio_user_tables
WHERE relname IN ('deployment_logs', 'deployment_events');

-- Hitung jumlah baris log yang memenuhi kriteria retensi (> 7 hari setelah deployment terminal)
SELECT COUNT(*) AS eligible_logs_count
FROM deployment_logs l
WHERE l.deployment_id IN (
    SELECT d.id
    FROM deployments d
    WHERE d.status IN ('succeeded', 'failed', 'cancelled')
      AND COALESCE(d.finished_at, d.cancelled_at, d.created_at) < NOW() - INTERVAL '7 days'
);
```

#### Langkah 2: Eksekusi Penghapusan Bertahap (Chunked Deletion)
Untuk menghindari *lock timeout* pada transaksi aktif, hapus baris dalam batasan limit per iterasi:
```sql
-- Contoh batch 5.000 baris
DELETE FROM deployment_logs
WHERE id IN (
    SELECT l.id
    FROM deployment_logs l
    WHERE l.deployment_id IN (
        SELECT d.id
        FROM deployments d
        WHERE d.status IN ('succeeded', 'failed', 'cancelled')
          AND COALESCE(d.finished_at, d.cancelled_at, d.created_at) < NOW() - INTERVAL '7 days'
    )
    LIMIT 5000
);

DELETE FROM deployment_events
WHERE id IN (
    SELECT e.id
    FROM deployment_events e
    WHERE e.deployment_id IN (
        SELECT d.id
        FROM deployments d
        WHERE d.status IN ('succeeded', 'failed', 'cancelled')
          AND COALESCE(d.finished_at, d.cancelled_at, d.created_at) < NOW() - INTERVAL '7 days'
    )
    LIMIT 5000
);

-- Ulangi kedua perintah di atas hingga affected rows menjadi 0.
```

Jalankan SQL menggunakan client terparameterisasi bila menerima nilai dari operator. Jangan menulis credential yang bocor atau nilai rahasia literal ke query maupun shell history.

#### Langkah 3: Verifikasi Pasca-Pembersihan
```sql
-- Pastikan tidak ada log dari deployment aktif yang terhapus
SELECT COUNT(*) AS active_deployments_logs
FROM deployment_logs l
JOIN deployments d ON d.id = l.deployment_id
WHERE d.status NOT IN ('succeeded', 'failed', 'cancelled');

SELECT COUNT(*) AS active_deployments_events
FROM deployment_events e
JOIN deployments d ON d.id = e.deployment_id
WHERE d.status NOT IN ('succeeded', 'failed', 'cancelled');

-- Reklamasi ruang penyimpanan disk jika diperlukan
VACUUM ANALYZE deployment_logs;
VACUUM ANALYZE deployment_events;
```

---

## 9. Prosedur Tanggap Insiden (Incident Procedures)

### 9.1. Kebocoran Kredensial Tidak Sengaja (*Accidental Credential Leakage*)

Jika seorang pengguna atau operator melaporkan adanya credential produksi yang tercetak ke log:

1. **Rotasi Segera**: Pengguna WAJIB segera merotasi/merevoke credential yang bocor pada provider asal (GitHub token, database password, API key).
2. **Identifikasi Baris Log dan Event**: Gunakan `deployment_id` dan rentang `sequence` yang diketahui. Jika hanya memiliki potongan nilai rahasia, gunakan parameter binding pada client SQL yang aman; jangan menaruh nilai rahasia langsung di query atau shell history.
   ```sql
   SELECT id, deployment_id, sequence, recorded_at, message
   FROM deployment_logs
   WHERE deployment_id = :deployment_id
     AND sequence BETWEEN :start_seq AND :end_seq;

   SELECT id, deployment_id, sequence, occurred_at, message
   FROM deployment_events
   WHERE deployment_id = :deployment_id
     AND sequence BETWEEN :start_seq AND :end_seq;
   ```
3. **Penghapusan Tertarget**: Jalankan dalam transaction dan hapus dari kedua tabel.
   ```sql
   BEGIN;

   DELETE FROM deployment_logs
   WHERE deployment_id = :deployment_id
     AND sequence BETWEEN :start_seq AND :end_seq;

   DELETE FROM deployment_events
   WHERE deployment_id = :deployment_id
     AND sequence BETWEEN :start_seq AND :end_seq;

   COMMIT;
   ```
4. **Verifikasi**: Pastikan respons endpoint `/api/v1/app/projects/{project}/deployments/{deployment}/logs` dan `/api/v1/app/projects/{project}/deployments/{deployment}/events` untuk deployment tersebut tidak lagi memuat baris yang bersangkutan.

### 9.2. Kondisi Darurat Kapasitas Disk (*Disk Space Exhaustion*)

Jika monitoring server mendeteksi penggunaan disk PostgreSQL melebihi 85%:

1. Operator menjalankan `php artisan pilot:prune-logs --days=3` untuk memangkas log yang lebih tua dari 3 hari.
2. Jika kapasitas masih kritis, jalankan pemangkasan darurat terparameterisasi yang disetujui operator untuk deployment `cancelled` atau `failed` dengan waktu terminal lebih tua dari 24 jam, terhadap kedua tabel.
3. Jalankan `VACUUM FULL deployment_logs;` di luar jam sibuk untuk mengembalikan space disk fisik ke OS bila PostgreSQL *dead tuples* menumpuk signifikan.

---

## 10. Retensi Usage Signal Records

Tabel `usage_signal_records` menyimpan agregat operasional yang dikumpulkan secara berkala untuk observabilitas pilot. Retensi berlaku sebagaimana berikut:

### Durasi

- **Durasi Retensi**: **30 hari** sejak `collected_at` (dikonfigurasi via `SAKALA_USAGE_SIGNALS_RETENTION_DAYS`, default: `30`).
- Record yang `collected_at`-nya lebih tua dari batas retensi akan dipangkas oleh scheduler harian.

### Koleksi Sinyal

Signal agregat dikumpulkan setiap jam oleh command `usage:signals-collect`. Scheduler menjadwalkan secara *hourly* dengan `withoutOverlapping()` dan `onOneServer()`. Collector memakai window hourly yang sejalan dengan jadwal scheduler, sehingga tiap run menutup tepat satu bucket jam lengkap tanpa tumpang tindih.

Signal types yang dicatat:

| Signal | Deskripsi |
| --- | --- |
| `deployment_attempt` | Jumlah attempt deployment dalam window (tidak ada tag opsional) |
| `successful_deployment` | Jumlah deployment berstatus `succeeded` yang terminal di window |
| `active_projects` | Jumlah project dengan `runtime_status` `running` atau `deploying` |
| `rejected_limits` | Pelanggaran limit pilot yang ditolak (tag: `limit_name` dari daftar limit yang dikenal) |
| `agent_failure` | Jumlah agent command berstatus `failed` yang terminal di window |
| `repeated_build_failure` | Jumlah project dengan ≥N deployment berstatus `failed` dan `failure_code = runtime_build_failed` dalam window (threshold configurable) |
| `manual_intervention` | Penanda intervensi manual oleh operator (tag: `action_code` dari daftar kode intervensi yang dikenal) |

Tag bersifat opsional dan hanya diisi untuk signal yang mendefinisikan allowlist key + validasi value per-type. Field tag **tidak pernah** menampung nilai sensitif seperti token, password, email, atau free-text sembarangan — sanitasi terjadi di `RecordUsageSignalAction` sebelum persistensi.

### Pembersihan

```bash
# Simulasi (tanpa menghapus)
php artisan usage:signals-prune --dry-run

# Eksekusi dengan retensi default (30 hari)
php artisan usage:signals-prune

# Retensi kustom
php artisan usage:signals-prune --days=60 --batch=1000
```

Command berjalan harian melalui scheduler Laravel dengan `withoutOverlapping()` dan `onOneServer()`. Penghapusan dilakukan secara chunked untuk mencegah table lock escalation pada PostgreSQL.

### Audit Trail

Signal `rejected_limits` dan `manual_intervention` secara otomatis membuat `AuditEvent` pada waktu pencatatan sebagai jejak investigasi admin.

---

## 11. Referensi Terkait

- [Arsitektur Sistem (ARCHITECTURE.md)](../ARCHITECTURE.md)
- [Desain Database & Index Strategy (docs/DATABASE.md)](DATABASE.md)
- [Konvensi API & Batasan Report (docs/API_CONVENTIONS.md)](API_CONVENTIONS.md)
- [Dokumentasi Konfigurasi Environment (docs/CONFIGURATION.md)](CONFIGURATION.md)
- [Kebijakan Keamanan (SECURITY.md)](../SECURITY.md)
- [Sakala Agent Security](https://github.com/gmedia/sakala-agent/blob/main/SECURITY.md)
