<?php

/**
 * Recursive payload scrubbing for the admin payload viewer. The stored payload
 * in hirale_queue_job is unchanged — this only affects what the admin sees.
 */
class Hirale_Queue_Model_Redactor
{
    private const REPLACEMENT = '***REDACTED***';

    /**
     * @param array<mixed> $payload
     * @param list<string>|null $fieldsOverride pass null to use Helper config
     * @return array<mixed>
     */
    public function redact(array $payload, ?array $fieldsOverride = null): array
    {
        $fields = $fieldsOverride ?? Mage::helper('hirale_queue')->getRedactedFields();
        if (count($fields) === 0) {
            return $payload;
        }
        $lowered = array_map('strtolower', $fields);
        return $this->walk($payload, $lowered);
    }

    /**
     * @param array<mixed> $node
     * @param list<string> $loweredFields
     * @return array<mixed>
     */
    private function walk(array $node, array $loweredFields): array
    {
        foreach ($node as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), $loweredFields, true)) {
                $node[$key] = is_array($value) ? array_fill_keys(array_keys($value), self::REPLACEMENT) : self::REPLACEMENT;
                continue;
            }
            if (is_array($value)) {
                $node[$key] = $this->walk($value, $loweredFields);
            }
        }
        return $node;
    }
}
