import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowRight, Network } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardHeader,
    CardTitle,
    CardDescription,
    CardFooter,
} from '@/components/ui/card';
import { login, register } from '@/routes';
import { index as companies } from '@/routes/companies';

export default function Welcome() {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="NetVibe" />
            <main className="flex min-h-svh items-center justify-center bg-muted/30 p-4 sm:p-8">
                <Card className="w-full max-w-md">
                    <CardHeader className="gap-4">
                        <Network
                            className="size-10 text-primary"
                            aria-hidden="true"
                        />
                        <CardTitle>
                            <h1 className="text-3xl tracking-tight">NetVibe</h1>
                        </CardTitle>
                        <CardDescription>
                            Aziende, reti e indirizzi IP.
                        </CardDescription>
                    </CardHeader>
                    <CardFooter className="flex-col items-stretch gap-3">
                        {auth.user ? (
                            <Button asChild>
                                <Link href={companies()}>
                                    Apri aziende <ArrowRight />
                                </Link>
                            </Button>
                        ) : (
                            <>
                                <Button asChild>
                                    <Link href={login()}>
                                        Accedi <ArrowRight />
                                    </Link>
                                </Button>
                                <Button variant="outline" asChild>
                                    <Link href={register()}>Registrati</Link>
                                </Button>
                            </>
                        )}
                    </CardFooter>
                </Card>
            </main>
        </>
    );
}
