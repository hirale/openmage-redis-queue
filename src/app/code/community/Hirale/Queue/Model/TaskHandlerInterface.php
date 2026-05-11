<?php

declare(strict_types=1);

interface Hirale_Queue_Model_TaskHandlerInterface
{
    /*
     * Marker only: 1.x handlers commonly declare handle($data), while newer
     * handlers may use a typed handle(array $task): void signature.
     */
}
