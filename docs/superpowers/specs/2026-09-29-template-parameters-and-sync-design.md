# Template Parameters, Media Headers and Sync — Design

Status: proposed. Covers issues #45, #46, #47, #48 and #49.

## Goal

Make every kind of template Meta supports sendable through the builder, record what was
sent in Meta's own shape, keep template media headers durable, and make template sync
correct for WABAs with more than one page of templates.

Guiding rule: **Meta's structure is the source of truth.** The builder is a thin, explicit
mirror of Meta's send payload. It does not invent its own parameter format, and it does
not look up synced template definitions when sending.

## Delivery

Two independent PRs, each released as a minor:

- **PR A — sync (#49).** Client pagination, explicit fields, failure handling,
  `parameter_format` column.
- **PR B — builder (#45, #46, #47, #48).** `TemplateComponents`, new builder API, Meta-shaped
  record, file headers for `send()` and `queue()`.

PR A goes first. It is small and PR B does not depend on it.

## Out of scope

- Deriving button `sub_type`/`index` or parameter names from the synced template
  definition. Apps that want that can read `WhatsAppTemplate::components` themselves.
- File (upload) headers for interactive messages. `headerImage()` keeps setting the
  interactive header from a string; a file there throws.
- Downloading a caller's header URL to keep a local copy. Callers that want a durable copy
  pass a file.
- Backfilling `template_parameters` on existing rows. Old rows keep their shape (see
  Compatibility).

---

## PR A — Template sync (#49)

### Problems

1. `WhatsAppClient::getTemplates()` makes one request and never follows `paging.next`.
2. It sends no `fields`, so the response shape depends on Meta's defaults.
3. It never checks `$response->successful()`. An error returns `[]`, and
   `WhatsAppSyncTemplates` then marks **every** local template `DISABLED`.
4. Templates from pages after the first are marked `DISABLED` on every sync.

### Client

`getTemplates(?string $status = null): array` keeps its signature.

- Query: `fields=id,name,language,status,category,components,parameter_format,rejected_reason`,
  `limit=100`, plus `status` when given.
- Follows `paging.next` until it is absent, collecting every page's `data`.
- Loop guard: stops if a `paging.next` URL repeats.
- Any non-2xx page throws `TemplateSyncException::fetchFailed(int $status, array $error)`.
  The method returns either the complete list or throws; never a partial list.

`getTemplate(string $name)` keeps working on top of it unchanged.

New `src/Exceptions/TemplateSyncException.php` extends `WhatsAppException`.

### Sync job

`WhatsAppSyncTemplates::handle()`:

1. `$templates = $client->getTemplates()`. If this throws, the job fails and retries through
   its existing `$tries`/`$backoff`. Nothing is upserted or disabled.
2. Upserts every template, now also writing `parameter_format` (`null` when Meta omits it).
3. Only then marks local templates whose `template_id` is missing from the full list as
   `DISABLED`.

### Schema

Migration `2024_01_01_000013_add_parameter_format_to_whatsapp_templates.php`: a nullable
`string('parameter_format')` on `whatsapp_templates`. It is additive; existing rows are
`NULL` until the next sync.

`WhatsAppTemplate`:

- `parameter_format` in `$fillable` and the `@property` docblock.
- `PARAMETER_FORMAT_NAMED = 'NAMED'`, `PARAMETER_FORMAT_POSITIONAL = 'POSITIONAL'`.
- `usesNamedParameters(): bool`.

---

## PR B — Template builder (#45, #46, #47, #48)

### `Support/TemplateComponents`

A new class that owns the template parameter model. It has no HTTP or database
dependency, so it can be unit-tested on its own.

State (all Meta-shaped):

```php
?array $header;          // one Meta header parameter
array  $body;            // Meta body parameters, in order
array  $buttons;         // Meta button components keyed by index
array  $extra;           // raw components of any other type (carousel, limited_time_offer, ...)
?SplFileInfo $headerFile; // header file waiting to be uploaded
```

Writers:

| Method | Produces |
|---|---|
| `headerText(string $text, ?string $name = null)` | `{type:'text', text, parameter_name?}` |
| `headerMedia(string $type, SplFileInfo\|string $source, ?string $filename = null)` | `{type, <type>: {id\|link, filename?}}` (rules below) |
| `body(array $params)` | list → `{type:'text', text}` each; string keys → `{type:'text', parameter_name, text}` each |
| `urlButton(int $index, string $suffix)` | `{type:'button', sub_type:'url', index, parameters:[{type:'text', text}]}` |
| `copyCodeButton(int $index, string $code)` | `{..., sub_type:'copy_code', parameters:[{type:'coupon_code', coupon_code}]}` |
| `quickReplyButton(int $index, string $payload)` | `{..., sub_type:'quick_reply', parameters:[{type:'payload', payload}]}` |
| `raw(array $components)` | splits Meta components into `header` / `body` / `buttons` / `extra` |

Header media source rules:

- `SplFileInfo` (an `UploadedFile`, an `Illuminate\Http\File`, or any file object): stored in
  `$headerFile`, so the media object has no `id` or `link` until upload. For a document,
  `filename` defaults to the file's original name.
- A string of digits only (`/^\d+$/`): `{id}`.
- Any other string: `{link}`.

Validation (`InvalidArgumentException`, thrown before any request):

- `body()` with a mix of string and integer keys.
- A second write to a button index that is already set, whether it comes from a fluent
  method, `buttonParameters()` or `raw()`.
- `headerMedia()` with a type other than `image`, `video` or `document`.

Precedence: `raw()` and fluent writers write into the same slots. For the header and the
body, a later write replaces the earlier one, matching how the header setters already
behave. Buttons are the exception above: a duplicate index throws, because silently
replacing a button is what caused #46.

Readers:

- `toComponents(): array` returns header, body, buttons (sorted by index), then `extra`, as
  Meta components. Empty slots are left out.
- `toRecord(): array` returns
  `['header' => ?array, 'body' => array, 'buttons' => list]` from the same slots. `extra` is
  not included; it lives in `content.components`.
- `headerFile(): ?SplFileInfo` and `resolveHeaderMedia(string $mediaId): void`. The latter
  sets the uploaded `id` on the header media object.

### `MessageBuilder` API

Template state moves into a `TemplateComponents` instance. Public methods:

- `headerText(string $text, ?string $name = null)`
- `headerImage(SplFileInfo|string $source)`, `headerVideo(SplFileInfo|string $source)`,
  `headerDocument(SplFileInfo|string $source, ?string $filename = null)`
- `bodyParameters(array $params)`: list for positional, string keys for named.
- `urlButton()`, `copyCodeButton()`, `quickReplyButton()`: new.
- `buttonParameters(array $params)`: kept; calls `urlButton($key, $value)` for each entry.
  Documented: keys are button indexes, so a plain list targets buttons 0, 1, ...
- `components(array $metaComponents)`: new raw passthrough.

`headerImage()` still sets `interactiveHeader` for interactive messages. When it receives an
`SplFileInfo` and the message type is interactive, the interactive send path throws
`InvalidArgumentException`.

`buildTemplateComponents()` becomes `$this->template->toComponents()`, and
`buildTemplateParametersForRecord()` becomes `$this->template->toRecord()`.

### Recorded row

- `content` = `{name, language, components}`, where `components` is exactly what was sent.
  The shape is unchanged.
- `template_parameters` = `toRecord()`:
  ```php
  [
      'header'  => ['type' => 'document', 'document' => ['id' => '…', 'filename' => 'Orçamento.pdf']],
      'body'    => [['type' => 'text', 'parameter_name' => 'customer_name', 'text' => 'Marina']],
      'buttons' => [['type' => 'button', 'sub_type' => 'copy_code', 'index' => 0,
                     'parameters' => [['type' => 'coupon_code', 'coupon_code' => 'CORBI10']]]],
  ]
  ```
- `media_id` is set whenever the header goes out as `{id}`, whether uploaded or passed in.

### Header files: `send()`

`uploadMediaFile()` and `storeMediaFile()` work on "the pending file", which is
`mediaFile ?? template->headerFile()`. For a header file they:

1. Upload the contents to Meta and get the media id.
2. Store the local copy through `MediaService` and set `local_media_disk`,
   `local_media_path`, `media_mime_type`, `media_size`, and
   `media_status = downloaded`.
3. Call `template->resolveHeaderMedia($id)` and set `media_id`.

This happens before `executeApiCall()`, so the payload, `content` and `template_parameters`
all carry the id.

### Header files: `queue()`

1. The builder stores the local copy only. The header is recorded without an id, e.g.
   `{type:'document', document:{filename:'Orçamento.pdf'}}`.
2. `WhatsAppSendMessage::uploadStoredMedia()` changes its guard from `isMedia()` to
   `isMedia() || type === 'template'`, still requiring a local file and no `media_id`.
3. For a template, after uploading it writes `id` into the header parameter in both
   `content.components` and `template_parameters.header`, sets `media_id`, then sends.
4. A retry after a successful upload sees `media_id` set and does not upload again.

### `TemplateBuilder`

`Support/TemplateBuilder` is not returned by the manager or the facade; only one test
creates it directly. It delegates to `TemplateComponents` (so it gets the same fixes and
record shape) and is marked `@deprecated` in favor of
`WhatsApp::phone()->to()->template()`. Removal is left for a future major.

---

## Compatibility

- **Sending.** Existing calls (`bodyParameters` with a list, `buttonParameters`,
  `headerDocument($url, ...)`, `headerText`) produce the same payload as before. The only
  behavior change is that a digits-only string header now goes out as `{id}`; it used to go
  out as `{link}` and Meta rejected it.
- **Stored data.** No existing row is rewritten, and no data migration is needed. The
  package itself never reads `template_parameters`. The queued job sends from
  `content.components`, whose shape does not change, so messages queued before the deploy
  and sent after it are unaffected.
- **`template_parameters` shape.** New rows use Meta's shape and old rows keep the old
  shape (`header` as a string, `body` as a list of strings, `buttons` as
  `[index => text]`). Apps that read this column must handle both. For Corbi, its reader
  change ships in the same deploy as the package upgrade.
- **Versioning.** Each PR is a minor. The changelog lists the `template_parameters` shape
  change as something consumers must adapt to.

## Testing

Pest with `Http::fake`.

PR A:

- Two pages are both synced, and a template on page 2 is not disabled.
- A 500 on page 2 throws, and nothing is upserted or disabled.
- `parameter_format` is stored.
- A repeated `paging.next` stops the loop.

PR B, unit (`TemplateComponents`):

- Positional and named body parameters; mixed keys throw.
- Each button type; a duplicate index throws; buttons come out sorted by index.
- Digit id vs link; header text with and without a name.
- `raw()` round-trips through `toComponents()`, and unknown types go to `extra`.
- A later header or body write replaces the earlier one; a duplicate button index from
  `raw()` or a fluent method throws.

PR B, feature:

- A header file via `send()` goes out as `{id}` and records `local_media_*` and `media_id`.
- A header file via `queue()`: the job uploads it, patches both `content.components` and
  `template_parameters.header`, and does not upload again on retry.
- `template_parameters` matches the components that were sent.
- Existing calls produce the same payload as before (regression).
- A file on an interactive header throws.
- `TemplateBuilder` produces the same record shape.

## Release follow-up

README: document the new methods, that `buttonParameters()` keys are indexes, the record
shape, and `parameter_format`.

Corbi handoff after release:

- A `template_parameters` normalizer that turns the old shape into Meta's.
- Enable NAMED templates in the send-template modal (`usesNamedParameters()`).
- Replace signed URLs with file uploads for header media.
