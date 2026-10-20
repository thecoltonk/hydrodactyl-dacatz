// Player list, moderation and saved inventories for Minecraft Java servers.
// Ported from Aquadactyl/MCPlayersJava (BSD 2-Clause, Copyright (c) Aquadactyl contributors).
// See licenses/MCPlayersJava.LICENSE.
import {
    ArrowsRotateRight,
    Check,
    CircleInfo,
    Copy,
    Magnifier,
    PersonGear,
    Persons,
    PlugConnection,
    Server,
    ShieldCheck,
} from '@gravity-ui/icons';
import type { AxiosError } from 'axios';
import { useEffect, useState } from 'react';
import useSWR from 'swr';

import { httpErrorToHuman } from '@/api/http';
import { getPlayers, type Player, type PlayerList } from '@/api/server/players';
import { Input } from '@/components/elements/inputs';
import { MainPageHeader } from '@/components/elements/MainPageHeader';
import ServerContentBlock from '@/components/elements/ServerContentBlock';
import ServerHeader from '@/components/server/header/ServerHeader';
import { Button } from '@/components/ui/button';

import { ServerContext } from '@/state/server';

import PlayerAvatar from './PlayerAvatar';
import PlayerDialog from './PlayerDialog';
import './players.css';

const icon = (Icon: typeof Persons, size: number, className?: string) => (
    <Icon width={size} height={size} className={className} aria-hidden />
);

const STOP_POLLING = [401, 403, 404, 409, 429];

