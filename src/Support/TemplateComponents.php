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
            $type = strtolower((string) ($component['type'] ?? ''));
            switch ($type) {
                case 'header':
                    $this->rawHeader($component);
                    break;
                case 'body':
                    $this->body = array_values($component['parameters'] ?? []);
                    break;
                case 'button':
                    $this->addButton($component);
                    break;
                default:
                    $this->extra[] = $component;
            }
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
