import { useEffect, useState } from 'react';
import { QueryClientProvider } from '@tanstack/react-query';
import { RouterProvider } from 'react-router-dom';
import { Toaster } from 'sonner';
import { createQueryClient } from '@/lib/query-client';
import { useAuthStore } from '@/stores/auth-store';
import { TooltipProvider } from '@/components/ui/tooltip';
import { router } from '@/routes/router';

export function App() {
  const [queryClient] = useState(createQueryClient);
  const bootstrap = useAuthStore((s) => s.bootstrap);
  const status = useAuthStore((s) => s.status);

  useEffect(() => {
    void bootstrap();
  }, [bootstrap]);

  // Drop cached server data whenever the user signs out so the next user never sees it.
  useEffect(() => {
    if (status === 'anonymous') queryClient.clear();
  }, [status, queryClient]);

  return (
    <QueryClientProvider client={queryClient}>
      <TooltipProvider>
        <RouterProvider router={router} />
        <Toaster position="top-right" richColors closeButton toastOptions={{ style: { fontFamily: 'var(--font-sans)' } }} />
      </TooltipProvider>
    </QueryClientProvider>
  );
}
