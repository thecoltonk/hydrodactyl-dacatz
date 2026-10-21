<?php

namespace Pterodactyl\Http\Requests\Api\Client\Servers\QuickBuild;

use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\UserPlan;

/**
 * Change a server's CPU, RAM and storage from the server list. Panel admins
 * can set anything; plan owners can resize servers they own within their plan.
 */
class UpdateQuickBuildRequest extends ClientApiRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $server = $this->route('server');
        if ($user === null || !$server instanceof Server) {
            return false;
        }

        return $user->root_admin
            || ($server->owner_id === $user->id && UserPlan::query()->where('user_id', $user->id)->exists());
    }

    public function rules(): array
    {
        return [
            // MiB, 0 = unlimited (admins only).
            'memory' => ['required', 'integer', 'min:0', 'max:1048576'],
            // Percent of one core (100 = 1 core), 0 = unlimited (admins only).
            'cpu' => ['required', 'integer', 'min:0', 'max:51200'],
            // MiB, 0 = unlimited (admins only). Optional for older clients.
            'disk' => ['sometimes', 'integer', 'min:0', 'max:10485760'],
        ];
    }
}
