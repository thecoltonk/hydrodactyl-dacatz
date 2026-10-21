import { useState } from 'react';

import { cores, gb, usePlan } from '@/api/account/plan';

import CreatePlanServerDialog from './CreatePlanServerDialog';

const Meter = ({
    label,
    used,
    total,
    format,
}: {
    label: string;
    used: number;
    total: number;
    format: (v: number) => string;
}) => {
    const percent = total > 0 ? Math.min(100, (used / total) * 100) : 0;
    return (
        <div className='flex min-w-[9rem] flex-1 flex-col gap-1'>
            <div className='flex items-baseline justify-between gap-2 text-xs'>
                <span className='text-cream-400/60'>{label}</span>
                <span className='tabular-nums text-cream-400'>
                    {format(used)} / {format(total)}
                </span>
            </div>
            <div className='h-1.5 overflow-hidden rounded-full bg-mocha-300/50'>
                <div
                    className={`h-full rounded-full ${percent >= 90 ? 'bg-red-400' : 'bg-brand-400'}`}
                    style={{ width: `${percent}%` }}
                />
            </div>
        </div>
    );
};

/**
 * Plan usage + "New server" for users the admin gave a server plan.
 * Renders nothing for accounts without a plan.
 */
export default function PlanBar() {
    const { data } = usePlan();
    const [creating, setCreating] = useState(false);
    if (!data?.plan) return null;
    const { plan, used } = data;

    return (
        <div className='mb-4 flex flex-col gap-4 rounded-2xl border border-mocha-400 bg-bg-raised p-4 sm:flex-row sm:items-center'>
            <div className='flex flex-1 flex-wrap gap-4'>
                <Meter label='RAM' used={used.memory} total={plan.memory} format={(v) => `${gb(v)} GB`} />
                <Meter label='CPU' used={used.cpu} total={plan.cpu} format={(v) => `${cores(v)}`} />
                <Meter label='Storage' used={used.disk} total={plan.disk} format={(v) => `${gb(v)} GB`} />
                {plan.servers > 0 && (
                    <Meter label='Servers' used={used.servers} total={plan.servers} format={(v) => String(v)} />
                )}
            </div>
            <button
                type='button'
                onClick={() => setCreating(true)}
                className='inline-flex shrink-0 items-center justify-center gap-1.5 rounded-xl border border-brand-400/40 bg-brand-400/80 px-4 py-2.5 text-sm font-semibold text-mocha-500 transition hover:bg-brand-400'
            >
                <span aria-hidden className='text-base leading-none'>
                    +
                </span>
                New server
            </button>
            {creating && <CreatePlanServerDialog info={data} onClose={() => setCreating(false)} />}
        </div>
    );
}
