<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class UnifiException extends RuntimeException implements ShouldntReport
{
    public function __construct(public readonly string $status)
    {
        parent::__construct(self::messageFor($status));
    }

    public static function messageFor(string $status): string
    {
        return match ($status) {
            'ok' => 'Connessione verificata.',
            'unauthorized' => 'Chiave API non valida o revocata (401).',
            'forbidden' => 'Permessi insufficienti (403). Verifica l’accesso della chiave API alla console.',
            'not_found' => 'Risorsa API non disponibile (404). Verifica versione e console selezionata.',
            'unsupported' => 'Non supportato dalla versione API rilevata.',
            'tls' => 'Certificato HTTPS non verificabile. Controlla nome, scadenza e catena del certificato.',
            'network' => 'Controller non raggiungibile. Verifica indirizzo, porta, DNS e firewall.',
            'timeout' => 'Tempo di lettura esaurito. Riprova quando il controller è raggiungibile.',
            'rate_limit' => 'Troppe richieste. Attendi un minuto e riprova.',
            'redirect' => 'Reindirizzamento rifiutato. Inserisci l’indirizzo HTTPS finale del controller.',
            'unsafe_url' => 'Destinazione non consentita. Usa HTTPS con IPv4 o FQDN, senza percorsi o credenziali.',
            'suspended' => 'Connessione sospesa. Riattivala prima della verifica.',
            'limit' => 'Limite di lettura raggiunto. L’ultima lettura valida è stata conservata.',
            'http_error' => 'Errore del servizio UniFi. Riprova più tardi.',
            default => 'Risposta UniFi incompleta o in formato inatteso.',
        };
    }
}
