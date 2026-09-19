# Architecture

## Peran Sistem

Sakala API adalah control plane. Ia menyimpan state dan metadata, melakukan autentikasi/authorization, menerima webhook, membuat command untuk agent, serta mempublikasikan event. Ia tidak menjalankan Docker, build aplikasi, Caddy, atau operasi host.

```text
sakala-console -> HTTPS/JSON -> sakala-api -> database/queue
                                      |
                                      +-> command records <-poll/report-> sakala-agent
```

## Boundary Repository

Frontend berada di `sakala-console`. Eksekusi runtime berada di `sakala-agent`. Referensi jaringan lokal berada di `sakala-infra`. Boundary ini mencegah browser atau Laravel mendapat akses Docker socket.

## API-first

Semua kontrak baru memakai JSON dan versi mayor di URL. Route dikelompokkan berdasarkan audience:

- App API (`/api/v1/app/*`, `/api/v1/auth/*`, `/api/v1/onboarding/*`) untuk console first-party.
- Machine protocol agent (`/api/agent/v1/*`) untuk heartbeat, node-state, polling, claim, credential lease, events/logs, dan completion — diversikan terpisah dari app API karena mengikuti protocol revision `sakala-agent`; kontraknya ada di [Agent API](docs/AGENT_API.md).
- Admin API (`/api/v1/admin/*` untuk project control, `/api/agent/v1/agents/*` untuk provisioning dan lifecycle node) untuk operasi internal yang diautorisasi.
- Webhook endpoint (`/api/v1/webhooks/*`) untuk provider seperti GitHub.

## Persistence

PostgreSQL menyimpan metadata control plane dan durable agent command. Entitas yang melewati boundary API/agent memakai UUIDv7, sedangkan event/log/audit append-only memakai bigint internal. Nilai status didefinisikan sebagai backed enum PHP dan disimpan sebagai string agar kontrak dapat berkembang tanpa migrasi native enum database.

Project dimiliki user secara langsung pada MVP. Workspace/team dan custom domain sengaja belum dimodelkan. Lihat [desain database](docs/DATABASE.md) untuk relasi, pola query, idempotensi, dan strategi index, serta [kebijakan retensi log pilot](docs/LOG_RETENTION.md) untuk batas retensi, model akses, dan prosedur pembersihan log.

## Authentication

Console memakai Sanctum stateful SPA authentication melalui cookie HTTP-only dan proteksi CSRF. Agent tidak memakai session browser; ia memakai bearer token dan identitas agent. GitHub App user-to-server OAuth berjalan pada `/auth/github/*` dengan stateful callback; GitHub App installation menjadi boundary akses repository. Browser kembali ke Console tanpa token, sementara API menyimpan hanya token user terenkripsi dan memakai installation token pendek yang dicache terenkripsi. Endpoint JSON Console tetap berada di `/api/v1/auth/*`.

## Asynchronous Work

Queue digunakan untuk operasi yang tidak perlu menyelesaikan request secara sinkron. Reverb disiapkan untuk status deployment real-time, tetapi broadcaster default tetap `log` sampai credential dan channel domain tersedia.

## Struktur Bertumbuh

Struktur backend dibagi berdasarkan tanggung jawab, lalu dikelompokkan lagi per domain:

```text
app/
├── Actions/                # satu use case atau perubahan state
├── Data/                   # DTO immutable antar-layer
├── Enums/                  # state dan type domain
├── Events/                 # event domain/application
├── Http/
│   ├── Controllers/Api/V1/ # transport HTTP dan orchestration tipis
│   ├── Requests/Api/V1/    # authorization dan validation input
│   └── Resources/Api/V1/   # representasi JSON keluar
├── Jobs/                   # pekerjaan asynchronous
├── Models/                 # persistence dan relationship
├── Policies/               # authorization terhadap resource
├── Services/               # integrasi eksternal atau workflow panjang
└── Support/                # primitive lintas domain yang benar-benar umum
```

Folder hanya dibuat ketika memiliki implementasi nyata. Jangan menambahkan class kosong untuk meniru arsitektur. Domain baru mengikuti vertical slice seperti contoh `System`: controller memanggil Action, Action menghasilkan DTO, lalu Resource membentuk kontrak JSON.

### Cara Membaca Layer

Arsitektur ini dibuat supaya `sakala-api` tetap mudah dibaca saat fiturnya bertambah. Sakala API bukan sekadar CRUD app; ia adalah control plane yang akan mengatur auth, project, deployment, command agent, webhook, logs, policy, dan status runtime.

Alur umum request adalah:

```text
HTTP request
-> route
-> controller
-> form request
-> action
-> service / model / database
-> resource
-> JSON response
```

Contoh yang sudah ada:

```text
GET /api/v1/status
-> ServiceStatusController
-> GetServiceStatusAction
-> ServiceStatusData
-> ServiceStatusResource
-> { data: ... }
```

