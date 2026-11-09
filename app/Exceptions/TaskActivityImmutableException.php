<?php

namespace App\Exceptions;

use LogicException;

/**
 * A task history entry is a record of something that already happened, so it is never changed or removed.
 */
class TaskActivityImmutableException extends LogicException
{
    public function __construct()
    {
        parent::__construct('Riwayat aktivitas tugas tidak dapat diubah atau dihapus.');
    }
}
