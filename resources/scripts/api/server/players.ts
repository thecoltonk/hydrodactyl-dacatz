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
    online_mode?: boolean | null;
    capabilities?: PlayerCapabilities;
    skins?: Record<string, PlayerSkin>;
}

export interface PlayerSkin {
    name: string;
    url: string;
    variant: 'classic' | 'slim';
    updated_at: string;
}

export interface SkinResult {
    applied: boolean;
    provider: 'skinrestorer' | 'skinsrestorer' | null;
    message: string;
    url?: string;
}

export type PlayerAction =
    | 'kick'
    | 'ban'
    | 'pardon'
    | 'op'
    | 'deop'
    | 'whitelist_add'
    | 'whitelist_remove'
    | 'gamemode'
    | 'teleport'
    | 'teleport_to_player';

export type GameMode = 'survival' | 'creative' | 'adventure' | 'spectator';

export interface SavedLocation {
    dimension: string;
    position: number[];
}

export interface PlayerActionExtra {
    mode?: GameMode;
    dimension?: string;
    x?: number;
    y?: number;
    z?: number;
    target?: string;
}

export interface StatCount {
    id: string;
    count: number;
}

export interface StatGroup {
    total: number;
    top: StatCount[];
}

export interface PlayerStats {
    player: string;
    uuid: string;
    read_at: string;
    play_time_seconds: number;
    player_kills: number;
    mob_kills: number;
    deaths: number;
    kdr: number;
    mob_kdr: number;
    damage_dealt: number;
    damage_taken: number;
    jumps: number;
    time_since_death_seconds: number;
    distance_cm: number;
    distance: StatCount[];
    blocks_mined: StatGroup;
    items_used: StatGroup;
    items_crafted: StatGroup;
    mobs_killed: StatGroup;
    killed_by: StatGroup;
}

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
    game_mode?: GameMode | null;
    last_death?: SavedLocation | null;
    respawn?: SavedLocation | null;
    state: { operator: boolean | null; banned: boolean | null; whitelisted: boolean | null };
}

export const getPlayers = async (uuid: string): Promise<PlayerList> => {
    const { data } = await http.get<{ attributes: PlayerList }>(`/api/client/servers/${uuid}/players`);
    return data.attributes;
};

export const sendPlayerAction = async (
    uuid: string,
    name: string,
    action: PlayerAction,
    reason = '',
    extra: PlayerActionExtra = {},
) => {
    await http.post(`/api/client/servers/${uuid}/players/${encodeURIComponent(name)}/actions`, {
        action,
        reason: reason || null,
        confirmed: true,
        ...extra,
    });
};

const snapshotParams = (player: Player, save: boolean) => ({
    ...(player.uuid ? { uuid: player.uuid } : {}),
    ...(save ? { save: 1 } : {}),
});

export const getInventory = async (uuid: string, player: Player, save = false): Promise<InventorySnapshot> => {
    const { data } = await http.get<{ attributes: InventorySnapshot }>(
        `/api/client/servers/${uuid}/players/${encodeURIComponent(player.name)}/inventory`,
        { params: snapshotParams(player, save) },
    );
    return data.attributes;
};

export const getPlayerStats = async (uuid: string, player: Player, save = false): Promise<PlayerStats> => {
    const { data } = await http.get<{ attributes: PlayerStats }>(
        `/api/client/servers/${uuid}/players/${encodeURIComponent(player.name)}/stats`,
        { params: snapshotParams(player, save) },
    );
    return data.attributes;
};

const skinPath = (uuid: string, name: string) => `/api/client/servers/${uuid}/players/${encodeURIComponent(name)}/skin`;

export const uploadSkin = async (
    uuid: string,
    name: string,
    file: File,
    variant: 'classic' | 'slim',
): Promise<SkinResult> => {
    const form = new FormData();
    form.append('skin', file);
    form.append('variant', variant);
    const { data } = await http.post<{ attributes: SkinResult }>(skinPath(uuid, name), form, {
        // The client defaults to JSON, which would serialize away the file.
        headers: { 'Content-Type': 'multipart/form-data' },
    });
    return data.attributes;
};

export const applySkin = async (uuid: string, name: string): Promise<SkinResult> => {
    const { data } = await http.post<{ attributes: SkinResult }>(`${skinPath(uuid, name)}/apply`);
    return data.attributes;
};

export const deleteSkin = async (uuid: string, name: string): Promise<SkinResult> => {
    const { data } = await http.delete<{ attributes: SkinResult }>(skinPath(uuid, name));
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
