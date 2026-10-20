import { useEffect, useRef } from 'react';

/**
 * Draws a Minecraft skin's face (with the hat layer) from a skin PNG.
 */
export default function SkinFace({ url, size = 40, className }: { url: string; size?: number; className?: string }) {
    const canvas = useRef<HTMLCanvasElement>(null);
    useEffect(() => {
        const image = new Image();
        image.onload = () => {
            const context = canvas.current?.getContext('2d');
            if (!context) return;
            context.imageSmoothingEnabled = false;
            context.clearRect(0, 0, 8, 8);
            context.drawImage(image, 8, 8, 8, 8, 0, 0, 8, 8);
            context.drawImage(image, 40, 8, 8, 8, 0, 0, 8, 8);
        };
        image.src = url;
    }, [url]);

    return (
        <canvas
            ref={canvas}
            width={8}
            height={8}
            className={className ?? 'mcp-avatar mcp-skin'}
            style={{ width: size, height: size, imageRendering: 'pixelated' }}
            aria-hidden
        />
    );
}
