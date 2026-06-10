<?php

/**
 * "Test Connection" button rendered below the backend fields in
 * System > Configuration > Hirale > Queue.
 *
 * Modeled on Mage_Adminhtml_Block_System_Config_Form_Field_TestEmail, with one
 * deliberate difference: instead of probing the *saved* config, the button
 * serializes the backend/queue form values currently on screen and POSTs them
 * to QueueController::testConnectionAction, so the operator can verify a
 * connection before clicking Save. Save-time validation then re-checks the
 * same way via the shared ConnectionTester.
 */
class Hirale_Queue_Block_Adminhtml_System_Config_TestConnection extends Mage_Adminhtml_Block_System_Config_Form_Field
{
    #[\Override]
    public function render(Varien_Data_Form_Element_Abstract $element)
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();
        return parent::render($element);
    }

    #[\Override]
    protected function _getElementHtml(Varien_Data_Form_Element_Abstract $element)
    {
        $helper       = Mage::helper('hirale_queue');
        $buttonLabel  = $this->escapeHtml($helper->__('Test Connection'));
        $testingLabel = $this->jsQuoteEscape($helper->__('Testing...'));
        $failedLabel  = $this->jsQuoteEscape($helper->__('Request failed. Check the error log.'));
        $resetLabel   = $this->jsQuoteEscape($helper->__('Test Connection'));
        $ajaxUrl      = Mage::getSingleton('adminhtml/url')->getUrl('adminhtml/queue/testConnection');

        return <<<HTML
<style>
    #hirale_queue_test_connection_result {
        display: none;
        margin-top: 8px;
        padding: 10px 14px;
        border-radius: 4px;
        font-size: 13px;
    }
    #hirale_queue_test_connection_result.success {
        display: block;
        background: #ecfdf5;
        border: 1px solid #10b981;
    }
    #hirale_queue_test_connection_result.error {
        display: block;
        background: #fef2f2;
        border: 1px solid #ef4444;
    }
</style>
<script>
    function hiraleQueueTestConnection() {
        const button = document.getElementById('hirale_queue_test_connection_button');
        const label = document.getElementById('hirale_queue_test_connection_label');
        const result = document.getElementById('hirale_queue_test_connection_result');

        result.className = '';
        label.textContent = '{$testingLabel}';
        button.disabled = true;

        const formData = new FormData();
        formData.append('form_key', FORM_KEY);
        document.querySelectorAll('#config_edit_form input, #config_edit_form select, #config_edit_form textarea').forEach((el) => {
            if (!el.name || el.disabled) {
                return;
            }
            if (el.name.startsWith('groups[backend]') || el.name.startsWith('groups[queues]')) {
                formData.append(el.name, el.value);
            }
        });

        fetch('{$ajaxUrl}', { method: 'POST', body: formData, credentials: 'same-origin' })
            .then((r) => r.json())
            .then((response) => {
                result.textContent = response.message;
                result.className = response.success ? 'success' : 'error';
            })
            .catch((error) => {
                console.error('Test connection error:', error);
                result.textContent = '{$failedLabel}';
                result.className = 'error';
            })
            .finally(() => {
                label.textContent = '{$resetLabel}';
                button.disabled = false;
            });
    }
</script>
<button onclick="hiraleQueueTestConnection(); return false;" class="scalable" type="button" id="hirale_queue_test_connection_button">
    <span id="hirale_queue_test_connection_label">{$buttonLabel}</span>
</button>
<div id="hirale_queue_test_connection_result"></div>
HTML;
    }
}
