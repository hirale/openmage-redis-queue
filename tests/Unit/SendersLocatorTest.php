<?php

declare(strict_types=1);

namespace HiraleQueue\Tests\Unit;

use Hirale\Queue\SendersLocator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

final class FixtureMessageA
{
}

final class FixtureMessageB
{
}

final class FixtureSender implements SenderInterface
{
    public function __construct(public string $label)
    {
    }

    public function send(Envelope $envelope): Envelope
    {
        return $envelope;
    }
}

final class SendersLocatorTest extends TestCase
{
    public function testRoutesMessageToConfiguredQueue(): void
    {
        $senders = [
            'default'      => new FixtureSender('default'),
            'full_reindex' => new FixtureSender('full_reindex'),
        ];
        $locator = new SendersLocator([
            FixtureMessageA::class => 'full_reindex',
        ], $senders);

        $names = $this->resolveNames($locator->getSenders(new Envelope(new FixtureMessageA())));
        self::assertSame(['full_reindex'], $names);
    }

    public function testUnmappedMessageRoutesToDefault(): void
    {
        $senders = [
            'default' => new FixtureSender('default'),
        ];
        $locator = new SendersLocator([], $senders);

        $names = $this->resolveNames($locator->getSenders(new Envelope(new FixtureMessageB())));
        self::assertSame(['default'], $names);
    }

    public function testTransportNamesStampOverridesRouting(): void
    {
        $senders = [
            'default' => new FixtureSender('default'),
            'high'    => new FixtureSender('high'),
            'low'     => new FixtureSender('low'),
        ];
        $locator = new SendersLocator([
            FixtureMessageA::class => 'default',
        ], $senders);

        $envelope = (new Envelope(new FixtureMessageA()))
            ->with(new TransportNamesStamp(['high', 'low']));

        $names = $this->resolveNames($locator->getSenders($envelope));
        self::assertSame(['high', 'low'], $names);
    }

    public function testUnknownQueueWithoutDefaultYieldsNothing(): void
    {
        $locator = new SendersLocator([
            FixtureMessageA::class => 'phantom',
        ], []);

        $names = $this->resolveNames($locator->getSenders(new Envelope(new FixtureMessageA())));
        self::assertSame([], $names);
    }

    /**
     * @param iterable<string, SenderInterface> $iter
     * @return list<string>
     */
    private function resolveNames(iterable $iter): array
    {
        $names = [];
        foreach ($iter as $name => $_sender) {
            $names[] = (string) $name;
        }
        return $names;
    }
}
