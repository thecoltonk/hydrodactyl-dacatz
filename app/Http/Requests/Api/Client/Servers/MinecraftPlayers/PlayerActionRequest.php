<?php

namespace Pterodactyl\Http\Requests\Api\Client\Servers\MinecraftPlayers;

use Pterodactyl\Models\Server;
use Pterodactyl\Services\MinecraftPlayers\PlayerTarget;

class PlayerActionRequest extends GetPlayersRequest
{
    public function authorize(): bool
    {
        if (!parent::authorize()) {
            return false;
        }
        $server = $this->route('server');
        if (!$server instanceof Server || !app(PlayerTarget::class)->supports($server)) {
            return false;
        }
        // Operator status grants powerful in-game commands, so only the owner or a
        // panel administrator may change it.
        if (in_array($this->input('action'), ['op', 'deop'], true)) {
            return $this->user()->root_admin || $this->user()->id === $server->owner_id;
        }

        return true;
    }

    public function validationData(): array
    {
        return array_replace(parent::validationData(), ['player' => $this->route('player')]);
    }

    public function rules(): array
    {
        return [
            'player' => ['required', 'string', 'regex:/\A[A-Za-z0-9_]{1,16}\z/'],
            'action' => ['required', 'in:kick,ban,pardon,op,deop,whitelist_add,whitelist_remove'],
            'reason' => ['nullable', 'string', 'max:120', 'not_regex:/[\p{C}\p{Zl}\p{Zp}]/u'],
            'confirmed' => ['required', 'accepted'],
        ];
    }
}
