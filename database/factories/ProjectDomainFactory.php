<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CustomDomainKind;
use App\Enums\CustomDomainRoutingMatch;
use App\Enums\CustomDomainStatus;
use App\Models\Project;
use App\Models\ProjectDomain;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A pending claim of a random subdomain; verified() makes it active.
 *
 * @extends Factory<ProjectDomain>
 */
class ProjectDomainFactory extends Factory
{
    protected $model = ProjectDomain::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'hostname' => 'www.'.Str::lower(Str::random(10)).'.com',
            'kind' => CustomDomainKind::Subdomain,
            'status' => CustomDomainStatus::Pending,
            'verification_token' => Str::lower(Str::random(26)),
            'next_check_at' => now(),
        ];
    }

    public function verified(): static
    {
        return $this->state(fn (): array => [
            'status' => CustomDomainStatus::Verified,
            'verified_at' => now(),
            'last_checked_at' => now(),
            'check_reasons' => [],
            'routing_match' => CustomDomainRoutingMatch::Cname,
            'next_check_at' => now()->addHours(6),
        ]);
    }
}
