// Ported from Aquadactyl/MCPlayersJava (BSD 2-Clause, Copyright (c) Aquadactyl contributors).
import { Book, Boxes3, Cube, Gem, Hammer, Heart, Shield } from '@gravity-ui/icons';
import { useState } from 'react';

// Minecraft inventory renders, pinned to a fixed revision rather than a moving branch.
export const ITEM_RENDER_SOURCE = 'https://github.com/Owen1212055/mc-assets';
export const ITEM_RENDER_BASE =
    'https://cdn.jsdelivr.net/gh/Owen1212055/mc-assets@bc96011714c0eb3770bd641a3b86459ad5f860c7/item-assets';

export function minecraftItemImage(id: string): string | null {
    const match = /^minecraft:([a-z0-9_]{1,100})$/u.exec(id);
    return match ? `${ITEM_RENDER_BASE}/${match[1]!.toUpperCase()}.png` : null;
}

const size = { width: 22, height: 22 };

const fallbackIcon = (id: string) =>
    /sword|trident|mace|pickaxe|axe|shovel|hoe/u.test(id) ? (
        <Hammer {...size} />
    ) : /helmet|chestplate|leggings|boots|shield/u.test(id) ? (
        <Shield {...size} />
    ) : /diamond|emerald|quartz/u.test(id) ? (
        <Gem {...size} />
    ) : /apple|bread|beef|carrot|potato|porkchop|stew/u.test(id) ? (
        <Heart {...size} />
    ) : /book/u.test(id) ? (
        <Book {...size} />
    ) : /chest|shulker/u.test(id) ? (
        <Boxes3 {...size} />
    ) : (
        <Cube {...size} />
    );

export default function ItemIcon({ id }: { id: string }) {
    const src = minecraftItemImage(id);
    const [loadedSource, setLoadedSource] = useState<string | null>(null);
    const [failedSource, setFailedSource] = useState<string | null>(null);
    const available = src !== null && failedSource !== src;
    const loaded = available && loadedSource === src;

    return (
        <span className='mcp-item-art' aria-hidden='true'>
            {!loaded && fallbackIcon(id)}
            {available && (
                <img
                    key={src}
                    className={`mcp-item-texture ${loaded ? 'mcp-item-texture-loaded' : ''}`}
                    src={src}
                    alt=''
                    width={32}
                    height={32}
                    loading='lazy'
                    decoding='async'
                    crossOrigin='anonymous'
                    referrerPolicy='no-referrer'
                    draggable={false}
                    onLoad={() => setLoadedSource(src)}
                    onError={() => setFailedSource(src)}
                />
            )}
        </span>
    );
}
