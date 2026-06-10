<?php

declare(strict_types=1);

/**
 * Built-in self-test message for `./maho hirale:queue:test`. Carries a random
 * token so the round-trip in the log is attributable to one test run.
 */
final readonly class Hirale_Queue_Message_PingMessage
{
    public function __construct(
        public string $token,
    ) {
    }
}
