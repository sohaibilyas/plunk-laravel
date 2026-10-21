<?php

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Message;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification as Notifications;
use Orchestra\Testbench\TestCase;
use SohaibIlyas\PlunkLaravel\PlunkServiceProvider;
use SohaibIlyas\PlunkLaravel\Support\PlunkMailContext;
use Symfony\Component\Mailer\Exception\TransportException;

class RetryPackageTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PlunkServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('mail.default', 'plunk');
        $app['config']->set('mail.mailers.plunk', ['transport' => 'plunk']);
        $app['config']->set('mail.from', ['address' => 'sender@example.com', 'name' => 'Sender']);
        $app['config']->set('plunk.secret_key', 'sk_test_only');
        $app['config']->set('plunk.base_api_url', 'https://plunk.test');
        $app['config']->set('queue.default', 'sync');
    }
}

class RetryTestMailable extends Mailable implements ShouldQueue
{
    public function build(): static
    {
        return $this->subject('Queued message')->html('<p>Queued body</p>');
    }
}

class RetryTestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Queued notification')->line('Notification body');
    }
}

uses(RetryPackageTestCase::class);

beforeEach(function () {
    Http::preventStrayRequests();
});

function acceptedPackageMail(): array
{
    return ['success' => true, 'data' => ['emails' => [['email' => 'provider-id']]]];
}

function packageMail(?string $key = null, array $to = ['recipient@example.com']): void
{
    Mail::html('<p>Message</p>', function (Message $mail) use ($key, $to) {
        $mail->to($to)->subject('Test');
        if ($key !== null) {
            $mail->getHeaders()->addTextHeader('X-Plunk-Idempotency-Key', $key);
        }
    });
}

it('sends actual queued mailables and notifications using the package provider and cleans context', function () {
    Http::fake(['plunk.test/*' => Http::response(acceptedPackageMail())]);
    Mail::to('recipient@example.com')->queue(new RetryTestMailable);
    Notifications::route('mail', 'recipient@example.com')->notify(new RetryTestNotification);
    Http::assertSentCount(2);
    $keys = Http::recorded()->map(fn ($r) => $r[0]->header('Idempotency-Key')[0]);
    expect($keys->unique())->toHaveCount(2);
    expect(app(PlunkMailContext::class)->nextIdentity(82800))->toMatch('/^[a-f0-9-]{36}$/');
});

it('replays the same real queue payload after response loss without a second accepted send', function () {
    $keys = [];
    Http::fake(function ($request) use (&$keys) {
        $keys[] = $request->header('Idempotency-Key')[0];
        if (count($keys) === 1) {
            throw new ConnectionException('Response lost after acceptance');
        }

        return Http::response(['error' => ['code' => 'IDEMPOTENCY_KEY_REUSED', 'details' => ['key' => $keys[0], 'originalStatusCode' => 200]]], 409);
    });
    $runner = new class extends SyncQueue
    {
        private ?string $savedPayload = null;

        protected function createPayload($job, $queue, $data = '', $delay = null)
        {
            return $this->savedPayload ??= parent::createPayload($job, $queue, $data, $delay);
        }
    };
    $runner->setContainer(app());
    $runner->setConnectionName('sync');
    $job = new SendQueuedMailable((new RetryTestMailable)->to('recipient@example.com'));
    expect(fn () => $runner->push($job))->toThrow(TransportException::class);
    $runner->push($job);
    expect($keys)->toHaveCount(2)->and($keys[0])->toBe($keys[1]);
    expect(app(PlunkMailContext::class)->nextIdentity(82800))->toMatch('/^[a-f0-9-]{36}$/');
});

it('uses stable explicit keys despite reordered sends and strips the private header', function () {
    Http::fake(['plunk.test/*' => Http::response(acceptedPackageMail())]);
    packageMail('invoice:42');
    packageMail('invoice:43');
    packageMail('invoice:43');
    packageMail('invoice:42');
    $requests = Http::recorded();
    expect($requests[0][0]->header('Idempotency-Key'))->toBe($requests[3][0]->header('Idempotency-Key'));
    expect($requests[1][0]->header('Idempotency-Key'))->toBe($requests[2][0]->header('Idempotency-Key'));
    Http::assertSent(fn ($r) => ! isset($r['headers']['X-Plunk-Idempotency-Key']));
});

