<?php

declare(strict_types=1);

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Multek\LaravelWhatsAppCloud\Client\WhatsAppClient;
use Multek\LaravelWhatsAppCloud\Exceptions\TemplateSyncException;
use Multek\LaravelWhatsAppCloud\Jobs\WhatsAppSyncTemplates;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppPhone;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppTemplate;

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
 * Fake the templates edge: each key is the `after` cursor ('first' = first page).
 *
 * @param  array<string, PromiseInterface>  $pages
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

it('throws when Meta repeats the same next page', function () {
    fakeTemplatePages([
        'first' => Http::response(['data' => [metaTemplate('1', 'welcome')], 'paging' => ['next' => nextPage('p2')]]),
        'p2' => Http::response(['data' => [metaTemplate('2', 'order_update')], 'paging' => ['next' => nextPage('p2')]]),
    ]);

    try {
        (new WhatsAppClient($this->phone))->getTemplates();
        $this->fail('Expected TemplateSyncException');
    } catch (TemplateSyncException $e) {
        expect($e->getMessage())->toContain('repeated');
    }

    Http::assertSentCount(2);
});

it('treats an empty data array as no templates', function () {
    fakeTemplatePages(['first' => Http::response(['data' => []])]);

    expect((new WhatsAppClient($this->phone))->getTemplates())->toBe([]);
});

it('throws when a successful page has no data array', function () {
    fakeTemplatePages(['first' => Http::response([])]);

    (new WhatsAppClient($this->phone))->getTemplates();
})->throws(TemplateSyncException::class, 'malformed');

it('does not disable local templates when Meta returns a page without data', function () {
    WhatsAppTemplate::create([
        'whatsapp_phone_id' => $this->phone->id, 'template_id' => '2', 'name' => 'order_update',
        'language' => 'pt_BR', 'category' => 'UTILITY', 'status' => 'APPROVED', 'components' => [],
    ]);
    fakeTemplatePages(['first' => Http::response([])]);

    expect(fn () => (new WhatsAppSyncTemplates($this->phone))->handle())->toThrow(TemplateSyncException::class);
    expect(WhatsAppTemplate::where('name', 'order_update')->value('status'))->toBe('APPROVED');
});

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

it('keeps syncing the other phones when one phone fails and exits with failure', function () {
    $second = WhatsAppPhone::create([
        'key' => 'second',
        'phone_id' => 'second_phone_id',
        'phone_number' => '+15557654321',
        'business_account_id' => 'second_waba',
        'is_active' => true,
    ]);

    $this->phone->update(['business_account_id' => 'first_waba']);

    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'first_waba')) {
            return Http::response(['error' => ['code' => 190, 'message' => 'bad token']], 500);
        }

        return Http::response(['data' => [metaTemplate('1', 'welcome')]]);
    });

    $this->artisan('whatsapp:sync-templates')->assertFailed();

    expect(WhatsAppTemplate::where('whatsapp_phone_id', $second->id)->where('name', 'welcome')->exists())->toBeTrue()
        ->and(WhatsAppTemplate::where('whatsapp_phone_id', $this->phone->id)->count())->toBe(0);
});

it('exits with success when every phone syncs', function () {
    fakeTemplatePages(['first' => Http::response(['data' => [metaTemplate('1', 'welcome')]])]);

    $this->artisan('whatsapp:sync-templates')->assertSuccessful();
});
