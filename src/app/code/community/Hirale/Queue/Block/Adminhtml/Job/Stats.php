<?php

declare(strict_types=1);

class Hirale_Queue_Block_Adminhtml_Job_Stats extends Mage_Adminhtml_Block_Template
{
    protected function _toHtml()
    {
        $stats = $this->_getQueue()->getStats();
        $total = array_sum(array_map('intval', $stats));

        $html = '<div class="content-header"><h3>' . $this->escapeHtml($this->__('Queue Status')) . '</h3></div>';
        $html .= '<div style="' . $this->_styleContainer() . '">';
        $html .= '<div style="' . $this->_styleHero() . '">';
        $html .= '<div style="' . $this->_styleHeroLabel() . '">' . $this->escapeHtml($this->__('Total Jobs')) . '</div>';
        $html .= '<div style="' . $this->_styleHeroValue() . '">' . (int) $total . '</div>';
        $html .= '<div style="' . $this->_styleHeroMeta() . '">' . $this->escapeHtml($this->__('Redis backed, DB indexed')) . '</div>';
        $html .= '</div>';
        $html .= '<div style="' . $this->_styleGrid() . '">';

        foreach ($this->_orderedStatuses() as $status) {
            $count = (int) ($stats[$status] ?? 0);
            $colors = $this->_statusColors($status);
            $html .= '<div style="' . $this->_styleTile($colors) . '">';
            $html .= '<div style="' . $this->_styleTileTop() . '">';
            $html .= '<span style="' . $this->_styleDot($colors) . '"></span>';
            $html .= '<span style="' . $this->_styleTileLabel() . '">' . $this->escapeHtml($this->_statusLabel($status)) . '</span>';
            $html .= '</div>';
            $html .= '<div style="' . $this->_styleTileValue($colors) . '">' . $count . '</div>';
            $html .= '</div>';
        }

        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    /**
     * @return list<string>
     */
    private function _orderedStatuses(): array
    {
        return [
            Hirale_Queue_Model_Job::STATUS_QUEUED,
            Hirale_Queue_Model_Job::STATUS_PUBLISHING,
            Hirale_Queue_Model_Job::STATUS_RUNNING,
            Hirale_Queue_Model_Job::STATUS_RETRY_WAIT,
            Hirale_Queue_Model_Job::STATUS_FAILED,
            Hirale_Queue_Model_Job::STATUS_SUCCEEDED,
            Hirale_Queue_Model_Job::STATUS_PUBLISHED,
            Hirale_Queue_Model_Job::STATUS_CANCELED,
        ];
    }

    private function _statusLabel(string $status): string
    {
        return $this->__(ucwords(str_replace('_', ' ', $status)));
    }

    /**
     * @return array{accent: string, bg: string, border: string}
     */
    private function _statusColors(string $status): array
    {
        return match ($status) {
            Hirale_Queue_Model_Job::STATUS_FAILED => [
                'accent' => '#b3261e',
                'bg' => '#fff5f3',
                'border' => '#efc7c1',
            ],
            Hirale_Queue_Model_Job::STATUS_RETRY_WAIT => [
                'accent' => '#9a5b00',
                'bg' => '#fff8e7',
                'border' => '#ead59d',
            ],
            Hirale_Queue_Model_Job::STATUS_RUNNING => [
                'accent' => '#1769aa',
                'bg' => '#eef7ff',
                'border' => '#bfd7ef',
            ],
            Hirale_Queue_Model_Job::STATUS_SUCCEEDED => [
                'accent' => '#2f6f3e',
                'bg' => '#f1fbf3',
                'border' => '#bedfc4',
            ],
            Hirale_Queue_Model_Job::STATUS_CANCELED => [
                'accent' => '#667085',
                'bg' => '#f6f7f9',
                'border' => '#d8dde5',
            ],
            default => [
                'accent' => '#315c72',
                'bg' => '#f3f8fa',
                'border' => '#c8d8df',
            ],
        };
    }

    private function _styleContainer(): string
    {
        return 'display:flex;gap:14px;align-items:stretch;margin:0 0 18px;padding:14px;background:#f7f9fa;border:1px solid #d5dde2;border-radius:6px;box-shadow:0 1px 2px rgba(0,0,0,.06);';
    }

    private function _styleHero(): string
    {
        return 'min-width:170px;padding:14px 16px;background:#263844;color:#fff;border-radius:5px;box-shadow:inset 0 -1px 0 rgba(255,255,255,.08);';
    }

    private function _styleHeroLabel(): string
    {
        return 'font-size:11px;line-height:14px;text-transform:uppercase;color:#c7d6df;font-weight:bold;';
    }

    private function _styleHeroValue(): string
    {
        return 'margin-top:5px;font-size:34px;line-height:38px;font-weight:bold;color:#fff;';
    }

    private function _styleHeroMeta(): string
    {
        return 'margin-top:6px;font-size:11px;line-height:15px;color:#d8e3e9;';
    }

    private function _styleGrid(): string
    {
        return 'display:grid;grid-template-columns:repeat(auto-fit,minmax(132px,1fr));gap:10px;flex:1;';
    }

    /**
     * @param array{accent: string, bg: string, border: string} $colors
     */
    private function _styleTile(array $colors): string
    {
        return 'min-height:72px;padding:11px 12px;background:' . $colors['bg'] . ';border:1px solid ' . $colors['border'] . ';border-radius:5px;box-sizing:border-box;';
    }

    private function _styleTileTop(): string
    {
        return 'display:flex;align-items:center;gap:6px;white-space:nowrap;';
    }

    private function _styleTileLabel(): string
    {
        return 'font-size:11px;line-height:14px;text-transform:uppercase;color:#4f5f68;font-weight:bold;';
    }

    /**
     * @param array{accent: string, bg: string, border: string} $colors
     */
    private function _styleTileValue(array $colors): string
    {
        return 'margin-top:8px;font-size:24px;line-height:28px;font-weight:bold;color:' . $colors['accent'] . ';';
    }

    /**
     * @param array{accent: string, bg: string, border: string} $colors
     */
    private function _styleDot(array $colors): string
    {
        return 'display:inline-block;width:7px;height:7px;border-radius:7px;background:' . $colors['accent'] . ';';
    }

    private function _getQueue(): Hirale_Queue_Model_Queue
    {
        $queue = Mage::getModel('hirale_queue/queue');
        if (!$queue instanceof Hirale_Queue_Model_Queue) {
            throw new RuntimeException('Hirale Queue service is unavailable.');
        }

        return $queue;
    }
}
