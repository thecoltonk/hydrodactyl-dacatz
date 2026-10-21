import { useEffect, useState } from 'react';

/** Da Catz Hosting branding. Change the name here; the logo comes from the admin logo setting. */
export const BRAND_NAME = 'Da Catz Hosting';
export const BRAND_TAGLINE = 'Customer Panel';

/**
 * Optional background art, baked in by update-hydro.sh from images placed on the
 * server. If a file is missing the panel keeps its normal plain background.
 */
export const BACKGROUNDS = {
    login: '/dacatz/ground.webp',
    panel: '/dacatz/aerial.webp',
};

const probe = (src: string, onLoad: () => void) => {
    const image = new Image();
    image.onload = onLoad;
    image.src = src;
};

/** Turns on the panel background (html.dacatz-bg) once the image actually loads. */
export const enablePanelBackground = () => {
    if (typeof document === 'undefined') return;
    probe(BACKGROUNDS.panel, () => document.documentElement.classList.add('dacatz-bg'));
};

export const useImageAvailable = (src: string) => {
    const [available, setAvailable] = useState(false);
    useEffect(() => {
        let active = true;
        probe(src, () => {
            if (active) setAvailable(true);
        });
        return () => {
            active = false;
        };
    }, [src]);

    return available;
};
