<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Support;

use Multek\LaravelWhatsAppCloud\Client\WhatsAppClientInterface;
use Multek\LaravelWhatsAppCloud\Events\MessageSent;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppConversation;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppMessage;
use Multek\LaravelWhatsAppCloud\Models\WhatsAppPhone;

/**
 * @deprecated Use WhatsApp::phone($key)->to($number)->template($name) instead; this class will be removed in the next major.
 */
class TemplateBuilder
{
    protected string $templateName;

    protected string $language = 'pt_BR';

    protected TemplateComponents $template;

    /** @var array<int|string, string> */
    protected array $bodyValues = [];

    protected ?WhatsAppConversation $conversation = null;

    public function __construct(
        protected WhatsAppPhone $phone,
        protected WhatsAppClientInterface $client,
        protected string $to,
    ) {
        $this->template = new TemplateComponents;
    }

    /**
     * Address the template to an existing conversation's contact and link it to that conversation.
     */
    public function conversation(WhatsAppConversation $conversation): self
    {
        $this->conversation = $conversation;
        $this->to = $conversation->contact_phone;

        return $this;
    }

    /**
     * Set the template name.
     */
    public function name(string $name): self
    {
        $this->templateName = $name;

        return $this;
    }

    /**
     * Set the template language.
     */
    public function language(string $code): self
    {
        $this->language = $code;

        return $this;
    }

    /**
     * Set header as text.
     */
    public function headerText(string $text): self
    {
        $this->template->headerText($text);

        return $this;
    }

    /**
     * Set header as image.
     */
    public function headerImage(string $url): self
    {
        $this->template->headerMedia('image', $url);

        return $this;
    }

    /**
     * Set header as video.
     */
    public function headerVideo(string $url): self
    {
        $this->template->headerMedia('video', $url);

        return $this;
    }

    /**
     * Set header as document.
     */
    public function headerDocument(string $url, ?string $filename = null): self
    {
        $this->template->headerMedia('document', $url, $filename);

        return $this;
    }

    /**
     * Set body parameters.
     *
     * @param  array<int|string, string>  $parameters
     */
    public function bodyParameters(array $parameters): self
    {
        $this->bodyValues = $parameters;
        $this->template->body($parameters);

        return $this;
    }

    /**
     * Add a body parameter.
     */
    public function addBodyParameter(string $value): self
    {
        return $this->bodyParameters([...$this->bodyValues, $value]);
    }

    /**
     * Set button parameters (for URL buttons with dynamic suffix).
     *
     * @param  array<int, string>  $parameters
     */
    public function buttonParameters(array $parameters): self
    {
        foreach ($parameters as $index => $value) {
            $this->template->urlButton((int) $index, (string) $value);
        }

        return $this;
    }

    /**
     * Add a quick reply button parameter.
     */
    public function addQuickReplyButton(int $index, string $payload): self
    {
        $this->template->quickReplyButton($index, $payload);

        return $this;
    }

    /**
     * Send the template message.
     */
    public function send(): WhatsAppMessage
    {
        $components = $this->template->toComponents();

        $result = $this->client->sendTemplate(
            $this->to,
            $this->templateName,
            $components,
            $this->language
        );

        $messageId = $result['messages'][0]['id'] ?? 'unknown_'.uniqid();

        $conversation = OutboundConversationResolver::resolve($this->phone, $this->to, $this->conversation);

        $message = WhatsAppMessage::create([
            'whatsapp_phone_id' => $this->phone->id,
            'whatsapp_conversation_id' => $conversation->id,
            'message_id' => $messageId,
            'direction' => WhatsAppMessage::DIRECTION_OUTBOUND,
            'type' => 'template',
            'from' => $this->phone->phone_number,
            'to' => $this->to,
            'content' => [
                'template' => [
                    'name' => $this->templateName,
                    'language' => $this->language,
                    'components' => $components,
                ],
            ],
            'status' => WhatsAppMessage::STATUS_PROCESSED,
            'delivery_status' => WhatsAppMessage::DELIVERY_STATUS_SENT,
            'sent_at' => now(),
            'template_name' => $this->templateName,
            'template_parameters' => $this->template->toRecord(),
        ]);

        event(new MessageSent($message));

        return $message;
    }
}
