<?php

declare(strict_types=1);

namespace Hirale\Queue\Middleware;

use Hirale\Queue\Exception\PayloadTooLargeException;
use Hirale\Queue\MessageBusFactory;
use Hirale\Queue\Stamp\JobIdStamp;
use Hirale\Queue\Stamp\RetryPolicyStamp;
use Hirale\Queue\Stamp\StoreScopeStamp;
use Mage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * Producer-side DB-observability middleware. On the FIRST dispatch (envelope
 * has no JobIdStamp and no ReceivedStamp), creates a hirale_queue_job row
 * and attaches the JobIdStamp so the rest of the pipeline can reference it.
 *
 * Consumer-side state transitions (running / succeeded / retry_wait / failed)
 * are NOT handled here; the worker EventDispatcher listeners in the consume
 * CLI command own that surface. Splitting producer-side from consumer-side
 * keeps each path simple and avoids double-recording during the consume loop.
 */
final class JobRepositoryMiddleware implements MiddlewareInterface
{
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        // Skip on the consumer side; the worker creates no new job rows.
        if ($envelope->last(ReceivedStamp::class) !== null) {
            return $stack->next()->handle($envelope, $stack);
        }
        // Idempotent — second pass through the bus on the same envelope.
        if ($envelope->last(JobIdStamp::class) !== null) {
            return $stack->next()->handle($envelope, $stack);
        }

        $message = $envelope->getMessage();
        $messageClass = $message::class;
        $payload = $this->encodeMessage($message);

        $storeStamp  = $envelope->last(StoreScopeStamp::class);

        // Enforce the configured payload ceiling before anything is persisted
        // or sent — oversized payloads fail fast at the producer.
        $payloadBytes = strlen((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $maxBytes     = MessageBusFactory::helper()->getPayloadMaxBytes($storeStamp?->storeId);
        if ($maxBytes > 0 && $payloadBytes > $maxBytes) {
            throw new PayloadTooLargeException(sprintf(
                '%s payload is %d bytes; limit is %d (hirale_queue/operational/payload_max_bytes).',
                $messageClass,
                $payloadBytes,
                $maxBytes,
            ));
        }
        $retryStamp  = $envelope->last(RetryPolicyStamp::class);
        $delayStamp  = $envelope->last(DelayStamp::class);
        $busNameStamp = $envelope->last(BusNameStamp::class);
        $transportStamp = $envelope->last(TransportNamesStamp::class);

        $queueName = 'default';
        if ($transportStamp !== null) {
            $names = $transportStamp->getTransportNames();
            if ($names !== []) {
                $queueName = reset($names);
            }
        }

        /** @var \Hirale_Queue_Model_JobRepository $repo */
        $repo = Mage::getSingleton('hirale_queue/jobRepository');
        $jobId = $repo->create(
            messageClass:     $messageClass,
            queueName:        $queueName,
            payload:          $payload,
            metadata:         ['bus' => $busNameStamp?->getBusName() ?? 'hirale_queue'],
            maxAttempts:      $retryStamp->maxAttempts        ?? MessageBusFactory::helper()->getRetryMaxAttempts(),
            retryBackoffBase: $retryStamp->backoffBaseSeconds ?? MessageBusFactory::helper()->getRetryBackoffBaseSeconds(),
            retryBackoffCap:  $retryStamp->backoffCapSeconds  ?? MessageBusFactory::helper()->getRetryBackoffCapSeconds(),
            storeId:          $storeStamp?->storeId,
            delaySeconds:     $delayStamp === null ? 0 : (int) ($delayStamp->getDelay() / 1000),
        );

        return $stack->next()->handle($envelope->with(new JobIdStamp($jobId)), $stack);
    }

    /**
     * Plain-array snapshot of public message state, suitable for JSON encoding
     * into hirale_queue_job.payload_json. We capture the public, scalar-ish
     * surface of the message class; Symfony's PhpSerializer keeps the real
     * envelope in the transport. The DB column is for operator observability.
     *
     * @return array<string, mixed>
     */
    private function encodeMessage(object $message): array
    {
        return get_object_vars($message);
    }
}
