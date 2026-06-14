---
name: provisioning-matte-on-cloud
description: "Deploy a self-hosted Matte background-removal instance to Laravel Cloud with the `cloud` CLI. Guides the one-time interactive bootstrap (`cloud ship` + `cloud repo:config`), then provisions the rest mostly non-interactively: attaches a Postgres database, sets the build command that bakes the bg-remover binary into the deploy artifact, wires the object-storage bucket, the managed queue, env vars, deploys, migrates, and issues the first API token. Use when the user wants to deploy, set up, provision, or stand up a Matte server / instance / environment on Laravel Cloud, or fork-and-deploy Matte for a client."
---

# Provisioning Matte on Laravel Cloud

Stands up **one self-hosted Matte instance** (single-tenant background removal) on Laravel Cloud. A client
forks `artisan-build/matte`, then runs this once per environment. Validated end-to-end on a real deploy
(arm64 managed runtime — see `reference/cli-reality.md` for the exact CLI truths, which differ from the
generic flow on cloud-cli **v0.5.0**).

This **specializes** the generic `deploying-laravel-cloud` skill shipped by the Cloud CLI
(`~/.composer/vendor/laravel/cloud-cli/skills/`). Its rules still apply: discover options at runtime
(`cloud <cmd> -h`), add `-n` to every command, `--json` on reads/creates, `--force` on updates/variable
sets, and **confirm before any billable `:create`**.

## What gets provisioned (the Matte topology)

| Resource | How | Notes |
| --- | --- | --- |
| App + default env + Postgres + first deploy | **`cloud ship`** (interactive, once) | One command does app+env+DB+instance+attach+deploy and resolves the org. Avoids the broken `instance:create`/`environment:update` attach paths. |
| Repo binding | **`cloud repo:config`** (interactive, once) | Writes `.cloud/config.json` `{organization_id, application_id}` so later commands are non-interactive. |
| Database attach | `environment:update --database-id <schemaId>` + a deploy | Takes effect **on deploy**; `environment:get` under-reports `databaseSchemaId` — verify by exercising, not by readback. Set `DB_CONNECTION=pgsql`. |
| **bg-remover binary** | **build command**: `… && php artisan matte:provision-binary` + `MATTE_RUNTIME_PATH=/var/www/html/runtime` | Build-command fs changes persist into the artifact shipped to **every** instance (web + worker). Build runs arm64, so it fetches the correct `bg-remover-linux-arm64`. Deploy-command fs changes do NOT persist — don't use it for this. |
| Object storage bucket | **dashboard** (env → Storage → attach) | The CLI can `bucket:create` org-level but cannot associate to an env. Once attached, Cloud injects a `private` S3 disk + `FILESYSTEM_DISK=private` as the default — Matte uses it automatically (`MATTE_DISK` defaults to `FILESYSTEM_DISK`). You never handle the R2 secret. |
| Managed queue | **dashboard** on v0.5.0 (`managed-queue:create` is bugged — always sends `min_replicas`) | The worker. Once created, `managed-queue:set-default` and leave `MATTE_QUEUE_CONNECTION` unset so jobs dispatch on the default connection. |
| Scheduler | `instance:update <inst> --uses-scheduler=true` | Only if a prune/maintenance command exists. |

## Step 1 — Bootstrap (user runs these two; interactive, one-time)

Run from the forked repo root, in-session via `!` so you can see the output:
```
! cloud ship --name=matte --database=postgres18
! cloud repo:config
```
`ship`'s only prompt is the **organization** picker (there is no `--organization` flag, and with multiple
API tokens every non-interactive command errors until the org is chosen). After these two, the repo is
bound and the rest is automated. (The Cloud team is aware of these CLI rough edges; re-check on newer
versions — some may become scriptable.)

## Step 2 — Provision (you, the agent; non-interactive; confirm before each billable `:create`)

Capture ids from `cloud application:get <app> --json -n` (→ `defaultEnvironmentId`, env url) and follow
**`reference/resource-plan.md`** exactly — it has the validated command sequence. High level:

1. **Build command** → bake the binary into the artifact:
   `cloud environment:variables <env> --action set --key MATTE_RUNTIME_PATH --value /var/www/html/runtime -n --force`
   then set the build command to append `php artisan matte:provision-binary` (see resource-plan).
2. **Database** — if `ship` didn't fully provision it (check `databaseSchemaId`/exercise): create a Neon
   cluster + schema, `environment:update <env> --database-id <schemaId> -n --force`, set `DB_CONNECTION=pgsql`.
3. **Bucket** — have the user attach a bucket to the env in the **dashboard** (Storage tab). Then it's the
   default disk; `MATTE_DISK` needs no value.
4. **Managed queue** — create in the **dashboard** (v0.5.0 CLI bug), then `managed-queue:set-default`.
5. **Deploy command** → `php artisan migrate --force`. **Token** → `MATTE_TOKENS` (Step 4).
6. **Deploy** and poll (`cloud deploy matte main --no-wait -n` → `deployment:get <id> --json -n`).

## Step 3 — Confirm + gate (REQUIRED before billables)

Present the resolved resource list + a cost note (Cloud's CLI has no per-resource pricing — point at
`cloud usage --json -n`; Matte is light — a managed-queue cluster needs the **Growth tier ~$20/mo** fixed;
per-image compute is a fraction of a cent). **Wait for approval before any `:create`.**

## Step 4 — First token + verify (functional, not by readback)

- Issue a token: `cloud command:run <env> --cmd="php artisan matte:issue-token <id>" -n` (prints the
  plaintext once + the `MATTE_TOKENS=` entry). Set it: `environment:variables <env> --action set --key
  MATTE_TOKENS --value "<id>=<sha256>" -n --force`, then **redeploy** (env vars apply on deploy).
- **Binary on the worker:** `cloud command:run <env> --cmd="php artisan matte:doctor" -n` → must show
  `PASS Real grabcut conversion`.
- **Sync API:** `curl -F image=@sample.jpg "https://<env-url>/v1/remove?sync=1" -H "Authorization: Bearer
  <token>" -o out.png` → `200 image/png`, transparent (PNG color-type 6).
- **Async API:** `POST /v1/remove` (no `sync`) → `202 {job_id}`; poll `GET /v1/jobs/{job_id}` until
  `done`; the `output_ref` object is in the bucket. (No token → `401`.)

## Step 5 — Hand off (the consuming app)

Once the Matte client package ships (Phase 2): in a consuming app, `composer require artisan-build/matte-client`
then `php artisan matte:install --url=https://<env-url> --token=<plaintext>`. Until then, any HTTP client
POSTs to `/v1/remove` directly — `matte-contracts` is the public wire contract.

## Notes

- **`bg-remover` is x86_64 + arm64.** Cloud workers are arm64 (Graviton); the build is arm64, so the
  binary is correct by construction. Both Linux binaries are self-contained (static OpenCV) — only
  `libonnxruntime.so.1` is co-located, fetched by `matte:provision-binary`.
- **Don't** hand-wire the bucket via `AWS_*` env vars — the CLI never returns the R2 secret and doesn't
  need to; the dashboard attach injects a managed `private` disk.
