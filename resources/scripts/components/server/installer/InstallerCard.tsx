import { ArrowDownToLine, Bookmark, BookmarkFill, Check, Plus, ThunderboltFill } from '@gravity-ui/icons';

import { useState } from 'react';

import type { MarketplaceProject } from '@/api/server/marketplace';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';

import type { InstalledEntry } from './installedState';
import { SOURCE_LABELS } from './sources';

interface InstallerCardProps {
    project: MarketplaceProject;
    installedEntry?: InstalledEntry;
    onInstall: (project: MarketplaceProject) => void;
    installing?: boolean;
    /** The user's collections and whether this project is in each. */
    collections?: { id: number; name: string; has: boolean }[];
    onToggleCollection?: (collectionId: number, project: MarketplaceProject) => void;
    onNewCollection?: (project: MarketplaceProject) => void;
    /** Install the newest compatible version without opening the picker. */
    onQuickInstall?: (project: MarketplaceProject) => void;
    quickInstalling?: boolean;
}

const formatNumber = (n: number): string => {
    if (n >= 1_000_000) return `${(n / 1_000_000).toFixed(1)}M`;
    if (n >= 1_000) return `${(n / 1_000).toFixed(1)}K`;
    return String(n);
};

const InstallerCard = ({
    project,
    installedEntry,
    onInstall,
    installing,
    collections,
    onToggleCollection,
    onNewCollection,
    onQuickInstall,
    quickInstalling,
}: InstallerCardProps) => {
    const saved = collections?.some((collection) => collection.has) ?? false;
    const isInstalled = Boolean(installedEntry);
    // Some marketplace projects have no usable icon (Hangar projects without a
    // custom avatar 404 on the CDN; some Spiget resources have none at all).
    // Track load failures so we degrade to the letter avatar instead of showing
    // a broken-image glyph.
    const [iconFailed, setIconFailed] = useState(false);
    const iconSrc = project.icon && !iconFailed ? project.icon : null;

    return (
        <div
            className={cn(
                'group flex flex-col rounded-2xl border bg-mocha-400/60 p-5 transition',
                isInstalled ? 'border-brand-400/50' : 'border-mocha-300/60',
                'hover:border-brand-400/60 hover:bg-mocha-400/80 hover:shadow-lg hover:shadow-black/20',
            )}
        >
            <div className='flex items-start gap-4'>
                <div className='h-14 w-14 flex-shrink-0 overflow-hidden rounded-xl border border-mocha-300/60 bg-mocha-300/40'>
                    {iconSrc ? (
                        <img
                            src={iconSrc}
                            alt=''
                            className='h-full w-full object-cover'
                            loading='lazy'
                            // Marketplace icons are remote user-uploaded content.
                            referrerPolicy='no-referrer'
                            onError={() => setIconFailed(true)}
                        />
                    ) : (
                        <div className='flex h-full w-full items-center justify-center text-base font-semibold text-cream-400/50'>
                            {(project.title || '?').charAt(0).toUpperCase()}
                        </div>
                    )}
                </div>
                <div className='min-w-0 flex-1'>
                    <div className='flex flex-wrap items-center gap-2'>
                        <h3
                            className='truncate text-sm font-semibold'
                            title={project.url ? `${project.title} — open project page` : project.title}
                        >
                            {project.url ? (
                                <a
                                    href={project.url}
                                    target='_blank'
                                    rel='noopener noreferrer'
                                    className='text-cream-400 transition-colors hover:text-brand-400 hover:underline'
                                >
                                    {project.title}
                                </a>
                            ) : (
                                <span className='text-cream-400'>{project.title}</span>
                            )}
                        </h3>
                        <span className='rounded-md bg-mocha-300/60 px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-cream-400/70'>
                            {SOURCE_LABELS[project.source] ?? project.source}
                        </span>
                        {isInstalled && (
                            <span
                                title={installedEntry?.version_name}
                                className='flex items-center gap-1 rounded-md bg-brand-400/20 px-1.5 py-0.5 text-[10px] font-medium text-brand-400'
                            >
                                <Check width={11} height={11} fill='currentColor' />
                                Installed
                            </span>
                        )}
                    </div>
                    <p className='mt-0.5 truncate text-xs text-cream-400/60'>by {project.author || 'Unknown'}</p>
                </div>
                {onToggleCollection && collections && (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <button
                                type='button'
                                aria-label='Add to a collection'
                                title={
                                    saved
                                        ? `In ${collections
                                              .filter((c) => c.has)
                                              .map((c) => c.name)
                                              .join(', ')}`
                                        : 'Add to a collection'
                                }
                                className={cn(
                                    'flex-shrink-0 rounded-lg p-1.5 transition hover:bg-mocha-300/40',
                                    saved ? 'text-brand-400' : 'text-cream-400/40 hover:text-brand-400',
                                )}
                            >
                                {saved ? (
                                    <BookmarkFill width={18} height={18} fill='currentColor' />
                                ) : (
                                    <Bookmark width={18} height={18} fill='currentColor' />
                                )}
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align='end'>
                            <DropdownMenuLabel>Collections</DropdownMenuLabel>
                            {collections.map((collection) => (
                                <DropdownMenuCheckboxItem
                                    key={collection.id}
                                    checked={collection.has}
                                    onSelect={(event) => event.preventDefault()}
                                    onCheckedChange={() => onToggleCollection(collection.id, project)}
                                >
                                    {collection.name}
                                </DropdownMenuCheckboxItem>
                            ))}
                            {collections.length > 0 && <DropdownMenuSeparator />}
                            <DropdownMenuItem className='gap-2' onSelect={() => onNewCollection?.(project)}>
                                <Plus width={14} height={14} fill='currentColor' />
                                New collection…
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                )}
            </div>

            <p className='mt-3 line-clamp-2 min-h-[2.5rem] text-xs leading-relaxed text-cream-400/70'>
                {project.description || 'No description provided.'}
            </p>

            <div className='mt-3 flex flex-wrap gap-1.5'>
                {project.categories.slice(0, 3).map((category) => (
                    <span
                        key={category}
                        className='rounded-md bg-mocha-300/40 px-1.5 py-0.5 text-[10px] text-cream-400/60'
                    >
                        {category}
                    </span>
                ))}
            </div>

            <div className='mt-auto flex items-center justify-between pt-4'>
                <span className='text-[11px] text-cream-400/50'>{formatNumber(project.downloads)} downloads</span>
                <div className='flex items-center gap-1.5'>
                    {onQuickInstall && !isInstalled && (
                        <Button
                            size='sm'
                            variant='secondary'
                            disabled={quickInstalling || installing}
                            onClick={() => onQuickInstall(project)}
                            title='Install the newest version for this server'
                            className='gap-1.5'
                        >
                            <ThunderboltFill width={14} height={14} fill='currentColor' />
                            {quickInstalling ? 'Installing…' : 'Quick'}
                        </Button>
                    )}
                    <Button
                        size='sm'
                        variant={isInstalled ? 'attention' : 'secondary'}
                        disabled={installing}
                        onClick={() => onInstall(project)}
                        className='gap-1.5'
                    >
                        {isInstalled ? (
                            <Check width={16} height={16} fill='currentColor' />
                        ) : (
                            <ArrowDownToLine width={16} height={16} fill='currentColor' />
                        )}
                        {installing ? 'Installing…' : isInstalled ? 'Manage' : 'Install'}
                    </Button>
                </div>
            </div>
        </div>
    );
};

export default InstallerCard;
