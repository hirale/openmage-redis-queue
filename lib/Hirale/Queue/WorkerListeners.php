<?php

declare(strict_types=1);

namespace Hirale\Queue;

use Hirale\Queue\Stamp\JobIdStamp;
use Mage;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMemoryLimitListener;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Worker;

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

    /**
     * Stop conditions via Messenger's shipped listeners. The time limit is
     * NOT handled here — pass it as Worker::run(['time_limit' => N]); Worker
     * supports only `sleep`, `time_limit`, and `queues` run options and
     * silently ignores anything else.
     */
    public static function attachStopConditions(
        EventDispatcher $dispatcher,
        ?int $messageLimit = null,
        ?int $memoryLimitBytes = null,
    ): void {
        if ($messageLimit !== null && $messageLimit > 0) {
            $dispatcher->addSubscriber(new StopWorkerOnMessageLimitListener($messageLimit));
        }
        if ($memoryLimitBytes !== null && $memoryLimitBytes > 0) {
            $dispatcher->addSubscriber(new StopWorkerOnMemoryLimitListener($memoryLimitBytes));
        }
    }

    /**
     * Graceful SIGTERM/SIGINT shutdown: the worker finishes the in-flight
     * message and exits. No-op when ext-pcntl is unavailable.
     */
    public static function registerSignalHandlers(Worker $worker): void
    {
        if (!\function_exists('pcntl_signal')) {
            return;
        }
        pcntl_async_signals(true);
        foreach ([SIGTERM, SIGINT] as $signal) {
            pcntl_signal($signal, static function () use ($worker): void {
                $worker->stop();
            });
        }
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
                'started_at' => \gmdate('Y-m-d H:i:s'),
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
