<?php

namespace Pterodactyl\Http\Requests\Api\Client\Servers\MinecraftPlayers;

class ItemIconRequest extends KnownPlayersRequest
{
    public function rules(): array
    {
        return [
            'id' => ['required', 'string', 'max:170', 'regex:~\A[a-z0-9_.-]{1,64}:[a-z0-9_./-]{1,96}\z~'],
        ];
    }
}
