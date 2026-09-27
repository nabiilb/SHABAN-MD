import { useEffect, useMemo, useState } from 'react';
import type { ColumnDef } from '@tanstack/react-table';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { toast } from 'sonner';
import { MoreHorizontal, Pencil, Plus, Power, Trash2 } from 'lucide-react';
import { PERMISSIONS, ROLE_LABELS, ROLE_ORDER } from '@/lib/permissions';
import { emailField, optionalPhone, passwordSchema, requiredText } from '@/lib/validation';
import { useDeleteUser, useSaveUser, useSetUserActive, useUsers } from '@/hooks/api/use-admin';
import { useClinics } from '@/hooks/api/use-directory';
import { useAuth } from '@/hooks/use-auth';
import { usePageTitle } from '@/hooks/use-page-title';
import { useUrlState } from '@/hooks/use-url-state';
import { errorMessage } from '@/services/api/errors';
import type { RoleKey, User } from '@/types/models';
import { formatDateTime } from '@/utils/format';
import { applyApiErrors } from '@/components/forms/api-errors';
import { SelectField, SwitchField, TextField } from '@/components/forms/fields';
import { DataTable } from '@/components/tables/data-table';
import { ClearFiltersButton, FilterSelect, SearchInput, ToolbarRow } from '@/components/tables/toolbar';
import { Alert } from '@/components/ui/feedback';
import { Avatar } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { Dialog, DialogContent } from '@/components/ui/dialog';
import { Menu, MenuContent, MenuItem, MenuSeparator, MenuTrigger } from '@/components/ui/menu';

const DEFAULTS = { search: '', role: undefined as string | undefined, active: undefined as string | undefined, sort: 'name', dir: 'asc', page: '1', perPage: '20' };

const userSchema = z
  .object({
    name: requiredText('Full name'),
    email: emailField,
    phone: optionalPhone,
    role: z.enum(ROLE_ORDER as [RoleKey, ...RoleKey[]]),
    clinicId: z.string().optional(),
    active: z.boolean(),
    password: z.union([passwordSchema, z.literal('')]).optional(),
    isNew: z.boolean(),
  })
  .superRefine((v, ctx) => {
    if (v.isNew && !v.password) ctx.addIssue({ code: 'custom', path: ['password'], message: 'Set an initial password.' });
    if (v.role === 'client' && !v.clinicId) ctx.addIssue({ code: 'custom', path: ['clinicId'], message: 'Client users must be linked to a clinic.' });
  });
type UserValues = z.infer<typeof userSchema>;