Layer ini bukan dibuat supaya project terlihat rumit. Tujuannya agar setiap perubahan punya tempat yang jelas. Kalau endpoint baru ditambahkan, reviewer bisa langsung melihat bagian HTTP, validasi, use case, output JSON, dan state database tanpa mencari logic yang tercecer.

### Controller

Controller adalah pintu masuk HTTP. Tugasnya tipis:

- menerima request dari route;
- menerima dependency dari container Laravel;
- memanggil Form Request jika endpoint menerima input;
- memanggil Action;
- mengembalikan Resource atau response status.

Controller tidak berisi query panjang, validation rules, business rule, generate slug, hitung resource plan, panggil Docker, atau payload JSON manual yang kompleks.

Contoh bentuk yang diharapkan:

```php
public function store(StoreProjectRequest $request, CreateProjectAction $action): ProjectResource
{
    $project = $action->handle($request->user(), $request->toData());

    return ProjectResource::make($project);
}
```

Controller menjawab pertanyaan:

```text
Endpoint ini menerima request apa, memanggil use case apa, dan mengembalikan response apa?
```

Bukan:

```text
Bagaimana detail project dibuat?
```

### Form Request

Form Request dipakai untuk endpoint yang menerima input dari client. Tempatnya:

```text
app/Http/Requests/Api/V1/<Domain>
```

Tanggung jawabnya:

- authorization awal untuk request tersebut;
- validation rules;
- normalisasi input yang dekat dengan HTTP;
- membuat Data object jika input perlu dibawa ke Action.

Contoh case:

```text
POST /api/v1/app/projects
-> StoreProjectRequest
```

Di sini kita validasi hal seperti `name`, `repository_url`, `branch`, atau field lain yang datang dari console. Validasi tidak ditaruh di controller supaya controller tetap menjadi penghubung, bukan tempat semua aturan hidup.

### Action

Action adalah tempat satu use case dijalankan. Namanya harus eksplisit dan berbentuk pekerjaan nyata:

```text
CreateProjectAction
StartDeploymentAction
ClaimAgentCommandAction
CompleteAgentCommandAction
ResolveDeploymentResourcePolicyAction
```

Action menjawab pertanyaan:

```text
Untuk menyelesaikan use case ini, langkah bisnisnya apa?
```

Contoh `CreateProjectAction` dapat melakukan:

- cek user boleh membuat project;
- normalisasi nama project;
- buat slug;
- simpan project;
- buat command inspection jika flow preview sudah tersedia;
- mengembalikan model atau Data yang dibutuhkan Resource.

Contoh `StartDeploymentAction` dapat melakukan:

- cek project aktif;
- cek user boleh deploy;
- resolve resource policy;
- buat deployment;
- buat agent command `DeployProject`;
- mengembalikan deployment.

Action boleh memakai Service, Model, Policy, atau DB transaction. Action tidak boleh menjadi generic helper bernama samar seperti `ProjectService::process()`. Jika nama class tidak menjelaskan use case, kemungkinan tempatnya salah.

### Service

Service dipakai untuk kemampuan teknis atau integrasi yang dipakai lebih dari satu Action.

Contoh:

```text
GitHubRepositoryService
DeploymentResourcePolicyService
AgentCommandPayloadFactory
ProjectSlugGenerator
SecretRedactionService
```

Bedanya dengan Action:

```text
Action  = use case yang sedang diselesaikan.
Service = kemampuan reusable yang membantu use case.
```

Service bukan tempat membuang logic yang belum jelas pemiliknya. Kalau logic hanya dipakai oleh satu use case dan menjelaskan alur bisnis, letakkan di Action dulu. Pindahkan ke Service ketika benar-benar reusable atau berhubungan dengan integrasi eksternal.

### Model

Model merepresentasikan tabel database dan relasi domain.

Contoh domain Sakala:

```text
User
Project
Deployment
Agent
AgentCommand
DeploymentEvent
DeploymentLog
```

Model boleh punya relationship dan helper kecil:

```php
public function deployments(): HasMany
public function isRunning(): bool
```

Model tidak menjadi tempat workflow panjang. Jika sebuah method perlu membuat deployment, membuat command, menghitung policy, dan mengirim event, itu sudah masuk wilayah Action.

### Enum

Enum dipakai untuk status dan type yang nilainya terbatas dan menjadi bagian dari kontrak domain/API.

Contoh:

```text
ProjectStatus
DeploymentStatus
RuntimeStatus
AgentCommandType
AgentCommandStatus
AgentNodeStatus
```

Gunakan enum agar tidak ada string bebas seperti:

```php
'pending'
'running'
'succeeded'
'failed'
```

yang rawan typo dan susah dilacak. Dengan enum, status menjadi mudah dicari, mudah divalidasi, dan lebih jelas saat code review.

