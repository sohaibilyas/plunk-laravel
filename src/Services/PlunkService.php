<?php

namespace SohaibIlyas\PlunkLaravel\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class PlunkService
{
    public function __construct(
        protected readonly string $secretApiKey,
        protected readonly ?string $publicApiKey,
        protected readonly string $baseUrl,
        protected readonly string $endpoint = '/v1/send',
        protected readonly int $timeout = 15,
        protected readonly int $connectTimeout = 5,
    ) {}

    /**
     * Send a transactional email.
     *
     * @param  string|array<string|array{name: string, email: string}>  $to
     */
    public function sendEmail(string|array $to, string $subject, string $body, array $options = []): array
    {
        $payload = [
            'to' => $to,
            'subject' => $subject,
            'body' => $body,
        ];

        foreach (['from', 'name', 'reply', 'subscribed', 'headers', 'attachments'] as $field) {
            if (isset($options[$field])) {
                $payload[$field] = $options[$field];
            }
        }

        return $this->requestSecret('POST', $this->endpoint, $payload);
    }

    /**
     * Send an email using a Plunk template.
     *
     * @param  string|array<string|array{name: string, email: string}>  $to
     * @param  array<string, mixed>  $data  Template variables
     */
    public function sendTemplate(string|array $to, string $templateId, array $data = [], array $options = []): array
    {
        $payload = [
            'to' => $to,
            'template' => $templateId,
        ];

        if (! empty($data)) {
            $payload['data'] = $data;
        }

        foreach (['from', 'name', 'reply', 'subscribed', 'headers'] as $field) {
            if (isset($options[$field])) {
                $payload[$field] = $options[$field];
            }
        }

        return $this->requestSecret('POST', $this->endpoint, $payload);
    }

    /**
     * Track an event for a contact (auto-creates contact if needed).
     *
     * @param  array<string, mixed>  $data  Event data / contact metadata
     */
    public function trackEvent(string $email, string $event, array $data = [], bool $subscribed = true): array
    {
        $payload = [
            'email' => $email,
            'event' => $event,
            'subscribed' => $subscribed,
        ];

        if (! empty($data)) {
            $payload['data'] = $data;
        }

        return $this->requestPublic('POST', '/v1/track', $payload);
    }

    /**
     * Verify an email address.
     */
    public function verifyEmail(string $email): array
    {
        return $this->requestSecret('POST', '/v1/verify', ['email' => $email]);
    }

    protected function requestSecret(string $method, string $endpoint, array $data = []): array
    {
        return $this->dispatch($method, $endpoint, $this->secretApiKey, $data);
    }

    protected function requestPublic(string $method, string $endpoint, array $data = []): array
    {
        if ($this->publicApiKey === null || $this->publicApiKey === '') {
            throw new \RuntimeException(
                "Set PLUNK_PUBLIC_API_KEY to call {$endpoint}. "
                .'Public keys (pk_*) are required for event tracking.'
            );
        }

        return $this->dispatch($method, $endpoint, $this->publicApiKey, $data);
    }

    /**
     * Low-level transport request. The caller owns the stable retry identity.
     *
     * @param  array<string, mixed>  $payload
     */
    public function sendMail(array $payload, string $idempotencyKey): Response
    {
        return $this->request($this->secretApiKey)
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post(rtrim($this->baseUrl, '/').$this->endpoint, array_filter($payload, fn ($value): bool => $value !== null));
    }

    protected function request(string $apiKey): PendingRequest
    {
        if ($apiKey === '' || ! str_starts_with($this->baseUrl, 'https://')
            || $this->timeout <= 0 || $this->connectTimeout <= 0) {
            throw new \InvalidArgumentException('Plunk requires an API key, HTTPS base URL and positive timeouts.');
        }

        return Http::withToken($apiKey)->acceptJson()->asJson()
            ->timeout($this->timeout)->connectTimeout($this->connectTimeout)->withoutRedirecting();
    }

    protected function dispatch(string $method, string $endpoint, string $apiKey, array $data): array
    {
        try {
            $response = $this->request($apiKey)->$method(rtrim($this->baseUrl, '/').$endpoint, $data);
            if ($response->successful() && is_array($response->json())) {
                return $response->json();
            }

            return ['success' => false, 'error' => 'Plunk API error: HTTP '.$response->status()];
        } catch (ConnectionException) {
            return ['success' => false, 'error' => 'Plunk connection failed; request outcome is unknown.'];
        }
    }
}
