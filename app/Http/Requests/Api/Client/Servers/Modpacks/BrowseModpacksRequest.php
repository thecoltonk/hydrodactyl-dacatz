<?php

namespace Pterodactyl\Http\Requests\Api\Client\Servers\Modpacks;

use Pterodactyl\Models\Permission;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class BrowseModpacksRequest extends ClientApiRequest
{
    public function permission(): string
    {
        return Permission::ACTION_STARTUP_SOFTWARE;
    }

    public function rules(): array
    {
        return [
            'query' => 'nullable|string|max:100',
            'offset' => 'nullable|integer|min:0|max:1000',
            'loader' => 'nullable|string|in:fabric,quilt,forge,neoforge',
            'project_id' => 'nullable|string|regex:/\A[A-Za-z0-9]{1,64}\z/',
        ];
    }
}
