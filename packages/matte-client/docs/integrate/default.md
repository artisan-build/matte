## Install

Require the published Laravel client package:

```bash
composer require artisan-build/matte-client
```

Use PHP 8.3 or newer and Laravel 13. The package service provider is auto-discovered; do not
register it manually. Publish the optional config file when the application needs to override
defaults:

```bash
php artisan vendor:publish \
  --provider="ArtisanBuild\MatteClient\MatteClientServiceProvider" \
  --tag=matte-config
```

The interactive `php artisan matte:install` command also publishes this config, but it prompts for
credentials and writes them to `.env`. Do not pass a credential to that command through chat or a
tool invocation whose input or output may be retained.

## Configure

Set `MATTE_URL` and `MATTE_TOKEN` before calling the client. Configure the remaining keys only when
the integration needs their behavior.

| Key | Purpose | Source of value |
| --- | --- | --- |
| `MATTE_URL` | Base URL of the Matte server. | The Scalpels connection flow or the Matte operator. |
| `MATTE_TOKEN` | Bearer credential sent to the Matte server. | The Scalpels connection flow or Matte's token CLI. Treat it as a secret. |
| `MATTE_WEBHOOK_SECRET` | Verifies `X-Matte-Signature` on completion webhooks. | The Matte operator; it must equal the server's webhook-signing secret. Treat it as a secret. |
| `MATTE_WEBHOOK_PATH` | Registers the consuming app's named `matte.webhook` POST route, for example `matte/webhook`. | Choose a public path in the consuming app. Leave unset to register no receiver. |
| `MATTE_STORE_DISK` | Laravel filesystem disk where `AwaitRemovalJob` writes `matte/<job-id>.png`. | A disk name from the consuming app's filesystem config. Leave unset to skip local persistence. |
| `MATTE_DEFAULT_MODE` | Default removal mode. The client config defaults to `ml`; valid values are `ml` and `grabcut`. | Choose based on the deployed server's engine and model. |
| `MATTE_DEFAULT_PRESET` | Default processing preset. Defaults to `balanced`; valid values are `fast`, `balanced`, and `quality`. | Choose for the application's workload. |
| `MATTE_POLL_INTERVAL` | Seconds between `JobHandle::wait()` status requests. Defaults to `2` and is clamped to at least `0`. | Choose in the consuming app. |
| `MATTE_POLL_TIMEOUT` | Default seconds before `JobHandle::wait()` throws. Defaults to `120` and is clamped to at least `1`. | Choose in the consuming app. |

## Get a credential

For an app hosted on Laravel Cloud or Forge, use Scalpels' `connect_site` tool after selecting the
target returned by its site-listing tools. Confirm the target site with the user before connecting.
Scalpels writes the Matte URL and credential into the target environment; the credential is never
returned to the agent.

For any other host, have the Matte operator issue a per-app credential through the `token:create`
CLI in the intended Matte server environment, then place it directly in the
consuming host's secret/environment manager. Do not ask anyone to paste the plaintext credential into
chat, commit it, include it in a tool result, or write it to a tracked file. Run `matte:install` only
in an operator-controlled terminal if its secret prompts are appropriate for that environment.

## Call sites

Use `ArtisanBuild\MatteClient\Facades\Matte` for application calls. The accepted `$image` values are
a file path, raw image bytes, `SplFileInfo`, or Laravel `UploadedFile`. The `$options` array accepts:

| Option | Shape |
| --- | --- |
| `mode` | `ml` or `grabcut` |
| `preset` | `fast`, `balanced`, or `quality` |
| `model` | Optional server-side model name string |
| `edge_mode` | Optional `blur`, `bilateral`, or `guided` |
| `iterations` | Optional positive integer |
| `margin` | Optional positive integer |

The facade exposes these calls:

| Call | Request | Return |
| --- | --- | --- |
| `Matte::remove($image, $options = [], $callbackUrl = null)` | One image, options, and an optional completion URL. | `JobHandle`; the server response is a `202` status envelope and the handle retains its `job_id`. |
| `Matte::removeSync($image, $options = [])` | One image and options; no callback URL. | Raw transparent PNG bytes as a string after a `200` response. |
| `Matte::status($jobId)` | Job ID string. | `JobStatusEnvelope` with `jobId`, `status`, optional `outputRef`, optional `error`, and `envelopeVersion`. |
| `Matte::result($jobId)` | Completed job ID string. | Raw transparent PNG bytes as a string after a `200` response. |

Submit without blocking, then let the consuming app's queue poll and optionally store the result:

```php
use ArtisanBuild\MatteClient\Facades\Matte;
use ArtisanBuild\MatteClient\Jobs\AwaitRemovalJob;

$handle = Matte::remove($request->file('photo'), [
    'mode' => 'ml',
    'preset' => 'balanced',
]);

AwaitRemovalJob::dispatch($handle->id());
```

