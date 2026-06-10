<?php

use Hirale\Queue\Bus;
use Hirale\Queue\MessageBusFactory;
use Hirale\Queue\MessageReconstructor;
use Hirale\Queue\Stamp\JobIdStamp;
use Hirale\Queue\TransportDsnBuilder;

/**
 * Admin controller backing System > Tools > Hirale Queue.
 *
 * Actions:
 *  - index           : grid + stats
 *  - retry           : re-dispatch a job (reconstructs message from payload_json)
 *  - cancel          : cooperative for running jobs, immediate for queued/retry_wait
 *  - purge           : run retention purge against the archive
 *  - testConnection  : ping the configured backend; same probe the save observer uses
 */
class Hirale_Queue_Adminhtml_QueueController extends Mage_Adminhtml_Controller_Action
{
    public function indexAction(): void
    {
        $this->loadLayout()
            ->_setActiveMenu('system/tools/hirale_queue')
            ->_title($this->__('System'))
            ->_title($this->__('Tools'))
            ->_title($this->__('Hirale Queue'));

        $this->_addContent($this->getLayout()->createBlock('hirale_queue/adminhtml_job_stats'));
        $this->_addContent($this->getLayout()->createBlock('hirale_queue/adminhtml_job'));

        $this->renderLayout();
    }

    public function viewAction(): void
    {
        $jobId = (string) $this->getRequest()->getParam('job_id');
        $row   = $this->jobRepository()->findByJobId($jobId);

        if ($row === null) {
            Mage::getSingleton('adminhtml/session')->addError($this->__('Job not found: %s', $jobId));
            $this->_redirect('*/*/index');
            return;
        }

        Mage::register('hirale_queue_current_job', $row);

        $this->loadLayout()
            ->_setActiveMenu('system/tools/hirale_queue')
            ->_title($this->__('System'))
            ->_title($this->__('Tools'))
            ->_title($this->__('Hirale Queue'))
            ->_title($jobId);

        $this->_addContent($this->getLayout()->createBlock('hirale_queue/adminhtml_job_view'));
        $this->renderLayout();
    }

    public function retryAction(): void
    {
        $jobId = (string) $this->getRequest()->getParam('job_id');
        $repo  = $this->jobRepository();
        $row   = $repo->findByJobId($jobId);

        if ($row === null) {
            Mage::getSingleton('adminhtml/session')->addError($this->__('Job not found: %s', $jobId));
            $this->_redirect('*/*/index');
            return;
        }

        try {
            $messageClass = (string) $row['message_class'];
            $payload      = json_decode((string) $row['payload_json'], true) ?: [];
            $message      = MessageReconstructor::reconstruct($messageClass, $payload);
            $envelope     = Bus::dispatch($message);
            $newJobId     = $envelope->last(JobIdStamp::class)?->jobId ?? '';
            // Close out the old row so it leaves the retryable set: pending
            // rows cancel directly, failed rows are marked superseded.
            if ($row['status'] === Hirale_Queue_Model_Job::STATUS_FAILED && $newJobId !== '') {
                $repo->markSuperseded($jobId, $newJobId);
            } else {
                $repo->markCanceledIfPending($jobId);
            }
            $this->audit()->record(Hirale_Queue_Model_Audit::ACTION_RETRY, $jobId);
            Mage::getSingleton('adminhtml/session')->addSuccess($this->__('Re-dispatched job %s.', $jobId));
        } catch (\Throwable $e) {
            Mage::logException($e);
            Mage::getSingleton('adminhtml/session')->addError($this->__('Retry failed: %s', $e->getMessage()));
        }

        $this->_redirect('*/*/index');
    }

    public function cancelAction(): void
    {
        $jobId = (string) $this->getRequest()->getParam('job_id');
        $repo  = $this->jobRepository();
        $row   = $repo->findByJobId($jobId);

        if ($row === null) {
            Mage::getSingleton('adminhtml/session')->addError($this->__('Job not found: %s', $jobId));
            $this->_redirect('*/*/index');
            return;
        }

        if ($row['status'] === Hirale_Queue_Model_Job::STATUS_RUNNING) {
            $affected = $repo->requestCancel($jobId);
            $message = $affected > 0
                ? $this->__('Cancellation requested. Handler will exit at the next safe boundary.')
                : $this->__('No running job to cancel; state may have changed.');
        } else {
            $canceled = $repo->markCanceledIfPending($jobId);
            $message = $canceled
                ? $this->__('Job canceled.')
                : $this->__('Cannot cancel a finished job.');
        }

        $this->audit()->record(Hirale_Queue_Model_Audit::ACTION_CANCEL, $jobId);
        Mage::getSingleton('adminhtml/session')->addSuccess($message);
        $this->_redirect('*/*/index');
    }

