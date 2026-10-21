import { Magnifier } from '@gravity-ui/icons';
import { useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { toast } from 'sonner';
import { useSWRConfig } from 'swr';

import {
    cores,
    createPlanServer,
    gb,
    getPlanModpackVersions,
    remaining,
    searchPlanModpacks,
    type UserPlanInfo,
} from '@/api/account/plan';
import { httpErrorToHuman } from '@/api/http';
import type { ModpackHit, ModpackSource, ModpackVersion } from '@/api/server/modpacks';
import { Dialog } from '@/components/elements/dialog';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

import ResourceSlider from './ResourceSlider';

const inputClass =
    'w-full rounded-xl border border-mocha-300/50 bg-mocha-400/50 px-4 py-3 text-sm text-cream-400 placeholder:text-cream-400/40 focus:border-brand-400/60 focus:outline-none';

const floorTo = (value: number, step: number) => Math.floor(value / step) * step;

const compact = (n: number) =>
    n >= 1_000_000 ? `${(n / 1_000_000).toFixed(1)}M` : n >= 1_000 ? `${(n / 1_000).toFixed(1)}K` : String(n);

const Pill = ({ active, onClick, children }: { active: boolean; onClick: () => void; children: React.ReactNode }) => (
    <button
        type='button'
        aria-pressed={active}
        onClick={onClick}
        className={cn(
            'flex-1 rounded-lg px-3 py-2 text-sm font-medium transition',
            active ? 'bg-brand-400/80 text-mocha-500' : 'text-cream-400/70 hover:bg-mocha-300/40',
        )}
    >
        {children}
    </button>
);

export default function CreatePlanServerDialog({ info, onClose }: { info: UserPlanInfo; onClose: () => void }) {
    const navigate = useNavigate();
    const { mutate } = useSWRConfig();
    const left = remaining(info);
    const maxRam = floorTo(gb(left.memory), 0.5);
    const maxCpu = floorTo(cores(left.cpu), 0.5);
    const maxDisk = Math.floor(gb(left.disk));
    const atCap = (info.plan?.servers ?? 0) > 0 && info.used.servers >= (info.plan?.servers ?? 0);

    const [mode, setMode] = useState<'game' | 'modpack'>('game');
    const [name, setName] = useState('');
    const [search, setSearch] = useState('');
    const [eggId, setEggId] = useState<number | null>(
        () => info.eggs.find((egg) => /paper/i.test(egg.name))?.id ?? info.eggs[0]?.id ?? null,
    );
    const [ram, setRam] = useState(Math.min(4, maxRam));
    const [cpu, setCpu] = useState(Math.min(2, maxCpu));
    const [disk, setDisk] = useState(Math.min(20, maxDisk));
    const [saving, setSaving] = useState(false);

    // Modpack picker
    const [source, setSource] = useState<ModpackSource>('modrinth');
    const [packQuery, setPackQuery] = useState('');
    const [packs, setPacks] = useState<ModpackHit[]>([]);
    const [packsLoading, setPacksLoading] = useState(false);
    const [packError, setPackError] = useState('');
    const [pack, setPack] = useState<ModpackHit | null>(null);
    const [versions, setVersions] = useState<ModpackVersion[]>([]);
    const [versionId, setVersionId] = useState<string>('');

    useEffect(() => {
        if (mode !== 'modpack') return;
        let active = true;
        const handle = window.setTimeout(() => {
            setPacksLoading(true);
            setPackError('');
            searchPlanModpacks(source, packQuery.trim())
                .then((result) => active && setPacks(result.hits))
                .catch((error) => {
                    if (!active) return;
                    setPacks([]);
                    setPackError(httpErrorToHuman(error as Error));
                })
                .finally(() => active && setPacksLoading(false));
        }, 350);
        return () => {
            active = false;
            window.clearTimeout(handle);
        };
    }, [mode, source, packQuery]);

    useEffect(() => {
        setVersions([]);
        setVersionId('');
        if (!pack) return;
        let active = true;
        getPlanModpackVersions(source, pack.id)
            .then((list) => {
                if (!active) return;
                setVersions(list);
                setVersionId((list.find((v) => v.type === 'release') ?? list[0])?.id ?? '');
            })
            .catch((error) => active && setPackError(httpErrorToHuman(error as Error)));
        return () => {
            active = false;
        };
    }, [pack, source]);

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
    const picked = mode === 'game' ? eggId !== null : pack !== null && versionId !== '';
    const valid = name.trim().length > 0 && picked && !tooSmall && !atCap;

    const create = async () => {
        if (!valid || saving) return;
        setSaving(true);
        try {
            const server = await createPlanServer({
                name: name.trim(),
                ...(mode === 'game'
                    ? { egg_id: eggId ?? undefined }
                    : { modpack: { source, project_id: pack!.id, version_id: versionId } }),
                memory: Math.round(ram * 1024),
                cpu: Math.round(cpu * 100),
                disk: Math.round(disk * 1024),
            });
            toast.success(
                mode === 'modpack'
                    ? `${name.trim()} is being created. ${pack?.title} installs right after, follow it on the Modpacks tab.`
                    : `${name.trim()} is being created. It installs in the background.`,
            );
            void mutate('account:plan');
            onClose();
            navigate(mode === 'modpack' ? `/server/${server.identifier}/modpacks` : `/server/${server.identifier}`);
        } catch (error) {
            toast.error(httpErrorToHuman(error as Error) || 'Could not create the server.');
        } finally {
            setSaving(false);
        }
    };

    return (
        <Dialog
            open
            onClose={onClose}
            title='New server'
            description='Pick a game or a modpack and how much of your plan it gets.'
        >
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

                <div className='flex rounded-xl border border-mocha-300/50 bg-mocha-400/50 p-1'>
                    <Pill active={mode === 'game'} onClick={() => setMode('game')}>
                        Game
                    </Pill>
                    <Pill active={mode === 'modpack'} onClick={() => setMode('modpack')}>
                        Modpack
                    </Pill>
                </div>

                {mode === 'game' ? (
                    <div className='flex flex-col gap-2 text-sm'>
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
                                                        ? 'bg-brand-400/80 font-semibold text-mocha-500'
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
                ) : (
                    <div className='flex flex-col gap-2 text-sm'>
                        {info.curseforge && (
                            <div className='flex rounded-lg border border-mocha-300/50 bg-mocha-400/30 p-0.5'>
                                <Pill
                                    active={source === 'modrinth'}
                                    onClick={() => {
                                        setSource('modrinth');
                                        setPack(null);
                                    }}
                                >
                                    Modrinth
                                </Pill>
                                <Pill
                                    active={source === 'curseforge'}
                                    onClick={() => {
                                        setSource('curseforge');
                                        setPack(null);
                                    }}
                                >
                                    CurseForge
                                </Pill>
                            </div>
                        )}
                        <div className='relative'>
                            <Magnifier
                                width={15}
                                height={15}
                                fill='currentColor'
                                className='pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-cream-400/40'
                            />
                            <input
                                className={`${inputClass} pl-9`}
                                value={packQuery}
                                placeholder='Search modpacks…'
                                aria-label='Search modpacks'
                                onChange={(event) => setPackQuery(event.target.value)}
                            />
                        </div>
                        <div className='max-h-56 overflow-y-auto rounded-xl border border-mocha-300/50 bg-mocha-500/60 p-1'>
                            {packError ? (
                                <p className='p-3 text-xs text-red-300'>{packError}</p>
                            ) : packsLoading && packs.length === 0 ? (
                                <p className='p-3 text-xs text-cream-400/50'>Searching…</p>
                            ) : packs.length === 0 ? (
                                <p className='p-3 text-xs text-cream-400/50'>No modpacks found.</p>
                            ) : (
                                packs.map((hit) => (
                                    <button
                                        key={hit.id}
                                        type='button'
                                        aria-pressed={pack?.id === hit.id}
                                        onClick={() => {
                                            setPack(hit);
                                            if (!name.trim()) setName(hit.title);
                                        }}
                                        className={cn(
                                            'flex w-full items-center gap-3 rounded-lg px-2 py-2 text-left transition',
                                            pack?.id === hit.id
                                                ? 'bg-brand-400/80 text-mocha-500'
                                                : 'hover:bg-mocha-400',
                                        )}
                                    >
                                        {hit.icon ? (
                                            <img
                                                src={hit.icon}
                                                alt=''
                                                referrerPolicy='no-referrer'
                                                loading='lazy'
                                                className='size-8 shrink-0 rounded-md object-cover'
                                            />
                                        ) : (
                                            <span className='flex size-8 shrink-0 items-center justify-center rounded-md bg-mocha-300/50 text-xs'>
                                                {hit.title.charAt(0)}
                                            </span>
                                        )}
                                        <span className='min-w-0 flex-1'>
                                            <span className='block truncate text-sm font-medium'>{hit.title}</span>
                                            <span
                                                className={cn(
                                                    'block truncate text-[11px]',
                                                    pack?.id === hit.id ? 'text-mocha-500/70' : 'text-cream-400/50',
                                                )}
                                            >
                                                {compact(hit.downloads)} downloads ·{' '}
                                                {hit.loaders.join(', ') || 'modpack'}
                                            </span>
                                        </span>
                                    </button>
                                ))
                            )}
                        </div>
                        {pack && (
                            <label className='flex flex-col gap-1.5'>
                                <span className='text-cream-400/70'>Version</span>
                                <select
                                    className={inputClass}
                                    value={versionId}
                                    disabled={versions.length === 0}
                                    onChange={(event) => setVersionId(event.target.value)}
                                >
                                    {versions.length === 0 && <option>Loading versions…</option>}
                                    {versions.map((version) => (
                                        <option key={version.id} value={version.id}>
                                            {version.name} · MC {version.game_versions[0] ?? '?'}
                                            {version.type !== 'release' ? ` (${version.type})` : ''}
                                        </option>
                                    ))}
                                </select>
                                <span className='text-xs text-cream-400/40'>
                                    Modpacks usually want 6 GB+ RAM. The right loader and Java version are set up
                                    automatically.
                                </span>
                            </label>
                        )}
                    </div>
                )}

                <ResourceSlider
                    label='RAM'
                    unit='GB'
                    value={ram}
                    onChange={setRam}
                    min={0.5}
                    max={maxRam}
                    step={0.5}
                    hint={`${maxRam} GB left`}
                />
                <ResourceSlider
                    label='CPU'
                    unit='cores'
                    value={cpu}
                    onChange={setCpu}
                    min={0.5}
                    max={maxCpu}
                    step={0.5}
                    hint={`${maxCpu} left`}
                />
                <ResourceSlider
                    label='Storage'
                    unit='GB'
                    value={disk}
                    onChange={setDisk}
                    min={1}
                    max={maxDisk}
                    step={1}
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
