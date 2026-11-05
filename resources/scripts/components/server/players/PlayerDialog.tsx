// Ported from Aquadactyl/MCPlayersJava (BSD 2-Clause, Copyright (c) Aquadactyl contributors).
import {
    ArrowsRotateRight,
    BookOpen,
    ChartColumn,
    CrownDiamond,
    MapPin,
    Person,
    Shield,
    TriangleExclamation,
} from '@gravity-ui/icons';
import { useRef, useState } from 'react';
import useSWR, { useSWRConfig } from 'swr';

import { httpErrorToHuman } from '@/api/http';
import {
    editInventory,
    type GameMode,
    getInventory,
    getPlayerStats,
    type InventoryArea,
    type InventoryChange,
    NITROCRAFT,
    nitroImage,
    type Player,
    type PlayerAction,
    type PlayerActionExtra,
    type PlayerCapabilities,
    type PlayerSkin,
    sendPlayerAction,
    wipePlayer,
} from '@/api/server/players';
import { Dialog } from '@/components/elements/dialog';
import { Input } from '@/components/elements/inputs';
import { Button } from '@/components/ui/button';

import InventoryView from './InventoryView';
import LocationsPanel, { dimensionLabel, type TeleportTarget } from './LocationsPanel';
import PlayerAvatar from './PlayerAvatar';
import SkinPanel from './SkinPanel';
import StatsPanel from './StatsPanel';
import useNitroIdentity from './useNitroIdentity';

export const actions: {
    action: PlayerAction;
    label: string;
    description: string;
    owner?: boolean;
    reason?: boolean;
}[] = [
    {
        action: 'kick',
        label: 'Kick',
        description: 'Disconnect this player from the server. They can rejoin.',
        reason: true,
    },
    { action: 'ban', label: 'Ban', description: 'Ban this player until an administrator unbans them.', reason: true },
    { action: 'pardon', label: 'Unban', description: "Remove this player's username ban." },
    {
        action: 'op',
        label: 'OP',
        description: 'Grant operator permissions, including powerful server commands.',
        owner: true,
    },
    { action: 'deop', label: 'De-OP', description: 'Remove operator permissions from this player.', owner: true },
    {
        action: 'whitelist_add',
        label: 'Add to whitelist',
        description: 'Allow this player to join when the whitelist is enabled.',
    },
    {
        action: 'whitelist_remove',
        label: 'Remove from whitelist',
        description: 'Remove this player from the whitelist.',
    },
];

const GAME_MODES: { mode: GameMode; label: string }[] = [
    { mode: 'survival', label: 'Survival' },
    { mode: 'creative', label: 'Creative' },
    { mode: 'adventure', label: 'Adventure' },
    { mode: 'spectator', label: 'Spectator' },
];

type Tab = 'profile' | 'locations' | 'stats' | 'inventory';

const icon = (Icon: typeof Person, size = 15, className?: string) => (
    <Icon width={size} height={size} className={className} aria-hidden />
);

