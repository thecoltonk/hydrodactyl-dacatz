// Ported from Aquadactyl/MCPlayersJava (BSD 2-Clause, Copyright (c) Aquadactyl contributors).
import { useEffect, useRef, useState } from 'react';

import { nitroImage } from '@/api/server/players';

import SkinFace from './SkinFace';
import useNitroIdentity from './useNitroIdentity';

export function PixelAvatar({ name }: { name: string }) {
    const hash = [...name].reduce((value, char) => (value * 31 + char.charCodeAt(0)) >>> 0, 0);
    const colors = ['#c4a879', '#b79dca', '#89a1c6', '#79b0a0', '#adbc82', '#c68e8e'];
    return (
        <svg className='mcp-avatar' viewBox='0 0 8 8' aria-hidden='true' shapeRendering='crispEdges'>
            <path fill={colors[hash % colors.length]} d='M0 0h8v8H0z' />
            <path fill='#ffffff' opacity='.15' d='M0 0h8v2H0zM0 2h1v6H0z' />
            <path fill='#182221' d='M1 3h2v1H1zM5 3h2v1H5zM3 5h2v1H3z' />
            <path fill='#182221' opacity='.3' d='M0 7h8v1H0z' />
        </svg>
    );
}

export default function PlayerAvatar({
    name,
    skinUrl,
    offline = false,
}: {
    name: string;
    /** Panel-uploaded skin; drawn locally. */
    skinUrl?: string;
    /** Offline-mode servers: names are not Mojang accounts, so skip the Nitrocraft lookup. */
    offline?: boolean;
}) {
    const ref = useRef<HTMLSpanElement>(null);
    const [visible, setVisible] = useState(false);
    const [failed, setFailed] = useState(false);
    useEffect(() => {
        if (!window.IntersectionObserver) {
            setVisible(true);
            return;
        }
        const observer = new IntersectionObserver(
            (entries) => {
                if (entries.some((entry) => entry.isIntersecting)) {
                    setVisible(true);
                    observer.disconnect();
                }
            },
            { rootMargin: '150px' },
        );
        if (ref.current) observer.observe(ref.current);
        return () => observer.disconnect();
    }, []);
    const identity = useNitroIdentity(name, visible && !offline && !skinUrl);
    if (skinUrl) {
        return (
            <span ref={ref} className='mcp-avatar-wrap'>
                <PixelAvatar name={name} />
                <SkinFace url={skinUrl} />
            </span>
        );
    }
    return (
        <span ref={ref} className='mcp-avatar-wrap'>
            <PixelAvatar name={name} />
            {identity.data && !failed && (
                <img
                    className='mcp-avatar mcp-skin'
                    src={nitroImage(identity.data.id)}
                    alt=''
                    width={40}
                    height={40}
                    loading='lazy'
                    referrerPolicy='no-referrer'
                    onError={() => setFailed(true)}
                />
            )}
        </span>
    );
}
