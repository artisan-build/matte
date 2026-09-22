<p align="center">
  <img src="art/icon.png" alt="matte icon" width="128">
</p>

# Matte

**Matte removes the background from an image. You send it a photo, it sends back a transparent PNG.**

Matte is a small Laravel application that you run yourself. It replaces the background-removal
services that charge you for every image you send them. With Matte there is no per-image bill —
you pay only for the server it runs on — and no outside company sits in the middle of your
requests.

Matte has no web interface and nothing to log into. It is an HTTP API with three endpoints, so
anything that can make an HTTP request can use it. If your app is a Laravel app, there is also a
client package that wraps those requests for you.

## The easy way: Scalpels

[Scalpels](https://scalpels.app/products/matte) deploys and runs Matte for you, in a Laravel Cloud
account you own. It stands the server up, keeps it patched, and connects it to your app — it writes
the server URL and the API credential directly into your app's environment, so the credential never
passes through your hands.

Use Scalpels if you would rather ship your product than operate an image pipeline. Everything below
is the do-it-yourself path, which is free and fully documented.

## The API

Three routes. All three need a credential (see [step 6](#6-create-an-api-credential)).

| Method and path | What it does |
| --- | --- |
| `POST /v1/remove` | Submit an image. Returns `202` and a job id. Add `?sync=1` to convert during the request and get the PNG back immediately. |
| `GET /v1/jobs/{jobId}` | Ask how a job is doing: `queued`, `processing`, `done` or `failed`. |
| `GET /v1/jobs/{jobId}/result` | Download the finished PNG. Returns `409` if the job is not `done` yet. |

The normal flow is asynchronous: you submit an image, a queue worker converts it, and you poll the
status route until it says `done`. `?sync=1` is for small images where waiting a second in the
request is fine.

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

---

## Run it yourself

### Prerequisites

- **PHP 8.3 or newer** with Composer. (CI runs the tests on PHP 8.5.)
- **Git.**
- A **[Laravel Cloud](https://cloud.laravel.com)** account, if you want to deploy it (steps 8–9).
- On **macOS only**, if you want to convert images on your own machine:
  `brew install opencv onnxruntime`. See [Troubleshooting](#troubleshooting) — this currently does
  not work on up-to-date Homebrew, and it is not needed to run the tests or to deploy.

### 1. Clone and install

```shell
git clone https://github.com/artisan-build/matte.git
cd matte
composer install
```

### 2. Create your environment file

```shell
cp .env.example .env
php artisan key:generate
php artisan migrate --force
```

`migrate` creates `database/database.sqlite` for you and adds the tables Matte needs. You should see
a list of migrations, each ending in `DONE`. SQLite is fine for local work; on Laravel Cloud you use
a managed Postgres database instead.

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

### 5. Check the runtime

```shell
php artisan matte:doctor
```

`matte:doctor` checks that the binary is there, that the ONNX Runtime library is there, that the
operating system can load everything the binary links against, and finally runs a **real
conversion** to prove the whole thing works. Each check prints `PASS`, `FAIL` or `SKIP`, and the
command exits non-zero if anything failed.

This is the command to run on a deployed instance when something is wrong. On macOS it may report a
dynamic-library failure — see [Troubleshooting](#troubleshooting).

### 6. Create an API credential

Matte's routes are protected by bearer credentials from
[`artisan-build/built-for-cloud`](https://github.com/artisan-build/built-for-cloud), which Matte
installs as a dependency. Mint one like this:

```shell
php artisan bfc:credential:mint installation 'acme-crm' --kind=bearer --purpose=consumption --name='matte-acme-crm' --local
```

Three things in that command are worth understanding:

- **`installation 'acme-crm'`** is the *subject* — who the credential belongs to. `installation`
  means one installed copy of one consuming app, so revoking this credential cuts off that app and
  nothing else. `acme-crm` is an identifier you choose for that consumer. Use `external_consumer`
  instead for an outside party, such as someone else's system or a CI runner.
- **`--purpose=consumption`** is the *purpose* — a fixed label saying what the credential may be
  used for. Matte's image routes accept only `consumption` credentials. A perfectly valid credential
  minted for a different purpose is refused.
- **`--local` runs the command against the database on the machine you are sitting at.** Without it
  the command refuses to run and tells you to use the HTTP contract instead. Always pass `--local`,
  and run the command *inside* the environment you want the credential to exist in.

The command prints the credential once and stores only a hash of it. Copy it straight into your
consuming app's secret manager. There is no way to read it back later; if you lose it, mint a new
one and revoke the old.

Two companions: `php artisan bfc:credential:list --local` shows what exists (never the secrets), and
`php artisan bfc:credential:revoke <credential-id> --local` kills one.

### 7. Call the API

Start the app and try it:

```shell
php artisan serve --host=127.0.0.1 --port=8765
```

In another terminal, with `$TOKEN` set to the credential from step 6:

```shell
# No credential -> 401.
curl -i -F image=@sample.jpg http://127.0.0.1:8765/v1/remove

# Submit an image -> 202 and a job id.
curl -F image=@sample.jpg -H "Authorization: Bearer $TOKEN" http://127.0.0.1:8765/v1/remove
# {"envelope_version":1,"job_id":"01a0cb3d-...","status":"queued"}

# Check on it.
curl -H "Authorization: Bearer $TOKEN" http://127.0.0.1:8765/v1/jobs/01a0cb3d-...

# Fetch the PNG once the status is "done".
curl -o out.png -H "Authorization: Bearer $TOKEN" http://127.0.0.1:8765/v1/jobs/01a0cb3d-.../result
```

The job sits at `queued` until a queue worker picks it up, so run one:

```shell
php artisan queue:work
```

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
| `callback_destination` | A destination name you registered in config. Async only. | none |

`callback_url` is rejected on purpose: the server will not post a result to an arbitrary URL you
hand it. To have Matte push results instead of you polling, register a destination under
`matte-server.callback.destinations` with its URL and scope; Matte signs the completion payload and
posts it there. Delivery is best-effort and never changes the job's stored outcome.

The output file's storage key is a hash of the input bytes plus the options, so re-submitting the
same image with the same options overwrites the same object instead of piling up copies. (The
conversion does run again.)

### 8. Deploy to Laravel Cloud

> ⛔ **The one rule that will bite you: never set an environment variable for a resource Laravel
> Cloud provisions for you.** When you attach a database, a cache, a managed queue or a storage
> bucket, Cloud injects all of its configuration — the credentials *and* the connection selectors
> like `DB_CONNECTION`, `QUEUE_CONNECTION`, `CACHE_STORE` and `FILESYSTEM_DISK` — into a managed
> environment file your app reads at runtime. Anything you set yourself shadows the injected value
> and breaks the resource. Provision, attach, deploy, and let Cloud fill those in.

Matte needs four things from Cloud: **compute**, a **database**, a **managed queue** (this is the
worker that runs the conversions) and an **object-storage bucket** (this is where images live).

The order that works:

1. **Bootstrap the app.** From your fork's root, run `cloud ship` and then `cloud repo:config`.
   These two are interactive and you only run them once; `ship` creates the application, the
   environment and the database and does the first deploy, and `repo:config` writes
   `.cloud/config.json` so later commands know which application they are talking to.
2. **Set the build command** to install dependencies *and* fetch the binary:
   `composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader && php artisan matte:provision-binary`.
   It has to be the **build** command, not the deploy command: files written during the build are
   baked into the artifact that ships to every instance, and files written during deploy are not.
3. **Set the deploy command** to `php artisan migrate --force`.
4. **Attach a storage bucket** to the environment in the Cloud dashboard. Cloud then makes it the
   default disk and Matte uses it automatically. Do not set `AWS_*` or `MATTE_DISK`.
5. **Create a managed queue** and make it the default. Do not set `QUEUE_CONNECTION` or
   `MATTE_QUEUE_CONNECTION`; the removal job then runs on the managed queue by default.
6. **Deploy.**
7. **Prove it works by exercising it**, not by reading settings back — the CLI cannot be trusted to
   report what is attached. Run `cloud command:run <env> --cmd="php artisan matte:doctor"`; it must
   report a real conversion passing. Then mint a credential on that environment the same way as in
   step 6 above, and call `POST /v1/remove?sync=1` with it. A request with no credential must come
   back `401`.

There is more detail, including the exact CLI commands and where the CLI still needs the dashboard,
in [`.claude/skills/provisioning-matte-on-cloud/`](.claude/skills/provisioning-matte-on-cloud/).
If you use a coding agent that understands skills, you can open this repository and ask it to
provision a Matte instance on Laravel Cloud; that folder is written for exactly that.

### 9. Connect a Laravel app

In the app that will send images:

```shell
composer require artisan-build/matte-client
php artisan matte:install
```

`matte:install` asks for your Matte server URL and the credential from step 6, writes them to that
app's `.env` as `MATTE_URL` and `MATTE_TOKEN`, and publishes the client config. Run it in a terminal
you control — it is asking for a secret.

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
| `MATTE_TOKEN` | unset | The credential from step 6. Required, and a secret. |
| `MATTE_DEFAULT_MODE` | `ml` | Mode used when you do not pass one. |
| `MATTE_DEFAULT_PRESET` | `balanced` | Preset used when you do not pass one. |
| `MATTE_POLL_INTERVAL` | `2` | Seconds between status checks while waiting. |
| `MATTE_POLL_TIMEOUT` | `120` | Seconds to wait before giving up on a job. |
| `MATTE_STORE_DISK` | unset | If set, `AwaitRemovalJob` saves the PNG to `matte/<job-id>.png` on that disk. |
| `MATTE_CALLBACK_PATH` | `matte/callback` | Where the client mounts its callback receiver. |

## Troubleshooting

**`matte:doctor` says the binary is missing.** You have not run `php artisan matte:provision-binary`
yet, or `MATTE_RUNTIME_PATH` points somewhere else than it did when you ran it.

**On macOS, `matte:doctor` fails on "dynamic library dependencies" even after
`brew install opencv onnxruntime`.** The macOS build of `bg-remover v0.8.0` links against OpenCV
4.13, and Homebrew now ships OpenCV 5 (its `opencv@4` formula is 4.14, and it installs somewhere
else). There is currently no way to satisfy this with Homebrew, so local image conversion on macOS
does not work. Nothing else is affected: the tests pass, the API answers, and the Linux builds are
unaffected — they carry OpenCV inside the binary and need only the ONNX Runtime library that
`matte:provision-binary` puts next to them. Convert against a deployed instance instead.

**A job is stuck at `processing` and never finishes.** The worker started it and then died. Check
your queue worker and your `failed_jobs` table. If the conversion binary crashes outright — rather
than exiting with an error — the job row is left at `processing` and the queue job lands in
`failed_jobs`.

**`POST /v1/remove?sync=1` returns `503`.** The binary or its libraries are not available on the
machine handling the request. Run `matte:doctor` there.

**Everything returns `401`.** The credential is missing, wrong, revoked, expired, or was minted with
a purpose other than `consumption`. `php artisan bfc:credential:list --local` shows which credentials
exist on that environment.

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
