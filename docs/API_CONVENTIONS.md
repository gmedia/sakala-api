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

Gunakan ISO 8601 untuk waktu, UUID/ULID bila identifier publik membutuhkan non-sequential ID, dan idempotency key pada operasi deployment yang berisiko diduplikasi.

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
