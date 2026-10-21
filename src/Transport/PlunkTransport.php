<?php

namespace SohaibIlyas\PlunkLaravel\Transport;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use SohaibIlyas\PlunkLaravel\Services\PlunkService;
use SohaibIlyas\PlunkLaravel\Support\PlunkMailContext;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;
use Symfony\Component\Mime\Part\DataPart;

class PlunkTransport extends AbstractTransport
{
    /**
     * @param  array{base_api_url: string, secret_key: ?string, timeout: int, connect_timeout: int, mail_max_job_age: int}  $config
     * @param  Closure(): PlunkMailContext  $context
     */
    public function __construct(private readonly array $config, private readonly Closure $context)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $this->validateConfiguration();
        $email = MessageConverter::toEmail($message->getOriginalMessage());

        if (count($email->getFrom()) !== 1 || count($email->getReplyTo()) > 1 || $email->getCc() || $email->getBcc()) {
            throw new TransportException('The Plunk API mailer requires one sender, at most one reply-to, and To recipients only. CC/BCC semantics are not supported.');
        }

        $body = $email->getHtmlBody();

        if ($body === null) {
            $text = $email->getTextBody();
            $text = is_resource($text) ? stream_get_contents($text) : ($text ?? '');
            $body = '<pre>'.htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</pre>';
        } elseif (is_resource($body)) {
            $body = stream_get_contents($body);
        }

        $identity = ($this->context)()->nextIdentity(
            $this->config['mail_max_job_age'],
            $email->getHeaders()->get('X-Plunk-Idempotency-Key')?->getBodyAsString(),
        );
        $headers = $this->emailHeaders($email);
        $attachments = array_map(fn (DataPart $part): array => $this->attachment($part), $email->getAttachments());
        $recipients = [];
        $namedRecipients = [];

        foreach ($email->getTo() as $recipient) {
            $namedRecipients[$recipient->getAddress()] = $recipient;
        }

        foreach ($message->getEnvelope()->getRecipients() as $recipient) {
            $recipients[$recipient->getAddress()] = $namedRecipients[$recipient->getAddress()] ?? $recipient;
        }

        foreach ($recipients as $recipient) {
            $key = 'plunk-laravel:'.hash('sha256', $identity.'|'.$recipient->getAddress());
            $client = new PlunkService(
                secretApiKey: $this->config['secret_key'],
                publicApiKey: null,
                baseUrl: rtrim($this->config['base_api_url'], '/'),
                endpoint: $this->config['endpoint'] ?? '/v1/send',
                timeout: $this->config['timeout'],
                connectTimeout: $this->config['connect_timeout'],
            );

            try {
                $response = $client->sendMail([
                    'to' => $this->address($recipient),
                    'subject' => $email->getSubject(),
                    'body' => $body,
                    'from' => $this->address($email->getFrom()[0]),
                    'reply' => ($email->getReplyTo()[0] ?? null)?->getAddress(),
                    'headers' => $headers ?: null,
                    'attachments' => $attachments,
                ], $key);
            } catch (ConnectionException) {
                throw new TransportException('Plunk connection failed. Delivery is unconfirmed; queued retries will reuse the same request key.');
            }

            if (! $response->successful()) {
                $originalStatus = $response->json('error.details.originalStatusCode');
                if ($response->status() === 409 && $response->json('error.code') === 'IDEMPOTENCY_KEY_REUSED'
                    && $response->json('error.details.key') === $key
                    && is_int($originalStatus) && $originalStatus >= 200 && $originalStatus < 300) {
                    continue;
                }

                throw new TransportException('Plunk did not confirm email acceptance (HTTP '.$response->status().').');
            }

            $result = $response->json();
            $providerId = $result['data']['emails'][0]['email'] ?? null;

            if (($result['success'] ?? false) !== true || ! is_string($providerId) || $providerId === '') {
                throw new TransportException('Plunk returned an unexpected response; email acceptance is unconfirmed.');
            }

            if (count($recipients) === 1) {
                $message->setMessageId($providerId);
            }
        }
    }

    private function validateConfiguration(): void
    {
        if (empty($this->config['secret_key']) || ! str_starts_with($this->config['base_api_url'], 'https://')) {
            throw new TransportException('Configure PLUNK_SECRET_KEY and an HTTPS PLUNK_BASE_API_URL before selecting the plunk mailer.');
        }

        if ($this->config['timeout'] <= 0 || $this->config['connect_timeout'] <= 0
            || $this->config['mail_max_job_age'] <= 0 || $this->config['mail_max_job_age'] > 82800) {
            throw new TransportException('Plunk timeouts must be positive and its safe queue age must not exceed 23 hours.');
        }
    }

    /** @return array{email: string, name: string} */
    private function address(Address $address): array
    {
        return ['email' => $address->getAddress(), 'name' => $address->getName()];
    }

    /** @return array<string, string> */
    private function emailHeaders(Email $email): array
    {
        $headers = [];
        $excluded = ['from', 'sender', 'to', 'cc', 'bcc', 'subject', 'reply-to', 'return-path', 'date', 'message-id', 'mime-version', 'content-type', 'content-transfer-encoding', 'x-plunk-idempotency-key'];

        foreach ($email->getHeaders()->all() as $header) {
            if (! in_array(strtolower($header->getName()), $excluded, true)) {
                $headers[$header->getName()] = $header->getBodyAsString();
            }
        }

        return $headers;
    }

    /** @return array{filename: string, content: string, contentType: string, disposition: string, contentId?: string} */
    private function attachment(DataPart $part): array
    {
        $attachment = [
            'filename' => $part->getFilename() ?? 'attachment',
            'content' => base64_encode($part->getBody()),
            'contentType' => $part->getMediaType().'/'.$part->getMediaSubtype(),
            'disposition' => $part->getDisposition() ?? 'attachment',
        ];

        if ($part->hasContentId()) {
            $attachment['contentId'] = $part->getContentId();
        }

        return $attachment;
    }

    public function __toString(): string
    {
        return 'plunk';
    }
}
