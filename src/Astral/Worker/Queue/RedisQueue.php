<?php

declare(strict_types=1);

namespace Astral\Worker\Queue;

use Throwable;

/**
 * File d'attente Redis (ext-redis / phpredis).
 *
 * Clés (préfixe configurable) :
 *   {p}ready:{queue}     LIST   jobs prêts
 *   {p}delayed:{queue}   ZSET   jobs différés, score = timestamp de disponibilité
 *   {p}reserved:{queue}  ZSET   jobs en cours, score = timestamp de réservation
 *   {p}attempts          HASH   id → nombre de tentatives
 *   {p}failed            HASH   id → JSON de l'échec
 *
 * Les transferts delayed/reserved → ready et la réservation passent par des scripts Lua
 * (atomiques) : plusieurs workers peuvent tourner en parallèle sans doublon.
 * Un job réservé depuis plus de $retryAfter secondes est remis en file.
 */
final class RedisQueue implements QueueInterface
{
    private const LUA_MIGRATE = <<<'LUA'
local jobs = redis.call('zrangebyscore', KEYS[1], '-inf', ARGV[1], 'LIMIT', 0, 100)
for _, job in ipairs(jobs) do
    redis.call('zrem', KEYS[1], job)
    redis.call('rpush', KEYS[2], job)
end
return #jobs
LUA;

    private const LUA_POP = <<<'LUA'
local job = redis.call('lpop', KEYS[1])
if job then
    redis.call('zadd', KEYS[2], ARGV[1], job)
end
return job
LUA;

    public function __construct(
        private \Redis $redis,
        private string $prefix = 'astral:queue:',
        private int $retryAfter = 90,
    ) {
    }

    public function push(string $jobClass, array $payload = [], int $delay = 0, string $queue = 'default'): string|int
    {
        $id  = bin2hex(random_bytes(8));
        $raw = json_encode([
            'id'         => $id,
            'queue'      => $queue,
            'class'      => $jobClass,
            'payload'    => $payload,
            'created_at' => time(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        if ($delay > 0) {
            $this->redis->zAdd($this->key('delayed', $queue), time() + $delay, $raw);
        } else {
            $this->redis->rPush($this->key('ready', $queue), $raw);
        }

        return $id;
    }

    public function pop(string $queue = 'default'): ?QueuedJob
    {
        $now      = time();
        $ready    = $this->key('ready', $queue);
        $delayed  = $this->key('delayed', $queue);
        $reserved = $this->key('reserved', $queue);

        $this->redis->eval(self::LUA_MIGRATE, [$delayed, $ready, (string) $now], 2);
        $this->redis->eval(self::LUA_MIGRATE, [$reserved, $ready, (string) ($now - $this->retryAfter)], 2);

        $raw = $this->redis->eval(self::LUA_POP, [$ready, $reserved, (string) $now], 2);

        if (!is_string($raw)) {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['id'], $data['class'])) {
            // Entrée corrompue : on la retire pour ne pas boucler dessus
            $this->redis->zRem($reserved, $raw);

            return null;
        }

        $attempts = (int) $this->redis->hIncrBy($this->key('attempts'), (string) $data['id'], 1);

        return new QueuedJob(
            id: (string) $data['id'],
            queue: (string) ($data['queue'] ?? $queue),
            class: (string) $data['class'],
            payload: is_array($data['payload'] ?? null) ? $data['payload'] : [],
            attempts: $attempts,
            raw: $raw,
        );
    }

    public function delete(QueuedJob $job): void
    {
        $this->redis->multi()
            ->zRem($this->key('reserved', $job->queue), $job->raw)
            ->hDel($this->key('attempts'), (string) $job->id)
            ->exec();
    }

    public function release(QueuedJob $job, int $delay = 0): void
    {
        $tx = $this->redis->multi()->zRem($this->key('reserved', $job->queue), $job->raw);

        if ($delay > 0) {
            $tx->zAdd($this->key('delayed', $job->queue), time() + $delay, $job->raw);
        } else {
            $tx->rPush($this->key('ready', $job->queue), $job->raw);
        }

        $tx->exec();
    }

    public function fail(QueuedJob $job, Throwable $e): void
    {
        $record = json_encode([
            'raw'       => $job->raw,
            'queue'     => $job->queue,
            'class'     => $job->class,
            'attempts'  => $job->attempts,
            'exception' => substr((string) $e, 0, 10000),
            'failed_at' => time(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $this->redis->multi()
            ->zRem($this->key('reserved', $job->queue), $job->raw)
            ->hDel($this->key('attempts'), (string) $job->id)
            ->hSet($this->key('failed'), (string) $job->id, $record)
            ->exec();
    }

    public function size(string $queue = 'default'): int
    {
        return (int) $this->redis->lLen($this->key('ready', $queue))
            + (int) $this->redis->zCard($this->key('delayed', $queue));
    }

    public function failed(int $limit = 50): array
    {
        $all  = $this->redis->hGetAll($this->key('failed'));
        $rows = [];

        if (!is_array($all)) {
            return [];
        }

        foreach ($all as $id => $json) {
            $data = json_decode((string) $json, true);
            if (!is_array($data)) {
                continue;
            }

            $rows[] = [
                'id'        => (string) $id,
                'queue'     => (string) ($data['queue'] ?? 'default'),
                'job_class' => (string) ($data['class'] ?? ''),
                'attempts'  => (int) ($data['attempts'] ?? 0),
                'exception' => (string) ($data['exception'] ?? ''),
                'failed_at' => (int) ($data['failed_at'] ?? 0),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $b['failed_at'] <=> $a['failed_at']);

        return array_slice($rows, 0, max(1, $limit));
    }

    public function retry(string|int $id): bool
    {
        $json = $this->redis->hGet($this->key('failed'), (string) $id);
        if (!is_string($json)) {
            return false;
        }

        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['raw'])) {
            $this->redis->hDel($this->key('failed'), (string) $id);

            return false;
        }

        $this->redis->multi()
            ->rPush($this->key('ready', (string) ($data['queue'] ?? 'default')), (string) $data['raw'])
            ->hDel($this->key('failed'), (string) $id)
            ->exec();

        return true;
    }

    public function retryAll(): int
    {
        $count = 0;
        $ids   = $this->redis->hKeys($this->key('failed'));

        foreach (is_array($ids) ? $ids : [] as $id) {
            if ($this->retry((string) $id)) {
                $count++;
            }
        }

        return $count;
    }

    public function flushFailed(): int
    {
        $count = (int) $this->redis->hLen($this->key('failed'));
        $this->redis->del($this->key('failed'));

        return $count;
    }

    private function key(string $type, string $queue = ''): string
    {
        return $this->prefix . $type . ($queue !== '' ? ':' . $queue : '');
    }
}
