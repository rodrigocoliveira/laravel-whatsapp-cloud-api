# Template Parameters, Media Headers and Sync Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix template sync pagination and failure handling (#49), and let the builder send named parameters, every button type, and uploaded header media, recording what it sent in Meta's own shape (#45–#48).

**Architecture:** PR A changes `WhatsAppClient::getTemplates()` and `WhatsAppSyncTemplates` and adds a `parameter_format` column. PR B adds `Support/TemplateComponents`, a class with no HTTP or database dependency that keeps the header, body and buttons as Meta objects. `MessageBuilder`, `TemplateBuilder` and the queued `WhatsAppSendMessage` job all read the payload and the recorded row from it.

**Tech Stack:** PHP 8.2, Laravel 11–13 (illuminate), Pest 3 + Orchestra Testbench, `Http::fake`, `Storage::fake`, Pint, PHPStan.

**Spec:** `docs/superpowers/specs/2026-09-29-template-parameters-and-sync-design.md`

## Global Constraints

- Meta's structure is the source of truth: the payload and `template_parameters` use Meta's own component and parameter objects; no invented keys.
- The builder never reads `whatsapp_templates` when sending.
- A string header source matching `/^\d+$/` is a media id (`{id}`); any other string is a link (`{link}`).
- Body params: a list is positional; string keys add `parameter_name`; a mix throws `InvalidArgumentException`.
- A duplicate button index throws `InvalidArgumentException`, whether it comes from a fluent method, `buttonParameters()` or `components()`. For the header and the body, a later write replaces the earlier one.
- Existing calls (`bodyParameters` with a list, `buttonParameters`, `headerDocument($url, ...)`, `headerText`) must produce the same payload as today.
- No existing row is rewritten; no data migration. Never change the timestamps of shipped migration files; new migrations go into `database/migrations/` as `2024_01_01_0000NN_*` and are auto-loaded from vendor.
- Commits end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Every task ends with `./vendor/bin/pest`, `./vendor/bin/pint --test` and `./vendor/bin/phpstan analyse` green.

## Review Focus

1. **Numeric-looking values in body/button params** (e.g. an order number `42` passed as an int): they must go out as the string `"42"`, because Meta's `text` must be a string. Pinned in Task 3.
2. **A header set twice with different kinds** (a file, then a URL): the second call must clear the pending file, so nothing gets uploaded for a header that is no longer a file. Pinned in Task 3.
3. **`buttonParameters()` with a plain list** (`['PED-1']`) keeps targeting button 0, as it does today. Pinned in Task 4.
4. **A queued template whose job retries after the upload succeeded but the send failed**: it must not upload again, and the resend must carry the id. Pinned in Task 6.
5. **Meta returns a page with no `data` key** (e.g. an empty WABA): sync must treat it as zero templates, not crash. Pinned in Task 1.

---

## Branches

- **PR A (Tasks 1–2):** the current branch `claude/laravel-whatsapp-cloud-api-issues-6ee97c`, which also carries the spec and this plan.
- **PR B (Tasks 3–8):** a new branch `claude/template-parameters`, created from `main` after PR A is opened: `git switch -c claude/template-parameters origin/main`. PR B does not depend on PR A.

---

### Task 1: Paginated, failure-safe `getTemplates()`

**Files:**
- Create: `src/Exceptions/TemplateSyncException.php`
- Modify: `src/Client/WhatsAppClient.php:508-521` (`getTemplates`)
- Modify: `src/Client/WhatsAppClientInterface.php:183` (docblock `@throws`)
- Test: `tests/Feature/TemplateSyncTest.php` (new)

**Interfaces:**
- Produces: `TemplateSyncException::fetchFailed(int $status, ?array $error = null): self`; `WhatsAppClient::getTemplates(?string $status = null): array`, which returns every template across all pages or throws `TemplateSyncException`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/TemplateSyncTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Multek\LaravelWhatsAppCloud\Client\WhatsAppClient;
use Multek\LaravelWhatsAppCloud\Exceptions\TemplateSyncException;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppPhone;

beforeEach(function () {
    $this->phone = WhatsAppPhone::create([
        'key' => 'test',
        'phone_id' => 'test_phone_id',
        'phone_number' => '+15551234567',
        'business_account_id' => 'test_waba',
        'is_active' => true,
    ]);
});

/**
 * @return array<string, mixed>
 */
function metaTemplate(string $id, string $name, string $format = 'POSITIONAL'): array
{
    return [
        'id' => $id,
        'name' => $name,
        'language' => 'pt_BR',
        'status' => 'APPROVED',
        'category' => 'UTILITY',
        'parameter_format' => $format,
        'components' => [['type' => 'BODY', 'text' => 'Olá']],
    ];
}

/**
 * Fake the templates edge: each key is the `after` cursor (null = first page).
 *
 * @param  array<string, \GuzzleHttp\Promise\PromiseInterface>  $pages
 */
function fakeTemplatePages(array $pages): void
{
    Http::fake(function (Request $request) use ($pages) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $pages[$query['after'] ?? 'first'];
    });
}

function nextPage(string $cursor): string
{
    return "https://graph.facebook.com/v24.0/test_waba/message_templates?after={$cursor}";
}

it('follows paging.next until the last page', function () {
    fakeTemplatePages([
        'first' => Http::response(['data' => [metaTemplate('1', 'welcome')], 'paging' => ['next' => nextPage('p2')]]),
        'p2' => Http::response(['data' => [metaTemplate('2', 'order_update')]]),
    ]);

    $templates = (new WhatsAppClient($this->phone))->getTemplates();

    expect(collect($templates)->pluck('name')->all())->toBe(['welcome', 'order_update']);
    Http::assertSentCount(2);
});

it('asks Meta for explicit fields and a page size', function () {
    fakeTemplatePages(['first' => Http::response(['data' => []])]);

    (new WhatsAppClient($this->phone))->getTemplates('APPROVED');

    Http::assertSent(function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query['fields'] === 'id,name,language,status,category,components,parameter_format,rejected_reason'
            && $query['limit'] === '100'
            && $query['status'] === 'APPROVED';
    });
});

it('throws when any page fails instead of returning a partial list', function () {
    fakeTemplatePages([
        'first' => Http::response(['data' => [metaTemplate('1', 'welcome')], 'paging' => ['next' => nextPage('p2')]]),
        'p2' => Http::response(['error' => ['code' => 1, 'message' => 'boom']], 500),
    ]);

    (new WhatsAppClient($this->phone))->getTemplates();
})->throws(TemplateSyncException::class, 'HTTP 500');

it('stops when Meta repeats the same next page', function () {
    fakeTemplatePages([
        'first' => Http::response(['data' => [metaTemplate('1', 'welcome')], 'paging' => ['next' => nextPage('p2')]]),
        'p2' => Http::response(['data' => [metaTemplate('2', 'order_update')], 'paging' => ['next' => nextPage('p2')]]),
    ]);

    $templates = (new WhatsAppClient($this->phone))->getTemplates();

    expect($templates)->toHaveCount(2);
    Http::assertSentCount(2);
});

