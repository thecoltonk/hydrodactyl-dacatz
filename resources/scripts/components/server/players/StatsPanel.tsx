import type { PlayerStats, StatCount, StatGroup } from '@/api/server/players';

import ItemIcon from './ItemIcon';

const number = new Intl.NumberFormat();

const label = (id: string) =>
    id
        .split(':')
        .at(-1)!
        .replaceAll('_', ' ')
        .replace(/\b\w/gu, (char) => char.toUpperCase());

const duration = (seconds: number) => {
    const days = Math.floor(seconds / 86400);
    const hours = Math.floor((seconds % 86400) / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    if (days > 0) return `${days}d ${hours}h`;
    if (hours > 0) return `${hours}h ${minutes}m`;
    return `${minutes}m`;
};

const distance = (cm: number) => (cm >= 100000 ? `${(cm / 100000).toFixed(2)} km` : `${Math.round(cm / 100)} m`);

const MOVEMENT: Record<string, string> = {
    walk: 'Walked',
    sprint: 'Sprinted',
    crouch: 'Crouched',
    swim: 'Swam',
    fly: 'Flown',
    aviate: 'Elytra',
    fall: 'Fallen',
    climb: 'Climbed',
    boat: 'Boat',
    minecart: 'Minecart',
    horse: 'Horse',
    pig: 'Pig',
    strider: 'Strider',
    walk_on_water: 'On water',
    walk_under_water: 'Under water',
    happy_ghast: 'Happy ghast',
};

function Tile({ title, value }: { title: string; value: string }) {
    return (
        <div className='flex flex-col rounded-xl border border-mocha-300 bg-mocha-500 p-3'>
            <span className='text-xs text-mocha-50'>{title}</span>
            <strong className='text-lg'>{value}</strong>
        </div>
    );
}

function TopList({
    title,
    group,
    icons = false,
}: {
    title: string;
    group: StatGroup | { total: number; top: StatCount[] };
    icons?: boolean;
}) {
    return (
        <section className='flex flex-col gap-2'>
            <h4 className='flex items-baseline justify-between text-sm font-semibold'>
                {title}
                <span className='text-xs font-normal text-mocha-50'>{number.format(group.total)} total</span>
            </h4>
            {group.top.length === 0 ? (
                <p className='text-xs text-mocha-50'>Nothing yet.</p>
            ) : (
                <ul className='flex flex-col gap-1'>
                    {group.top.map((entry) => (
                        <li key={entry.id} className='flex items-center gap-2 text-sm'>
                            {icons && <ItemIcon id={entry.id} />}
                            <span className='min-w-0 flex-1 truncate'>{label(entry.id)}</span>
                            <span className='tabular-nums text-mocha-50'>{number.format(entry.count)}</span>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

export default function StatsPanel({ data }: { data: PlayerStats }) {
    const movement = data.distance.filter((entry) => entry.count >= 100);

    return (
        <div className='flex flex-col gap-5'>
            <div className='grid grid-cols-2 gap-2 sm:grid-cols-4'>
                <Tile title='Playtime' value={duration(data.play_time_seconds)} />
                <Tile title='Player kills' value={number.format(data.player_kills)} />
                <Tile title='Deaths' value={number.format(data.deaths)} />
                <Tile title='K/D' value={data.kdr.toFixed(2)} />
                <Tile title='Mob kills' value={number.format(data.mob_kills)} />
                <Tile title='Distance' value={distance(data.distance_cm)} />
                <Tile title='Blocks mined' value={number.format(data.blocks_mined.total)} />
                <Tile title='Items used' value={number.format(data.items_used.total)} />
                <Tile title='Damage dealt' value={number.format(data.damage_dealt)} />
                <Tile title='Damage taken' value={number.format(data.damage_taken)} />
                <Tile title='Jumps' value={number.format(data.jumps)} />
                <Tile title='Since last death' value={duration(data.time_since_death_seconds)} />
            </div>

            <div className='grid grid-cols-1 gap-5 sm:grid-cols-2'>
                <TopList title='Most used items' group={data.items_used} icons />
                <TopList title='Mobs killed' group={data.mobs_killed} />
                <TopList title='Blocks mined' group={data.blocks_mined} icons />
                <TopList title='Items crafted' group={data.items_crafted} icons />
                <TopList title='Killed by' group={data.killed_by} />
                <section className='flex flex-col gap-2'>
                    <h4 className='flex items-baseline justify-between text-sm font-semibold'>
                        Distance travelled
                        <span className='text-xs font-normal text-mocha-50'>{distance(data.distance_cm)} total</span>
                    </h4>
                    {movement.length === 0 ? (
                        <p className='text-xs text-mocha-50'>Nothing yet.</p>
                    ) : (
                        <ul className='flex flex-col gap-1'>
                            {movement.map((entry) => (
                                <li key={entry.id} className='flex items-center gap-2 text-sm'>
                                    <span className='min-w-0 flex-1 truncate'>
                                        {MOVEMENT[entry.id] ?? label(entry.id)}
                                    </span>
                                    <span className='tabular-nums text-mocha-50'>{distance(entry.count)}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </div>
    );
}
