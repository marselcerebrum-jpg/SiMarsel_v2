<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Destructive rollback of the TASK-19 migrations
    |--------------------------------------------------------------------------
    |
    | migrate:rollback of the TASK-19 migrations is refused once the application has written data the old schema
    | cannot hold (PIC assignments, comments, new agendas, ...). Use `php artisan tasks:recovery` to inspect the
    | risk. Set this to true only to throw that data away on purpose, for example on a disposable test database.
    |
    */

    'allow_destructive_rollback' => (bool) env('TASKLIST_ALLOW_DESTRUCTIVE_ROLLBACK', false),

    /*
    |--------------------------------------------------------------------------
    | Task evidence uploads
    |--------------------------------------------------------------------------
    |
    | The largest evidence file a PIC may upload, in kilobytes. Keep it at or below PHP's upload_max_filesize and
    | post_max_size, or larger files fail before they reach validation.
    |
    */

    'evidence' => [
        'max_kilobytes' => (int) env('TASK_EVIDENCE_MAX_KB', 10240),
    ],

];
