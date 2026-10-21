<?php

namespace SohaibIlyas\PlunkLaravel\Tests;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Orchestra\Testbench\TestCase;
use SohaibIlyas\PlunkLaravel\PlunkServiceProvider;
use Symfony\Component\Mailer\Exception\TransportException;

class PlunkTransportTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PlunkServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('mail.default', 'plunk');
        $app['config']->set('mail.mailers.plunk', ['transport' => 'plunk']);
        $app['config']->set('plunk.secret_key', 'sk_test_key');
        $app['config']->set('plunk.public_key', 'pk_test_key');
        $app['config']->set('plunk.base_api_url', 'https://api.useplunk.com');
        $app['config']->set('plunk.endpoint', '/v1/send');
    }

    public function test_sends_email_through_laravel_mail(): void
    {
        Http::fake([
            'https://api.useplunk.com/v1/send' => Http::response(['success' => true, 'data' => ['emails' => [['email' => 'provider-id']]]]),
        ]);

        Mail::raw('Test body content', function ($message) {
            $message->to('recipient@example.com')
                ->from('sender@example.com', 'Sender Name')
                ->subject('Integration Test');
        });

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.useplunk.com/v1/send'
                && $request['subject'] === 'Integration Test'
                && $request['from']['email'] === 'sender@example.com'
                && $request['from']['name'] === 'Sender Name';
        });
    }

    public function test_sends_html_email(): void
    {
        Http::fake([
            'https://api.useplunk.com/v1/send' => Http::response(['success' => true, 'data' => ['emails' => [['email' => 'provider-id']]]]),
        ]);

        Mail::html('<h1>Hello</h1><p>World</p>', function ($message) {
            $message->to('recipient@example.com')
                ->from('sender@example.com')
                ->subject('HTML Test');
        });

        Http::assertSent(function ($request) {
            return str_contains($request['body'], '<h1>Hello</h1>');
        });
    }

    public function test_includes_reply_to(): void
    {
        Http::fake([
            'https://api.useplunk.com/v1/send' => Http::response(['success' => true, 'data' => ['emails' => [['email' => 'provider-id']]]]),
        ]);

        Mail::raw('Test', function ($message) {
            $message->to('recipient@example.com')
                ->from('sender@example.com')
                ->replyTo('support@example.com')
                ->subject('Reply-To Test');
        });

        Http::assertSent(function ($request) {
            return $request['reply'] === 'support@example.com';
        });
    }

    public function test_rejects_cc_and_bcc_instead_of_exposing_recipient_roles(): void
    {
        Http::fake([
            'https://api.useplunk.com/v1/send' => Http::response(['success' => true, 'data' => ['emails' => [['email' => 'provider-id']]]]),
        ]);

        $this->expectException(TransportException::class);
        Mail::raw('Test', function ($message) {
            $message->to('to@example.com')
                ->cc('cc@example.com')
                ->bcc('bcc@example.com')
                ->from('sender@example.com')
                ->subject('CC/BCC Test');
        });

    }

    public function test_includes_recipient_names(): void
    {
        Http::fake([
            'https://api.useplunk.com/v1/send' => Http::response(['success' => true, 'data' => ['emails' => [['email' => 'provider-id']]]]),
        ]);

        Mail::raw('Test', function ($message) {
            $message->to('recipient@example.com', 'John Doe')
                ->from('sender@example.com')
                ->subject('Named Recipient');
        });

        Http::assertSent(function ($request) {
            $to = $request['to'];

            return is_array($to)
                && $to['name'] === 'John Doe'
                && $to['email'] === 'recipient@example.com';
        });
    }

    public function test_sends_with_attachment(): void
    {
        Http::fake([
            'https://api.useplunk.com/v1/send' => Http::response(['success' => true, 'data' => ['emails' => [['email' => 'provider-id']]]]),
        ]);

        Mail::raw('Test with attachment', function ($message) {
            $message->to('recipient@example.com')
                ->from('sender@example.com')
                ->subject('Attachment Test')
                ->attachData('PDF file content here', 'document.pdf', [
                    'mime' => 'application/pdf',
                ]);
        });

        Http::assertSent(function ($request) {
            if (! isset($request['attachments']) || count($request['attachments']) !== 1) {
                return false;
            }
            $attachment = $request['attachments'][0];

            return $attachment['filename'] === 'document.pdf'
                && $attachment['contentType'] === 'application/pdf'
                && base64_decode($attachment['content']) === 'PDF file content here';
        });
    }

    public function test_throws_on_api_failure(): void
    {
        Http::fake([
            'https://api.useplunk.com/v1/send' => Http::response(
                ['success' => false, 'error' => ['code' => 'UNAUTHORIZED']],
                401,
            ),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Plunk did not confirm');

        Mail::raw('Test', function ($message) {
            $message->to('recipient@example.com')
                ->from('sender@example.com')
                ->subject('Error Test');
        });
    }
}
