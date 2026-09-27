import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { passwordSchema } from '@/lib/validation';
import { authService } from '@/services/authService';
import { usePageTitle } from '@/hooks/use-page-title';
import { applyApiErrors } from '@/components/forms/api-errors';
import { FormField } from '@/components/forms/form-field';
import { Alert } from '@/components/ui/feedback';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

const schema = z
  .object({ password: passwordSchema, passwordConfirmation: z.string().min(1, 'Confirm your new password.') })
  .refine((v) => v.password === v.passwordConfirmation, { path: ['passwordConfirmation'], message: 'Passwords do not match.' });
type Values = z.infer<typeof schema>;

export default function ResetPasswordPage() {
  usePageTitle('Choose a new password');
  const [params] = useSearchParams();
  const navigate = useNavigate();
  const token = params.get('token') ?? '';
  const email = params.get('email') ?? '';
  const [error, setError] = useState<string | null>(null);
  const { register, handleSubmit, setError: setFieldError, formState: { errors, isSubmitting } } = useForm<Values>({ resolver: zodResolver(schema) });

  if (!token || !email) {
    return (
      <div className="flex flex-col gap-4">
        <h1 className="text-[22px] font-bold">Reset link invalid</h1>
        <Alert tone="danger">This link is missing information. Request a new reset link.</Alert>
        <Button asChild variant="secondary"><Link to="/forgot-password">Request a new link</Link></Button>
      </div>
    );
  }

  const onSubmit = async (v: Values) => {
    setError(null);
    try {
      await authService.resetPassword({ token, email, password: v.password, passwordConfirmation: v.passwordConfirmation });
      navigate('/login?reset=1', { replace: true });
    } catch (err) {
      setError(applyApiErrors(err, setFieldError, ['password', 'passwordConfirmation']));
    }
  };

  return (
    <div className="flex flex-col gap-5">
      <div className="flex flex-col gap-1">
        <h1 className="text-[22px] font-bold">Choose a new password</h1>
        <p className="text-[13.5px] text-ink-2">For {email}</p>
      </div>
      <form onSubmit={handleSubmit(onSubmit)} className="flex flex-col gap-4" noValidate>
        <FormField label="New password" error={errors.password?.message} hint="At least 8 characters, with a letter and a number.">
          {(a) => <Input id={a.id} type="password" autoComplete="new-password" aria-invalid={a.invalid} aria-describedby={a.describedBy} className="h-11" {...register('password')} />}
        </FormField>
        <FormField label="Confirm new password" error={errors.passwordConfirmation?.message}>
          {(a) => <Input id={a.id} type="password" autoComplete="new-password" aria-invalid={a.invalid} aria-describedby={a.describedBy} className="h-11" {...register('passwordConfirmation')} />}
        </FormField>
        {error && <Alert tone="danger">{error}</Alert>}
        <Button type="submit" size="lg" loading={isSubmitting} className="w-full">Update password</Button>
      </form>
      <Link to="/login" className="text-[13px] font-semibold text-navy-500 hover:underline">Back to sign in</Link>
    </div>
  );
}
