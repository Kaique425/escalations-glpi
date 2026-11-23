<?php

namespace GlpiPlugin\Escalations;

use Closure;
use Throwable;

final class Transaction
{
    /**
     * @template T
     * @param Closure(): T $action
     * @return T
     */
    public static function run(Closure $action): mixed
    {
        global $DB;
        $DB->beginTransaction();
        try {
            $result = $action();
            $DB->commit();
            return $result;
        } catch (Throwable $e) {
            $DB->rollBack();
            throw $e;
        }
    }
}
