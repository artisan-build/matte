<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\LandingManifest;

/**
 * Every Scalpels URL below was confirmed to answer HTTP 200 with curl on 2026-09-23.
 *
 * The suite must never make live HTTP requests (flaky in CI), so reachability is pinned
 * here instead: every absolute URL configured for Built for Cloud has to be drawn from
 * this list, and adding a new destination means verifying it by hand first. Any URL that
 * reaches a deployed instance — the landing page icon, the `/bfc/ui` icon, the product
 * link — is covered, not just the one the original report named.
 *
 * @var list<string>
 */
const VERIFIED_LIVE_SCALPELS_URLS = [
    'https://scalpels.app',
    'https://scalpels.app/products/matte',
    'https://scalpels.app/img/products/transparent/matte.png',
    'https://scalpels.app/img/products/transparent/matte.svg',
];

/**
 * Flatten a config tree into `dot.path => url` for every absolute http(s) string in it.
 *
 * @param  array<array-key, mixed>  $config
 * @return array<string, string>
 */
function configuredAbsoluteUrls(array $config, string $prefix = ''): array
{
    $urls = [];

    foreach ($config as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

        if (is_array($value)) {
            $urls = [...$urls, ...configuredAbsoluteUrls($value, $path)];

            continue;
        }

        if (is_string($value) && preg_match('#^https?://#i', $value) === 1) {
            $urls[$path] = $value;
        }
    }

    return $urls;
}

it('configures only Built for Cloud URLs that are known to resolve', function (): void {
    $configured = configuredAbsoluteUrls(config('built-for-cloud'));

    // Guards against a vacuous pass if the manifest URLs ever stop being configured here.
    expect($configured)->not->toBeEmpty();

    $unverified = array_filter(
        $configured,
        fn (string $url): bool => ! in_array($url, VERIFIED_LIVE_SCALPELS_URLS, true),
    );

    expect($unverified)->toBe([]);
});

it('renders a resolvable icon everywhere Built for Cloud shows one', function (): void {
    $icon = LandingManifest::fromConfiguration()->icon;

    expect($icon)->toBeIn(VERIFIED_LIVE_SCALPELS_URLS);
});
