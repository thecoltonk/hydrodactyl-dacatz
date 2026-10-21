<?php

namespace Pterodactyl\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Prologue\Alerts\AlertsMessageBag;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Models\User;
use Pterodactyl\Models\UserPlan;

/**
 * Admin form on the user page: the total RAM / CPU / storage / servers a user
 * can spread across servers they create themselves.
 */
class UserPlanController extends Controller
{
    public function __construct(private AlertsMessageBag $alert)
    {
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'plan_enabled' => ['required', 'boolean'],
            'plan_memory_gb' => ['required_if:plan_enabled,1', 'nullable', 'numeric', 'min:0.5', 'max:1024'],
            'plan_cpu_cores' => ['required_if:plan_enabled,1', 'nullable', 'numeric', 'min:0.5', 'max:512'],
            'plan_disk_gb' => ['required_if:plan_enabled,1', 'nullable', 'numeric', 'min:1', 'max:10240'],
            'plan_servers' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ]);

        if (!$request->boolean('plan_enabled')) {
            UserPlan::query()->where('user_id', $user->id)->delete();
            $this->alert->success('Server plan removed. Their existing servers are untouched.')->flash();

            return redirect()->route('admin.users.view', $user->id);
        }

        UserPlan::query()->updateOrCreate(['user_id' => $user->id], [
            'memory' => (int) round((float) $data['plan_memory_gb'] * 1024),
            'cpu' => (int) round((float) $data['plan_cpu_cores'] * 100),
            'disk' => (int) round((float) $data['plan_disk_gb'] * 1024),
            'servers' => (int) ($data['plan_servers'] ?? 0),
        ]);
        $this->alert->success('Server plan saved.')->flash();

        return redirect()->route('admin.users.view', $user->id);
    }
}
