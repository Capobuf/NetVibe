<?php

use App\Filament\Clusters\Ipam\Resources\Subnets\SubnetResource;

it('getEloquentQuery eager loads location and includes ip_addresses_count', function () {
    $builder = SubnetResource::getEloquentQuery();

    // Check eager loads
    $eager = $builder->getEagerLoads();
    expect(array_key_exists('location', $eager))->toBeTrue();
    expect(array_key_exists('vlan', $eager))->toBeTrue();
    expect(array_key_exists('customer', $eager))->toBeTrue();

    // The underlying query columns should include the ip_addresses_count alias
    $cols = $builder->getQuery()->columns;
    $found = false;
    if (is_array($cols)) {
        foreach ($cols as $col) {
            if (is_string($col) && str_contains($col, 'ip_addresses_count')) {
                $found = true; break;
            }

            if (is_object($col)) {
                // Try to reflect into Expression->value if present
                try {
                    $r = new ReflectionObject($col);
                    if ($r->hasProperty('value')) {
                        $p = $r->getProperty('value');
                        $p->setAccessible(true);
                        $val = (string) $p->getValue($col);
                        if (str_contains($val, 'ip_addresses_count')) { $found = true; break; }
                    }
                } catch (Throwable $_) {
                    // ignore — handle other types defensively
                }
            }
        }
    }

    expect($found)->toBeTrue();
});

it('file contains VLAN group title helper, location column and utilization formatting', function () {
    $file = file_get_contents(app_path('Filament/Clusters/Ipam/Resources/Subnets/SubnetResource.php'));

    expect(strpos($file, 'getTitleFromRecordUsing') !== false)->toBeTrue();
    expect(strpos($file, "location.name") !== false)->toBeTrue();
    expect(strpos($file, 'utilization_summary') !== false)->toBeTrue();
    // Ensure we don't use the literal string ' IPs' in the utilization formatting
    expect(strpos($file, "' IPs'") === false)->toBeTrue();
});
