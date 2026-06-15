# matte-client

The **send side** of [Matte](https://github.com/artisan-build/matte) — **self-hosted, unmetered
image background removal on Laravel Cloud.** Install it in any Laravel app to call your
self-hosted Matte server with one line: `Matte::remove($image)`.

> **Read-only mirror.** This repository is a read-only split of the
> [`artisan-build/matte`](https://github.com/artisan-build/matte) monorepo. Issues and pull
> requests are disabled here — please open them on the monorepo.

## What it does

`matte-client` is a thin convenience SDK over the Matte HTTP API. It is a fast-path, **not a
requirement** — the server is a plain HTTP API, so anything can POST to it directly. The SDK
just makes the common Laravel case ergonomic.

- **`Matte::remove($image, $options, $callbackUrl?)`** → a `JobHandle` (async submit). The
  `$image` can be a file path, raw bytes, an `UploadedFile`, or an `SplFileInfo`.
- **`Matte::removeSync($image, $options)`** → the transparent PNG bytes, inline, for small /
  interactive cases.
- **`JobHandle`** → `status()`, `wait($timeout)` (polls to done/failed/timeout), `result()`
  (fetches the PNG).

It speaks the [`matte-contracts`](https://github.com/artisan-build/matte-contracts) wire
protocol and authenticates with a `Bearer` token.

## Async, two ways — simple by default

The default keeps the install **zero-infrastructure**: no public endpoint, no websockets.

- **Poll → event (default).** Submit, then `AwaitRemovalJob::dispatch($handle->id())`. The job
  polls the server on your app's own queue, optionally stores the result to `MATTE_STORE_DISK`,
  and fires a **`MatteRemovalCompleted`** event you listen for. Works everywhere — localhost,
  CI, behind a firewall.
- **Signed webhook (opt-in).** Set `MATTE_WEBHOOK_PATH` and pass a `callback_url`; the package
  registers a receiver that verifies the server's `X-Matte-Signature` (constant-time HMAC) and
  fires the same `MatteRemovalCompleted` event. Lower latency at scale, but needs a public URL.

```php
use ArtisanBuild\MatteClient\Facades\Matte;
use ArtisanBuild\MatteClient\Jobs\AwaitRemovalJob;

$handle = Matte::remove($request->file('photo'), ['mode' => 'grabcut', 'preset' => 'quality']);
AwaitRemovalJob::dispatch($handle->id());        // → MatteRemovalCompleted

// or, inline:
$png = Matte::removeSync($smallImage);
```

## Activation — by presence of config

The client is active when `MATTE_URL` and `MATTE_TOKEN` are set. The webhook receiver is
registered only when `MATTE_WEBHOOK_PATH` is configured.

## Installation

```bash
composer require artisan-build/matte-client
php artisan matte:install
```

`matte:install` prompts for the Matte server URL and the API token (and an optional webhook
secret), writes `MATTE_URL` / `MATTE_TOKEN` / `MATTE_WEBHOOK_SECRET` to your `.env`, publishes
the config, and pins `matte-contracts` to a caret constraint. Generate the token with
`php artisan token:create <client-id>` (from `artisan-build/built-for-cloud`).

## License

MIT. See [LICENSE](LICENSE).
