<?php

namespace Pterodactyl\Http\Requests\Api\Client\Servers\MinecraftPlayers;

use Pterodactyl\Models\Permission;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class GetPlayersRequest extends ClientApiRequest
{
    public function permission(): string
    {
        return Permission::ACTION_CONTROL_CONSOLE;
    }
}
