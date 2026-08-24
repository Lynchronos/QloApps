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

/**
 * Session-scoped undo queue (per employee), stored in the PHP session as the
 * plan specifies (no database table). Holds the last N reversible actions.
 */
class QlofdUndo
{
    /**
     * @return string
     */
    protected static function getQueueKey()
    {
        $idEmployee = 0;
        $context = Context::getContext();
        if ($context && isset($context->employee) && $context->employee) {
            $idEmployee = (int) $context->employee->id;
        }

        return 'qlofd_undo_queue_'.$idEmployee;
    }

    /**
     * Ensure the PHP session is available.
     *
     * @return void
     */
    protected static function ensureSession()
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            @session_start();
        }
    }

    /**
     * Push a reversible action onto the queue.
     *
     * @param array $item
     * @return void
     */
    public static function push($item)
    {
        $limit = (int) Configuration::get('QLOFD_UNDO_LIMIT');
        if ($limit < 1) {
            $limit = 10;
        }

        $queue = self::all();
        array_unshift($queue, $item);
        $queue = array_slice($queue, 0, $limit);

        self::save($queue);
    }

    /**
     * Pop the most recent action (LIFO).
     *
     * @return array|false
     */
    public static function pop()
    {
        $queue = self::all();
        if (empty($queue)) {
            return false;
        }

        $item = array_shift($queue);
        self::save($queue);

        return $item;
    }

    /**
     * Peek without popping.
     *
     * @return array|false
     */
    public static function peek()
    {
        $queue = self::all();
        return empty($queue) ? false : $queue[0];
    }

    /**
     * @return array
     */
    public static function all()
    {
        self::ensureSession();
        $key = self::getQueueKey();
        return isset($_SESSION[$key]) && is_array($_SESSION[$key])
            ? $_SESSION[$key]
            : array();
    }

    /**
     * @param array $queue
     * @return void
     */
    protected static function save($queue)
    {
        self::ensureSession();
        $_SESSION[self::getQueueKey()] = array_values($queue);
    }

    /**
     * @return void
     */
    public static function clear()
    {
        self::ensureSession();
        unset($_SESSION[self::getQueueKey()]);
    }
}