<?php

namespace Pterodactyl\Http\Requests\Api\Client\Account\ModCollections;

use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class RemoveCollectionItemRequest extends ClientApiRequest
{
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', 'in:mod,plugin'],
            'source' => ['required', 'string', 'alpha_dash', 'max:32'],
            'project_id' => ['required', 'string', 'max:128'],
        ];
    }
}
