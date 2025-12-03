# WhatsApp Business Calling API - Roadmap de Implementacao

## Visao Geral

A WhatsApp Business Calling API (lancada em 1 de Julho de 2025) permite chamadas VoIP entre empresas e usuarios do WhatsApp. A API segue o modelo "Bring Your Own VoIP" onde a Meta gerencia a conexao com o usuario e voce gerencia a conexao com seu sistema de voz.

### Arquitetura de Chamadas

```
┌─────────────────┐         ┌─────────────────┐         ┌─────────────────┐
│  WhatsApp User  │◄───────►│  Meta Servers   │◄───────►│  Seu Sistema    │
│  (App no cel)   │  Leg 1  │  (Cloud API)    │  Leg 2  │  (WebRTC/SIP)   │
└─────────────────┘         └─────────────────┘         └─────────────────┘
       WhatsApp Leg                                        Business Leg
```

### Tipos de Chamada

| Tipo | Descricao |
|------|-----------|
| **User-Initiated** | Usuario clica no icone de chamada no chat |
| **Business-Initiated** | Empresa solicita permissao, usuario aceita, empresa liga |

### Conexoes Suportadas

- WebRTC (browser/aplicacoes web)
- SIP (infraestrutura de telefonia existente)
- VoIP generico
- **PSTN NAO suportado** (nao conecta com telefone fixo/celular)

### Disponibilidade Regional

Disponivel na maioria dos paises Cloud API, **exceto**: USA, Canada, Turquia, Egito, Vietna, Nigeria.

---

## Fase 1: Infraestrutura Base

### 1.1 Modelo de Dados

Criar nova tabela `whatsapp_calls`:

```php
Schema::create('whatsapp_calls', function (Blueprint $table) {
    $table->id();
    $table->string('call_id')->unique()->index(); // ID da Meta
    $table->foreignId('phone_id')->constrained('whatsapp_phones');
    $table->foreignId('conversation_id')->nullable()->constrained('whatsapp_conversations');

    // Participantes
    $table->string('from'); // Numero de origem
    $table->string('to');   // Numero de destino

    // Tipo e direcao
    $table->enum('direction', ['user_initiated', 'business_initiated']);
    $table->enum('type', ['voice']); // Futuro: video

    // Status
    $table->enum('status', [
        'ringing',      // Chamada recebida, aguardando acao
        'pre_accepted', // pre_accept enviado
        'accepted',     // Chamada aceita, em andamento
        'rejected',     // Chamada rejeitada pela empresa
        'terminated',   // Chamada encerrada normalmente
        'missed',       // Chamada perdida
        'failed',       // Erro na chamada
    ])->default('ringing');

    // Timestamps da chamada
    $table->timestamp('started_at')->nullable();  // Quando conectou
    $table->timestamp('ended_at')->nullable();    // Quando encerrou
    $table->integer('duration_seconds')->nullable();

    // Dados tecnicos
    $table->text('sdp_offer')->nullable();   // SDP recebido da Meta
    $table->text('sdp_answer')->nullable();  // SDP enviado para Meta
    $table->json('metadata')->nullable();    // Dados extras

    // Erros
    $table->string('error_code')->nullable();
    $table->text('error_message')->nullable();

    $table->timestamps();

    $table->index(['phone_id', 'status']);
    $table->index(['from', 'created_at']);
});
```

### 1.2 Modelo Eloquent

```php
// src/Models/WhatsAppCall.php
class WhatsAppCall extends Model
{
    // Relacionamentos
    public function phone(): BelongsTo;
    public function conversation(): BelongsTo;

    // Helpers
    public function isActive(): bool;
    public function isUserInitiated(): bool;
    public function isBusinessInitiated(): bool;

    // Scopes
    public function scopeActive($query);
    public function scopeMissed($query);
    public function scopeByPhone($query, $phoneId);
}
```

---

## Fase 2: Webhooks de Chamadas

### 2.1 Estrutura do Webhook Recebido

