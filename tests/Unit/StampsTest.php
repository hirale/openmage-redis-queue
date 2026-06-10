<?php

declare(strict_types=1);

namespace HiraleQueue\Tests\Unit;

use Hirale\Queue\Stamp\JobIdStamp;
use Hirale\Queue\Stamp\RetryPolicyStamp;
use Hirale\Queue\Stamp\StoreScopeStamp;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Stamp\StampInterface;

final class StampsTest extends TestCase
{
    public function testJobIdStampImplementsInterfaceAndIsReadonly(): void
    {
        $stamp = new JobIdStamp('abc123');
        self::assertInstanceOf(StampInterface::class, $stamp);
        self::assertSame('abc123', $stamp->jobId);
    }

    public function testStoreScopeStampAcceptsNullForGlobalScope(): void
    {
        self::assertNull((new StoreScopeStamp(null))->storeId);
        self::assertSame(7, (new StoreScopeStamp(7))->storeId);
    }

    public function testRetryPolicyStampCarriesAllThreeBounds(): void
    {
        $stamp = new RetryPolicyStamp(5, 10, 600);
        self::assertSame(5, $stamp->maxAttempts);
        self::assertSame(10, $stamp->backoffBaseSeconds);
        self::assertSame(600, $stamp->backoffCapSeconds);
    }
}