export default function PlayerDialog({
    uuid,
    player,
    capabilities,
    onClose,
    onNotice,
    offline = false,
    skin,
    onSkinChanged = () => undefined,
}: {
    uuid: string;
    player: Player;
    capabilities: PlayerCapabilities;
    onClose: () => void;
    onNotice: (message: string) => void;
    /** Server runs with online-mode=false. */
    offline?: boolean;
    skin?: PlayerSkin;
    onSkinChanged?: () => void;
}) {
    const [tab, setTab] = useState<Tab>('profile');
    const [action, setAction] = useState<PlayerAction | null>(null);
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [submitted, setSubmitted] = useState('');
    const [renderFailed, setRenderFailed] = useState(false);
    const { mutate } = useSWRConfig();
    const identity = useNitroIdentity(player.name, !offline);
    // Set before a refresh to have the server flush player data first (save-all).
    const saveNext = useRef(false);
    const takeSave = () => {
        const save = saveNext.current;
        saveNext.current = false;
        return save;
    };
    const inventory = useSWR(
        capabilities.inventory ? ['minecraft-players:inventory', uuid, player.name, player.uuid] : null,
        () => getInventory(uuid, player, takeSave()),
        { revalidateOnFocus: false, shouldRetryOnError: false, dedupingInterval: 10000 },
    );
    const stats = useSWR(
        tab === 'stats' && capabilities.inventory ? ['minecraft-players:stats', uuid, player.name, player.uuid] : null,
        () => getPlayerStats(uuid, player, takeSave()),
        { revalidateOnFocus: false, shouldRetryOnError: false, dedupingInterval: 10000 },
    );
    const saveAndRefresh = () => {
        saveNext.current = capabilities.moderate;
        void (tab === 'stats' ? stats.mutate() : inventory.mutate());
    };

    const quick = async (next: PlayerAction, extra: PlayerActionExtra, message: string) => {
        if (busy) return;
        setBusy(true);
        setError('');
        setSubmitted('');
        try {
            await sendPlayerAction(uuid, player.name, next, '', extra);
            setSubmitted(message);
            onNotice(message);
        } catch (failure) {
            setError(httpErrorToHuman(failure as Error));
        } finally {
            setBusy(false);
        }
    };
    const edit = async (change: InventoryChange) => {
        if (busy) return;
        setBusy(true);
        setError('');
        setSubmitted('');
        try {
            const result = await editInventory(uuid, player, [change]);
            setSubmitted(result.message);
            onNotice(result.message);
            // Online edits land in the live game; save so the file view catches up.
            saveNext.current = result.mode === 'online';
            await inventory.mutate();
        } catch (failure) {
            setError(httpErrorToHuman(failure as Error));
        } finally {
            setBusy(false);
        }
    };
    // Two-step confirm for destructive one-click actions.
    const [confirming, setConfirming] = useState<string | null>(null);
    const [wiping, setWiping] = useState(false);
    const [wipeName, setWipeName] = useState('');
    const confirmThen = (key: string, run: () => void) => {
        if (confirming === key) {
            setConfirming(null);
            run();
        } else {
            setConfirming(key);
        }
    };
    const clearArea = async (area: InventoryArea) => {
        const items = inventory.data?.[area] ?? [];
        if (busy || !items.length) return;
        setBusy(true);
        setError('');
        setSubmitted('');
        try {
            const changes: InventoryChange[] = items.map((item) => ({
                area,
                slot: item.slot,
                id: null,
                count: null,
                expect: item.id,
            }));
            const result = await editInventory(uuid, player, changes);
            const message = `${area === 'inventory' ? 'Inventory' : 'Ender chest'} cleared. ${result.message}`;
            setSubmitted(message);
            onNotice(message);
            saveNext.current = result.mode === 'online';
            await inventory.mutate();
        } catch (failure) {
            setError(httpErrorToHuman(failure as Error));
        } finally {
            setBusy(false);
        }
    };
    const wipe = async () => {
        if (busy || wipeName !== player.name) return;
        setBusy(true);
        setError('');
        setSubmitted('');
        try {
            const message = await wipePlayer(uuid, player);
            setSubmitted(message);
            onNotice(message);
            setWiping(false);
            setWipeName('');
            await inventory.mutate();
        } catch (failure) {
            setError(httpErrorToHuman(failure as Error));
        } finally {
            setBusy(false);
        }
    };
    const teleport = (target: TeleportTarget, label: string) =>
        void quick(
            'teleport',
            target,
            `Teleporting ${player.name} to ${label} (${Math.round(target.x)}, ${Math.round(target.y)}, ${Math.round(target.z)}, ${dimensionLabel(target.dimension)}). They must be online.`,
        );
    const definition = actions.find((entry) => entry.action === action);

    const submit = async (event: React.FormEvent) => {
        event.preventDefault();
        if (!action || busy) return;
        setBusy(true);
        setError('');
        try {
            await sendPlayerAction(uuid, player.name, action, reason);
            const message = `${definition?.label} command for ${player.name} submitted. Check the console for Minecraft's response.`;
            setSubmitted(message);
            onNotice(message);
            setAction(null);
            setReason('');
            void mutate(['minecraft-players', uuid]);
        } catch (failure) {
            setError(httpErrorToHuman(failure as Error));
        } finally {
            setBusy(false);
        }
    };

    return (
        <Dialog
            open
            title={definition ? `${definition.label} ${player.name}` : player.name}
            description={
                definition ? definition.description : 'Profile, locations, stats, inventory and server actions.'
            }
            onClose={() => {
                if (!busy) onClose();
            }}
            preventExternalClose={busy}
            hideCloseIcon={busy}
        >
            <div className='mcp-player-dialog'>
                {definition ? (
                    <form onSubmit={(event) => void submit(event)} className='mcp-confirm-action'>
                        <div className='mcp-alert mcp-alert-warning'>
                            {icon(TriangleExclamation, 18)}
                            <div>
                                <strong>{definition.description}</strong>
                                <p>This sends a command to this Minecraft server. It runs when the server is online.</p>
                            </div>
                        </div>
                        {definition.reason && (
                            <label className='mcp-reason' htmlFor='mcp-reason'>
                                Reason (optional)
                                <Input.Text
                                    id='mcp-reason'
                                    autoFocus
                                    maxLength={120}
                                    value={reason}
                                    onChange={(event) => setReason(event.target.value)}
                                    placeholder='Enter a reason…'
                                />
                            </label>
                        )}
                        {error && (
                            <p className='mcp-tool-error' role='alert'>
                                {error}
                            </p>
                        )}
                        <div className='mcp-dialog-actions'>
                            <Button
                                variant='secondary'
                                type='button'
                                disabled={busy}
                                onClick={() => {
                                    setAction(null);
                                    setError('');
                                    setReason('');
                                }}
                            >
                                Cancel
                            </Button>
                            <Button
                                variant={action === 'ban' || action === 'op' ? 'destructive' : 'default'}
                                type='submit'
                                disabled={busy}
                            >
                                <span className='mcp-button-content'>
                                    {busy && icon(ArrowsRotateRight, 15, 'mcp-spin')}Confirm {definition.label}
                                </span>
                            </Button>
                        </div>
                    </form>
                ) : (
                    <>
                        <div className='mcp-profile-header'>
                            <PlayerAvatar name={player.name} skinUrl={skin?.url} offline={offline} />
                            <div>
                                <strong>{player.name}</strong>
                                <p>Minecraft Java player</p>
                            </div>
                        </div>
                        <div className='mcp-dialog-tabs' role='tablist' aria-label='Player details'>
                            {(
                                [
                                    ['profile', 'Profile', Person, false],
                                    ['locations', 'Locations', MapPin, true],
                                    ['stats', 'Stats', ChartColumn, true],
                                    ['inventory', 'Inventory', BookOpen, true],
                                ] as const
                            ).map(([key, label, Icon, needsFiles]) => (
                                <button
                                    key={key}
                                    type='button'
                                    role='tab'
                                    aria-selected={tab === key}
                                    aria-controls={`mcp-${key}-panel`}
                                    id={`mcp-${key}-tab`}
                                    disabled={needsFiles && !capabilities.inventory}
                                    title={
                                        needsFiles && !capabilities.inventory
                                            ? 'Requires file read and file content permissions'
                                            : undefined
                                    }
                                    onClick={() => setTab(key)}
                                >
                                    {icon(Icon)}
                                    {label}
                                </button>
                            ))}
                        </div>
                        {error && (
                            <p className='mcp-tool-error' role='alert'>
                                {error}
                            </p>
                        )}
                        {submitted && (
                            <p className='mcp-submitted' role='status'>
                                {submitted}
                            </p>
                        )}
                        {tab === 'profile' ? (
                            <div id='mcp-profile-panel' role='tabpanel' aria-labelledby='mcp-profile-tab'>
                                {offline ? (
                                    <SkinPanel
                                        uuid={uuid}
                                        name={player.name}
                                        skin={skin}
                                        canEdit={Boolean(capabilities.skins ?? capabilities.moderate)}
                                        onChanged={onSkinChanged}
                                    />
                                ) : (
                                    <>
                                        <div className='mcp-profile-render'>
                                            {identity.data && !renderFailed ? (
                                                <img
                                                    src={nitroImage(identity.data.id, true)}
                                                    alt={`${player.name}'s Minecraft skin`}
                                                    referrerPolicy='no-referrer'
                                                    onError={() => setRenderFailed(true)}
                                                />
                                            ) : (
                                                <span>
                                                    {identity.isLoading ? 'Loading skin…' : 'Skin render unavailable'}
                                                </span>
                                            )}
                                        </div>
                                        <p className='mcp-nitro-credit'>
                                            Skins and profiles by{' '}
                                            <a href={NITROCRAFT} target='_blank' rel='noreferrer'>
                                                Nitrocraft
                                            </a>
                                            .
                                        </p>
                                        {identity.data && (
                                            <p className='mcp-player-uuid'>Skin UUID: {identity.data.id}</p>
                                        )}
                                    </>
                                )}
                                {capabilities.moderate && (
                                    <>
                                        <h3 className='mcp-tools-title'>Game mode</h3>
                                        <div className='mcp-action-grid'>
                                            {GAME_MODES.map(({ mode, label }) => (
                                                <Button
                                                    key={mode}
                                                    type='button'
                                                    variant={
                                                        inventory.data?.game_mode === mode ? 'default' : 'secondary'
                                                    }
                                                    disabled={busy}
                                                    onClick={() =>
                                                        void quick(
                                                            'gamemode',
                                                            { mode },
                                                            `Set ${player.name} to ${label}. They must be online.`,
                                                        )
                                                    }
                                                >
                                                    {label}
                                                </Button>
                                            ))}
                                        </div>
                                        <h3 className='mcp-tools-title'>
                                            {icon(Shield, 16)}
                                            Server actions
                                        </h3>
                                        <div className='mcp-action-grid'>
                                            {actions
                                                .filter((entry) => !entry.owner || capabilities.operator)
                                                .map((entry) => (
                                                    <Button
                                                        key={entry.action}
                                                        variant='secondary'
                                                        type='button'
                                                        onClick={() => {
                                                            setAction(entry.action);
                                                            setError('');
                                                            setSubmitted('');
                                                        }}
                                                    >
                                                        {entry.label}
                                                    </Button>
                                                ))}
                                        </div>
                                        {!capabilities.operator && (
                                            <p className='mcp-permission-note'>
                                                {icon(CrownDiamond, 13)}
                                                Only the server owner and panel admins can OP or de-OP players.
                                            </p>
                                        )}
                                    </>
                                )}
                                {(capabilities.moderate || capabilities.edit_inventory) && (
                                    <>
                                        <h3 className='mcp-tools-title'>
                                            {icon(TriangleExclamation, 16)}
                                            Quick actions
                                        </h3>
                                        <div className='mcp-action-grid'>
                                            {capabilities.moderate && (
                                                <Button
                                                    type='button'
                                                    variant='secondary'
                                                    disabled={busy}
                                                    onClick={() =>
                                                        confirmThen(
                                                            'kill',
                                                            () =>
                                                                void quick(
                                                                    'kill',
                                                                    {},
                                                                    `Killed ${player.name}. They must be online.`,
                                                                ),
                                                        )
                                                    }
                                                >
                                                    {confirming === 'kill' ? 'Tap again to kill' : 'Kill'}
                                                </Button>
                                            )}
                                            {capabilities.edit_inventory && (
                                                <>
                                                    <Button
                                                        type='button'
                                                        variant='secondary'
                                                        disabled={busy || !inventory.data?.inventory.length}
                                                        onClick={() =>
                                                            confirmThen('inventory', () => void clearArea('inventory'))
                                                        }
                                                    >
                                                        {confirming === 'inventory'
                                                            ? 'Tap again to clear'
                                                            : 'Clear inventory'}
                                                    </Button>
                                                    <Button
                                                        type='button'
                                                        variant='secondary'
                                                        disabled={busy || !inventory.data?.ender_chest.length}
                                                        onClick={() =>
                                                            confirmThen('ender', () => void clearArea('ender_chest'))
                                                        }
                                                    >
                                                        {confirming === 'ender'
                                                            ? 'Tap again to clear'
                                                            : 'Clear ender chest'}
                                                    </Button>
                                                    <Button
                                                        type='button'
                                                        variant='destructive'
                                                        disabled={busy}
                                                        onClick={() => {
                                                            setWiping(true);
                                                            setConfirming(null);
                                                        }}
                                                    >
                                                        Wipe player data
                                                    </Button>
                                                </>
                                            )}
                                        </div>
                                        {wiping && (
                                            <div className='mcp-wipe'>
                                                <p>
                                                    Deletes {player.name}'s inventory, ender chest, location, stats and
                                                    advancements. They start fresh the next time they join. They must be
                                                    offline. Type <strong>{player.name}</strong> to confirm.
                                                </p>
                                                <div className='mcp-wipe-row'>
                                                    <Input.Text
                                                        aria-label='Type the player name to confirm'
                                                        value={wipeName}
                                                        placeholder={player.name}
                                                        onChange={(event) => setWipeName(event.target.value)}
                                                    />
                                                    <Button
                                                        type='button'
                                                        variant='destructive'
                                                        disabled={busy || wipeName !== player.name}
                                                        onClick={() => void wipe()}
                                                    >
                                                        Wipe
                                                    </Button>
                                                    <Button
                                                        type='button'
                                                        variant='secondary'
                                                        onClick={() => {
                                                            setWiping(false);
                                                            setWipeName('');
                                                        }}
                                                    >
                                                        Cancel
                                                    </Button>
                                                </div>
                                            </div>
                                        )}
                                    </>
                                )}
                            </div>
                        ) : (
                            <div id={`mcp-${tab}-panel`} role='tabpanel' aria-labelledby={`mcp-${tab}-tab`}>
                                <div className='mcp-inventory-heading'>
                                    <h3>
                                        {tab === 'locations'
                                            ? 'Locations'
                                            : tab === 'stats'
                                              ? 'Statistics'
                                              : 'Saved inventory'}
                                    </h3>
                                    <Button
                                        size='sm'
                                        variant='secondary'
                                        disabled={tab === 'stats' ? stats.isValidating : inventory.isValidating}
                                        onClick={saveAndRefresh}
                                        title={
                                            capabilities.moderate
                                                ? 'Runs save-all on the server, then re-reads the files'
                                                : 'Re-read the saved files'
                                        }
                                    >
                                        <span className='mcp-button-content'>
                                            {icon(
                                                ArrowsRotateRight,
                                                14,
                                                (tab === 'stats' ? stats.isValidating : inventory.isValidating)
                                                    ? 'mcp-spin'
                                                    : undefined,
                                            )}
                                            {capabilities.moderate ? 'Save & refresh' : 'Refresh'}
                                        </span>
                                    </Button>
                                </div>
                                {(tab === 'stats' ? stats.error : inventory.error) && (
                                    <p className='mcp-tool-error' role='alert'>
                                        {httpErrorToHuman((tab === 'stats' ? stats.error : inventory.error) as Error)}
                                    </p>
                                )}
                                {(tab === 'stats' ? stats.isLoading : inventory.isLoading) ? (
                                    <div className='mcp-empty'>
                                        {icon(ArrowsRotateRight, 25, 'mcp-spin')}
                                        <p>Reading saved player data…</p>
                                    </div>
                                ) : tab === 'stats' ? (
                                    stats.data && <StatsPanel data={stats.data} />
                                ) : tab === 'locations' ? (
                                    inventory.data && (
                                        <LocationsPanel
                                            data={inventory.data}
                                            canAct={capabilities.moderate}
                                            busy={busy}
                                            onTeleport={teleport}
                                            onTeleportToPlayer={(target) =>
                                                void quick(
                                                    'teleport_to_player',
                                                    { target },
                                                    `Teleporting ${player.name} to ${target}. Both must be online.`,
                                                )
                                            }
                                        />
                                    )
                                ) : (
                                    inventory.data && (
                                        <InventoryView
                                            data={inventory.data}
                                            editable={Boolean(capabilities.edit_inventory)}
                                            busy={busy}
                                            onEdit={(change) => void edit(change)}
                                        />
                                    )
                                )}
                            </div>
                        )}
                    </>
                )}
            </div>
        </Dialog>
    );
}
