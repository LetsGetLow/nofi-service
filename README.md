# nofi-service

Notification service. Accepts email and push notifications over a JWT-protected
REST API, records them, and delivers them asynchronously through Symfony
Messenger workers.

- **Stack:** PHP 8.4+, Symfony 7.4, API Platform 4, Doctrine ORM 3, PostgreSQL 16
- **Runtime:** FrankenPHP (Caddy) in worker mode, Firebase Cloud Messaging for push
- **Everything runs in Docker.** No local PHP installation is required.

---

## Requirements

| | |
|---|---|
| Docker Engine | with the Compose plugin **v2.24 or newer** (`env_file` needs the `required:` field) |
| git | |
| `curl`, `jq` | only for the verification steps and `load-test` |

Check your Compose version with `docker compose version`.

---

## First-time setup

### 1. Clone and enter the project

```bash
git clone git@github.com:LetsGetLow/nofi-service.git
cd nofi-service
```

### 2. Create `.env.local` with the secrets

`.env` is committed and holds **non-secret defaults only**. Every real secret
lives in `.env.local`, which is gitignored and never enters the Docker image.

```bash
cat > .env.local <<'EOF'
POSTGRES_PASSWORD=<choose a password>
JWT_PASSPHRASE=<choose a passphrase>
EOF
```

Generate strong values with `openssl rand -hex 32`.

> **Order matters.** `JWT_PASSPHRASE` must exist *before* you generate the
> keypair in step 5, because the key is encrypted with it. Compose injects
> `.env.local` into the containers when they are **created**, so if you change
> it later you need `docker compose up -d --force-recreate`, not `restart`.

### 3. Firebase credentials — only needed for push

