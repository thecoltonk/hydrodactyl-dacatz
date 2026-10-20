// Ported from Aquadactyl/MCPlayersJava (BSD 2-Clause, Copyright (c) Aquadactyl contributors).
import { BookOpen, CrownDiamond } from '@gravity-ui/icons';
import { useState } from 'react';

import type { InventoryItem, InventorySnapshot } from '@/api/server/players';

import ItemIcon, { ITEM_RENDER_SOURCE } from './ItemIcon';
import PlayerVitals from './PlayerVitals';

const itemName = (id: string) =>
    id
        .split(':')
        .at(-1)!
        .replaceAll('_', ' ')
        .replace(/\b\w/gu, (char) => char.toUpperCase());

export default function InventoryView({ data }: { data: InventorySnapshot }) {
    const [selected, setSelected] = useState<InventoryItem | null>(null);
    const grid = (title: string, slots: number[], items: InventoryItem[], equipment = false) => (
        <section className='mcp-inventory-section'>
            <h3>{title}</h3>
            <div className={`mcp-slot-grid ${equipment ? 'mcp-equipment-grid' : ''}`}>
                {slots.map((slot) => {
                    const item = items.find((entry) => entry.slot === slot);
                    const label = equipment
                        ? (
                              { 103: 'Head', 102: 'Chest', 101: 'Legs', 100: 'Feet', 150: 'Offhand' } as Record<
                                  number,
                                  string
                              >
                          )[slot]
                        : `Slot ${slot}`;
                    return (
                        <div key={slot} className='mcp-slot-wrap'>
                            <button
                                type='button'
                                className={`mcp-slot ${!equipment && title === 'Hotbar' && data.selected_slot === slot ? 'mcp-slot-held' : ''}`}
                                disabled={!item}
                                aria-label={`${label}: ${item ? `${itemName(item.id)} × ${item.count}` : 'Empty'}`}
                                title={`${label}: ${item ? `${itemName(item.id)} × ${item.count}` : 'Empty'}`}
                                onClick={() => setSelected(item ?? null)}
                            >
                                {item && (
                                    <>
                                        <ItemIcon id={item.id} />
                                        <span className='mcp-item-count'>{item.count}</span>
                                    </>
                                )}
                            </button>
                            {equipment && <small>{label}</small>}
                        </div>
                    );
                })}
            </div>
        </section>
    );
    const saved =
        data.saved_at && Number.isFinite(new Date(data.saved_at).getTime())
            ? new Date(data.saved_at).toLocaleString()
            : 'Unknown save time';
    return (
        <div className='mcp-inventory-view'>
            <div className='mcp-alert'>
                <BookOpen width={18} height={18} aria-hidden />
                <div>
                    <strong>Saved inventory snapshot</strong>
                    <p>
                        {saved}. Items may have changed since Minecraft last saved this player. This view is read-only.
                    </p>
                </div>
            </div>
            <PlayerVitals data={data} />
            {grid('Equipment', [103, 102, 101, 100, 150], data.inventory, true)}
            {grid(
                'Main inventory',
                Array.from({ length: 27 }, (_, index) => index + 9),
                data.inventory,
            )}
            {grid(
                'Hotbar',
                Array.from({ length: 9 }, (_, index) => index),
                data.inventory,
            )}
            {grid(
                'Ender Chest',
                Array.from({ length: 27 }, (_, index) => index),
                data.ender_chest,
            )}
            {selected && (
                <section className='mcp-item-details'>
                    <div className='mcp-item-summary'>
                        <ItemIcon id={selected.id} />
                        <div>
                            <h3>
                                {itemName(selected.id)} × {selected.count}
                            </h3>
                            <code>{selected.id}</code>
                        </div>
                    </div>
                    <p>Slot {selected.slot}</p>
                    {selected.metadata_truncated ? (
                        <p>Item metadata exceeds the display limit.</p>
                    ) : (
                        <pre>{JSON.stringify(selected.metadata, null, 2)}</pre>
                    )}
                </section>
            )}
            <div className='mcp-saved-state'>
                <CrownDiamond width={15} height={15} aria-hidden />
                {(
                    [
                        ['operator', 'OP'],
                        ['banned', 'Banned'],
                        ['whitelisted', 'Whitelisted'],
                    ] as const
                ).map(([key, label]) => (
                    <span key={key}>
                        {label}: {data.state[key] === null ? 'Unknown' : data.state[key] ? 'Yes' : 'No'}
                    </span>
                ))}
            </div>
            <p className='mcp-player-location'>
                {data.dimension ?? 'Unknown dimension'}
                {data.position ? ` · ${data.position.join(', ')}` : ''}
            </p>
            <p className='mcp-player-uuid'>Server UUID: {data.uuid}</p>
            <p className='mcp-item-credit'>
                Item renders by{' '}
                <a href={ITEM_RENDER_SOURCE} target='_blank' rel='noreferrer'>
                    mc-assets
                </a>
                .
            </p>
        </div>
    );
}
