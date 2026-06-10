<?php

/**
 * Job entity model. Extends Mage_Core_Model_Abstract so the admin grid can use
 * Mage::getResourceModel('hirale_queue/job_collection'). Operational mutations
 * go through Hirale_Queue_Model_JobRepository, not this class.
 */
class Hirale_Queue_Model_Job extends Mage_Core_Model_Abstract
{
    public const STATUS_QUEUED     = 'queued';
    public const STATUS_RUNNING    = 'running';
    public const STATUS_RETRY_WAIT = 'retry_wait';
    public const STATUS_SUCCEEDED  = 'succeeded';
    public const STATUS_FAILED     = 'failed';
    public const STATUS_CANCELED   = 'canceled';

    /**
     * @return array<string, string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_QUEUED     => 'Queued',
            self::STATUS_RUNNING    => 'Running',
            self::STATUS_RETRY_WAIT => 'Retry Wait',
            self::STATUS_SUCCEEDED  => 'Succeeded',
            self::STATUS_FAILED     => 'Failed',
            self::STATUS_CANCELED   => 'Canceled',
        ];
    }

    public static function isTerminal(string $status): bool
    {
        return in_array($status, [
            self::STATUS_SUCCEEDED,
            self::STATUS_FAILED,
            self::STATUS_CANCELED,
        ], true);
    }

    protected function _construct(): void
    {
        $this->_init('hirale_queue/job');
    }
}
