import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Link } from 'react-router-dom';
import { ArrowLeft } from 'lucide-react';
import { authService } from '@/services/authService';
import { usePageTitle } from '@/hooks/use-page-title';
import type { ForgotPasswordResult } from '@48hrs/shared/types';
import { applyApiErrors } from '@/components/forms/api-errors';
import { FormField } from '@/components/forms/form-field';
import { Alert } from '@/components/ui/feedback';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

const schema = z.object({ email: z.string().trim().min(1, 'Enter your email address.').email('Enter a valid email address.') });
type Values = z.infer<typeof schema>;

export default function ForgotPasswordPage() {
  usePageTitle('Forgot password');
  const [result, setResult] = useState<ForgotPasswordResult | null>(null);
  const [error, setError] = useState<string | null>(null);
  const { register, handleSubmit, setError: setFieldError, formState: { errors, isSubmitting } } = useForm<Values>({ resolver: zodResolver(schema) });

  const onSubmit = async (v: Values) => {
    setError(null);
    try {
      setResult(await authService.forgotPassword({ email: v.email }));
    } catch (err) {
      setError(applyApiErrors(err, setFieldError, ['email']));
    }
  };

  return (
    <div className="flex flex-col gap-5">
      <div className="flex flex-col gap-1">
        <h1 className="text-[22px] font-bold">Reset your password</h1>
        <p className="text-[13.5px] text-ink-2">Enter your account email and we will send you a reset link.</p>
      </div>
      {result ? (
        <div className="flex flex-col gap-3">
          <Alert tone="success">{result.message}</Alert>
          {result.devResetUrl && (
            <Alert tone="info" title="Demo mode">
              E-mail is not sent in mock mode. Continue with{' '}
              <Link to={result.devResetUrl} className="font-semibold underline">this reset link</Link>.
            </Alert>
          )}
        </div>
      ) : (
        <form onSubmit={handleSubmit(onSubmit)} className="flex flex-col gap-4" noValidate>
          <FormField label="Email" error={errors.email?.message}>
            {(a) => <Input id={a.id} type="email" autoComplete="email" aria-invalid={a.invalid} aria-describedby={a.describedBy} className="h-11" {...register('email')} />}
          </FormField>
          {error && <Alert tone="danger">{error}</Alert>}
          <Button type="submit" size="lg" loading={isSubmitting} className="w-full">Send reset link</Button>
        </form>
      )}
      <Link to="/login" className="inline-flex items-center gap-1.5 text-[13px] font-semibold text-navy-500 hover:underline">
        <ArrowLeft className="size-4" /> Back to sign in
      </Link>
    </div>
  );
}