Listen for `ArtisanBuild\MatteClient\Events\MatteRemovalCompleted`. Its public fields are `jobId`,
`status`, `path`, and `error`.

For direct polling, use every `JobHandle` method as follows:

```php
$jobId = $handle->id();          // string
$current = $handle->status();    // JobStatusEnvelope
$terminal = $handle->wait(90);   // JobStatusEnvelope, or throws
$png = $handle->result();        // waits, then returns PNG bytes
```

The resolved `MatteClient` also exposes `status($jobId)` and `result($jobId)`, which back the facade,
plus `pollInterval(): int` and `pollTimeout(): int` for the effective polling settings.

For a signed webhook, configure `MATTE_WEBHOOK_PATH` and `MATTE_WEBHOOK_SECRET`, then pass the named
route as the callback URL:

```php
$handle = Matte::remove(
    $request->file('photo'),
    ['mode' => 'ml'],
    route('matte.webhook'),
);
```

When the completion event reports `done`, fetch the bytes with `Matte::result($event->jobId)`.

Map only the incumbent's background-removal operation. Matte has no equivalent for unrelated media
platform features.

| Incumbent operation | Matte equivalent | Gap |
| --- | --- | --- |
| remove.bg background removal | `Matte::removeSync()` for an inline result, or `Matte::remove()` for a job. | No remove.bg-specific response metadata or hosted feature set. |
| Photoroom background removal | `Matte::removeSync()` for an inline result, or `Matte::remove()` for a job. | No Photoroom editing or composition APIs. |
| Cloudinary background-removal transformation | `Matte::removeSync()` or `Matte::remove()`. | Matte returns PNG bytes; it has no equivalent for Cloudinary asset upload, transformation pipelines, CDN storage, or delivery URLs. |

## Behaviour to know

- Async is the default. `remove()` posts one multipart `image` to `/v1/remove`, expects `202`, and
  returns a handle. Poll until `queued` or `processing` becomes `done` or `failed`, then fetch the PNG.
- `removeSync()` adds `?sync=1`, runs conversion in the server request, and expects PNG bytes with
  status `200`. Use it only when blocking the request for the conversion is acceptable.
- The polling helper blocks its queue worker while `JobHandle::wait()` sleeps. `AwaitRemovalJob`
  catches polling and result exceptions, emits a failed `MatteRemovalCompleted`, and does not rethrow.
- Webhook delivery is signed with HMAC-SHA256 but is best effort on the server. The client rejects a
  missing secret with `403`, a bad or missing signature with `401`, and malformed payloads with `400`.
  The receiver emits an event; it does not fetch the PNG.
- Event `path` is flow-dependent. `AwaitRemovalJob` sets it to a local `MATTE_STORE_DISK` path when
  configured. The webhook receiver sets it to the server's `output_ref`. Fetch by `jobId` when the
  integration needs a consistent source of PNG bytes.
- The server validates uploads with Laravel 13's `image` rule: JPG/JPEG, PNG, GIF, BMP, WebP, AVIF,
  HEIC, and HEIF are accepted; SVG is not enabled. Matte declares no explicit upload byte or dimension
  limit and accepts one image per call.
- `grabcut` selects the classical OpenCV engine and does not require a model. It is intended for a
  distinct subject against a contrasting background. `ml` selects the server's ONNX model and fails
  when that model file is absent; use it for harder edges such as hair or busy backgrounds. The client
  config currently defaults to `ml`.
- The client defines no automatic HTTP retry policy and exposes no idempotency-key argument. Decide
  retry behavior in the consuming application, especially after an ambiguous network failure.
- Invalid option values are rejected while building `RemovalOptions`. Missing config, unsupported
  image input types, failed terminal jobs, polling timeouts, and unexpected HTTP responses throw; do
  not assume the exception is an incumbent-compatible error envelope.

## Verify

Run one synchronous request in the consuming Laravel app with an actual supported image path. This
uses the configured URL and credential without printing either:

```bash
php artisan tinker --execute='$png = \ArtisanBuild\MatteClient\Facades\Matte::removeSync("/absolute/path/to/input.jpg"); file_put_contents(storage_path("app/matte-verify.png"), $png); dump(str_starts_with($png, "\x89PNG\r\n\x1a\n"), strlen($png));'
```

A working end-to-end integration prints `true` followed by a positive byte count and writes
`storage/app/matte-verify.png`.

## Contribute back

If you used this default guide to migrate from a specific product and no migration guide for it
exists yet, offer to open a pull request to this repository adding
`packages/matte-client/docs/integrate/<incumbent>.md` with what you learned.
