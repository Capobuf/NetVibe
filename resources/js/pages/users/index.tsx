import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    Search,
    ShieldCheck,
    Trash2,
    Users,
    X,
} from 'lucide-react';
import { useRef, useState } from 'react';
import { DeletionConfirmation } from '@/components/companies/deletion-confirmation';
import { IconButton } from '@/components/icon-button';
import InputError from '@/components/input-error';
import { TableColumns } from '@/components/table-columns';
import { TableSortHead } from '@/components/table-sort-head';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardFooter,
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
        prev_page_url: string | null;
        next_page_url: string | null;
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
    const { auth } = usePage().props;
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
    const indexRoute = (nextQuery: Record<string, string | null>) =>
        company
            ? companyIndex(company.id, { query: nextQuery })
            : index({ query: nextQuery });

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
                        <Form
                            {...(company
                                ? companyIndex.form(company.id)
                                : index.form())}
                            options={{
                                preserveState: true,
                                preserveScroll: true,
                            }}
                            className="w-full max-w-md"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <Label
                                        htmlFor="user-search"
                                        className="sr-only"
                                    >
                                        Cerca per nome, email o azienda
                                    </Label>
                                    <div className="flex items-center gap-2">
                                        <Input
                                            key={query.search}
                                            id="user-search"
                                            name="search"
                                            type="search"
                                            placeholder="Cerca nome, email o azienda…"
                                            defaultValue={query.search}
                                            maxLength={255}
                                            aria-invalid={Boolean(
                                                errors.search,
                                            )}
                                            aria-describedby="user-search-error"
                                        />
                                        <input
                                            type="hidden"
                                            name="sort"
                                            value={query.sort ?? ''}
                                        />
                                        <input
                                            type="hidden"
                                            name="direction"
                                            value={query.direction}
                                        />
                                        <IconButton
                                            label="Cerca utenti"
                                            type="submit"
                                            disabled={processing}
                                        >
                                            <Search />
                                        </IconButton>
                                        {query.search ? (
                                            <IconButton
                                                label="Azzera ricerca"
                                                asChild
                                            >
                                                <Link
                                                    href={indexRoute({
                                                        sort: query.sort,
                                                        direction:
                                                            query.direction,
                                                    })}
                                                    preserveState
                                                    preserveScroll
                                                >
                                                    <X />
                                                </Link>
                                            </IconButton>
                                        ) : null}
                                    </div>
                                    <InputError
                                        id="user-search-error"
                                        message={errors.search}
                                    />
                                </>
                            )}
                        </Form>
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
                                                router.visit(
                                                    indexRoute({
                                                        search: query.search,
                                                        sort:
                                                            next?.column ??
                                                            null,
                                                        direction:
                                                            next?.direction ??
                                                            'asc',
                                                    }),
                                                    {
                                                        preserveState: true,
                                                        preserveScroll: true,
                                                    },
                                                )
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
                    <CardFooter className="flex-wrap justify-between gap-3 border-t py-4 text-sm text-muted-foreground tabular-nums">
                        <p>
                            {users.total}{' '}
                            {users.total === 1 ? 'utente' : 'utenti'}
                        </p>
                        {users.last_page > 1 || users.current_page > 1 ? (
                            <nav
                                aria-label="Pagine degli utenti"
                                className="flex items-center gap-3"
                            >
                                {users.prev_page_url ? (
                                    <IconButton
                                        label="Pagina precedente"
                                        asChild
                                    >
                                        <Link
                                            href={users.prev_page_url}
                                            preserveState
                                            preserveScroll
                                        >
                                            <ArrowLeft />
                                        </Link>
                                    </IconButton>
                                ) : (
                                    <IconButton
                                        label="Pagina precedente"
                                        disabled
                                    >
                                        <ArrowLeft />
                                    </IconButton>
                                )}
                                <span>
                                    {users.current_page} / {users.last_page}
                                </span>
                                {users.next_page_url ? (
                                    <IconButton
                                        label="Pagina successiva"
                                        asChild
                                    >
                                        <Link
                                            href={users.next_page_url}
                                            preserveState
                                            preserveScroll
                                        >
                                            <ArrowRight />
                                        </Link>
                                    </IconButton>
                                ) : (
                                    <IconButton
                                        label="Pagina successiva"
                                        disabled
                                    >
                                        <ArrowRight />
                                    </IconButton>
                                )}
                            </nav>
                        ) : null}
                    </CardFooter>
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
