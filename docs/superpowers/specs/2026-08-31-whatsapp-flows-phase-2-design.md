# WhatsApp Flows — Phase 2 (Endpoint Flows) Design

Status: proposed. Follow-up to issue #4; Phase 1 (static Flows) shipped separately.

## Goal

Support Flows whose screens call back to our server between steps (`flow_action:
data_exchange`). Meta posts an encrypted request to a business endpoint; the endpoint
must decrypt it, decide the next screen, and answer with an encrypted response.

## Scope

In scope:

- A data-exchange endpoint with the full Meta crypto handshake.
- Private/public key management (config + a command to upload the public key).
- `ping` health checks and client error-notification requests.
- An application-facing contract for deciding the next screen.

Out of scope (deliberate):

- Flow JSON authoring helpers. Publishing/updating Flow JSON through the Graph API is a
  separate, independently useful feature; it does not block endpoint Flows. Track it as
  its own issue.

## Crypto handshake

Meta posts `{encrypted_flow_data, encrypted_aes_key, initial_vector}`, all base64.

1. RSA-decrypt `encrypted_aes_key` with the business private key — OAEP, SHA-256 for both
   the digest and MGF1.
2. AES-128-GCM-decrypt `encrypted_flow_data` with that key and `initial_vector`. The last
   16 bytes of the ciphertext are the auth tag.
3. Handle the decrypted JSON: `{version, action, screen?, data?, flow_token?}`.
4. Encrypt the response with the same AES key, using the bitwise-flipped IV
   (`~iv`, byte by byte), and return base64 of `ciphertext || tag` as a plain-text body
   (not JSON).

PHP has everything needed: `openssl_private_decrypt` with `OPENSSL_PKCS1_OAEP_PADDING`
does not allow choosing SHA-256, so use `openssl_pkey_get_private` plus the `sodium`/
`phpseclib` route, or `openssl_private_decrypt` via a `RSA/ECB/OAEPWithSHA-256AndMGF1Padding`
implementation. **Decide this during implementation with a spike** — it is the one
genuinely uncertain piece. `phpseclib/phpseclib` v3 supports OAEP with an explicit hash
and MGF1 hash and is the likely answer; adding it is an acceptable dependency.

Error contract: any decryption failure must return HTTP 421 (Meta then refreshes the
public key), and any other failure HTTP 500 with no body. Never leak details.

## Components

| Component | Responsibility |
|---|---|
| `FlowEncryptionService` | Decrypt request, encrypt response. Pure, no HTTP. Fully unit-testable with a generated key pair. |
| `FlowEndpointController` | Route the decrypted request: `ping` → health, `error` notification → log + ack, `INIT`/`BACK`/`data_exchange` → handler. |
| `FlowHandlerInterface` | `handle(FlowRequest $request): FlowResponse` — what the app implements. |
| `FlowRequest` / `FlowResponse` DTOs | `version`, `action`, `screen`, `data`, `flow_token`; response carries `screen` + `data`, or the terminal `SUCCESS` payload. |
| `whatsapp:flow-key` command | Generate a key pair and/or upload the public key to the phone number via `POST /{phone_id}/whatsapp_business_encryption`. |

## Configuration

```php
'flows' => [
    'private_key' => env('WHATSAPP_FLOW_PRIVATE_KEY'),           // PEM, or a path
    'private_key_passphrase' => env('WHATSAPP_FLOW_PRIVATE_KEY_PASSPHRASE'),
    'endpoint_enabled' => env('WHATSAPP_FLOW_ENDPOINT_ENABLED', false),
    'endpoint_path' => 'webhooks/whatsapp/flow',
    'handler' => null,                                            // FlowHandlerInterface
],
```

Per-phone keys are a plausible future need (the key is registered per phone number), so
resolve the key through the phone when one is set on `whatsapp_phones.flow_private_key`,
falling back to config. Add that nullable column in the same migration that is otherwise
not needed.

## Routing

Register the endpoint route only when `endpoint_enabled` is true, alongside the existing
webhook routes. It does **not** use `VerifyWhatsAppSignature` — authenticity comes from
the fact that only Meta can encrypt with our public key. Keep the route stateless and
exempt from CSRF.

## Testing

- Unit: generate an RSA key pair in the test, encrypt a request the way Meta does, assert
  round-trip decrypt/encrypt including the flipped IV and the auth tag placement.
- Feature: `ping` returns the `active` health response; a malformed body returns 421; a
  registered handler receives the decoded `FlowRequest` and its `FlowResponse` comes back
  encrypted and decryptable with the same AES key.

## Risks

- The OAEP-SHA256 route is the main unknown; spike it first.
- Meta refreshes the public key on 421, so an accidental 421 loop is possible. Log every
  421 with a reason so it can be diagnosed.
