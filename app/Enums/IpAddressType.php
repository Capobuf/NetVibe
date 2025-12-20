<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum IpAddressType: string implements HasLabel, HasColor, HasIcon
{
    case Gateway = 'gateway';
    case Host = 'host';
    case DHCP = 'dhcp';
    case Reserved = 'reserved';
    case Broadcast = 'broadcast';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Gateway => 'Gateway',
            self::Host => 'Host',
            self::DHCP => 'DHCP',
            self::Reserved => 'Reserved',
            self::Broadcast => 'Broadcast',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Gateway => 'primary',
            self::Host => 'success',
            self::DHCP => 'gray',
            self::Reserved => 'warning',
            self::Broadcast => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Gateway => 'heroicon-o-arrows-right-left',
            self::Host => 'heroicon-o-computer-desktop',
            self::DHCP => 'heroicon-o-arrow-path',
            self::Reserved => 'heroicon-o-lock-closed',
            self::Broadcast => 'heroicon-o-signal',
        };
    }
}
