# Matte resource plan — command sequence + env checklist

Read [cli-reality.md](cli-reality.md) first — it marks which steps need the dashboard on cloud-cli v0.5.0.
`<...>` = captured from a prior `--json`. Matte is light: bias to the smallest web instance; the only fixed
cost is the **Growth tier (~$20/mo)** that a managed-queue cluster requires. Per-image compute is a fraction
of a cent.

## Sizing (re-resolve from a working app — `instance:sizes` returned empty)

| Tier | Web instance | Managed queue | Postgres | For |
| --- | --- | --- | --- | --- |
| **Default** | `flex-1gb` (`flex-512mb` floor) | `mq-pro-256mb` (scales to zero) | Neon serverless (default CU) | most clients; scale later with `instance:update` |

## Provisioning sequence (validated end-to-end)

```sh
# 0. BOOTSTRAP (user runs interactively, once — only prompt is the org picker)
cloud ship --name=matte --database=postgres18
cloud repo:config
#    → writes .cloud/config.json {organization_id, application_id}; later commands are non-interactive.

# 1. Capture ids
cloud application:get <app> --json -n        # → defaultEnvironmentId (the env), region
cloud environment:get <env> --json -n        # → url (https://matte-<...>.laravel.cloud)

# 2. DATABASE (if ship didn't finish it — verify by exercising, not by env:get)
cloud database-cluster:create --name matte --type neon_serverless_postgres_18 --region <region> --json -n
cloud database:create <cluster-id> --name matte --json -n            # → <schema-id> (numeric)
cloud environment:update <env> --database-id <schema-id> -n --force  # attaches; effective on deploy
cloud environment:variables <env> --action set --key DB_CONNECTION --value pgsql -n --force

# 3. BINARY via BUILD command (bakes bg-remover-linux-arm64 into the artifact -> all instances)
cloud environment:variables <env> --action set --key MATTE_RUNTIME_PATH --value /var/www/html/runtime -n --force
cloud environment:update <env> -n --force \
  --build-command="composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader && php artisan matte:provision-binary"

# 4. DEPLOY command = migrations
cloud environment:update <env> --deploy-command="php artisan migrate --force" -n --force

# 5. BUCKET (DASHBOARD): env -> Storage -> attach a bucket.
#    Cloud then injects FILESYSTEM_DISK=private + a managed `private` S3 disk as default.
#    Matte uses it automatically (MATTE_DISK defaults to FILESYSTEM_DISK). Leave MATTE_DISK unset.

# 6. MANAGED QUEUE (DASHBOARD on v0.5.0 — managed-queue:create is bugged): create size mq-pro-256mb, then:
cloud managed-queue:set-default <queue>          # leave MATTE_QUEUE_CONNECTION unset -> job uses the default

# 7. DEPLOY + poll
cloud deploy matte main --no-wait -n             # → deployment_id
cloud deployment:get <deployment_id> --json -n   # poll until deployment.succeeded

# 8. BOOTSTRAP TOKEN, then redeploy (per-app tokens come later via `token:create`)
cloud environment:variables <env> --action set --key FALLBACK_TOKEN --value "<random-secret>" -n --force
cloud deploy matte main --no-wait -n             # redeploy to apply FALLBACK_TOKEN
```

## Verify (functional — never by `environment:get`)

```sh
cloud command:run <env> --cmd="php artisan matte:doctor" -n          # PASS Real grabcut conversion
# sync:
curl -s -o out.png -w '%{http_code} %{content_type}\n' -F image=@sample.jpg \
  "https://<env-url>/v1/remove?sync=1" -H "Authorization: Bearer <token>"   # 200 image/png (PNG color-type 6)
# async:
curl -s -F image=@sample.jpg "https://<env-url>/v1/remove" -H "Authorization: Bearer <token>"  # 202 {job_id}
curl -s "https://<env-url>/v1/jobs/<job_id>" -H "Authorization: Bearer <token>"                 # -> "done", output_ref
# no token -> 401
```

## Environment variable checklist

| Key | Value | Notes |
| --- | --- | --- |
| `DB_CONNECTION` | `pgsql` | DB_* host/db/user/password are Cloud-injected from the attached schema on deploy. |
| `MATTE_RUNTIME_PATH` | `/var/www/html/runtime` | `base_path` location so the build-baked binary ships in the artifact. |
| `FALLBACK_TOKEN` | `<random-secret>` | Bootstrap/fallback token. Delete it and use per-app `token:create` tokens for production. Apply on (re)deploy. |
| `MATTE_DISK` | **unset** | Defaults to `FILESYSTEM_DISK` (the injected `private` bucket disk). Set only to override. |
| `MATTE_QUEUE_CONNECTION` | **unset** | Job dispatches on the app's default connection = the managed queue (after `set-default`). |
| `MATTE_BG_REMOVER_TAG` | unset (default `v0.7.1`) | The pinned bg-remover release. |
| `MATTE_MODEL_NAME` / `MATTE_MODEL_URL` | unset | Only for ML mode (`--model`); GrabCut is the no-model default. |

## Scale later

`cloud instance:update <inst>` (size/replicas), resize the managed queue in the dashboard, or raise the
Neon `cu_max`. The Neon DB suspends when idle; the managed queue scales to zero.
