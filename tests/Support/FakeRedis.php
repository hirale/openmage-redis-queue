<?php

declare(strict_types=1);

namespace HiraleQueue\Tests\Support;

use Predis\Client;
use Throwable;

class FakeRedis extends Client
{
    /** @var list<array<int, mixed>> */
    public array $commands = [];

    /** @var list<mixed> */
    private array $responses = [];

    public function __construct()
    {
    }

    /**
     * @param mixed $response
     */
    public function queueResponse($response): void
    {
        $this->responses[] = $response;
    }

    /**
     * @param array<int, mixed> $arguments
     * @param bool|null $error
     * @return mixed
     */
    public function executeRaw(array $arguments, &$error = null)
    {
        $this->commands[] = $arguments;

        $response = array_shift($this->responses);
        if ($response instanceof Throwable) {
            throw $response;
        }

        return $response;
    }
}
