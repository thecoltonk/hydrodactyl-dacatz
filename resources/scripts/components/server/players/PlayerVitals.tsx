// Ported from Aquadactyl/MCPlayersJava (BSD 2-Clause, Copyright (c) Aquadactyl contributors).
import type { InventorySnapshot } from '@/api/server/players';
import foodEmpty from './hud/food-empty.png';
import foodFull from './hud/food-full.png';
import foodHalf from './hud/food-half.png';
import heartContainer from './hud/heart-container.png';
import heartFull from './hud/heart-full.png';
import heartHalf from './hud/heart-half.png';
import xpBackground from './hud/xp-background.png';
import xpProgress from './hud/xp-progress.png';

const nonnegative = (value: number | null | undefined) =>
    typeof value === 'number' && Number.isFinite(value) && value >= 0 ? value : null;

function IconMeter({ kind, value }: { kind: 'health' | 'food'; value: number | null }) {
    const health = kind === 'health';
    const label = health ? 'Health' : 'Food';
    const points = Math.ceil(value ?? 0);
    const count = health ? Math.min(50, Math.ceil(Math.max(20, points) / 2)) : 10;
    const text =
        value === null
            ? 'Unknown'
            : health
              ? `${value.toLocaleString(undefined, { maximumFractionDigits: 2 })} HP`
              : `${value} / 20`;

    return (
        <div className={`mcp-hud-meter ${value === null ? 'mcp-hud-unknown' : ''}`}>
            <div className='mcp-hud-label'>
                <span>{label}</span>
                <strong>{text}</strong>
            </div>
            <div
                className='mcp-hud-icons'
                role={value === null ? 'group' : 'meter'}
                aria-label={label}
                aria-valuemin={value === null ? undefined : 0}
                aria-valuemax={value === null ? undefined : health ? Math.max(20, value) : 20}
                aria-valuenow={value ?? undefined}
                aria-valuetext={text}
            >
                {Array.from({ length: count }, (_, index) => {
                    const slot = health ? index : 9 - index;
                    const state = points >= slot * 2 + 2 ? 'full' : points > slot * 2 ? 'half' : 'empty';
                    return (
                        <span key={index} className='mcp-hud-icon' data-fill={state} aria-hidden='true'>
                            <img
                                src={health ? heartContainer : foodEmpty}
                                alt=''
                                width={9}
                                height={9}
                                draggable={false}
                            />
                            {state !== 'empty' && (
                                <img
                                    src={
                                        health
                                            ? state === 'full'
                                                ? heartFull
                                                : heartHalf
                                            : state === 'full'
                                              ? foodFull
                                              : foodHalf
                                    }
                                    alt=''
                                    width={9}
                                    height={9}
                                    draggable={false}
                                />
                            )}
                        </span>
                    );
                })}
            </div>
        </div>
    );
}

export default function PlayerVitals({
    data,
}: {
    data: Pick<InventorySnapshot, 'health' | 'food' | 'xp_level' | 'xp_progress'>;
}) {
    const health = nonnegative(data.health);
    const savedFood = nonnegative(data.food);
    const food = savedFood !== null && savedFood <= 20 ? savedFood : null;
    const savedProgress = nonnegative(data.xp_progress);
    const progress = savedProgress !== null && savedProgress <= 1 ? savedProgress : null;
    const savedLevel = nonnegative(data.xp_level);
    const level = savedLevel !== null && Number.isInteger(savedLevel) ? savedLevel : null;
    const percent = progress === null ? null : Math.round(progress * 1000) / 10;
    const progressLabel = percent === null ? 'Progress unavailable' : `${percent}% to next level`;

    return (
        <section className='mcp-player-hud' aria-label='Saved player vitals'>
            <div className='mcp-hud-status'>
                <IconMeter kind='health' value={health} />
                <IconMeter kind='food' value={food} />
            </div>
            <div className='mcp-xp-heading'>
                <span>Experience</span>
                <strong className='mcp-xp-level'>
                    <small>Level</small>
                    {level ?? '—'}
                </strong>
                <span>{progressLabel}</span>
            </div>
            <div
                className={`mcp-xp-track ${progress === null ? 'mcp-hud-unknown' : ''}`}
                role='progressbar'
                aria-label='Experience'
                aria-valuemin={0}
                aria-valuemax={100}
                aria-valuenow={percent ?? undefined}
                aria-valuetext={`${level === null ? 'Level unknown' : `Level ${level}`}, ${progressLabel.toLowerCase()}`}
            >
                <img src={xpBackground} alt='' aria-hidden='true' draggable={false} />
                {percent !== null && (
                    <img
                        className='mcp-xp-fill'
                        src={xpProgress}
                        alt=''
                        aria-hidden='true'
                        draggable={false}
                        style={{ clipPath: `inset(0 ${100 - percent}% 0 0)` }}
                    />
                )}
            </div>
        </section>
    );
}
