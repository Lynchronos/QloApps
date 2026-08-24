<?php
/**
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License version 3.0
 * that is bundled with this package in the file LICENSE.md
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/license/osl-3-0-php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to support@qloapps.com so we can send you a copy immediately.
 */

class QlofdAuditLog extends ObjectModel
{
    /** @var int */
    public $id;

    /** @var string */
    public $entity_type;

    /** @var int */
    public $entity_id;

    /** @var string */
    public $action;

    /** @var string */
    public $before_data;

    /** @var string */
    public $after_data;

    /** @var int */
    public $id_employee;

    /** @var string */
    public $date_add;

    public static $definition = array(
        'table' => 'qlofd_audit_log',
        'primary' => 'id',
        'fields' => array(
            'entity_type' => array('type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'required' => true, 'size' => 32),
            'entity_id' => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'),
            'action' => array('type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'required' => true, 'size' => 64),
            'before_data' => array('type' => self::TYPE_HTML, 'validate' => 'isCleanHtml'),
            'after_data' => array('type' => self::TYPE_HTML, 'validate' => 'isCleanHtml'),
            'id_employee' => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedId'),
            'date_add' => array('type' => self::TYPE_DATE, 'validate' => 'isDate'),
        ),
    );

    /**
     * Write an audit entry.
     *
     * @param string $entityType
     * @param int $entityId
     * @param string $action
     * @param array|string|null $beforeData
     * @param array|string|null $afterData
     * @param int $idEmployee
     * @return bool
     */
    public static function record($entityType, $entityId, $action, $beforeData = null, $afterData = null, $idEmployee = 0)
    {
        $objLog = new QlofdAuditLog();
        $objLog->entity_type = pSQL($entityType);
        $objLog->entity_id = (int) $entityId;
        $objLog->action = pSQL($action);
        $objLog->before_data = is_array($beforeData) || is_object($beforeData)
            ? pSQL(json_encode($beforeData), true)
            : pSQL($beforeData, true);
        $objLog->after_data = is_array($afterData) || is_object($afterData)
            ? pSQL(json_encode($afterData), true)
            : pSQL($afterData, true);
        $objLog->id_employee = (int) $idEmployee;

        return $objLog->save();
    }

    /**
     * Fetch recent audit entries for an entity.
     *
     * @param string $entityType
     * @param int $entityId
     * @param int $limit
     * @return array
     */
    public static function getForEntity($entityType, $entityId, $limit = 50)
    {
        return Db::getInstance()->executeS(
            'SELECT * FROM `'._DB_PREFIX_.'qlofd_audit_log`
             WHERE `entity_type` = \''.pSQL($entityType).'\'
             AND `entity_id` = '.(int) $entityId.'
             ORDER BY `id` DESC
             LIMIT '.(int) $limit
        );
    }

    /**
     * Latest entries across the module (for the log view).
     *
     * @param int $limit
     * @return array
     */
    public static function getLatest($limit = 100)
    {
        return Db::getInstance()->executeS(
            'SELECT * FROM `'._DB_PREFIX_.'qlofd_audit_log`
             ORDER BY `id` DESC
             LIMIT '.(int) $limit
        );
    }
}