function UserDialog({ open, onOpenChange, record }: { open: boolean; onOpenChange: (o: boolean) => void; record: User | null }) {
  const save = useSaveUser();
  const { user: me } = useAuth();
  const clinics = useClinics({ perPage: 200 }, open);
  const { register, control, handleSubmit, reset, setError, watch, formState: { errors } } = useForm<UserValues>({ resolver: zodResolver(userSchema) });
  useEffect(() => {
    if (open) reset({ name: record?.name ?? '', email: record?.email ?? '', phone: record?.phone ?? '', role: record?.role ?? 'technician', clinicId: record?.clinicId ?? '', active: record?.active ?? true, password: '', isNew: !record });
  }, [open, record, reset]);
  const role = watch('role');

  const onSubmit = async (v: UserValues) => {
    try {
      await save.mutateAsync({ id: record?.id, payload: { name: v.name, email: v.email, phone: v.phone, role: v.role, active: v.active, clinicId: v.role === 'client' ? v.clinicId : null, password: v.password || undefined } });
      toast.success(record ? 'User updated' : 'User created');
      onOpenChange(false);
    } catch (err) {
      const msg = applyApiErrors(err, setError, ['name', 'email', 'phone', 'role', 'clinicId', 'password']);
      if (msg) toast.error(msg);
    }
  };

  const roles = ROLE_ORDER.filter((r) => r !== 'super_admin' || me?.role === 'super_admin');

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent size="lg" title={record ? 'Edit user' : 'Add user'} meta={record?.email} footer={<><Button variant="ghost" onClick={() => onOpenChange(false)}>Cancel</Button><Button onClick={handleSubmit(onSubmit)} loading={save.isPending}>Save user</Button></>}>
        <form onSubmit={handleSubmit(onSubmit)} noValidate className="grid gap-4 sm:grid-cols-2">
          <TextField name="name" label="Full name" register={register} errors={errors} required placeholder="Hodan Abdi" />
          <TextField name="email" label="Email" type="email" register={register} errors={errors} required placeholder="hodan@48hrs.lab" autoComplete="off" />
          <SelectField name="role" label="Role" register={register} errors={errors} required options={roles.map((r) => ({ value: r, label: ROLE_LABELS[r] }))} />
          <TextField name="phone" label="Phone" type="tel" register={register} errors={errors} optional />
          {role === 'client' && <SelectField name="clinicId" label="Clinic" register={register} errors={errors} required className="sm:col-span-2" options={(clinics.data?.data ?? []).map((k) => ({ value: k.id, label: k.name }))} placeholder="Select clinic" />}
          <TextField name="password" label={record ? 'New password' : 'Initial password'} type="password" autoComplete="new-password" register={register} errors={errors} required={!record} optional={!!record} hint={record ? 'Leave empty to keep the current password.' : 'At least 8 characters, with a letter and a number.'} />
          <div className="self-end"><SwitchField name="active" control={control} label="Active" description="Disabled accounts cannot sign in." /></div>
          <Alert className="sm:col-span-2">The role decides which actions this user can perform. Fine-tune them under Roles &amp; Permissions.{role === 'technician' && ' A technician profile is linked automatically.'}</Alert>
        </form>
      </DialogContent>
    </Dialog>
  );
}

