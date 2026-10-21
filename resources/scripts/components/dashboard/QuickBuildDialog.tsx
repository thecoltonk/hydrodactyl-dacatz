import { useState } from 'react';
import { toast } from 'sonner';
import { useSWRConfig } from 'swr';

import { cores, deletePlanServer, gb, remaining, type UserPlanInfo } from '@/api/account/plan';
import { httpErrorToHuman } from '@/api/http';
import updateQuickBuild from '@/api/server/updateQuickBuild';
import { Dialog } from '@/components/elements/dialog';
import { Button } from '@/components/ui/button';

import ResourceSlider from './plan/ResourceSlider';

const inputClass =
    'w-full rounded-xl border border-mocha-300/50 bg-mocha-400/50 px-4 py-3 text-sm text-cream-400 placeholder:text-cream-400/40 focus:border-brand-400/60 focus:outline-none';

export interface Limits {
    memory: number;
    cpu: number;
    disk: number;
}

const floorTo = (value: number, step: number) => Math.floor(value / step) * step;

/**
 * The pencil on a server row. Admins get free number inputs (0 = unlimited);
 * plan owners get sliders capped by what's left in their plan. Both can delete.
 */
export default function QuickBuildDialog({
    onClose,
    uuid,
    name,
    limits,
    plan,
    onSaved,
    onDeleted,
}: {
    onClose: () => void;
    uuid: string;
    name: string;
    limits: Limits;
    /** Set for plan owners (not admins): enables the capped slider mode. */
    plan: UserPlanInfo | null;
    onSaved: (limits: Limits) => void;
    onDeleted: () => void;
}) {
    const { mutate } = useSWRConfig();
    const capped = plan?.plan != null;
    const left = plan ? remaining(plan, limits) : null;

    const [ram, setRam] = useState(gb(limits.memory));
    const [cpu, setCpu] = useState(cores(limits.cpu));
    const [disk, setDisk] = useState(gb(limits.disk));
    const [ramText, setRamText] = useState(String(gb(limits.memory)));
    const [cpuText, setCpuText] = useState(String(cores(limits.cpu)));
    const [diskText, setDiskText] = useState(String(gb(limits.disk)));
    const [saving, setSaving] = useState(false);
    const [confirm, setConfirm] = useState('');
    const [deleting, setDeleting] = useState(false);

    const values = capped ? { ram, cpu, disk } : { ram: Number(ramText), cpu: Number(cpuText), disk: Number(diskText) };
    const valid = Object.values(values).every((value) => Number.isFinite(value) && value >= 0);

    const save = async () => {
        if (!valid || saving) return;
        setSaving(true);
        try {
            const result = await updateQuickBuild(
                uuid,
                Math.round(values.ram * 1024),
                Math.round(values.cpu * 100),
                Math.round(values.disk * 1024),
            );
            onSaved(result);
            void mutate('account:plan');
            toast.success(`${name} updated. Restart the server to use the new limits.`);
            onClose();
        } catch (error) {
            toast.error(httpErrorToHuman(error as Error) || 'Could not update the server.');
        } finally {
            setSaving(false);
        }
    };

    const remove = async () => {
        if (confirm !== name || deleting) return;
        setDeleting(true);
        try {
            await deletePlanServer(uuid, confirm);
            void mutate('account:plan');
            toast.success(`${name} deleted.`);
            onDeleted();
            onClose();
        } catch (error) {
            toast.error(httpErrorToHuman(error as Error) || 'Could not delete the server.');
            setDeleting(false);
        }
    };

    return (
        <Dialog
            open
            onClose={onClose}
            title={`Edit ${name}`}
            description={capped ? 'Resize within your plan.' : 'Admin: 0 means unlimited.'}
        >
            <form
                className='flex flex-col gap-5'
                onSubmit={(event) => {
                    event.preventDefault();
                    void save();
                }}
            >
                {capped && left ? (
                    <>
                        <ResourceSlider
                            label='RAM'
                            value={ram}
                            onChange={setRam}
                            min={0.5}
                            max={floorTo(gb(left.memory), 0.5)}
                            step={0.5}
                            unit='GB'
                            hint={`up to ${floorTo(gb(left.memory), 0.5)} GB`}
                        />
                        <ResourceSlider
                            label='CPU'
                            value={cpu}
                            onChange={setCpu}
                            min={0.5}
                            max={floorTo(cores(left.cpu), 0.5)}
                            step={0.5}
                            unit='cores'
                            hint={`up to ${floorTo(cores(left.cpu), 0.5)}`}
                        />
                        <ResourceSlider
                            label='Storage'
                            value={disk}
                            onChange={setDisk}
                            min={1}
                            max={Math.floor(gb(left.disk))}
                            step={1}
                            unit='GB'
                            hint={`up to ${Math.floor(gb(left.disk))} GB`}
                        />
                    </>
                ) : (
                    (
                        [
                            ['CPU cores', cpuText, setCpuText, 0.25],
                            ['RAM (GB)', ramText, setRamText, 0.5],
                            ['Storage (GB)', diskText, setDiskText, 1],
                        ] as const
                    ).map(([label, value, set, step]) => (
                        <label key={label} className='flex flex-col gap-1.5 text-sm'>
                            <span className='text-cream-400/70'>{label}</span>
                            <input
                                className={inputClass}
                                type='number'
                                inputMode='decimal'
                                min={0}
                                step={step}
                                value={value}
                                onChange={(event) => set(event.target.value)}
                            />
                        </label>
                    ))
                )}
                <p className='text-xs text-cream-400/50'>
                    Saved instantly. Minecraft only picks up new RAM after a restart. Shrinking storage below what the
                    server already uses stops it from writing new files.
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

            <div className='mt-6 flex flex-col gap-2 rounded-xl border border-red-500/30 bg-red-500/5 p-4'>
                <strong className='text-sm text-red-300'>Delete server</strong>
                <p className='text-xs text-cream-400/60'>
                    Permanently deletes {name} and all its files
                    {capped ? ' and gives its resources back to your plan' : ''}. Type <code>{name}</code> to confirm.
                </p>
                <div className='flex flex-col gap-2 sm:flex-row'>
                    <input
                        className={inputClass}
                        aria-label='Type the server name to confirm'
                        value={confirm}
                        onChange={(event) => setConfirm(event.target.value)}
                    />
                    <Button
                        type='button'
                        variant='destructive'
                        disabled={confirm !== name || deleting}
                        onClick={() => void remove()}
                    >
                        {deleting ? 'Deleting…' : 'Delete'}
                    </Button>
                </div>
            </div>
        </Dialog>
    );
}
