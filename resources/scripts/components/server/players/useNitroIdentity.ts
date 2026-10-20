// Ported from Aquadactyl/MCPlayersJava (BSD 2-Clause, Copyright (c) Aquadactyl contributors).
import useSWR from 'swr';

import { getNitroIdentity, type NitroIdentity } from '@/api/server/players';

// Identity lookups are shared between the list and the dialog and cached for 20 minutes.
const useNitroIdentity = (name: string, enabled = true) =>
    useSWR<NitroIdentity>(
        enabled ? ['minecraft-players:nitrocraft', name.toLowerCase()] : null,
        () => getNitroIdentity(name),
        {
            dedupingInterval: 1200000,
            revalidateOnFocus: false,
            revalidateIfStale: false,
            shouldRetryOnError: false,
        },
    );

export default useNitroIdentity;
