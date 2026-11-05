<?php

namespace Pterodactyl\Services\Subusers;

use Illuminate\Support\Str;
use Pterodactyl\Models\User;
use Pterodactyl\Models\Server;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Services\Discord\DiscordInvites;
use Pterodactyl\Services\Discord\DiscordNotifier;
use Pterodactyl\Models\Subuser;
use Illuminate\Database\ConnectionInterface;
use Pterodactyl\Services\Users\UserCreationService;
use Pterodactyl\Repositories\Eloquent\SubuserRepository;
use Pterodactyl\Contracts\Repository\UserRepositoryInterface;
use Pterodactyl\Exceptions\Repository\RecordNotFoundException;
use Pterodactyl\Exceptions\Service\Subuser\UserIsServerOwnerException;
use Pterodactyl\Exceptions\Service\Subuser\ServerSubuserExistsException;

class SubuserCreationService
{
    /**
     * SubuserCreationService constructor.
     */
    public function __construct(
        private ConnectionInterface $connection,
        private SubuserRepository $subuserRepository,
        private UserCreationService $userCreationService,
        private UserRepositoryInterface $userRepository,
    ) {
    }

    /**
     * Creates a new user on the system and assigns them access to the provided server.
     * If the email address already belongs to a user on the system a new user will not
     * be created.
     *
     * @throws \Pterodactyl\Exceptions\Model\DataValidationException
     * @throws ServerSubuserExistsException
     * @throws UserIsServerOwnerException
     * @throws \Throwable
     */
    public function handle(Server $server, string $email, array $permissions, ?string $discord = null): Subuser
    {
        // Discord invites: resolve the person before creating anything, so a
        // wrong username fails cleanly.
        $invite = null;
        $existing = User::query()->where('email', $email)->first();
        if ($existing === null && DiscordInvites::enabled()) {
            if ($discord === null || trim($discord) === '') {
                throw new DisplayException("There's no account with that email yet. Add their Discord username and the bot will DM them an invite.");
            }
            $invite = DiscordInvites::findMember($discord);
            if (User::query()->where('discord_id', $invite['id'])->exists()) {
                throw new DisplayException('That Discord account is already linked to a panel account. Use that account\'s email instead.');
            }
        }

        $subuser = $this->connection->transaction(function () use ($server, $email, $permissions, $invite) {
            try {
                $user = $this->userRepository->findFirstWhere([['email', '=', $email]]);

                if ($server->owner_id === $user->id) {
                    throw new UserIsServerOwnerException(trans('exceptions.subusers.user_is_owner'));
                }

                $subuserCount = $this->subuserRepository->findCountWhere([['user_id', '=', $user->id], ['server_id', '=', $server->id]]);
                if ($subuserCount !== 0) {
                    throw new ServerSubuserExistsException(trans('exceptions.subusers.subuser_exists'));
                }
            } catch (RecordNotFoundException) {
                if ($invite !== null) {
                    $user = $this->userCreationService->handle([
                        'email' => $email,
                        'username' => DiscordInvites::usernameFor($invite, $email),
                        'name_first' => 'Server',
                        'name_last' => 'Subuser',
                        'root_admin' => false,
                    ]);

                    return $this->subuserRepository->create([
                        'user_id' => $user->id,
                        'server_id' => $server->id,
                        'permissions' => array_unique($permissions),
                    ]);
                }

                // Without email the invited person could never set a password, so
                // only existing accounts can be added unless mail is enabled.
                if (!config('mail.default') || config('mail.default') === 'log') {
                    throw new DisplayException('There is no account with that email yet. Ask an admin to create one first, then add it here.');
                }

                // Just cap the username generated at 64 characters at most and then append a random string
                // to the end to make it "unique"...
                $username = substr(preg_replace('/([^\w\.-]+)/', '', strtok($email, '@')), 0, 64) . Str::random(3);

                $user = $this->userCreationService->handle([
                    'email' => $email,
                    'username' => $username,
                    'name_first' => 'Server',
                    'name_last' => 'Subuser',
                    'root_admin' => false,
                ]);
            }

            return $this->subuserRepository->create([
                'user_id' => $user->id,
                'server_id' => $server->id,
                'permissions' => array_unique($permissions),
            ]);
        });

        $user = $subuser->user;
        if ($invite !== null) {
            DiscordInvites::link($user, $invite);
            DiscordInvites::sendSetupLink($user, $invite, sprintf('You were added to the server "%s".', $server->name));
        } elseif ($user?->discord_id) {
            DiscordNotifier::send([
                'author' => ['name' => mb_substr($server->name, 0, 100)],
                'title' => 'You were added to a server',
                'color' => DiscordNotifier::BLUE,
                'description' => 'You now have access to **' . DiscordNotifier::escape($server->name) . '** on the panel.',
            ], $user, false, false, ['Open server', url('/server/' . $server->uuidShort)]);
        }

        return $subuser;
    }
}
