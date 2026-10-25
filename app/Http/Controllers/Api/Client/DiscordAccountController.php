<?php

namespace Pterodactyl\Http\Controllers\Api\Client;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Pterodactyl\Facades\Activity;
use Illuminate\Support\Facades\Cache;
use Pterodactyl\Http\Controllers\Base\DiscordLinkController;

class DiscordAccountController extends ClientApiController
{
    public function show(Request $request): array
    {
        $user = $request->user();
        $invite = trim((string) config('hydrodactyl.discord.invite_url'));

        return [
            'available' => DiscordLinkController::available(),
            'linked' => $user->discord_id !== null,
            'username' => $user->discord_username,
            'invite_url' => $invite !== '' ? $invite : null,
        ];
    }

    public function destroy(Request $request): Response
    {
        $user = $request->user();
        if ($user->discord_id !== null) {
            Cache::forget('discord:dm:' . $user->discord_id);
            $user->forceFill(['discord_id' => null, 'discord_username' => null])->saveQuietly();
            Activity::event('user:discord.unlinked')->subject($user)->log();
        }

        return $this->returnNoContent();
    }
}
