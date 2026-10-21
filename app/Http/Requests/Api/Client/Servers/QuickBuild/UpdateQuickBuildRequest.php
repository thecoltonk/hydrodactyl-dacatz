<?php

namespace Pterodactyl\Http\Requests\Api\Client\Servers\QuickBuild;

use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

/**
 * Panel admins only: change a server's CPU and RAM from the server list.
 */
class UpdateQuickBuildRequest extends ClientApiRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->root_admin;
    }

    public function rules(): array
    {
        return [
            // MiB, 0 = unlimited.
            'memory' => ['required', 'integer', 'min:0', 'max:1048576'],
            // Percent of one core (100 = 1 core), 0 = unlimited.
            'cpu' => ['required', 'integer', 'min:0', 'max:51200'],
        ];
    }
}
