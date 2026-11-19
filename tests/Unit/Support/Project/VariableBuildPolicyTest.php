<?php

declare(strict_types=1);

use App\Data\Agent\ProjectInspectionResultData;
use App\Enums\DetectedFramework;
use App\Enums\ProjectInspectionStatus;
use App\Enums\VariableBuildReason;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Support\Project\BuildTimeVariable;
use App\Support\Project\DeploymentConfigFingerprint;
use App\Support\Project\VariableBuildPolicy;

/** A fixed APP_KEY, so the pinned fingerprints below do not depend on the environment. */
const POLICY_APP_KEY = 'base64:2fl+Ktvkfl+Fuz4Qp/A75G2RTiWVA/ZoKZvp6fiiM10=';

/**
 * The v0.9.0 build fingerprint (computed with the released class) for a project without root directory or
 * commands and the variables below: what live deployments of that release
 * stored.
 */
const POLICY_V090_FINGERPRINT = 'b91af1219f80775c4d846636b4ad9bd9f1018c72c2282840dfcd4271435e6e35';

/** @param  array<string, mixed>  $attributes */
function policyProject(array $attributes = []): Project
{
    return (new Project)->forceFill(['root_directory' => null, 'start_command' => null, 'build_command' => null, ...$attributes]);
}

/** @param  array<string, mixed>  $attributes */
function policyProjectFor(string $stack, array $attributes = []): Project
{
    return policyProject([...inspectedProjectAttributes(railpackStackInspection($stack)), ...$attributes]);
}

/** @return list<EnvironmentVariable> */
function policyVariables(): array
{
    return [
        new EnvironmentVariable(['key' => 'VITE_API_URL', 'encrypted_value' => 'https://api.example.test']),
        new EnvironmentVariable(['key' => 'API_URL', 'encrypted_value' => 'https://internal.example.test']),
        new EnvironmentVariable(['key' => 'NODE_ENV', 'encrypted_value' => 'production']),
    ];
}

/**
 * DeploymentConfigFingerprint::forBuild() as released in v0.9.0, copied
 * verbatim apart from its inputs.
 *
 * @param  iterable<EnvironmentVariable>  $variables
 */
function policyV090BuildFingerprint(Project $project, iterable $variables): string
{
    $present = static fn (?string $value): ?string => $value === null || $value === '' ? null : $value;
    $pairs = [];

    foreach ($variables as $variable) {
        if (BuildTimeVariable::matches($variable->key)) {
            $pairs[$variable->key] = [$variable->key, (string) $variable->encrypted_value];
        }
    }

    ksort($pairs, SORT_STRING);

    return hash_hmac('sha256', json_encode([
        'build',
        $present($project->root_directory),
        $present($project->build_command),
        $present($project->start_command),
        array_values($pairs),
    ], JSON_THROW_ON_ERROR), (string) config('app.key'));
}

// ─── Classification ──────────────────────────────────────────────────────────

test('a stack is classified from its Railpack signals', function (string $stack, ?VariableBuildReason $expected): void {
    expect(VariableBuildPolicy::reasonFor(policyProjectFor($stack)))->toBe($expected);
})->with([
    'Vite SPA' => ['vite-spa', VariableBuildReason::StaticSite],
    'Vite SPA, nodeIsSPA as a bool' => ['vite-spa-bool', VariableBuildReason::StaticSite],
    'Astro SPA' => ['astro-spa', VariableBuildReason::StaticSite],
    'staticfile' => ['staticfile', VariableBuildReason::StaticSite],
    'nodeRuntime static' => ['node-static-runtime', VariableBuildReason::StaticSite],
    'workspace static_output_directory' => ['workspace-static', VariableBuildReason::StaticSite],
    'Next.js' => ['next', VariableBuildReason::PrerenderFramework],
    'Next.js, nodeIsSPA as a bool' => ['next-bool', VariableBuildReason::PrerenderFramework],
    'Nuxt' => ['nuxt', VariableBuildReason::PrerenderFramework],
    'Remix' => ['remix', VariableBuildReason::PrerenderFramework],
    'React Router' => ['react-router', VariableBuildReason::PrerenderFramework],
    'TanStack Start' => ['tanstack-start', VariableBuildReason::PrerenderFramework],
    'SvelteKit (reported as vite)' => ['sveltekit', VariableBuildReason::PrerenderFramework],
    'Express' => ['express', null],
    'bun' => ['bun', null],
    'node without nodeRuntime' => ['node-without-runtime', null],
    'Laravel (php)' => ['laravel', null],
    'Django (python)' => ['django', null],
    'Go (golang)' => ['go', null],
    'Rails (ruby)' => ['rails', null],
    'Dockerfile, even over Next.js' => ['dockerfile', null],
    'shell' => ['shell', VariableBuildReason::UnknownStack],
    'unknown provider' => ['unknown-provider', VariableBuildReason::UnknownStack],
    'no provider' => ['no-provider', VariableBuildReason::UnknownStack],
]);

