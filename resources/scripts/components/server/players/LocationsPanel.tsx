import { useState } from 'react';

import type { InventorySnapshot, SavedLocation } from '@/api/server/players';
import { Input } from '@/components/elements/inputs';
import { Button } from '@/components/ui/button';

export const dimensionLabel = (dimension: string) =>
    (
        ({
            'minecraft:overworld': 'Overworld',
            'minecraft:the_nether': 'Nether',
            'minecraft:the_end': 'The End',
        }) as Record<string, string>
    )[dimension] ?? dimension;

const DIMENSIONS = ['minecraft:overworld', 'minecraft:the_nether', 'minecraft:the_end'];

export interface TeleportTarget {
    dimension: string;
    x: number;
    y: number;
    z: number;
}

function LocationCard({
    title,
    hint,
    location,
    canAct,
    busy,
    onTeleport,
}: {
    title: string;
    hint: string;
    location: SavedLocation | null | undefined;
    canAct: boolean;
    busy: boolean;
    onTeleport: (target: TeleportTarget, label: string) => void;
}) {
    return (
        <div className='flex flex-col gap-2 rounded-xl border border-mocha-300 bg-mocha-500 p-3'>
            <strong className='text-sm'>{title}</strong>
            {location && location.position.length === 3 ? (
                <>
                    <code className='text-sm'>{location.position.map((value) => Math.round(value)).join(', ')}</code>
                    <span className='text-xs text-mocha-50'>{dimensionLabel(location.dimension)}</span>
                    {canAct && (
                        <Button
                            size='sm'
                            variant='secondary'
                            disabled={busy}
                            onClick={() =>
                                onTeleport(
                                    {
                                        dimension: location.dimension,
                                        x: location.position[0]!,
                                        y: location.position[1]!,
                                        z: location.position[2]!,
                                    },
                                    title.toLowerCase(),
                                )
                            }
                        >
                            Teleport here
                        </Button>
                    )}
                </>
            ) : (
                <span className='text-xs text-mocha-50'>{hint}</span>
            )}
        </div>
    );
}

export default function LocationsPanel({
    data,
    canAct,
    busy,
    onTeleport,
    onTeleportToPlayer,
}: {
    data: InventorySnapshot;
    canAct: boolean;
    busy: boolean;
    onTeleport: (target: TeleportTarget, label: string) => void;
    onTeleportToPlayer: (target: string) => void;
}) {
    const [custom, setCustom] = useState({ x: '', y: '', z: '', dimension: 'minecraft:overworld' });
    const [target, setTarget] = useState('');
    const current: SavedLocation | null =
        data.position && data.position.length === 3
            ? { dimension: data.dimension ?? 'minecraft:overworld', position: data.position }
            : null;
    const customValid = (['x', 'y', 'z'] as const).every(
        (axis) => custom[axis].trim() !== '' && Number.isFinite(Number(custom[axis])),
    );

    return (
        <div className='flex flex-col gap-4'>
            <div className='grid grid-cols-1 gap-2 sm:grid-cols-3'>
                <LocationCard
                    title='Current position'
                    hint='Unknown'
                    location={current}
                    canAct={canAct}
                    busy={busy}
                    onTeleport={onTeleport}
                />
                <LocationCard
                    title='Last death'
                    hint='Has not died yet.'
                    location={data.last_death}
                    canAct={canAct}
                    busy={busy}
                    onTeleport={onTeleport}
                />
                <LocationCard
                    title='Respawn point'
                    hint='World spawn (no bed or anchor set).'
                    location={data.respawn}
                    canAct={canAct}
                    busy={busy}
                    onTeleport={onTeleport}
                />
            </div>

            {canAct && (
                <>
                    <form
                        className='flex flex-col gap-2'
                        onSubmit={(event) => {
                            event.preventDefault();
                            if (!customValid) return;
                            onTeleport(
                                {
                                    dimension: custom.dimension,
                                    x: Number(custom.x),
                                    y: Number(custom.y),
                                    z: Number(custom.z),
                                },
                                'those coordinates',
                            );
                        }}
                    >
                        <strong className='text-sm'>Teleport to coordinates</strong>
                        <div className='flex flex-wrap items-center gap-2'>
                            {(['x', 'y', 'z'] as const).map((axis) => (
                                <Input.Text
                                    key={axis}
                                    aria-label={axis.toUpperCase()}
                                    placeholder={axis.toUpperCase()}
                                    inputMode='decimal'
                                    value={custom[axis]}
                                    onChange={(event) => setCustom({ ...custom, [axis]: event.target.value })}
                                    className='w-24!'
                                />
                            ))}
                            <select
                                aria-label='Dimension'
                                value={custom.dimension}
                                onChange={(event) => setCustom({ ...custom, dimension: event.target.value })}
                                className='rounded-lg border border-mocha-300 bg-mocha-500 px-2 py-2 text-sm'
                            >
                                {DIMENSIONS.map((dimension) => (
                                    <option key={dimension} value={dimension}>
                                        {dimensionLabel(dimension)}
                                    </option>
                                ))}
                            </select>
                            <Button type='submit' size='sm' variant='secondary' disabled={!customValid || busy}>
                                Teleport
                            </Button>
                        </div>
                    </form>

                    <form
                        className='flex flex-col gap-2'
                        onSubmit={(event) => {
                            event.preventDefault();
                            if (/^[A-Za-z0-9_]{1,16}$/u.test(target.trim())) onTeleportToPlayer(target.trim());
                        }}
                    >
                        <strong className='text-sm'>Teleport to another player</strong>
                        <div className='flex flex-wrap items-center gap-2'>
                            <Input.Text
                                aria-label='Target player'
                                placeholder='Username'
                                maxLength={16}
                                value={target}
                                onChange={(event) => setTarget(event.target.value)}
                                className='w-48!'
                            />
                            <Button type='submit' size='sm' variant='secondary' disabled={!target.trim() || busy}>
                                Teleport
                            </Button>
                        </div>
                    </form>
                    <p className='text-xs text-mocha-50'>
                        Teleports only work while the player is online. Positions are from the last save; use Save &
                        refresh for the latest.
                    </p>
                </>
            )}
        </div>
    );
}
