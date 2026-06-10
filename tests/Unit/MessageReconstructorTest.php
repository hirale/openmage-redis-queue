<?php

declare(strict_types=1);

namespace HiraleQueue\Tests\Unit;

use Hirale\Queue\MessageReconstructor;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ReconstructorFixtureMessage
{
    public function __construct(
        public readonly string $reason,
        public readonly ?int $eventId = null,
        public readonly string $entity = 'catalog_product',
    ) {
    }
}

final class MessageReconstructorTest extends TestCase
{
    public function testReconstructsViaNamedConstructorArgs(): void
    {
        $message = MessageReconstructor::reconstruct(ReconstructorFixtureMessage::class, [
            'reason'  => 'index_events',
            'eventId' => 42,
            'entity'  => 'cms_page',
        ]);

        self::assertInstanceOf(ReconstructorFixtureMessage::class, $message);
        self::assertSame('index_events', $message->reason);
        self::assertSame(42, $message->eventId);
        self::assertSame('cms_page', $message->entity);
    }

    public function testOmittedOptionalArgsUseConstructorDefaults(): void
    {
        $message = MessageReconstructor::reconstruct(ReconstructorFixtureMessage::class, [
            'reason' => 'reconcile',
        ]);

        self::assertNull($message->eventId);
        self::assertSame('catalog_product', $message->entity);
    }

    public function testUnknownPayloadKeysFallBackToReflectionAndAreIgnored(): void
    {
        // 'legacy_field' is not a constructor arg → named-args path throws →
        // reflection fallback sets only matching properties.
        $message = MessageReconstructor::reconstruct(ReconstructorFixtureMessage::class, [
            'reason'       => 'index_events',
            'eventId'      => 7,
            'legacy_field' => 'dropped',
        ]);

        self::assertSame('index_events', $message->reason);
        self::assertSame(7, $message->eventId);
        self::assertFalse(property_exists($message, 'legacy_field'));
    }

    public function testMissingClassThrows(): void
    {
        $this->expectException(RuntimeException::class);
        MessageReconstructor::reconstruct('Vendor\\Gone\\Message', ['a' => 1]);
    }
}