```json
{
  "object": "whatsapp_business_account",
  "entry": [{
    "id": "BUSINESS_ACCOUNT_ID",
    "changes": [{
      "value": {
        "messaging_product": "whatsapp",
        "metadata": {
          "display_phone_number": "5511999999999",
          "phone_number_id": "PHONE_NUMBER_ID"
        },
        "contacts": [{
          "profile": { "name": "Usuario" },
          "wa_id": "5511888888888"
        }],
        "calls": [{
          "id": "wacid.CALL_ID_HERE",
          "from": "5511888888888",
          "to": "5511999999999",
          "event": "connect",
          "timestamp": "1753115233",
          "direction": "USER_INITIATED",
          "session": {
            "sdp": "v=0\r\no=- 1753115233717 2 IN IP4 127.0.0.1\r\n..."
          }
        }]
      },
      "field": "messages"
    }]
  }]
}
```

### 2.2 Eventos de Webhook

| Evento | Descricao |
|--------|-----------|
| `connect` | Nova chamada recebida (contem SDP offer) |
| `terminate` | Chamada encerrada |
| `status` | Atualizacao de status da chamada |

### 2.3 Processador de Webhooks

Estender `WebhookProcessor` ou criar `CallWebhookProcessor`:

```php
// src/Services/CallWebhookProcessor.php
class CallWebhookProcessor
{
    public function process(array $payload): void
    {
        $calls = $payload['entry'][0]['changes'][0]['value']['calls'] ?? [];

        foreach ($calls as $callData) {
            match ($callData['event']) {
                'connect' => $this->handleConnect($callData),
                'terminate' => $this->handleTerminate($callData),
                'status' => $this->handleStatus($callData),
            };
        }
    }

    protected function handleConnect(array $data): void
    {
        // 1. Criar registro WhatsAppCall
        // 2. Disparar evento CallReceived
        // 3. Notificar handler (se configurado)
    }
}
```

---

## Fase 3: Signaling API (Controle de Chamadas)

### 3.1 Fluxo de Signaling

```
Usuario liga
     │
     ▼
Webhook "connect" (com SDP offer)
     │
     ▼
Seu servidor processa
     │
     ├─► Rejeitar: POST /calls { action: "reject" }
     │
     └─► Aceitar:
            │
            ▼
         POST /calls { action: "pre_accept" }
            │
            ▼
         Preparar WebRTC/SIP (gerar SDP answer)
            │
            ▼
         POST /calls { action: "accept", sdp: "..." }
            │
            ▼
         Chamada conectada!
```

**IMPORTANTE**: `pre_accept` DEVE ser enviado antes de `accept`, caso contrario a API rejeita.

### 3.2 Servico de Chamadas

```php
// src/Services/CallService.php
class CallService
{
    public function __construct(
        protected WhatsAppClient $client
    ) {}

    /**
     * Pre-aceita uma chamada (obrigatorio antes de accept)
     */
    public function preAccept(WhatsAppCall $call): void
    {
        $this->client->post("/{$call->phone->phone_id}/calls", [
            'call_id' => $call->call_id,
            'action' => 'pre_accept',
        ]);

        $call->update(['status' => 'pre_accepted']);
        event(new CallPreAccepted($call));
    }

    /**
     * Aceita a chamada com SDP answer
     */
    public function accept(WhatsAppCall $call, string $sdpAnswer): void
    {
        if ($call->status !== 'pre_accepted') {
            throw new CallException('Must call preAccept() before accept()');
        }

        $this->client->post("/{$call->phone->phone_id}/calls", [
            'call_id' => $call->call_id,
            'action' => 'accept',
            'sdp' => $sdpAnswer,
        ]);

        $call->update([
            'status' => 'accepted',
            'sdp_answer' => $sdpAnswer,
            'started_at' => now(),
        ]);

        event(new CallAccepted($call));
    }

    /**
     * Rejeita uma chamada
     */
    public function reject(WhatsAppCall $call, ?string $reason = null): void
    {
        $this->client->post("/{$call->phone->phone_id}/calls", [
            'call_id' => $call->call_id,
            'action' => 'reject',
        ]);

        $call->update(['status' => 'rejected']);
        event(new CallRejected($call));
    }

    /**
     * Encerra uma chamada ativa
     */
    public function terminate(WhatsAppCall $call): void
    {
        $this->client->post("/{$call->phone->phone_id}/calls", [
            'call_id' => $call->call_id,
            'action' => 'terminate',
        ]);

        $call->update([
            'status' => 'terminated',
            'ended_at' => now(),
            'duration_seconds' => $call->started_at?->diffInSeconds(now()),
        ]);

        event(new CallTerminated($call));
    }
}
```

