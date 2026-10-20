// Ported from Aquadactyl/MCPlayersJava (BSD 2-Clause, Copyright (c) Aquadactyl contributors).
import { ArrowsRotateRight, BookOpen, CrownDiamond, Person, Shield, TriangleExclamation } from '@gravity-ui/icons';
import { useState } from 'react';
import useSWR, { useSWRConfig } from 'swr';

import { httpErrorToHuman } from '@/api/http';
import {
    getInventory,
    NITROCRAFT,
    nitroImage,
    type Player,
    type PlayerAction,
    type PlayerCapabilities,
    sendPlayerAction,
} from '@/api/server/players';
import { Dialog } from '@/components/elements/dialog';
import { Input } from '@/components/elements/inputs';
import { Button } from '@/components/ui/button';

import InventoryView from './InventoryView';
import PlayerAvatar from './PlayerAvatar';
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

const icon = (Icon: typeof Person, size = 15, className?: string) => (
    <Icon width={size} height={size} className={className} aria-hidden />
);

export default function PlayerDialog({
    uuid,
    player,
    capabilities,
    onClose,
    onNotice,
}: {
    uuid: string;
    player: Player;
    capabilities: PlayerCapabilities;
    onClose: () => void;
    onNotice: (message: string) => void;
}) {
    const [tab, setTab] = useState<'profile' | 'inventory'>('profile');
    const [action, setAction] = useState<PlayerAction | null>(null);
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [submitted, setSubmitted] = useState('');
    const [renderFailed, setRenderFailed] = useState(false);
    const { mutate } = useSWRConfig();
    const identity = useNitroIdentity(player.name);
    const inventory = useSWR(
        tab === 'inventory' && capabilities.inventory
            ? ['minecraft-players:inventory', uuid, player.name, player.uuid]
            : null,
        () => getInventory(uuid, player),
        { revalidateOnFocus: false, shouldRetryOnError: false, dedupingInterval: 10000 },
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
            description={definition ? definition.description : 'Player profile, saved inventory and server actions.'}
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
                            <PlayerAvatar name={player.name} />
                            <div>
                                <strong>{player.name}</strong>
                                <p>Minecraft Java player</p>
                            </div>
                        </div>
                        <div className='mcp-dialog-tabs' role='tablist' aria-label='Player details'>
                            <button
                                type='button'
                                role='tab'
                                aria-selected={tab === 'profile'}
                                aria-controls='mcp-profile-panel'
                                id='mcp-profile-tab'
                                onClick={() => setTab('profile')}
                            >
                                {icon(Person)}
                                Profile & actions
                            </button>
                            <button
                                type='button'
                                role='tab'
                                aria-selected={tab === 'inventory'}
                                aria-controls='mcp-inventory-panel'
                                id='mcp-inventory-tab'
                                disabled={!capabilities.inventory}
                                title={
                                    !capabilities.inventory
                                        ? 'Requires file read and file content permissions'
                                        : undefined
                                }
                                onClick={() => setTab('inventory')}
                            >
                                {icon(BookOpen)}
                                Saved inventory
                            </button>
                        </div>
                        {submitted && (
                            <p className='mcp-submitted' role='status'>
                                {submitted}
                            </p>
                        )}
                        {tab === 'profile' ? (
                            <div id='mcp-profile-panel' role='tabpanel' aria-labelledby='mcp-profile-tab'>
                                <div className='mcp-profile-render'>
                                    {identity.data && !renderFailed ? (
                                        <img
                                            src={nitroImage(identity.data.id, true)}
                                            alt={`${player.name}'s Minecraft skin`}
                                            referrerPolicy='no-referrer'
                                            onError={() => setRenderFailed(true)}
                                        />
                                    ) : (
                                        <span>{identity.isLoading ? 'Loading skin…' : 'Skin render unavailable'}</span>
                                    )}
                                </div>
                                <p className='mcp-nitro-credit'>
                                    Skins and profiles by{' '}
                                    <a href={NITROCRAFT} target='_blank' rel='noreferrer'>
                                        Nitrocraft
                                    </a>
                                    .
                                </p>
                                {identity.data && <p className='mcp-player-uuid'>Skin UUID: {identity.data.id}</p>}
                                {capabilities.moderate && (
                                    <>
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
                            </div>
                        ) : (
                            <div id='mcp-inventory-panel' role='tabpanel' aria-labelledby='mcp-inventory-tab'>
                                <div className='mcp-inventory-heading'>
                                    <h3>Saved inventory</h3>
                                    <Button
                                        size='icon'
                                        variant='secondary'
                                        disabled={inventory.isValidating}
                                        onClick={() => void inventory.mutate()}
                                        aria-label='Refresh saved inventory'
                                    >
                                        {icon(ArrowsRotateRight, 14, inventory.isValidating ? 'mcp-spin' : undefined)}
                                    </Button>
                                </div>
                                {inventory.error && (
                                    <p className='mcp-tool-error' role='alert'>
                                        {httpErrorToHuman(inventory.error)}
                                        {inventory.data &&
                                            ' The previous snapshot is shown below; this refresh failed.'}
                                    </p>
                                )}
                                {inventory.isLoading ? (
                                    <div className='mcp-empty'>
                                        {icon(ArrowsRotateRight, 25, 'mcp-spin')}
                                        <p>Reading saved player data…</p>
                                    </div>
                                ) : inventory.data ? (
                                    <InventoryView data={inventory.data} />
                                ) : null}
                            </div>
                        )}
                    </>
                )}
            </div>
        </Dialog>
    );
}
