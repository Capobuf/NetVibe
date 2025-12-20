<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum DeviceType: string implements HasLabel
{
    case Router = 'router';
    case Switch = 'switch';
    case Firewall = 'firewall';
    case Server = 'server';
    case PDU = 'pdu';
    case Other = 'other';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Router => 'Router',
            self::Switch => 'Switch',
            self::Firewall => 'Firewall',
            self::Server => 'Server',
            self::PDU => 'PDU',
            self::Other => 'Other',
        };
    }

    /**
     * Return a semantic color key used for UI purposes.
     * Values: danger, warning, info, success, gray
     */
    public function getColor(): string
    {
        return match ($this) {
            self::Firewall => 'danger',
            self::Router => 'warning',
            self::Switch => 'info',
            self::Server => 'success',
            default => 'gray',
        };
    }
}
