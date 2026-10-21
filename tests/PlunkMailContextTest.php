<?php

use Illuminate\Contracts\Queue\Job;
use SohaibIlyas\PlunkLaravel\Support\PlunkMailContext;
use Symfony\Component\Mailer\Exception\TransportException;

function plunkContextJob(string $uuid, ?int $createdAt = null): Job
{
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('uuid')->andReturn($uuid);
    $job->shouldReceive('payload')->andReturn(['createdAt' => $createdAt ?? time()]);

    return $job;
}

it('reuses identities on retries but separates jobs and multiple sends', function () {
    $context = new PlunkMailContext;
    $firstJob = plunkContextJob('first-job');
    $context->begin($firstJob);
    $first = $context->nextIdentity(82800);
    $second = $context->nextIdentity(82800);
    $context->end($firstJob);
    $retry = plunkContextJob('first-job');
    $context->begin($retry);
    expect($context->nextIdentity(82800))->toBe($first)
        ->and($context->nextIdentity(82800))->toBe($second)
        ->and($first)->not->toBe($second);
    $context->end($retry);
    $context->begin(plunkContextJob('second-job'));
    expect($context->nextIdentity(82800))->not->toBe($first);
});

it('restores the outer job after nested synchronous jobs and clears completed context', function () {
    $context = new PlunkMailContext;
    $outer = plunkContextJob('outer');
    $inner = plunkContextJob('inner');
    $context->begin($outer);
    expect($context->nextIdentity(82800))->toBe('outer:1');
    $context->begin($inner);
    expect($context->nextIdentity(82800))->toBe('inner:1');
    $context->end($inner);
    expect($context->nextIdentity(82800))->toBe('outer:2');
    $context->end($outer);
    expect($context->nextIdentity(82800))->not->toStartWith('outer:');
});

it('does not deduplicate separate synchronous sends', function () {
    $context = new PlunkMailContext;
    expect($context->nextIdentity(82800))->not->toBe($context->nextIdentity(82800));
});

it('refuses mail without a safe queued identity', function (string $uuid, int $age) {
    $context = new PlunkMailContext;
    $context->begin(plunkContextJob($uuid, time() - $age));
    expect(fn () => $context->nextIdentity(82800))->toThrow(TransportException::class);
})->with(['expired' => ['old-job', 86400], 'missing UUID' => ['', 1]]);
