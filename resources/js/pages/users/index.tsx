import { Head, router, usePage } from '@inertiajs/react';
import { ShieldCheck, Trash2, Users } from 'lucide-react';
import { useRef, useState } from 'react';
import { DeletionConfirmation } from '@/components/companies/deletion-confirmation';
import { IconButton } from '@/components/icon-button';
import { TableColumns } from '@/components/table-columns';
import { TablePagination } from '@/components/table-pagination';
import { TableSearch } from '@/components/table-search';
import { TableSortHead } from '@/components/table-sort-head';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardHeader,
} from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Empty,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import InviteUser from '@/components/users/invite-user';
import { useTablePreferences } from '@/hooks/use-table-preferences';
import type { TableSort } from '@/lib/table-sort';
import { index as administration } from '@/routes/administration';
import { index, destroy } from '@/routes/administration/users';
import { show as companyShow } from '@/routes/companies';
import {
    index as companyIndex,
    destroy as companyDestroy,
} from '@/routes/companies/settings/users';
import type { Company } from '@/types/company';

type UserColumn = 'name' | 'email' | 'is_superadmin' | 'companies_count';
type ManagedUser = {
    id: number;
    name: string;
    email: string;
    is_superadmin: boolean;
    companies: Company[];
    companies_count: number;
};
type Props = {
    company: Company | null;
    users: {
        data: ManagedUser[];
        total: number;
        current_page: number;
        last_page: number;
        per_page: number;
        from: number | null;
        to: number | null;
    };
    query: {
        search: string;
        sort: UserColumn | null;
        direction: 'asc' | 'desc';
    };
    superadminCount: number;
};

