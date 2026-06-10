<?php

/**
 * Cron jobs for hirale_queue. The single nightly archive job declared in
 * etc/config.xml::<crontab> runs archiveFinished() — moves finished rows
 * older than the retention threshold into hirale_queue_job_archive, then
 * purges old archive rows per the success/failure retention days.
 */
class Hirale_Queue_Model_Cron
{
    public function archiveFinished(): void
    {
        $helper = Mage::helper('hirale_queue');
        /** @var Hirale_Queue_Model_JobRepository $repo */
        $repo   = Mage::getSingleton('hirale_queue/jobRepository');

        try {
            $thresholdSeconds = $helper->getArchiveRetentionDays() * 86400;
            $batchSize        = $helper->getArchiveBatchSize();
            $archived         = $repo->archiveFinished($thresholdSeconds, $batchSize);
            $purged           = $repo->purgeArchive(
                $helper->getSuccessRetentionDays(),
                $helper->getFailureRetentionDays(),
            );
            $auditPurged      = Mage::getSingleton('hirale_queue/audit')
                ->purgeOlderThan($helper->getAuditRetentionDays());
            Mage::log(
                sprintf('hirale_queue cron: archived=%d purged=%d audit_purged=%d', $archived, $purged, $auditPurged),
                \Monolog\Level::Info->value,
                'hirale_queue.log',
            );
        } catch (\Throwable $e) {
            Mage::logException($e);
        }
    }
}