it('treats a page without data as no templates', function () {
    fakeTemplatePages(['first' => Http::response([])]);

    expect((new WhatsAppClient($this->phone))->getTemplates())->toBe([]);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/TemplateSyncTest.php`
Expected: FAIL. `TemplateSyncException` doesn't exist, only one request is sent, and there's no `fields` query.

- [ ] **Step 3: Create the exception**

`src/Exceptions/TemplateSyncException.php`:

```php
<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Exceptions;

class TemplateSyncException extends WhatsAppException
{
    /**
     * @param  array<string, mixed>|null  $error  Meta's `error` object from the rejected response
     */
    public static function fetchFailed(int $status, ?array $error = null): self
    {
        $reason = $error['message'] ?? 'no error details returned';

        if (isset($error['code'])) {
            $reason = "[{$error['code']}] {$reason}";
        }

        return new self(
            "Failed to fetch message templates from WhatsApp (HTTP {$status}): {$reason}",
            (int) ($error['code'] ?? 0),
            null,
            $error
        );
    }
}
```

- [ ] **Step 4: Rewrite `getTemplates()`**

Add `use Multek\LaravelWhatsAppCloud\Exceptions\TemplateSyncException;` to `src/Client/WhatsAppClient.php` and replace the method:

```php
    /**
     * Get every template for the business account, following Meta's pagination.
     *
     * @throws TemplateSyncException when any page fails; a partial list is never returned
     */
    public function getTemplates(?string $status = null): array
    {
        $query = array_filter([
            'fields' => 'id,name,language,status,category,components,parameter_format,rejected_reason',
            'limit' => 100,
            'status' => $status,
        ]);

        $templates = [];
        $url = $this->getTemplatesEndpoint();
        $visited = [];

        while ($url !== null && ! isset($visited[$url])) {
            $visited[$url] = true;

            $response = $this->http()->get($url, $query);

            if (! $response->successful()) {
                throw TemplateSyncException::fetchFailed($response->status(), $response->json('error'));
            }

            array_push($templates, ...($response->json('data') ?? []));

            // paging.next already carries the query and the cursor
            $url = $response->json('paging.next');
            $query = [];
        }

        return $templates;
    }
```

In `src/Client/WhatsAppClientInterface.php`, add `@throws \Multek\LaravelWhatsAppCloud\Exceptions\TemplateSyncException` to the `getTemplates` docblock (create the docblock if it has none).

- [ ] **Step 5: Run the tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/TemplateSyncTest.php`
Expected: PASS (5 tests).

- [ ] **Step 6: Full suite, style, static analysis**

Run: `./vendor/bin/pest && ./vendor/bin/pint --test && ./vendor/bin/phpstan analyse`
Expected: all green.

- [ ] **Step 7: Commit**

```bash
git add src/Exceptions/TemplateSyncException.php src/Client/WhatsAppClient.php src/Client/WhatsAppClientInterface.php tests/Feature/TemplateSyncTest.php
git commit -m "Follow template pagination and fail loudly on Meta errors

getTemplates() read only the first page and returned [] on an error
response, which made the sync disable templates it never saw.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: `parameter_format` column and a safe sync job

**Files:**
- Create: `database/migrations/2024_01_01_000013_add_parameter_format_to_whatsapp_templates.php`
- Modify: `src/Models/WhatsAppTemplate.php` (docblock, constants, `$fillable`, `usesNamedParameters()`)
- Modify: `src/Jobs/WhatsAppSyncTemplates.php:33-73`
- Modify: `README.md` (Console Commands section, around line 792)
- Test: `tests/Feature/TemplateSyncTest.php` (append)

**Interfaces:**
- Consumes: `WhatsAppClient::getTemplates()` from Task 1 (throws `TemplateSyncException`).
- Produces: `WhatsAppTemplate::PARAMETER_FORMAT_NAMED = 'NAMED'`, `PARAMETER_FORMAT_POSITIONAL = 'POSITIONAL'`, `usesNamedParameters(): bool`; column `whatsapp_templates.parameter_format` (nullable string).

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/TemplateSyncTest.php`. Add these imports at the top: `use Multek\LaravelWhatsAppCloud\Jobs\WhatsAppSyncTemplates;` and `use Multek\LaravelWhatsAppCloud\Models\WhatsAppTemplate;`.

```php
it('syncs templates from every page and disables only the ones Meta no longer has', function () {
    WhatsAppTemplate::create([
        'whatsapp_phone_id' => $this->phone->id, 'template_id' => '99', 'name' => 'gone',
        'language' => 'pt_BR', 'category' => 'UTILITY', 'status' => 'APPROVED', 'components' => [],
    ]);

    fakeTemplatePages([
        'first' => Http::response(['data' => [metaTemplate('1', 'welcome')], 'paging' => ['next' => nextPage('p2')]]),
        'p2' => Http::response(['data' => [metaTemplate('2', 'order_update', 'NAMED')]]),
    ]);

    (new WhatsAppSyncTemplates($this->phone))->handle();

    expect(WhatsAppTemplate::where('name', 'order_update')->value('status'))->toBe('APPROVED')
        ->and(WhatsAppTemplate::where('name', 'welcome')->value('status'))->toBe('APPROVED')
        ->and(WhatsAppTemplate::where('name', 'gone')->value('status'))->toBe(WhatsAppTemplate::STATUS_DISABLED);
});

it('stores the parameter format Meta reports', function () {
    fakeTemplatePages(['first' => Http::response(['data' => [
        metaTemplate('1', 'welcome'),
        metaTemplate('2', 'order_update', 'NAMED'),
    ]])]);

    (new WhatsAppSyncTemplates($this->phone))->handle();

    $named = WhatsAppTemplate::where('name', 'order_update')->first();
    $positional = WhatsAppTemplate::where('name', 'welcome')->first();

    expect($named->parameter_format)->toBe(WhatsAppTemplate::PARAMETER_FORMAT_NAMED)
        ->and($named->usesNamedParameters())->toBeTrue()
        ->and($positional->usesNamedParameters())->toBeFalse();
});

it('changes nothing when a page fails', function () {
    WhatsAppTemplate::create([
        'whatsapp_phone_id' => $this->phone->id, 'template_id' => '2', 'name' => 'order_update',
        'language' => 'pt_BR', 'category' => 'UTILITY', 'status' => 'APPROVED', 'components' => [],
    ]);

    fakeTemplatePages([
        'first' => Http::response(['data' => [metaTemplate('1', 'welcome')], 'paging' => ['next' => nextPage('p2')]]),
        'p2' => Http::response(['error' => ['message' => 'boom']], 500),
    ]);

    expect(fn () => (new WhatsAppSyncTemplates($this->phone))->handle())->toThrow(TemplateSyncException::class);

    expect(WhatsAppTemplate::count())->toBe(1)
        ->and(WhatsAppTemplate::where('name', 'order_update')->value('status'))->toBe('APPROVED');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/TemplateSyncTest.php`
Expected: FAIL on `parameter_format` (no such column).

- [ ] **Step 3: Add the migration**

`database/migrations/2024_01_01_000013_add_parameter_format_to_whatsapp_templates.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Meta reports NAMED or POSITIONAL per template; existing rows stay null until the next sync.
        Schema::table('whatsapp_templates', function (Blueprint $table) {
            $table->string('parameter_format')->nullable()->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_templates', function (Blueprint $table) {
            $table->dropColumn('parameter_format');
        });
    }
};
```

- [ ] **Step 4: Update the model**

In `src/Models/WhatsAppTemplate.php`:
- Add `@property string|null $parameter_format` after `@property string $category`.
- Add the constants after the `STATUS_*` block:
  ```php
      public const PARAMETER_FORMAT_NAMED = 'NAMED';

      public const PARAMETER_FORMAT_POSITIONAL = 'POSITIONAL';
  ```
- Add `'parameter_format',` to `$fillable` after `'category',`.
- Add after `isRejected()`:
  ```php
      public function usesNamedParameters(): bool
      {
          return $this->parameter_format === self::PARAMETER_FORMAT_NAMED;
      }
  ```

- [ ] **Step 5: Update the sync job**

In `src/Jobs/WhatsAppSyncTemplates.php`, replace `handle()`:

```php
    public function handle(): void
    {
        $phone = $this->phone;
        $client = new WhatsAppClient($phone);

        // Throws on any failed page, so nothing below runs on a partial list
        $templates = $client->getTemplates();

        foreach ($templates as $templateData) {
            $this->syncTemplate($phone, $templateData);
        }

        // The list is complete here: anything missing from it is gone on Meta's side
        $activeTemplateIds = collect($templates)->pluck('id')->toArray();

        WhatsAppTemplate::where('whatsapp_phone_id', $phone->id)
            ->whereNotIn('template_id', $activeTemplateIds)
            ->update(['status' => WhatsAppTemplate::STATUS_DISABLED]);
    }
```

In `syncTemplate()`, add `'parameter_format' => $templateData['parameter_format'] ?? null,` after the `'category'` line.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/TemplateSyncTest.php`
Expected: PASS (8 tests).

- [ ] **Step 7: Document it**

In `README.md`, under `# Sync message templates from Meta` in the Console Commands block, replace the comment line with:

```bash
# Sync message templates from Meta (follows every page; if Meta returns an error,
# nothing is changed and the job retries, so templates are never disabled by mistake)
```

- [ ] **Step 8: Full suite, style, static analysis**

Run: `./vendor/bin/pest && ./vendor/bin/pint --test && ./vendor/bin/phpstan analyse`
Expected: all green, including `tests/Integration/MigrationAdoptionTest.php`.

- [ ] **Step 9: Commit and open PR A**

```bash
git add database/migrations/2024_01_01_000013_add_parameter_format_to_whatsapp_templates.php src/Models/WhatsAppTemplate.php src/Jobs/WhatsAppSyncTemplates.php README.md tests/Feature/TemplateSyncTest.php
git commit -m "Store template parameter_format and disable only after a full sync

Closes #49.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
git push -u origin claude/laravel-whatsapp-cloud-api-issues-6ee97c
```

Open the PR against `main` with a body that ends with `🤖 Generated with [Claude Code](https://claude.com/claude-code)`.

---

### Task 3: `TemplateComponents`

Switch to PR B's branch first: `git switch -c claude/template-parameters origin/main`.

**Files:**
- Create: `src/Support/TemplateComponents.php`
- Test: `tests/Unit/TemplateComponentsTest.php`

**Interfaces:**
- Produces (all used by Tasks 4–7):
  - `headerText(string $text, ?string $name = null): self`
  - `headerMedia(string $type, SplFileInfo|string $source, ?string $filename = null): self`
  - `body(array $params): self`
  - `urlButton(int $index, string $suffix): self`, `copyCodeButton(int $index, string $code): self`, `quickReplyButton(int $index, string $payload): self`
  - `raw(array $components): self`
  - `toComponents(): array` (Meta components list), `toRecord(): array{header: ?array, body: array, buttons: array}`
  - `headerFile(): ?SplFileInfo`, `headerMediaId(): ?string`, `resolveHeaderMedia(string $mediaId): void`

- [ ] **Step 1: Write the failing tests**

`tests/Unit/TemplateComponentsTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Multek\LaravelWhatsAppCloud\Support\TemplateComponents;

it('builds positional body parameters from a list', function () {
    $components = (new TemplateComponents)->body(['Marina', 42])->toComponents();

    expect($components)->toBe([[
        'type' => 'body',
        'parameters' => [
            ['type' => 'text', 'text' => 'Marina'],
            ['type' => 'text', 'text' => '42'],
        ],
    ]]);
});

it('builds named body parameters from string keys', function () {
    $components = (new TemplateComponents)->body(['customer_name' => 'Marina', 'order_id' => '#42'])->toComponents();

    expect($components[0]['parameters'])->toBe([
        ['type' => 'text', 'parameter_name' => 'customer_name', 'text' => 'Marina'],
        ['type' => 'text', 'parameter_name' => 'order_id', 'text' => '#42'],
    ]);
});

it('rejects body parameters that mix named and positional keys', function () {
    (new TemplateComponents)->body(['customer_name' => 'Marina', 'Extra']);
})->throws(InvalidArgumentException::class, 'not a mix');

it('builds a header text parameter with and without a name', function () {
    expect((new TemplateComponents)->headerText('#42')->toRecord()['header'])
        ->toBe(['type' => 'text', 'text' => '#42'])
        ->and((new TemplateComponents)->headerText('#42', 'order_id')->toRecord()['header'])
        ->toBe(['type' => 'text', 'parameter_name' => 'order_id', 'text' => '#42']);
});

it('sends a digits-only header source as a media id and anything else as a link', function () {
    expect((new TemplateComponents)->headerMedia('image', '1234567890')->toRecord()['header'])
        ->toBe(['type' => 'image', 'image' => ['id' => '1234567890']])
        ->and((new TemplateComponents)->headerMedia('document', 'https://x.test/a.pdf', 'a.pdf')->toRecord()['header'])
        ->toBe(['type' => 'document', 'document' => ['link' => 'https://x.test/a.pdf', 'filename' => 'a.pdf']]);
});

it('rejects header media types Meta does not support', function () {
    (new TemplateComponents)->headerMedia('audio', 'https://x.test/a.ogg');
})->throws(InvalidArgumentException::class, 'image, video or document');

it('keeps a header file pending and resolves it to a media id after upload', function () {
    $file = UploadedFile::fake()->create('Orçamento.pdf', 4, 'application/pdf');
    $template = (new TemplateComponents)->headerMedia('document', $file);

    expect($template->headerFile())->toBe($file)
        ->and($template->headerMediaId())->toBeNull()
        ->and($template->toRecord()['header'])->toBe(['type' => 'document', 'document' => ['filename' => 'Orçamento.pdf']]);

    $template->resolveHeaderMedia('media_123');

    expect($template->headerMediaId())->toBe('media_123')
        ->and($template->toRecord()['header'])
        ->toBe(['type' => 'document', 'document' => ['id' => 'media_123', 'filename' => 'Orçamento.pdf']]);
});

it('drops a pending header file when the header is replaced by a url', function () {
    $template = (new TemplateComponents)
        ->headerMedia('image', UploadedFile::fake()->image('a.jpg'))
        ->headerMedia('image', 'https://x.test/b.jpg');

    expect($template->headerFile())->toBeNull()
        ->and($template->toRecord()['header'])->toBe(['type' => 'image', 'image' => ['link' => 'https://x.test/b.jpg']]);
});

it('builds each button type and sorts them by index', function () {
    $template = (new TemplateComponents)
        ->quickReplyButton(2, 'talk_to_human')
        ->urlButton(1, 'PED-1')
        ->copyCodeButton(0, 'CORBI10');

    expect($template->toComponents())->toBe([
        ['type' => 'button', 'sub_type' => 'copy_code', 'index' => 0, 'parameters' => [['type' => 'coupon_code', 'coupon_code' => 'CORBI10']]],
        ['type' => 'button', 'sub_type' => 'url', 'index' => 1, 'parameters' => [['type' => 'text', 'text' => 'PED-1']]],
        ['type' => 'button', 'sub_type' => 'quick_reply', 'index' => 2, 'parameters' => [['type' => 'payload', 'payload' => 'talk_to_human']]],
    ]);
});

it('rejects a second parameter for the same button index', function () {
    (new TemplateComponents)->urlButton(1, 'PED-1')->copyCodeButton(1, 'CORBI10');
})->throws(InvalidArgumentException::class, 'Template button 1');

it('rejects a raw button whose index is already set', function () {
    (new TemplateComponents)->urlButton(0, 'PED-1')->raw([
        ['type' => 'button', 'sub_type' => 'quick_reply', 'index' => '0', 'parameters' => [['type' => 'payload', 'payload' => 'x']]],
    ]);
})->throws(InvalidArgumentException::class, 'Template button 0');

it('round-trips raw Meta components and keeps unknown types after the known ones', function () {
    $raw = [
        ['type' => 'header', 'parameters' => [['type' => 'text', 'text' => '#42']]],
        ['type' => 'body', 'parameters' => [['type' => 'currency', 'currency' => ['fallback_value' => 'R$10', 'code' => 'BRL', 'amount_1000' => 10000]]]],
        ['type' => 'button', 'sub_type' => 'url', 'index' => 0, 'parameters' => [['type' => 'text', 'text' => 'PED-1']]],
        ['type' => 'limited_time_offer', 'parameters' => [['type' => 'limited_time_offer', 'limited_time_offer' => ['expiration_time_ms' => 1]]]],
    ];

    expect((new TemplateComponents)->raw($raw)->toComponents())->toBe($raw);
});

it('lets a later header or body write replace the earlier one', function () {
    $template = (new TemplateComponents)
        ->raw([['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'old']]]])
        ->body(['new'])
        ->headerText('first')
        ->headerText('second');

    expect($template->toRecord())->toBe([
        'header' => ['type' => 'text', 'text' => 'second'],
        'body' => [['type' => 'text', 'text' => 'new']],
        'buttons' => [],
    ]);
});

it('produces nothing when no parameters were given', function () {
    expect((new TemplateComponents)->toComponents())->toBe([])
        ->and((new TemplateComponents)->toRecord())->toBe(['header' => null, 'body' => [], 'buttons' => []]);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/pest tests/Unit/TemplateComponentsTest.php`
Expected: FAIL with "Class ... TemplateComponents not found".

- [ ] **Step 3: Implement `TemplateComponents`**

`src/Support/TemplateComponents.php`:

```php
<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Support;

use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use SplFileInfo;

/**
 * Template parameters kept in Meta's own component shape, so the payload that is sent and
 * the row that is recorded are read from the same place.
 */
class TemplateComponents
{
    protected const HEADER_MEDIA_TYPES = ['image', 'video', 'document'];

    /** @var array<string, mixed>|null */
    protected ?array $header = null;

    /** @var array<int, array<string, mixed>> */
    protected array $body = [];

    /** @var array<int, array<string, mixed>> keyed by button index */
    protected array $buttons = [];

    /** @var array<int, array<string, mixed>> raw components of any other type */
    protected array $extra = [];

    protected ?SplFileInfo $headerFile = null;

    public function headerText(string $text, ?string $name = null): self
    {
        $this->headerFile = null;
        $this->header = $this->textParameter($text, $name);

        return $this;
    }

    /**
     * A file is uploaded later (see resolveHeaderMedia); a digits-only string is a Meta media id;
     * any other string is a link.
     */
    public function headerMedia(string $type, SplFileInfo|string $source, ?string $filename = null): self
    {
        if (! in_array($type, self::HEADER_MEDIA_TYPES, true)) {
            throw new InvalidArgumentException("A template header must be image, video or document, got '{$type}'.");
        }

        $this->headerFile = $source instanceof SplFileInfo ? $source : null;

        $media = match (true) {
            $source instanceof SplFileInfo => [],
            preg_match('/^\d+$/', $source) === 1 => ['id' => $source],
            default => ['link' => $source],
        };

        if ($type === 'document') {
            $filename ??= $source instanceof SplFileInfo ? $this->fileName($source) : null;

            if ($filename !== null) {
                $media['filename'] = $filename;
            }
        }

        $this->header = ['type' => $type, $type => $media];

        return $this;
    }

    /**
     * A list sends positional parameters; string keys send them as Meta's parameter_name.
     *
     * @param  array<int|string, string|int|float>  $params
     */
    public function body(array $params): self
    {
        $named = count(array_filter(array_keys($params), is_string(...)));

        if ($named > 0 && $named !== count($params)) {
            throw new InvalidArgumentException('Template body parameters must be all positional (a list) or all named (string keys), not a mix.');
        }

        $this->body = [];

        foreach ($params as $key => $value) {
            $this->body[] = $this->textParameter((string) $value, is_string($key) ? $key : null);
        }

        return $this;
    }

    public function urlButton(int $index, string $suffix): self
    {
        return $this->button($index, 'url', ['type' => 'text', 'text' => $suffix]);
    }

    public function copyCodeButton(int $index, string $code): self
    {
        return $this->button($index, 'copy_code', ['type' => 'coupon_code', 'coupon_code' => $code]);
    }

    public function quickReplyButton(int $index, string $payload): self
    {
        return $this->button($index, 'quick_reply', ['type' => 'payload', 'payload' => $payload]);
    }

    /**
     * Take components exactly as Meta documents them.
     *
     * @param  array<int, array<string, mixed>>  $components
     */
    public function raw(array $components): self
    {
        foreach ($components as $component) {
            match (strtolower((string) ($component['type'] ?? ''))) {
                'header' => $this->rawHeader($component),
                'body' => $this->body = array_values($component['parameters'] ?? []),
                'button' => $this->addButton($component),
                default => $this->extra[] = $component,
            };
        }

        return $this;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function toComponents(): array
    {
        $components = [];

        if ($this->header !== null) {
            $components[] = ['type' => 'header', 'parameters' => [$this->header]];
        }

        if ($this->body !== []) {
            $components[] = ['type' => 'body', 'parameters' => $this->body];
        }

        return [...$components, ...$this->sortedButtons(), ...$this->extra];
    }

    /**
     * @return array{header: array<string, mixed>|null, body: array<int, array<string, mixed>>, buttons: array<int, array<string, mixed>>}
     */
    public function toRecord(): array
    {
        return [
            'header' => $this->header,
            'body' => $this->body,
            'buttons' => $this->sortedButtons(),
        ];
    }

    public function headerFile(): ?SplFileInfo
    {
        return $this->headerFile;
    }

    public function headerMediaId(): ?string
    {
        $type = $this->header['type'] ?? null;

        return in_array($type, self::HEADER_MEDIA_TYPES, true) ? ($this->header[$type]['id'] ?? null) : null;
    }

    /**
     * Point the header at the media id Meta returned for the uploaded file.
     */
    public function resolveHeaderMedia(string $mediaId): void
    {
        $type = $this->header['type'] ?? null;

        if (! in_array($type, self::HEADER_MEDIA_TYPES, true)) {
            return;
        }

        $this->header[$type] = ['id' => $mediaId] + ($this->header[$type] ?? []);
        $this->headerFile = null;
    }

    /**
     * @return array<string, string>
     */
    protected function textParameter(string $text, ?string $name): array
    {
        return $name === null
            ? ['type' => 'text', 'text' => $text]
            : ['type' => 'text', 'parameter_name' => $name, 'text' => $text];
    }

    /**
     * @param  array<string, mixed>  $parameter
     */
    protected function button(int $index, string $subType, array $parameter): self
    {
        return $this->addButton([
            'type' => 'button',
            'sub_type' => $subType,
            'index' => $index,
            'parameters' => [$parameter],
        ]);
    }

    /**
     * @param  array<string, mixed>  $component
     */
    protected function addButton(array $component): self
    {
        $index = (int) ($component['index'] ?? 0);

        if (isset($this->buttons[$index])) {
            throw new InvalidArgumentException("Template button {$index} already has a parameter.");
        }

        $this->buttons[$index] = $component;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $component
     */
    protected function rawHeader(array $component): void
    {
        $this->headerFile = null;
        $this->header = $component['parameters'][0] ?? null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function sortedButtons(): array
    {
        $buttons = $this->buttons;
        ksort($buttons);

        return array_values($buttons);
    }

    protected function fileName(SplFileInfo $file): string
    {
        return $file instanceof UploadedFile ? $file->getClientOriginalName() : $file->getFilename();
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `./vendor/bin/pest tests/Unit/TemplateComponentsTest.php`
Expected: PASS (14 tests).

- [ ] **Step 5: Full suite, style, static analysis**

Run: `./vendor/bin/pest && ./vendor/bin/pint --test && ./vendor/bin/phpstan analyse`
Expected: all green. If PHPStan flags the `match` arms in `raw()` as unused results, rewrite `raw()` as a `switch` with the same four cases.

- [ ] **Step 6: Commit**

```bash
git add src/Support/TemplateComponents.php tests/Unit/TemplateComponentsTest.php
git commit -m "Add TemplateComponents to hold template parameters in Meta's shape

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: `MessageBuilder` delegates template state to `TemplateComponents`

**Files:**
- Modify: `src/Support/MessageBuilder.php`: template properties (`:57-67`), template setters (`:255-310`), `buildTemplateComponents()` (`:590-634`), `buildTemplateParametersForRecord()` (`:847-858`), both record creators (`:640-681`), `send()`/`queue()` (`:464-488`), plus new methods
- Test: `tests/Feature/TemplateMessageTest.php` (new); existing `tests/Feature/TemplateHeaderDocumentTest.php` and `tests/Feature/InteractiveImageHeaderTest.php` must stay green unchanged

**Interfaces:**
- Consumes: everything `TemplateComponents` produces (Task 3).
- Produces: builder methods `headerText(string $text, ?string $name = null)`, `headerImage(SplFileInfo|string $source)`, `headerVideo(SplFileInfo|string $source)`, `headerDocument(SplFileInfo|string $source, ?string $filename = null)`, `bodyParameters(array $params)`, `buttonParameters(array $params)`, `urlButton(int, string)`, `copyCodeButton(int, string)`, `quickReplyButton(int, string)`, `components(array $metaComponents)`; protected `MessageBuilder::$template` (`TemplateComponents`), used by Tasks 5–6.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/TemplateMessageTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppPhone;
use Multek\LaravelWhatsAppCloud\WhatsAppManager;

beforeEach(function () {
    Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.template']]])]);

    WhatsAppPhone::create([
        'key' => 'test',
        'phone_id' => 'test_phone_id',
        'phone_number' => '+15551234567',
        'business_account_id' => 'test_waba',
        'is_active' => true,
    ]);

    $this->template = fn (string $name = 'order_update') => app(WhatsAppManager::class)
        ->phone('test')->to('5511999999999')->template($name);
});

/**
 * @return array<int, array<string, mixed>>
 */
function sentComponents(): array
{
    $components = null;

    Http::assertSent(function (Request $request) use (&$components) {
        $components = $request->data()['template']['components'] ?? [];

        return true;
    });

    return $components;
}

it('keeps the payload of existing positional calls unchanged', function () {
    ($this->template)()
        ->headerText('#42')
        ->bodyParameters(['Marina', 'amanhã'])
        ->buttonParameters([1 => 'PED-1'])
        ->send();

    expect(sentComponents())->toBe([
        ['type' => 'header', 'parameters' => [['type' => 'text', 'text' => '#42']]],
        ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Marina'], ['type' => 'text', 'text' => 'amanhã']]],
        ['type' => 'button', 'sub_type' => 'url', 'index' => 1, 'parameters' => [['type' => 'text', 'text' => 'PED-1']]],
    ]);
});

it('keeps a plain buttonParameters list targeting button 0', function () {
    ($this->template)()->buttonParameters(['PED-1'])->send();

    expect(sentComponents()[0]['index'])->toBe(0);
});

it('sends named body and header parameters', function () {
    ($this->template)()
        ->headerText('#42', name: 'order_id')
        ->bodyParameters(['customer_name' => 'Marina'])
        ->send();

    expect(sentComponents())->toBe([
        ['type' => 'header', 'parameters' => [['type' => 'text', 'parameter_name' => 'order_id', 'text' => '#42']]],
        ['type' => 'body', 'parameters' => [['type' => 'text', 'parameter_name' => 'customer_name', 'text' => 'Marina']]],
    ]);
});

it('sends copy-code, url and quick-reply buttons at their explicit indexes', function () {
    ($this->template)()
        ->copyCodeButton(0, 'CORBI10')
        ->urlButton(1, 'PED-1')
        ->quickReplyButton(2, 'talk_to_human')
        ->send();

    expect(array_column(sentComponents(), 'sub_type'))->toBe(['copy_code', 'url', 'quick_reply']);
});

it('sends a digits-only header image as a media id', function () {
    $message = ($this->template)()->headerImage('1234567890')->send();

    expect(sentComponents()[0]['parameters'][0])->toBe(['type' => 'image', 'image' => ['id' => '1234567890']])
        ->and($message->media_id)->toBe('1234567890');
});

it('sends raw Meta components as given', function () {
    $raw = [['type' => 'body', 'parameters' => [['type' => 'date_time', 'date_time' => ['fallback_value' => 'amanhã']]]]];

    $message = ($this->template)()->components($raw)->send();

    expect(sentComponents())->toBe($raw)
        ->and($message->template_parameters['body'])->toBe($raw[0]['parameters']);
});

it('records template_parameters in the same shape it sent', function () {
    $message = ($this->template)()
        ->headerDocument('https://x.test/o.pdf', 'Orçamento.pdf')
        ->bodyParameters(['customer_name' => 'Marina'])
        ->copyCodeButton(0, 'CORBI10')
        ->send();

    $components = sentComponents();

    expect($message->fresh()->template_parameters)->toBe([
        'header' => $components[0]['parameters'][0],
        'body' => $components[1]['parameters'],
        'buttons' => [$components[2]],
    ])->and($message->fresh()->content['components'])->toBe($components);
});

it('rejects a header file on an interactive message', function () {
    app(WhatsAppManager::class)->phone('test')->to('5511999999999')
        ->buttons('Choose')
        ->headerImage(UploadedFile::fake()->image('a.jpg'))
        ->button('yes', 'Yes')
        ->send();
})->throws(InvalidArgumentException::class, 'only be sent with a template');
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/TemplateMessageTest.php`
Expected: FAIL. `urlButton`, `copyCodeButton` and `components` are undefined, named params are sent without `parameter_name`, and the header image goes out as `link`.

- [ ] **Step 3: Replace the template state**

In `src/Support/MessageBuilder.php`, delete `$headerType`, `$headerValue`, `$headerFilename`, `$bodyParameters` and `$buttonParameters` (`:57-67`) and add:

```php
    protected TemplateComponents $template;
```

Give the constructor a body:

```php
    public function __construct(
        protected WhatsAppPhone $phone,
        protected WhatsAppClientInterface $client,
    ) {
        $this->template = new TemplateComponents;
    }
```

- [ ] **Step 4: Replace the template setters**

Replace `headerImage()` through `buttonParameters()` (`:255-310`) with:

```php
    // Template header media: a file is uploaded to Meta, a digits-only string is a media id,
    // any other string is a link.
    public function headerImage(SplFileInfo|string $source): self
    {
        $this->template->headerMedia('image', $source);
        $this->interactiveHeader = is_string($source) ? ['type' => 'image', 'image' => $source] : null;

        return $this;
    }

    public function headerVideo(SplFileInfo|string $source): self
    {
        $this->template->headerMedia('video', $source);

        return $this;
    }

    public function headerDocument(SplFileInfo|string $source, ?string $filename = null): self
    {
        $this->template->headerMedia('document', $source, $filename);

        return $this;
    }

    public function headerText(string $text, ?string $name = null): self
    {
        $this->template->headerText($text, $name);

        return $this;
    }

    /**
     * A list sends positional parameters; string keys send named ones (parameter_name).
     *
     * @param  array<int|string, string|int|float>  $params
     */
    public function bodyParameters(array $params): self
    {
        $this->template->body($params);

        return $this;
    }

    /**
     * URL button suffixes keyed by button index: [1 => 'PED-1'] targets the second button.
     *
     * @param  array<int, string>  $params
     */
    public function buttonParameters(array $params): self
    {
        foreach ($params as $index => $suffix) {
            $this->template->urlButton((int) $index, (string) $suffix);
        }

        return $this;
    }

    public function urlButton(int $index, string $suffix): self
    {
        $this->template->urlButton($index, $suffix);

        return $this;
    }

    public function copyCodeButton(int $index, string $code): self
    {
        $this->template->copyCodeButton($index, $code);

        return $this;
    }

    public function quickReplyButton(int $index, string $payload): self
    {
        $this->template->quickReplyButton($index, $payload);

        return $this;
    }

    /**
     * Template components exactly as Meta documents them, for anything the helpers don't cover.
     *
     * @param  array<int, array<string, mixed>>  $metaComponents
     */
    public function components(array $metaComponents): self
    {
        $this->template->raw($metaComponents);

        return $this;
    }
```

- [ ] **Step 5: Read components and the record from `TemplateComponents`**

Replace the body of `buildTemplateComponents()` (`:590-634`), keeping its `@return` docblock:

```php
    protected function buildTemplateComponents(): array
    {
        return $this->template->toComponents();
    }
```

Replace `buildTemplateParametersForRecord()`:

```php
    /**
     * @return array<string, mixed>|null
     */
    protected function buildTemplateParametersForRecord(): ?array
    {
        return $this->messageType === 'template' ? $this->template->toRecord() : null;
    }
```

In both `createMessageRecord()` and `createPendingMessage()`, change the closing `] + $this->mediaAttributes);` to `] + $this->mediaAttributes + $this->templateMediaAttributes());` and add:

```php
    /**
     * A template header sent by media id records it like any outbound media.
     *
     * @return array<string, string>
     */
    protected function templateMediaAttributes(): array
    {
        $mediaId = $this->messageType === 'template' ? $this->template->headerMediaId() : null;

        return $mediaId === null ? [] : ['media_id' => $mediaId];
    }
