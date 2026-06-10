<?php

declare(strict_types=1);

namespace Hirale\Queue;

use Hirale\Queue\Stamp\RetryPolicyStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\RecoverableExceptionInterface;
use Symfony\Component\Messenger\Exception\UnrecoverableExceptionInterface;
use Symfony\Component\Messenger\Retry\RetryStrategyInterface;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;

/**
 * Full-jitter exponential backoff, as recommended by the AWS Architecture
 * Blog "Exponential Backoff And Jitter" article. The waiting time is a
 * uniformly random number in [0, min(cap, base * 2^attempt)].
 *
 * Policy is read from the envelope's RetryPolicyStamp — captured at dispatch
 * from the dispatching store's config. The strategy itself holds only the
 * fallback defaults used when no stamp is present (which should not happen
 * in normal operation; the JobRepositoryMiddleware always adds one).
 *
 * Standard Messenger exception markers are honored before the attempt
 * counter: a handler throwing UnrecoverableMessageHandlingException (or any
 * UnrecoverableExceptionInterface) fails the job immediately with no
 * retries; RecoverableExceptionInterface forces a retry regardless of the
 * attempt count. HandlerFailedException wrappers are unwrapped.
 */
final class RetryStrategy implements RetryStrategyInterface
{
    public function __construct(
        private readonly int $defaultMaxAttempts = 3,
        private readonly int $defaultBaseSeconds = 5,
        private readonly int $defaultCapSeconds = 3600,
    ) {
    }

    public function isRetryable(Envelope $message, ?\Throwable $throwable = null): bool
    {
        if ($throwable !== null && ($verdict = $this->markerVerdict($throwable)) !== null) {
            return $verdict;
        }
        return $this->currentAttempt($message) < $this->policyOf($message)['max'];
    }

    /**
     * Decide from Messenger's marker interfaces alone: true = force retry,
     * false = forbid retry, null = no marker applies (fall through to the
     * attempt counter). Mirrors Symfony's SendFailedMessageForRetryListener:
     * any recoverable nested exception forces a retry; a retry is forbidden
     * only when ALL nested exceptions are unrecoverable.
     */
    private function markerVerdict(\Throwable $throwable): ?bool
    {
        if ($throwable instanceof RecoverableExceptionInterface) {
            return true;
        }
        if ($throwable instanceof HandlerFailedException) {
            $allUnrecoverable = true;
            foreach ($throwable->getWrappedExceptions() as $nested) {
                if ($nested instanceof RecoverableExceptionInterface) {
                    return true;
                }
                if (!$nested instanceof UnrecoverableExceptionInterface) {
                    $allUnrecoverable = false;
                }
            }
            return $allUnrecoverable ? false : null;
        }
        if ($throwable instanceof UnrecoverableExceptionInterface) {
            return false;
        }
        return null;
    }

    public function getWaitingTime(Envelope $message, ?\Throwable $throwable = null): int
    {
        $policy = $this->policyOf($message);
        $attempt = $this->currentAttempt($message);
        $upperBound = min(
            $policy['cap'],
            $policy['base'] * (2 ** $attempt),
        );
        // Symfony's contract is milliseconds.
        return (int) (mt_rand(0, max(1, $upperBound)) * 1000);
    }

    /**
     * @return array{max: int, base: int, cap: int}
     */
    private function policyOf(Envelope $envelope): array
    {
        $stamp = $envelope->last(RetryPolicyStamp::class);
        return [
            'max'  => $stamp->maxAttempts        ?? $this->defaultMaxAttempts,
            'base' => $stamp->backoffBaseSeconds ?? $this->defaultBaseSeconds,
            'cap'  => $stamp->backoffCapSeconds  ?? $this->defaultCapSeconds,
        ];
    }

    private function currentAttempt(Envelope $envelope): int
    {
        // RedeliveryStamp::getRetryCount() returns the number of completed
        // retries; first delivery has no stamp. Attempt = retryCount + 1
        // matches our hirale_queue_job.attempt counter (1-indexed).
        $stamp = $envelope->last(RedeliveryStamp::class);
        return $stamp === null ? 0 : $stamp->getRetryCount();
    }
}
