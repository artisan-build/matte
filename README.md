# Matte

**Self-hosted, unmetered image background removal on Laravel Cloud.**

Matte is background removal you **fork and deploy to your own Laravel Cloud account**. Drop the
client into any Laravel app, point it at your self-hosted server, and get transparent PNGs
back — asynchronously through a queue (poll or signed webhook) or synchronously for small
images. The server is a plain HTTP API, so anything that speaks HTTP can use it; the Laravel
client is a convenience, not a requirement.

> One image in, one transparent PNG out. No metering, no per-image bill, no third-party vendor
> in your critical path.

---

## Why Matte exists

Background-removal APIs (remove.bg and friends) are **metered** — typically **$0.10+ per
image** — and you don't control them: outages, rate limits, pricing changes, and a vendor
sitting in your critical path. For apps that process real volume, both the cost and the
dependency add up fast.

Matte is the **unmetered, self-hosted floor**: dedicated, single-tenant background removal
running on compute **you** own. Your only cost is the Cloud resources it runs on — marginal
per-image compute is a fraction of a cent, two to three orders of magnitude under a metered
API. Break-even versus $0.10/image is a couple hundred images a month; everything beyond that
is near-pure savings.

**Positioning.** remove.bg is the full-featured, metered incumbent — the polished ML quality
ceiling, the hosted API, the SLA — and it is the **upgrade path**. Matte is the floor for the
apps paying per image to an API they don't own. Outgrow it, or need best-in-class edges on hard
images (hair, fine detail, busy backgrounds) → remove.bg or a hosted ML model is the upgrade.

**Reliability shifts from uncontrollable to controllable.** Failure stops being "an external
vendor rate-limited us / is down / changed pricing" and becomes "our own queue worker retries
the job," backed by Cloud's managed failed-jobs dashboard and one-click retry. Single-tenant
compute has no shared rate limits.

## Support posture

