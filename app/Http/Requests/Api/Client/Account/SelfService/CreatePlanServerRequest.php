<?php

namespace Pterodactyl\Http\Requests\Api\Client\Account\SelfService;

use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class CreatePlanServerRequest extends ClientApiRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:191', 'regex:/\A[^\p{C}]+\z/u'],
            'egg_id' => ['required_without:modpack', 'nullable', 'integer', 'min:1'],
            'modpack' => ['sometimes', 'nullable', 'array'],
            'modpack.source' => ['required_with:modpack', 'string', 'in:modrinth,curseforge'],
            'modpack.project_id' => ['required_with:modpack', 'string', 'regex:/\A[A-Za-z0-9]{1,64}\z/'],
            'modpack.version_id' => ['required_with:modpack', 'string', 'regex:/\A[A-Za-z0-9]{1,64}\z/'],
            'memory' => ['required', 'integer', 'min:0', 'max:1048576'],
            'cpu' => ['required', 'integer', 'min:0', 'max:51200'],
            'disk' => ['required', 'integer', 'min:0', 'max:10485760'],
        ];
    }
}
