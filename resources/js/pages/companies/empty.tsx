import { Head, router, usePage } from '@inertiajs/react';
import { Building2 } from 'lucide-react';
import CompanyForm from '@/components/companies/company-form';
import {
    Empty,
    EmptyContent,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import { index as subnets } from '@/routes/companies/sites/subnets';

export default function CompaniesEmpty() {
    const { auth } = usePage().props;
    return (
        <>
            <Head title="Nessuna azienda" />
            <Empty className="flex-1">
                <EmptyHeader>
                    <EmptyMedia variant="icon">
                        <Building2 />
                    </EmptyMedia>
                    <EmptyTitle>Nessuna azienda</EmptyTitle>
                    {auth.user.is_superadmin ? null : (
                        <EmptyDescription>
                            Chiedi a un amministratore di associarti a
                            un’azienda.
                        </EmptyDescription>
                    )}
                </EmptyHeader>
                {auth.user.is_superadmin ? (
                    <EmptyContent>
                        <CompanyForm
                            onCreated={(company) =>
                                router.visit(
                                    subnets({
                                        company: company.id,
                                        site: company.site_id,
                                    }),
                                )
                            }
                        />
                    </EmptyContent>
                ) : null}
            </Empty>
        </>
    );
}
