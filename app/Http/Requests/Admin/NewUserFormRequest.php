<?php

namespace Pterodactyl\Http\Requests\Admin;

use Pterodactyl\Models\User;
use Illuminate\Support\Collection;

class NewUserFormRequest extends AdminFormRequest
{
    /**
     * Rules to apply to requests for updating or creating a user
     * in the Admin CP.
     */
    public function rules(): array
    {
        return Collection::make(
            User::getRules()
        )->only([
            'email',
            'username',
            'name_first',
            'password',
            'language',
            'root_admin',
        ])->merge([
            // No email is sent, so the password can't be set by the user later,
            // unless the bot DMs them a setup link (Discord invites).
            'password' => \Pterodactyl\Services\Discord\DiscordInvites::enabled()
                ? ['nullable', 'required_without:discord', 'string', 'min:8']
                : ['required', 'string', 'min:8'],
            'discord' => ['nullable', 'string', 'max:64'],
        ])->toArray();
    }
}