### Data Object

Data object adalah payload typed antar-layer. Ia tidak bergantung pada HTTP dan idealnya immutable.

Contoh:

```text
CreateProjectData
CreateDeploymentData
AgentHeartbeatData
DeployProjectCommandData
ServiceStatusData
```

Data object dipakai supaya Action tidak menerima array mentah seperti:

```php
$data['repository_url']
$data['branch']
$data['name']
```

Lebih jelas jika Action menerima:

```php
CreateProjectData $data
```

Data object cocok untuk input kompleks seperti payload command agent, resource limit deployment, metadata repository, atau hasil service yang perlu diteruskan ke Resource.

### Resource

Resource adalah bentuk JSON keluar. Tempatnya:

```text
app/Http/Resources/Api/V1/<Domain>
```

Resource menjawab:

```text
Field apa yang boleh keluar?
Nama field JSON-nya apa?
Format response-nya seperti apa?
```

Controller tidak sebaiknya mengembalikan model langsung dan tidak menyusun payload domain panjang dengan `response()->json()` manual. Resource menjaga agar field internal tidak bocor dan kontrak API tetap konsisten.

Contoh:

```json
{
  "data": {
    "id": "...",
    "name": "Portfolio",
    "slug": "portfolio",
    "default_domain": "portfolio.run.sakala.dev",
    "status": "active",
    "runtime_status": "running"
  }
}
```

### Policy

Policy adalah tempat authorization berbasis model.

Pertanyaan yang dijawab Policy:

```text
User ini boleh melihat project ini?
User ini boleh deploy project ini?
Admin ini boleh suspend project?
Agent ini boleh claim command ini?
```

Jangan menyalin aturan akses yang sama di banyak controller atau action. Jika aturan berkaitan dengan model dan actor, masukkan ke Policy.

### Middleware

Middleware menjalankan guard sebelum request masuk controller.

Contoh:

- `auth:sanctum` untuk console first-party;
- agent token middleware untuk `Authorization: Bearer <agent-token>` dan `X-Agent-Id`;
- rate limit;
- JSON response guard;
- request context.

Middleware cocok untuk aturan transport yang berlaku ke banyak endpoint. Use case spesifik tetap berada di Action.

### Routes

Route dibagi berdasarkan audience agar tidak semua endpoint menumpuk di satu file.

```text
routes/api/v1/auth.php
routes/api/v1/onboarding.php
routes/api/v1/app.php
routes/api/v1/admin.php
routes/api/v1/system.php
routes/api/v1/webhooks.php
routes/agent.php            # /api/agent/v1/*, di luar prefix /api/v1
routes/web.php              # browser OAuth (/auth/github/*, /auth/google/*)
routes/console.php          # scheduler: agent:assign-commands, agent:expire-commands, agent:mark-offline-nodes, prune, usage signals
```

Pembagian mentalnya:

- `auth.php` untuk login, registrasi, current user, dan JSON auth Console;
- `app.php` untuk endpoint yang dipakai `sakala-console`;
- `agent.php` untuk machine protocol (`AgentController`) dan administrasi node (`AgentNodeControlController`: drain, resume, cleanup);
- `admin.php` untuk project control (stop, suspend, reconcile) dan metrik internal;
- `system.php` untuk status/health API;
- `webhooks.php` untuk event dari provider eksternal seperti GitHub.

Pekerjaan berkala (penugasan command yang menunggu node, lease expiry, derivasi offline) hidup di `app/Console/Commands` dan dijadwalkan di `routes/console.php`; jalankan `php artisan schedule:work` di lokal.

### Contoh Alur: Membuat Project

Saat user membuat project dari console:

```text
sakala-console
-> POST /api/v1/app/projects
-> auth:sanctum
-> ProjectController@store
-> StoreProjectRequest
-> CreateProjectAction
-> Project model disimpan
-> ProjectResource
-> JSON response ke console
```

Setelah project tersimpan, `CreateProjectAction` meminta preview stack di luar transaction pembuatan (GitHub I/O tidak boleh menahan lock user dan tidak boleh menggagalkan pembuatan):

```text
CreateProjectAction
-> create Project (transaction)
-> RequestProjectInspectionAction
   -> resolve branch head di GitHub
   -> pilih node (AgentNodeSchedulerService)
   -> create AgentCommand InspectProject (pinned, idempotent per commit)
   -> project.inspection_status = pending
-> return project dengan preview_status pending
```

Bila branch head tidak dapat di-resolve, project tetap dibuat dengan `preview_status = unavailable` dan `inspection_error_code`. Hasil inspeksi agent disimpan pada project saat command selesai dan diekspos sebagai `inspection` (field stabil saja; output `railpack` mentah tetap server-side).

### Contoh Alur: Deploy Project

Saat user klik deploy:

