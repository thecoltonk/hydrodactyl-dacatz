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
        <div className='fixed inset-0 z-[1] flex overflow-hidden [--page-padding:--spacing(8)]'>
            {art && (
                <div aria-hidden className='pointer-events-none absolute inset-0'>
                    <img src={BACKGROUNDS.login} alt='' className='size-full object-cover' />
                    <div className='absolute inset-0 bg-gradient-to-r from-black/50 via-black/10 to-transparent' />
                </div>
            )}
            <div
                style={{
                    backgroundImage: 'url(/assets/auth-noise.png)',
                    backgroundSize: '1920px 1080px',
                    backgroundRepeat: 'repeat',
                    backgroundPosition: '0 0',
                }}
                className={`pointer-events-none absolute inset-0 ${art ? 'opacity-[0.12]' : 'opacity-[0.4]'}`}
            ></div>

            <div
                className={`relative z-[2] flex min-h-dvh w-full flex-col overflow-y-auto px-6 py-8 sm:px-12 lg:w-[34rem] lg:shrink-0 ${
                    art ? 'border-r border-white/5 bg-[#0c0a09cc] backdrop-blur-xl' : 'bg-bg-lowered'
                }`}
            >
                <header className='flex items-center gap-3'>
                    <div className='h-10 w-10 shrink-0'>
                        <Logo className='h-full w-full flex' />
                    </div>
                    <div className='leading-tight'>
                        <p className='font-semibold tracking-tight'>{BRAND_NAME}</p>
                        <p className='text-xs text-brand-300'>{BRAND_TAGLINE}</p>
                    </div>
                </header>

                <main className='flex flex-1 items-center py-10'>
                    <div className='w-full'>
                        <Routes>
                            <Route path='login' element={<LoginContainer />} />
                            <Route path='login/checkpoint/*' element={<LoginCheckpointContainer />} />
                            <Route path='password' element={<ForgotPasswordContainer />} />
                            <Route path='password/reset/:token' element={<ResetPasswordContainer />} />
                            <Route path='*' element={<NotFound />} />
                        </Routes>
                    </div>
                </main>

                <footer className='text-xs text-cream-400/40'>
                    Start, stop and manage your Minecraft servers. Modpacks, mods and plugins in a click.
                </footer>
            </div>

            <div className='relative z-[2] hidden flex-1 lg:block'>
                {!art && (
                    <div className='opacity-50'>
                        <div className='absolute inset-0 bg-gradient-to-tr from-transparent via-brand-400/5 to-brand-600/10' />
                        <div className='absolute inset-0 bg-gradient-to-tr to-transparent via-brand-400/5 from-brand-600/10' />
                        <div className='absolute top-0 left-0 w-full h-32 bg-gradient-to-b from-brand-500/13 to-transparent' />
                    </div>
                )}
            </div>
        </div>
    );
};

export default AuthenticationRouter;
