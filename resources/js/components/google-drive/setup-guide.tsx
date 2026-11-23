import { Check } from 'lucide-react';
import type { ReactNode } from 'react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Progress } from '@/components/ui/progress';
import { cn } from '@/lib/utils';

export type SetupStep = {
    title: string;
    status: string;
    done: boolean;
    action?: ReactNode;
};

/** Checklist shown until the integration is usable; the first open step carries the main action. */
export function SetupGuide({ steps }: { steps: SetupStep[] }) {
    const completed = steps.filter((step) => step.done).length;
    const current = steps.findIndex((step) => !step.done);

    return (
        <Card>
            <CardHeader className="flex-row flex-wrap items-center justify-between gap-4">
                <CardTitle>Configurazione</CardTitle>
                <div className="flex min-w-40 items-center gap-3">
                    <Progress
                        value={(completed / steps.length) * 100}
                        className="h-1.5"
                        aria-label="Avanzamento della configurazione"
                    />
                    <span className="text-sm whitespace-nowrap text-muted-foreground tabular-nums">
                        {completed} di {steps.length}
                    </span>
                </div>
            </CardHeader>
            <CardContent>
                <ol className="flex flex-col">
                    {steps.map((step, index) => (
                        <li
                            key={step.title}
                            className="relative flex gap-4 pb-6 last:pb-0"
                        >
                            {index < steps.length - 1 ? (
                                <span
                                    aria-hidden="true"
                                    className={cn(
                                        'absolute top-8 bottom-0 left-3.5 w-px',
                                        step.done ? 'bg-emerald-500/60' : 'bg-border',
                                    )}
                                />
                            ) : null}
                            <span
                                aria-hidden="true"
                                className={cn(
                                    'relative z-10 flex size-7 shrink-0 items-center justify-center rounded-full border text-xs font-medium tabular-nums',
                                    step.done &&
                                        'border-emerald-500 bg-emerald-500 text-white',
                                    index === current &&
                                        'border-primary bg-primary text-primary-foreground',
                                    !step.done &&
                                        index !== current &&
                                        'bg-background text-muted-foreground',
                                )}
                            >
                                {step.done ? (
                                    <Check className="size-4" />
                                ) : (
                                    index + 1
                                )}
                            </span>
                            <div className="flex min-w-0 flex-1 flex-wrap items-center justify-between gap-3">
                                <div className="flex min-w-0 flex-col gap-0.5">
                                    <span
                                        className={cn(
                                            'text-sm font-medium',
                                            !step.done &&
                                                index !== current &&
                                                'text-muted-foreground',
                                        )}
                                    >
                                        {step.title}
                                        <span className="sr-only">
                                            {step.done
                                                ? ' (completato)'
                                                : ' (da completare)'}
                                        </span>
                                    </span>
                                    <span className="text-sm break-words text-muted-foreground">
                                        {step.status}
                                    </span>
                                </div>
                                {!step.done && step.action ? (
                                    <div className="shrink-0">{step.action}</div>
                                ) : null}
                            </div>
                        </li>
                    ))}
                </ol>
            </CardContent>
        </Card>
    );
}
