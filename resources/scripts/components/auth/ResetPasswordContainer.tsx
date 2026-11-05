import { Formik, type FormikHelpers } from 'formik';
import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { object, ref, string } from 'yup';
import performPasswordReset from '@/api/auth/performPasswordReset';
import LoginFormContainer, { TitleSection } from '@/components/auth/LoginFormContainer';
import Button from '@/components/elements/Button';
import Captcha, { getCaptchaResponse } from '@/components/elements/Captcha';
import Field from '@/components/elements/Field';
import Input from '@/components/elements/Input';
import { BRAND_NAME } from '@/lib/branding';
import CaptchaManager from '@/lib/captcha';

import useFlash from '@/plugins/useFlash';

interface Values {
    password: string;
    password_confirmation: string;
}

function ResetPasswordContainer() {
    const [email, setEmail] = useState('');

    const { clearFlashes, clearAndAddHttpError } = useFlash();

    useEffect(() => {
        clearFlashes();
    }, [clearFlashes]);

    const parsed = new URLSearchParams(location.search);
    if (email.length === 0 && parsed.get('email')) {
        setEmail(parsed.get('email') || '');
    }

    const params = useParams<'token'>();

    const submit = ({ password, password_confirmation }: Values, { setSubmitting }: FormikHelpers<Values>) => {
        clearFlashes();

        // Get captcha response if enabled
        const captchaResponse = getCaptchaResponse();

        let resetData: Record<string, unknown> = {
            token: params.token ?? '',
            password,
            password_confirmation,
        };
        if (CaptchaManager.isEnabled()) {
            const fieldName = CaptchaManager.getProviderInstance().getResponseFieldName();

            if (fieldName) {
                if (captchaResponse) {
                    resetData = {
                        ...resetData,
                        [fieldName]: captchaResponse,
                    };
                } else {
                    console.error('Captcha enabled but no response available');
                    clearAndAddHttpError({
                        error: new Error('Please complete the captcha verification.'),
                    });
                    setSubmitting(false);
                    return;
                }
            }
        }

        performPasswordReset(email, resetData)
            .then(() => {
                // @ts-expect-error this is valid
                window.location = '/';
            })
            .catch((error) => {
                console.error(error);

                setSubmitting(false);
                clearAndAddHttpError({
                    error: new Error(error),
                });
            });
    };

    return (
        <Formik
            onSubmit={submit}
            initialValues={{
                password: '',
                password_confirmation: '',
            }}
            validationSchema={object().shape({
                password: string()
                    .required('A new password is required.')
                    .min(8, 'Your new password should be at least 8 characters in length.'),
                password_confirmation: string()
                    .required('Your new password does not match.')
                    .oneOf([ref('password')], 'Your new password does not match.'),
            })}
        >
            {({ isSubmitting }) => (
                <LoginFormContainer className='mx-auto flex w-full max-w-md flex-col gap-6 rounded-2xl border border-cream-500/10 bg-bg-raised p-8 shadow-xl shadow-black/40'>
                    <TitleSection
                        title='Set your password'
                        subtitle={`Choose a password for your ${BRAND_NAME} account.`}
                    />
                    <div>
                        <label htmlFor='reset-email' className='mb-1 block text-sm text-secondary'>
                            Account
                        </label>
                        <Input id='reset-email' value={email} disabled />
                    </div>
                    <Field
                        id='password'
                        label={'New password'}
                        name={'password'}
                        type={'password'}
                        description={'At least 8 characters.'}
                        disabled={isSubmitting}
                    />
                    <Field
                        id='password_confirmation'
                        label={'Confirm new password'}
                        name={'password_confirmation'}
                        type={'password'}
                        disabled={isSubmitting}
                    />
                    <Captcha
                        onError={(error) => {
                            console.error('Captcha error:', error);
                            clearAndAddHttpError({
                                error: new Error('Captcha verification failed. Please try again.'),
                            });
                        }}
                    />
                    <div className='flex w-full flex-col gap-4 sm:flex-row sm:justify-between sm:items-center'>
                        <Button
                            className='bg-mocha-100 rounded-lg p-2 px-4 text-black hover:cursor-pointer hover:bg-mocha-200 ease-in-out w-full sm:w-auto'
                            size={'xlarge'}
                            type={'submit'}
                            disabled={isSubmitting}
                            isLoading={isSubmitting}
                        >
                            Save password
                        </Button>
                        <Link
                            to='/auth/login'
                            className='text-center text-xs text-secondary hover:underline sm:text-right'
                        >
                            Back to sign in
                        </Link>
                    </div>
                </LoginFormContainer>
            )}
        </Formik>
    );
}

export default ResetPasswordContainer;