Matte is written for how [Artisan Build](https://artisan.build) uses it. Bugs get fixed.
Feature requests are a fork away. Client-specific features are **not** backfilled into the OSS
release. If you need a polished, hosted, best-in-class product, that's what remove.bg is for.
MIT licensed.

---

## Optimized for Laravel Cloud

This is the part that makes Matte work, and it's why the server is shaped the way it is.

Matte wraps the [`artisan-build/bg-remover`](https://github.com/artisan-build/bg-remover) C++
binary (OpenCV GrabCut by default; optional ONNX / U²-Net ML for remove.bg-class edges). The
non-obvious result, validated empirically on a real deploy: **that binary runs directly on a
Laravel Cloud managed-queue worker — no container, no sidecar, no system packages.**

- Cloud's workers are **arm64 (Graviton)** on Debian 12 / glibc 2.36. Matte ships a
  statically-linked `bg-remover-linux-arm64` (OpenCV + libstdc++ baked in; only
  `libonnxruntime.so.1` co-located and resolved via `$ORIGIN`).
- A **build-command step** (`php artisan matte:provision-binary`) fetches the arch-correct
  binary into the deploy artifact, so **every web and worker instance has it** — build-command
  filesystem changes persist; deploy-command changes don't.
- `php artisan matte:doctor` runs a **real conversion on the worker** to prove the runtime is
  healthy.

So the entire pipeline — receive image → store to object storage → enqueue → worker runs the
binary → transparent PNG back to storage → status/webhook — lives inside **one managed Laravel
Cloud app**. There is no extra infrastructure to operate.

> **Deploy it with a coding agent.** This repo ships a
> [`provisioning-matte-on-cloud`](.claude/skills/provisioning-matte-on-cloud/SKILL.md) skill.
> Open the monorepo with a skill-aware agent (Claude Code) and ask it to *"provision a Matte
> instance on Laravel Cloud."* It bootstraps with `cloud ship` + `cloud repo:config`, then
> provisions the database, the object-storage bucket, the managed queue, and the build-command
> binary provisioning, wires the `MATTE_*` config, deploys, migrates, and issues the first API
> token. It specializes the Cloud CLI's generic `deploying-laravel-cloud` skill; the manual
> equivalent is in the skill's [`reference/`](.claude/skills/provisioning-matte-on-cloud/reference/).

---

## Architecture

```
  Consumer app ──POST /v1/remove (token)──►  Matte app (one isolated Laravel Cloud env)
   (matte-client or any HTTP client)           │  auth, store original → bucket, create Job, dispatch
                                                ▼
                                       Managed queue  (autoscale on depth, scale to zero)
                                                ▼
                                       Worker (arm64) → Process::run bg-remover  (baked into the artifact)
                                                │  transparent PNG → object storage (deterministic key)
                                                ▼
                                 GET /v1/jobs/{id}  (poll)   or   signed webhook   →   GET /v1/jobs/{id}/result
```

- **One isolated environment per client** on Laravel Cloud — its own compute, database, queue,
  and object-storage bucket, sized and billed per client. Matte is single-tenant; isolation is
  environmental.
- **The server is a plain HTTP API.** Any language can call it directly, so
  [`matte-contracts`](packages/matte-contracts) is a *public* wire contract, not just an
  internal envelope. The Laravel client is a fast-path.
- **Async by default, sync when you want it.** Submit returns a job id; poll `GET /v1/jobs/{id}`
  or receive a signed webhook, then fetch the PNG from `/v1/jobs/{id}/result`. `?sync=1` returns
  the bytes inline for small/interactive cases.
- **Idempotent storage.** The output key is a hash of the input bytes + options, so retries
  don't reprocess.

---

## Repository layout

This is a **monorepo**. Three packages are developed here under `packages/`, each split
read-only to its own repository and published to Packagist. The **Matte app** at the root is a
slim Laravel shell that wires `matte-server` and stays thin so there's no Matte-specific
business logic to drift.

| Package | Repo | Installed in | Role |
| --- | --- | --- | --- |
| [`artisan-build/matte-contracts`](https://github.com/artisan-build/matte-contracts) | read-only split | both packages | The versioned HTTP wire protocol. The single place compatibility lives. |
| [`artisan-build/matte-server`](https://github.com/artisan-build/matte-server) | read-only split | the Matte app | The receive side: ingest, token auth, storage, queue, the worker that runs the binary, status/result, signed webhooks. |
| [`artisan-build/matte-client`](https://github.com/artisan-build/matte-client) | read-only split | consuming apps | The send side: `Matte::remove()`, polling/webhooks, `matte:install`. A convenience SDK. |

**Contributing.** Issues and PRs are **disabled** on the three split repos — the same model as
Laravel's own `illuminate/*` read-only splits. All development happens here in the monorepo.

---

## Compatibility & versioning

Across many independently-deployed consumers and one self-hosted server, **version skew is the
normal state, not an error.** The wire protocol tolerates it:

- **Versioned envelope.** Every payload carries `ENVELOPE_VERSION`. The protocol evolves
  **additively within a major** — new optional fields only, never remove or repurpose one.
- **Backward-compatible server.** A newer `matte-server` parses every older envelope major.
- **Loud failure on the dangerous case.** A request whose envelope is *newer* than the server
  understands gets a clear **4xx** ("your client is ahead of this Matte instance — upgrade it").
- **The image bytes are opaque to the envelope.** Only the thin options/status envelope is
  version-sensitive.
- **Canonical upgrade order: update the Matte server first, then bump clients.**

See [`matte-contracts`](packages/matte-contracts) for the rules and the envelope shapes.

---

## Quick start

**Issue an API token** for a consumer. Tokens are managed by
[`artisan-build/built-for-cloud`](https://github.com/artisan-build/built-for-cloud) and stored
(hashed) in your deployed database; the command runs against your deployed environment through the
Laravel Cloud CLI:

```shell
php artisan token:create <client-id>
```

The plaintext token is printed once — store it in the consuming app. For local or bootstrap use you
can instead set a single `FALLBACK_TOKEN` in the environment (delete it and use per-app tokens for
production workloads).

**In a consuming Laravel app:**

```shell
composer require artisan-build/matte-client
php artisan matte:install        # prompts for the server URL + token
```

```php
use ArtisanBuild\MatteClient\Facades\Matte;
use ArtisanBuild\MatteClient\Jobs\AwaitRemovalJob;

// Async (default): submit, then handle the MatteRemovalCompleted event.
$handle = Matte::remove($pathOrBytesOrUploadedFile, ['mode' => 'grabcut']);
AwaitRemovalJob::dispatch($handle->id());

// Or block for small / interactive cases:
$png = Matte::removeSync($image);
```

Anything that isn't Laravel just POSTs to `/v1/remove` directly — see
[`matte-contracts`](packages/matte-contracts) for the wire shapes and
[`matte-server`](packages/matte-server) for the endpoints.

## The bg-remover binary

The actual segmentation is done by [`artisan-build/bg-remover`](https://github.com/artisan-build/bg-remover),
a self-contained C++ CLI (OpenCV GrabCut + optional ONNX/U²-Net). Matte fetches a pinned
release at deploy time; it is not committed here. GrabCut is the no-model default and is good
on single subjects with contrasting backgrounds; ML mode (a U²-Net `.onnx` model) is
remove.bg-competitive on harder images.

## Releasing

This is a monorepo; the three packages publish to Packagist from read-only split repos. A
release is **lockstep** — every package is tagged the same version even if it didn't change,
the way Laravel tags `illuminate/*`.

To cut a release, push a `v*` tag to this repository:

```shell
git tag v1.2.3
git push origin v1.2.3
```

The [`release.yml`](.github/workflows/release.yml) workflow runs `php artisan kibble:split
--tag=v1.2.3`. For each `packages/*` it does a `git subtree split` into the matching split
repo (`artisan-build/matte-contracts`, `matte-server`, `matte-client`), strips the dev-only
`version` field and path `repositories` from the split's `composer.json`, and force-pushes the
content plus the tag. Packagist auto-updates from the new tag.

**Prerequisite:** a `SPLIT_REPO_TOKEN` repository secret — a fine-grained PAT with
**Contents: write** on the three split repos.

Keep the inter-package constraints on the same major (e.g. `matte-server` requires
`matte-contracts: ^1.0`) so a release resolves to itself.

## License

MIT. See [LICENSE](LICENSE).
