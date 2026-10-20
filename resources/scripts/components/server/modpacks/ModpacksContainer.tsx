import {
    ArrowDownToLine,
    ArrowsRotateRight,
    CircleCheck,
    CircleXmark,
    Magnifier,
    TriangleExclamation,
} from '@gravity-ui/icons';
import type { AxiosError } from 'axios';
import { useEffect, useState } from 'react';
import useSWR from 'swr';
import { useDebounce } from 'use-debounce';

import { httpErrorToHuman } from '@/api/http';
import {
    getModpackStatus,
    getModpackVersions,
    installModpack,
    type ModpackHit,
    type ModpackLoader,
    type ModpackSource,
    searchModpacks,
} from '@/api/server/modpacks';
import { Dialog } from '@/components/elements/dialog';
import { Input } from '@/components/elements/inputs';
import { MainPageHeader } from '@/components/elements/MainPageHeader';
import ServerContentBlock from '@/components/elements/ServerContentBlock';
import ServerHeader from '@/components/server/header/ServerHeader';
import { Button } from '@/components/ui/button';

import { ServerContext } from '@/state/server';

const LOADERS: { key: ModpackLoader | null; label: string }[] = [
    { key: null, label: 'All' },
    { key: 'fabric', label: 'Fabric' },
    { key: 'forge', label: 'Forge' },
    { key: 'neoforge', label: 'NeoForge' },
    { key: 'quilt', label: 'Quilt' },
];

const LOADER_LABEL: Record<string, string> = { fabric: 'Fabric', forge: 'Forge', neoforge: 'NeoForge', quilt: 'Quilt' };

const compact = new Intl.NumberFormat(undefined, { notation: 'compact', maximumFractionDigits: 1 });

const Spin = ({ size = 16 }: { size?: number }) => (
    <ArrowsRotateRight width={size} height={size} className='animate-spin' aria-hidden />
);

function InstallDialog({
    uuid,
    source,
    pack,
    onClose,
    onStarted,
}: {
    uuid: string;
    source: ModpackSource;
    pack: ModpackHit;
    onClose: () => void;
    onStarted: () => void;
}) {
    const versions = useSWR(
        ['modpacks:versions', uuid, source, pack.id],
        () => getModpackVersions(uuid, source, pack.id),
        {
            revalidateOnFocus: false,
        },
    );
    const [selected, setSelected] = useState<string | null>(null);
    const [confirmed, setConfirmed] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const list = (versions.data ?? []).filter((version) => version.loaders.length > 0);
    const versionId = selected ?? list[0]?.id ?? null;

    const install = async () => {
        if (!versionId || !confirmed || busy) return;
        setBusy(true);
        setError('');
        try {
            await installModpack(uuid, source, pack.id, versionId);
            onStarted();
        } catch (failure) {
            setError(httpErrorToHuman(failure as Error));
            setBusy(false);
        }
    };

    return (
        <Dialog
            open
            title={`Install ${pack.title}`}
            description='Pick a version. The server is wiped and rebuilt with the right loader and Minecraft version.'
            onClose={() => !busy && onClose()}
            preventExternalClose={busy}
            hideCloseIcon={busy}
        >
            <div className='flex flex-col gap-4'>
                {versions.isLoading ? (
                    <div className='flex items-center gap-2 text-sm text-mocha-50'>
                        <Spin /> Loading versions…
                    </div>
                ) : versions.error ? (
                    <p className='text-sm text-red-400'>{httpErrorToHuman(versions.error)}</p>
                ) : list.length === 0 ? (
                    <p className='text-sm text-mocha-50'>This modpack has no installable server versions.</p>
                ) : (
                    <div
                        className='flex max-h-72 flex-col gap-1 overflow-y-auto rounded-xl border border-mocha-300 p-1'
                        role='radiogroup'
                        aria-label='Modpack version'
                    >
                        {list.map((version) => (
                            <label
                                key={version.id}
                                className={`flex cursor-pointer items-center gap-3 rounded-lg px-3 py-2 text-sm hover:bg-mocha-400 ${
                                    versionId === version.id ? 'bg-mocha-400' : ''
                                }`}
                            >
                                <input
                                    type='radio'
                                    name='modpack-version'
                                    checked={versionId === version.id}
                                    onChange={() => setSelected(version.id)}
                                    className='accent-brand-400'
                                />
                                <span className='min-w-0 flex-1 truncate font-medium'>{version.name}</span>
                                <span className='shrink-0 text-xs text-mocha-50'>
                                    {version.loaders.map((loader) => LOADER_LABEL[loader] ?? loader).join(', ')} ·{' '}
                                    {version.game_versions.at(-1) ?? '?'}
                                    {version.type !== 'release' ? ` · ${version.type}` : ''}
                                    {version.server_pack ? ' · server pack' : ''}
                                </span>
                            </label>
                        ))}
                    </div>
                )}

                <div className='flex gap-3 rounded-xl border border-red-500/40 bg-red-500/10 p-3 text-sm'>
                    <TriangleExclamation width={18} height={18} className='mt-0.5 shrink-0 text-red-400' aria-hidden />
                    <div>
                        <strong className='block'>This deletes every file on the server.</strong>
                        Worlds, plugins and configs are wiped first. Download anything you want to keep, or take a
                        backup, before continuing. The server's software (egg) and Java version are changed to match the
                        pack.
                    </div>
                </div>

                <label htmlFor='modpack-confirm' className='flex cursor-pointer items-center gap-2 text-sm'>
                    <Input.Checkbox
                        id='modpack-confirm'
                        checked={confirmed}
                        onChange={(event) => setConfirmed(event.target.checked)}
                    />
                    <span>I understand all server files will be deleted</span>
                </label>

                {error && (
                    <p className='text-sm text-red-400' role='alert'>
                        {error}
                    </p>
                )}

                <div className='flex justify-end gap-2'>
                    <Button variant='secondary' type='button' disabled={busy} onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        variant='destructive'
                        type='button'
                        disabled={!versionId || !confirmed || busy}
                        onClick={() => void install()}
                    >
                        <span className='flex items-center gap-2'>
                            {busy ? <Spin /> : <ArrowDownToLine width={16} height={16} aria-hidden />}
                            {busy ? 'Preparing…' : 'Wipe & install'}
                        </span>
                    </Button>
                </div>
            </div>
        </Dialog>
    );
}

