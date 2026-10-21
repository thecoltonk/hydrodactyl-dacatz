<?php

namespace Pterodactyl\Http\Requests\Api\Client\Account\ModCollections;

use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class AddCollectionItemRequest extends ClientApiRequest
{
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', 'in:mod,plugin'],
            'source' => ['required', 'string', 'alpha_dash', 'max:32'],
            'project_id' => ['required', 'string', 'max:128'],
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:191'],
            'author' => ['nullable', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:2000'],
            'icon' => ['nullable', 'string', 'url:https', 'max:1024'],
            'url' => ['nullable', 'string', 'url:https', 'max:1024'],
            'downloads' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
