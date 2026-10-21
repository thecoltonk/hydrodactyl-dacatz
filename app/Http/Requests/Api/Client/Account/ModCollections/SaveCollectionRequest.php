<?php

namespace Pterodactyl\Http\Requests\Api\Client\Account\ModCollections;

use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class SaveCollectionRequest extends ClientApiRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:64'],
        ];
    }
}
