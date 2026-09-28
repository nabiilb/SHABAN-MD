import { describe, expect, it } from 'vitest';
import { useState } from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { Outlet } from 'react-router-dom';
import LoginPage from '@/pages/auth/login-page';
import NewCasePage from '@/pages/cases/new-case-page';
import { GuestOnly, RequireAuth, RequirePermission } from '@/routes/guards';
import { useAuthStore } from '@/stores/auth-store';
import { ToothChart } from '@/components/cases/tooth-chart';
import { DEMO_PASSWORD, loginAs, renderRoutes } from './helpers';

const Dashboard = () => <p>Dashboard content</p>;

describe('login page', () => {
  it('validates, signs in and redirects to the requested page', async () => {
    useAuthStore.setState({ status: 'anonymous', user: null, permissions: [] });
    const user = userEvent.setup();
    const { router } = renderRoutes(
      [
        { element: <GuestOnly />, children: [{ path: '/login', element: <LoginPage /> }] },
        { element: <RequireAuth />, children: [{ path: '/dashboard', element: <Dashboard /> }, { path: '/cases', element: <p>Cases content</p> }] },
      ],
      '/login?next=%2Fcases',
    );

    await user.click(await screen.findByRole('button', { name: 'Sign in' }));
    expect(await screen.findByText('Enter your email address.')).toBeInTheDocument();

    await user.type(screen.getByLabelText('Email'), 'sagal@48hrs.lab');
    await user.type(screen.getByLabelText('Password', { selector: 'input' }), 'wrong');
    await user.click(screen.getByRole('button', { name: 'Sign in' }));
    expect(await screen.findByText('Email or password not recognised.')).toBeInTheDocument();

    await user.clear(screen.getByLabelText('Password', { selector: 'input' }));
    await user.type(screen.getByLabelText('Password', { selector: 'input' }), DEMO_PASSWORD);
    await user.click(screen.getByRole('button', { name: 'Sign in' }));
    expect(await screen.findByText('Cases content')).toBeInTheDocument();
    expect(router.state.location.pathname).toBe('/cases');
    expect(useAuthStore.getState().user?.email).toBe('sagal@48hrs.lab');
  });

  it('shows demo accounts only in mock mode and fills the form', async () => {
    useAuthStore.setState({ status: 'anonymous', user: null, permissions: [] });
    const user = userEvent.setup();
    renderRoutes([{ path: '/login', element: <LoginPage /> }], '/login');
    await user.click(await screen.findByRole('button', { name: /Lab Manager/ }));
    expect(screen.getByLabelText('Email')).toHaveValue('omar@48hrs.lab');
  });
});

describe('protected routes', () => {
  it('redirects anonymous users to /login with a return path', async () => {
    useAuthStore.setState({ status: 'anonymous', user: null, permissions: [] });
    const { router } = renderRoutes(
      [
        { path: '/login', element: <p>Login screen</p> },
        { element: <RequireAuth />, children: [{ path: '/cases/:id', element: <p>Secret</p> }] },
      ],
      '/cases/abc',
    );
    expect(await screen.findByText('Login screen')).toBeInTheDocument();
    expect(screen.queryByText('Secret')).not.toBeInTheDocument();
    expect(router.state.location.search).toBe('?next=%2Fcases%2Fabc');
  });

  it('lets signed-in users through', async () => {
    await loginAs('sagal@48hrs.lab');
    renderRoutes([{ element: <RequireAuth />, children: [{ path: '/dashboard', element: <Dashboard /> }] }], '/dashboard');
    expect(await screen.findByText('Dashboard content')).toBeInTheDocument();
  });
});

describe('permission gate', () => {
  it('shows the access-restricted page when the permission is missing', async () => {
    await loginAs('fatima@48hrs.lab');
    renderRoutes([{ element: <Outlet />, children: [{ path: '/users', element: <RequirePermission anyOf={['users.view']}><p>User admin</p></RequirePermission> }] }], '/users');
    expect(await screen.findByText('You do not have access to this page')).toBeInTheDocument();
    expect(screen.queryByText('User admin')).not.toBeInTheDocument();
  });

  it('renders the page when the role has the permission', async () => {
    await loginAs('khalid@48hrs.lab');
    renderRoutes([{ path: '/users', element: <RequirePermission anyOf={['users.view']}><p>User admin</p></RequirePermission> }], '/users');
    expect(await screen.findByText('User admin')).toBeInTheDocument();
  });
});

describe('tooth chart', () => {
  function Harness() {
    const [teeth, setTeeth] = useState<number[]>([]);
    return (
      <>
        <ToothChart value={teeth} onChange={setTeeth} />
        <output data-testid="value">{teeth.join(',')}</output>
      </>
    );
  }

  it('toggles teeth and quadrants with real buttons', async () => {
    const user = userEvent.setup();
    render(<Harness />);
    await user.click(screen.getByRole('button', { name: 'Tooth 14' }));
    await user.click(screen.getByRole('button', { name: 'Tooth 3' }));
    expect(screen.getByTestId('value')).toHaveTextContent('3,14');
    expect(screen.getByRole('button', { name: 'Tooth 14' })).toHaveAttribute('aria-pressed', 'true');
    await user.click(screen.getByRole('button', { name: 'Remove tooth 3' }));
    expect(screen.getByTestId('value')).toHaveTextContent('14');
    await user.click(screen.getByRole('button', { name: 'Upper left' }));
    expect(screen.getByTestId('value')).toHaveTextContent('9,10,11,12,13,14,15,16');
  });
});

describe('create case form', () => {
  it('blocks submission until clinic, dentist, patient and teeth are provided', async () => {
    await loginAs('sagal@48hrs.lab');
    const user = userEvent.setup();
    renderRoutes([{ path: '/cases/new', element: <NewCasePage /> }, { path: '/cases/:id', element: <p>Detail page</p> }], '/cases/new');
    const submit = (await screen.findAllByRole('button', { name: 'Register case' }))[0];
    await user.click(submit);
    await waitFor(() => expect(screen.getByText('Select the clinic.')).toBeInTheDocument());
    expect(screen.getByText('Select the doctor.')).toBeInTheDocument();
    expect(screen.getByText('Select the patient, or add a new patient.')).toBeInTheDocument();
    expect(screen.getByText('Select at least one tooth on the chart before submitting.')).toBeInTheDocument();
    expect(screen.getByText(/Some fields need attention/)).toBeInTheDocument();
  });
});
