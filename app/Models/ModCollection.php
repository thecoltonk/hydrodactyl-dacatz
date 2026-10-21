<?php

namespace Pterodactyl\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A user's private, named list of mods/plugins (e.g. "Performance").
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property int $sort
 * @property \Illuminate\Database\Eloquent\Collection<int, ModCollectionItem> $items
 */
class ModCollection extends Model
{
    public const RESOURCE_NAME = 'mod_collection';

    public const PER_USER_LIMIT = 30;

    public const ITEM_LIMIT = 200;

    protected $table = 'mod_collections';

    protected bool $immutableDates = true;

    protected $casts = ['id' => 'int', 'user_id' => 'int', 'sort' => 'int'];

    protected $guarded = ['id', 'created_at', 'updated_at'];

    public static array $validationRules = [
        'user_id' => 'bail|required|integer|exists:users,id',
        'name' => 'required|string|min:1|max:64',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ModCollectionItem::class, 'collection_id');
    }
}
