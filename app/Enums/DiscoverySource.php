<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Origine dei dati di discovery per MergePolicyService.
 *
 * Manteniamo un set minimale coerente con il PDL.
 */
enum DiscoverySource: string
{
    case Rest = 'rest';
    case Snmp = 'snmp';
    case Controller = 'controller';
    case Agent = 'agent';
}

