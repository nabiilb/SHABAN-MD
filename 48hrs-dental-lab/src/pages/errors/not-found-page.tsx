import { Link } from 'react-router-dom';
import { Compass } from 'lucide-react';
import { usePageTitle } from '@/hooks/use-page-title';
import { Button } from '@/components/ui/button';

export default function NotFoundPage() {
  usePageTitle('Page not found');
  return (
    <div className="mx-auto mt-10 flex max-w-md flex-col items-center gap-3 rounded-md border border-line bg-card px-6 py-12 text-center shadow-sm">
      <span className="flex size-12 items-center justify-center rounded-full bg-navy-50 text-brand">
        <Compass className="size-6" aria-hidden />
      </span>
      <h2 className="text-lg font-bold">Page not found</h2>
      <p className="text-sm text-ink-2">The page you are looking for does not exist or has moved.</p>
      <Button asChild className="mt-2">
        <Link to="/dashboard">Back to dashboard</Link>
      </Button>
    </div>
  );
}
