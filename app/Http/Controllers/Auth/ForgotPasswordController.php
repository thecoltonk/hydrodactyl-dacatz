<?php

namespace Pterodactyl\Http\Controllers\Auth;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Pterodactyl\Models\User;
use Illuminate\Support\Facades\Cache;
use Pterodactyl\Services\Discord\DiscordClient;
use Pterodactyl\Services\Discord\DiscordInvites;
use Illuminate\Support\Facades\Password;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Events\Auth\FailedPasswordReset;
use Illuminate\Foundation\Auth\SendsPasswordResetEmails;

class ForgotPasswordController extends Controller
{
    use SendsPasswordResetEmails {
        sendResetLinkEmail as protected sendResetEmailByMail;
    }

    private const SENT = "If that account exists, a reset link is on its way: by Discord DM if the account has Discord linked, otherwise by email if this panel sends email. Didn't get one? Ask an admin to reset it.";

    /**
     * Send the reset link by Discord DM when the account has Discord linked,
     * otherwise by email when mail is set up. The reply never says which (or
     * whether the account exists).
     */
    public function sendResetLinkEmail(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email|max:191']);
        $user = User::query()->where('email', $request->input('email'))->first();

        if ($user !== null && $user->discord_id && DiscordClient::configured()) {
            // One DM per account every two minutes.
            if (Cache::add('password-reset-dm:' . $user->id, 1, 120)) {
                try {
                    DiscordInvites::sendSetupLink(
                        $user,
                        ['id' => $user->discord_id, 'username' => (string) ($user->discord_username ?? $user->discord_id)],
                        "Someone asked to reset your panel password. If that wasn't you, ignore this message: your password stays the same.",
                        'Reset your password',
                    );
                } catch (\Throwable $exception) {
                    report($exception);
                }
            }

            return response()->json(['status' => self::SENT]);
        }

        if (config('mail.default') && config('mail.default') !== 'log') {
            $this->sendResetEmailByMail($request);
        } else {
            event(new FailedPasswordReset($request->ip(), $request->input('email')));
        }

        return response()->json(['status' => self::SENT]);
    }

    /**
     * Get the response for a failed password reset link.
     */
    protected function sendResetLinkFailedResponse(Request $request, $response): JsonResponse
    {
        // As noted in #358 we will return success even if it failed
        // to avoid pointing out that an account does or does not
        // exist on the system.
        event(new FailedPasswordReset($request->ip(), $request->input('email')));

        return $this->sendResetLinkResponse($request, Password::RESET_LINK_SENT);
    }

    /**
     * Get the response for a successful password reset link.
     *
     * @param string $response
     */
    protected function sendResetLinkResponse(Request $request, $response): JsonResponse
    {
        return response()->json([
            'status' => trans($response),
        ]);
    }
}
