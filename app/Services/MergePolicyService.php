<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DiscoverySource;
use App\Models\Device;
use App\Models\IpAddress;

/**
 * MergePolicyService
 *
 * Implementa le regole di merge di alto livello fra stato locale (DB)
 * e stato remoto (device_result) per Device, IP, ecc.
 *
 * Regole PDL (Fase 1):
 * - LOCKED beats remote → se esiste un campo *_locked true, il valore remoto viene ignorato.
 * - REST beats SNMP → in caso di conflitto fra sorgenti, REST ha priorità su SNMP.
 * - Newest wins → in futuro si potrà usare un timestamp per decidere quale valore è più recente.
 *
 * Nota: per Fase 1 implementiamo un sottoinsieme minimo delle regole, senza generare ancora
 * DiscoveryEvent strutturati; gli eventi vengono modellati come TODO nella collezione events.
 */
class MergePolicyService
{
    public function mergeDevice(
        Device $device,
        array $local,
        array $remote,
        DiscoverySource $source
    ): MergeResult {
        $result = new MergeResult();

        // Campi supportati in Fase 1: hostname, serial_number.
        $this->mergeFieldWithLock(
            model: $device,
            local: $local,
            remote: $remote,
            field: 'hostname',
            remoteKey: 'hostname',
            lockField: 'hostname_locked',
            result: $result,
        );

        $this->mergeFieldWithLock(
            model: $device,
            local: $local,
            remote: $remote,
            field: 'serial_number',
            remoteKey: 'serial_number',
            lockField: 'serial_locked',
            result: $result,
        );

        // TODO: in Fase 2 estendere a brand/model/location ecc.

        return $result;
    }

    public function mergeIpAddress(
        IpAddress $ip,
        array $local,
        array $remote,
        DiscoverySource $source
    ): MergeResult {
        $result = new MergeResult();

        // Campi supportati in Fase 1: mac_address, hostname.
        $this->mergeFieldWithLock(
            model: $ip,
            local: $local,
            remote: $remote,
            field: 'mac_address',
            remoteKey: 'mac_address',
            lockField: 'mac_locked', // TODO: campo lock da introdurre in futuro
            result: $result,
        );

        $this->mergeFieldWithLock(
            model: $ip,
            local: $local,
            remote: $remote,
            field: 'hostname',
            remoteKey: 'hostname',
            lockField: 'hostname_locked',
            result: $result,
        );

        // TODO: applicare policy REST vs SNMP quando avremo doppie sorgenti e timestamp.

        return $result;
    }

    /**
     * Helper generico per gestire merge di un singolo campo con supporto locking.
     *
     * @param  object  $model  Device o IpAddress (o simili) che espone le proprietà.
     */
    protected function mergeFieldWithLock(
        object $model,
        array $local,
        array $remote,
        string $field,
        string $remoteKey,
        string $lockField,
        MergeResult $result,
    ): void {
        // Se il campo remoto non è presente, niente da fare.
        if (! array_key_exists($remoteKey, $remote)) {
            return;
        }

        $remoteValue = $remote[$remoteKey];

        // Se esiste un campo lock e vale true, non sovrascriviamo.
        $isLocked = property_exists($model, $lockField) ? (bool) ($model->{$lockField} ?? false) : false;
        if ($isLocked) {
            $result->addSkippedField($field, 'locked');
            return;
        }

        $current = $local[$field] ?? null;

        // Se uguale, niente da fare.
        if ($current === $remoteValue) {
            return;
        }

        // Remote vince (regola base Fase 1).
        $model->{$field} = $remoteValue;
        $result->addUpdatedField($field, $current, $remoteValue);
    }
}

