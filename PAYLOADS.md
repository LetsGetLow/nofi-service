# Notification payloads

Every notification is sent by posting one JSON body to one endpoint:

```
POST /api/v1/notifications/send
```

`channel` decides which payload applies. Everything else in the body is read
against that choice: an email is addressed with `sender`, `subject` and
`recipients`, a push with `title`, `tokens` and `topics`. The answer is `202`
with the record that was created, not with the delivery result — see
[Endpoints](README.md#endpoints).

This page is the field reference. The reasoning behind attachments, templates
and push targets lives in the README sections linked from each section below.

## Which fields each channel needs

| field | type | email | push |
|---|---|---|---|
| `channel` | string | **required** — `"email"` | **required** — `"push"` |
| `message` | string | **required** | **required** |
| `sender` | string | **required** | ignored |
| `subject` | string | **required** | ignored |
| `recipients` | string[] | **required**, at least one | rejected |
| `title` | string | ignored | **required** |
| `tokens` | string[] | rejected | **one of the two required** |
| `topics` | string[] | rejected | **one of the two required** |
| `icon` | string | ignored | optional |
| `template` | string | optional | rejected |
| `attachments` | object[] | optional | rejected |
| `data` | object | optional | optional |
| `scheduledAt` | string | optional | optional |

**required** — the request is refused with `422` when it is missing or empty.
**optional** — leave it out and the send goes ahead without it.
**ignored** — accepted and then dropped; it never reaches the delivery, and it
does not appear in the record you read back.
**rejected** — a `422` that names the field to use instead. See
[Ignored is not the same as rejected](#ignored-is-not-the-same-as-rejected),
which is the one part of this worth reading twice.

## Email

The smallest request that sends an email:

```json
{
  "channel": "email",
  "sender": "noreply@example.com",
  "subject": "Order 4711 shipped",
  "message": "<p>Your parcel is on its way.</p>",
  "recipients": ["kunde@example.com"]
}
```

Everything an email may carry:

```json
{
  "channel": "email",
  "sender": "noreply@example.com",
  "subject": "Order 4711 shipped",
  "message": "<p>Your parcel is on its way.</p>",
  "recipients": ["kunde@example.com", "zweite@example.com"],
  "template": "welcome",
  "data": {"customerName": "Ada", "orderId": 4711},
  "attachments": [
    {"filename": "invoice.pdf", "contentType": "application/pdf", "content": "<base64>"}
  ],
  "scheduledAt": "2026-12-01T10:00:00+00:00"
}
```

| field | rule |
|---|---|
| `sender` | Must be a valid email address. |
| `subject` | Any non-empty string. Also handed to a template as the `subject` variable. |
| `message` | HTML, and the body of the mail. With a `template` it becomes the `message` variable inside it instead. |
| `recipients` | At least one, and **every entry must be a valid email address** — one bad entry refuses the whole request, naming its index. |
| `template` | Bare filename of a Twig template in `templates/mail`: `"welcome"` resolves to `welcome.html.twig`. Letters, digits, underscores and hyphens only — no dots or slashes, which is what keeps a request inside that directory. A template that does not exist is a `422`. Left out, `message` is the body. See [Templates](README.md#templates). |
| `attachments` | At most 10 files, 5 MB decoded in total. See [The attachment object](#the-attachment-object). |
| `data` | Every key becomes a Twig variable when a `template` is named. Without one it is stored but changes nothing about the mail. |

## Push

The smallest request that sends a push, addressing an audience by topic:

```json
{
  "channel": "push",
  "title": "Order 4711 shipped",
  "message": "Your parcel is on its way.",
  "topics": ["test_notif_all"]
}
```

Everything a push may carry:

```json
{
  "channel": "push",
  "title": "Order 4711 shipped",
  "message": "Your parcel is on its way.",
  "topics": ["test_notif_all"],
  "tokens": ["fMEr9c1TQ_example_device_token"],
  "icon": "https://example.com/icon.png",
  "data": {"deepLink": "/orders/4711", "orderId": 4711},
  "scheduledAt": "2026-12-01T10:00:00+00:00"
}
```

| field | rule |
|---|---|
| `title` | The headline the device shows. |
| `message` | The text under the title. Plain text, not HTML. |
| `topics` | FCM topic names, **bare**: `"test_notif_all"`, never `"/topics/test_notif_all"`. Only letters, digits, hyphens, underscores, dots, tildes and percent signs — FCM's own rule, checked at request time so a malformed name is a `422` rather than a failure inside a worker. Writing the `/topics/` prefix into `tokens` is refused with a pointer to this field. |
| `tokens` | Device registration tokens, addressing single devices. A token is opaque and cannot be checked, so a bad one fails per device at delivery. |
| `topics` / `tokens` | **A push needs at least one of the two** and may carry both. Both empty is a `422` — a push with no target is always a mistake. See [Push targets](README.md#push-targets). |
| `icon` | Must be a valid URL with a TLD. |
| `data` | Delivered to the device for client-side handling such as deep links, and **not shown to the user**. FCM carries strings only, so any other value is JSON encoded and the client decodes it — `4711` arrives as `"4711"`, an object as its JSON text. |

> **A topic send always reports success.** FCM accepts a message for a topic
> with no subscribers at all, so `sent` on that recipient row means "FCM took
> it", not that a device saw it. Only a `tokens` entry gives per-device
> feedback.

## The attachment object

Email only. Each entry of `attachments` is:

```json
{"filename": "logo.png", "contentType": "image/png", "content": "<base64>", "contentId": "logo"}
```

| field | required | rule |
|---|---|---|
| `filename` | **yes** | Up to 255 characters, no path separators or control characters — it is the name the recipient saves. |
| `content` | **yes** | The file, base64 encoded. |
| `contentType` | no | A media type such as `application/pdf`. Defaults to `application/octet-stream`. |
| `contentId` | no | Embeds the file in the HTML body instead of attaching it, referenced there as `<img src="cid:logo">`. |

**Limits.** At most 10 files, and 5 MB decoded across all of them together —
the cap is on the total because the whole message travels in a single queue
row.

**`contentId` is strict on purpose**, because Symfony embeds whatever it is
handed and a mistake reaches the recipient as a broken image with nothing
logged. A request carrying one is refused when the file is not a readable
image, when it is a format clients do not render inline (only PNG, JPEG and
GIF are), when `contentType` contradicts the actual bytes, or when the message
never references `cid:<contentId>`. Omit `contentId` and any format is fine as
an ordinary attachment. See [Attachments](README.md#attachments).

## Fields both channels share

| field | rule |
|---|---|
| `message` | Required for both, and the only required field they have in common. |
| `data` | Free key/value object. Read as Twig variables by an email template, delivered to the device by a push. |
| `scheduledAt` | When to deliver. Left out, the send goes as soon as a worker picks it up. |

`scheduledAt` is parsed by PHP, so it takes ISO 8601 —
`"2026-12-01T10:00:00+00:00"` — but also relative expressions such as
`"tomorrow"`, which resolves to midnight of the next day. Something PHP cannot
parse at all, such as `"not-a-date"`, is a `400`.

**The delay is stamped onto the queued message and cannot be moved
afterwards.** There is no `PATCH`; changing a scheduled send means cancelling
it and sending a new one. See
[Stopping and changing a send](README.md#stopping-and-changing-a-send).

## Ignored is not the same as rejected

A field belonging to the other channel is treated one of two ways, and the
difference matters when a request behaves unexpectedly.

**Rejected** fields are the ones that would change who gets the notification.
Sending them is refused with a `422` naming the field to use instead, so a
misaddressed notification can never go out silently:

| request | answer |
|---|---|
| email carrying `tokens` or `topics` | `422` — email addresses `recipients` |
| push carrying `recipients` | `422` — push addresses `tokens` and `topics` |
| push carrying `attachments` or `template` | `422` — neither exists for push |

**Ignored** fields are the ones that only affect presentation. They are
accepted, dropped, and never appear in the record you read back:

| request | answer |
|---|---|
| email carrying `title` or `icon` | `202`, and both are discarded |
| push carrying `sender` or `subject` | `202`, and both are discarded |

There are two traps in that second table.

**An ignored field is still validated for format.** `icon` must be a valid URL
and `sender` a valid email address whatever the channel, so an email carrying a
malformed `icon` is refused with `422` over a value it was going to throw away.

**An unknown field is accepted in silence.** A body carrying `"bogusField"`, or
`"titel"` for `"title"`, answers `202` — the misspelling is dropped, and only
the resulting absence is reported. If a field seems to have no effect, check
its spelling against the tables above before anything else.

## When a request is refused

| status | meaning |
|---|---|
| `400` | The body could not be read into the payload at all: an unparsable `scheduledAt`, or a `channel` that is not `email` or `push`. |
| `422` | The body was understood and a rule rejected it. Everything else on this page. |

A `422` names every field it objected to, indexed where the field is a list:

```json
{
  "status": 422,
  "violations": [
    {"propertyPath": "tokens", "message": "Email notifications address \"recipients\", not \"tokens\""}
  ],
  "detail": "tokens: Email notifications address \"recipients\", not \"tokens\""
}
```

`propertyPath` is the field, so `recipients[0]` is the first recipient and
`attachments[2].contentId` the content id of the third attachment.

The rules themselves live in `src/Dto/SendNotificationDto.php` and
`src/Dto/AttachmentDto.php`, and `https://localhost:8443/api/docs` renders the
same fields with a "Try it out" console.