export default function UsersPage() {
  usePageTitle('Users', 'Who has access, and with which role');
  const { can, user: me } = useAuth();
  const [f, setF, resetF] = useUrlState(DEFAULTS);
  const [editing, setEditing] = useState<User | null | 'new'>(null);
  const [toDelete, setToDelete] = useState<User | null>(null);
  const q = useUsers({ search: f.search || undefined, role: f.role as RoleKey | undefined, active: f.active === undefined ? undefined : f.active === 'true', sort: f.sort, dir: f.dir as 'asc' | 'desc', page: Number(f.page), perPage: Number(f.perPage) });
  const setActive = useSetUserActive();
  const del = useDeleteUser();
  const manage = can(PERMISSIONS.USERS_MANAGE);

  const toggle = async (u: User) => {
    try {
      await setActive.mutateAsync({ id: u.id, active: !u.active });
      toast.success(`${u.name} ${u.active ? 'disabled' : 'enabled'}`);
    } catch (err) {
      toast.error(errorMessage(err));
    }
  };

  const columns = useMemo<ColumnDef<User, unknown>[]>(
    () => [
      { id: 'name', header: 'User', meta: { sortKey: 'name' }, cell: ({ row }) => <div className="flex items-center gap-3"><Avatar name={row.original.name} size="sm" /><div className="flex flex-col"><span className="font-semibold whitespace-nowrap">{row.original.name}{row.original.id === me?.id && <span className="ml-1.5 text-xs text-ink-3">(you)</span>}</span><span className="text-xs text-ink-3">{row.original.email}</span></div></div> },
      { id: 'role', header: 'Role', meta: { sortKey: 'role' }, cell: ({ row }) => <Badge tone={row.original.role === 'super_admin' ? 'navy' : 'info'}>{ROLE_LABELS[row.original.role]}</Badge> },
      { id: 'phone', header: 'Phone', meta: { cellClassName: 'whitespace-nowrap text-ink-2' }, cell: ({ row }) => row.original.phone || '—' },
      { id: 'last', header: 'Last sign-in', meta: { sortKey: 'lastLoginAt', cellClassName: 'whitespace-nowrap text-ink-2' }, cell: ({ row }) => formatDateTime(row.original.lastLoginAt) },
      { id: 'status', header: 'Status', meta: { sortKey: 'status' }, cell: ({ row }) => <Badge tone={row.original.active ? 'success' : 'neutral'}>{row.original.active ? 'ACTIVE' : 'DISABLED'}</Badge> },
      ...(manage
        ? ([
            {
              id: 'actions',
              header: () => <span className="sr-only">Actions</span>,
              meta: { align: 'right' },
              cell: ({ row }) => (
                <Menu>
                  <MenuTrigger asChild><Button variant="ghost" size="icon-sm" aria-label={`Actions for ${row.original.name}`}><MoreHorizontal /></Button></MenuTrigger>
                  <MenuContent>
                    <MenuItem onSelect={() => setEditing(row.original)}><Pencil /> Edit</MenuItem>
                    {row.original.id !== me?.id && <MenuItem onSelect={() => void toggle(row.original)}><Power /> {row.original.active ? 'Disable' : 'Enable'}</MenuItem>}
                    {row.original.id !== me?.id && (<><MenuSeparator /><MenuItem tone="danger" onSelect={() => setToDelete(row.original)}><Trash2 /> Delete</MenuItem></>)}
                  </MenuContent>
                </Menu>
              ),
            },
          ] as ColumnDef<User, unknown>[])
        : []),
    ],
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [manage, me?.id],
  );

  return (
    <div className="flex flex-col gap-4">
      {manage && <div className="flex justify-end"><Button onClick={() => setEditing('new')}><Plus /> Add user</Button></div>}
      <DataTable
        caption="Users"
        columns={columns}
        data={q.data?.data}
        meta={q.data?.meta}
        getRowId={(u) => u.id}
        isLoading={q.isLoading}
        isFetching={q.isFetching}
        error={q.error}
        onRetry={() => void q.refetch()}
        sort={{ key: f.sort, dir: f.dir as 'asc' | 'desc' }}
        onSortChange={(s) => setF({ sort: s.key ?? DEFAULTS.sort, dir: s.dir ?? DEFAULTS.dir })}
        onPageChange={(p) => setF({ page: String(p) }, { resetPage: false })}
        onPerPageChange={(n) => setF({ perPage: String(n) })}
        emptyTitle="No users found."
        renderMobileCard={(u) => (
          <div className="flex items-center justify-between gap-2">
            <div className="flex items-center gap-3"><Avatar name={u.name} size="sm" /><div className="flex flex-col"><span className="font-bold">{u.name}</span><span className="text-xs text-ink-3">{u.email}</span></div></div>
            <div className="flex flex-col items-end gap-1"><Badge tone="info">{ROLE_LABELS[u.role]}</Badge>{manage && <Button variant="link" size="sm" onClick={() => setEditing(u)}>Edit</Button>}</div>
          </div>
        )}
        toolbar={
          <ToolbarRow>
            <SearchInput value={f.search} onChange={(v) => setF({ search: v })} placeholder="Name, email or role…" />
            <FilterSelect label="Role" value={f.role} onChange={(v) => setF({ role: v })} options={ROLE_ORDER.map((r) => ({ value: r, label: ROLE_LABELS[r] }))} />
            <FilterSelect label="Status" value={f.active} onChange={(v) => setF({ active: v })} options={[{ value: 'true', label: 'Active' }, { value: 'false', label: 'Disabled' }]} />
            <ClearFiltersButton show={!!(f.search || f.role || f.active)} onClear={resetF} />
          </ToolbarRow>
        }
      />
      <UserDialog open={editing !== null} onOpenChange={(o) => !o && setEditing(null)} record={editing && editing !== 'new' ? editing : null} />
      <ConfirmDialog
        open={!!toDelete}
        onOpenChange={(o) => !o && setToDelete(null)}
        title="Delete user?"
        tone="danger"
        confirmLabel="Delete user"
        loading={del.isPending}
        description={<>Users who appear in case history cannot be deleted — disable them instead so the audit trail stays intact.</>}
        onConfirm={async () => {
          if (!toDelete) return;
          try {
            await del.mutateAsync(toDelete.id);
            toast.success('User deleted');
            setToDelete(null);
          } catch (err) {
            toast.error(errorMessage(err));
            setToDelete(null);
          }
        }}
      />
    </div>
  );
}