```

- [ ] **Step 6: Reject header files outside templates**

At the top of both `send()` and `queue()`, right after `$this->ensureRecipient();`, add `$this->ensureHeaderFileIsTemplate();` and define:

```php
    protected function ensureHeaderFileIsTemplate(): void
    {
        if ($this->messageType !== 'template' && $this->template->headerFile() !== null) {
            throw new \InvalidArgumentException('A header file can only be sent with a template; pass a URL or media id for an interactive header.');
        }
    }
```

- [ ] **Step 7: Run the new and existing template tests**

Run: `./vendor/bin/pest tests/Feature/TemplateMessageTest.php tests/Feature/TemplateHeaderDocumentTest.php tests/Feature/InteractiveImageHeaderTest.php`
Expected: all PASS, including the unchanged `TemplateHeaderDocumentTest` and `InteractiveImageHeaderTest`.

- [ ] **Step 8: Full suite, style, static analysis**

Run: `./vendor/bin/pest && ./vendor/bin/pint --test && ./vendor/bin/phpstan analyse`
Expected: all green.

- [ ] **Step 9: Commit**

```bash
git add src/Support/MessageBuilder.php tests/Feature/TemplateMessageTest.php
git commit -m "Send named parameters, typed buttons and raw template components

Template parameters are kept in Meta's shape and template_parameters
now records exactly what was sent.