```text
sakala-console
-> POST /api/v1/app/projects/{project}/deployments
-> DeploymentController@store
-> StoreDeploymentRequest
-> StartDeploymentAction
-> cek policy project
-> resolve resource policy
-> create Deployment
-> create AgentCommand DeployProject
-> DeploymentResource
-> JSON response ke console
```

API tidak menjalankan build. API hanya menyimpan state dan command. Runtime dijalankan oleh agent.

### Contoh Alur: Agent Claim dan Report

Saat bootstrap agent memulihkan intent lifecycle-nya, lalu mengambil command secara outbound:

```text
sakala-agent
-> GET /api/agent/v1/node-state        (desired_state: active|draining|drained|maintenance)
-> GET /api/agent/v1/commands
-> EnsureAgentToken
-> AgentController@pollCommands
-> PollAgentCommandsAction             (AgentCommandEligibilityService: auth, protocol, status, desired state, capability, node scope, project policy)
-> AgentCommandResource collection     (payload dimaterialisasi hanya untuk node target)
```

Setelah mendapat command, agent harus claim:

```text
sakala-agent
-> POST /api/agent/v1/commands/{command}/claim
-> ClaimAgentCommandAction
-> lock node, validasi ulang eligibility
-> UPDATE ... WHERE status = Pending (atomik, satu pemenang)
-> lease_expires_at = command_timeout + grace
-> return command payload
```

Claim harus atomik agar dua agent tidak menjalankan command yang sama. Agent tidak memanggil endpoint untuk `Running`; report pertama dari node pemilik (event `command.claimed`) yang menggeser `Claimed -> Running`.

Agent melaporkan events dan logs:

```text
sakala-agent
-> POST /api/agent/v1/commands/{command}/events | /logs
-> ReportDeploymentEventRequest / ReportDeploymentLogRequest
-> ReportDeploymentEventAction / ReportDeploymentLogAction
-> lock command (+ deployment), dedupe Idempotency-Key, redaction, bounds
-> deployment_events / deployment_logs (atau agent_command_reports bila tanpa deployment)
-> event fase menggerakkan DeploymentStatus maju
-> broadcast, acknowledgement 200
```

Agent menyelesaikan command:

```text
POST /api/agent/v1/commands/{command}/complete
-> CompleteAgentCommandAction
-> command status Succeeded
-> DeployProject: applied_resources, deployment Succeeded, project Running, StopProject bila finalization_deferred
-> InspectProject: hasil inspeksi ke project (hanya command terbaru)
-> Stop/Sleep: runtime_status Stopped bila menargetkan workload aktif
-> audit
```

Jika gagal:

```text
POST /api/agent/v1/commands/{command}/fail
-> FailAgentCommandAction
-> command status Failed
-> DeployProject: deployment Failed + klasifikasi error_code
-> InspectProject: preview failed
-> ResumeNode: desired_state kembali drained
-> audit
```

Command yang tidak pernah selesai ditutup `agent:expire-commands` (`Expired`), dan node yang berhenti heartbeat diturunkan `offline` oleh `agent:mark-offline-nodes`.

### Rule Praktis untuk Contributor

Gunakan aturan ini ketika menambahkan fitur:

```text
Berhubungan dengan HTTP?
-> Controller / Form Request / Resource

Berhubungan dengan satu use case?
-> Action

Reusable atau integrasi eksternal?
-> Service

Status atau type resmi?
-> Enum

Bentuk payload antar-layer?
-> Data object

Relasi database?
-> Model / Migration

Keputusan akses?
-> Policy / Middleware
```

Endpoint baru dianggap belum rapi jika:

- controller berisi business logic panjang;
- validasi ditulis langsung di controller;
- response JSON disusun manual padahal ada Resource;
- status ditulis sebagai string bebas padahal harus enum;
- Action menerima array besar tanpa struktur;
- Service menjadi tempat logic acak tanpa domain yang jelas.

Arsitektur ini boleh berkembang, tetapi boundary dasarnya harus dijaga:

```text
Controller = HTTP boundary
Request    = validasi input
Action     = use case
Service    = kemampuan reusable / integrasi
Model      = persistence
Enum       = status dan type resmi
Data       = payload typed
Resource   = output JSON
Policy     = authorization
Middleware = guard transport
```

## Dokumentasi API

Scramble menghasilkan OpenAPI 3.1 langsung dari route, Form Request, return type controller, dan API Resource. UI berada di `/docs/api`; dokumen JSON berada di `/docs/api.json`. Dokumentasi hanya terbuka otomatis pada environment `local`. Environment lain harus mengaktifkan `SCRAMBLE_ENABLED` secara eksplisit.

Spesifikasi ikut diperiksa oleh `composer check`. Endpoint baru dianggap belum selesai jika OpenAPI tidak dapat digenerasikan atau kontraknya tidak tercermin dengan benar.
