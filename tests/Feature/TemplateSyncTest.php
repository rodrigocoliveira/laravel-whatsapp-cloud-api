<?php

declare(strict_types=1);

use GuzzleHttp\Promise\PromiseInterface;
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
