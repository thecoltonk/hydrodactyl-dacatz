import { useId } from 'react';

/** Range slider with a live value, used for RAM / CPU / storage picks. */
export default function ResourceSlider({
    label,
    value,
    onChange,
    min,
    max,
    step,
    format,
    hint,
}: {
    label: string;
    value: number;
    onChange: (value: number) => void;
    min: number;
    max: number;
    step: number;
    format: (value: number) => string;
    hint?: string;
}) {
    const id = useId();
    const disabled = max < min;

    return (
        <div className='flex flex-col gap-1.5'>
            <div className='flex items-baseline justify-between text-sm'>
                <label htmlFor={id} className='text-cream-400/70'>
                    {label}
                </label>
                <strong className='tabular-nums text-cream-400'>{disabled ? '—' : format(value)}</strong>
            </div>
            <input
                id={id}
                type='range'
                min={min}
                max={Math.max(min, max)}
                step={step}
                value={disabled ? min : Math.min(Math.max(value, min), max)}
                disabled={disabled}
                onChange={(event) => onChange(Number(event.target.value))}
                className='w-full accent-brand-400 disabled:opacity-40'
            />
            <div className='flex justify-between text-[11px] text-cream-400/40'>
                <span>{format(min)}</span>
                <span>{hint ?? `${format(Math.max(min, max))} max`}</span>
            </div>
        </div>
    );
}
