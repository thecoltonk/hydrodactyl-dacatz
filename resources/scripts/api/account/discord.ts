import useSWR from 'swr';

import http from '@/api/http';

export interface DiscordLinkInfo {
    /** Bot is on and OAuth is configured. */
    available: boolean;
    linked: boolean;
    username: string | null;
    invite_url: string | null;
}

export const useDiscordLink = () =>
    useSWR<DiscordLinkInfo>(
        'account:discord',
        async () => {
            const { data } = await http.get('/api/client/account/discord');
            return data as DiscordLinkInfo;
        },
        { revalidateOnFocus: false },
    );

export const unlinkDiscord = async (): Promise<void> => {
    await http.delete('/api/client/account/discord');
};
