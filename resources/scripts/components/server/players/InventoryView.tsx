// Ported from Aquadactyl/MCPlayersJava (BSD 2-Clause, Copyright (c) Aquadactyl contributors).
import { BookOpen, CrownDiamond, Pencil } from '@gravity-ui/icons';
import { useEffect, useId, useState } from 'react';

import type { InventoryArea, InventoryChange, InventoryItem, InventorySnapshot } from '@/api/server/players';
import { Input } from '@/components/elements/inputs';
import { Button } from '@/components/ui/button';

import ItemIcon, { ITEM_RENDER_SOURCE } from './ItemIcon';
import PlayerVitals from './PlayerVitals';

const itemName = (id: string) =>
    id
        .split(':')
        .at(-1)!
        .replaceAll('_', ' ')
        .replace(/\b\w/gu, (char) => char.toUpperCase());

const SLOT_LABELS: Record<number, string> = { 103: 'Head', 102: 'Chest', 101: 'Legs', 100: 'Feet', 150: 'Offhand' };

const normalizeId = (value: string) => {
    const id = value.trim().toLowerCase().replaceAll(' ', '_');
    return id.includes(':') ? id : `minecraft:${id}`;
};

const VALID_ID = /^[a-z0-9_.-]{1,64}:[a-z0-9_./-]{1,96}$/u;

function SlotEditor({
    area,
    slot,
    item,
    busy,
    onSave,
}: {
    area: InventoryArea;
    slot: number;
    item: InventoryItem | undefined;
    busy: boolean;
    onSave: (change: InventoryChange) => void;
}) {
    const listId = useId();
    const [id, setId] = useState(item?.id ?? '');
    const [count, setCount] = useState(String(item?.count ?? 1));
    const [ids, setIds] = useState<string[]>([]);

    useEffect(() => {
        let active = true;
        void import('./itemIds').then((module) => {
            if (active) setIds(module.default);
        });
        return () => {
            active = false;
        };
    }, []);

    const normalized = normalizeId(id);
    const amount = Number(count);
    const valid = VALID_ID.test(normalized) && Number.isInteger(amount) && amount >= 1 && amount <= 99;
    const expect = item?.id ?? null;
    const label = area === 'ender_chest' ? `Ender chest slot ${slot}` : (SLOT_LABELS[slot] ?? `Slot ${slot}`);

    return (
        <form
            className='mcp-slot-editor'
            onSubmit={(event) => {
                event.preventDefault();
                if (valid) onSave({ area, slot, id: normalized, count: amount, expect });
            }}
        >
            <strong>
                <Pencil width={14} height={14} aria-hidden /> Edit {label}
            </strong>
            {item?.metadata ? (
                <p>This item has extra data (enchantments, names…). Changing only the count keeps it.</p>
            ) : null}
            <div className='mcp-slot-editor-fields'>
                <Input.Text
                    aria-label='Item id'
                    placeholder='diamond_sword'
                    list={listId}
                    value={id}
                    onChange={(event) => setId(event.target.value)}
                    maxLength={170}
                />
                <datalist id={listId}>
                    {ids.map((entry) => (
                        <option key={entry} value={entry} />
                    ))}
                </datalist>
                <Input.Text
                    aria-label='Count'
                    type='number'
                    min={1}
                    max={99}
                    value={count}
                    onChange={(event) => setCount(event.target.value)}
                    className='w-20!'
                />
            </div>
            <div className='mcp-slot-editor-actions'>
                <Button type='submit' size='sm' disabled={!valid || busy}>
                    Save slot
                </Button>
                {item && (
                    <Button
                        type='button'
                        size='sm'
                        variant='secondary'
                        disabled={busy}
                        onClick={() => onSave({ area, slot, id: null, count: null, expect })}
                    >
                        Clear slot
                    </Button>
                )}
            </div>
        </form>
    );
}

export default function InventoryView({
    data,
    editable = false,
    busy = false,
    onEdit,
}: {
    data: InventorySnapshot;
    editable?: boolean;
    busy?: boolean;
    onEdit?: (change: InventoryChange) => void;
}) {
    const [selection, setSelection] = useState<{ area: InventoryArea; slot: number } | null>(null);
    const itemsFor = (area: InventoryArea) => (area === 'ender_chest' ? data.ender_chest : data.inventory);
    const selected = selection ? itemsFor(selection.area).find((entry) => entry.slot === selection.slot) : undefined;
    const grid = (title: string, slots: number[], items: InventoryItem[], equipment = false) => (
        <section className='mcp-inventory-section'>
            <h3>{title}</h3>
            <div className={`mcp-slot-grid ${equipment ? 'mcp-equipment-grid' : ''}`}>
                {slots.map((slot) => {
                    const item = items.find((entry) => entry.slot === slot);
                    const area: InventoryArea = items === data.ender_chest ? 'ender_chest' : 'inventory';
                    const label = equipment ? SLOT_LABELS[slot] : `Slot ${slot}`;
                    const active = selection?.area === area && selection.slot === slot;
                    return (
                        <div key={slot} className='mcp-slot-wrap'>
                            <button
                                type='button'
                                className={`mcp-slot ${!equipment && title === 'Hotbar' && data.selected_slot === slot ? 'mcp-slot-held' : ''} ${active ? 'mcp-slot-active' : ''}`}
                                disabled={!item && !editable}
                                aria-pressed={active}
                                aria-label={`${label}: ${item ? `${itemName(item.id)} × ${item.count}` : 'Empty'}`}
                                title={`${label}: ${item ? `${itemName(item.id)} × ${item.count}` : 'Empty'}`}
                                onClick={() => setSelection(active ? null : { area, slot })}
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
                        {saved}. Items may have changed since Minecraft last saved this player.{' '}
                        {editable
                            ? 'Click any slot to edit it. Online players are edited live; offline players get their save file updated (a backup is kept).'
                            : 'This view is read-only.'}
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
            {editable && selection && onEdit && (
                <SlotEditor
                    key={`${selection.area}:${selection.slot}:${selected?.id ?? ''}:${selected?.count ?? 0}`}
                    area={selection.area}
                    slot={selection.slot}
                    item={selected}
                    busy={busy}
                    onSave={onEdit}
                />
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
