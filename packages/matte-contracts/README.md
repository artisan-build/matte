# matte-contracts

The versioned **HTTP wire protocol** shared between
[`matte-client`](https://github.com/artisan-build/matte-client) (the send side) and
[`matte-server`](https://github.com/artisan-build/matte-server) (the receive side) of
[Matte](https://github.com/artisan-build/matte) — **self-hosted, unmetered image background
removal on Laravel Cloud.**

This package is tiny and deliberately so. It has **zero Laravel dependencies** — just typed
PHP 8 DTOs, the enums, and the `ENVELOPE_VERSION` constant. It is the **single place Matte's
wire compatibility lives**, and because Matte's server is a plain HTTP API, it doubles as a
**public contract**: any consumer in any language can implement against it.

> **Read-only mirror.** This repository is a read-only split of the
> [`artisan-build/matte`](https://github.com/artisan-build/matte) monorepo. Issues and pull
> requests are disabled here — please open them on the monorepo.

## Why a separate package

Across many independently-deployed consumers and one self-hosted server, **version skew is the
normal state, not an error.** You cannot keep them in lockstep, so the protocol is built to
tolerate skew, and the contract that defines it lives in exactly one place that both sides pin.
Keeping it framework-free means the wire shapes don't drag the Laravel runtime into a
non-Laravel consumer.

## The compatibility rules

1. **The envelope carries its own version.** Every payload includes `ENVELOPE_VERSION`.
2. **Additive within a major.** New fields are optional and added; existing fields are never
   removed or repurposed. That one rule is what lets a newer server parse every older envelope.
3. **A version newer than the server fails loud.** The server returns a clear **4xx** for an
   envelope it doesn't understand yet — "your client is ahead of this Matte instance."
4. **The image bytes are opaque.** They cross the wire as multipart, never inside the envelope;
   only the thin options/status metadata is version-sensitive.
5. **A major bump is a deliberate act** — an explicit `composer require`, never a routine
   `composer update`. `matte-client` pins a caret constraint (`^X`) on this package.

## The envelopes (shape)

**Submit** (`POST /v1/remove`): the image is sent as multipart bytes; this envelope is the
options/metadata beside it.

| Field | Meaning |
| --- | --- |
| `envelope_version` | The `ENVELOPE_VERSION` the client was built against. |
| `options.mode` | `ml` or `grabcut`. |
| `options.preset` | `fast` \| `balanced` \| `quality`. |
| `options.model` / `edge_mode` / `iterations` / `margin` | Optional tuning. |
| `idempotency_key` / `callback_url` | Optional. |

**Status** (`GET /v1/jobs/{id}`):

| Field | Meaning |
| --- | --- |
| `envelope_version` | The server's envelope major. |
| `job_id` | The job identifier. |
| `status` | `queued` \| `processing` \| `done` \| `failed`. |
| `output_ref` | Storage key of the transparent PNG (when done). |
| `error` | Failure reason (when failed). |

The package ships these as readonly DTOs (`RemovalRequest`, `RemovalOptions`,
`JobStatusEnvelope`), the backing enums (`Mode`, `Preset`, `EdgeMode`, `JobStatus`), a
`Protocol` version helper, and an `InvalidEnvelope` exception — with tolerant `fromArray`
(ignores unknown keys, defaults absent ones).

## Installation

```bash
composer require artisan-build/matte-contracts
```

You usually don't install this directly — it arrives as a dependency of `matte-client` or
`matte-server`.

## License

MIT. See [LICENSE](LICENSE).
