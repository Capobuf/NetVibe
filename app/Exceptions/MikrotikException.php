<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class MikrotikException extends RuntimeException implements ShouldntReport
{
    public function __construct(public readonly string $status)
    {
        parent::__construct(self::messageFor($status));
    }

    public static function messageFor(string $status): string
    {
        return match ($status) {
            'ok' => 'Connessione verificata.',
            'unauthorized' => 'Credenziali non valide (401). Verifica username e password.',
            'forbidden' => 'Permessi insufficienti (403). Verifica le policy read, api e rest-api dell’utente RouterOS.',
            'not_found' => 'Menu non disponibile (404). Verifica versione, piattaforma e abilitazione REST sul servizio www-ssl.',
            'tls' => 'Errore TLS. Controlla nome, scadenza e catena del certificato e i protocolli HTTPS del dispositivo.',
            'timeout' => 'Tempo di lettura esaurito. Verifica raggiungibilità e tempi di risposta del dispositivo.',
            'network' => 'Dispositivo non raggiungibile. Verifica indirizzo, porta, DNS e firewall dal server NetVibe.',
            'redirect' => 'Reindirizzamento rifiutato. Inserisci l’indirizzo HTTPS finale del dispositivo.',
            'unsafe_url' => 'Destinazione non consentita. Usa HTTPS con IPv4 o FQDN, senza percorsi, credenziali o parametri.',
            'rate_limit' => 'Troppe richieste. Attendi un minuto e riprova.',
            'limit' => 'Risposta oltre il limite di lettura. Il menu non è stato acquisito.',
            'not_json' => 'Risposta non JSON. Verifica porta e servizio REST www-ssl.',
            'version' => 'Versione RouterOS non supportata da questa ricognizione: è richiesta la versione 7.',
            'partial' => 'Lettura parziale. Controlla gli esiti dei singoli menu.',
            'http_error' => 'Errore HTTP del dispositivo. Verifica il servizio REST e riprova.',
            'not_read' => 'Non interrogato: lettura interrotta prima di questo menu.',
            default => 'Risposta RouterOS incompleta o in formato inatteso.',
        };
    }
}
