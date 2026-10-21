<?php

namespace Pterodactyl\Http\Controllers\Api\Client;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pterodactyl\Http\Requests\Api\Client\Account\ModCollections\AddCollectionItemRequest;
use Pterodactyl\Http\Requests\Api\Client\Account\ModCollections\RemoveCollectionItemRequest;
use Pterodactyl\Http\Requests\Api\Client\Account\ModCollections\SaveCollectionRequest;
use Pterodactyl\Models\ModCollection;
use Pterodactyl\Models\ModCollectionItem;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Per-user mod/plugin collections. Every query is scoped to the signed-in
 * user, so collections are private and work across all of their servers.
 */
class ModCollectionsController extends ClientApiController
{
    public function index(Request $request): JsonResponse
    {
        $collections = ModCollection::query()
            ->where('user_id', $request->user()->id)
            ->with(['items' => fn ($query) => $query->orderBy('title')])
            ->orderBy('sort')
            ->orderBy('id')
            ->get();

        return response()->json(['collections' => $collections->map(fn (ModCollection $c) => $this->serialize($c))->values()->all()]);
    }

    public function store(SaveCollectionRequest $request): JsonResponse
    {
        $userId = $request->user()->id;
        $count = ModCollection::query()->where('user_id', $userId)->count();
        if ($count >= ModCollection::PER_USER_LIMIT) {
            throw new HttpException(422, 'You can have up to ' . ModCollection::PER_USER_LIMIT . ' collections.');
        }

        $collection = ModCollection::query()->create([
            'user_id' => $userId,
            'name' => trim((string) $request->input('name')),
            'sort' => $count,
        ]);

        return response()->json(['collection' => $this->serialize($collection->load('items'))], 201);
    }

    public function update(SaveCollectionRequest $request, int $collection): JsonResponse
    {
        $model = $this->find($request, $collection);
        $model->update(['name' => trim((string) $request->input('name'))]);

        return response()->json(['collection' => $this->serialize($model->load('items'))]);
    }

    public function destroy(Request $request, int $collection): JsonResponse
    {
        $this->find($request, $collection)->delete();

        return response()->json([], 204);
    }

    public function addItem(AddCollectionItemRequest $request, int $collection): JsonResponse
    {
        $model = $this->find($request, $collection);
        $identity = [
            'collection_id' => $model->id,
            'type' => $request->input('type'),
            'source' => $request->input('source'),
            'project_id' => $request->input('project_id'),
        ];
        if (!ModCollectionItem::query()->where($identity)->exists()
            && ModCollectionItem::query()->where('collection_id', $model->id)->count() >= ModCollection::ITEM_LIMIT) {
            throw new HttpException(422, 'A collection can hold up to ' . ModCollection::ITEM_LIMIT . ' items.');
        }

        ModCollectionItem::query()->updateOrCreate($identity, [
            'title' => (string) $request->input('title'),
            'slug' => (string) $request->input('slug', ''),
            'author' => (string) $request->input('author', ''),
            'description' => mb_substr((string) $request->input('description', ''), 0, 500),
            'icon' => $request->input('icon'),
            'url' => $request->input('url'),
            'downloads' => (int) $request->input('downloads', 0),
        ]);

        return response()->json([], 204);
    }

    public function removeItem(RemoveCollectionItemRequest $request, int $collection): JsonResponse
    {
        $model = $this->find($request, $collection);
        ModCollectionItem::query()
            ->where('collection_id', $model->id)
            ->where('type', $request->input('type'))
            ->where('source', $request->input('source'))
            ->where('project_id', $request->input('project_id'))
            ->delete();

        return response()->json([], 204);
    }

    private function find(Request $request, int $id): ModCollection
    {
        $collection = ModCollection::query()->where('user_id', $request->user()->id)->find($id);
        if ($collection === null) {
            throw new HttpException(404, 'Collection not found.');
        }

        return $collection;
    }

    private function serialize(ModCollection $collection): array
    {
        return [
            'id' => $collection->id,
            'name' => $collection->name,
            'items' => $collection->items->map(fn (ModCollectionItem $item) => [
                'type' => $item->type,
                'project' => [
                    'source' => $item->source,
                    'id' => $item->project_id,
                    'title' => $item->title,
                    'slug' => $item->slug,
                    'description' => $item->description,
                    'author' => $item->author,
                    'icon' => $item->icon,
                    'downloads' => $item->downloads,
                    'categories' => [],
                    'url' => $item->url,
                    'updated_at' => null,
                ],
            ])->values()->all(),
        ];
    }
}
