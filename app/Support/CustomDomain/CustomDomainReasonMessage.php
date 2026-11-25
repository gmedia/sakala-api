<?php

declare(strict_types=1);

namespace App\Support\CustomDomain;

use App\Enums\CustomDomainCheckReason;
use App\Enums\CustomDomainKind;
use App\Models\ProjectDomain;
use App\Services\CustomDomain\CustomDomainPolicy;

/**
 * What a check reason means for the person who manages the domain's DNS, in
 * Indonesian: what Sakala found, and what to change at the DNS provider. The
 * API returns it next to the reason code, and the emails quote it. Plain
 * text: the Console does not render markup.
 */
final class CustomDomainReasonMessage
{
    public static function for(CustomDomainCheckReason $reason, ProjectDomain $domain): string
    {
        $host = $domain->hostname;
        $target = app(CustomDomainPolicy::class)->target();

        return match ($reason) {
            CustomDomainCheckReason::TxtMissing => "Record TXT {$domain->txtName()} belum ditemukan. Tambahkan record TXT dengan nama {$domain->txtName()} dan nilai {$domain->txtValue()}.",
            CustomDomainCheckReason::TxtMismatch => "Record TXT {$domain->txtName()} ada, tetapi nilainya bukan token domain ini. Setiap kali domain ditambahkan, tokennya baru. Ganti nilainya menjadi {$domain->txtValue()}.",
            CustomDomainCheckReason::CnameRequired => "{$host} memakai record A atau AAAA. Untuk subdomain, Sakala hanya menerima CNAME ke {$target}, supaya domainmu tetap jalan saat alamat server Sakala berubah. Hapus record A atau AAAA itu, lalu buat CNAME {$host} ke {$target}.",
            CustomDomainCheckReason::PointsElsewhere => $domain->kind === CustomDomainKind::Apex
                ? "{$host} belum mengarah ke Sakala. Buat CNAME flattening, ALIAS, atau ANAME dari {$host} ke {$target}, kalau DNS provider-mu mendukungnya. Kalau tidak, pakai www.{$host} di Sakala dan arahkan {$host} ke www lewat fitur redirect di provider. Kalau domain ini memakai proxy Cloudflare (awan oranye), ubah menjadi DNS only."
                : "{$host} belum mengarah ke Sakala. Buat record CNAME {$host} ke {$target}. Kalau domain ini memakai proxy Cloudflare (awan oranye), ubah menjadi DNS only.",
            CustomDomainCheckReason::ExtraAddresses => "{$host} sudah mengarah ke Sakala, tetapi masih punya alamat lain, misalnya record AAAA dari hosting lama. Sebagian pengunjung dan penerbit sertifikat akan sampai ke tempat lain. Hapus record A atau AAAA yang bukan dari Sakala.",
            CustomDomainCheckReason::NoAddress => $domain->kind === CustomDomainKind::Apex
                ? "{$host} belum punya record yang mengarah ke mana pun. Buat CNAME flattening, ALIAS, atau ANAME dari {$host} ke {$target}."
                : "{$host} belum punya record yang mengarah ke mana pun. Buat record CNAME {$host} ke {$target}.",
            CustomDomainCheckReason::CaaForbidsIssuer => "Record CAA domain ini tidak mengizinkan Let's Encrypt, penerbit sertifikat HTTPS yang dipakai Sakala, jadi sertifikat untuk {$host} tidak bisa terbit. Tambahkan record CAA dengan nilai 0 issue \"letsencrypt.org\", atau hapus record CAA yang membatasi.",
            CustomDomainCheckReason::DnsError => "Server DNS {$host} tidak menjawab atau menjawab dengan error saat diperiksa. Biasanya ini sementara, dan Sakala memeriksa lagi otomatis. Kalau terus terjadi, cek pengaturan nameserver domain ini di registrar.",
            CustomDomainCheckReason::DomainTaken => "{$host} sudah aktif di proyek lain di Sakala. Satu domain hanya bisa aktif di satu proyek. Hapus dulu domain itu dari proyek lain tersebut.",
        };
    }
}