---

## Fase 4: Chamadas Business-Initiated

### 4.1 Fluxo de Permissao

```
Empresa quer ligar para usuario
          │
          ▼
Enviar template de permissao (durante conversa ativa)
          │
          ▼
Usuario recebe e aceita/rejeita
          │
          ├─► Rejeitou: Nao pode ligar
          │
          └─► Aceitou: Permissao valida por 72h
                  │
                  ▼
              Iniciar chamada
```

### 4.2 Limites de Permissao

- Maximo 1 solicitacao a cada 24 horas
- Maximo 2 solicitacoes em 7 dias
- Chamada deve ocorrer em ate 72 horas apos permissao
- Se 4 chamadas consecutivas forem perdidas/rejeitadas, permissao revogada

### 4.3 Metodos para Business-Initiated

```php
// src/Services/CallService.php

/**
 * Solicita permissao para ligar (via template)
 */
public function requestCallPermission(
    WhatsAppPhone $phone,
    string $to,
    string $templateName,
    array $templateParams = []
): void {
    // Envia template com botao de permissao de chamada
    // Template deve ter button type: VOICE_CALL
}

/**
 * Inicia chamada business-initiated (requer permissao previa)
 */
public function initiateCall(
    WhatsAppPhone $phone,
    string $to,
    string $sdpOffer
): WhatsAppCall {
    $response = $this->client->post("/{$phone->phone_id}/calls", [
        'to' => $to,
        'sdp' => $sdpOffer,
    ]);

    return WhatsAppCall::create([
        'call_id' => $response['call_id'],
        'phone_id' => $phone->id,
        'from' => $phone->phone_number,
        'to' => $to,
        'direction' => 'business_initiated',
        'status' => 'ringing',
        'sdp_offer' => $sdpOffer,
    ]);
}
```

---

## Fase 5: Sistema de Eventos

### 5.1 Novos Eventos

```php
// src/Events/Calls/
CallReceived::class      // Nova chamada recebida
CallPreAccepted::class   // pre_accept enviado
CallAccepted::class      // Chamada aceita e conectada
CallRejected::class      // Chamada rejeitada
CallTerminated::class    // Chamada encerrada normalmente
CallMissed::class        // Chamada perdida
CallFailed::class        // Erro na chamada

// Business-initiated
CallPermissionRequested::class  // Solicitacao de permissao enviada
CallPermissionGranted::class    // Usuario aceitou
CallPermissionDenied::class     // Usuario rejeitou
CallInitiated::class            // Chamada business-initiated iniciada
```

### 5.2 Estrutura dos Eventos

```php
// src/Events/Calls/CallReceived.php
class CallReceived
{
    public function __construct(
        public WhatsAppCall $call,
        public WhatsAppPhone $phone,
        public ?WhatsAppConversation $conversation,
        public string $sdpOffer,
    ) {}
}
```

---

## Fase 6: Handler Interface

### 6.1 Interface para Handlers de Chamada

