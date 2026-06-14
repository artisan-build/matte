# Laravel Cloud CLI reality for Matte (validated on cloud-cli v0.5.0, real deploy June 2026)

What actually works, what's bugged, and the workaround — learned standing up the first real Matte instance
end-to-end (arm64 managed runtime, Debian 12 / glibc 2.36 / PHP 8.5). Re-check on newer CLI versions; the
Cloud team is aware of several of these.

## Auth / org selection (the multi-org gate)

- Global `~/.config/cloud/config.json` holds `api_tokens` (a list of `id|secret` strings, one per org) with
  **no org names locally**. With >1 token, **every non-interactive command errors**: *"Multiple API tokens
  found. Set organization_id in .cloud/config.json…"*.
- A repo binds via repo-local `.cloud/config.json` = `{"organization_id":"org-…","application_id":"app-…"}`.
- Resolving which token → which org requires the API, which is what `cloud ship`'s interactive picker does.
  **So the first org choice is unavoidably interactive.** After it (ship writes the binding, or `repo:config`
  does), all later commands are non-interactive.
- There is **no `--organization` flag** on `ship`/`repo:config`/`application:create`, and **no
  `organization:list`** command. `.cloud/config.json` should be **gitignored** in a forkable repo so each
  fork binds to its own org/app.

## Works fine via CLI (non-interactive)

- `cloud ship --name=matte --database=postgres18` — interactive **org pick only**; creates app + default env
  (named `main`). NOTE: on the validated run, `ship` created the app + env but did **not** finish the
  Postgres — verify and provision the DB yourself if `databaseSchemaId` is null / a query fails.
- `database-cluster:create --name matte --type neon_serverless_postgres_18 --region <r> --json -n` →
  cluster id (Neon serverless, scales to zero). `database:create <cluster> --name matte --json -n` →
  schema id (a numeric id, e.g. `65431203`). The cluster must be `available` first.
- `environment:update <env> --database-id <schemaId> -n --force` — **attaches the DB; takes effect on the
  next deploy.** `environment:get` keeps reporting `databaseSchemaId: null` even when it's attached — a
  readback gap, NOT a no-op. **Verify by exercising** (`migrate --force` in the deploy command succeeds;
  `migrate:status` lists `matte_jobs`). Set `DB_CONNECTION=pgsql`.
- `environment:update <env> --build-command="…" --deploy-command="…" -n --force` — sets the build/deploy
  commands. **Build-command filesystem changes persist into the artifact shipped to all instances;
  deploy-command changes do NOT persist** (Cloud docs). So provision the binary in the **build** command.
- `environment:variables <env> --action set --key K --value V -n --force` — upserts one var (preserves
  others incl. Cloud-injected). Actions are **append/set/replace only — no delete** (to "remove", `set` it
  to the right value, or `replace` from a file). **Env vars apply on deploy.**
- `bucket:create --name … --region … --visibility private --key-name … --key-permission read_write
  --allowed-origins <url> --json -n` — creates an **org-level** bucket (type Cloudflare **R2**, S3-compatible).
  `--allowed-origins` is required. **The R2 `secretAccessKey` is NEVER returned** (masked in create/get/list
  even with `--show-sensitive`) — and you don't need it (see bucket attach below).
- `command:run <env> --cmd="php artisan …" -n` — runs a **shell** command on an instance (prefix `php artisan`).
  Returns JSON lines; read the `command.success` line's `output`. To run multi-line PHP cleanly, base64 a
  `.php` file (with a `<?php` tag!) and `echo <b64> | base64 -d > /tmp/p.php && php artisan tinker
  --execute="require('/tmp/p.php');"`.
- `deploy matte main --no-wait -n` → a `deployment_id`; poll `deployment:get <id> --json -n` for `status`
  (`build.running` → `deployment.running` → `deployment.succeeded`/`failed`) + `failureReason`. Deploy
  auto-runs the deploy command (set it to `php artisan migrate --force`).

## Bugged / dashboard-only on v0.5.0

- **Object-storage bucket → environment association is dashboard-only.** The env object has
  `databaseSchemaId`/`cacheId`/`websocketApplicationId` but **no bucket field**, and no `bucket:*`/
  `environment:update` flag associates a bucket to an env. **Attach a bucket in the dashboard** (env →
  Storage). Once attached + deployed, Cloud injects a disk named **`private`** (the bucket's visibility),
  an `AwsS3V3Adapter`, and sets `FILESYSTEM_DISK=private` as the default — fully managed (you never touch
  the R2 secret). Matte's `MATTE_DISK` defaults to `FILESYSTEM_DISK`, so it just works. Verify by exercising:
  `Storage::disk(config('matte-server.disk'))->put(...)` → adapter is `AwsS3V3Adapter` and the object lands
  in the bucket.
- **`managed-queue:create` is broken non-interactively.** It always sends `min_replicas` and the API
  rejects it (*"min_replicas field cannot be set for managed queues, which always scale to zero"*),
  regardless of `--size`/`--max-workers`/etc. → **create the managed queue in the dashboard**, then
  `managed-queue:set-default`. (`managed-queue:list` takes no env arg and no `--json`.) The validated run
  proved the async job path with `QUEUE_CONNECTION=database` + a manual `queue:work database --once`; the
  managed queue is the production worker.
- **`instance:sizes --json` returned empty** for the org. Get sizes from an existing app:
  `instance:list <some-env> --json -n` shows e.g. app `flex-1gb`, managed queue `mq-pro-256mb`.

## The binary-on-Cloud answer (validated)

- Build command: `composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader && php
  artisan matte:provision-binary`, with env `MATTE_RUNTIME_PATH=/var/www/html/runtime` (a `base_path`
  location — NOT `storage/`, which Cloud may mount over). The build runs **arm64**, so `BinaryLocator`
  fetches `bg-remover-linux-arm64` correctly; the ~8 MB binary + `lib/libonnxruntime.so.1` are baked into
  the artifact and ship to **every** instance. Verified: `matte:doctor` → `PASS Real grabcut conversion`
  from the artifact with no re-download.

## Don't trust `environment:get` to verify state

`databaseSchemaId`, `cacheId` come back null even when set; bucket isn't represented at all. **Verify
functionally**: `matte:doctor` PASS, `POST /v1/remove?sync=1` → 200 transparent PNG, async → `202` →
`GET /v1/jobs/{id}` → `done` with the object in the bucket, `migrate:status` lists `matte_jobs`.
