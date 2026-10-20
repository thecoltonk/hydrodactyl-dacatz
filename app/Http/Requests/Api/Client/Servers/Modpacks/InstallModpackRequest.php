<?php

namespace Pterodactyl\Http\Requests\Api\Client\Servers\Modpacks;

use Pterodactyl\Models\Server;
use Pterodactyl\Models\Permission;

class InstallModpackRequest extends BrowseModpacksRequest
{
    /**
     * A fresh install wipes every file and changes the server's software, so it
     * needs the same rights as changing software plus file deletion.
     */
    public function authorize(): bool
    {
        $server = $this->route('server');

        return parent::authorize() && $server instanceof Server
            && $this->user()->can(Permission::ACTION_FILE_DELETE, $server)
            && $this->user()->can(Permission::ACTION_FILE_CREATE, $server);
    }

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