test('a framework the agent found in package.json renders at build, whatever Railpack reports', function (string $stack, ?VariableBuildReason $expected): void {
    expect(VariableBuildPolicy::reasonFor(policyProjectFor($stack)))->toBe($expected);
})->with([
    'Gatsby (Railpack: node)' => ['gatsby', VariableBuildReason::PrerenderFramework],
    'Astro in server mode (Railpack: node)' => ['astro-server', VariableBuildReason::PrerenderFramework],
    'Eleventy (Railpack: node)' => ['eleventy', VariableBuildReason::PrerenderFramework],
    'SvelteKit without a Vite config (Railpack: node)' => ['sveltekit-without-vite-config', VariableBuildReason::PrerenderFramework],
    'Express, none found' => ['express-without-frameworks', null],
    // Order a and b stay first.
    'Dockerfile, even with frameworks' => ['dockerfile-with-frameworks', null],
    'static site, even with frameworks' => ['astro-spa-with-frameworks', VariableBuildReason::StaticSite],
]);

test('every detected framework counts, alone', function (DetectedFramework $framework): void {
    $inspection = railpackStackInspection('express');
    $inspection['frameworks'] = [$framework->value];

    expect(VariableBuildPolicy::reasonFor(policyProject(inspectedProjectAttributes($inspection))))->toBe(VariableBuildReason::PrerenderFramework);
})->with(DetectedFramework::cases());

test('an inspection by an agent before frameworks keeps the Railpack classification', function (string $stack, ?VariableBuildReason $expected): void {
    $inspection = railpackStackInspection($stack);
    unset($inspection['frameworks']);

    expect(VariableBuildPolicy::reasonFor(policyProject(inspectedProjectAttributes($inspection))))->toBe($expected);
})->with([
    // The known gap for those agents: Gatsby looks like Express.
    'Gatsby (Railpack: node)' => ['gatsby', null],
    'Express' => ['express', null],
    'Next.js' => ['next', VariableBuildReason::PrerenderFramework],
    'Vite SPA' => ['vite-spa', VariableBuildReason::StaticSite],
]);

test('unknown or malformed framework values are not frameworks', function (mixed $frameworks, ?VariableBuildReason $expected): void {
    $inspection = railpackStackInspection('express');
    $inspection['frameworks'] = $frameworks;

    expect(VariableBuildPolicy::reasonFor(policyProject(inspectedProjectAttributes($inspection))))->toBe($expected);
})->with([
    'unknown names only' => [['jekyll', 'hugo', 'Gatsby', 'react-router-dom'], null],
    'non-string items' => [[42, null, true, ['gatsby']], null],
    'not a list' => ['gatsby', null],
    'null' => [null, null],
    'one known among unknown' => [['jekyll', 'gatsby'], VariableBuildReason::PrerenderFramework],
]);

test('the inspection result keeps known frameworks, sorted and unique, and null from an older agent', function (array $result, ?array $expected): void {
    expect(ProjectInspectionResultData::fromArray($result)->toArray()['frameworks'])->toBe($expected);
})->with([
    'sorted and unique' => [['frameworks' => ['vitepress', 'next', 'astro', 'next']], ['astro', 'next', 'vitepress']],
    'unknown and non-string dropped' => [['frameworks' => ['hugo', 'gatsby', 7, null, 'NEXT']], ['gatsby']],
    'none found' => [['frameworks' => []], []],
    'nothing known left' => [['frameworks' => ['hugo']], []],
    'older agent' => [[], null],
    'not a list' => [['frameworks' => 'next'], null],
]);

test('the detected framework enum lists exactly the contract values', function (): void {
    expect(array_map(static fn (DetectedFramework $framework): string => $framework->value, DetectedFramework::cases()))
        ->toBe(['next', 'nuxt', 'astro', 'sveltekit', 'gatsby', 'remix', 'react-router', 'tanstack-start', 'eleventy', 'docusaurus', 'vitepress']);
});

test('a project start command stops an SPA from being served as files', function (string $stack, ?VariableBuildReason $expected): void {
    expect(VariableBuildPolicy::reasonFor(policyProjectFor($stack, ['start_command' => 'npm run preview'])))->toBe($expected);
})->with([
    // Still a framework that renders at build.
    'Vite SPA becomes vite preview' => ['vite-spa', VariableBuildReason::PrerenderFramework],
    'Astro SPA' => ['astro-spa', VariableBuildReason::PrerenderFramework],
    'workspace Vite package' => ['workspace-static', VariableBuildReason::PrerenderFramework],
    // Nothing else to go by.
    'staticfile' => ['staticfile', VariableBuildReason::UnknownStack],
    'nodeRuntime static' => ['node-static-runtime', VariableBuildReason::UnknownStack],
    // Unaffected.
    'Express' => ['express', null],
    'Next.js' => ['next', VariableBuildReason::PrerenderFramework],
]);

test('an empty start command counts as none', function (): void {
    expect(VariableBuildPolicy::reasonFor(policyProjectFor('vite-spa', ['start_command' => ''])))->toBe(VariableBuildReason::StaticSite);
});

