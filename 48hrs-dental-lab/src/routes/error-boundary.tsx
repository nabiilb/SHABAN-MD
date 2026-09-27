import { Component, type ErrorInfo, type ReactNode } from 'react';
import { ErrorState } from '@/components/ui/feedback';

interface State {
  error: Error | null;
}

/** Catches render errors in a page so one broken view never blanks the whole app. */
export class RouteErrorBoundary extends Component<{ children: ReactNode }, State> {
  state: State = { error: null };

  static getDerivedStateFromError(error: Error): State {
    return { error };
  }

  componentDidCatch(error: Error, info: ErrorInfo) {
    // Hook for Sentry or similar in production.
    console.error('Page crashed', error, info.componentStack);
  }

  render() {
    if (this.state.error) {
      return (
        <ErrorState
          title="This page ran into a problem"
          message="The error has been logged. Try again, or go back to the dashboard."
          onRetry={() => this.setState({ error: null })}
          className="mt-6"
        />
      );
    }
    return this.props.children;
  }
}