Closes #45, closes #46, closes #47.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Upload a template header file on `send()`

**Files:**
- Modify: `src/Support/MessageBuilder.php`: `media()` (`:199-211`), `uploadMediaFile()` (`:713-732`), `storeMediaFile()` (`:734-752`), `mediaFileMimeType()` / `mediaFileName()` (`:754-767`)
- Test: `tests/Feature/TemplateHeaderUploadTest.php` (new)

**Interfaces:**
- Consumes: `MessageBuilder::$template`, `TemplateComponents::headerFile()`, `resolveHeaderMedia()` (Tasks 3–4).
- Produces: protected `pendingFile(): ?SplFileInfo`, `fileMimeType(SplFileInfo $file): string` and `fileName(SplFileInfo $file): string` on `MessageBuilder`, used by Task 6.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/TemplateHeaderUploadTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppMessage;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppPhone;
use Multek\LaravelWhatsAppCloud\WhatsAppManager;

beforeEach(function () {
    Storage::fake('local');

    Http::fake([
        '*/test_phone_id/media' => Http::response(['id' => 'media_123']),
        '*/test_phone_id/messages' => Http::response(['messages' => [['id' => 'wamid.template']]]),
    ]);

    WhatsAppPhone::create([
        'key' => 'test',
        'phone_id' => 'test_phone_id',
        'phone_number' => '+15551234567',
        'business_account_id' => 'test_waba',
        'is_active' => true,
    ]);

    $this->builder = fn () => app(WhatsAppManager::class)->phone('test')->to('5511999999999')->template('quote_ready');
});

