<?php

declare(strict_types=1);

use App\Enums\ProjectInspectionStatus;

/**
 * A succeeded InspectProject result for a project without a root directory,
 * with a `railpack` block shaped like `railpack info` v0.23.0 output (see
 * tests/Fixtures/railpack/info-node-no-lockfile.json): metadata values are
 * strings, `detectedProviders` lists the provider, and `metadata.providers`
 * repeats it.
 *
 * @param  array<string, string|bool>  $metadata
 * @param  array<string, mixed>  $overrides  top-level inspection keys
 * @return array<string, mixed>
 */
function railpackInspection(?string $provider, array $metadata = [], array $overrides = []): array
{
    return [
        'repository_url' => 'https://github.com/sakala-demo/app',
        'commit_sha' => '3e91b22a2e560a9f42b9d0921ca9b66c94462e5d',
        'root_directory' => null,
        'dockerfile_found' => false,
        'env_example_found' => false,
        'compose_found' => false,
        'manifests' => [],
        'package_manager' => null,
        'railpack' => [
            'railpackVersion' => '0.23.0',
            'metadata' => ['providers' => $provider ?? '', ...$metadata],
            'detectedProviders' => $provider === null ? [] : [$provider],
            'logs' => [],
            'success' => true,
        ],
        ...$overrides,
    ];
}

/**
 * The inspection Railpack v0.23.0 produces for a named stack. Node projects
 * carry `nodeRuntime`, `nodeIsSPA` ("true"/"false"), and, for an SPA,
 * `nodeSPAFramework`, the way its node provider writes them.
 *
 * @return array<string, mixed>
 */
function railpackStackInspection(string $stack): array
{
    $node = static fn (string $runtime, bool $spa = false, array $extra = [], array $overrides = []): array => railpackInspection('node', [
        'nodeRuntime' => $runtime,
        'nodePackageManager' => 'npm',
        'nodeIsSPA' => $spa ? 'true' : 'false',
        ...($spa ? ['nodeSPAFramework' => $runtime] : []),
        ...$extra,
    ], ['manifests' => ['package.json'], 'package_manager' => 'npm', ...$overrides]);

    return match ($stack) {
        'vite-spa' => $node('vite', spa: true),
        'vite-spa-bool' => $node('vite', extra: ['nodeIsSPA' => true, 'nodeSPAFramework' => 'vite']),
        'astro-spa' => $node('astro', spa: true),
        'staticfile' => railpackInspection('staticfile', ['staticfileRoot' => 'public']),
        'node-static-runtime' => $node('static'),
        'workspace-static' => $node('vite', overrides: ['workspace' => [
            'manager' => 'pnpm',
            'root_directory' => '.',
            'package_directory' => 'apps/web',
            'package_name' => 'web',
            'build_command' => 'pnpm --filter web build',
            'start_command' => null,
            'static_output_directory' => 'apps/web/dist',
        ]]),
        'next' => $node('next'),
        'next-bool' => $node('next', extra: ['nodeIsSPA' => false]),
        'nuxt' => $node('nuxt'),
        'remix' => $node('remix'),
        'react-router' => $node('react-router'),
        'tanstack-start' => $node('tanstack-start'),
        'sveltekit' => $node('vite'),
        'express' => $node('node'),
        'bun' => railpackInspection('node', ['nodeRuntime' => 'bun', 'nodePackageManager' => 'bun', 'nodeIsSPA' => 'false'], ['manifests' => ['package.json'], 'package_manager' => 'bun']),
        'node-without-runtime' => railpackInspection('node', ['nodePackageManager' => 'npm']),
        'laravel' => railpackInspection('php', ['phpVersion' => '8.4'], ['manifests' => ['composer.json']]),
        'django' => railpackInspection('python', ['pythonRuntime' => 'django'], ['manifests' => ['requirements.txt']]),
        'go' => railpackInspection('golang', [], ['manifests' => ['go.mod']]),
        'rails' => railpackInspection('ruby', [], ['manifests' => ['Gemfile']]),
        'dockerfile' => $node('next', overrides: ['dockerfile_found' => true]),
        'shell' => railpackInspection('shell', ['shellScript' => 'start.sh']),
        'unknown-provider' => railpackInspection('cobol'),
        'no-provider' => railpackInspection(null),
    };
}

/**
 * Project attributes for a succeeded inspection.
 *
 * @param  array<string, mixed>  $inspection
 * @return array{inspection_status: ProjectInspectionStatus, inspection: array<string, mixed>}
 */
function inspectedProjectAttributes(array $inspection): array
{
    return ['inspection_status' => ProjectInspectionStatus::Succeeded, 'inspection' => $inspection];
}
