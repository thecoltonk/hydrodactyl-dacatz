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

        // parent: players.read
        return parent::authorize() && $server instanceof Server
            && app(PlayerTarget::class)->supports($server);
    }

    public function rules(): array
    {
        return [];
    }
}
