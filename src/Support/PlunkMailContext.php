<?php

namespace SohaibIlyas\PlunkLaravel\Support;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportException;

class PlunkMailContext
{
    /** @var list<array{job: Job, uuid: string, createdAt: int, sequence: int}> */
    private array $jobs = [];

    public function begin(Job $job): void
    {
        $parent = array_key_last($this->jobs);
        $nested = $job instanceof SyncJob && $parent !== null;
        $uuid = $nested
            ? ($this->jobs[$parent]['uuid'] === '' ? '' : $this->jobs[$parent]['uuid'].':nested:'.++$this->jobs[$parent]['sequence'])
            : ($job->uuid() ?? '');
        $createdAt = $nested
            ? $this->jobs[$parent]['createdAt']
            : (int) ($job->payload()['plunk_created_at'] ?? $job->payload()['createdAt'] ?? 0);

        $this->jobs[] = [
            'job' => $job,
            'uuid' => $uuid,
            'createdAt' => $createdAt,
            'sequence' => 0,
        ];
    }

    public function end(Job $job): void
    {
        if (($this->jobs[array_key_last($this->jobs)]['job'] ?? null) === $job) {
            array_pop($this->jobs);
        }
    }

    /**
     * Queue retries reuse the job UUID and deterministic mail position. Separate
     * dispatches and separate synchronous sends must remain distinct intents.
     */
    public function nextIdentity(int $maxAgeSeconds, ?string $explicitKey = null): string
    {
        if ($explicitKey !== null && (trim($explicitKey) === '' || strlen($explicitKey) > 255)) {
            throw new TransportException('Plunk explicit idempotency keys must contain 1 to 255 bytes.');
        }

        if ($this->jobs === []) {
            return $explicitKey !== null ? 'explicit:'.$explicitKey : (string) Str::uuid();
        }

        $index = array_key_last($this->jobs);
        $job = $this->jobs[$index];

        if ($job['uuid'] === '' || $job['createdAt'] <= 0
            || time() - $job['createdAt'] >= $maxAgeSeconds) {
            throw new TransportException('Plunk mail job is outside its safe deduplication window or has no stable identity. Review its delivery status before dispatching a new job.');
        }

        return $explicitKey !== null ? 'explicit:'.$explicitKey : $job['uuid'].':'.++$this->jobs[$index]['sequence'];
    }
}
