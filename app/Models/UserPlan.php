<?php

namespace Pterodactyl\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Total resources a user can spread across servers they create themselves.
 *
 * @property int $id
 * @property int $user_id
 * @property int $memory MiB
 * @property int $cpu percent (100 = 1 core)
 * @property int $disk MiB
 * @property int $servers 0 = no limit
 */
class UserPlan extends Model
{
    public const RESOURCE_NAME = 'user_plan';

    protected $table = 'user_plans';

    protected bool $immutableDates = true;

    protected $casts = ['id' => 'int', 'user_id' => 'int', 'memory' => 'int', 'cpu' => 'int', 'disk' => 'int', 'servers' => 'int'];

    protected $guarded = ['id', 'created_at', 'updated_at'];

    public static array $validationRules = [
        'user_id' => 'bail|required|integer|exists:users,id',
        'memory' => 'required|integer|min:0',
        'cpu' => 'required|integer|min:0',
        'disk' => 'required|integer|min:0',
        'servers' => 'required|integer|min:0',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
