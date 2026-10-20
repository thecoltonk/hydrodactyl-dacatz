<?php

namespace Pterodactyl\Http\Requests\Api\Client\Servers\MinecraftPlayers;

use Pterodactyl\Models\Server;
use Pterodactyl\Services\MinecraftPlayers\PlayerTarget;

class SkinRequest extends GetPlayersRequest
{
    public function authorize(): bool
    {
        $server = $this->route('server');

        return parent::authorize() && $server instanceof Server && app(PlayerTarget::class)->supports($server);
    }

    public function validationData(): array
    {
        return array_replace(parent::validationData(), ['player' => $this->route('player')]);
    }

    public function rules(): array
    {
        return [
            'player' => ['required', 'string', 'regex:/\A[A-Za-z0-9_]{1,16}\z/'],
        ];
    }
}