it('keeps per-recipient keys when retrying partial delivery', function () {
    $keys = [];
    Http::fake(function ($request) use (&$keys) {
        $keys[] = $request->header('Idempotency-Key')[0];
        if (count($keys) === 2) {
            throw new ConnectionException('Response lost');
        }

        return Http::response(acceptedPackageMail());
    });
    expect(fn () => packageMail('batch:42', ['one@example.com', 'two@example.com']))->toThrow(TransportException::class);
    packageMail('batch:42', ['one@example.com', 'two@example.com']);
    expect($keys)->toHaveCount(4)->and($keys[0])->toBe($keys[2])->and($keys[1])->toBe($keys[3])->and($keys[0])->not->toBe($keys[1]);
});

it('only treats confirmed matching conflicts as accepted', function ($status, $code, $sameKey, $accept) {
    Http::fake(fn ($r) => Http::response(['error' => ['code' => $code, 'details' => ['key' => $sameKey ? $r->header('Idempotency-Key')[0] : 'wrong', 'originalStatusCode' => $status]]], 409));
    if ($accept) {
        packageMail();
    } else {
        expect(fn () => packageMail())->toThrow(TransportException::class);
    }
    Http::assertSentCount(1);
})->with([[200, 'IDEMPOTENCY_KEY_REUSED', true, true], [null, 'IDEMPOTENCY_KEY_REUSED', true, false], [500, 'IDEMPOTENCY_KEY_REUSED', true, false], [200, 'CONFLICT', true, false], [200, 'IDEMPOTENCY_KEY_REUSED', false, false]]);

it('rejects malformed success without hidden retries', function ($body, $status) {
    Http::fake(['plunk.test/*' => Http::response($body, $status)]);
    expect(fn () => packageMail())->toThrow(TransportException::class);
    Http::assertSentCount(1);
})->with([[[], 200], [['success' => true], 200], [['success' => false], 200], [['error' => 'rate limited'], 429], [['error' => 'unavailable'], 503]]);

it('uses parent retry identity for nested sync jobs whose UUID changes', function () {
    $context = app(PlunkMailContext::class);
    $outer = Mockery::mock(Job::class);
    $outer->shouldReceive('uuid')->andReturn('outer');
    $outer->shouldReceive('payload')->andReturn(['createdAt' => time()]);
    $context->begin($outer);
    $inner = new SyncJob(app(), json_encode(['uuid' => 'first', 'createdAt' => time()]), 'sync', 'default');
    $context->begin($inner);
    $first = $context->nextIdentity(82800);
    $context->end($inner);
    $context->end($outer);
    $context->begin($outer);
    $retry = new SyncJob(app(), json_encode(['uuid' => 'different', 'createdAt' => time()]), 'sync', 'default');
    $context->begin($retry);
    expect($context->nextIdentity(82800))->toBe($first);
});

it('fails old queued jobs before HTTP even when an explicit key is present', function () {
    Http::fake();
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('uuid')->andReturn('old-job');
    $job->shouldReceive('payload')->andReturn(['createdAt' => time() - 86400]);
    app(PlunkMailContext::class)->begin($job);
    expect(fn () => packageMail('invoice:42'))->toThrow(TransportException::class);
    Http::assertNothingSent();
});

it('does not invent a valid identity for a nested job whose parent has no UUID', function () {
    Http::fake();
    $context = app(PlunkMailContext::class);
    $outer = Mockery::mock(Job::class);
    $outer->shouldReceive('uuid')->andReturn(null);
    $outer->shouldReceive('payload')->andReturn(['createdAt' => time()]);
    $context->begin($outer);
    $context->begin(new SyncJob(app(), json_encode(['uuid' => 'child', 'createdAt' => time()]), 'sync', 'default'));
    expect(fn () => packageMail())->toThrow(TransportException::class);
    Http::assertNothingSent();
});