    public function purgeAction(): void
    {
        $helper = Mage::helper('hirale_queue');
        $repo   = $this->jobRepository();
        try {
            $thresholdSeconds = $helper->getArchiveRetentionDays() * 86400;
            $batchSize        = $helper->getArchiveBatchSize();
            $archived         = $repo->archiveFinished($thresholdSeconds, $batchSize);
            $purged           = $repo->purgeArchive(
                $helper->getSuccessRetentionDays(),
                $helper->getFailureRetentionDays(),
            );
            $this->audit()->record(Hirale_Queue_Model_Audit::ACTION_PURGE);
            Mage::getSingleton('adminhtml/session')->addSuccess(
                $this->__('Archived %d, purged %d rows.', $archived, $purged),
            );
        } catch (\Throwable $e) {
            Mage::logException($e);
            Mage::getSingleton('adminhtml/session')->addError($this->__('Purge failed: %s', $e->getMessage()));
        }
        $this->_redirect('*/*/index');
    }

    public function testConnectionAction(): void
    {
        // AJAX path: the Test Connection button in System Configuration posts
        // the on-screen form values so the operator can verify before saving.
        $groups = $this->getRequest()->getParam('groups');
        if (is_array($groups) && $groups !== []) {
            $this->testConnectionFromForm($groups);
            return;
        }

        try {
            $helper = Mage::helper('hirale_queue');
            $config = $helper->getBackendConfig();
            $queue  = $helper->getQueueList()[0] ?? 'default';

            // Build a real transport instance — exercises the same code path
            // that the bus uses, so success here means dispatch will work too.
            MessageBusFactory::reset();
            $transport = MessageBusFactory::getTransport($queue);
            if (!$transport instanceof \Symfony\Component\Messenger\Transport\TransportInterface) {
                throw new \RuntimeException('Transport factory returned wrong type.');
            }
            // Probing the connection by querying the message count is the
            // cheapest read that touches the network for most transports.
            if (method_exists($transport, 'getMessageCount')) {
                $transport->getMessageCount();
            }
            $this->audit()->record(Hirale_Queue_Model_Audit::ACTION_TEST_CONNECTION);
            Mage::getSingleton('adminhtml/session')->addSuccess(
                $this->__('Connected to %s backend (queue "%s").', $config['type'], $queue),
            );
        } catch (\Throwable $e) {
            Mage::logException($e);
            Mage::getSingleton('adminhtml/session')->addError($this->__('Connection failed: %s', $e->getMessage()));
        }
        $this->_redirect('*/*/index');
    }

    /**
     * @param array<string, mixed> $groups
     */
    private function testConnectionFromForm(array $groups): void
    {
        if (!$this->_validateFormKey()) {
            $this->getResponse()->setBodyJson([
                'success' => false,
                'message' => $this->__('Invalid form key. Reload the page and try again.'),
            ]);
            return;
        }
        try {
            $tester = Mage::getSingleton('hirale_queue/connectionTester');
            $config = $tester->buildBackendConfigFromForm($groups);
            $queues = $tester->parseQueueListFromForm($groups);
            // DSN assembly validates required fields for the selected backend.
            (new TransportDsnBuilder())->build($config, $queues[0]);
            $tester->probe($config, true);
            $this->audit()->record(Hirale_Queue_Model_Audit::ACTION_TEST_CONNECTION);
            $this->getResponse()->setBodyJson([
                'success' => true,
                'message' => $this->__('Connected to the %s backend (queue "%s").', (string) $config['type'], $queues[0]),
            ]);
        } catch (\Throwable $e) {
            $this->getResponse()->setBodyJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    protected function _isAllowed(): bool
    {
        return Mage::getSingleton('admin/session')->isAllowed('system/tools/hirale_queue');
    }

    private function jobRepository(): Hirale_Queue_Model_JobRepository
    {
        return Mage::getSingleton('hirale_queue/jobRepository');
    }

    private function audit(): Hirale_Queue_Model_Audit
    {
        return Mage::getSingleton('hirale_queue/audit');
    }
}
