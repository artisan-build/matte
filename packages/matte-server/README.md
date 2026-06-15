# matte-server

The **receive side** of [Matte](https://github.com/artisan-build/matte) — **self-hosted,
unmetered image background removal on Laravel Cloud.** This package turns a slim Laravel app
into a single-tenant background-removal API: it authenticates requests, stores the image,
runs the [`bg-remover`](https://github.com/artisan-build/bg-remover) binary on a queue worker,
and returns a transparent PNG.

> **Read-only mirror.** This repository is a read-only split of the
> [`artisan-build/matte`](https://github.com/artisan-build/matte) monorepo. Issues and pull
> requests are disabled here — please open them on the monorepo.

## Why it's built for Laravel Cloud

`matte-server` is deliberately shaped around one validated fact: **the `bg-remover` binary runs
directly on a Laravel Cloud managed-queue worker — no container, no sidecar, no system
packages.** That is the whole reason Matte can be "fork it and deploy it," with nothing to
operate but a normal Laravel Cloud app.

- **arm64, self-contained.** Cloud workers are arm64 (Graviton) on Debian 12. The pinned
  `bg-remover-linux-arm64` release statically links OpenCV; only `libonnxruntime.so.1` is
  co-located and resolved via the binary's `$ORIGIN` RUNPATH.
- **Baked into the artifact.** `matte:provision-binary` runs as a **build command**, so the
  arch-correct binary lands in the deploy artifact and ships to **every** web and worker
  instance (build-command filesystem changes persist; deploy-command changes do not).
- **`BinaryLocator`** resolves the right binary per platform (macOS arm64 for local dev, Linux
  arm64/x86_64 on servers), so the same code runs everywhere.
- **`php artisan matte:doctor`** runs a real conversion on the host and reports binary
  presence, `$ORIGIN` resolution, and a passing GrabCut — run it on a Cloud instance to prove
  the worker is healthy.

The full pipeline lives inside one managed app: **ingest → object storage → managed queue →
worker runs the binary → transparent PNG → status/webhook.** Object storage and the database
are Cloud-managed resources; nothing else is required.

## The HTTP API

All routes are token-authenticated with a `Bearer` token, resolved against the hashed `api_tokens`
table (managed by [`artisan-build/built-for-cloud`](https://github.com/artisan-build/built-for-cloud))
or the `FALLBACK_TOKEN` environment variable.

| Method & path | Purpose |
| --- | --- |
| `POST /v1/remove` | Submit an image (multipart `image` + options). Returns `202 {job_id, status:"queued"}`. With `?sync=1`, runs inline and returns `200 image/png`. |
| `GET /v1/jobs/{id}` | Job status: `queued` / `processing` / `done` / `failed`, plus `output_ref` / `error`. |
| `GET /v1/jobs/{id}/result` | Streams the transparent PNG (`200 image/png`); `409` if not done yet. |

**Options** (form fields on submit): `mode` (`grabcut` \| `ml`), `preset` (`fast` \| `balanced`
\| `quality`), `model`, `edge_mode` (`blur` \| `bilateral` \| `guided`), `iterations`,
`margin`, plus optional `idempotency_key` and `callback_url`.

**Completion webhook.** If a `callback_url` is supplied and `MATTE_WEBHOOK_SECRET` is set, the
worker POSTs `{job_id, status, output_ref, error}` with an `X-Matte-Signature: sha256=<hmac>`
header (HMAC-SHA256 over the exact body) so the receiver can verify authenticity.

## Console commands

| Command | What it does |
| --- | --- |
| `matte:provision-binary` | Fetch the pinned `bg-remover` binary + ONNX runtime (+ optional model) into the runtime layout. Run as a **build command**. |
| `matte:doctor` | Verify the binary runtime and run a real conversion. |
| `matte:remove <path>` | Synchronous CLI conversion (no queue) — the local eyeball loop. |

API tokens are managed by the `token:*` commands from
[`artisan-build/built-for-cloud`](https://github.com/artisan-build/built-for-cloud)
(`token:create`, `token:rotate`, `token:revoke`, `token:list`, `token:usage`).

## Configuration

Env vars (all `MATTE_*` keys live in `config/matte-server.php`):

| Key | Meaning |
| --- | --- |
| `FALLBACK_TOKEN` | Optional single bootstrap/fallback API token (plaintext). Delete it and use per-app `api_tokens` for production workloads. |
| `MATTE_DISK` | Storage disk for originals + outputs. Defaults to `FILESYSTEM_DISK` (the bucket Cloud injects), then `local`. |
| `MATTE_RUNTIME_PATH` | Where the binary is provisioned. On Cloud, a `base_path` location so it ships in the artifact. |
| `MATTE_BG_REMOVER_TAG` | Pinned `bg-remover` release (default `v0.7.1`). |
| `MATTE_QUEUE_CONNECTION` | Queue for the removal job. Leave unset to use the app default (the managed queue). |
| `MATTE_WEBHOOK_SECRET` | HMAC secret for signing completion webhooks. |
| `MATTE_DEFAULT_MODE`, `MATTE_TIMEOUT`, `MATTE_MODEL_NAME`, `MATTE_MODEL_URL`, `MATTE_ROUTE_PREFIX` | Defaults / tuning. |

## Installation

You don't usually install this directly — it's the package the **Matte app** requires. To
stand a server up, fork [`artisan-build/matte`](https://github.com/artisan-build/matte) and
use the bundled `provisioning-matte-on-cloud` skill (or its manual `reference/`).

## License

MIT. See [LICENSE](LICENSE).
