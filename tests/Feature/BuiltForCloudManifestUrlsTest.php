<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\LandingManifest;
use ArtisanBuild\BuiltForCloud\ManagedTransitionDirection;

/**
 * Scalpels URLs that answer with an IMAGE, confirmed with curl on 2026-09-23.
 *
 * A URL only belongs here if it returned `200` AND an `image/*` content type. That second
 * half is the whole point: Built for Cloud drops the manifest icon straight into an
 * `<img src>`, so a URL that merely returns `200 text/html` — the product page, the site
 * root — is still a broken image on every deployed instance.
 *
 * @var list<string>
 */
const VERIFIED_LIVE_IMAGE_URLS = [
    'https://scalpels.app/img/products/transparent/matte.png', // 200 image/png
    'https://scalpels.app/img/products/transparent/matte.svg', // 200 image/svg+xml
];

/**
 * Scalpels URLs that answer with a PAGE a browser can land on, confirmed with curl on 2026-09-23.
 *
 * @var list<string>
 */
const VERIFIED_LIVE_PAGE_URLS = [
    'https://scalpels.app', // 200 text/html
    'https://scalpels.app/products/matte', // 200 text/html
];

/**
 * Which kind of destination each configured URL has to be, keyed by its config dot path.
 *
 * Anything absolute in `config/built-for-cloud.php` that is missing from this map fails the
 * test below, so a new URL cannot ship until somebody says what it is for and verifies it.
 *
 * @var array<string, 'image'|'page'>
 */
const CONFIGURED_URL_ROLES = [
    'manifest.icon' => 'image',
    'manifest.product_url' => 'page',
];

/**
 * The suite must never make live HTTP requests — that would make CI depend on scalpels.app
 * being up. Reachability is therefore pinned in the lists above, with the verification date,
 * rather than fetched.
 *
 * @param  array<array-key, mixed>  $config
 * @return array<string, string> dot path => url
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

/**
 * @return list<string>
 */
function verifiedLiveUrlsForRole(string $role): array
{
    return $role === 'image' ? VERIFIED_LIVE_IMAGE_URLS : VERIFIED_LIVE_PAGE_URLS;
}

/**
 * Pull one `data-testid`-marked element's URL attribute out of rendered HTML.
 *
 * Returns null when the element is absent, which the callers assert on: both views wrap the
 * manifest block in a conditional, so "no marker" must fail loudly rather than pass silently.
 */
function renderedUrlAttribute(string $html, string $testId, string $attribute): ?string
{
    $pattern = '#<[a-z]+[^>]*\bdata-testid="'.preg_quote($testId, '#').'"[^>]*\b'.$attribute.'="([^"]*)"#i';

    return preg_match($pattern, $html, $matches) === 1 ? $matches[1] : null;
}

/**
 * Render the two Built for Cloud surfaces that display the manifest, with every optional
 * panel switched off so only the manifest header is in play.
 *
 * @return array<string, string> surface label => rendered html
 */
function renderedManifestSurfaces(LandingManifest $manifest): array
{
    return [
        'landing page' => view('bfc::landing', ['manifest' => $manifest])->render(),
        '/bfc/ui' => view('bfc::home', [
            'manifest' => $manifest,
            'memberManagement' => false,
            'memberManagementHref' => '#',
            'managedMembers' => collect(),
            'managedMemberManagement' => false,
            'sessionManagement' => false,
            'managedTransitions' => false,
            'personalCredentials' => false,
            'installationCredentials' => false,
            'transitionDirection' => ManagedTransitionDirection::Adopt,
        ])->render(),
    ];
}

it('pins every configured Built for Cloud URL to a verified destination of the right kind', function (): void {
    $configured = configuredAbsoluteUrls(config('built-for-cloud'));

    // Guards against a vacuous pass if the manifest URLs ever move out of this config.
    expect($configured)->not->toBeEmpty();
    expect(array_keys(CONFIGURED_URL_ROLES))->each->toBeIn(array_keys($configured));

    $violations = [];

    foreach ($configured as $path => $url) {
        $role = CONFIGURED_URL_ROLES[$path] ?? null;

        if ($role === null) {
            $violations[$path] = $url.' — no destination kind is declared for this key';

            continue;
        }

        if (! in_array($url, verifiedLiveUrlsForRole($role), true)) {
            $violations[$path] = $url.' — not a verified live '.$role.' URL';
        }
    }

    expect($violations)->toBe([]);
});

it('renders an image URL as the icon source on every surface that shows the manifest', function (): void {
    $manifest = LandingManifest::fromConfiguration();

    foreach (renderedManifestSurfaces($manifest) as $surface => $html) {
        $testId = $surface === 'landing page' ? 'landing-manifest-icon' : 'ui-manifest-icon';
        $source = renderedUrlAttribute($html, $testId, 'src');

        expect($source)->not->toBeNull("the {$surface} rendered no manifest icon at all");
        expect($source)->toBeIn(VERIFIED_LIVE_IMAGE_URLS);
    }
});

it('renders a page URL as the product link on every surface that shows the manifest', function (): void {
    $manifest = LandingManifest::fromConfiguration();

    foreach (renderedManifestSurfaces($manifest) as $surface => $html) {
        $testId = $surface === 'landing page' ? 'landing-manifest-product-link' : 'ui-manifest-product-link';
        $href = renderedUrlAttribute($html, $testId, 'href');

        expect($href)->not->toBeNull("the {$surface} rendered no manifest product link at all");
        expect($href)->toBeIn(VERIFIED_LIVE_PAGE_URLS);
    }
});
