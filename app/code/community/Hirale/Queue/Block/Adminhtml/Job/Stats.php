<?php

/**
 * Dashboard rendered above the job grid: status-total tiles plus per-queue
 * backlog cards in the same visual language. Renders inline HTML rather than
 * templated for v3.0; minimal coupling, easy to restyle later.
 */
class Hirale_Queue_Block_Adminhtml_Job_Stats extends Mage_Adminhtml_Block_Template
{
    private const TILE_COLORS = [
        Hirale_Queue_Model_Job::STATUS_QUEUED     => '#8fb6d1',
        Hirale_Queue_Model_Job::STATUS_RUNNING    => '#2186c4',
        Hirale_Queue_Model_Job::STATUS_RETRY_WAIT => '#e89c00',
        Hirale_Queue_Model_Job::STATUS_SUCCEEDED  => '#28a745',
        Hirale_Queue_Model_Job::STATUS_FAILED     => '#d9534f',
        Hirale_Queue_Model_Job::STATUS_CANCELED   => '#888',
    ];

    /** Oldest-active-job age at which a queue is flagged amber / red. */
    private const AGE_WARN_SECONDS = 300;
    private const AGE_CRIT_SECONDS = 1800;

    protected function _toHtml(): string
    {
        /** @var Hirale_Queue_Model_Stats $stats */
        $stats  = Mage::getSingleton('hirale_queue/stats');
        $helper = Mage::helper('hirale_queue');

        return '<div style="margin:0 0 18px 0;font-family:Arial,sans-serif;">'
            . $this->renderSectionLabel((string) $helper->__('Job Totals'))
            . $this->renderStatusTiles($stats->totalsByStatus())
            . $this->renderSectionLabel((string) $helper->__('Queues'))
            . $this->renderQueueCards($stats->perQueueDepth())
            . '</div>';
    }

    private function renderSectionLabel(string $label): string
    {
        return sprintf(
            '<div style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#999;margin:10px 0 6px;">%s</div>',
            htmlspecialchars($label, ENT_QUOTES, 'UTF-8'),
        );
    }

    /**
     * @param array<string, int> $totals
     */
    private function renderStatusTiles(array $totals): string
    {
        $tiles = '';
        foreach (Hirale_Queue_Model_Job::statuses() as $statusKey => $statusLabel) {
            $count = (int) ($totals[$statusKey] ?? 0);
            $color = self::TILE_COLORS[$statusKey] ?? '#666';
            $tiles .= sprintf(
                '<div style="min-width:130px;padding:10px 14px;background:%s;color:#fff;border-radius:6px;">'
                . '<div style="font-size:11px;text-transform:uppercase;opacity:.85;">%s</div>'
                . '<div style="font-size:22px;font-weight:bold;line-height:1.2;">%d</div>'
                . '</div>',
                htmlspecialchars($color, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars((string) $statusLabel, ENT_QUOTES, 'UTF-8'),
                $count,
            );
        }
        return '<div style="display:flex;flex-wrap:wrap;gap:8px;">' . $tiles . '</div>';
    }

    /**
     * One card per queue, styled to match the status tiles: depth as the big
     * number, health as a colored pill, oldest-job age as the footnote.
     * Configured-but-idle queues are listed too, so an idle queue is visibly
     * idle rather than absent; leftover queues that still hold rows but were
     * removed from config stay visible as well.
     *
     * @param list<array{queue: string, depth: int, oldest_seconds: int}> $perQueue
     */
    private function renderQueueCards(array $perQueue): string
    {
        $helper = Mage::helper('hirale_queue');

        $byQueue = [];
        foreach ($perQueue as $row) {
            $byQueue[(string) $row['queue']] = $row;
        }
        $queues = array_unique(array_merge($helper->getQueueList(), array_keys($byQueue)));

        $cards = '';
        foreach ($queues as $queue) {
            $depth  = (int) ($byQueue[$queue]['depth'] ?? 0);
            $oldest = (int) ($byQueue[$queue]['oldest_seconds'] ?? 0);

            [$healthColor, $healthLabel] = match (true) {
                $depth === 0                      => ['#28a745', $helper->__('idle')],
                $oldest >= self::AGE_CRIT_SECONDS => ['#d9534f', $helper->__('stalled?')],
                $oldest >= self::AGE_WARN_SECONDS => ['#e89c00', $helper->__('backlog')],
                default                           => ['#28a745', $helper->__('ok')],
            };

            $footnote = $depth > 0
                ? $helper->__('oldest %s', $this->formatAge($oldest))
                : $helper->__('no active jobs');

            $cards .= sprintf(
                '<div style="min-width:170px;padding:10px 14px;background:#fff;border:1px solid #d9d9d9;border-left:4px solid %1$s;border-radius:6px;">'
                . '<div style="display:flex;justify-content:space-between;align-items:center;gap:12px;">'
                . '<span style="font-size:12px;font-weight:bold;color:#444;">%2$s</span>'
                . '<span style="padding:1px 8px;border-radius:8px;background:%1$s;color:#fff;font-size:10px;text-transform:uppercase;">%3$s</span>'
                . '</div>'
                . '<div style="font-size:22px;font-weight:bold;color:#333;line-height:1.3;">%4$d'
                . ' <span style="font-size:11px;font-weight:normal;color:#999;">%5$s</span></div>'
                . '<div style="font-size:11px;color:#999;">%6$s</div>'
                . '</div>',
                htmlspecialchars($healthColor, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($queue, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars((string) $healthLabel, ENT_QUOTES, 'UTF-8'),
                $depth,
                htmlspecialchars((string) $helper->__('active'), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars((string) $footnote, ENT_QUOTES, 'UTF-8'),
            );
        }

        return '<div style="display:flex;flex-wrap:wrap;gap:8px;">' . $cards . '</div>';
    }

    /**
     * Seconds to a compact human age: 45s, 12m 03s, 2h 05m, 3d 4h.
     */
    private function formatAge(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . 's';
        }
        if ($seconds < 3600) {
            return sprintf('%dm %02ds', intdiv($seconds, 60), $seconds % 60);
        }
        if ($seconds < 86400) {
            return sprintf('%dh %02dm', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
        }
        return sprintf('%dd %dh', intdiv($seconds, 86400), intdiv($seconds % 86400, 3600));
    }
}
