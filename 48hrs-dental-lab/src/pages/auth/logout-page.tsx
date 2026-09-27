import { useEffect } from 'react';
import { Navigate } from 'react-router-dom';
import { useAuthStore } from '@/stores/auth-store';
import { PageLoader } from '@/components/ui/feedback';

export default function LogoutPage() {
  const logout = useAuthStore((s) => s.logout);
  const status = useAuthStore((s) => s.status);

  useEffect(() => {
    if (status === 'authenticated') void logout('manual');
  }, [status, logout]);

  if (status === 'anonymous') return <Navigate to="/login" replace />;
  return <PageLoader label="Signing out…" />;
}
