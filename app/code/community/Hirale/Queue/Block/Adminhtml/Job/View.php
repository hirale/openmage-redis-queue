<?php

/**
 * Job detail page: redacted payload, metadata, full field list, and the
 * state-transition timeline from hirale_queue_job_event. The job row is
 * registered by QueueController::viewAction under 'hirale_queue_current_job'.
 *
 * Payload and metadata pass through Hirale_Queue_Model_Redactor before
 * display — the stored rows are never modified.
 */
class Hirale_Queue_Block_Adminhtml_Job_View extends Mage_Adminhtml_Block_Widget_Container
{
    /** Same palette as the dashboard tiles (Job_Stats). */
    private const STATUS_COLORS = [
        Hirale_Queue_Model_Job::STATUS_QUEUED     => '#8fb6d1',
        Hirale_Queue_Model_Job::STATUS_RUNNING    => '#2186c4',
        Hirale_Queue_Model_Job::STATUS_RETRY_WAIT => '#e89c00',
        Hirale_Queue_Model_Job::STATUS_SUCCEEDED  => '#28a745',
        Hirale_Queue_Model_Job::STATUS_FAILED     => '#d9534f',
        Hirale_Queue_Model_Job::STATUS_CANCELED   => '#888',
    ];

    /** @var list<array<string, mixed>>|null */
    private ?array $_events = null;

    public function __construct()
    {
        parent::__construct();
        $this->setTemplate('hirale/queue/job/view.phtml');

        $helper = Mage::helper('hirale_queue');
        $job    = $this->getJob();
        $jobId  = (string) ($job['job_id'] ?? '');

        $this->_headerText = $helper->__('Queue Job %s', $jobId);

        $this->_addButton('back', [
            'label'   => $helper->__('Back'),
            'onclick' => "setLocation('" . $this->getUrl('*/*/index') . "')",
            'class'   => 'back',
        ]);
        $this->_addButton('retry', [
            'label'   => $helper->__('Retry'),
            'onclick' => "if (confirm('" . $this->jsQuoteEscape($helper->__('Re-dispatch this job as a new message?')) . "')) setLocation('"
                . $this->getUrl('*/*/retry', ['job_id' => $jobId]) . "')",
            'class'   => 'task',
        ]);
        if (!Hirale_Queue_Model_Job::isTerminal((string) ($job['status'] ?? ''))) {
            $this->_addButton('cancel', [
                'label'   => $helper->__('Cancel Job'),
                'onclick' => "if (confirm('" . $this->jsQuoteEscape($helper->__('Cancel this job?')) . "')) setLocation('"
                    . $this->getUrl('*/*/cancel', ['job_id' => $jobId]) . "')",
                'class'   => 'delete',
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getJob(): array
    {
        return (array) Mage::registry('hirale_queue_current_job');
    }

    public function getStatusLabel(): string
    {
        $status = (string) ($this->getJob()['status'] ?? '');
        return Hirale_Queue_Model_Job::statuses()[$status] ?? $status;
    }

    /**
     * Redacted, pretty-printed payload. Falls back to the raw column text when
     * it is not valid JSON (should not happen; defensive for hand-edited rows).
     */
    public function getPayloadJson(): string
    {
        return $this->redactedPretty((string) ($this->getJob()['payload_json'] ?? ''));
    }

    public function getMetadataJson(): ?string
    {
        $raw = $this->getJob()['metadata_json'] ?? null;
        if ($raw === null || $raw === '') {
            return null;
        }
        return $this->redactedPretty((string) $raw);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getEvents(): array
    {
        if ($this->_events !== null) {
            return $this->_events;
        }
        $jobId = (string) ($this->getJob()['job_id'] ?? '');
        if ($jobId === '') {
            return $this->_events = [];
        }
        return $this->_events = Mage::getSingleton('hirale_queue/jobRepository')->eventsForJob($jobId);
    }

    public function statusColor(string $status): string
    {
        return self::STATUS_COLORS[$status] ?? '#666';
    }

    public function statusLabel(string $status): string
    {
        return Hirale_Queue_Model_Job::statuses()[$status] ?? $status;
    }

    private function redactedPretty(string $raw): string
    {
        if ($raw === '') {
            return '';
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $raw;
        }
        $redacted = Mage::getSingleton('hirale_queue/redactor')->redact($decoded);
        return (string) json_encode(
            $redacted,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }
}
