<?php

namespace Pterodactyl\Http\Requests\Api\Client\Servers\MinecraftPlayers;

use Pterodactyl\Models\Server;
use Pterodactyl\Models\Permission;

class WipePlayerRequest extends GetInventoryRequest
{
    public function authorize(): bool
    {
        $server = $this->route('server');

        return parent::authorize() && $server instanceof Server
            && $this->user()->can(Permission::ACTION_FILE_DELETE, $server);
    }

    public function rules(): array
    {
        return [
            'player' => ['required', 'string', 'regex:/\A[A-Za-z0-9_]{1,16}\z/'],
            'uuid' => ['nullable', 'uuid'],
            // Typed by the user to confirm; must match exactly.
            'confirm_name' => ['required', 'string', 'same:player'],
        ];
    }
}
