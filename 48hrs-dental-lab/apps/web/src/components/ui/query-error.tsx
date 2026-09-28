import { Link } from 'react-router-dom';
import { ShieldAlert } from 'lucide-react';
import { ApiError, errorMessage } from '@/services/api/errors';
import { Button } from './button';
import { EmptyState, ErrorState } from './feedback';

/**
 * Renders a failed query: 403 → access restricted, 404 → not found (no retry,
 * retrying cannot help), anything else → retryable error.
 */
export function QueryError({ error, onRetry, className, backTo, backLabel }: { error: unknown; onRetry?: () => void; className?: string; backTo?: string; backLabel?: string }) {
  const back = backTo ? (
    <Button asChild variant="outline" size="sm">
      <Link to={backTo}>{backLabel ?? 'Go back'}</Link>
    </Button>
  ) : undefined;
  if (error instanceof ApiError && error.isForbidden) {
    return <EmptyState className={className} icon={<ShieldAlert />} title="Access restricted" description={`${error.message} A Super Admin can grant access under Roles & Permissions.`} action={back} />;
  }
  if (error instanceof ApiError && error.isNotFound) {
    return <EmptyState className={className} title="Not found" description="It may have been deleted, or it is outside your access." action={back} />;
  }
  return <ErrorState className={className} message={errorMessage(error)} onRetry={onRetry} />;
}
