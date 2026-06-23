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
| `projects` | Metadata repository, generated domain, dan status runtime. |
| `environment_variables` | Key dan value terenkripsi per project. |
| `deployments` | Satu attempt deployment dan snapshot source yang dijalankan. |
| `agent_nodes` | Identitas runtime node, token hash, capability, dan heartbeat terakhir. |
| `agent_commands` | Durable command queue antara API dan agent. |
| `deployment_events` | Timeline state yang dilaporkan agent. |
| `deployment_logs` | Output redacted dari build/runtime. |
| `audit_events` | Jejak tindakan sensitif oleh user, agent, atau sistem. |

Project dimiliki langsung oleh user selama MVP. Model workspace/team baru boleh ditambahkan setelah ownership dan policy multi-user disetujui.

## Status dan Enum

Nilai status disimpan sebagai string dan dicast melalui backed enum di `app/Enums`. Pendekatan ini menjaga type safety di aplikasi tanpa mengunci deployment schema pada native enum PostgreSQL yang lebih sulit diubah.

Nilai `AgentCommandType`, `AgentCommandStatus`, `DeploymentEventLevel`, dan `LogStream` harus tetap sama dengan `sakala-agent-protocol`. Perubahan kontrak wajib dilakukan pada kedua repository dan disertai test compatibility.

## Index Strategy

Index dibuat dari query path yang sudah diketahui:

- daftar project user: `(user_id, status, created_at)`;
- ringkasan runtime user: `(user_id, runtime_status)`;
- riwayat deployment: `(project_id, created_at)`;
- deployment aktif/bermasalah: `(status, created_at)` dan `(failure_code, created_at)`;
- polling command: `(status, available_at, created_at)`;
- polling node tertentu: `(agent_node_id, status, available_at)`;
- timeline/log: `(deployment_id, occurred_at|recorded_at)`;
- audit actor/subject: `(type, id, created_at)`.

Jangan menambahkan index untuk setiap kolom. Setiap index menambah biaya write dan storage. Query baru yang penting harus divalidasi dengan `EXPLAIN (ANALYZE, BUFFERS)` pada PostgreSQL sebelum menambah index.

## Concurrency dan Idempotensi

- Nomor `deployments.sequence` unik per project. Action pembuat deployment harus mengalokasikannya di dalam transaction dan mengunci project row.
- `agent_commands.idempotency_key` mencegah command ganda akibat retry HTTP/job.
- Claim command harus atomic. Implementasi PostgreSQL dapat memakai transaction dengan `FOR UPDATE SKIP LOCKED` pada query polling.
- Sequence event/log unik per deployment. Action penerima report harus mengalokasikan atau memvalidasi sequence secara atomic agar retry memakai sequence yang sama dan tidak menggandakan baris.
- Status transition tidak boleh dilakukan langsung dari controller; gunakan Action yang memvalidasi state saat ini.

## Secret dan Retention

OAuth token dan environment value memakai encrypted cast Laravel. Agent bearer token tidak disimpan; database hanya menyimpan SHA-256/HMAC hash dan prefix untuk identifikasi. Model menyembunyikan seluruh nilai sensitif dari serialization.

Log/event disimpan di database untuk MVP. Sebelum volume produksi meningkat, tentukan retention policy dan evaluasi partitioning PostgreSQL atau object storage. Jangan membuat pencarian tanpa batas atas; endpoint timeline/log harus memakai cursor pagination dan urutan sequence.

## Data Lokal

Seeder hanya berjalan pada environment `local` dan `testing` serta aman dijalankan ulang:

```bash
php artisan migrate:fresh --seed
```

Fixture menyediakan user demo, OAuth account, dua project, node lokal, deployment sukses, command pending, event, log, environment variable, dan audit event. Tidak ada credential produksi di dalam fixture.
