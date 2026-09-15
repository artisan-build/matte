# matte-client

The **send side** of [Matte](https://github.com/artisan-build/matte) — **self-hosted, unmetered
image background removal on Laravel Cloud.** Install it in any Laravel app to call your
self-hosted Matte server with one line: `Matte::remove($image)`.

Use the [default integration guide](docs/integrate/default.md) when an agent is installing or migrating to this client.

> **Read-only mirror.** This repository is a read-only split of the
> [`artisan-build/matte`](https://github.com/artisan-build/matte) monorepo. Issues and pull
> requests are disabled here — please open them on the monorepo.

## What it does

`matte-client` is a thin convenience SDK over the Matte HTTP API. It is a fast-path, **not a
requirement** — the server is a plain HTTP API, so anything can POST to it directly. The SDK
just makes the common Laravel case ergonomic.

- **`Matte::remove($image, $options)`** → a `JobHandle` (async submit). The
  `$image` can be a file path, raw bytes, an `UploadedFile`, or an `SplFileInfo`.
- **`Matte::removeSync($image, $options)`** → the transparent PNG bytes, inline, for small /
  interactive cases.
- **`JobHandle`** → `status()`, `wait($timeout)` (polls to done/failed/timeout), `result()`
  (fetches the PNG).

It speaks the [`matte-contracts`](https://github.com/artisan-build/matte-contracts) wire
protocol and authenticates with a `Bearer` token.

## Async polling

The default keeps the install **zero-infrastructure**: no public endpoint, no websockets.

- **Poll → event (default).** Submit, then `AwaitRemovalJob::dispatch($handle->id())`. The job
  polls the server on your app's own queue, optionally stores the result to `MATTE_STORE_DISK`,
  and fires a **`MatteRemovalCompleted`** event you listen for. Works everywhere — localhost,
  CI, behind a firewall.

```php
use ArtisanBuild\MatteClient\Facades\Matte;
use ArtisanBuild\MatteClient\Jobs\AwaitRemovalJob;

$handle = Matte::remove($request->file('photo'), ['mode' => 'grabcut', 'preset' => 'quality']);
AwaitRemovalJob::dispatch($handle->id());        // → MatteRemovalCompleted

// or, inline:
$png = Matte::removeSync($smallImage);
```

## Activation — by presence of config

The client is active when `MATTE_URL` and `MATTE_TOKEN` are set. Async completion uses polling.

## Installation

```bash
composer require artisan-build/matte-client
php artisan matte:install
```

`matte:install` prompts for the Matte server URL and bearer credential, writes `MATTE_URL` and
`MATTE_TOKEN` to your `.env`, publishes the config, and pins `matte-contracts` to a caret constraint.
On the Matte server, mint the credential with:

```shell
php artisan bfc:credential:mint installation '<consumer-installation-ref>' --kind=bearer --purpose=consumption --name='matte-<app-id>' --local
```

The existing webhook receiver/verifier classes are explicitly retained as dormant v0.13.0 residue.
Callback submission is unsupported in this release, and the server rejects non-empty callback URLs.

## License

MIT. See [LICENSE](LICENSE).
