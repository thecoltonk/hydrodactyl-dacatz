<?php

namespace Pterodactyl\Http\Requests\Api\Client\Servers\Modpacks;

use Pterodactyl\Models\Server;
use Pterodactyl\Models\Permission;

class InstallModpackRequest extends BrowseModpacksRequest
{
    // parent: modpack.install, whose description warns that installing wipes the server.

    public function rules(): array
    {
        return [
            'source' => 'nullable|string|in:modrinth,curseforge',
            'project_id' => 'nullable|required_if:source,curseforge|string|regex:/\A[A-Za-z0-9]{1,64}\z/',
            'version_id' => 'required|string|regex:/\A[A-Za-z0-9]{1,64}\z/',
            'confirm_wipe' => 'required|accepted',
        ];
    }
}
