import { Route, Routes } from 'react-router-dom';

import ForgotPasswordContainer from '@/components/auth/ForgotPasswordContainer';
import LoginCheckpointContainer from '@/components/auth/LoginCheckpointContainer';
import LoginContainer from '@/components/auth/LoginContainer';
import ResetPasswordContainer from '@/components/auth/ResetPasswordContainer';
import Logo from '@/components/elements/HydroLogo';
import { NotFound } from '@/components/elements/ScreenBlock';
import { BACKGROUNDS, BRAND_NAME, BRAND_TAGLINE, useImageAvailable } from '@/lib/branding';

const AuthenticationRouter = () => {
    const art = useImageAvailable(BACKGROUNDS.login);

    return (
        <div
            className={
                'absolute w-full h-full flex justify-center items-center rounded-md [--page-padding:--spacing(8)]'
            }
        >
            {art && (
                <div aria-hidden className='pointer-events-none fixed inset-0 z-0'>
                    <img src={BACKGROUNDS.login} alt='' className='size-full object-cover' />
                    <div className='absolute inset-0 bg-gradient-to-r from-black/80 via-black/45 to-black/20' />
                    <div className='absolute inset-x-0 bottom-0 h-1/2 bg-gradient-to-t from-black/70 to-transparent' />
                </div>
            )}
            <div
                style={{
                    backgroundImage: 'url(/assets/auth-noise.png)',
                    backgroundSize: '1920px 1080px',
                    backgroundRepeat: 'repeat',
                    backgroundPosition: '0 0',
                }}
                className={`pointer-events-none fixed inset-0 z-1 ${art ? 'opacity-[0.15]' : 'opacity-[0.4]'}`}
            ></div>
            <div className='flex size-full flex-col lg:flex-row'>
                <div
                    className={`w-full max-w-4xl z-2 flex flex-col justify-start sm:justify-center gap-8 min-h-dvh lg:min-h-0 px-6 py-8 sm:px-[calc(var(--page-padding)*3)] overflow-y-auto ${art ? 'bg-[#0c0a09cc] backdrop-blur-xl border-r border-white/5' : 'bg-bg-lowered'}`}
                >
                    <div className='mx-auto flex w-full max-w-md items-center gap-3 lg:hidden'>
                        <div className='h-9 w-9 shrink-0'>
                            <Logo className='h-full w-full flex' />
                        </div>
                        <div className='leading-tight'>
                            <p className='font-semibold'>{BRAND_NAME}</p>
                            <p className='text-xs text-secondary'>{BRAND_TAGLINE}</p>
                        </div>
                    </div>
                    <Routes>
                        <Route path='login' element={<LoginContainer />} />
                        <Route path='login/checkpoint/*' element={<LoginCheckpointContainer />} />
                        <Route path='password' element={<ForgotPasswordContainer />} />
                        <Route path='password/reset/:token' element={<ResetPasswordContainer />} />
                        <Route path='*' element={<NotFound />} />
                    </Routes>
                </div>
                <div className='hidden lg:flex w-full relative z-2 flex-col justify-end p-(--page-padding)'>
                    {!art && (
                        <div className='opacity-50'>
                            <div className='absolute inset-0 bg-gradient-to-tr from-transparent via-brand-400/5 to-brand-600/10' />
                            <div className='absolute inset-0 bg-gradient-to-tr to-transparent via-brand-400/5 from-brand-600/10' />
                            <div className='absolute top-0 left-0 w-full h-32 bg-gradient-to-b from-brand-500/13 to-transparent' />
                        </div>
                    )}
                    <div className='relative max-w-xl rounded-2xl border border-white/10 bg-black/40 p-6 backdrop-blur-md'>
                        <div className='mb-4 flex items-center gap-3'>
                            <div className='h-12 w-12 shrink-0'>
                                <Logo className='h-full w-full flex' />
                            </div>
                            <div className='leading-tight'>
                                <p className='text-2xl font-bold tracking-tight'>{BRAND_NAME}</p>
                                <p className='text-sm text-brand-300'>{BRAND_TAGLINE}</p>
                            </div>
                        </div>
                        <p className='text-sm text-cream-400/80'>
                            Start, stop and manage your Minecraft servers. Install modpacks, mods and plugins in a
                            click, and keep an eye on your players.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    );
};

export default AuthenticationRouter;
