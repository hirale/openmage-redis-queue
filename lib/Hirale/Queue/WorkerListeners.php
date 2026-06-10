<?php

declare(strict_types=1);

namespace Hirale\Queue;

use Hirale\Queue\Stamp\JobIdStamp;
use Mage;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;

/**
 * Worker event listeners that flip hirale_queue_job state on the envelope's
 * JobIdStamp and implement the retry/redelivery flow. Shared by the
 * `hirale:queue:consume` worker and the `hirale:queue:test` self-test so both
 * drive the exact same DB observability path.
 */
final class WorkerListeners
{
    /**
     * @param array<string, mixed> $receivers queueName => transport, as built by MessageBusFactory::getReceivers()
     */
    public static function attach(
        EventDispatcher $dispatcher,
        RetryStrategy $retryStrategy,
        array $receivers,
    ): void {
        self::attachStateTransitions($dispatcher);
        self::attachRetry($dispatcher, $retryStrategy, $receivers);
    }

    private static function attachStateTransitions(EventDispatcher $dispatcher): void
    {
        $jobRepo = self::jobRepository();

        $dispatcher->addListener(WorkerMessageReceivedEvent::class, function (WorkerMessageReceivedEvent $event) use ($jobRepo): void {
            $stamp = $event->getEnvelope()->last(JobIdStamp::class);
            if ($stamp === null) {
                return;
            }
            $jobRepo->transition($stamp->jobId, \Hirale_Queue_Model_Job::STATUS_RUNNING, [
                'started_at' => \Mage_Core_Model_Locale::nowUtc(),
            ]);
        });

        $dispatcher->addListener(WorkerMessageHandledEvent::class, function (WorkerMessageHandledEvent $event) use ($jobRepo): void {
            $stamp = $event->getEnvelope()->last(JobIdStamp::class);
            if ($stamp === null) {
                return;
            }
            $jobRepo->transition($stamp->jobId, \Hirale_Queue_Model_Job::STATUS_SUCCEEDED);
        });
    }

    /**
     * @param array<string, mixed> $receivers
     */
    private static function attachRetry(
        EventDispatcher $dispatcher,
        RetryStrategy $retryStrategy,
        array $receivers,
    ): void {
        $jobRepo = self::jobRepository();
        $dispatcher->addListener(
            WorkerMessageFailedEvent::class,
            function (WorkerMessageFailedEvent $event) use ($jobRepo, $retryStrategy, $receivers): void {
                $envelope     = $event->getEnvelope();
                $throwable    = $event->getThrowable();
                $transportKey = $event->getReceiverName();
                $stamp        = $envelope->last(JobIdStamp::class);
                $errorMessage = $throwable->getMessage();

                if ($stamp !== null) {
                    $jobRepo->setError($stamp->jobId, $errorMessage);
                }

                if (!$retryStrategy->isRetryable($envelope, $throwable)) {
                    if ($stamp !== null) {
                        $jobRepo->transition(
                            $stamp->jobId,
                            \Hirale_Queue_Model_Job::STATUS_FAILED,
                            [],
                            mb_substr($errorMessage, 0, 1024),
                        );
                    }
                    return;
                }

                $waitTimeMs = $retryStrategy->getWaitingTime($envelope, $throwable);
                $retryCount = ($envelope->last(RedeliveryStamp::class)?->getRetryCount() ?? 0) + 1;
                $retryEnvelope = $envelope
                    ->with(new DelayStamp($waitTimeMs))
                    ->with(new RedeliveryStamp($retryCount));

                if (isset($receivers[$transportKey])
                    && method_exists($receivers[$transportKey], 'send')
                ) {
                    $receivers[$transportKey]->send($retryEnvelope);
                }
                if ($stamp !== null) {
                    $jobRepo->transition(
                        $stamp->jobId,
                        \Hirale_Queue_Model_Job::STATUS_RETRY_WAIT,
                        ['attempt' => $retryCount],
                        mb_substr($errorMessage, 0, 1024),
                    );
                }
                $event->setForRetry();
            },
        );
    }

    private static function jobRepository(): \Hirale_Queue_Model_JobRepository
    {
        $repo = Mage::getSingleton('hirale_queue/jobRepository');
        if (!$repo instanceof \Hirale_Queue_Model_JobRepository) {
            throw new \RuntimeException('Hirale_Queue job repository is unavailable.');
        }
        return $repo;
    }
}
