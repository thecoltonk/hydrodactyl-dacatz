<?php

namespace Pterodactyl\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $type
 * @property string $source
 * @property string $project_id
 * @property string $title
 * @property string $slug
 * @property string $author
 * @property string $description
 * @property string|null $icon
 * @property string|null $url
 * @property int $downloads
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 * @property User $user
 */
class MarketplaceFavorite extends Model
{
    public const RESOURCE_NAME = 'marketplace_favorite';

    /** Per-user cap so one account can't flood the shared tab. */
    public const PER_USER_LIMIT = 200;

    protected $table = 'marketplace_favorites';

    protected bool $immutableDates = true;

    protected $casts = [
        'id' => 'int',
        'user_id' => 'int',
        'downloads' => 'int',
    ];

    protected $guarded = ['id', 'created_at', 'updated_at'];

    public static array $validationRules = [
        'user_id' => 'bail|required|integer|exists:users,id',
        'type' => 'required|string|in:mod,plugin',
        'source' => 'required|string|max:32',
        'project_id' => 'required|string|max:128',
        'title' => 'required|string|max:255',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
