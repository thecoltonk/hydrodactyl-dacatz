<?php

namespace Pterodactyl\Http\Controllers\Admin\Settings;

use Illuminate\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Prologue\Alerts\AlertsMessageBag;
use Illuminate\Contracts\Console\Kernel;
use Pterodactyl\Http\Controllers\Controller;
use Illuminate\Contracts\Encryption\Encrypter;
use Pterodactyl\Services\Discord\DiscordClient;
use Pterodactyl\Services\Discord\DiscordNotifier;
use Pterodactyl\Services\Discord\DiscordException;
use Pterodactyl\Contracts\Repository\SettingsRepositoryInterface;

class DiscordController extends Controller
{
    private const PREFIX = 'settings::hydrodactyl:discord:';

    public function __construct(
        private AlertsMessageBag $alert,
        private Encrypter $encrypter,
        private Kernel $kernel,
        private SettingsRepositoryInterface $settings,
    ) {
    }

    public function index(): View
    {
        $appId = (string) config('hydrodactyl.discord.application_id');

        return view('admin.settings.discord', [
            'hasToken' => trim((string) config('hydrodactyl.discord.bot_token')) !== '',
            'hasSecret' => trim((string) config('hydrodactyl.discord.client_secret')) !== '',
            'redirectUrl' => route('account.discord.callback'),
            // bot + applications.commands; View Channel, Send Messages, Embed Links, Attach Files.
            'inviteUrl' => $appId !== ''
                ? 'https://discord.com/oauth2/authorize?client_id=' . urlencode($appId) . '&scope=bot+applications.commands&permissions=52224'
                : null,
            'linkedUsers' => \Pterodactyl\Models\User::query()->whereNotNull('discord_id')->count(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => 'required|in:true,false',
            'application_id' => ['nullable', 'regex:/\A\d{15,22}\z/'],
            'public_key' => ['nullable', 'regex:/\A[0-9a-fA-F]{64}\z/'],
            'bot_token' => 'nullable|string|min:50|max:100',
            'client_secret' => 'nullable|string|min:20|max:100',
            'channel_id' => ['nullable', 'regex:/\A\d{15,22}\z/'],
            'invite_url' => ['nullable', 'url', 'regex:/\Ahttps:\/\/(discord\.gg|discord\.com\/invite)\//'],
        ], [
            'application_id.regex' => 'The Application ID is the long number on the General Information page.',
            'public_key.regex' => 'The Public Key is 64 hex characters, on the General Information page.',
            'channel_id.regex' => 'Turn on Developer Mode in Discord, then right-click the channel and Copy Channel ID.',
            'invite_url.regex' => 'Use a discord.gg invite link.',
        ]);

        if ($data['enabled'] === 'true' && trim((string) ($data['bot_token'] ?? '')) === '' && trim((string) config('hydrodactyl.discord.bot_token')) === '') {
            $this->alert->danger('Add the bot token before turning the bot on.')->flash();

            return redirect()->route('admin.settings.discord')->withInput($request->except(['bot_token', 'client_secret']));
        }

        $this->settings->set(self::PREFIX . 'enabled', $data['enabled']);
        foreach (['application_id', 'public_key', 'channel_id', 'invite_url'] as $key) {
            $this->settings->set(self::PREFIX . $key, trim((string) ($data[$key] ?? '')));
        }
        // Secrets: blank keeps the saved value; they are never sent back to the browser.
        foreach (['bot_token', 'client_secret'] as $key) {
            $value = trim((string) ($data[$key] ?? ''));
            if ($request->boolean('clear_' . $key)) {
                $this->settings->set(self::PREFIX . $key, '');
            } elseif ($value !== '') {
                $this->settings->set(self::PREFIX . $key, $this->encrypter->encrypt($value));
            }
        }

        $this->kernel->call('queue:restart');
        $this->alert->success('Discord settings saved.')->flash();

        return redirect()->route('admin.settings.discord');
    }

    /**
     * Post a test message to the feed channel right now (not queued) so any
     * error shows up here.
     */
    public function test(): RedirectResponse
    {
        $channel = trim((string) config('hydrodactyl.discord.channel_id'));
        if (trim((string) config('hydrodactyl.discord.bot_token')) === '' || $channel === '') {
            $this->alert->danger('Save a bot token and a channel ID first.')->flash();

            return redirect()->route('admin.settings.discord');
        }

        try {
            $client = new DiscordClient();
            $bot = $client->me();
            $client->send('channel:' . $channel, ['embeds' => [[
                'title' => 'Panel connected',
                'color' => DiscordNotifier::GREEN,
                'description' => 'Server lifecycle, failures and security events will show up here.',
                'footer' => ['text' => (string) config('hydrodactyl.discord.brand')],
                'timestamp' => now()->toIso8601String(),
            ]]]);
            $this->alert->success('Sent a test message as ' . e((string) ($bot['username'] ?? 'the bot')) . '.' . (config('hydrodactyl.discord.enabled') ? '' : ' The bot is still turned off, so nothing else will be posted until you enable it.'))->flash();
        } catch (DiscordException $exception) {
            $hint = match ($exception->getCode()) {
                50001, 10003 => ' Invite the bot to the server and make sure it can see that channel.',
                50013 => ' Give the bot Send Messages and Embed Links in that channel.',
                default => str_contains($exception->getMessage(), '401') ? ' The bot token is wrong; reset it in the Developer Portal and paste it again.' : '',
            };
            $this->alert->danger(e($exception->getMessage()) . $hint)->flash();
        } catch (\Throwable $exception) {
            $this->alert->danger('Could not reach Discord: ' . e($exception->getMessage()))->flash();
        }

        return redirect()->route('admin.settings.discord');
    }
}
