import { useEffect, useState } from 'react';

import { unlinkDiscord, useDiscordLink } from '@/api/account/discord';
import { Button } from '@/components/ui/button';

const RESULTS: Record<string, { text: string; error?: boolean }> = {
    linked: { text: 'Linked. Check your Discord DMs for a confirmation.' },
    nodm: {
        text: "Linked, but the bot couldn't DM you. Join the Discord server and allow DMs from server members.",
        error: true,
    },
    taken: { text: 'That Discord account is already linked to another panel account.', error: true },
    cancelled: { text: 'Linking was cancelled.', error: true },
    expired: { text: 'That link expired. Try again.', error: true },
    failed: { text: "Couldn't link your Discord account. Try again in a minute.", error: true },
    disabled: { text: "Discord linking isn't set up on this panel yet.", error: true },
};

const DiscordLinkForm = () => {
    const { data, mutate } = useDiscordLink();
    const [result, setResult] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        const params = new URLSearchParams(window.location.search);
        const status = params.get('discord');
        if (status && RESULTS[status]) {
            setResult(status);
            params.delete('discord');
            const query = params.toString();
            window.history.replaceState(
                window.history.state,
                '',
                window.location.pathname + (query ? `?${query}` : ''),
            );
        }
    }, []);

    if (!data) {
        return <p className='text-sm text-zinc-400'>Loading...</p>;
    }

    const unlink = () => {
        setBusy(true);
        unlinkDiscord()
            .then(() => mutate())
            .then(() => setResult(null))
            .finally(() => setBusy(false));
    };

    const message = result ? RESULTS[result] : null;

    return (
        <div className='contents'>
            {message && (
                <p className={`text-sm mb-4 ${message.error ? 'text-amber-300' : 'text-green-300'}`}>{message.text}</p>
            )}
            {data.linked ? (
                <p className='text-sm'>
                    Linked to <span className='font-semibold'>{data.username ?? 'your Discord account'}</span>. The bot
                    DMs you when your servers finish installing, fail, or are changed by someone else, and about
                    security events on your account.
                </p>
            ) : (
                <p className='text-sm'>
                    Link your Discord account to get DMs about your servers and account security.
                    {data.invite_url && ' You need to be in our Discord server for the bot to DM you.'}
                </p>
            )}
            <div className='mt-6 flex flex-wrap gap-3'>
                {data.linked ? (
                    <Button variant='destructive' disabled={busy} onClick={unlink}>
                        Unlink Discord
                    </Button>
                ) : data.available ? (
                    <Button variant='secondary' asChild>
                        <a href='/account/discord/link'>Link Discord</a>
                    </Button>
                ) : (
                    <p className='text-sm text-zinc-400'>Discord isn't set up on this panel yet.</p>
                )}
                {data.invite_url && (
                    <Button variant='outline' asChild>
                        <a href={data.invite_url} target='_blank' rel='noopener noreferrer'>
                            Join the Discord
                        </a>
                    </Button>
                )}
            </div>
        </div>
    );
};

export default DiscordLinkForm;
