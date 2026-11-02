import { Head, Link } from '@inertiajs/react';
import {
    ArrowLeft,
    Download,
    LoaderCircle,
    Printer,
    RotateCcw,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { IconButton } from '@/components/icon-button';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { index, show } from '@/routes/companies/sites/racks';
import type { RackDocumentProps } from '@/types/rack-document';

type Preview = {
    url: string;
    filename: string;
    pages: number;
    warnings: string[];
};

export default function Documentation(props: RackDocumentProps) {
    return <DocumentPreview key={props.document.generatedAt} {...props} />;
}

function DocumentPreview(props: RackDocumentProps) {
    const { company, site, rack, document } = props;
    const [preview, setPreview] = useState<Preview | null>(null);
    const [error, setError] = useState('');
    const [attempt, setAttempt] = useState(0);
    const route = { company: company.id, site: site.id };
    useEffect(() => {
        let cancelled = false;
        let objectUrl: string | undefined;
        import('@/lib/rack-document-pdf')
            .then(({ rackDocumentPdf }) =>
                rackDocumentPdf({ company, site, rack, document }),
            )
            .then(({ blob, ...result }) => {
                if (cancelled) return;
                objectUrl = URL.createObjectURL(blob);
                setPreview({ ...result, url: objectUrl });
            })
            .catch((reason: unknown) => {
                if (!cancelled)
                    setError(
                        reason instanceof Error
                            ? reason.message
                            : 'Generazione del PDF non riuscita. Riprova.',
                    );
            });
        return () => {
            cancelled = true;
            if (objectUrl) URL.revokeObjectURL(objectUrl);
        };
    }, [company, site, rack, document, attempt]);

    return (
        <>
            <Head title={`Documentazione rack · ${company.name}`} />
            <div className="flex min-w-0 flex-col gap-5 p-4 md:p-6">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div className="flex min-w-0 items-start gap-3">
                        <IconButton label="Torna ai rack" asChild>
                            <Link
                                href={
                                    rack
                                        ? show({ ...route, rack: rack.id })
                                        : index(route)
                                }
                            >
                                <ArrowLeft />
                            </Link>
                        </IconButton>
                        <div className="flex min-w-0 flex-col gap-1">
                            <p className="text-sm text-muted-foreground">
                                {[company.name, site.name]
                                    .filter(Boolean)
                                    .join(' · ')}
                            </p>
                            <h1 className="text-2xl font-medium tracking-tight">
                                Documentazione rack
                            </h1>
                            <p className="text-sm text-muted-foreground">
                                {rack?.name ?? 'Intera sede'}
                                {preview ? ` · ${preview.pages} pagine` : ''}
                            </p>
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {preview ? (
                            <>
                                <Button variant="outline" asChild>
                                    <a
                                        href={preview.url}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    >
                                        <Printer data-icon="inline-start" />
                                        Apri / stampa PDF
                                    </a>
                                </Button>
                                <Button asChild>
                                    <a
                                        href={preview.url}
                                        download={preview.filename}
                                    >
                                        <Download data-icon="inline-start" />
                                        Scarica PDF
                                    </a>
                                </Button>
                            </>
                        ) : (
                            <Button disabled>
                                <Printer data-icon="inline-start" />
                                {error
                                    ? 'PDF non disponibile'
                                    : 'Preparazione PDF…'}
                            </Button>
                        )}
                    </div>
                </header>
                {error ? (
                    <Alert variant="destructive">
                        <AlertTitle>
                            Impossibile preparare il documento
                        </AlertTitle>
                        <AlertDescription>
                            <p>{error}</p>
                            <Button
                                variant="outline"
                                onClick={() => {
                                    setError('');
                                    setAttempt((value) => value + 1);
                                }}
                            >
                                <RotateCcw data-icon="inline-start" />
                                Riprova
                            </Button>
                        </AlertDescription>
                    </Alert>
                ) : preview ? (
                    <>
                        {preview.warnings.length > 0 && (
                            <Alert>
                                <AlertTitle>
                                    Alcune immagini non sono disponibili
                                </AlertTitle>
                                <AlertDescription>
                                    Nel PDF sono sostituite dal nome del
                                    dispositivo: {preview.warnings.join(', ')}.
                                </AlertDescription>
                            </Alert>
                        )}
                        <iframe
                            title="Anteprima documentazione infrastruttura rack"
                            src={preview.url}
                            className="h-[75vh] min-h-96 w-full rounded-xl border bg-muted"
                        />
                    </>
                ) : (
                    <div
                        className="flex flex-col gap-4"
                        role="status"
                        aria-live="polite"
                    >
                        <p className="flex items-center gap-2 text-sm text-muted-foreground">
                            <LoaderCircle className="size-4 motion-safe:animate-spin" />
                            Preparazione di inventario, tavole rack e
                            collegamenti…
                        </p>
                        <Skeleton className="h-[65vh] w-full rounded-xl" />
                    </div>
                )}
            </div>
        </>
    );
}

Documentation.layout = ({ company, site, rack }: RackDocumentProps) => ({
    breadcrumbs: [
        { title: 'Rack', href: index({ company: company.id, site: site.id }) },
        ...(rack
            ? [
                  {
                      title: rack.name,
                      href: show({
                          company: company.id,
                          site: site.id,
                          rack: rack.id,
                      }),
                  },
              ]
            : []),
        { title: 'Documentazione', href: '#' },
    ],
});