Copy the service-account JSON to `config/firebase/credentials.json` (see
`FIREBASE_CREDENTIALS_FILE` in `.env`). Obtain it from the protected
credentials store — it is gitignored and must never be committed. How such a
file is generated is described under
[Firebase service accounts](https://firebase.google.com/docs/admin/setup); the
client library is documented in
[firebase-php](https://firebase-php.readthedocs.io/8.4.0/cloud-messaging.html).

Email works without it, so skip this step if you only send mail. Once the stack
is up, `/health` reports `"push":"enabled"` when it can see the file and
`"push":"disabled"` when it cannot — which is the quickest way to tell a
credentials file that is not where the application looks from one that is. To
go further and have Firebase confirm the key and the project without delivering
anything: `./docker bin/console nofi:push:check`.

> **Why here, and what if you forget.** A worker reads the file when it builds
> its Firebase client — on the first push it handles, not at boot — and
> `config/firebase` is mounted as a directory, so a file added to a running
> stack is picked up on the next attempt with no restart. What does not recover
> by itself is the push you sent in between: handler construction fails before
> the send is even attempted, so the message retries five times over about two
> minutes and then waits in the failed transport for `messenger:failed:retry`
> (see [Queue](#queue)). Replacing the credentials later is the case that does
> need `docker compose restart messenger-worker`, because a worker keeps the
> client it has already built. With valid credentials a push fails per device
> token instead, with the reason in the worker log.

### 4. Build and start

```bash
docker compose up -d --build
```

This starts PostgreSQL, mailpit, the app, and 8 Messenger workers
(`MESSENGER_NUM_WORKERS` in `.env`).

The first start also fills `vendor/` and `.phpunit.cache/` in your working
tree. Both are gitignored, so a fresh clone does not have them, Docker creates
them empty for the bind mounts, and an empty `vendor/` mounted over the one the
image built is what used to leave `bin/console` throwing `Symfony Runtime is
missing` right here in the setup. The container copies its own `vendor` into
yours instead — 79 MB of it — and hands both directories to your uid so that
Composer and PHPUnit can write there afterwards. There is nothing to run by
hand; the first `up` is simply slower than every later one.

`up -d` returns once the containers are *running*, which can be before that
copy has finished. Wait for `php` to report `(healthy)` in `docker compose ps`
before step 5, or add `--wait` and let Compose wait for you.

### 5. Generate the JWT keypair

```bash
./docker bin/console lexik:jwt:generate-keypair
chmod 600 config/jwt/private.pem
./docker bin/console lexik:jwt:check-config
```

Writes `config/jwt/private.pem` and `public.pem`. Both are gitignored, and they
belong to you rather than root because `./docker` runs as your own uid. The key
paths, the passphrase and the token TTL are described in the
[LexikJWTAuthenticationBundle docs](https://symfony.com/bundles/LexikJWTAuthenticationBundle/current/index.html).

The `chmod` is not decoration. `generate-keypair` writes through Symfony's
`Filesystem::dumpFile()`, which applies `fileperms($existing) ?: 0666 & ~umask()`
— so a new key lands as `0644`, readable by everyone on the machine, and an
existing key simply keeps whatever mode it already had. The bundle has no
setting for this. Because `dumpFile` preserves the existing mode, doing it once
is permanent: later `--overwrite` runs keep `0600`.

### 6. Run the migrations

```bash
./docker bin/console doctrine:migrations:migrate
```

### 7. Create a user

The database ships with **no accounts**. Create your own:

```bash
./docker bin/console nofi:user:create admin --admin
```

You are prompted for the password, so it stays out of your shell history.
Omit `--admin` for a regular user. Only administrators may delete
notifications or see notifications they did not create.

`nofi:user:list` shows what exists — username, roles, token version and the
date the account was created, which comes out of its UUID v7 id. Passwords are
stored as argon2id hashes and are not recoverable, so a forgotten one is
replaced rather than looked up:

```bash
./docker bin/console nofi:user:set-password alice
```

It prompts, so the new password stays out of your shell history, and it cuts
off every token issued against the old one. `nofi:user:revoke-tokens` does that
much on its own, for a leaked token you do not want to change a password over.

---

## Verify the setup

```bash
# 1. Health — checks the database, not just that the web server is up
curl -sk https://localhost:8443/health
#    {"status":"ok","database":"ok","push":"enabled"}
#    "push":"disabled" instead means no credentials file — normal for a
#    deployment that only sends mail, and never an unhealthy container.

# 2. The API rejects unauthenticated callers
curl -sk -o /dev/null -w '%{http_code}\n' https://localhost:8443/api/v1/notifications
#    401

# 3. Log in
TOKEN=$(curl -sk -X POST https://localhost:8443/api/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"username":"admin","password":"<your password>"}' | jq -r .token)

# 4. Send an email notification
curl -sk -X POST https://localhost:8443/api/v1/notifications/send \
  -H 'Content-Type: application/ld+json' -H "Authorization: Bearer $TOKEN" \
  -d '{"channel":"email","sender":"noreply@example.com","subject":"Hello",
       "message":"<h1>It works</h1>","recipients":["ops@example.com"]}'
#    202 Accepted

# 4b. With an attachment and an inline image
curl -sk -X POST https://localhost:8443/api/v1/notifications/send \
  -H 'Content-Type: application/ld+json' -H "Authorization: Bearer $TOKEN" \
  -d '{"channel":"email","sender":"noreply@example.com","subject":"Invoice 4711",
       "message":"<p>See attached</p><img src=\"cid:logo\">",
       "recipients":["kunde@example.com"],
       "attachments":[
         {"filename":"invoice.pdf","contentType":"application/pdf","content":"<base64>"},
         {"filename":"logo.png","contentType":"image/png","content":"<base64>","contentId":"logo"}
       ]}'

# 4c. Send a push notification to a topic. Needs no device at all, which makes
#     it the one push you can try on a fresh checkout — see "Push targets".
curl -sk -X POST https://localhost:8443/api/v1/notifications/send \
  -H 'Content-Type: application/ld+json' -H "Authorization: Bearer $TOKEN" \
  -d '{"channel":"push","title":"Deployment finished",
       "message":"Build 42 is live",
       "topics":["test_notif_local_test_notification_v2"],
       "data":{"deepLink":"/orders/4711","orderId":4711}}'
#    202 Accepted

#     A registration token addresses one device instead. It is the only
#     target that proves real delivery and reports per device:
curl -sk -X POST https://localhost:8443/api/v1/notifications/send \
  -H 'Content-Type: application/ld+json' -H "Authorization: Bearer $TOKEN" \
  -d '{"channel":"push","title":"Deployment finished",
       "message":"Build 42 is live","tokens":["<registration-token>"]}'

# 5. List your notifications
curl -sk -H "Authorization: Bearer $TOKEN" \
  https://localhost:8443/api/v1/notifications
```

A push has no mailpit equivalent: it leaves the machine, so it needs
`config/firebase/credentials.json` to exist by the time a worker picks the
message up. What became of one is visible in the worker log and
in the recipient rows:

```bash
docker compose logs messenger-worker --since 2m | grep -i push
./docker bin/console dbal:run-sql \
  "SELECT status, recipient FROM notification_recipient WHERE notification_id='<uuid>'"
```

`<uuid>` is the tail of the `@id` the send returned. After changing anything
under `src/`, restart the workers — `messenger:consume` is long running and
keeps the classes it has already loaded:

```bash
docker compose restart messenger-worker
```

Mail is captured by mailpit, never sent outside your machine. It is a
development-only service defined in `compose.override.yaml`, so production has
no mailpit and needs a real `MAILER_DSN` — see [Production](#production). Its
web UI is on a dynamic port:

```bash
echo "http://localhost:$(docker compose port mailer 8025 | cut -d: -f2)"
```

`https://localhost:8443` uses a self-signed certificate, hence `-k`.

### Endpoints

Everything lives under `/api/v1/notifications`, and every call needs a JWT.

| Method | Path | What it does |
|---|---|---|
| `POST` | `/api/v1/notifications/send` | Queues a notification. Answers `202` with the `@id` of the created record, not the delivery result — that happens in a worker. |
| `GET` | `/api/v1/notifications` | The notifications you created, 25 per page. An administrator sees everyone's. |
| `GET` | `/api/v1/notifications/{id}` | One notification with its status, channel, payload and timestamps. |
| `POST` | `/api/v1/notifications/{id}/cancel` | Stops a send that has not gone out yet and keeps it as `cancelled`. No body. |
| `DELETE` | `/api/v1/notifications/{id}` | Removes a send that has not started yet, record and all. **Requires `ROLE_ADMIN`.** Anything already being delivered or finished is kept and answered `409`. |

`POST` is the odd one out on purpose: its body is not the resource you read
back — it carries `recipients` or `tokens` and `topics`, attachments and
template data, none of which appear in a `GET` — and the send is asynchronous.
An explicit action says that, where a plain `POST /notifications` would suggest
the response is the thing you created.

**[Notification payloads](PAYLOADS.md) is the field reference for that body**:
which fields each channel requires, which are optional, and which are refused
or quietly ignored when they belong to the other one.

Reading a notification answers with its fields:

```json
{
  "@id": "/api/v1/notifications/01a068d2-43db-7d47-bcc7-b6d0890cfb25",
  "@type": "Notification",
  "id": "01a068d2-43db-7d47-bcc7-b6d0890cfb25",
  "channel": "push",
  "status": "sent",
  "payload": {"title": "Order 4711 shipped", "message": "…", "data": {"deepLink": "/orders/4711"}},
  "createdAt": "2026-09-03T19:50:05+00:00"
}
```

Collections are paged: 25 per page by default, `?page=2` for the next one and
`?itemsPerPage=50` for a different size, up to 100. The response carries the
`first`, `next`, `previous` and `last` links under `view`, so a client can
follow them instead of counting pages:

```bash
curl -sk -H "Authorization: Bearer $TOKEN" \
  "https://localhost:8443/api/v1/notifications?itemsPerPage=3&page=2" | jq .view
```

The sizes are `pagination_*` under `defaults` in
`config/packages/api_platform.yaml`. A caller may only choose the page size
because `pagination_client_items_per_page` is on; without it the parameter is
accepted and ignored, which is exactly how it behaved before.

#### Stopping and changing a send

**`cancel` keeps the record, `DELETE` removes it.** Both only work while
nothing has gone out; afterwards each answers `409`. Cancelling sets the
notification and every still-waiting recipient to `cancelled`, so the record
says what was decided:

```bash
curl -sk -X POST -H "Authorization: Bearer $TOKEN" \
  https://localhost:8443/api/v1/notifications/<uuid>/cancel
```

The queued message is **not** withdrawn — a delayed one cannot be taken out of
the transport reliably. The cancellation takes effect when a worker picks the
message up and finds the notification no longer deliverable; it then skips it
without raising, so nothing lands in the failed transport.

**A notification cannot be edited, and there is no `PATCH`.** The status is
reported, not set. It moves in exactly two places: a worker delivering
(`processing` → `sent` or `failed`) and a cancellation. The states are
`created`, `queued`, `processing`, `sent`, `failed` and `cancelled` — the last
three are final, and nothing leaves them.

Everything else is the request as it was accepted, and it is fixed for one
reason: **the worker never reads the database.** It sends the DTO carried in
the queued message, so rewriting `payload`, `channel` or `scheduledAt` in the
record would describe a send that never happened — before delivery as much as
after it. Changing what goes out means cancelling and sending a new one.

A failed send is the one thing that is retried, and Messenger does it: five
attempts with growing delay, then the message waits in the failed transport for
`./docker bin/console messenger:failed:retry`. Each attempt contacts only the
recipients that failed or never got their turn.

Deleting is a purge, cancelling is not. What already went out stays as the
record of what was sent — the worker refuses to remove it, and since the
deletion is asynchronous the endpoint refuses it too, so the caller learns it
from the `409` rather than from a `204` that changes nothing. Personal data in
`notification_recipient` therefore needs a retention policy rather than manual
deletes.

A field that is null is left out rather than sent as `null`, so an unscheduled
notification has no `scheduledAt` key.

`payload` is what was asked for, `recipients` is who it went to and how each
one fared:

```json
"recipients": [
  {"recipient": "kunde@example.com", "status": "sent", "sentAt": "2026-09-04T07:25:14+00:00"},
  {"recipient": "device-abc", "status": "failed", "sentAt": null},
  {"recipient": "/topics/test_notif_all", "status": "sent", "sentAt": "…"}
]
```

Each target carries its own status, which is why a partly failed send shows up
here rather than only in the worker log, and why a retry contacts none of the
ones already reached. A topic appears prefixed, the way the application
addresses it, so it cannot be mistaken for a device token.

#### The interactive documentation

`https://localhost:8443/api/docs` renders the OpenAPI spec with a "Try it out"
console. **In development it is open**, because a browser sends no
`Authorization` header and a token requirement would make it unopenable.

**On every deployed environment it is closed**, like the rest of `/api`. That is
one parameter, not two rule sets — `access_control` may only be declared in a
single place, so the role for the docs is `%api_docs_role%` in
`config/packages/security.yaml` and only `when@dev` overrides it to
`PUBLIC_ACCESS`.

Two ways to read the spec of a deployed instance:

```bash
# From the application itself, no HTTP and no token involved
./docker bin/console api:openapi:export --yaml > openapi.yaml

# Or over HTTP with a token. Note the format: .json gives a 404
curl -sk -H "Authorization: Bearer $TOKEN" \
  https://<host>/api/docs.jsonopenapi > openapi.json
```

Render it locally with `npx @redocly/cli preview-docs openapi.yaml`, or open it
in an IDE that understands OpenAPI. Do not paste it into an online editor — it
describes an internal API. The **Authorize** button takes a token and the console then sends it with
every request: the document declares the JWT scheme as a requirement for
everything except the login, which is where a token comes from. Paste what
`/api/auth/login` returned, without the `Bearer ` prefix.

To use the console against a *protected* instance — where `/api/docs` itself
needs a token — an extension that adds the `Authorization` header works.

### Tokens and revocation

Login returns a JWT valid for an hour (`token_ttl` in
`config/packages/lexik_jwt_authentication.yaml`). There is no refresh token:
callers hold their credentials and log in again when a token expires, which is
what `load-test` does.

Tokens are stateless, so what an already issued one still respects matters:

| change | effect on a token already issued |
|---|---|
| user deleted | rejected immediately |
| roles changed | applies immediately — roles come from the database, not the token |
| password changed | rejected immediately |
| tokens revoked | rejected immediately |

The last two work through `nofi_user.token_version`, which is stamped into
every token alongside the user's id and compared on each request. The id is
checked too, because a username can be reused by a different person and their
counter starts at 1 again — matching on the counter alone would let the
previous holder's token unlock the new account. `User::setPassword()` moves it on by
itself, so `nofi:user:set-password` cannot forget to cut off existing sessions
and neither can anything else that changes a password. A second command does
the same without changing one — for a lost device or a leaked token:

```bash
./docker bin/console nofi:user:revoke-tokens alice
```

It goes through `UserRepository::revokeTokens()`, which increments the counter
in the database. Doing the arithmetic in PHP instead — read the number, add
one, write it back — would let two revocations racing each other both write the
same value, and would resurrect the tokens of anyone who revoked while the
entity was in memory.

Regenerating the JWT keypair also invalidates every token, but it does so for
everyone at once.

### Attachments

Attachments are sent inline in the request as base64:

```json
"attachments": [
  {"filename": "invoice.pdf", "contentType": "application/pdf", "content": "<base64>"},
  {"filename": "logo.png", "contentType": "image/png", "content": "<base64>", "contentId": "logo"}
]
```

`filename` and `content` are required; `contentType` defaults to
`application/octet-stream`.

**Embedding images.** A `contentId` embeds the file in the HTML body instead of
attaching it, and you reference it there as `<img src="cid:THE-CONTENT-ID">`.
Symfony rewrites that reference to a generated Content-ID when the message is
built, and renames inline parts after the content id — so `filename` is not
what a recipient sees for an embedded image. Symfony only resolves `cid:`
inside an `<img src>` or a `background` attribute; anywhere else the reference
is left dangling.

**Why embedding is validated.** Symfony embeds whatever it is handed without
complaint. An unrenderable format is encoded, marked inline and sent, the API
returns `202`, nothing is logged — and the recipient sees a broken image. A
request carrying a `contentId` is therefore rejected with `422` when:

| rejected when | example |
|---|---|
| the file is not a readable image | a PDF |
| the image is one clients do not render inline | TIFF, BMP |
| `contentType` contradicts the actual bytes | a GIF labelled `image/png` |
| the message never references `cid:<contentId>` | the part would be silently orphaned |

The format is read from the file itself rather than trusted from
`contentType`, so a mislabelled file is caught too. Renderable formats are
`image/png`, `image/jpeg` and `image/gif`
(`AttachmentDto::INLINE_CONTENT_TYPES`) — widen that list deliberately if your
recipients are known to cope with more. Every format remains fine as an
ordinary attachment: just omit `contentId`.

**Limits.** At most 10 files and 5 MB decoded in total
(`SendNotificationDto::MAX_TOTAL_ATTACHMENT_BYTES`). The cap is on the total
rather than per file because the whole message, attachments included, is
serialised into a single `messenger_messages` row. Push notifications cannot
carry attachments.

Both are constants in `src/Dto/SendNotificationDto.php`, not environment
variables: changing them means editing the class — a `docker compose restart
php` in development, where `./src` is mounted, and a rebuilt image in
production.

Behind them sits a second ceiling, `PHP_POST_MAX_SIZE` in `.env`, which is a
real variable. It is the largest body PHP will accept, and it is enforced
before any application code runs: an oversized request is discarded, so the
caller gets a deserialisation error on an empty payload rather than the
violation above. Base64 inflates attachments by a third, so 5 MB of files is
nearly 7 MB on the wire; the 32M default leaves the application limit as the
one callers actually hit, which is the one that explains itself. Raise the
constants far and this needs raising too — along with `PHP_MEMORY_LIMIT`, since
PHP has to hold and decode what it accepted, and `MESSENGER_MEMORY_LIMIT`,
since a worker holds the attachments in memory.

**Storage.** Only metadata — filename, content type, content id, size — is
written to the `notification` table. The bytes travel in the queued message and
are gone once it is consumed, so a notification row never holds a copy of the
file.

### Templates

An email body can come from a Twig template instead of the `message` field.
Templates live in **`templates/mail/`**, registered in the Twig loader as the
`@mail` namespace, and are addressed by bare filename:

```json
{
  "channel": "email",
  "sender": "noreply@example.com",
  "subject": "Order shipped",
  "message": "<p>Tracking below.</p>",
  "recipients": ["kunde@example.com"],
  "template": "welcome",
  "data": {"customerName": "Ada", "orderId": "4711"}
}
```

`"template": "welcome"` resolves to `templates/mail/welcome.html.twig`. Inside
it you get every key of `data` as a variable, plus two reserved ones:

| variable | value |
|---|---|
| `subject` | the notification subject |
| `message` | the `message` field, so a template can wrap it |
| *(each key of `data`)* | as given in the request |

```twig
<h1>{{ subject }}</h1>
{{ message|raw }}
<p>Hello {{ customerName }}, your order {{ orderId }} is on its way.</p>
```

Without a `template` the `message` field is the body, exactly as before.

#### Looping over a list in `data`

A key of `data` is handed to Twig as-is, so a list of objects can be iterated
to build a table. `templates/mail/example.html.twig` does exactly this with an
`items` key:

```json
{
  "channel": "email",
  "sender": "noreply@example.com",
  "subject": "Order 4711 shipped",
  "message": "<p>Your order is on its way. Here is what we sent:</p>",
  "recipients": ["kunde@example.com"],
  "template": "example",
  "data": {
    "customerName": "Ada Lovelace",
    "items": [
      {"name": "Analytical Engine", "quantity": 1, "price": "1.299,00 €"},
      {"name": "Punch card set",    "quantity": 3, "price": "24,50 €"}
    ],
    "total": "1.382,49 €"
  }
}
```

```twig
{% if items is defined and items is iterable %}
    <table cellpadding="8" cellspacing="0" style="border-collapse: collapse;">
        <thead>
            <tr><th>Article</th><th>Quantity</th><th>Price</th></tr>
        </thead>
        <tbody>
            {% for item in items %}
                <tr>
                    <td>{{ item.name|default('—') }}</td>
                    <td>{{ item.quantity|default(1) }}</td>
                    <td>{{ item.price|default('—') }}</td>
                </tr>
            {% endfor %}
        </tbody>
    </table>
{% endif %}
```

**Guard the loop.** Nothing validates that a request carries the keys a
template uses — `data` is only checked for being an object — and a missing key
behaves differently per environment, because Twig's `strict_variables` defaults
to `%kernel.debug%`:

| environment | `{% for item in items %}` with no `items` in `data` |
|---|---|
| `dev`, `test` | `Variable "items" does not exist`, which `renderBody()` turns into a failure for the whole notification: five retries, then the failed transport |
| `prod` | renders as an empty loop, so the mail goes out with an empty table |

`is defined and is iterable` gives both the same behaviour, and `|default()`
does the same for a row that is missing a field.

Use table markup rather than CSS layout, and keep the styles inline: mail
clients ignore `<style>` blocks and flexbox often enough that a table is still
the reliable way to line columns up.

The name must be a bare filename of letters, digits, underscores or hyphens —
no dots, no slashes. That is what keeps a request inside `templates/mail`, so
`"template": "../../config/packages/security"` is rejected rather than
resolved. A template that does not exist is rejected with `422` at request
time, because a typo would otherwise be accepted with `202` and fail later
inside a worker where the caller never sees it. Push notifications cannot use
a template.

The body is rendered once per notification, before any recipient is contacted,
so a broken template fails the whole send instead of delivering to some
recipients and not others.

### Push targets

Broadcast is the normal way to send a push here. A **topic** is named in the
`topics` field, bare and without any prefix, and every device subscribed to it
receives the message — so a whole audience is reached without knowing a single
device token:

```json
{
  "channel": "push",
  "title": "Deployment finished",
  "message": "Build 42 is live",
  "topics": ["test_notif_all"],
  "data": {"deepLink": "/orders/4711"}
}
```

A runnable version is step 4c under [Verify the setup](#verify-the-setup).

`tokens` holds **device registration tokens** and addresses single devices
instead of an audience. A push needs one of the two fields and may carry both:

```json
{"channel": "push", "title": "…", "message": "…",
 "topics": ["test_notif_all"], "tokens": ["fMEr9c…token"]}
```

Neither is required on its own, but a request with both empty is rejected with
`422` — a push with no target is always a mistake.

Each channel names its targets for what they are, and refuses the other one's
field: **email** sends to `recipients`, **push** to `tokens` and `topics`. A
push carrying `recipients`, or an email carrying `tokens`, is a `422` that says
which field to use rather than a silently ignored payload.

The prefix FCM writes as `/topics/NAME` never appears in a request. It exists
only inside the application, which marks a topic that way so one recipient row
per target can be tracked and retried the same for both kinds. Writing it into
`tokens` yourself is refused with a pointer to the `topics` field, so there is
exactly one way to address a topic. Kreait's `Topic::fromValue()` makes the
internal detail worth knowing: it strips only a singular `/topic/`, so passing
`/topics/news` through unchanged would send to a topic called `topics/news`.

A malformed topic name is rejected with `422` at request time — only letters,
digits, hyphens, underscores, dots, tildes and percent signs are allowed, which
is FCM's own rule. A token cannot be checked that way; it stays opaque and
fails per device if the provider refuses it.

> **A topic send always succeeds.** FCM accepts a message for a topic with no
> subscribers at all and reports success, so a green send is not evidence that
> anything was delivered, and `sent` on the recipient row means "FCM took it".
> Only a `tokens` entry gives per-device feedback: an uninstalled app comes
> back as `UNREGISTERED` and marks that recipient `failed`.

### Ports

| Service | URL |
|---|---|
| API (HTTPS) | `https://localhost:8443` |
| API (HTTP) | `http://localhost:8080` |
| OpenAPI docs | `https://localhost:8443/api/docs` — **requires a JWT** |
| PostgreSQL | `localhost:54321` |
| mailpit | `docker compose port mailer 8025` — development overlay only |

The two API ports are the defaults of `HTTP_PORT` and `HTTPS_PORT` in `.env`.
Change them when something else already owns 8080 or 8443 — inside the
container Caddy always serves 80 and 443, so only the host side moves:

```bash
# either in .env, or for one command
HTTP_PORT=9080 HTTPS_PORT=9443 docker compose up -d
```

A changed port mapping needs the container **recreated**, which `up -d` does;
`restart` keeps the old mapping. Every `curl` in this README uses the defaults,
so adjust the port if you moved it. `DEFAULT_URI` in `.env` follows
`HTTPS_PORT` on its own, and `./load-test` takes `NOFI_BASE_URL`.

---

## Day-to-day

### Running commands

`./docker` prefixes any command with `docker compose exec php`:

```bash
./docker bin/console cache:clear
./docker bin/console doctrine:migrations:migrate
./docker composer require some/package
./docker                                     # interactive shell

NOFI_SERVICE=messenger-worker ./docker bin/console messenger:stats
```

Commands run as **your own uid**, not the container's root, so anything written
into the mounted source tree — generated keys, `make:entity` output, an updated
`composer.lock` — stays owned by you. For the rare command that genuinely needs
root inside the container:

```bash
NOFI_USER=root ./docker install-php-extensions redis
```

### Live code changes

`src`, `config`, `templates`, `public`, `migrations` and `bin` are mounted, so
edits reach the container immediately. **FrankenPHP keeps the application in
memory in worker mode**, so a restart is still needed for PHP changes:

```bash
docker compose watch      # restarts automatically on change
```

Without `watch`, use `docker compose restart php messenger-worker` after
editing PHP. `composer.json` and `Dockerfile.frankenphp` changes need a rebuild.

Dependencies are the one thing a rebuild does not settle by itself. `vendor/`
is mounted from your working tree and the image's copy only ever *seeds* it, so
a rebuild refreshes the image and the next start refreshes your `vendor/` from
it only while the two still agree on `composer.lock`. After a `git pull` that
brings someone else's lock file, install into the tree you actually run:

```bash
./docker composer install
```

`./docker composer require some/package` already does both halves at once. When
the container finds a `vendor/` older than `composer.lock` that it cannot
safely refresh, it says so in `docker compose logs php` instead of guessing.

### Tests

```bash
./docker vendor/bin/phpunit
./docker vendor/bin/phpunit --filter FirebasePushService
```

Everything in `tests/` runs without credentials, without network and without a
device: the Firebase client is replaced at the `Messaging` contract, so
`tests/Application/PushDeliveryTest.php` can send a request over HTTP, consume
the queued message the way a worker does, and assert on the exact payload FCM
would receive.

The one exception is `tests/Live`, which really talks to Firebase. It is kept
out of every normal run twice over — the `live` group is excluded in
`phpunit.dist.xml`, and each test skips itself when `NOFI_LIVE_PUSH_TARGET` is
unset — so CI can never break on missing credentials. Nothing about it is
committed: the target comes from the environment and the service account stays
in `config/firebase`, which is gitignored.

```bash
# a topic: accepted by FCM even when nothing is subscribed
NOFI_LIVE_PUSH_TARGET=/topics/test_notif_local_test_notification_v2 \
  ./docker vendor/bin/phpunit --group live

# a device token: the only target that proves real delivery
NOFI_LIVE_PUSH_TARGET=<registration-token> ./docker vendor/bin/phpunit --group live
```

It asserts two different things: that Firebase accepts the message the
application builds, and — via `validate_only`, which delivers nothing — that
the credentials and the project are the right ones. A wrong key or a bad target
fails with the reason Google gave, not a backtrace.

A registration token comes from a debug build of the app. The plugin keeps it
in `EtEnv().firebaseToken` and now also prints it, so it shows up in the Flutter
console as `FCM registration token: ...`.

For load testing, `./load-test` takes its credentials from the environment:

```bash
NOFI_USERNAME=admin NOFI_PASSWORD=<password> NOFI_NUM_MESSAGES=100 ./load-test
```

### Queue

The workers are sized in `.env`: `MESSENGER_NUM_WORKERS` is how many run, and
`MESSENGER_MEMORY_LIMIT` is how much memory one may use before it exits and
Compose restarts it. The limit is a restart threshold rather than a cap — the
point is to shed what a long running process accumulates. A send holds its
attachments in memory, so raise it whenever the attachment limits go up —
`MAX_TOTAL_ATTACHMENT_BYTES` in `src/Dto/SendNotificationDto.php`, and
`PHP_POST_MAX_SIZE` in `.env`.

Underneath it sits a hard ceiling, `PHP_WORKER_MEMORY_LIMIT`: PHP's own
`memory_limit` for a worker process. The two are not alternatives. Messenger
checks its threshold *between* messages and stops the worker cleanly, logging
why, for Compose to start a fresh one; PHP's limit is a fatal error that kills
the process wherever it happens to be, which can be mid-delivery with some
recipients already marked sent. Keep the threshold below the ceiling so the
graceful path always fires first. The gap has to cover what one message adds
after the check passed, which is measurable: a send at the 5 MiB attachment cap
costs about 52 MiB, the payload being resident four times over — raw, base64 in
the queue row, decoded by the worker, and base64 again in the MIME body. The
defaults leave a little under twice that, a 160M threshold under a 256M
ceiling. Raise them together:

```bash
MESSENGER_NUM_WORKERS=2 MESSENGER_MEMORY_LIMIT=512M PHP_WORKER_MEMORY_LIMIT=1G \
  docker compose up -d
```

```bash
./docker bin/console messenger:stats
./docker bin/console messenger:failed:show -vv
./docker bin/console messenger:failed:retry <id>
docker compose logs -f messenger-worker
```

Scheduled sends (`scheduledAt`) sit in the queue until due. Delayed and retried
messages are only picked up every 60 seconds — `check_delayed_interval` in
`config/packages/messenger.yaml` — so a retry is not instant.

---

## Production

`compose.yaml` alone is the production definition; `compose.override.yaml` is
the development overlay and is applied automatically by `docker compose`. To
run the production shape explicitly:

```bash
docker compose -f compose.yaml up -d --build
```

The differences that matter:

- Builds the `prod` image target: no Xdebug, no dev dependencies, an
  authoritative classmap, `APP_ENV=prod`.
- **Runs as `www-data` (uid 33), not root.** FrankenPHP gets
  `CAP_NET_BIND_SERVICE` on the binary so it can still bind ports 80 and 443
  without being root. The development image stays root on purpose, because the
  source tree is bind mounted under your own uid.
- Nothing is mounted from the host except the two secrets the image
  deliberately excludes — `config/jwt` (read-only) and `config/firebase`.
- Source is baked into the image, so a deploy means rebuild, not restart.
- **No mailpit.** The `mailer` service lives only in the development overlay,
  so nothing listens on `smtp://mailer:1025` and you must supply a real SMTP
  relay. The overlay also publishes PostgreSQL on `54321`, which the production
  shape deliberately does not.

> **Mounted secrets must be readable by uid 33.** This is the one thing that
> catches people out. A key written by the development setup is mode `0600`
> owned by your host user, and the production container cannot read it:
>
> ```
> cat: config/jwt/private.pem: Permission denied
> ```
>
> On the deployment host, either give the files to that uid
> (`chown 33:33 config/jwt/*.pem config/firebase/*.json`) or make them
> group-readable for it. The same applies to the `var` volume: a fresh volume
> inherits the image's ownership and is fine, but a volume created by an
> earlier root-running version stays root-owned and must be chowned.

> **Set a real `MAILER_DSN`, or mail vanishes silently.** The development DSN
> `smtp://mailer:1025` comes from `.env.dev`, which Symfony does not load under
> `APP_ENV=prod`, and the committed fallback in `.env` is `MAILER_DSN=null://null`.
> The null transport *accepts* every message and discards it, so a forgotten DSN
> looks exactly like a healthy deployment: the send raises nothing, recipients
> are marked `sent`, the notification reaches `sent`, and no mail is delivered.
> Point it at the relay instead, e.g. `smtp://user:pass@smtp.example.com:587`;
> the DSN formats are listed under
> [Mailer transports](https://symfony.com/doc/current/mailer.html#using-built-in-transports).

Checklist for a real deployment:

1. Supply secrets as **real environment variables**, not `.env.local`:
   `APP_SECRET`, `POSTGRES_PASSWORD`, `JWT_PASSPHRASE`, `DATABASE_URL`.
2. Set `SERVER_NAME` to the public hostname so Caddy serves it and obtains a
   certificate. It defaults to `localhost`.
3. Set `CORS_ALLOW_ORIGIN` to the real front-end origin. The default only
   permits localhost.
4. Set `MAILER_DSN` to the production SMTP relay. Without it, nothing is sent.
5. Set `SENTRY_DSN` to enable error reporting. Empty disables it.
6. Run `doctrine:migrations:migrate` as part of the deploy.
7. Create the first account with `nofi:user:create`.

The container health check calls `/health`, which runs `SELECT 1`, so an
unreachable database marks the container unhealthy. It also reports `push`,
either `enabled` or `disabled`, from whether the credentials file named by
`FIREBASE_CREDENTIALS_FILE` is there — `compose.yaml` mounts `config/firebase`
into the app read-only for that alone, sending being the workers' job.

Push never changes the status code. An email-only deployment is a normal one,
and answering 503 for it would have Compose restart a container that is doing
its job. Nor is `push: enabled` a promise that a send will succeed: it says the file
exists, not that Google accepts the key. Proving that takes a real request, so
it stays out of an endpoint called every ten seconds. Ask for it deliberately
instead:

```bash
./docker bin/console nofi:push:check
```

That sends one message with `validate_only`, so Google checks the credentials,
the project and the message and delivers nothing. It defaults to a topic, which
needs no device and no subscriber, so it works on a fresh deployment; pass a
registration token or `/topics/<name>` to check a specific target. It answers 0
when Firebase accepts and 1 with the reason Google gave — a wrong project, an
expired key or a stale token each read as themselves.

---

## Continuous integration

`.github/workflows/ci.yml` runs on every push — any branch and tags — and on
every pull request. A branch with an open pull request is checked once rather
than twice: the `guard` job drops the push run, because a push to a branch with
an open PR raises both events for the same commit. The pull request run is the
one worth requiring in branch protection — it checks out `refs/pull/N/merge`,
the merge result, so it sees a conflict with the base branch that a push run
cannot.

The first job builds this repository's own `Dockerfile.frankenphp` — the `ci`
target — and pushes it to `ghcr.io`; every later job runs inside that image
through `container:`, so the pipeline and your `./docker` use the same PHP, the
same extensions and the same dependency tree. Nothing describes the environment
twice.

| job | what it protects |
|---|---|
| `phpunit` | the suite, on SQLite, exactly as it runs locally |
| `psalm` | `errorLevel="6"` over `src/`, which was reporting 57 findings the day it was installed |
| `audit` | `composer audit`, blocking. It found 36 production advisories once, including a firewall bypass |
| `lint` | `composer validate`, YAML, Twig, and `lint:container` — which resolves every service, so a wiring mistake no test touches fails here |
| `migrations` | the gap the suite cannot cover: see below |

`tests/Live` never runs there. It is excluded twice over — the `live` group in
`phpunit.dist.xml` and a skip when `NOFI_LIVE_PUSH_TARGET` is unset — so a
pipeline can never depend on Firebase credentials. `nofi:push:check` is the
deliberate way to test those.

The `migrations` job exists because the test suite deliberately cannot do this.
Tests build their schema from the entity metadata
(`tests/Support/InteractsWithDatabase.php`) and run on SQLite, so a migration
that is broken, misordered or drifted from the entities passes all 246 of them.
That job starts a `postgres:16-alpine` service, lets the migrations be the only
thing that builds the schema, and then runs `doctrine:schema:validate` —
including the sync half, so an entity changed without a migration fails the
pipeline.

To run what CI runs, before pushing:

```bash
docker build -f Dockerfile.frankenphp --target ci -t nofi-ci .
docker run --rm -v "$PWD:/w" -w /w nofi-ci \
  sh -lc 'rm -rf vendor && cp -a /app/vendor vendor && mkdir -p var .phpunit.cache && vendor/bin/phpunit'
```

Layer caching uses Buildx's GitHub Actions backend rather than a `:latest` tag
in the registry. That is scoped per branch with a fallback to the default
branch, so a pull request reads the shared cache without being able to poison
it, and no cache image has to exist at all.

Every run still pushes a `ci:<sha>` package of its own, so the `prune-ci-images`
job keeps the **10 most recent** and deletes the rest after a green run on
`main`. The consequence worth knowing: **re-running a single job from an older
run fails**, with `manifest unknown` rather than anything resembling a test
result, because the `ci:<sha>` image that job wants has been pruned. Re-run the
whole workflow instead, so the image is rebuilt first.

The `ci` target exists for one reason: a `container:` job overrides the image's
`ENTRYPOINT`, so `dev-entrypoint.sh` never runs and cannot seed `/app/vendor`
the way it does under Compose. The target puts the vendor there at build time
instead. Jobs copy it into the checkout rather than symlinking, because
`__DIR__` inside `autoload.php` resolves symlinks and the Symfony runtime would
then decide the project is `/app` and test the image's copy of the source.

---

## Configuration reference

Everything configurable, where it lives in this repo, and the upstream docs for
the format. Values come from `.env` and its overrides; the YAML under
`config/packages/` is what consumes them.

| What | Where | Upstream docs |
|---|---|---|
| `.env` precedence, `APP_ENV` | `.env`, `.env.dev`, `.env.local` | [Configuring environments](https://symfony.com/doc/current/configuration.html#config-dot-env), [selecting the environment](https://symfony.com/doc/current/configuration.html#selecting-the-active-environment) |
| Secrets in deployed environments | real environment variables | [Deployment](https://symfony.com/doc/current/deployment.html), [Secrets management](https://symfony.com/doc/current/configuration/secrets.html) |
| `DATABASE_URL`, `POSTGRES_*` | `config/packages/doctrine.yaml` | [Doctrine](https://symfony.com/doc/current/doctrine.html), [DBAL URL format](https://www.doctrine-project.org/projects/doctrine-dbal/en/latest/reference/configuration.html) |
| Migrations | `migrations/`, `config/packages/doctrine_migrations.yaml` | [DoctrineMigrationsBundle](https://symfony.com/bundles/DoctrineMigrationsBundle/current/index.html) |
| `HTTP_PORT`, `HTTPS_PORT` — where the php container is published | `compose.yaml` | [Compose services reference](https://docs.docker.com/reference/compose-file/services/), [interpolation](https://docs.docker.com/reference/compose-file/interpolation/) |
| `PHP_POST_MAX_SIZE`, `PHP_MEMORY_LIMIT`, `PHP_WORKER_MEMORY_LIMIT` — largest request body PHP accepts, and the memory a request and a worker may each use | `Dockerfile.frankenphp` writes `conf.d/zz-runtime.ini`, `compose.yaml` passes the values | [post_max_size](https://www.php.net/manual/en/ini.core.php#ini.post-max-size), [memory_limit](https://www.php.net/manual/en/ini.core.php#ini.memory-limit), [ini variable interpolation](https://www.php.net/manual/en/configuration.file.php) |
| `MESSENGER_NUM_WORKERS`, `MESSENGER_MEMORY_LIMIT` | `compose.yaml` | [Running the worker](https://symfony.com/doc/current/messenger.html#consuming-messages-running-the-worker) |
| `MESSENGER_TRANSPORT_DSN`, retries, failure transport | `config/packages/messenger.yaml` | [Messenger](https://symfony.com/doc/current/messenger.html), [transport DSNs](https://symfony.com/doc/current/messenger.html#messenger-transports-config) |
| `MAILER_DSN` | `.env`, `.env.dev` | [Mailer transports](https://symfony.com/doc/current/mailer.html#using-built-in-transports), [third-party relays](https://symfony.com/doc/current/mailer.html#using-a-3rd-party-transport) |
| `JWT_SECRET_KEY`, `JWT_PUBLIC_KEY`, `JWT_PASSPHRASE`, token TTL | `config/packages/lexik_jwt_authentication.yaml` | [LexikJWTAuthenticationBundle](https://symfony.com/bundles/LexikJWTAuthenticationBundle/current/index.html) |
| `CORS_ALLOW_ORIGIN` | `config/packages/nelmio_cors.yaml` | [NelmioCorsBundle](https://github.com/nelmio/NelmioCorsBundle) |
| Firewalls, password hashing, access control | `config/packages/security.yaml` | [Security](https://symfony.com/doc/current/security.html) |
| API resources, OpenAPI output | `config/packages/api_platform.yaml`, `src/ApiResource/` | [API Platform for Symfony](https://api-platform.com/docs/symfony/) |
| `FIREBASE_CREDENTIALS_FILE` | `config/firebase/credentials.json` | [Service account setup](https://firebase.google.com/docs/admin/setup), [firebase-php messaging](https://firebase-php.readthedocs.io/8.4.0/cloud-messaging.html) |
| Push targets: `topics` and `tokens` | `src/Notification/PushTopic.php`, `src/Dto/SendNotificationDto.php` | [Topic messaging](https://firebase.google.com/docs/cloud-messaging/topic-messaging) |
| `NOFI_LIVE_PUSH_TARGET` — enables the opt-in live push test | environment only, read by `tests/Live/RealPushTest.php` | [Test groups](https://docs.phpunit.de/en/13.0/organizing-tests.html), [attributes](https://docs.phpunit.de/en/13.0/attributes.html) |
| `SENTRY_DSN` | `config/packages/prod/sentry.yaml` | [Sentry for Symfony](https://docs.sentry.io/platforms/php/guides/symfony/) |
| Log channels and handlers | `config/packages/{dev,prod}/monolog.yaml` | [Logging](https://symfony.com/doc/current/logging.html) |
| `SERVER_NAME`, TLS, worker mode | `Caddyfile`, `FRANKENPHP_CONFIG` in `compose.yaml` | [FrankenPHP config](https://frankenphp.dev/docs/config/), [worker mode](https://frankenphp.dev/docs/worker/), [Caddyfile](https://caddyserver.com/docs/caddyfile) |
| How the dev overlay merges over `compose.yaml` | `compose.override.yaml` | [Merging Compose files](https://docs.docker.com/compose/how-tos/multiple-compose-files/merge/) |

---

## Troubleshooting

**`Read-only file system` writing `config/jwt/private.pem`**
You are running the production compose file. Key generation is a development
task; `compose.yaml` mounts `config/jwt` read-only on purpose.

**`Symfony Runtime is missing. Try running "composer require symfony/runtime"`**
`vendor/` exists but is empty, so `bin/console` finds the directory and not the
autoloader. The development container fills an empty `vendor/` from the image
when it starts, so seeing this means it was emptied afterwards — a `rm -rf
vendor`, a `git clean -xdf` — or the container predates that seeding. Let it do
the work again, with the stack down so that nothing still holds the old mount:
`docker compose down`, then `rm -rf vendor`, then `docker compose up -d --build`.

**`Class "..." not found`, or a package you installed is missing**
Your `vendor/` is older than `composer.lock`. Both services mount the same one
from your working tree, so this hits the app and the workers alike, and neither
a rebuild nor a recreate refreshes it — they refresh only the seed copy inside
the image. Run `./docker composer install`; `docker compose logs php` has
usually warned about it already.

**`Permission denied` writing into `vendor/` or `.phpunit.cache/`**
The container takes ownership of both when it starts, which it cannot do where
root has no say over your working tree — NFS with root squash, or a daemon
running under `userns-remap`. Take them yourself:
`sudo chown -R "$(id -u):$(id -g)" vendor .phpunit.cache`.

**An edit to `.env`, `composer.json`, `phpunit.dist.xml` or `psalm.xml` has no effect**
Those are mounted into the container as *single files*, and a bind mount
follows the inode rather than the path. Anything that replaces the file instead
of writing through it — `sed -i`, `git checkout`, `git stash`, most editors on
save — leaves the container reading the old inode, which no longer exists on
your side. Nothing warns you; the file simply appears unchanged inside, or
disappears. Re-bind it: `docker compose up -d --force-recreate php`. Directory
mounts such as `./src` and `./config` are immune, which is why this only ever
bites the handful of files above.

**`Unable to write in the "cache" directory (/app/var/cache/test)`**
Every test errors in `bootKernel()`, usually right after a source change made
the cache stale. `var/cache/test` is owned by root and not world writable,
while `./docker` runs PHPUnit as you. The container hands such a directory over
when it starts, so `docker compose restart php` normally clears it; it lives in
the `var_data` volume rather than your working tree, so there is nothing to
`chown` on the host.

**PHP changes have no effect**
FrankenPHP worker mode holds the booted application in memory. Use
`docker compose watch`, or restart the service.

**Config changes have no effect**
Clear the cache: `./docker bin/console cache:clear`.

**`nofi:user:create` says the user already exists**
Check with `./docker bin/console nofi:user:list`. Pending migrations can also
leave older accounts in place — `./docker bin/console doctrine:migrations:list`.

**Is push set up at all?**
`./docker bin/console nofi:push:check` asks Firebase, delivering nothing. It
separates the two failures that look alike in a worker log: credentials Google
will not accept, and credentials that are fine with a target that is not.

**Push fails for every token**
Read the reason in the worker log:
`docker compose logs messenger-worker | grep "Push notification"`.
`The registration token is not a valid FCM registration token` means Firebase
was reached and rejected the token — credentials and wiring are fine.

**`env_file` errors on startup**
Compose is older than v2.24. Upgrade, or create an empty `.env.local`.

---

## Layout

```
src/
  ApiResource/        API Platform resource definition
  Application/        Application services (send, delete)
  Command/            Console commands (nofi:user:*, nofi:push:check)
  Controller/         Health endpoint
  Dto/                Request payload and its validation
  Entity/             Doctrine entities
  Message/            Messenger messages
  MessageHandler/     Messenger handlers
  Notification/       Domain: status machine, payloads, state providers
  Repository/         Doctrine repositories
  Service/            Email and push delivery
config/
  jwt/                JWT keypair          (contents gitignored, mounted)
  firebase/           Service account JSON (contents gitignored, mounted)
migrations/           Doctrine migrations
tests/Unit/           Unit tests
```