test('labels are read like the stack summary: lowercased, and untrusted values ignored', function (): void {
    $upper = railpackInspection('Node', ['nodeRuntime' => 'Next', 'nodeIsSPA' => 'FALSE']);
    $injected = railpackInspection('node', ['nodeRuntime' => 'next; rm -rf /', 'nodeIsSPA' => ['true']]);

    expect(VariableBuildPolicy::reasonFor(policyProject(inspectedProjectAttributes($upper))))->toBe(VariableBuildReason::PrerenderFramework)
        // An unreadable runtime is no runtime: a plain node server.
        ->and(VariableBuildPolicy::reasonFor(policyProject(inspectedProjectAttributes($injected))))->toBeNull();
});

test('without a usable inspection the stack is unknown', function (array $attributes): void {
    expect(VariableBuildPolicy::reasonFor(policyProject($attributes)))->toBe(VariableBuildReason::UnknownStack);
})->with([
    'no inspection' => [[]],
    'inspection pending' => [['inspection_status' => ProjectInspectionStatus::Pending, 'inspection' => null]],
    'failed inspection' => [['inspection_status' => ProjectInspectionStatus::Failed, 'inspection' => railpackStackInspection('express')]],
    'inspection of another root directory' => [[...inspectedProjectAttributes(railpackStackInspection('express')), 'root_directory' => 'apps/api']],
]);

test('an inspection of the project\'s root directory is usable', function (): void {
    $inspection = railpackStackInspection('express');
    $inspection['root_directory'] = 'apps/api';

    expect(VariableBuildPolicy::reasonFor(policyProject([...inspectedProjectAttributes($inspection), 'root_directory' => 'apps/api'])))->toBeNull();
});

// ─── variable_changes ────────────────────────────────────────────────────────

test('variable_changes names the reason, or none for a plain server, with the build-time list', function (string $stack, bool $requiresBuild, ?VariableBuildReason $reason): void {
    expect(VariableBuildPolicy::present(policyProjectFor($stack)))->toBe([
        'requires_build' => $requiresBuild,
        'reason' => $reason,
        'build_time_prefixes' => BuildTimeVariable::PREFIXES,
        'build_time_keys' => BuildTimeVariable::KEYS,
    ]);
})->with([
    'static site' => ['vite-spa', true, VariableBuildReason::StaticSite],
    'prerender framework' => ['next', true, VariableBuildReason::PrerenderFramework],
    'unknown stack' => ['shell', true, VariableBuildReason::UnknownStack],
    'plain server' => ['express', false, null],
]);

test('the reason enum lists every reason the Console shows', function (): void {
    expect(array_map(static fn (VariableBuildReason $reason): string => $reason->value, VariableBuildReason::cases()))
        ->toBe(['static_site', 'prerender_framework', 'unknown_stack', 'build_time_prefix']);
});

// ─── Build fingerprint ───────────────────────────────────────────────────────

test('a plain server keeps the v0.9.0 build fingerprint, so its live deployments keep matching', function (string $stack): void {
    config(['app.key' => POLICY_APP_KEY]);
    $project = policyProjectFor($stack);

    expect(DeploymentConfigFingerprint::forBuild($project, policyVariables()))
        ->toBe(policyV090BuildFingerprint($project, policyVariables()))
        ->toBe(POLICY_V090_FINGERPRINT);
})->with(['express', 'laravel', 'dockerfile', 'express-without-frameworks', 'dockerfile-with-frameworks']);

test('the v0.9.0 formula still holds with build settings', function (): void {
    config(['app.key' => POLICY_APP_KEY]);
    $project = policyProjectFor('express', ['build_command' => 'npm run build', 'start_command' => 'node server.js']);

    expect(DeploymentConfigFingerprint::forBuild($project, policyVariables()))->toBe(policyV090BuildFingerprint($project, policyVariables()));
});

test('a static, prerendered, or unknown stack gets a fingerprint of every variable, unlike v0.9.0', function (string $stack): void {
    config(['app.key' => POLICY_APP_KEY]);
    $project = policyProjectFor($stack);
    $fingerprint = DeploymentConfigFingerprint::forBuild($project, policyVariables());

    $changed = policyVariables();
    $changed[1]->encrypted_value = 'https://other.example.test';

    expect($fingerprint)->toHaveLength(64)
        ->not->toBe(POLICY_V090_FINGERPRINT)
        // A non-prefixed variable now counts…
        ->not->toBe(DeploymentConfigFingerprint::forBuild($project, $changed))
        // …while the plain-server fingerprint ignores it.
        ->and(DeploymentConfigFingerprint::forBuild(policyProjectFor('express'), $changed))->toBe(POLICY_V090_FINGERPRINT);
})->with(['vite-spa', 'next', 'gatsby', 'shell']);

test('every-variable fingerprints are sorted by key and ignore ciphertext', function (): void {
    $project = policyProjectFor('vite-spa');

    expect(DeploymentConfigFingerprint::forBuild($project, policyVariables()))
        ->toBe(DeploymentConfigFingerprint::forBuild($project, array_reverse(policyVariables())));
});
