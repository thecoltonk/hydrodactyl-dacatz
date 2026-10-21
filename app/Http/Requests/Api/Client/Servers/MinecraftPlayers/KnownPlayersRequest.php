<?php

namespace Pterodactyl\Http\Requests\Api\Client\Servers\MinecraftPlayers;

use Pterodactyl\Models\Permission;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\MinecraftPlayers\PlayerTarget;

class KnownPlayersRequest extends GetPlayersRequest
{
    public function authorize(): bool
    {
        $server = $this->route('server');

        return parent::authorize() && $server instanceof Server
            && app(PlayerTarget::class)->supports($server)
            && $this->user()->can(Permission::ACTION_FILE_READ, $server);
    }

    public function rules(): array
    {
        return [];
    }
}
