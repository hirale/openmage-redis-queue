<?php

declare(strict_types=1);

class Hirale_Queue_Model_Job
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PUBLISHING = 'publishing';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_RUNNING = 'running';
    public const STATUS_RETRY_WAIT = 'retry_wait';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELED = 'canceled';

    public const DEFAULT_QUEUE = 'default';
    public const DEFAULT_MAX_ATTEMPTS = 3;
    public const DEFAULT_RETRY_DELAY = 60;
    public const DEFAULT_TIMEOUT = 60;

    /**
     * @return list<string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_QUEUED,
            self::STATUS_PUBLISHING,
            self::STATUS_PUBLISHED,
            self::STATUS_RUNNING,
            self::STATUS_RETRY_WAIT,
            self::STATUS_SUCCEEDED,
            self::STATUS_FAILED,
            self::STATUS_CANCELED,
        ];
    }
}
