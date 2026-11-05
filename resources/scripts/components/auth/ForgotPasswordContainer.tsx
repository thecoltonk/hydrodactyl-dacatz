import type { FormikHelpers } from 'formik';
import { Formik } from 'formik';
import { object, string } from 'yup';
import http, { httpErrorToHuman } from '@/api/http';
import LoginFormContainer, { TitleSection } from '@/components/auth/LoginFormContainer';
import Button from '@/components/elements/Button';
import Captcha, { getCaptchaResponse } from '@/components/elements/Captcha';
import Field from '@/components/elements/Field';
import CaptchaManager from '@/lib/captcha';
import useFlash from '@/plugins/useFlash';
import SecondaryLink from '../ui/secondary-link';

interface Values {
    email: string;
}

const ForgotPasswordContainer = () => {
    const { clearFlashes, addFlash } = useFlash();

    const handleSubmission = ({ email }: Values, { setSubmitting, resetForm }: FormikHelpers<Values>) => {
        clearFlashes();

        // Get captcha response if enabled
        const captchaResponse = getCaptchaResponse();

        let requestData: Record<string, unknown> = { email };
        if (CaptchaManager.isEnabled() && captchaResponse) {
            const fieldName = CaptchaManager.getProviderInstance().getResponseFieldName();
            if (fieldName) {
                requestData = { ...requestData, [fieldName]: captchaResponse };
            }
        }

        http.post('/auth/password', requestData)
            .then((response) => {
                resetForm();
                addFlash({
                    type: 'success',
                    title: 'Success',
                    message: response.data.status || 'Reset link sent.',
                });
            })
            .catch((error) => {
                console.error(error);
                addFlash({
                    type: 'error',
                    title: 'Error',
                    message: httpErrorToHuman(error),
                });
            })
            .finally(() => {
                setSubmitting(false);
            });
    };

    return (
        <Formik
            onSubmit={handleSubmission}
            initialValues={{ email: '' }}
            validationSchema={object().shape({
                email: string().email('Enter a valid email address.').required('Email is required.'),
            })}
        >
            {({ isSubmitting }) => (
                <LoginFormContainer className='mx-auto flex w-full max-w-md flex-col gap-6 rounded-2xl border border-cream-500/10 bg-bg-raised p-8 shadow-xl shadow-black/40'>
                    <TitleSection
                        title='Forgot your password?'
                        subtitle="Enter your account's email. If your account has Discord linked, the bot DMs you a reset link."
                    />
                    <Field id='email' label={'Email'} name={'email'} type={'email'} />

                    <Captcha
                        onError={(error) => {
                            console.error('Captcha error:', error);
                            addFlash({
                                type: 'error',
                                title: 'Error',
                                message: 'Captcha verification failed. Please try again.',
                            });
                        }}
                    />

                    <div className='flex w-full flex-col gap-4 sm:flex-row sm:justify-between sm:items-center'>
                        <Button
                            className='bg-mocha-100 rounded-lg p-2 px-4 text-black hover:cursor-pointer hover:bg-mocha-200 ease-in-out w-full sm:w-auto'
                            type='submit'
                            size='xlarge'
                            isLoading={isSubmitting}
                            disabled={isSubmitting}
                        >
                            Send reset link
                        </Button>

                        <SecondaryLink to='/auth/login' className='text-center sm:text-right'>
                            Back to sign in
                        </SecondaryLink>
                    </div>
                </LoginFormContainer>
            )}
        </Formik>
    );
};

export default ForgotPasswordContainer;
