<?php

/**
 * Handler for the built-in self-test ping. Logs the token and returns — the
 * job transitioning to `succeeded` is the assertion `hirale:queue:test` makes.
 */
class Hirale_Queue_Model_PingHandler
{
    public function __invoke(Hirale_Queue_Message_PingMessage $message): void
    {
        Mage::log(
            sprintf('hirale_queue ping ok token=%s', $message->token),
            \Monolog\Level::Info->value,
            'hirale_queue.log',
        );
    }
}
