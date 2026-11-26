<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\CustomDomain;

use App\Enums\CustomDomainCheckReason;
use App\Enums\CustomDomainKind;
use App\Enums\CustomDomainStatus;
use App\Models\ProjectDomain;
use App\Services\CustomDomain\CustomDomainPolicy;
use App\Support\CustomDomain\CustomDomainReasonMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A custom domain claim of a project (ADR-024), with the exact DNS records
 * to publish and what the last check found.
 *
 * @mixin ProjectDomain
 *
 * @property ProjectDomain $resource
 */
final class ProjectDomainResource extends JsonResource
{
    /** `dns_records[].type` of the ownership record. */
    public const TXT_RECORD_TYPE = 'TXT';

    /** `dns_records[].type` of a subdomain's pointing record. */
    public const CNAME_RECORD_TYPE = 'CNAME';

    /** `dns_records[].type` of an apex's pointing record: CNAME flattening, ALIAS, or ANAME, as the DNS provider calls it. */
    public const APEX_RECORD_TYPE = 'ALIAS';

    /** Whether the node that keeps the project's route cannot serve custom domains yet; set by the controller. */
    public bool $waitingForNode = false;

    public function withWaitingForNode(bool $waiting): static
    {
        $this->waitingForNode = $waiting;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $domain = $this->resource;
        $policy = app(CustomDomainPolicy::class);
        $target = $policy->target();

        return [
            'id' => $domain->id,
            'project_id' => $domain->project_id,
            /** Final lowercase ASCII form; an international name is punycode (`xn--`). */
            'hostname' => $domain->hostname,
            /** `apex` for a registrable domain (`contoh.com`), `subdomain` below one (`www.contoh.com`). */
            'kind' => $domain->kind->value,
            /**
             * `pending` until TXT, pointing, and CAA pass together; `verified` routes; `failed` when a pending claim
             * did not pass in 72 hours; `disabled` after 72 hours of failing checks or by an admin; `released` when
             * another project verified the name of this disabled claim.
             */
            'status' => $domain->status->value,
            /** The stable name the domain points at (`SAKALA_CUSTOM_DOMAIN_TARGET`). Never an IP address. */
            'target' => $target,
            /**
             * The records to publish, exactly. `TXT` proves ownership and stays for as long as the domain is used.
             * `CNAME` points a subdomain. `ALIAS` points an apex: CNAME flattening, ALIAS, or ANAME, whichever the
             * DNS provider offers; without one, use `www` and redirect the apex at the provider.
             *
             * @var list<array{type: 'TXT'|'CNAME'|'ALIAS', name: string, value: string}>
             */
            'dns_records' => [
                ['type' => self::TXT_RECORD_TYPE, 'name' => $domain->txtName(), 'value' => $domain->txtValue()],
                [
                    'type' => $domain->kind === CustomDomainKind::Apex ? self::APEX_RECORD_TYPE : self::CNAME_RECORD_TYPE,
                    'name' => $domain->hostname,
                    'value' => $target,
                ],
            ],
            /**
             * What the last check found; `checked_at` null before the first. `reasons` is empty when it passed;
             * each item has a stable `code` and a `message` in Indonesian with what to change. `routing_match` is how
             * the last passing check found the domain pointed: `cname`, or `addresses` for an apex whose addresses
             * are Sakala's (flattening, or a literal A record that breaks when Sakala's addresses change).
             *
             * @var array{checked_at: string|null, reasons: list<array{code: string, message: string}>, routing_match: 'cname'|'addresses'|null}
             */
            'last_check' => [
                'checked_at' => $domain->last_checked_at?->toAtomString(),
                'reasons' => array_map(
                    static fn (CustomDomainCheckReason $reason): array => ['code' => $reason->value, 'message' => CustomDomainReasonMessage::for($reason, $domain)],
                    $domain->checkReasons(),
                ),
                'routing_match' => $domain->routing_match?->value,
            ],
            /** When the next automatic check runs; null when none is planned (failed, released, admin-disabled, or past 30 days disabled). */
            'next_check_at' => $domain->next_check_at?->toAtomString(),
            'verified_at' => $domain->verified_at?->toAtomString(),
            /** Set while a verified domain fails its checks: the first failing check since the last pass. */
            'failing_since' => $domain->failing_since?->toAtomString(),
            /**
             * When a failing verified domain is disabled if its DNS is not fixed; null otherwise.
             *
             * @var string|null
             */
            'disable_at' => $domain->status === CustomDomainStatus::Verified && $domain->failing_since !== null
                ? $policy->disableAt($domain->failing_since)->toAtomString()
                : null,
            'failed_at' => $domain->failed_at?->toAtomString(),
            'disabled_at' => $domain->disabled_at?->toAtomString(),
            /**
             * Until when a disabled domain comes back on its own once its DNS passes; null when it does not (admin) or
             * is not disabled.
             *
             * @var string|null
             */
            'reactivation_ends_at' => $domain->status === CustomDomainStatus::Disabled && $domain->admin_disabled_at === null && $domain->disabled_at !== null
                ? $policy->reactivationEndsAt($domain->disabled_at)->toAtomString()
                : null,
            /** True while an admin keeps the domain disabled; it then never comes back on its own. */
            'admin_disabled' => $domain->admin_disabled_at !== null,
            'admin_disabled_reason' => $domain->admin_disabled_reason,
            'released_at' => $domain->released_at?->toAtomString(),
            /**
             * True when the domain is verified but the node that runs the project cannot serve custom domains yet
             * (an older agent): visitors get a 404 until the project runs on a node that can.
             *
             * @var bool
             */
            'waiting_for_node' => $this->waitingForNode,
            'created_at' => $domain->created_at?->toAtomString(),
        ];
    }
}
