<p align="center">
  <img src="art/icon.png" alt="matte icon" width="128">
</p>

# Matte

**Matte removes the background from an image. You send it a photo, it sends back a transparent PNG.**

Matte is a small Laravel application that you run yourself. It replaces the background-removal
services that charge you for every image you send them. The software is open source and there is no
per-image fee to anybody; what you pay is your hosting bill for the compute, database, queue and
storage it runs on.

Most of Matte is an HTTP API — three routes that anything speaking HTTP can call. There is also a
small administrative web page, inherited from the auth package, described below. If your app is a
Laravel app, there is a client package that wraps the API calls for you.

## The easy way: Scalpels

[Scalpels](https://scalpels.app/products/matte) deploys and runs Matte for you, in a Laravel Cloud
account you own. It stands the server up, keeps it patched, and connects it to your app — it writes
the server URL and the API credential directly into your app's environment, so the credential never
passes through your hands.

Use Scalpels if you would rather ship your product than operate an image pipeline. Everything below
is the do-it-yourself path.

## The image API

Three routes. All three need a credential (see [step 7](#7-create-an-api-credential)).

| Method and path | What it does |
| --- | --- |
| `POST /v1/remove` | Submit an image. Returns `202` and a job id. With `?sync=1` the conversion runs inside this request and the response body is the PNG. |
| `GET /v1/jobs/{jobId}` | Ask how a job is doing: `queued`, `processing`, `done` or `failed`. |
| `GET /v1/jobs/{jobId}/result` | Download the finished PNG. Returns `409` if the job is not `done` yet. |

The normal flow is asynchronous: you submit an image, a queue worker converts it, and you poll the
status route until it says `done`. `?sync=1` holds the HTTP request open until the conversion
finishes or hits `MATTE_TIMEOUT` (120 seconds by default) — use it only where blocking the request
that long is acceptable.

How it fits together:

```
your app  ──POST /v1/remove──►  Matte (one Laravel Cloud app)
                                  │  check the credential, save the original to storage,
                                  │  create a job row, push it onto the queue
                                  ▼
                                queue worker  ──►  runs the bg-remover binary
                                  │                writes the transparent PNG to storage
                                  ▼
your app  ──GET /v1/jobs/{id}──►  "done"  ──GET /v1/jobs/{id}/result──►  PNG
```

The conversion itself is done by [`bg-remover`](https://github.com/artisan-build/bg-remover), a
command-line program Matte downloads and runs. It has two engines: an ML model (the default) and
GrabCut, a classical algorithm that needs no model file.

## The other pages

Matte gets an authentication and administration surface from
[`artisan-build/built-for-cloud`](https://github.com/artisan-build/built-for-cloud), so a few more
routes exist. Two are useful and need no credential:

| Path | What it does |
| --- | --- |
| `GET /` | Returns `{"name":"Matte","status":"ok"}`. A cheap "is it up" check. |
| `GET /up` | Laravel's health endpoint. |

There is also a sign-in page at `GET /bfc/login` and an administration page at `GET /bfc/ui`, which
redirects to the login page when you are signed out. **Matte deliberately switches every panel in
that UI off** — member management, personal credentials, installation credentials, session
management and the rest are all `false` in `config/built-for-cloud.php`. Signed in, the page shows
the product name, its description, a link to Scalpels and a log-out button, and nothing else.

So there is no image work and no credential work to do in a browser: **everything you actually
administer on a Matte instance, you do from the command line.** Creating the first sign-in user at
all is optional, and covered in [step 8](#8-optional-the-sign-in-page).

---

## Run it yourself

### Prerequisites

- **PHP 8.3 or later in the PHP 8 series** (the `^8.3` constraint excludes PHP 9), with Composer.
  CI runs the tests on PHP 8.5.
- **Git.**
- **To convert an image on your own machine**, a platform `bg-remover` ships a build for:

  | Platform | Local conversion |
  | --- | --- |
  | Linux x86_64 | Works. |
  | Linux arm64 | Works. |
  | macOS on Apple Silicon | A build exists, but it **cannot currently run** — see [Troubleshooting](#troubleshooting). |
  | Windows, macOS on Intel, anything else | Not supported. `matte:provision-binary` throws `UnsupportedPlatform`. |

  If you are not on Linux, [step 6](#6-convert-an-image-without-a-linux-machine) gives you a
  container command that does convert. Nothing else in this guide needs the binary — the tests, the
  API, the credentials and the deploy all work anywhere PHP does.
- **To deploy** (steps 9–10): a [Laravel Cloud](https://cloud.laravel.com) account, and the Cloud
  CLI: `composer global require laravel/cloud-cli`, then `cloud auth` to sign in (it opens a
  browser; in CI, set `LARAVEL_CLOUD_TOKEN` instead).

### 1. Fork, clone and install

You deploy **your own fork**, so make one first if you intend to go past step 8. On GitHub, fork
[`artisan-build/matte`](https://github.com/artisan-build/matte), then:

```shell
git clone https://github.com/<your-account>/matte.git
cd matte
composer install
```

Only trying it locally? Clone `https://github.com/artisan-build/matte.git` directly instead.

### 2. Create your environment file

```shell
cp .env.example .env
php artisan key:generate
php artisan migrate --force
```

`migrate` creates `database/database.sqlite` for you and adds the tables Matte needs. You should see
a list of migrations, each ending in `DONE`. SQLite is fine for local work; on Laravel Cloud you use
a managed Postgres database instead.

`.env.example` ships `APP_NAME=Laravel`. Set `APP_NAME=Matte` if you care — it is what the instance
reports about itself on `GET /bfc/meta`.

### 3. Run the tests

```shell
composer test
```

This clears the config cache, checks formatting with Pint, and runs the test suite. Everything should
pass. Run it again any time you change something — it is the quickest way to know the app still boots
and the routes are still wired up.

### 4. Install the background-removal binary

```shell
php artisan matte:provision-binary
```

This downloads three things into a `runtime/` folder: the `bg-remover` program built for your
operating system and CPU, the ONNX Runtime library it needs, and the default ML model. The program
itself is checked against the checksum published with the release. `runtime/` is ignored by Git.

You should see `Matte binary provisioning complete.` followed by a line for each of the three files,
each ending in `downloaded`. Run the command again and they say `already present` instead; `--force`
downloads them afresh.

Two ways this fails. On an unsupported platform it raises `UnsupportedPlatform` and names your OS
and CPU — skip to [step 6](#6-convert-an-image-without-a-linux-machine). And the default ML model is
178 MB, which the command reads into memory in one go, so a PHP with a `memory_limit` under about
200 MB dies with `Allowed memory size ... exhausted`. If that happens, run
`php -d memory_limit=-1 artisan matte:provision-binary` instead.

### 5. Check the runtime

```shell
php artisan matte:doctor
```

`matte:doctor` checks that the binary is there, that the ONNX Runtime library is there, that the
operating system can load everything the binary links against, and finally runs a **real
conversion** to prove the whole thing works. Each check prints `PASS`, `FAIL` or `SKIP`, and the
command exits non-zero if anything failed. On Linux, all four say `PASS`:

```
PASS Binary present and executable
PASS ONNX Runtime library present
PASS Linux dynamic library resolution
PASS Real grabcut conversion
```

This is the command to run on a deployed instance when something looks wrong. On macOS it currently
reports a dynamic-library failure instead — see [Troubleshooting](#troubleshooting).

### 6. Convert an image without a Linux machine

The Linux builds carry OpenCV inside the binary; the only thing they load from outside is the ONNX
Runtime library that `matte:provision-binary` places next to them. That means a stock PHP image with
**no extra system packages** can run a conversion. If you have Docker, this works from your checkout:

```shell
rm -rf runtime
docker run --rm -v "$PWD:/app" -w /app php:8.4-cli \
  bash -c "php -d memory_limit=-1 artisan matte:provision-binary && php artisan matte:doctor"
```

All four checks print `PASS` and the command exits `0`. `rm -rf runtime` clears anything provisioned
for another platform; `-d memory_limit=-1` is there because the stock PHP image allows 128 MB and the
model is 178 MB.

To convert one of your own images, put it in the checkout and run the CLI converter the same way:

```shell
docker run --rm -v "$PWD:/app" -w /app php:8.4-cli \
  php artisan matte:remove my-photo.jpg --mode=grabcut --out=/app/out.png
```

`out.png` will be an RGBA PNG with the background transparent. The container writes `runtime/` as
root; if you later want the binary for your own machine back, `rm -rf runtime` and run step 4 again.

### 7. Create an API credential

Matte's image routes are protected by bearer credentials from
[`artisan-build/built-for-cloud`](https://github.com/artisan-build/built-for-cloud), which Matte
installs as a dependency. Mint one like this:

```shell
php artisan bfc:credential:mint installation 'acme-crm' --kind=bearer --purpose=consumption --name='matte-acme-crm' --local
```

Three things in that command are worth understanding:

- **`installation 'acme-crm'`** is the *subject* — who the credential belongs to. `installation`
  means one installed copy of one consuming app, so revoking this credential cuts off that app and
  nothing else. `acme-crm` is an identifier you choose for that consumer. **Use `installation` for
  every service client.** The other subject types the command accepts are real, but Matte's image
  routes admit only installation-owned credentials and signed-in users' own credentials — an
  `external_consumer` credential gets `401` on every image route even when its purpose is correct.
- **`--purpose=consumption`** is the *purpose* — a fixed label saying what the credential may be
  used for. Matte's image routes accept only `consumption` credentials. A perfectly valid credential
  minted for a different purpose is refused.
- **`--local` runs the command against the database on the machine you are sitting at.** Without it
  the command refuses to run and tells you to use the HTTP contract instead. Always pass `--local`,
  and run the command *inside* the environment you want the credential to exist in. A credential
  minted on your laptop does not work against your deployed server, and the other way round.

The command prints the credential once and stores only a hash of it. Copy it straight into your
consuming app's secret manager. There is no way to read it back later; if you lose it, mint a new
one and revoke the old.

Two companions: `php artisan bfc:credential:list --local` shows what exists (never the secrets), and
`php artisan bfc:credential:revoke <credential-id> --local` kills one.

### 8. (Optional) The sign-in page

You do not need a user account to run Matte, and the administration page has nothing in it that the
commands above do not do better. If you want one anyway, two steps.

First, make sessions survive between requests. `.env.example` ships `SESSION_DRIVER=array`, which
keeps a session in memory for one request only, so signing in cannot work: the login form always
comes back `419`. Set this in your `.env`:

```
SESSION_DRIVER=file
```

Do not set it to `database` — the auth package refuses to boot with database sessions.

Then create the Owner. It prompts for an email, a name and a password of eight characters or more:

```shell
php artisan create-admin --local
```

> ⚠️ **Pass `--local`.** Without it, `create-admin` lists your Laravel Cloud environments and offers
> to create the account on one of them. That is a real account on a real server, created from what
> looks like a local command.

Now `php artisan serve` and open `/bfc/login`.

### 9. Call the API

Start the app:

```shell
php artisan serve --host=127.0.0.1 --port=8765
```

Then, in another terminal, from the same checkout. The repository ships no test image, so copy any
JPEG or PNG you have to `sample.jpg` in the checkout first, and paste the credential from step 7
into `TOKEN`:

```shell
TOKEN='paste-the-credential-here'

# No credential -> 401.
curl -i -F image=@sample.jpg http://127.0.0.1:8765/v1/remove

# Submit an image -> 202 and a job id.
curl -F image=@sample.jpg -H "Authorization: Bearer $TOKEN" http://127.0.0.1:8765/v1/remove
# {"envelope_version":1,"job_id":"01a0cb3d-484f-7194-82ce-f310e5938786","status":"queued"}

# Check on it. Use the job_id the previous command returned, not this one.
curl -H "Authorization: Bearer $TOKEN" http://127.0.0.1:8765/v1/jobs/01a0cb3d-484f-7194-82ce-f310e5938786

# Fetch the PNG once the status is "done".
curl -o out.png -H "Authorization: Bearer $TOKEN" \
  http://127.0.0.1:8765/v1/jobs/01a0cb3d-484f-7194-82ce-f310e5938786/result
```

`TOKEN` is set only for that shell; it is never written to a file. The job sits at `queued` until a
queue worker picks it up, so run one in a third terminal:

```shell
php artisan queue:work
```

On a platform where the binary cannot run, the job fails and `?sync=1` returns `503` instead of a
PNG. Everything else above — the `401`, the `202`, the job id, the `409` before the job is done —
behaves the same everywhere.

Form fields you can send with `POST /v1/remove`:

| Field | Values | Default |
| --- | --- | --- |
| `image` | The image file. Required. | — |
| `mode` | `ml` or `grabcut` | `MATTE_DEFAULT_MODE`, which is `ml` |
| `preset` | `fast`, `balanced` or `quality` | `balanced` |
| `model` | A model filename inside `runtime/models`. Used in `ml` mode. | `MATTE_MODEL_NAME` |
| `edge_mode` | `blur`, `bilateral` or `guided` | none |
| `iterations` | A positive integer — the GrabCut iteration count. | none |
| `margin` | A positive integer — the GrabCut margin. | none |
| `sync` | `1` to convert during the request | off |
| `callback_destination` | The name of a destination registered in the server's config. Async only. | none |

`callback_url` is rejected on purpose: the server will not post a result to an arbitrary URL you
hand it. Having Matte push results instead of you polling is possible but is more setup than this
guide covers — each destination needs an entry carrying `url`, `subject_ref`, `installation`,
`application` and `audience`, and the receiving app needs a bound HMAC credential installed so it can
verify what arrives. The complete procedure is in
[`packages/matte-client/docs/integrate/default.md`](packages/matte-client/docs/integrate/default.md).
Polling works with no setup at all, so start there.

The output file's storage key is a hash of the input bytes plus the options, so re-submitting the
same image with the same options overwrites the same object instead of piling up copies. (The
conversion does run again.)

### 10. Deploy to Laravel Cloud

> ⛔ **The one rule that will bite you: never set an environment variable for a resource Laravel
> Cloud provisions for you.** When you attach a database, a cache, a managed queue or a storage
> bucket, Cloud injects all of its configuration — the credentials *and* the connection selectors
> like `DB_CONNECTION`, `QUEUE_CONNECTION`, `CACHE_STORE` and `FILESYSTEM_DISK` — into a managed
> environment file your app reads at runtime. Anything you set yourself shadows the injected value
> and breaks the resource. Provision, attach, deploy, and let Cloud fill those in.

Matte needs four things from Cloud: **compute**, a **database**, a **managed queue** (this is the
worker that runs the conversions) and an **object-storage bucket** (this is where images live).

Run these from your fork's root, in order. `<env>`, `<queue>` and `<deployment-id>` are ids the
earlier commands print.

```shell
# 1. Bootstrap. Interactive, and you do this once. The only prompt is the organisation picker.
#    `ship` creates the application, the environment and the database, and deploys once.
cloud ship --name=matte --database=postgres18
cloud repo:config          # writes .cloud/config.json so later commands know the application

# 2. Capture the ids everything else needs.
cloud application:get matte --json -n     # -> defaultEnvironmentId  (this is <env>) and region
cloud environment:get <env> --json -n     # -> url (https://matte-....laravel.cloud) = <env-url>

# 3. Build command: install dependencies AND bake the binary into the artifact.
cloud environment:update <env> -n --force \
  --build-command="composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader && php artisan matte:provision-binary"

# 4. Deploy command: run migrations.
cloud environment:update <env> --deploy-command="php artisan migrate --force" -n --force
```

It has to be the **build** command in step 3, not the deploy command: files written during the build
are baked into the artifact that ships to every web and worker instance, and files written during
deploy are not.

Two things the CLI cannot do for you today, both in the [Cloud dashboard](https://cloud.laravel.com):

- **Attach a storage bucket** — open your environment, go to **Storage**, attach a bucket. Cloud then
  makes it the app's default disk and Matte follows it. Do not set `AWS_*` or `MATTE_DISK`.
- **Create the managed queue** — create one in the environment's queue section, then make it the
  default. Do not set `QUEUE_CONNECTION` or `MATTE_QUEUE_CONNECTION`.

```shell
cloud managed-queue:set-default <queue>

# 5. Deploy, then poll until the deployment succeeds.
cloud deploy matte main --no-wait -n         # -> <deployment-id>
cloud deployment:get <deployment-id> --json -n
```

Then **prove it works by exercising it**, never by reading settings back — the CLI is known to
under-report what is attached:

```shell
# The worker can really convert. Must end with: PASS Real grabcut conversion
cloud command:run <env> --cmd="php artisan matte:doctor" -n

# The credential your consuming app will use. Revealed once; copy it into that app's secret manager.
cloud command:run <env> --cmd="php artisan bfc:credential:mint installation 'acme-crm' --kind=bearer --purpose=consumption --name='matte-acme-crm' --local" -n
```

```shell
# A real conversion over HTTP. 200 and a PNG.
curl -s -o out.png -w '%{http_code} %{content_type}\n' -F image=@sample.jpg \
  -H "Authorization: Bearer $TOKEN" "https://<env-url>/v1/remove?sync=1"

# And no credential is still 401.
curl -s -o /dev/null -w '%{http_code}\n' -F image=@sample.jpg "https://<env-url>/v1/remove"
```

The repository also carries an agent skill at
[`.claude/skills/provisioning-matte-on-cloud/`](.claude/skills/provisioning-matte-on-cloud/) that
walks an assistant through the same sequence, with the sizing choices and the CLI's rough edges
written down. You do not need it to follow the steps above.

### 11. Connect a Laravel app

In the app that will send images:

```shell
composer require artisan-build/matte-client
php artisan matte:install
```

`matte:install` asks for your Matte server URL and an API credential, writes them to that app's
`.env` as `MATTE_URL` and `MATTE_TOKEN`, and publishes the client config. Run it in a terminal you
control — it is asking for a secret.

**Use the credential that belongs to the server you are pointing at.** Credentials live in one
database each:

- Pointing at `http://127.0.0.1:8765`? Use the one from step 7.
- Pointing at `https://<env-url>` on Cloud? Use the one minted by `cloud command:run` in step 10.
  The step-7 credential is in your laptop's SQLite file and will only ever return `401` there.

```php
use ArtisanBuild\MatteClient\Facades\Matte;
use ArtisanBuild\MatteClient\Jobs\AwaitRemovalJob;

// Asynchronous. Returns straight away with a handle.
$handle = Matte::remove($request->file('photo'), ['mode' => 'grabcut', 'preset' => 'quality']);

// Watch it on your own queue; fires MatteRemovalCompleted when it finishes.
AwaitRemovalJob::dispatch($handle->id());

// Or block until it is done and hand me the bytes.
$png = $handle->result();

// Or, for a small image, do it all inside this request.
$png = Matte::removeSync($smallImage);
```

Anything that is not a Laravel app just posts to `/v1/remove` directly. The client is a convenience,
never a requirement.

---

## Configuration

Matte's server runs with none of these set: every key has a working default, and those defaults are
what a Cloud deployment should use. Set one only to override it. The exceptions are `MATTE_URL` and
`MATTE_TOKEN` in a consuming app, which the client cannot work without.

**On the Matte server** (defined in [`packages/matte-server/config/matte-server.php`](packages/matte-server/config/matte-server.php)):

| Variable | Default | What it does |
| --- | --- | --- |
| `MATTE_DISK` | `FILESYSTEM_DISK`, else `local` | The filesystem disk originals and results are written to. On Cloud, leave unset so it follows the attached bucket. |
| `MATTE_QUEUE_CONNECTION` | unset | The queue connection the removal job runs on. Leave unset to use the app's default connection. |
| `MATTE_RUNTIME_PATH` | `<app>/runtime` | Where `matte:provision-binary` installs the binary, the library and the models. |
| `MATTE_BG_REMOVER_TAG` | `v0.8.0` | Which `bg-remover` release to download. |
| `MATTE_ONNX_VERSION` | `1.19.2` | Which ONNX Runtime version to download. |
| `MATTE_MODEL_NAME` | `isnet-general-use.onnx` | The model file `ml` mode uses by default. |
| `MATTE_MODEL_URL` | the release's model asset | Where to download that model from. |
| `MATTE_DEFAULT_MODE` | `ml` | The mode used when a request does not send one. |
| `MATTE_TIMEOUT` | `120` | Seconds a single conversion may run before it is killed. |
| `MATTE_ROUTE_PREFIX` | empty | Prefix for the three routes, if you want them somewhere other than `/v1/...`. |
| `MATTE_CALLBACK_TIMEOUT` | `5` | Seconds a registered callback delivery may take in total. |
| `MATTE_CALLBACK_CONNECT_TIMEOUT` | `2` | Seconds to spend connecting for that delivery. |

**In a consuming app** (defined in [`packages/matte-client/config/matte.php`](packages/matte-client/config/matte.php)):

| Variable | Default | What it does |
| --- | --- | --- |
| `MATTE_URL` | unset | Your Matte server's base URL. Required. |
| `MATTE_TOKEN` | unset | The credential for that server. Required, and a secret. |
| `MATTE_DEFAULT_MODE` | `ml` | Mode used when you do not pass one. |
| `MATTE_DEFAULT_PRESET` | `balanced` | Preset used when you do not pass one. |
| `MATTE_POLL_INTERVAL` | `2` | Seconds between status checks while waiting. |
| `MATTE_POLL_TIMEOUT` | `120` | Seconds to wait before giving up on a job. |
| `MATTE_STORE_DISK` | unset | If set, `AwaitRemovalJob` saves the PNG to `matte/<job-id>.png` on that disk. |
| `MATTE_CALLBACK_PATH` | `matte/callback` | Where the client mounts its callback receiver. |
| `MATTE_CALLBACK_SUBJECT_REF` | unset | Callbacks only: this installation's routing identity in the callback scope. |
| `MATTE_CALLBACK_INSTALLATION` | unset | Callbacks only: the installation reference in the callback scope. |
| `MATTE_CALLBACK_APPLICATION` | unset | Callbacks only: the application reference in the callback scope. |
| `MATTE_CALLBACK_AUDIENCE` | unset | Callbacks only: this receiver's exact callback audience. |

The four callback-scope values must match the destination registered on the Matte server. See
[`packages/matte-client/docs/integrate/default.md`](packages/matte-client/docs/integrate/default.md).

## Troubleshooting

**`matte:provision-binary` fails with `UnsupportedPlatform`.** There is no `bg-remover` build for
your OS and CPU; the three that exist are Linux x86_64, Linux arm64, and macOS on Apple Silicon.
Use [step 6](#6-convert-an-image-without-a-linux-machine).

**`matte:doctor` says the binary is missing.** You have not run `php artisan matte:provision-binary`
yet, or `MATTE_RUNTIME_PATH` points somewhere else than it did when you ran it.

**`matte:provision-binary` dies with `Allowed memory size ... exhausted`.** It downloads the 178 MB
ML model into memory in one piece. Raise the limit for that one command:
`php -d memory_limit=-1 artisan matte:provision-binary`.

**On macOS, `matte:doctor` fails on "dynamic library dependencies" even after
`brew install opencv onnxruntime`.** The macOS build of `bg-remover v0.8.0` links against OpenCV
4.13, and Homebrew now ships OpenCV 5 (its `opencv@4` formula is 4.14, and it installs somewhere
else). No Homebrew formula currently satisfies it, so **local conversion on macOS does not work at
all right now** — the binary aborts. Nothing else is affected: the tests pass, the API answers, and
the Linux builds are unaffected. Use [step 6](#6-convert-an-image-without-a-linux-machine) or a
deployed instance.

**A job is stuck at `processing` and never finishes.** The worker started it and then died. Check
your queue worker and your `failed_jobs` table. If the conversion binary crashes outright — rather
than exiting with an error — the job row is left at `processing` and the queue job lands in
`failed_jobs`.

**`POST /v1/remove?sync=1` returns `503`.** The binary or its libraries are not available on the
machine handling the request. Run `matte:doctor` there.

**Everything returns `401`.** Either the credential is missing, wrong, revoked, expired or was minted
with a purpose other than `consumption` — or it is the wrong *kind* of credential: only
installation-owned credentials and signed-in users' own credentials are admitted, so an
`external_consumer` credential always gets `401`. Or it belongs to a different server's database.
`php artisan bfc:credential:list --local` shows what exists on the environment you run it in.

**The sign-in form returns `419`.** `SESSION_DRIVER=array` cannot hold a session between two
requests, so the CSRF token never matches. Set `SESSION_DRIVER=file` — see
[step 8](#8-optional-the-sign-in-page).

**A request returns `422` saying the client is ahead of this Matte instance.** The caller is speaking
a newer version of the wire format than the server understands. Upgrade the server first, then the
clients — that is always the safe order.

## How the repository is laid out

This is a monorepo. The application at the root is a thin Laravel shell; the behaviour lives in three
packages under `packages/`, each mirrored read-only to its own repository and published to Packagist.

| Package | Installed in | What it is |
| --- | --- | --- |
| [`matte-contracts`](packages/matte-contracts) | both of the others | The wire format: the request options, the job-status envelope and its version. |
| [`matte-server`](packages/matte-server) | the Matte app | The receiving side: the routes, storage, the queue job, the binary and the console commands. |
| [`matte-client`](packages/matte-client) | apps that send images | The sending side: the `Matte` facade, polling and `matte:install`. |

Issues and pull requests are disabled on the three mirrors — everything happens in this repository —
and pushing a `v*` tag here releases all three packages together at that version.

## License

Matte is open source under the MIT license.
