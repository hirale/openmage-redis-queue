<?php

declare(strict_types=1);

namespace HiraleQueue\Tests\Unit;

use Hirale\Queue\RetryStrategy;
use Hirale\Queue\Stamp\RetryPolicyStamp;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;

final class RetryStrategyTest extends TestCase
{
    public function testIsRetryableUsesStampMaxOverDefault(): void
    {
        $strategy = new RetryStrategy(defaultMaxAttempts: 1);
        $envelope = (new Envelope(new \stdClass()))
            ->with(new RetryPolicyStamp(maxAttempts: 5, backoffBaseSeconds: 1, backoffCapSeconds: 60))
            ->with(new RedeliveryStamp(3));
        self::assertTrue($strategy->isRetryable($envelope, new RuntimeException('boom')));
    }

    public function testIsRetryableReturnsFalseWhenAttemptsExhausted(): void
    {
        $strategy = new RetryStrategy();
        $envelope = (new Envelope(new \stdClass()))
            ->with(new RetryPolicyStamp(maxAttempts: 3, backoffBaseSeconds: 1, backoffCapSeconds: 60))
            ->with(new RedeliveryStamp(3));
        self::assertFalse($strategy->isRetryable($envelope, new RuntimeException('boom')));
    }

    public function testWaitingTimeUsesStampBaseAndCap(): void
    {
        $strategy = new RetryStrategy();
        $envelope = (new Envelope(new \stdClass()))
            ->with(new RetryPolicyStamp(maxAttempts: 10, backoffBaseSeconds: 2, backoffCapSeconds: 10))
            ->with(new RedeliveryStamp(5));

        // attempt=5, base=2, 2 * 2^5 = 64, capped at 10. Result is in [0, 10] seconds, ms-encoded.
        $waitMs = $strategy->getWaitingTime($envelope);
        self::assertGreaterThanOrEqual(0, $waitMs);
        self::assertLessThanOrEqual(10_000, $waitMs);
    }

    public function testFirstDeliveryHasNoRedeliveryStampAndIsRetryable(): void
    {
        $strategy = new RetryStrategy(defaultMaxAttempts: 3);
        $envelope = new Envelope(new \stdClass());
        // No RetryPolicyStamp, no RedeliveryStamp — uses defaults; current attempt = 0.
        self::assertTrue($strategy->isRetryable($envelope));
    }

    public function testUnrecoverableExceptionForbidsRetryEvenOnFirstAttempt(): void
    {
        $strategy = new RetryStrategy(defaultMaxAttempts: 3);
        $envelope = new Envelope(new \stdClass());
        self::assertFalse($strategy->isRetryable($envelope, new UnrecoverableMessageHandlingException('bad payload')));
    }

    public function testRecoverableExceptionForcesRetryEvenWhenAttemptsExhausted(): void
    {
        $strategy = new RetryStrategy();
        $envelope = (new Envelope(new \stdClass()))
            ->with(new RetryPolicyStamp(maxAttempts: 3, backoffBaseSeconds: 1, backoffCapSeconds: 60))
            ->with(new RedeliveryStamp(3));
        self::assertTrue($strategy->isRetryable($envelope, new RecoverableMessageHandlingException('lock contention')));
    }

    public function testHandlerFailedWrappingUnrecoverableForbidsRetry(): void
    {
        $strategy = new RetryStrategy(defaultMaxAttempts: 3);
        $envelope = new Envelope(new \stdClass());
        $wrapped  = new HandlerFailedException($envelope, [new UnrecoverableMessageHandlingException('bad payload')]);
        self::assertFalse($strategy->isRetryable($envelope, $wrapped));
    }

    public function testHandlerFailedWrappingPlainExceptionFallsThroughToCounter(): void
    {
        $strategy = new RetryStrategy(defaultMaxAttempts: 3);
        $envelope = new Envelope(new \stdClass());
        $wrapped  = new HandlerFailedException($envelope, [new RuntimeException('transient')]);
        self::assertTrue($strategy->isRetryable($envelope, $wrapped));
    }

    public function testWaitingTimeIsZeroToBaseOnFirstAttempt(): void
    {
        $strategy = new RetryStrategy();
        $envelope = (new Envelope(new \stdClass()))
            ->with(new RetryPolicyStamp(maxAttempts: 3, backoffBaseSeconds: 4, backoffCapSeconds: 100));

        // attempt=0, base=4, 4 * 2^0 = 4. Result in [0, 4] seconds.
        $waitMs = $strategy->getWaitingTime($envelope);
        self::assertLessThanOrEqual(4_000, $waitMs);
    }
}
