<?php

/**
 * The two platform seams between Maho and OpenMage that no shared class name
 * covers. Everything else in this module uses OpenMage-era class names that
 * Maho aliases in its bootstrap (Varien_Db_Ddl_Table, Varien_Event_Observer,
 * Varien_Data_Form_Element_Abstract, ...), so these helpers are deliberately
 * the whole abstraction layer.
 */
final class Hirale_Queue_Model_Compat
{
    /**
     * Raw SQL expression for select()/update() column lists: Maho\Db\Expr on
     * Maho, Zend_Db_Expr on OpenMage (which has no Varien_Db_Expr alias).
     */
    public static function expr(string $sql): object
    {
        if (class_exists(\Maho\Db\Expr::class)) {
            return new \Maho\Db\Expr($sql);
        }
        return new \Zend_Db_Expr($sql);
    }

    /**
     * JSON response body: Maho's setBodyJson() when available, manual
     * header + json_encode on OpenMage.
     *
     * @param array<string, mixed> $payload
     */
    public static function jsonResponse(object $response, array $payload): void
    {
        if (method_exists($response, 'setBodyJson')) {
            $response->setBodyJson($payload);
            return;
        }
        $response->setHeader('Content-Type', 'application/json', true);
        $response->setBody((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
