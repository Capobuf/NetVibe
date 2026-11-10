import { Link } from '@inertiajs/react';
import {
    ArrowLeft,
    Download,
    LoaderCircle,
    Printer,
    RotateCcw,
} from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { IconButton } from '@/components/icon-button';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';

export type PdfDocument = {
    blob: Blob;
    filename: string;
    pages: number;
    warnings: string[];
    /** Page the preview opens on. */
    page?: number;
};
type Preview = Omit<PdfDocument, 'blob'> & { url: string };

/**
 * Shared print workspace: options on the left, the generated PDF on the right.
 * Print and download always use the very PDF shown in the preview.
 */
export function PrintWorkspace({
    title,
    context,
    back,
    generate,
    problem,
    notice,
    children,
}: {
    title: string;
    context: string;
    back: { href: string; label: string };
    generate: () => Promise<PdfDocument>;
    problem: string | null;
    notice?: (preview: Preview) => ReactNode;
    children: ReactNode;
}) {
    const [preview, setPreview] = useState<Preview | null>(null);
    const [error, setError] = useState('');
    const [generated, setGenerated] = useState<unknown>(null);
    const [attempt, setAttempt] = useState(0);
    const url = useRef<string | null>(null);
    const frame = useRef<HTMLIFrameElement>(null);
    const pending = !problem && generated !== generate;

    useEffect(() => {
        if (problem) return;
        let cancelled = false;
        // Typing in a field should not rebuild the PDF on every keystroke.
        const timer = setTimeout(
            () =>
                generate()
                    .then(({ blob, ...result }) => {
                        if (cancelled) return;
                        if (url.current) URL.revokeObjectURL(url.current);
                        url.current = URL.createObjectURL(blob);
                        setPreview({ ...result, url: url.current });
                        setError('');
                    })
                    .catch((reason: unknown) => {
                        if (cancelled) return;
                        setError(
                            reason instanceof Error
                                ? reason.message
                                : 'Generazione del PDF non riuscita. Riprova.',
                        );
                    })
                    .finally(() => {
                        if (!cancelled) setGenerated(() => generate);
                    }),
            url.current ? 350 : 0,
        );
        return () => {
            cancelled = true;
            clearTimeout(timer);
        };
    }, [generate, problem, attempt]);
    useEffect(
        () => () => {
            if (url.current) URL.revokeObjectURL(url.current);
        },
        [],
    );

    function print() {
        const target = frame.current?.contentWindow;
        try {
            if (!target) throw new Error();
            target.focus();
            target.print();
        } catch {
            // Some browsers keep the embedded PDF viewer out of reach: print from its own tab.
            if (preview) window.open(preview.url, '_blank', 'noopener');
        }
    }

    const ready = preview && !pending && !problem && !error;
    return (
        <div className="flex min-w-0 flex-col gap-4 p-4 md:p-6 lg:h-[calc(100dvh-4rem)] lg:min-h-150">
            <header className="flex flex-wrap items-center justify-between gap-4">
                <div className="flex min-w-0 items-center gap-3">
                    <IconButton label={back.label} asChild>
                        <Link href={back.href}>
                            <ArrowLeft />
                        </Link>
                    </IconButton>
                    <div className="min-w-0">
                        <p className="truncate text-sm text-muted-foreground">
                            {context}
                        </p>
                        <h1 className="truncate text-2xl font-medium tracking-tight">
                            {title}
                        </h1>
                    </div>
                    {preview && (
                        <Badge variant="secondary">
                            {preview.pages}{' '}
                            {preview.pages === 1 ? 'pagina' : 'pagine'}
                        </Badge>
                    )}
                </div>
                <div className="flex flex-wrap gap-2">
                    <Button variant="outline" disabled={!ready} onClick={print}>
                        <Printer data-icon="inline-start" />
                        Stampa
                    </Button>
                    {ready ? (
                        <Button asChild>
                            <a href={preview.url} download={preview.filename}>
                                <Download data-icon="inline-start" />
                                Scarica PDF
                            </a>
                        </Button>
                    ) : (
                        <Button disabled>
                            <Download data-icon="inline-start" />
                            Scarica PDF
                        </Button>
                    )}
                </div>
            </header>
            {(problem || error) && (
                <Alert variant="destructive">
                    <AlertTitle>
                        {problem
                            ? 'Completa le opzioni di stampa'
                            : 'Impossibile preparare il documento'}
                    </AlertTitle>
                    <AlertDescription>
                        <p>{problem ?? error}</p>
                        {!problem && (
                            <Button
                                variant="outline"
                                onClick={() => {
                                    setGenerated(null);
                                    setAttempt((value) => value + 1);
                                }}
                            >
                                <RotateCcw data-icon="inline-start" />
                                Riprova
                            </Button>
                        )}
                    </AlertDescription>
                </Alert>
            )}
            {preview && notice?.(preview)}
            <div className="grid min-h-0 flex-1 gap-4 lg:grid-cols-[24rem_minmax(0,1fr)]">
                <aside
                    aria-label="Opzioni di stampa"
                    className="flex min-h-0 flex-col gap-3 rounded-xl border p-2 lg:overflow-y-auto"
                >
                    {children}
                </aside>
                <div className="relative h-[80dvh] min-h-96 overflow-hidden rounded-xl border bg-muted lg:h-auto">
                    {preview ? (
                        <iframe
                            ref={frame}
                            title={`Anteprima ${title}`}
                            src={`${preview.url}#page=${preview.page ?? 1}`}
                            className="size-full"
                        />
                    ) : (
                        !error && (
                            <Skeleton className="size-full rounded-none" />
                        )
                    )}
                    {pending && (
                        <p
                            role="status"
                            className="absolute top-3 left-1/2 flex -translate-x-1/2 items-center gap-2 rounded-full border bg-background px-3 py-1 text-sm shadow-sm"
                        >
                            <LoaderCircle className="size-4 motion-safe:animate-spin" />
                            {preview
                                ? 'Aggiornamento anteprima…'
                                : 'Preparazione del PDF…'}
                        </p>
                    )}
                </div>
            </div>
        </div>
    );
}
