<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Collection;

/**
 * Risultato di una operazione di merge fra stato locale e remoto.
 *
 * - updatedFields: campi effettivamente cambiati (local → remote).
 * - skippedFields: campi saltati (es. locked, conflict, policy).
 * - events: collezione di eventi di discovery/audit (modellati come array per ora).
 */
class MergeResult
{
    /**
     * @var array<string, array{old:mixed,new:mixed}>
     */
    public array $updatedFields = [];

    /**
     * @var array<string, string> motivo per cui il campo è stato saltato
     */
    public array $skippedFields = [];

    /**
     * @var \Illuminate\Support\Collection<int, array> eventi strutturati (DiscoveryEvent-ready)
     */
    public Collection $events;

    public function __construct()
    {
        $this->events = new Collection();
    }

    /**
     * Registra un campo aggiornato.
     */
    public function addUpdatedField(string $field, mixed $old, mixed $new): void
    {
        $this->updatedFields[$field] = [
            'old' => $old,
            'new' => $new,
        ];
    }

    /**
     * Registra un campo saltato (locked, conflict, policy).
     */
    public function addSkippedField(string $field, string $reason): void
    {
        $this->skippedFields[$field] = $reason;
    }
}

