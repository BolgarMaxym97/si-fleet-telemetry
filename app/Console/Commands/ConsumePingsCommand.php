<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Services\StorePingsService;
use App\Console\Support\PingBatch;
use App\Domain\Exceptions\InvalidPingException;
use App\Domain\Ping;
use App\Infrastructure\Metrics\PipelineMetrics;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Junges\Kafka\Contracts\ConsumerMessage;
use Junges\Kafka\Contracts\MessageConsumer;
use Junges\Kafka\Facades\Kafka;

final class ConsumePingsCommand extends Command
{
    protected $signature = 'kafka:consume-pings';

    protected $description = 'Consume vehicle.pings from Kafka into TimescaleDB + Redis';

    private PingBatch $batch;

    private ?MessageConsumer $consumer = null;

    public function __construct(private readonly PipelineMetrics $metrics)
    {
        parent::__construct();

        $this->batch = new PingBatch;
    }

    public function handle(StorePingsService $store): int
    {
        $topic = (string) config('telemetry.kafka_topic');
        $group = (string) config('telemetry.kafka_group');

        $this->info("Consuming '{$topic}' as group '{$group}'...");

        // Manual commit: offsets advance only after a batch is durably stored, so
        // a crash leaves the un-flushed tail uncommitted and Kafka redelivers it.
        // The (vehicle_id, recorded_at) dedup absorbs that redelivery — no loss.
        $this->consumer = Kafka::consumer([$topic])
            ->withConsumerGroupId($group)
            ->withManualCommit()
            ->withHandler(function (ConsumerMessage $message, MessageConsumer $consumer) use ($store): void {
                $this->onMessage($message, $consumer, $store);
            })
            ->onStopConsuming(function () use ($store): void {
                // Drain the partial buffer on SIGTERM/SIGINT before exit.
                if ($this->consumer !== null) {
                    $this->flush($this->consumer, $store);
                }
            })
            ->build();

        $this->consumer->consume();

        return self::SUCCESS;
    }

    private function onMessage(ConsumerMessage $message, MessageConsumer $consumer, StorePingsService $store): void
    {
        $this->metrics->consumed();

        $body = $message->getBody();
        if (is_string($body)) {
            $body = json_decode($body, true);
        }

        if (! is_array($body)) {
            $this->metrics->invalid();
            Log::warning('kafka.ping.malformed', ['offset' => $message->getOffset()]);

            return;
        }

        try {
            $ping = Ping::fromArray($body);
        } catch (InvalidPingException $e) {
            // Bridge already DLQs at its boundary; we just refuse to crash.
            $this->metrics->invalid();
            Log::warning('kafka.ping.invalid', [
                'offset' => $message->getOffset(),
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $this->batch->add($ping, microtime(true));

        // Age flush only fires on the next message — v2.11.3 has no idle-tick hook.
        // During a lull a partial buffer waits; with manual commit that is latency,
        // never loss.
        $maxSize = (int) config('telemetry.kafka_batch_size');
        $maxAge = (int) config('telemetry.kafka_batch_max_ms') / 1000;

        if ($this->batch->shouldFlush($maxSize, $maxAge, microtime(true))) {
            $this->flush($consumer, $store);
        }
    }

    private function flush(MessageConsumer $consumer, StorePingsService $store): void
    {
        $pings = $this->batch->drain();
        if ($pings === []) {
            return;
        }

        // Store first (source of truth), then commit. If storeBatch throws, the
        // offset stays uncommitted and the batch is redelivered (dedup absorbs it).
        $store->storeBatch($pings);
        $consumer->commit();
    }
}
