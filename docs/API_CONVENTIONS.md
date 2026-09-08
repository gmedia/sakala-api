# API Conventions

## Versioning

Endpoint publik aplikasi dimulai dari `/api/v1`. Breaking change memakai versi mayor baru; penambahan field kompatibel tetap berada pada versi aktif.

## Representation

Response sukses wajib dibentuk melalui Laravel API Resource dan memakai object `data`. Collection memakai `Resource::collection()` sehingga dapat menambahkan `links` dan `meta`. Controller tidak boleh membangun payload domain dengan `response()->json()`.

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

## HTTP

Gunakan method dan status code sesuai semantik HTTP. Validation error memakai `422`, unauthenticated `401`, forbidden `403`, missing resource `404`, dan conflict `409` bila state tidak memungkinkan operasi.

## Endpoint Implementation

Urutan dependency yang disarankan:

```text
Route -> Controller -> Form Request -> Action -> Model/Service
                    \-> API Resource -> JSON
```

Gunakan DTO di `app/Data/<Domain>` ketika payload melintasi lebih dari satu layer atau memerlukan type yang stabil. Jangan memakai array bebas sebagai kontrak internal untuk workflow domain.

## OpenAPI

Scramble menginfer dokumentasi dari route, validation rules pada Form Request, return type controller, dan API Resource. Jalankan `composer docs:analyse` setiap menambah atau mengubah endpoint. Lihat [panduan OpenAPI](OPENAPI.md).

## Project Control

Endpoint project control menyediakan kontrol runtime yang hanya dapat digunakan oleh admin tanpa melakukan operasi Docker atau Caddy secara langsung dari API. Operasi runtime dijalankan secara asynchronous oleh Agent melalui agent command yang bersifat durable.

### Stop vs Suspend

`Stop` menghentikan workload runtime yang sedang menjadi target pada sebuah project. Operasi ini tidak mengubah status control-plane project menjadi `Suspended`.

`Suspend` mengubah status control-plane project menjadi `Suspended` dan mencegah command yang dapat menjalankan atau mengekspos workload diproses untuk project tersebut. Jika project memiliki workload yang sedang berjalan, API membuat command `SleepProject` untuk Agent.

`Stop` dan `Suspend` merupakan operasi yang berbeda dan tidak boleh diperlakukan sebagai alias satu sama lain.

### Response Asynchronous

Operasi stop dan suspend mengembalikan `202 Accepted` ketika request control-plane telah diterima.

`202 Accepted` tidak berarti operasi runtime telah selesai. Runtime state diperbarui setelah Agent melaporkan hasil eksekusi command.

Jika command stop atau sleep berhasil, runtime state dari workload yang menjadi target berubah menjadi `Stopped`.

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

Jika command `StopProject` atau `SleepProject` untuk workload yang masih menjadi target berhasil diselesaikan, runtime state project berubah menjadi `Stopped`.

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
