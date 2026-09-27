import { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { Eye, EyeOff } from 'lucide-react';
import { useAuthStore } from '@/stores/auth-store';
import { usePageTitle } from '@/hooks/use-page-title';
import { ApiError } from '@/services/api/errors';
import { FormField } from '@/components/forms/form-field';
import { Alert } from '@/components/ui/feedback';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

const schema = z.object({
  email: z.string().trim().min(1, 'Enter your email address.').email('Enter a valid email address.'),
  password: z.string().min(1, 'Enter your password.'),
});
type Values = z.infer<typeof schema>;

type DemoAccount = { role: string; email: string };

/** Only allow in-app redirects after login (no open redirects). */
function safeNext(next: string | null) {
  return next && next.startsWith('/') && !next.startsWith('//') ? next : '/dashboard';
}

export default function LoginPage() {
  usePageTitle('Sign in');
  const login = useAuthStore((s) => s.login);
  const endedReason = useAuthStore((s) => s.endedReason);
  const navigate = useNavigate();
  const [params] = useSearchParams();
  const [serverError, setServerError] = useState<string | null>(null);
  const [showPassword, setShowPassword] = useState(false);
  const [demo, setDemo] = useState<{ password: string; accounts: readonly DemoAccount[] } | null>(null);

  const { register, handleSubmit, setValue, formState: { errors, isSubmitting } } = useForm<Values>({ resolver: zodResolver(schema), defaultValues: { email: '', password: '' } });

  // Demo sign-ins are loaded only in mock mode, so they never ship with a real backend.
  useEffect(() => {
    if (import.meta.env.VITE_USE_MOCKS === 'false') return;
    void import('@/mocks/demo-accounts').then((m) => setDemo({ password: m.DEMO_PASSWORD, accounts: m.DEMO_ACCOUNTS }));
  }, []);

  const onSubmit = async (v: Values) => {
    setServerError(null);
    try {
      await login({ email: v.email.trim(), password: v.password });
      navigate(safeNext(params.get('next')), { replace: true });
    } catch (err) {
      setServerError(err instanceof ApiError ? err.message : 'Sign-in failed. Please try again.');
    }
  };

  return (
    <div className="flex flex-col gap-5">
      <div className="flex flex-col gap-1">
        <h1 className="text-[22px] font-bold">Sign in</h1>
        <p className="text-[13.5px] text-ink-2">Use your laboratory account.</p>
      </div>

      {endedReason === 'expired' && !serverError && <Alert tone="warning">Your session expired. Please sign in again.</Alert>}
      {params.get('reset') === '1' && <Alert tone="success">Password updated. Sign in with your new password.</Alert>}

      <form onSubmit={handleSubmit(onSubmit)} className="flex flex-col gap-4" noValidate>
        <FormField label="Email" error={errors.email?.message}>
          {(a) => <Input id={a.id} type="email" autoComplete="username" placeholder="you@48hrs.lab" aria-invalid={a.invalid} aria-describedby={a.describedBy} className="h-11" {...register('email')} />}
        </FormField>
        <FormField label="Password" error={errors.password?.message}>
          {(a) => (
            <div className="relative">
              <Input id={a.id} type={showPassword ? 'text' : 'password'} autoComplete="current-password" aria-invalid={a.invalid} aria-describedby={a.describedBy} className="h-11 pr-11" {...register('password')} />
              <button type="button" onClick={() => setShowPassword((s) => !s)} className="absolute top-1/2 right-2 -translate-y-1/2 rounded p-1.5 text-ink-3 hover:text-ink" aria-label={showPassword ? 'Hide password' : 'Show password'}>
                {showPassword ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
              </button>
            </div>
          )}
        </FormField>
        <div className="-mt-1 flex justify-end">
          <Link to="/forgot-password" className="text-[13px] font-semibold text-navy-500 hover:underline">Forgot password?</Link>
        </div>
        {serverError && <Alert tone="danger">{serverError}</Alert>}
        <Button type="submit" size="lg" loading={isSubmitting} className="w-full">
          Sign in
        </Button>
      </form>

      {demo && (
        <div className="flex flex-col gap-2 border-t border-line pt-4">
          <span className="text-[11px] font-bold tracking-[.14em] text-ink-3">DEMO ACCOUNTS · PASSWORD {demo.password}</span>
          <div className="grid grid-cols-[repeat(auto-fit,minmax(160px,1fr))] gap-2">
            {demo.accounts.map((d) => (
              <button
                key={d.email}
                type="button"
                onClick={() => {
                  setValue('email', d.email, { shouldValidate: true });
                  setValue('password', demo.password, { shouldValidate: true });
                }}
                className="flex flex-col gap-0.5 rounded-md border border-line bg-gray-50 px-3 py-2.5 text-left hover:bg-navy-50"
              >
                <span className="text-[13px] font-bold">{d.role}</span>
                <span className="truncate text-[11.5px] text-ink-3">{d.email}</span>
              </button>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}
