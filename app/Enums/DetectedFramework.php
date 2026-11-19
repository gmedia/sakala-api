<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A framework that can render pages at build, found by name among the
 * `dependencies` and `devDependencies` of the inspected `package.json`
 * (InspectProject result `frameworks`, protocol revision 4). Every value counts
 * for VariableBuildPolicy, including those Railpack reports as plain `node`
 * (Gatsby, Astro in server mode, Eleventy, Docusaurus, VitePress, SvelteKit
 * without a Vite config). The agent may add values later; the API keeps only
 * the ones it knows.
 */
enum DetectedFramework: string
{
    /** `next`. */
    case Next = 'next';

    /** `nuxt` or `nuxt3`. */
    case Nuxt = 'nuxt';

    /** `astro`. */
    case Astro = 'astro';

    /** `@sveltejs/kit`. */
    case SvelteKit = 'sveltekit';

    /** `gatsby`. */
    case Gatsby = 'gatsby';

    /** `@remix-run/dev`. */
    case Remix = 'remix';

    /** `@react-router/dev`, framework mode; the `react-router` library alone is not. */
    case ReactRouter = 'react-router';

    /** `@tanstack/react-start`, `@tanstack/solid-start`, or `@tanstack/start`. */
    case TanStackStart = 'tanstack-start';

    /** `@11ty/eleventy`. */
    case Eleventy = 'eleventy';

    /** `@docusaurus/core`. */
    case Docusaurus = 'docusaurus';

    /** `vitepress`. */
    case VitePress = 'vitepress';

    /**
     * The known frameworks in an agent's `frameworks` value, sorted by value
     * and unique; unknown and non-string items are dropped. Null when the value
     * is not an array: an agent before the field, so nothing is known.
     *
     * @return list<self>|null
     */
    public static function listFrom(mixed $values): ?array
    {
        if (! is_array($values)) {
            return null;
        }

        $known = [];

        foreach ($values as $value) {
            $framework = is_string($value) ? self::tryFrom($value) : null;

            if ($framework !== null) {
                $known[$framework->value] = $framework;
            }
        }

        ksort($known, SORT_STRING);

        return array_values($known);
    }
}
