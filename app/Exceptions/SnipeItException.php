<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class SnipeItException extends RuntimeException implements ShouldntReport
{
    public function __construct(public readonly string $status)
    {
        parent::__construct(self::messageFor($status));
    }

    public static function messageFor(string $status): string
    {
        return match ($status) {
            'ok' => 'Accesso ad aziende e hardware verificato.',
            'unauthorized' => 'API key non valida o scaduta (401).',
            'forbidden' => 'Permessi insufficienti (403). Verifica l’utente del token in Snipe-IT.',
            'rate_limit' => 'Limite di richieste raggiunto (429). Attendi e riprova.',
            'network' => 'Connessione non riuscita: verifica rete, timeout e certificato TLS.',
            'html' => 'Il server ha restituito HTML anziché JSON. Verifica l’URL dell’installazione.',
            'api_error' => 'Snipe-IT ha restituito un errore applicativo. Verifica credenziali e permessi.',
            'redirect' => 'Il server richiede un reindirizzamento. Inserisci l’URL HTTPS finale dell’installazione.',
            'unsafe_url' => 'Destinazione non consentita. Usa un server HTTPS, senza credenziali, parametri o frammenti nell’URL.',
            'limit' => 'Risposta troppo grande o elenco incompleto. Riduci le aziende visibili al token e riprova.',
            'missing_company' => 'La chiave non vede tutte le aziende già associate. Le credenziali precedenti sono state mantenute.',
            'http_error' => 'Il server ha restituito un errore HTTP. Verifica URL e servizio Snipe-IT.',
            default => 'Formato della risposta Snipe-IT inatteso.',
        };
    }
}