export default function UserIndex({
    company,
    users,
    query,
    superadminCount,
}: Props) {
    const { auth, errors } = usePage().props;
    const [search, setSearch] = useState(query.search);
    const Title = company ? 'h2' : 'h1';
    const [deleting, setDeleting] = useState<ManagedUser | null>(null);
    const deleteOpener = useRef<HTMLButtonElement | null>(null);
    const { columns, setColumns, separators, setSeparators } =
        useTablePreferences<UserColumn>(company ? 'company-users' : 'users', [
            { id: 'name', label: 'Nome', visible: true },
            { id: 'email', label: 'Email', visible: true },
            { id: 'is_superadmin', label: 'Tipo account', visible: !company },
            { id: 'companies_count', label: 'Aziende', visible: true },
        ]);
    const visibleColumns = columns.filter((column) => column.visible);
    const sort: TableSort<UserColumn> = query.sort
        ? { column: query.sort, direction: query.direction }
        : null;

    function updateTable(
        options: Partial<Props['query']> & { page?: number; per_page?: number },
    ) {
        router.get(
            company ? companyIndex.url(company.id) : index.url(),
            {
                ...query,
                search,
                per_page: users.per_page,
                page: 1,
                ...options,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    return (
        <>
            <Head title={`Utenti · ${company?.name ?? 'Amministrazione'}`} />
            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-wrap items-center justify-between gap-4">
                    <Title
                        id="users-heading"
                        tabIndex={-1}
                        className="text-2xl font-medium tracking-tight"
                    >
                        Utenti
                    </Title>
                    <InviteUser
                        key={company?.id ?? 'global'}
                        company={company}
                    />
                </header>
                <Card className="gap-0 overflow-hidden py-0">
                    <CardHeader className="py-4">
                        <TableSearch
                            value={search}
                            maxLength={255}
                            error={errors.search}
                            total={users.total}
                            label="Cerca nome, email o azienda"
                            onChange={(value) => {
                                setSearch(value);
                                updateTable({ search: value });
                            }}
                        />
                    </CardHeader>
                    <CardContent className="px-0">
                        <Table
                            aria-label="Utenti"
                            data-separators={separators}
                            className="data-[separators=true]:[&_tr>:not(:last-child)]:border-r"
                        >
                            <TableHeader>
                                <TableRow>
                                    {visibleColumns.map((column) => (
                                        <TableSortHead
                                            key={column.id}
                                            column={column}
                                            sort={sort}
                                            onSort={(next) =>
                                                updateTable({
                                                    sort: next?.column ?? null,
                                                    direction:
                                                        next?.direction ??
                                                        'asc',
                                                })
                                            }
                                        />
                                    ))}
                                    <TableHead className="w-12 text-right">
                                        <TableColumns
                                            columns={columns}
                                            onChange={setColumns}
                                            separators={separators}
                                            onSeparatorsChange={setSeparators}
                                        />
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {users.data.map((user) => (
                                    <TableRow key={user.id}>
                                        {visibleColumns.map((column) => (
                                            <TableCell
                                                key={column.id}
                                                className="max-w-md break-words whitespace-normal"
                                            >
                                                {column.id ===
                                                'is_superadmin' ? (
                                                    <Badge
                                                        variant={
                                                            user.is_superadmin
                                                                ? 'default'
                                                                : 'secondary'
                                                        }
                                                    >
                                                        {user.is_superadmin ? (
                                                            <ShieldCheck data-icon="inline-start" />
                                                        ) : null}
                                                        {user.is_superadmin
                                                            ? 'Superadmin'
                                                            : 'Utente aziendale'}
                                                    </Badge>
                                                ) : column.id ===
                                                  'companies_count' ? (
                                                    user.is_superadmin ? (
                                                        'Tutte le aziende'
                                                    ) : (
                                                        <div className="flex flex-wrap gap-1">
                                                            {user.companies
                                                                .length
                                                                ? user.companies.map(
                                                                      (
                                                                          item,
                                                                      ) => (
                                                                          <Badge
                                                                              key={
                                                                                  item.id
                                                                              }
                                                                              variant="outline"
                                                                          >
                                                                              {
                                                                                  item.name
                                                                              }
                                                                          </Badge>
                                                                      ),
                                                                  )
                                                                : 'Nessuna azienda'}
                                                        </div>
                                                    )
                                                ) : (
                                                    <>
                                                        {user[column.id]}
                                                        {column.id === 'name' &&
                                                        user.id ===
                                                            auth.user.id ? (
                                                            <span className="ml-2 text-xs text-muted-foreground">
                                                                Tu
                                                            </span>
                                                        ) : null}
                                                    </>
                                                )}
                                            </TableCell>
                                        ))}
                                        <TableCell className="text-right">
                                            {user.is_superadmin &&
                                            superadminCount === 1 ? (
                                                <Badge variant="outline">
                                                    Ultimo Superadmin
                                                </Badge>
                                            ) : (
                                                <IconButton
                                                    label={`Elimina utente ${user.email}`}
                                                    onClick={(event) => {
                                                        deleteOpener.current =
                                                            event.currentTarget;
                                                        setDeleting(user);
                                                    }}
                                                >
                                                    <Trash2 />
                                                </IconButton>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                                {users.data.length === 0 ? (
                                    <TableRow>
                                        <TableCell
                                            colSpan={visibleColumns.length + 1}
                                            className="h-40"
                                        >
                                            <Empty>
                                                <EmptyHeader>
                                                    <EmptyMedia variant="icon">
                                                        <Users />
                                                    </EmptyMedia>
                                                    <EmptyTitle>
                                                        {query.search
                                                            ? 'Nessun utente trovato'
                                                            : 'Nessun utente'}
                                                    </EmptyTitle>
                                                </EmptyHeader>
                                            </Empty>
                                        </TableCell>
                                    </TableRow>
                                ) : null}
                            </TableBody>
                        </Table>
                    </CardContent>
                    {users.total > 25 || users.last_page > 1 ? (
                        <TablePagination
                            summary={`${users.from ?? 0}–${users.to ?? 0} di ${users.total} utenti`}
                            page={users.current_page}
                            pageCount={users.last_page}
                            pageSize={users.per_page}
                            onPageChange={(page) => updateTable({ page })}
                            onPageSizeChange={(per_page) =>
                                updateTable({ per_page })
                            }
                        />
                    ) : null}
                </Card>
            </div>
            <Dialog
                open={deleting !== null}
                onOpenChange={(open) => {
                    if (!open) setDeleting(null);
                }}
            >
                <DialogContent
                    onCloseAutoFocus={(event) => {
                        event.preventDefault();
                        (deleteOpener.current?.isConnected
                            ? deleteOpener.current
                            : document.getElementById('users-heading')
                        )?.focus();
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>Elimina utente</DialogTitle>
                        <DialogDescription>{deleting?.email}</DialogDescription>
                    </DialogHeader>
                    {deleting ? (
                        <DeletionConfirmation
                            action={
                                company
                                    ? companyDestroy.form({
                                          company: company.id,
                                          user: deleting.id,
                                      })
                                    : destroy.form(deleting.id)
                            }
                            submitLabel="Elimina utente"
                            cancelLabel="Annulla eliminazione"
                            onCancel={() => setDeleting(null)}
                            onSuccess={() => setDeleting(null)}
                        >
                            <p>
                                L’intero account sarà eliminato definitivamente
                                da NetVibe, con tutte le sue associazioni
                                aziendali. Non potrà più accedere. I dati IPAM
                                saranno conservati.
                            </p>
                            {deleting.companies.length ? (
                                <p>
                                    Aziende associate:{' '}
                                    {deleting.companies
                                        .map((item) => item.name)
                                        .join(', ')}
                                    .
                                </p>
                            ) : null}
                            {deleting.id === auth.user.id ? (
                                <p>
                                    Stai eliminando il tuo account: verrai
                                    disconnesso.
                                </p>
                            ) : null}
                        </DeletionConfirmation>
                    ) : null}
                </DialogContent>
            </Dialog>
        </>
    );
}

UserIndex.layout = ({ company }: Props) => ({
    breadcrumbs: company
        ? [
              { title: company.name, href: companyShow(company.id) },
              {
                  title: 'Impostazioni · Utenti',
                  href: companyIndex(company.id),
              },
          ]
        : [
              { title: 'Amministrazione', href: administration() },
              { title: 'Utenti', href: index() },
          ],
});