it('uploads a template document header, sends it by id and keeps a local copy', function () {
    $message = ($this->builder)()
        ->headerDocument(UploadedFile::fake()->create('Orçamento.pdf', 4, 'application/pdf'))
        ->bodyParameters(['Marina'])
        ->send();

    $header = ['type' => 'document', 'document' => ['id' => 'media_123', 'filename' => 'Orçamento.pdf']];

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/test_phone_id/media')
        && $request->hasFile('file', null, 'Orçamento.pdf'));
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/test_phone_id/messages')
        && $request->data()['template']['components'][0]['parameters'][0] === $header);

    expect($message->media_id)->toBe('media_123')
        ->and($message->media_mime_type)->toBe('application/pdf')
        ->and($message->media_status)->toBe(WhatsAppMessage::MEDIA_STATUS_DOWNLOADED)
        ->and($message->local_media_disk)->toBe('local')
        ->and($message->local_media_path)->toEndWith('.pdf')
        ->and($message->template_parameters['header'])->toBe($header)
        ->and($message->content['components'][0]['parameters'][0])->toBe($header);

    Storage::disk('local')->assertExists($message->local_media_path);
});

it('uses the given filename over the file name for a document header', function () {
    ($this->builder)()
        ->headerDocument(UploadedFile::fake()->create('tmp123.pdf', 4, 'application/pdf'), 'Orçamento.pdf')
        ->send();

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/test_phone_id/messages')
        && $request->data()['template']['components'][0]['parameters'][0]['document']['filename'] === 'Orçamento.pdf');
});

