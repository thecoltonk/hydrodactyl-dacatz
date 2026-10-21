<?php

namespace Pterodactyl\Http\Requests\Api\Client\Servers\MinecraftPlayers;

use Pterodactyl\Models\Permission;
use Pterodactyl\Models\Server;

class EditInventoryRequest extends GetInventoryRequest
{
    public function authorize(): bool
    {
        $server = $this->route('server');

        return parent::authorize() && $server instanceof Server
            && $this->user()->can(Permission::ACTION_FILE_UPDATE, $server);
    }

    public function rules(): array
    {
        $item = 'regex:~\A[a-z0-9_.-]{1,64}:[a-z0-9_./-]{1,96}\z~';

        return [
            'player' => ['required', 'string', 'regex:/\A[A-Za-z0-9_]{1,16}\z/'],
            'uuid' => ['nullable', 'uuid'],
            'changes' => ['required', 'array', 'min:1', 'max:64'],
            'changes.*.area' => ['required', 'string', 'in:inventory,ender_chest'],
            'changes.*.slot' => ['required', 'integer', 'min:0', 'max:150'],
            'changes.*.id' => ['present', 'nullable', 'string', $item],
            'changes.*.count' => ['nullable', 'required_with:changes.*.id', 'integer', 'min:1', 'max:99'],
            'changes.*.expect' => ['present', 'nullable', 'string', 'max:170'],
        ];
    }
}
