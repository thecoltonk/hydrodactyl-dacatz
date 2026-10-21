<?php

namespace Pterodactyl\Http\Requests\Api\Client\Servers\QuickBuild;

use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\UserPlan;

/**
 * Plan owners can delete servers they own; panel admins can delete any.
 */
class DeletePlanServerRequest extends ClientApiRequest
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
        return ['confirm_name' => ['required', 'string', 'max:191']];
    }
}