const ModpacksContainer = () => {
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const [query, setQuery] = useState('');
    const [debounced] = useDebounce(query.trim(), 400);
    const [loader, setLoader] = useState<ModpackLoader | null>(null);
    const [source, setSource] = useState<ModpackSource>('modrinth');
    const [pages, setPages] = useState(1);
    const [selected, setSelected] = useState<ModpackHit | null>(null);

    // biome-ignore lint/correctness/useExhaustiveDependencies: reset paging when the search changes
    useEffect(() => setPages(1), [debounced, loader, source]);

    const results = useSWR<{ hits: ModpackHit[]; total: number }, AxiosError>(
        ['modpacks:search', uuid, source, debounced, loader, pages],
        async () => {
            const all: ModpackHit[] = [];
            let total = 0;
            for (let page = 0; page < pages; page++) {
                const result = await searchModpacks(uuid, source, debounced, loader, page * 20);
                all.push(...result.hits);
                total = result.total;
            }
            return { hits: all, total };
        },
        { revalidateOnFocus: false, keepPreviousData: true },
    );

    const status = useSWR(['modpacks:status', uuid], () => getModpackStatus(uuid), {
        refreshInterval: (latest) => (latest?.is_active ? 3000 : 0),
        revalidateOnFocus: true,
    });
    const operation = status.data;
    const active = !!operation?.is_active;

    const hits = results.data?.hits ?? [];

    return (
        <ServerContentBlock title={'Modpacks'}>
            <ServerHeader />
            <div className='w-full h-full min-h-full flex-1 flex flex-col px-2 sm:px-0'>
                <MainPageHeader direction='column' title={'Modpacks'}>
                    <p className='text-sm text-mocha-50'>
                        One-click modpacks from Modrinth or CurseForge. Installing sets up the right loader, Minecraft
                        version and Java for you.
                    </p>
                </MainPageHeader>

                {operation && (
                    <div
                        className={`mb-6 flex items-start gap-3 rounded-xl border p-4 text-sm ${
                            operation.has_failed
                                ? 'border-red-500/40 bg-red-500/10'
                                : operation.is_completed
                                  ? 'border-green-500/40 bg-green-500/10'
                                  : 'border-mocha-300 bg-mocha-400'
                        }`}
                        role='status'
                    >
                        {active ? (
                            <Spin size={18} />
                        ) : operation.has_failed ? (
                            <CircleXmark width={18} height={18} className='text-red-400' aria-hidden />
                        ) : (
                            <CircleCheck width={18} height={18} className='text-green-400' aria-hidden />
                        )}
                        <div className='min-w-0'>
                            <strong className='block'>
                                {active
                                    ? `Installing ${String(operation.parameters?.title ?? 'modpack')}…`
                                    : operation.has_failed
                                      ? 'Last modpack install failed'
                                      : 'Last modpack install finished'}
                            </strong>
                            <span className='break-words text-mocha-50'>{operation.message}</span>
                            {active && (
                                <span className='mt-1 block text-xs text-mocha-50'>
                                    You can leave this page; the install keeps running.
                                </span>
                            )}
                        </div>
                    </div>
                )}

                <div className='mb-3 flex gap-1'>
                    {(
                        [
                            ['modrinth', 'Modrinth'],
                            ['curseforge', 'CurseForge'],
                        ] as const
                    ).map(([key, label]) => (
                        <button
                            key={key}
                            type='button'
                            aria-pressed={source === key}
                            onClick={() => setSource(key)}
                            className={`rounded-xl border px-4 py-2 text-sm font-medium transition ${
                                source === key
                                    ? 'border-cream-400 bg-cream-400 text-mocha-500'
                                    : 'border-mocha-300 hover:bg-mocha-400'
                            }`}
                        >
                            {label}
                        </button>
                    ))}
                </div>

                <div className='mb-4 flex flex-col gap-3 sm:flex-row sm:items-center'>
                    <label
                        htmlFor='modpack-search'
                        className='flex flex-1 items-center gap-2 rounded-xl border border-mocha-300 bg-mocha-500 px-3'
                    >
                        <Magnifier width={16} height={16} className='text-mocha-50' aria-hidden />
                        <Input.Text
                            id='modpack-search'
                            type='search'
                            placeholder='Search modpacks…'
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            className='border-0! bg-transparent! flex-1'
                        />
                    </label>
                    <div className='flex flex-wrap gap-1'>
                        {LOADERS.map((option) => (
                            <button
                                key={option.label}
                                type='button'
                                aria-pressed={loader === option.key}
                                onClick={() => setLoader(option.key)}
                                className={`rounded-full border px-3 py-1.5 text-sm transition ${
                                    loader === option.key
                                        ? 'border-cream-400 bg-cream-400 text-mocha-500'
                                        : 'border-mocha-300 hover:bg-mocha-400'
                                }`}
                            >
                                {option.label}
                            </button>
                        ))}
                    </div>
                </div>

                {results.error ? (
                    <p className='text-sm text-red-400'>{httpErrorToHuman(results.error)}</p>
                ) : results.isLoading && !hits.length ? (
                    <div className='flex items-center gap-2 py-10 text-mocha-50'>
                        <Spin /> Searching {source === 'curseforge' ? 'CurseForge' : 'Modrinth'}…
                    </div>
                ) : !hits.length ? (
                    <p className='py-10 text-center text-mocha-50'>No server-compatible modpacks found.</p>
                ) : (
                    <ul className='grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3'>
                        {hits.map((pack) => (
                            <li
                                key={pack.id}
                                className='flex flex-col gap-3 rounded-xl border border-mocha-300 bg-mocha-500 p-4'
                            >
                                <div className='flex items-start gap-3'>
                                    {pack.icon ? (
                                        <img
                                            src={pack.icon}
                                            alt=''
                                            width={48}
                                            height={48}
                                            loading='lazy'
                                            referrerPolicy='no-referrer'
                                            className='h-12 w-12 shrink-0 rounded-lg object-cover'
                                        />
                                    ) : (
                                        <div className='h-12 w-12 shrink-0 rounded-lg bg-mocha-300' />
                                    )}
                                    <div className='min-w-0'>
                                        <strong className='block truncate'>{pack.title}</strong>
                                        <span className='text-xs text-mocha-50'>
                                            by {pack.author} · {compact.format(pack.downloads)} downloads
                                        </span>
                                    </div>
                                </div>
                                <p className='line-clamp-2 text-sm text-mocha-50'>{pack.description}</p>
                                <div className='mt-auto flex items-center justify-between gap-2'>
                                    <span className='truncate text-xs text-mocha-50'>
                                        {pack.loaders.map((key) => LOADER_LABEL[key] ?? key).join(', ')}
                                        {pack.game_versions.length ? ` · ${pack.game_versions.at(-1)}` : ''}
                                    </span>
                                    <Button
                                        size='sm'
                                        variant='secondary'
                                        disabled={active}
                                        onClick={() => setSelected(pack)}
                                    >
                                        Install
                                    </Button>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}

                {hits.length > 0 && hits.length < (results.data?.total ?? 0) && (
                    <div className='mt-4 flex justify-center'>
                        <Button
                            variant='secondary'
                            disabled={results.isValidating}
                            onClick={() => setPages((value) => value + 1)}
                        >
                            {results.isValidating ? 'Loading…' : 'Load more'}
                        </Button>
                    </div>
                )}

                <p className='mt-6 text-xs text-mocha-50'>
                    Modpacks from Modrinth and CurseForge. Client-only content is skipped automatically, and CurseForge
                    packs use the author's server pack when one exists. Some CurseForge mods block third-party
                    downloads; those are listed after install so you can add them by hand.
                </p>

                {selected && (
                    <InstallDialog
                        key={selected.id}
                        uuid={uuid}
                        source={source}
                        pack={selected}
                        onClose={() => setSelected(null)}
                        onStarted={() => {
                            setSelected(null);
                            void status.mutate();
                        }}
                    />
                )}
            </div>
        </ServerContentBlock>
    );
};

export default ModpacksContainer;
