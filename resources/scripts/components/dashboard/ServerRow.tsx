import { Pencil } from '@gravity-ui/icons';
import { useStoreState } from 'easy-peasy';
import { Fragment, useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import styled from 'styled-components';
import { usePlan } from '@/api/account/plan';
import type { Server } from '@/api/server/getServer';
import getServerResourceUsage, { type ServerPowerState, type ServerStats } from '@/api/server/getServerResourceUsage';
import { bytesToString, ip } from '@/lib/formatters';

import QuickBuildDialog from './QuickBuildDialog';

// Determines if the current value is in an alarm threshold so we can show it in red rather
// than the more faded default style.
const isAlarmState = (current: number, limit: number): boolean => limit > 0 && current / (limit * 1024 * 1024) >= 0.9;

const StatusIndicatorBox = styled.div<{ $status: ServerPowerState }>`
transition: all 250ms ease-in-out;
padding: 1.75rem 2rem;
cursor: pointer;
border-radius: 0.75rem;
display: flex;
align-items: center;
justify-content: space-between;
position: relative;

    @media (max-width: 639px) {
        padding: 1rem 1.25rem;
    }

    &:hover {
    border: 1px solid #ffffff11;
}

    & .status-bar {
    width: 12px;
    height: 12px;
    min-width: 12px;
    min-height: 12px;
    background-color: #ffffff11;
    z-index: 20;
    border-radius: 9999px;
    transition: all 250ms ease-in-out;

    box-shadow: ${({ $status }) => {
        if (!$status || $status === 'offline') {
            return '0 0 12px 1px #C74343';
        } else if ($status === 'running') {
            return '0 0 12px 1px #43C760';
        } else if ($status === 'installing') {
            return '0 0 12px 1px #4381c7';
        } else {
            return '0 0 12px 1px #c7aa43';
        }
    }};

    background: ${({ $status }) => {
        if (!$status || $status === 'offline') {
            return 'linear-gradient(180deg, #C74343 0%, #C74343 100%)';
        } else if ($status === 'running') {
            return 'linear-gradient(180deg, #91FFA9 0%, #43C760 100%)';
        } else if ($status === 'installing') {
            return 'linear-gradient(180deg, #91c7ff 0%, #4381c7 100%)';
        } else {
            return 'linear-gradient(180deg, #c7aa43 0%, #c7aa43 100%)';
        }
    }};
    }
`;

const ServerRow = ({ server, className, hideGroup }: { server: Server; className?: string; hideGroup?: boolean }) => {
    const [isSuspended, setIsSuspended] = useState(server.status === 'suspended');
    const [isInstalling, setIsInstalling] = useState(server.status === 'installing');
    const [stats, setStats] = useState<ServerStats | null>(null);
    const rootAdmin = useStoreState((state) => state.user.data?.rootAdmin) ?? false;
    const [editing, setEditing] = useState(false);
    const [limits, setLimits] = useState({
        memory: server.limits.memory,
        cpu: server.limits.cpu,
        disk: server.limits.disk,
    });
    const [deleted, setDeleted] = useState(false);
    const { data: planInfo } = usePlan();
    // Admins edit anything; plan users edit servers they own, within their plan.
    const planOwner = Boolean(planInfo?.plan) && Boolean(server.isOwner);
    const canEdit = rootAdmin || planOwner;

    // Memoized so its identity is stable across renders. Using an inline function
    // here as a useEffect dependency makes the polling effect re-fire every render
    // -> setStats -> re-render -> re-fire, i.e. a setState-in-useEffect infinite
    // loop ("Maximum update depth exceeded").
    const getStats = useCallback(() => {
        getServerResourceUsage(server.uuid)
            .then((data) => setStats(data))
            .catch((error) => console.error(error));
    }, [server.uuid]);

    useEffect(() => {
        setIsSuspended(stats?.isSuspended || server.status === 'suspended');
    }, [stats?.isSuspended, server.status]);

    useEffect(() => {
        setIsInstalling(stats?.isInstalling || server.status === 'installing');
    }, [stats?.isInstalling, server.status]);

    useEffect(() => {
        // Don't waste a HTTP request if there is nothing important to show to the user because
        // the server is suspended.
        if (isSuspended) return;

        getStats();
        const interval = setInterval(getStats, 30000);

        return () => clearInterval(interval);
    }, [isSuspended, getStats]);

    const alarms = { cpu: false, memory: false, disk: false };
    if (stats) {
        alarms.cpu = limits.cpu === 0 ? false : stats.cpuUsagePercent >= limits.cpu * 0.9;
        alarms.memory = isAlarmState(stats.memoryUsageInBytes, limits.memory);
        alarms.disk = limits.disk === 0 ? false : isAlarmState(stats.diskUsageInBytes, limits.disk);
    }

    if (deleted) return null;

    return (
        <div className='relative'>
            <StatusIndicatorBox
                as={Link}
                to={`/server/${server.id}`}
                className={`${className} bg-mocha-500 hover:bg-mocha-400 border border-[1px] border-mocha-400 hover:border-mocha-400`}
                $status={stats?.status || 'offline'}
            >
                <div className={`flex items-center min-w-0`}>
                    <div className='flex flex-col min-w-0'>
                        <div className='flex items-center gap-2 min-w-0'>
                            <p
                                className={`text-xl tracking-tight font-bold truncate min-w-0 max-w-full sm:max-w-[20vw]`}
                            >
                                {server.name}
                            </p>{' '}
                            <div className={'status-bar'} />
                        </div>
                        <div className='flex items-center gap-2 min-w-0'>
                            <p className={`text-sm text-[#ffffff66] truncate`}>
                                {server.allocations
                                    .filter((alloc) => alloc.isDefault)
                                    .map((allocation) => (
                                        <Fragment key={allocation.ip + allocation.port.toString()}>
                                            {allocation.alias || ip(allocation.ip)}:{allocation.port}
                                        </Fragment>
                                    ))}
                            </p>
                            {server.group && !hideGroup && (
                                <span className='inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-semibold bg-accent/10 text-accent border border-accent/20'>
                                    {server.group.name}
                                </span>
                            )}
                        </div>
                    </div>
                </div>
                <div
                    className={`h-full hidden sm:flex items-center justify-center bg-mocha-500 border-[1px] border-[#ffffff11] shadow-xs rounded-md w-fit whitespace-nowrap px-4 py-2 text-sm gap-4`}
                >
                    {!stats || isSuspended || isInstalling ? (
                        isSuspended ? (
                            <div className={`flex-1 text-center`}>
                                <span className={`text-red-100 text-xs`}>
                                    {server.status === 'suspended' ? 'Suspended' : 'Connection Error'}
                                </span>
                            </div>
                        ) : server.isTransferring || server.status ? (
                            <div className={`flex-1 text-center`}>
                                <span className={`text-zinc-100 text-xs`}>
                                    {server.isTransferring
                                        ? 'Transferring'
                                        : server.status === 'installing'
                                          ? 'Installing'
                                          : server.status === 'restoring_backup'
                                            ? 'Restoring Backup'
                                            : 'Unavailable'}
                                </span>
                            </div>
                        ) : (
                            <div className='text-xs opacity-25'>Sit tight!</div>
                        )
                    ) : (
                        <Fragment>
                            <div className={`sm:flex hidden`}>
                                <div className={`flex justify-center gap-2 w-fit`}>
                                    <p className='text-sm text-[#ffffff66] font-bold w-fit whitespace-nowrap'>CPU:</p>
                                    <p className='font-bold w-fit whitespace-nowrap'>
                                        {stats.cpuUsagePercent.toFixed(2)}%
                                    </p>
                                </div>
                            </div>
                            <div className={`sm:flex hidden`}>
                                <div className={`flex justify-center gap-2 w-fit`}>
                                    <p className='text-sm text-[#ffffff66] font-bold w-fit whitespace-nowrap'>RAM:</p>
                                    <p className='font-bold w-fit whitespace-nowrap'>
                                        {bytesToString(stats.memoryUsageInBytes, 0)}
                                    </p>
                                </div>
                            </div>
                            <div className={`sm:flex hidden`}>
                                <div className={`flex justify-center gap-2 w-fit`}>
                                    <p className='text-sm text-[#ffffff66] font-bold w-fit whitespace-nowrap'>
                                        Storage:
                                    </p>
                                    <p className='font-bold w-fit whitespace-nowrap'>
                                        {bytesToString(stats.diskUsageInBytes, 0)}
                                    </p>
                                </div>
                            </div>
                        </Fragment>
                    )}
                </div>
            </StatusIndicatorBox>
            {canEdit && (
                <>
                    <button
                        type='button'
                        onClick={() => setEditing(true)}
                        aria-label={`Edit ${server.name} resources`}
                        title='Edit CPU, RAM and storage'
                        className='absolute top-2 right-2 z-30 rounded-lg border border-[#ffffff11] bg-mocha-500/90 p-1.5 text-cream-400/60 transition hover:bg-mocha-400 hover:text-cream-400'
                    >
                        <Pencil width={14} height={14} fill='currentColor' />
                    </button>
                    {editing && (
                        <QuickBuildDialog
                            onClose={() => setEditing(false)}
                            uuid={server.uuid}
                            name={server.name}
                            limits={limits}
                            plan={rootAdmin ? null : (planInfo ?? null)}
                            onSaved={setLimits}
                            onDeleted={() => setDeleted(true)}
                        />
                    )}
                </>
            )}
        </div>
    );
};

export default ServerRow;
