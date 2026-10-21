import { useEffect, useId, useState } from 'react';

/**
 * Slider + number box for RAM / CPU / storage. Both stay in sync, the slider
 * updates live while dragging, and typed values snap to the step and range.
 */
export default function ResourceSlider({
    label,
    unit,
    value,
    onChange,
    min,
    max,
    step,
    hint,
}: {
    label: string;
    unit: string;
    value: number;
    onChange: (value: number) => void;
    min: number;
    max: number;
    step: number;
    hint?: string;
}) {
    const id = useId();
    const disabled = max < min;
    const clamp = (next: number) => {
        const snapped = Math.round(next / step) * step;
        return Math.min(Math.max(Number(snapped.toFixed(2)), min), Math.max(min, max));
    };
    const [text, setText] = useState(String(value));

    // Keep the box in step with the slider (and with max changes).
    useEffect(() => {
        setText(String(value));
    }, [value]);

    useEffect(() => {
        if (!disabled && value > max) onChange(clamp(max));
        // biome-ignore lint/correctness/useExhaustiveDependencies: only react to a shrinking max
    }, [max]);

    const percent = disabled ? 0 : ((Math.min(value, max) - min) / Math.max(max - min, step)) * 100;

    return (
        <div className='flex flex-col gap-2'>
            <div className='flex items-center justify-between gap-3'>
                <label htmlFor={id} className='text-sm text-cream-400/70'>
                    {label}
                </label>
                <div className='flex items-center gap-1.5'>
                    <input
                        aria-label={`${label} (${unit})`}
                        type='number'
                        inputMode='decimal'
                        min={min}
                        max={Math.max(min, max)}
                        step={step}
                        disabled={disabled}
                        value={text}
                        onChange={(event) => {
                            setText(event.target.value);
                            const next = Number(event.target.value);
                            if (event.target.value !== '' && Number.isFinite(next) && next >= min && next <= max) {
                                onChange(clamp(next));
                            }
                        }}
                        onBlur={() => {
                            const next = Number(text);
                            onChange(Number.isFinite(next) ? clamp(next) : value);
                            setText(String(Number.isFinite(next) ? clamp(next) : value));
                        }}
                        className='w-20 rounded-lg border border-mocha-300/50 bg-mocha-400/50 px-2 py-1 text-right text-sm tabular-nums text-cream-400 focus:border-brand-400/60 focus:outline-none disabled:opacity-40'
                    />
                    <span className='w-10 text-xs text-cream-400/50'>{unit}</span>
                </div>
            </div>
            <input
                id={id}
                type='range'
                min={min}
                max={Math.max(min, max)}
                step={step}
                value={disabled ? min : Math.min(Math.max(value, min), max)}
                disabled={disabled}
                onInput={(event) => onChange(clamp(Number((event.target as HTMLInputElement).value)))}
                onChange={(event) => onChange(clamp(Number(event.target.value)))}
                style={{
                    background: `linear-gradient(to right, var(--color-brand-400) ${percent}%, rgb(255 255 255 / 0.12) ${percent}%)`,
                }}
                className='h-2 w-full cursor-pointer appearance-none rounded-full accent-brand-400 touch-none disabled:cursor-not-allowed disabled:opacity-40 [&::-moz-range-thumb]:size-5 [&::-moz-range-thumb]:rounded-full [&::-moz-range-thumb]:border-0 [&::-moz-range-thumb]:bg-cream-400 [&::-webkit-slider-thumb]:size-5 [&::-webkit-slider-thumb]:appearance-none [&::-webkit-slider-thumb]:rounded-full [&::-webkit-slider-thumb]:bg-cream-400 [&::-webkit-slider-thumb]:shadow'
            />
            <div className='flex justify-between text-[11px] text-cream-400/40'>
                <span>
                    {min} {unit}
                </span>
                <span>{hint ?? `${Math.max(min, max)} ${unit} max`}</span>
            </div>
        </div>
    );
}
