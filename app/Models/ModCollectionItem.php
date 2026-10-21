<?php

namespace Pterodactyl\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $collection_id
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
 */
class ModCollectionItem extends Model
{
    public const RESOURCE_NAME = 'mod_collection_item';

    protected $table = 'mod_collection_items';

    protected bool $immutableDates = true;

    protected $casts = ['id' => 'int', 'collection_id' => 'int', 'downloads' => 'int'];

    protected $guarded = ['id', 'created_at', 'updated_at'];

    public static array $validationRules = [
        'collection_id' => 'bail|required|integer',
        'type' => 'required|string|in:mod,plugin',
        'source' => 'required|string|max:32',
        'project_id' => 'required|string|max:128',
        'title' => 'required|string|max:255',
    ];

    public function collection(): BelongsTo
    {
        return $this->belongsTo(ModCollection::class, 'collection_id');
    }
}