it('does not upload anything for a template with a link header', function () {
    ($this->builder)()->headerImage('https://x.test/a.jpg')->send();

    Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/media'));
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/TemplateHeaderUploadTest.php`
Expected: the first two FAIL (no upload request; the header has no `id`); the third PASSES.

- [ ] **Step 3: Generalize the file helpers**

In `src/Support/MessageBuilder.php`, replace `mediaFileMimeType()` and `mediaFileName()` with versions that take the file:

```php
    protected function fileMimeType(SplFileInfo $file): string
    {
        $mimeType = $file instanceof SymfonyFile ? $file->getMimeType() : mime_content_type($file->getPathname());

        return $mimeType ?: 'application/octet-stream';
    }

    protected function fileName(SplFileInfo $file): string
    {
        return $file instanceof UploadedFile ? $file->getClientOriginalName() : $file->getFilename();
    }

    /**
     * The local file this message sends: the media file, or a template's header file.
     */
    protected function pendingFile(): ?SplFileInfo
    {
        return $this->messageType === 'template' ? $this->template->headerFile() : $this->mediaFile;
    }
```

In `media()`, change `$this->filename ??= $this->mediaFileName();` to `$this->filename ??= $this->fileName($this->mediaFile);`.

- [ ] **Step 4: Upload and store the pending file**

Replace `uploadMediaFile()` and `storeMediaFile()`:

```php
    /**
     * Upload the local file to Meta and keep a copy on the media disk, so the record
     * is complete (media id, local path, size) by the time MessageSent fires.
     */
    protected function uploadMediaFile(): void
    {
        $file = $this->pendingFile();

        if ($file === null) {
            return;
        }

        $contents = (string) file_get_contents($file->getPathname());
        $mimeType = $this->fileMimeType($file);

        $result = $this->client->uploadMediaContents($contents, $mimeType, $this->fileName($file));
        $mediaId = $result['id'] ?? throw MessageSendException::mediaUploadFailed('no media id returned');

        $this->storeMediaFile($contents, $mimeType);

        if ($this->messageType === 'template') {
            $this->template->resolveHeaderMedia($mediaId);
        } else {
            $this->mediaUrlOrId = $mediaId;
        }

        $this->mediaAttributes['media_id'] = $mediaId;
    }

    /**
     * Keep a copy on the media disk; a queued message is uploaded from it by the job.
     */
    protected function storeMediaFile(?string $contents = null, ?string $mimeType = null): void
    {
        $file = $this->pendingFile();

        if ($file === null || isset($this->mediaAttributes['local_media_path'])) {
            return;
        }

        $contents ??= (string) file_get_contents($file->getPathname());
        $mimeType ??= $this->fileMimeType($file);

        ['disk' => $disk, 'path' => $path] = app(MediaService::class)->store($contents, $mimeType);

        $this->mediaAttributes += [
            'media_mime_type' => $mimeType,
            'media_size' => (int) $file->getSize(),
            'media_status' => WhatsAppMessage::MEDIA_STATUS_DOWNLOADED,
            'local_media_disk' => $disk,
            'local_media_path' => $path,
        ];
    }
```

Note: `resolveHeaderMedia()` clears `headerFile()`, so `pendingFile()` returns `null` afterwards. The `ensureHeaderFileIsTemplate()` guard runs before the upload, so this is safe.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/TemplateHeaderUploadTest.php tests/Feature/OutboundMediaUploadTest.php`
Expected: PASS; the regular media uploads still work.

- [ ] **Step 6: Full suite, style, static analysis**

Run: `./vendor/bin/pest && ./vendor/bin/pint --test && ./vendor/bin/phpstan analyse`
Expected: all green.

- [ ] **Step 7: Commit**

```bash
git add src/Support/MessageBuilder.php tests/Feature/TemplateHeaderUploadTest.php
git commit -m "Upload template header files and send them by media id

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Queued template header files

**Files:**
- Modify: `src/Jobs/WhatsAppSendMessage.php:78-97` (`uploadStoredMedia`)
- Test: `tests/Feature/TemplateHeaderUploadTest.php` (append)

**Interfaces:**
- Consumes: `TemplateComponents::raw()`, `resolveHeaderMedia()`, `toComponents()` and `toRecord()` (Task 3); `MessageBuilder::storeMediaFile()` via `queue()` (Task 5).

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/TemplateHeaderUploadTest.php`. Add the imports `use Illuminate\Support\Facades\Queue;` and `use Multek\LaravelWhatsAppCloud\Jobs\WhatsAppSendMessage;`.

```php
it('stores a queued template header file and uploads it when the job runs', function () {
    Queue::fake();

    $message = ($this->builder)()
        ->headerDocument(UploadedFile::fake()->create('Orçamento.pdf', 4, 'application/pdf'))
        ->queue();

    Http::assertNothingSent();

    expect($message->media_id)->toBeNull()
        ->and($message->local_media_path)->toEndWith('.pdf')
        ->and($message->template_parameters['header'])->toBe(['type' => 'document', 'document' => ['filename' => 'Orçamento.pdf']]);

    (new WhatsAppSendMessage($message))->handle();

    $header = ['type' => 'document', 'document' => ['id' => 'media_123', 'filename' => 'Orçamento.pdf']];
    $message->refresh();

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/test_phone_id/messages')
        && $request->data()['template']['components'][0]['parameters'][0] === $header);

    expect($message->media_id)->toBe('media_123')
        ->and($message->content['components'][0]['parameters'][0])->toBe($header)
        ->and($message->template_parameters['header'])->toBe($header)
        ->and($message->delivery_status)->toBe(WhatsAppMessage::DELIVERY_STATUS_SENT);
});

it('does not upload again when a queued template is retried after the upload', function () {
    Queue::fake();

    $message = ($this->builder)()
        ->headerImage(UploadedFile::fake()->image('banner.jpg'))
        ->queue();

    (new WhatsAppSendMessage($message))->handle();

    // A retry of the same row (e.g. the send failed after the upload succeeded)
    $message->refresh()->update(['delivery_status' => WhatsAppMessage::DELIVERY_STATUS_QUEUED]);
    (new WhatsAppSendMessage($message))->handle();

    Http::assertSentCount(3); // one upload, two sends
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/test_phone_id/messages')
        && $request->data()['template']['components'][0]['parameters'][0] === ['type' => 'image', 'image' => ['id' => 'media_123']]);
});

it('leaves a queued template with a link header untouched by the upload step', function () {
    Queue::fake();

    $message = ($this->builder)()->headerImage('https://x.test/a.jpg')->queue();

    (new WhatsAppSendMessage($message))->handle();

    Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/media'));
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/TemplateHeaderUploadTest.php`
Expected: the two file tests FAIL (the job skips non-media types, so no upload happens and the header has no id).

- [ ] **Step 3: Teach the job about template headers**

In `src/Jobs/WhatsAppSendMessage.php`, add `use Multek\LaravelWhatsAppCloud\Support\TemplateComponents;` and replace `uploadStoredMedia()`:

```php
    /**
     * A message queued with a local file carries its copy on disk but no Meta media id yet;
     * for a template, that file is its header.
     */
    protected function uploadStoredMedia(WhatsAppClient $client, WhatsAppMessage $message): void
    {
        $isTemplate = $message->type === 'template';

        if ((! $message->isMedia() && ! $isTemplate) || $message->media_id !== null || ! $message->local_media_path || ! $message->local_media_disk) {
            return;
        }

        $result = $client->uploadMediaContents(
            Storage::disk($message->local_media_disk)->get($message->local_media_path) ?? '',
            $message->media_mime_type ?? 'application/octet-stream',
            basename($message->local_media_path)
        );

        $mediaId = $result['id'] ?? throw MessageSendException::mediaUploadFailed('no media id returned');

        if (! $isTemplate) {
            $message->update([
                'media_id' => $mediaId,
                'content' => ['id' => $mediaId] + ($message->content ?? []),
            ]);

            return;
        }

        $template = (new TemplateComponents)->raw($message->content['components'] ?? []);
        $template->resolveHeaderMedia($mediaId);

        $message->update([
            'media_id' => $mediaId,
            'content' => ['components' => $template->toComponents()] + ($message->content ?? []),
            'template_parameters' => $template->toRecord(),
        ]);
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/TemplateHeaderUploadTest.php tests/Feature/OutboundMediaUploadTest.php`
Expected: PASS.

- [ ] **Step 5: Full suite, style, static analysis**

Run: `./vendor/bin/pest && ./vendor/bin/pint --test && ./vendor/bin/phpstan analyse`
Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add src/Jobs/WhatsAppSendMessage.php tests/Feature/TemplateHeaderUploadTest.php
git commit -m "Upload queued template header files when the send job runs

Closes #48.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: `TemplateBuilder` delegates and is deprecated

**Files:**
- Modify: `src/Support/TemplateBuilder.php`
- Test: `tests/Feature/TemplateBuilderTest.php` (new); `tests/Feature/OutboundConversationLinkingTest.php` stays green

**Interfaces:**
- Consumes: `TemplateComponents` (Task 3).

- [ ] **Step 1: Write the failing tests**

`tests/Feature/TemplateBuilderTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Multek\LaravelWhatsAppCloud\Client\WhatsAppClient;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppPhone;
use Multek\LaravelWhatsAppCloud\Support\TemplateBuilder;

beforeEach(function () {
    Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.template']]])]);

    $this->phone = WhatsAppPhone::create([
        'key' => 'test',
        'phone_id' => 'test_phone_id',
        'phone_number' => '+15551234567',
        'business_account_id' => 'test_waba',
        'is_active' => true,
    ]);
});

it('sends named parameters and records them in Meta shape', function () {
    $message = (new TemplateBuilder($this->phone, new WhatsAppClient($this->phone), '5511999999999'))
        ->name('order_update')
        ->headerDocument('https://x.test/o.pdf', 'Orçamento.pdf')
        ->bodyParameters(['customer_name' => 'Marina'])
        ->addQuickReplyButton(0, 'talk_to_human')
        ->send();

    $header = ['type' => 'document', 'document' => ['link' => 'https://x.test/o.pdf', 'filename' => 'Orçamento.pdf']];

    Http::assertSent(fn (Request $request) => $request->data()['template']['components'][1]['parameters'][0]
        === ['type' => 'text', 'parameter_name' => 'customer_name', 'text' => 'Marina']);

    expect($message->template_parameters)->toBe([
        'header' => $header,
        'body' => [['type' => 'text', 'parameter_name' => 'customer_name', 'text' => 'Marina']],
        'buttons' => [['type' => 'button', 'sub_type' => 'quick_reply', 'index' => 0, 'parameters' => [['type' => 'payload', 'payload' => 'talk_to_human']]]],
    ]);
});

it('appends body parameters one at a time', function () {
    $message = (new TemplateBuilder($this->phone, new WhatsAppClient($this->phone), '5511999999999'))
        ->name('order_update')
        ->addBodyParameter('Marina')
        ->addBodyParameter('#42')
        ->send();

    expect(array_column($message->template_parameters['body'], 'text'))->toBe(['Marina', '#42']);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/TemplateBuilderTest.php`
Expected: FAIL. The named parameter has no `parameter_name`, and the record shape differs.

- [ ] **Step 3: Rewrite the internals of `TemplateBuilder`**

In `src/Support/TemplateBuilder.php`:
- Add a class docblock:
  ```php
  /**
   * @deprecated Use WhatsApp::phone($key)->to($number)->template($name) instead; this class will be removed in the next major.
   */
  ```
- Replace `$components`, `$headerType`, `$headerValue`, `$bodyParameters` and `$buttonParameters` with:
  ```php
      protected TemplateComponents $template;

      /** @var array<int|string, string> */
      protected array $bodyValues = [];
  ```
- Give the constructor a body: `{ $this->template = new TemplateComponents; }`.
- Replace the header, body and button methods (keep their docblocks' one-line summaries):
  ```php
      public function headerText(string $text): self
      {
          $this->template->headerText($text);

          return $this;
      }

      public function headerImage(string $url): self
      {
          $this->template->headerMedia('image', $url);

          return $this;
      }

      public function headerVideo(string $url): self
      {
          $this->template->headerMedia('video', $url);

          return $this;
      }

      public function headerDocument(string $url, ?string $filename = null): self
      {
          $this->template->headerMedia('document', $url, $filename);

          return $this;
      }

      /**
       * @param  array<int|string, string>  $parameters
       */
      public function bodyParameters(array $parameters): self
      {
          $this->bodyValues = $parameters;
          $this->template->body($parameters);

          return $this;
      }

      public function addBodyParameter(string $value): self
      {
          return $this->bodyParameters([...$this->bodyValues, $value]);
      }

      /**
       * @param  array<int, string>  $parameters
       */
      public function buttonParameters(array $parameters): self
      {
          foreach ($parameters as $index => $value) {
              $this->template->urlButton((int) $index, (string) $value);
          }

          return $this;
      }

      public function addQuickReplyButton(int $index, string $payload): self
      {
          $this->template->quickReplyButton($index, $payload);

          return $this;
      }
  ```
- Delete `buildComponents()`. In `send()`, set `$components = $this->template->toComponents();` and `'template_parameters' => $this->template->toRecord(),`.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/TemplateBuilderTest.php tests/Feature/OutboundConversationLinkingTest.php`
Expected: PASS.

- [ ] **Step 5: Full suite, style, static analysis**

Run: `./vendor/bin/pest && ./vendor/bin/pint --test && ./vendor/bin/phpstan analyse`
Expected: all green. PHPStan may report the deprecated class being used in tests; that's fine. If it fails the build, add a targeted `ignoreErrors` entry for `TemplateBuilder` in `phpstan.neon`.

- [ ] **Step 6: Commit**

```bash
git add src/Support/TemplateBuilder.php tests/Feature/TemplateBuilderTest.php
git commit -m "Build TemplateBuilder on TemplateComponents and deprecate it

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Document templates and open PR B

**Files:**
- Modify: `README.md` (Sending Messages section, after the `// Send a location` example around line 225)
- Modify: `CLAUDE.md` (Common Patterns: add a template example)

- [ ] **Step 1: Add the README section**

Insert after the location example, inside the same PHP block:

````php
// Send a template. Positional parameters are a list; named ones ({{customer_name}})
// use string keys and go out as Meta's parameter_name.
WhatsApp::phone('support')
    ->to('+5511999999999')
    ->template('order_update')
    ->language('pt_BR')
    ->headerText('#42', name: 'order_id')        // or ->headerText('#42') for positional
    ->bodyParameters(['customer_name' => 'Marina'])
    ->copyCodeButton(0, 'CORBI10')                // index = the button's position in the template
    ->urlButton(1, 'PED-42')
    ->quickReplyButton(2, 'talk_to_human')
    ->send();

// Template media headers take a file (uploaded to Meta, sent by id, kept on the media disk
// like any outbound media), a Meta media id (digits only) or a URL.
WhatsApp::phone('support')
    ->to('+5511999999999')
    ->template('quote_ready')
    ->headerDocument($request->file('quote'), 'Orçamento.pdf')
    ->send();

// Anything the helpers don't cover: pass components exactly as Meta documents them.
WhatsApp::phone('support')
    ->to('+5511999999999')
    ->template('flash_sale')
    ->components([
        ['type' => 'limited_time_offer', 'parameters' => [
            ['type' => 'limited_time_offer', 'limited_time_offer' => ['expiration_time_ms' => 1767225600000]],
        ]],
    ])
    ->send();

// buttonParameters([1 => 'PED-42']) still works for URL buttons: its keys are the button indexes.
````

After the code block that contains it, add a paragraph:

```markdown
A sent template's `template_parameters` column records what was sent, in Meta's shape:
`header` is Meta's header parameter (e.g. `{type: 'document', document: {id, filename}}`),
`body` is the list of body parameters and `buttons` the list of button components. Rows
recorded before this version keep the old shape (`header` a string, `body` a list of strings,
`buttons` as `[index => text]`), so readers of that column should accept both. Synced
templates expose `parameter_format` and `usesNamedParameters()`.
```

- [ ] **Step 2: Add a template example to `CLAUDE.md`**

Under `## Common Patterns`, after "Building Complex Messages", add:

````markdown
### Sending Templates

```php
WhatsApp::phone('support')
    ->to($recipient)
    ->template('order_update')
    ->bodyParameters(['customer_name' => 'Marina']) // list = positional, string keys = named
    ->urlButton(1, 'PED-42')
    ->send();
```

Template state lives in `Support/TemplateComponents` in Meta's component shape; the payload
and `template_parameters` are both read from it.
````

- [ ] **Step 3: Final verification**

Run: `./vendor/bin/pest && ./vendor/bin/pint --test && ./vendor/bin/phpstan analyse`
Expected: all green.

- [ ] **Step 4: Commit and open PR B**

```bash
git add README.md CLAUDE.md
git commit -m "Document template parameters, buttons, header uploads and the record shape

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
git push -u origin claude/template-parameters
```

Open the PR against `main`. The body lists #45–#48, flags the `template_parameters` shape change for consumers (Corbi's reader must ship in the same deploy), and ends with `🤖 Generated with [Claude Code](https://claude.com/claude-code)`. Confirm the release versions with Rodrigo before tagging.
