import { useState } from 'react';
import { toast } from 'sonner';

import { httpErrorToHuman } from '@/api/http';
import updateQuickBuild from '@/api/server/updateQuickBuild';
import { Dialog } from '@/components/elements/dialog';
import { Button } from '@/components/ui/button';

const inputClass =
    'w-full rounded-xl border border-mocha-300/50 bg-mocha-400/50 px-4 py-3 text-sm text-cream-400 placeholder:text-cream-400/40 focus:border-brand-400/60 focus:outline-none';

/**
 * Admin-only CPU/RAM editor opened from the pencil on a server row.
 * Cores map to Pterodactyl's CPU percent (1 core = 100%), RAM is entered in GB.
 */
export default function QuickBuildDialog({
    open,
    onClose,
    uuid,
    name,
    memory,
    cpu,
    onSaved,
}: {
    open: boolean;
    onClose: () => void;
    uuid: string;
    name: string;
    memory: number;
    cpu: number;
    onSaved: (limits: { memory: number; cpu: number }) => void;
}) {
    const [cores, setCores] = useState(cpu === 0 ? '0' : String(cpu / 100));
    const [ram, setRam] = useState(memory === 0 ? '0' : String(Math.round((memory / 1024) * 100) / 100));
    const [saving, setSaving] = useState(false);

    const coreValue = Number(cores);
    const ramValue = Number(ram);
    const valid =
        cores.trim() !== '' &&
        ram.trim() !== '' &&
        Number.isFinite(coreValue) &&
        Number.isFinite(ramValue) &&
        coreValue >= 0 &&
        coreValue <= 512 &&
        ramValue >= 0 &&
        ramValue <= 1024;

    const save = async () => {
        if (!valid || saving) return;
        setSaving(true);
        try {
            const limits = await updateQuickBuild(uuid, Math.round(ramValue * 1024), Math.round(coreValue * 100));
            onSaved(limits);
            toast.success(`${name} updated. Restart the server to use the new limits.`);
            onClose();
        } catch (error) {
            toast.error(httpErrorToHuman(error as Error) || 'Could not update the server.');
        } finally {
            setSaving(false);
        }
    };

    return (
        <Dialog
            open={open}
            onClose={onClose}
            title={`Edit ${name}`}
            description='CPU cores and RAM. 0 means unlimited.'
        >
            <form
                className='flex flex-col gap-4'
                onSubmit={(event) => {
                    event.preventDefault();
                    void save();
                }}
            >
                <label className='flex flex-col gap-1.5 text-sm'>
                    <span className='text-cream-400/70'>CPU cores</span>
                    <input
                        className={inputClass}
                        type='number'
                        inputMode='decimal'
                        min={0}
                        max={512}
                        step={0.25}
                        value={cores}
                        onChange={(event) => setCores(event.target.value)}
                    />
                    <span className='text-xs text-cream-400/40'>
                        {coreValue > 0 ? `${Math.round(coreValue * 100)}% CPU` : 'No CPU limit'}
                    </span>
                </label>
                <label className='flex flex-col gap-1.5 text-sm'>
                    <span className='text-cream-400/70'>RAM (GB)</span>
                    <input
                        className={inputClass}
                        type='number'
                        inputMode='decimal'
                        min={0}
                        max={1024}
                        step={0.5}
                        value={ram}
                        onChange={(event) => setRam(event.target.value)}
                    />
                    <span className='text-xs text-cream-400/40'>
                        {ramValue > 0 ? `${Math.round(ramValue * 1024)} MiB` : 'No RAM limit'}
                    </span>
                </label>
                <p className='text-xs text-cream-400/50'>
                    Saved instantly. Minecraft only picks up new RAM after a restart.
                </p>
                <div className='flex justify-end gap-2'>
                    <Button type='button' variant='secondary' onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type='submit' disabled={!valid || saving}>
                        {saving ? 'Saving…' : 'Save'}
                    </Button>
                </div>
            </form>
        </Dialog>
    );
}
