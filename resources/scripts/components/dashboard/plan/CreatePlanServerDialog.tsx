import { Magnifier } from '@gravity-ui/icons';
import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { toast } from 'sonner';
import { useSWRConfig } from 'swr';

import { cores, createPlanServer, gb, remaining, type UserPlanInfo } from '@/api/account/plan';
import { httpErrorToHuman } from '@/api/http';
import { Dialog } from '@/components/elements/dialog';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

import ResourceSlider from './ResourceSlider';

const inputClass =
    'w-full rounded-xl border border-mocha-300/50 bg-mocha-400/50 px-4 py-3 text-sm text-cream-400 placeholder:text-cream-400/40 focus:border-brand-400/60 focus:outline-none';

const floorTo = (value: number, step: number) => Math.floor(value / step) * step;

export default function CreatePlanServerDialog({ info, onClose }: { info: UserPlanInfo; onClose: () => void }) {
    const navigate = useNavigate();
    const { mutate } = useSWRConfig();
    const left = remaining(info);
    const maxRam = floorTo(gb(left.memory), 0.5);
    const maxCpu = floorTo(cores(left.cpu), 0.5);
    const maxDisk = Math.floor(gb(left.disk));
    const atCap = (info.plan?.servers ?? 0) > 0 && info.used.servers >= (info.plan?.servers ?? 0);

    const [name, setName] = useState('');
    const [search, setSearch] = useState('');
    const [eggId, setEggId] = useState<number | null>(
        () => info.eggs.find((egg) => /paper/i.test(egg.name))?.id ?? info.eggs[0]?.id ?? null,
    );
    const [ram, setRam] = useState(Math.min(4, maxRam));
    const [cpu, setCpu] = useState(Math.min(2, maxCpu));
    const [disk, setDisk] = useState(Math.min(20, maxDisk));
    const [saving, setSaving] = useState(false);

    const groups = useMemo(() => {
        const term = search.trim().toLowerCase();
        const map = new Map<string, typeof info.eggs>();
        for (const egg of info.eggs) {
            if (term && !`${egg.name} ${egg.nest}`.toLowerCase().includes(term)) continue;
            map.set(egg.nest, [...(map.get(egg.nest) ?? []), egg]);
        }
        return [...map.entries()];
    }, [info.eggs, search]);

    const tooSmall = maxRam < 0.5 || maxCpu < 0.5 || maxDisk < 1;
    const valid = name.trim().length > 0 && eggId !== null && !tooSmall && !atCap;

    const create = async () => {
        if (!valid || saving || eggId === null) return;
        setSaving(true);
        try {
            const server = await createPlanServer({
                name: name.trim(),
                egg_id: eggId,
                memory: Math.round(ram * 1024),
                cpu: Math.round(cpu * 100),
                disk: Math.round(disk * 1024),
            });
            toast.success(`${name.trim()} is being created. It installs in the background.`);
            void mutate('account:plan');
            onClose();
            navigate(`/server/${server.identifier}`);
        } catch (error) {
            toast.error(httpErrorToHuman(error as Error) || 'Could not create the server.');
        } finally {
            setSaving(false);
        }
    };

    return (
        <Dialog open onClose={onClose} title='New server' description='Pick a game and how much of your plan it gets.'>
            <form
                className='flex flex-col gap-5'
                onSubmit={(event) => {
                    event.preventDefault();
                    void create();
                }}
            >
                {(atCap || tooSmall) && (
                    <p className='rounded-xl border border-red-500/30 bg-red-500/10 p-3 text-sm text-red-300'>
                        {atCap
                            ? `You've used all ${info.plan?.servers} servers in your plan. Delete one or ask for a bigger plan.`
                            : 'Not enough left in your plan for another server. Shrink or delete one first.'}
                    </p>
                )}
                <label className='flex flex-col gap-1.5 text-sm'>
                    <span className='text-cream-400/70'>Server name</span>
                    <input
                        className={inputClass}
                        value={name}
                        maxLength={191}
                        placeholder='Survival with friends'
                        onChange={(event) => setName(event.target.value)}
                    />
                </label>

                <div className='flex flex-col gap-2 text-sm'>
                    <span className='text-cream-400/70'>Game</span>
                    <div className='relative'>
                        <Magnifier
                            width={15}
                            height={15}
                            fill='currentColor'
                            className='pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-cream-400/40'
                        />
                        <input
                            className={`${inputClass} pl-9`}
                            value={search}
                            placeholder='Search games…'
                            aria-label='Search games'
                            onChange={(event) => setSearch(event.target.value)}
                        />
                    </div>
                    <div className='max-h-56 overflow-y-auto rounded-xl border border-mocha-300/50 bg-mocha-500/60 p-1'>
                        {groups.length === 0 ? (
                            <p className='p-3 text-xs text-cream-400/50'>No games match.</p>
                        ) : (
                            groups.map(([nest, eggs]) => (
                                <div key={nest} className='mb-1'>
                                    <p className='px-2 pt-2 pb-1 text-[10px] font-semibold uppercase tracking-wide text-cream-400/40'>
                                        {nest}
                                    </p>
                                    {eggs.map((egg) => (
                                        <button
                                            key={egg.id}
                                            type='button'
                                            aria-pressed={eggId === egg.id}
                                            title={egg.description || egg.name}
                                            onClick={() => setEggId(egg.id)}
                                            className={cn(
                                                'block w-full rounded-lg px-3 py-2 text-left text-sm transition',
                                                eggId === egg.id
                                                    ? 'bg-brand-400/80 text-mocha-500 font-semibold'
                                                    : 'text-cream-400/80 hover:bg-mocha-400',
                                            )}
                                        >
                                            {egg.name}
                                        </button>
                                    ))}
                                </div>
                            ))
                        )}
                    </div>
                </div>

                <ResourceSlider
                    label='RAM'
                    value={ram}
                    onChange={setRam}
                    min={0.5}
                    max={maxRam}
                    step={0.5}
                    format={(v) => `${v} GB`}
                    hint={`${maxRam} GB left`}
                />
                <ResourceSlider
                    label='CPU'
                    value={cpu}
                    onChange={setCpu}
                    min={0.5}
                    max={maxCpu}
                    step={0.5}
                    format={(v) => `${v} ${v === 1 ? 'core' : 'cores'}`}
                    hint={`${maxCpu} left`}
                />
                <ResourceSlider
                    label='Storage'
                    value={disk}
                    onChange={setDisk}
                    min={1}
                    max={maxDisk}
                    step={1}
                    format={(v) => `${v} GB`}
                    hint={`${maxDisk} GB left`}
                />

                <div className='flex justify-end gap-2'>
                    <Button type='button' variant='secondary' onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type='submit' disabled={!valid || saving}>
                        {saving ? 'Creating…' : 'Create server'}
                    </Button>
                </div>
            </form>
        </Dialog>
    );
}