const PlayersContainer = () => {
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const [search, setSearch] = useState('');
    const [automatic, setAutomatic] = useState(true);
    const [halted, setHalted] = useState(false);
    const [notice, setNotice] = useState('');
    const [lookup, setLookup] = useState('');
    const [selectedPlayer, setSelectedPlayer] = useState<Player | null>(null);

    // biome-ignore lint/correctness/useExhaustiveDependencies: reset when switching servers
    useEffect(() => {
        setSelectedPlayer(null);
        setHalted(false);
    }, [uuid]);

    const query = useSWR<PlayerList, AxiosError>(['minecraft-players', uuid], () => getPlayers(uuid), {
        refreshInterval: (latest) => {
            if (!automatic || halted || latest?.status === 'unsupported') return 0;
            return latest?.status === 'pending' ? 2000 : 15000;
        },
        refreshWhenHidden: false,
        revalidateOnFocus: true,
        shouldRetryOnError: false,
        dedupingInterval: 2000,
        onError: (error) => {
            if (STOP_POLLING.includes(error.response?.status ?? 0)) setHalted(true);
        },
        onSuccess: () => setHalted(false),
    });

    useEffect(() => {
        if (!notice) return;
        const timeout = window.setTimeout(() => setNotice(''), 3000);
        return () => window.clearTimeout(timeout);
    }, [notice]);

    const data = query.data;
    const isError = !!query.error;
    const capabilities = data?.capabilities ?? { moderate: false, operator: false, inventory: false };
    const available = data?.status === 'available';
    const unsupported = data?.status === 'unsupported';
    const busy = query.isValidating;
    const players = data?.players ?? [];
    const filtered = players.filter((player) => player.name.toLowerCase().includes(search.trim().toLowerCase()));
    const copy = async (value: string, description: string) => {
        try {
            await navigator.clipboard.writeText(value);
            setNotice(description);
        } catch {
            setNotice('Copy failed. Select the name and copy it manually.');
        }
    };
    const updated = data?.checked_at
        ? new Date(data.checked_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' })
        : null;
    const health = isError
        ? 'Update failed'
        : available
          ? 'Online'
          : unsupported
            ? 'Unsupported'
            : query.isLoading || data?.status === 'pending'
              ? 'Checking'
              : 'Unreachable';

    return (
        <ServerContentBlock title={'Players'}>
            <ServerHeader />
            <div className='mcp-page w-full h-full min-h-full flex-1 flex flex-col px-2 sm:px-0'>
                <MainPageHeader
                    direction='column'
                    title={'Players'}
                    titleChildren={
                        <div className='flex gap-3 items-center flex-wrap'>
                            <span className={`mcp-status ${available && !isError ? 'mcp-status-online' : ''}`}>
                                <span aria-hidden />
                                {health}
                            </span>
                            <Button
                                variant='secondary'
                                disabled={busy}
                                onClick={() => void query.mutate()}
                                aria-label='Refresh player list'
                            >
                                <span className='mcp-button-content'>
                                    {icon(ArrowsRotateRight, 16, busy ? 'mcp-spin' : undefined)}
                                    Refresh
                                </span>
                            </Button>
                        </div>
                    }
                >
                    <p className='mcp-subtitle'>Player profiles, server actions and saved inventories.</p>
                </MainPageHeader>

                {isError && (
                    <div className='mcp-alert mcp-alert-warning' role='alert'>
                        {icon(PlugConnection, 19)}
                        <div>
                            <strong>Could not update the player list.</strong>
                            <p>
                                {httpErrorToHuman(query.error!)}{' '}
                                {data && 'The last result is shown below; it may be out of date.'}
                            </p>
                        </div>
                    </div>
                )}

                {!unsupported && (
                    <div className='mcp-stats'>
                        <div className='mcp-stat'>
                            {icon(Persons, 19)}
                            <span>{isError ? 'Last reported online' : 'Players online'}</span>
                            <strong>
                                {available ? data.online : '—'}
                                <small> / {available ? data.maximum : '—'}</small>
                            </strong>
                        </div>
                        <div className='mcp-stat'>
                            {icon(ShieldCheck, 19)}
                            <span>Names returned</span>
                            <strong>
                                {available ? players.length : '—'}
                                <small>{available && (data.complete ? 'full list' : 'partial list')}</small>
                            </strong>
                        </div>
                        <div className='mcp-stat'>
                            {icon(Server, 19)}
                            <span>List source</span>
                            <strong className='mcp-stat-source'>
                                {available ? (data.source === 'query' ? 'Minecraft Query' : 'Server status') : '—'}
                                <small>
                                    {available
                                        ? data.source === 'query'
                                            ? 'UDP query'
                                            : 'Status sample'
                                        : 'Waiting for response'}
                                </small>
                            </strong>
                        </div>
                    </div>
                )}

                {available && !data.complete && (
                    <div className='mcp-alert' role='status'>
                        {icon(CircleInfo, 19)}
                        <div>
                            <strong>
                                {data.source === 'status'
                                    ? 'These names are a status sample.'
                                    : 'The server returned a partial player list.'}
                            </strong>
                            <p>
                                {data.source === 'status'
                                    ? 'Minecraft status shows at most 12 names, so this may not include everyone online. Enable Query for the full list (see below).'
                                    : 'Some names were missing or could not be displayed. The reported online count is shown above.'}
                            </p>
                        </div>
                    </div>
                )}

                {(capabilities.moderate || capabilities.inventory) && (
                    <form
                        className='mcp-lookup'
                        onSubmit={(event) => {
                            event.preventDefault();
                            const name = lookup.trim();
                            if (/^[A-Za-z0-9_]{1,16}$/u.test(name))
                                setSelectedPlayer({
                                    name,
                                    uuid:
                                        players.find((player) => player.name.toLowerCase() === name.toLowerCase())
                                            ?.uuid ?? null,
                                });
                        }}
                    >
                        <label htmlFor='mcp-lookup'>Open any player, including offline players</label>
                        <div>
                            <Input.Text
                                id='mcp-lookup'
                                value={lookup}
                                onChange={(event) => setLookup(event.target.value)}
                                placeholder='Minecraft username'
                                maxLength={16}
                                pattern='[A-Za-z0-9_]{1,16}'
                                required
                            />
                            <Button type='submit' variant='secondary'>
                                <span className='mcp-button-content'>
                                    {icon(PersonGear, 16)}
                                    Open player
                                </span>
                            </Button>
                        </div>
                    </form>
                )}

                <section className='mcp-panel' aria-label='Player list' aria-busy={busy}>
                    <div className='mcp-toolbar'>
                        <div>
                            <h2>{isError && data ? 'Last reported players' : 'Player list'}</h2>
                            <p>
                                {available
                                    ? `${players.length} ${players.length === 1 ? 'name' : 'names'} returned · Sorted A–Z`
                                    : 'Live names from your Minecraft server'}
                            </p>
                        </div>
                        <div className='mcp-toolbar-actions'>
                            <label className='mcp-search' htmlFor='mcp-search'>
                                {icon(Magnifier, 17)}
                                <Input.Text
                                    id='mcp-search'
                                    type='search'
                                    aria-label='Search players'
                                    placeholder='Search players…'
                                    value={search}
                                    onChange={(event) => setSearch(event.target.value)}
                                    disabled={!players.length}
                                />
                            </label>
                            <Button
                                variant='secondary'
                                disabled={!players.length}
                                onClick={() =>
                                    void copy(players.map((player) => player.name).join('\n'), 'Player names copied.')
                                }
                            >
                                <span className='mcp-button-content'>
                                    {icon(Copy, 15)}
                                    Copy names
                                </span>
                            </Button>
                        </div>
                    </div>

                    {query.isLoading || data?.status === 'pending' ? (
                        <div className='mcp-empty'>
                            {icon(ArrowsRotateRight, 30, 'mcp-spin')}
                            <h3>Checking your server</h3>
                            <p>Retrieving the latest player list…</p>
                        </div>
                    ) : unsupported ? (
                        <div className='mcp-empty'>
                            {icon(Server, 30)}
                            <h3>Minecraft Java servers only</h3>
                            <p>
                                Player management works on Minecraft Java game servers (Paper, Purpur, Fabric, Forge,
                                Vanilla and similar). Proxies like Velocity are not supported.
                            </p>
                        </div>
                    ) : !available ? (
                        <div className='mcp-empty'>
                            {icon(PlugConnection, 30)}
                            <h3>{isError ? 'Player list unavailable' : 'Your server could not be reached'}</h3>
                            <p>
                                {isError
                                    ? 'Try refreshing once access or connectivity is restored.'
                                    : 'The server may be offline or still starting. Start it and refresh. You can still open offline players by name above.'}
                            </p>
                        </div>
                    ) : !players.length ? (
                        <div className='mcp-empty'>
                            {icon(Persons, 30)}
                            <h3>{data.online === 0 ? 'No players online' : 'Player names are hidden'}</h3>
                            <p>
                                {data.online === 0
                                    ? 'Players will appear here when they join your server.'
                                    : "The server reports players online but returned no displayable names. Enable Query and check your server's player privacy settings."}
                            </p>
                        </div>
                    ) : !filtered.length ? (
                        <div className='mcp-empty'>
                            {icon(Magnifier, 30)}
                            <h3>No matching players</h3>
                            <p>Try a different player name.</p>
                        </div>
                    ) : (
                        <ul className='mcp-player-grid'>
                            {filtered.map((player) => (
                                <li className='mcp-player' key={player.name.toLowerCase()}>
                                    <PlayerAvatar name={player.name} />
                                    <div className='mcp-player-info'>
                                        <strong>{player.name}</strong>
                                        <span>{isError ? 'Last reported' : 'Reported online'}</span>
                                    </div>
                                    <button
                                        type='button'
                                        className='mcp-copy'
                                        onClick={() => void copy(player.name, `${player.name} copied.`)}
                                        aria-label={`Copy ${player.name}`}
                                        title='Copy username'
                                    >
                                        {icon(Copy, 16)}
                                    </button>
                                    <button
                                        type='button'
                                        className='mcp-copy'
                                        aria-label={`Manage ${player.name}`}
                                        title='Player profile and actions'
                                        onClick={() => setSelectedPlayer(player)}
                                    >
                                        {icon(PersonGear, 17)}
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}

                    <footer className='mcp-footer'>
                        <span>
                            {updated ? `Last checked at ${updated}` : 'No result yet'}
                            {available && search.trim() ? ` · Showing ${filtered.length} of ${players.length}` : ''}
                        </span>
                        <label htmlFor='mcp-auto-refresh'>
                            <Input.Checkbox
                                id='mcp-auto-refresh'
                                checked={automatic}
                                onChange={(event) => setAutomatic(event.target.checked)}
                            />
                            <span>Auto-refresh every 15s</span>
                        </label>
                    </footer>
                </section>

                <p className='mcp-notice' role='status' aria-live='polite'>
                    {notice && (
                        <>
                            {icon(Check, 15)}
                            {notice}
                        </>
                    )}
                </p>

                <p className='mcp-nitro-credit'>
                    Player skins by{' '}
                    <a href='https://nitrocraft.uk' target='_blank' rel='noreferrer'>
                        Nitrocraft
                    </a>
                    .
                </p>

                {selectedPlayer && (
                    <PlayerDialog
                        key={`${uuid}:${selectedPlayer.name}`}
                        uuid={uuid}
                        player={selectedPlayer}
                        capabilities={capabilities}
                        onClose={() => setSelectedPlayer(null)}
                        onNotice={setNotice}
                    />
                )}

                <details className='mcp-setup'>
                    <summary>
                        {icon(CircleInfo, 17)}
                        Get the full player list
                    </summary>
                    <div>
                        <p>
                            Enable Minecraft Query in <code>server.properties</code> and restart the server:
                        </p>
                        <pre>
                            <code>{'enable-query=true\nquery.port=25565'}</code>
                        </pre>
                        <p>
                            Use your server's own port (shown on the console page) for <code>query.port</code>. Without
                            Query, the panel falls back to the server status ping, which only shows up to 12 names.
                        </p>
                        <p>Inventories are read from the world save, so no Minecraft plugin or RCON is needed.</p>
                    </div>
                </details>
            </div>
        </ServerContentBlock>
    );
};

export default PlayersContainer;