```php
// src/Contracts/CallHandlerInterface.php
interface CallHandlerInterface
{
    /**
     * Chamado quando uma nova chamada e recebida.
     *
     * O handler deve:
     * 1. Decidir se aceita ou rejeita a chamada
     * 2. Se aceitar, gerar SDP answer e chamar $context->accept($sdp)
     * 3. Se rejeitar, chamar $context->reject()
     */
    public function handleIncomingCall(IncomingCallContext $context): void;

    /**
     * Chamado quando uma chamada e encerrada.
     */
    public function handleCallEnded(CallEndedContext $context): void;
}
```

### 6.2 Contexto de Chamada

```php
// src/DTOs/IncomingCallContext.php
class IncomingCallContext
{
    public function __construct(
        public WhatsAppCall $call,
        public WhatsAppPhone $phone,
        public ?WhatsAppConversation $conversation,
        protected CallService $callService,
    ) {}

    public function getSdpOffer(): string
    {
        return $this->call->sdp_offer;
    }

    public function getCallerNumber(): string
    {
        return $this->call->from;
    }

    public function preAccept(): void
    {
        $this->callService->preAccept($this->call);
    }

    public function accept(string $sdpAnswer): void
    {
        $this->callService->accept($this->call, $sdpAnswer);
    }

    public function reject(?string $reason = null): void
    {
        $this->callService->reject($this->call, $reason);
    }
}
```

---

## Fase 7: Configuracao

### 7.1 Adicoes ao config/whatsapp.php

```php
return [
    // ... configuracoes existentes ...

    /*
    |--------------------------------------------------------------------------
    | Calling Configuration
    |--------------------------------------------------------------------------
    */
    'calling' => [
        // Habilita funcionalidade de chamadas
        'enabled' => env('WHATSAPP_CALLING_ENABLED', false),

        // Handler padrao para chamadas (implementa CallHandlerInterface)
        'handler' => env('WHATSAPP_CALL_HANDLER'),

        // Aceitar chamadas automaticamente (util para integracao com IVR)
        'auto_accept' => env('WHATSAPP_CALL_AUTO_ACCEPT', false),

        // Timeout para pre_accept (segundos)
        'pre_accept_timeout' => 5,

        // Timeout para accept apos pre_accept (segundos)
        'accept_timeout' => 30,

        // Habilitar chamadas business-initiated
        'business_initiated' => [
            'enabled' => env('WHATSAPP_BUSINESS_CALLS_ENABLED', false),

            // Template padrao para solicitar permissao
            'permission_template' => env('WHATSAPP_CALL_PERMISSION_TEMPLATE'),
        ],

        // Configuracao por telefone (sobrescreve global)
        // Definido no banco em whatsapp_phones.settings
    ],
];
```

### 7.2 Configuracao por Telefone

Adicionar campo `call_settings` na tabela `whatsapp_phones` ou usar o `settings` existente:

```php
// Exemplo de settings no WhatsAppPhone
[
    'calling' => [
        'enabled' => true,
        'handler' => \App\Handlers\MyCallHandler::class,
        'auto_accept' => false,
    ],
]
```

---

## Fase 8: Integracao com WebRTC

### 8.1 Consideracoes

A lib **NAO implementa WebRTC** diretamente. Ela fornece:

1. **Signaling** - Receber SDP offer, enviar SDP answer
2. **Controle** - pre_accept, accept, reject, terminate
3. **Eventos** - Para seu sistema reagir

O **usuario da lib** deve:

1. Implementar WebRTC no frontend/sistema de voz
2. Gerar SDP answer baseado no SDP offer recebido
3. Gerenciar a conexao de midia

### 8.2 Exemplo de Integracao

