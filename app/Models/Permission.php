<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * What a role may do in general. The division, PIC, status, and field rules are still checked by the Backend.
 */
#[Fillable(['code', 'description'])]
class Permission extends Model
{
    public const TASK_VIEW_ALL = 'task.view_all';

    public const TASK_CREATE = 'task.create';

    public const TASK_UPDATE = 'task.update';

    public const TASK_DELETE = 'task.delete';

    public const TASK_ASSIGN = 'task.assign';

    public const TASK_REVIEW = 'task.review';

    public const TASK_CHANGE_STATUS = 'task.change_status';

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permissions');
    }
}
