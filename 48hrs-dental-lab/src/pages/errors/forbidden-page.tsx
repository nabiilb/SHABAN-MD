import { Link } from 'react-router-dom';
import { ShieldAlert } from 'lucide-react';
import { usePageTitle } from '@/hooks/use-page-title';
import { Button } from '@/components/ui/button';

export function ForbiddenPage() {
  usePageTitle('Access restricted');
  return (
    <div className="mx-auto mt-10 flex max-w-md flex-col items-center gap-3 rounded-md border border-line bg-card px-6 py-12 text-center shadow-sm">
      <span className="flex size-12 items-center justify-center rounded-full bg-warning-bg text-warning">
        <ShieldAlert className="size-6" aria-hidden />
      </span>
      <h2 className="text-lg font-bold">You do not have access to this page</h2>
      <p className="text-sm text-ink-2">Your role does not include the permission this page needs. A Super Admin can grant it under Roles &amp; Permissions.</p>
      <Button asChild className="mt-2">
        <Link to="/dashboard">Back to dashboard</Link>
      </Button>
    </div>
  );
}