```php
// No seu CallHandler
class MyCallHandler implements CallHandlerInterface
{
    public function handleIncomingCall(IncomingCallContext $context): void
    {
        // 1. Notificar seu sistema de voz (via websocket, etc)
        $this->voiceSystem->notifyIncomingCall(
            callId: $context->call->call_id,
            from: $context->getCallerNumber(),
            sdpOffer: $context->getSdpOffer(),
        );

        // 2. Pre-aceitar imediatamente (inicia o processo)
        $context->preAccept();

        // 3. O accept sera chamado quando seu sistema de voz
        //    gerar o SDP answer (via outro endpoint/job)
    }
}

// Endpoint no seu controller para receber SDP do seu sistema de voz
public function acceptCall(Request $request, string $callId)
{
    $call = WhatsAppCall::where('call_id', $callId)->firstOrFail();

    app(CallService::class)->accept($call, $request->input('sdp_answer'));

    return response()->json(['status' => 'accepted']);
}
```

---

## Fase 9: Estrutura de Diretorios

```
src/
├── Contracts/
│   └── CallHandlerInterface.php          # Interface para handlers
│
├── DTOs/
│   ├── IncomingCallContext.php           # Contexto de chamada recebida
│   └── CallEndedContext.php              # Contexto de chamada encerrada
│
├── Events/
│   └── Calls/
│       ├── CallReceived.php
│       ├── CallPreAccepted.php
│       ├── CallAccepted.php
│       ├── CallRejected.php
│       ├── CallTerminated.php
│       ├── CallMissed.php
│       ├── CallFailed.php
│       ├── CallPermissionRequested.php
│       ├── CallPermissionGranted.php
│       ├── CallPermissionDenied.php
│       └── CallInitiated.php
│
├── Exceptions/
│   └── CallException.php                 # Excecoes de chamada
│
├── Models/
│   └── WhatsAppCall.php                  # Modelo Eloquent
│
├── Services/
│   ├── CallService.php                   # Servico principal
│   └── CallWebhookProcessor.php          # Processador de webhooks
│
database/
└── migrations/
    └── create_whatsapp_calls_table.php
```

---

## Fase 10: Compatibilidade

### 10.1 Zero Breaking Changes

| Componente | Impacto |
|------------|---------|
| WebhookController | Adiciona deteccao de `calls`, roteia para CallWebhookProcessor |
| WebhookProcessor | Sem alteracao (continua processando mensagens) |
| WhatsAppManager | Adiciona metodo `call()` para iniciar chamadas |
| Configuracao | Novos campos opcionais, defaults seguros |
| Migrations | Nova tabela, nao altera existentes |

### 10.2 Feature Flag

Toda funcionalidade de chamadas desabilitada por padrao:

```php
// config/whatsapp.php
'calling' => [
    'enabled' => false, // Desabilitado por padrao
],
```

### 10.3 Migracao Opcional

```bash
# Publicar apenas migration de calls
php artisan vendor:publish --tag=whatsapp-calls-migrations
```

---

## Limitacoes Conhecidas

1. **PSTN nao suportado** - Nao conecta com telefones fixos/celulares via rede telefonica
2. **Video nao disponivel** - API atual suporta apenas voz
3. **Paises bloqueados** - USA, Canada, Turquia, Egito, Vietna, Nigeria
4. **Limite de chamadas simultaneas** - Ate 1000 por numero
5. **Gravacao nao nativa** - Precisa implementar no seu sistema de voz

---

## Referencias

- [WhatsApp Business Calling API Blog](https://business.whatsapp.com/blog/whatsapp-business-calling-api)
- [Meta Developer Docs](https://developers.facebook.com/docs/whatsapp/cloud-api/calling/)
- [Infobip Guide](https://www.infobip.com/blog/whatsapp-business-calling-api-guide)
- [Twilio Integration](https://www.twilio.com/docs/voice/whatsapp-business-calling)

---

## Proximos Passos

1. [ ] Validar estrutura de webhooks com ambiente de teste
2. [ ] Criar migration e modelo WhatsAppCall
3. [ ] Implementar CallWebhookProcessor
4. [ ] Implementar CallService
5. [ ] Criar eventos de chamada
6. [ ] Criar CallHandlerInterface e contextos
7. [ ] Adicionar configuracoes
8. [ ] Integrar com WebhookController existente
9. [ ] Testes unitarios e de integracao
10. [ ] Documentacao no README
