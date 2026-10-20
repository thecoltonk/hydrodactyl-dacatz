import { ArrowShapeUpFromLine, ArrowsRotateRight, TrashBin } from '@gravity-ui/icons';
import { useRef, useState } from 'react';

import { httpErrorToHuman } from '@/api/http';
import { applySkin, deleteSkin, type PlayerSkin, uploadSkin } from '@/api/server/players';
import { Button } from '@/components/ui/button';

import SkinFace from './SkinFace';

/**
 * Upload / apply / remove a custom skin for an offline-mode player.
 */
export default function SkinPanel({
    uuid,
    name,
    skin,
    canEdit,
    onChanged,
}: {
    uuid: string;
    name: string;
    skin?: PlayerSkin;
    canEdit: boolean;
    onChanged: () => void;
}) {
    const input = useRef<HTMLInputElement>(null);
    const [file, setFile] = useState<File | null>(null);
    const [preview, setPreview] = useState<string | null>(null);
    const [variant, setVariant] = useState<'classic' | 'slim'>(skin?.variant ?? 'classic');
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState('');
    const [error, setError] = useState('');

    const shown = preview ?? skin?.url ?? null;

    const pick = (picked: File | null) => {
        setError('');
        setMessage('');
        setFile(picked);
        if (preview) URL.revokeObjectURL(preview);
        if (!picked) {
            setPreview(null);
            return;
        }
        const url = URL.createObjectURL(picked);
        const image = new Image();
        image.onload = () => {
            if (image.width !== 64 || (image.height !== 64 && image.height !== 32)) {
                setError('Skins must be 64×64 (or legacy 64×32) PNG files.');
                setFile(null);
                URL.revokeObjectURL(url);
                setPreview(null);
            }
        };
        image.src = url;
        setPreview(url);
    };

    const run = async (action: () => Promise<{ message: string }>) => {
        setBusy(true);
        setError('');
        setMessage('');
        try {
            const result = await action();
            setMessage(result.message);
            onChanged();
        } catch (failure) {
            setError(httpErrorToHuman(failure as Error));
        } finally {
            setBusy(false);
        }
    };

    return (
        <section className='mcp-skin-panel'>
            <h3 className='mcp-tools-title'>Skin</h3>
            <div className='flex items-center gap-4'>
                {shown ? (
                    <>
                        <SkinFace url={shown} size={56} className='rounded-md' />
                        <img
                            src={shown}
                            alt={`${name}'s skin file`}
                            width={128}
                            height={128}
                            style={{ imageRendering: 'pixelated' }}
                            className='rounded-md border border-mocha-300 bg-mocha-400'
                        />
                    </>
                ) : (
                    <p className='text-sm text-mocha-50'>
                        No custom skin yet. Offline-mode players show as Steve/Alex unless the server has a skin mod.
                    </p>
                )}
            </div>

            {canEdit && (
                <div className='mt-4 flex flex-col gap-3'>
                    <div className='flex flex-wrap items-center gap-2'>
                        <input
                            ref={input}
                            type='file'
                            accept='image/png'
                            className='hidden'
                            onChange={(event) => pick(event.target.files?.[0] ?? null)}
                        />
                        <Button variant='secondary' type='button' onClick={() => input.current?.click()}>
                            <span className='flex items-center gap-2'>
                                <ArrowShapeUpFromLine width={15} height={15} aria-hidden />
                                {file ? file.name : 'Choose PNG'}
                            </span>
                        </Button>
                        <div className='flex gap-1'>
                            {(['classic', 'slim'] as const).map((option) => (
                                <button
                                    key={option}
                                    type='button'
                                    aria-pressed={variant === option}
                                    onClick={() => setVariant(option)}
                                    className={`rounded-full border px-3 py-1.5 text-sm ${
                                        variant === option
                                            ? 'border-cream-400 bg-cream-400 text-mocha-500'
                                            : 'border-mocha-300 hover:bg-mocha-400'
                                    }`}
                                >
                                    {option === 'classic' ? 'Classic (Steve)' : 'Slim (Alex)'}
                                </button>
                            ))}
                        </div>
                    </div>
                    <div className='flex flex-wrap gap-2'>
                        <Button
                            type='button'
                            disabled={!file || busy}
                            onClick={() =>
                                file &&
                                void run(async () => {
                                    const result = await uploadSkin(uuid, name, file, variant);
                                    pick(null);
                                    if (input.current) input.current.value = '';
                                    return result;
                                })
                            }
                        >
                            {busy && file ? 'Uploading…' : 'Upload & apply'}
                        </Button>
                        {skin && (
                            <>
                                <Button
                                    variant='secondary'
                                    type='button'
                                    disabled={busy}
                                    onClick={() => void run(() => applySkin(uuid, name))}
                                >
                                    <span className='flex items-center gap-2'>
                                        <ArrowsRotateRight width={15} height={15} aria-hidden />
                                        Apply again
                                    </span>
                                </Button>
                                <Button
                                    variant='secondary'
                                    type='button'
                                    disabled={busy}
                                    onClick={() => void run(() => deleteSkin(uuid, name))}
                                >
                                    <span className='flex items-center gap-2'>
                                        <TrashBin width={15} height={15} aria-hidden />
                                        Remove
                                    </span>
                                </Button>
                            </>
                        )}
                    </div>
                    <p className='text-xs text-mocha-50'>
                        Applied in game through the SkinRestorer mod (Fabric/Forge/NeoForge/Quilt) or SkinsRestorer
                        plugin (Paper). The player must be online when it's applied.
                    </p>
                </div>
            )}

            {message && (
                <p className='mcp-submitted' role='status'>
                    {message}
                </p>
            )}
            {error && (
                <p className='mcp-tool-error' role='alert'>
                    {error}
                </p>
            )}
        </section>
    );
}
