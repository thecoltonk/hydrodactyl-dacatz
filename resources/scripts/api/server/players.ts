// Ported from Aquadactyl/MCPlayersJava (BSD 2-Clause, Copyright (c) Aquadactyl contributors).
// See licenses/MCPlayersJava.LICENSE.
import http from '@/api/http';

export interface Player {
    name: string;
    uuid: string | null;
}

export interface PlayerCapabilities {
    moderate: boolean;
    operator: boolean;
    inventory: boolean;
}

export interface PlayerList {
    status: 'available' | 'unavailable' | 'unsupported' | 'pending';
    source: 'query' | 'status' | null;
    complete: boolean;
    online: number | null;
    maximum: number | null;
    players: Player[];
    checked_at: string | null;
    capabilities?: PlayerCapabilities;
}

export type PlayerAction = 'kick' | 'ban' | 'pardon' | 'op' | 'deop' | 'whitelist_add' | 'whitelist_remove';

export interface InventoryItem {
    slot: number;
    id: string;
    count: number;
    metadata: unknown;
    metadata_truncated: boolean;
}

export interface InventorySnapshot {
    source: 'saved_file';
    player: string;
    uuid: string;
    saved_at: string | null;
    read_at: string;
    inventory: InventoryItem[];
    ender_chest: InventoryItem[];
    health: number | null;
    food: number | null;
    xp_level: number | null;
    xp_progress?: number | null;
    selected_slot: number | null;
    dimension: string | null;
    position: number[] | null;
    data_version: number | null;
    state: { operator: boolean | null; banned: boolean | null; whitelisted: boolean | null };
}

export const getPlayers = async (uuid: string): Promise<PlayerList> => {
    const { data } = await http.get<{ attributes: PlayerList }>(`/api/client/servers/${uuid}/players`);
    return data.attributes;
};

export const sendPlayerAction = async (uuid: string, name: string, action: PlayerAction, reason = '') => {
    await http.post(`/api/client/servers/${uuid}/players/${encodeURIComponent(name)}/actions`, {
        action,
        reason: reason || null,
        confirmed: true,
    });
};

export const getInventory = async (uuid: string, player: Player): Promise<InventorySnapshot> => {
    const { data } = await http.get<{ attributes: InventorySnapshot }>(
        `/api/client/servers/${uuid}/players/${encodeURIComponent(player.name)}/inventory`,
        { params: player.uuid ? { uuid: player.uuid } : undefined },
    );
    return data.attributes;
};

// Skins and profile identity come from the public Nitrocraft API. Requests carry
// no panel credentials or referrer.
export const NITROCRAFT = 'https://nitrocraft.uk';

export interface NitroIdentity {
    id: string;
    name: string;
}

export const getNitroIdentity = async (name: string): Promise<NitroIdentity> => {
    if (!/^[A-Za-z0-9_]{1,16}$/u.test(name)) throw new Error('Invalid Minecraft username.');
    const response = await fetch(`${NITROCRAFT}/players/${encodeURIComponent(name)}`, {
        credentials: 'omit',
        referrerPolicy: 'no-referrer',
        signal: AbortSignal.timeout(8000),
    });
    if (!response.ok) throw new Error('Nitrocraft player lookup is unavailable.');
    const text = await response.text();
    if (text.length > 32768) throw new Error('Unexpected Nitrocraft response.');
    const identity = JSON.parse(text) as Partial<NitroIdentity> | null;
    const id = typeof identity?.id === 'string' ? identity.id.replaceAll('-', '') : null;
    if (
        !id ||
        !/^[0-9a-f]{32}$/iu.test(id) ||
        typeof identity?.name !== 'string' ||
        !/^[A-Za-z0-9_]{1,16}$/u.test(identity.name)
    ) {
        throw new Error('Invalid Nitrocraft identity.');
    }
    return { id: id.toLowerCase(), name: identity.name };
};

export const nitroImage = (id: string, body = false) => {
    if (!/^[0-9a-f]{32}$/iu.test(id)) throw new Error('Invalid skin UUID.');
    return body ? `${NITROCRAFT}/renders/body/${id}?scale=4&overlay` : `${NITROCRAFT}/avatars/${id}?size=80&overlay`;
};
