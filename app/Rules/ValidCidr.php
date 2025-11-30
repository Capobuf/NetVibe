<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use IPLib\Factory;

class ValidCidr implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            $range = Factory::parseRangeString($value);
            if (! $range) {
                $fail('Invalid CIDR format');
                return;
            }

            $start = method_exists($range, 'getStartAddress') ? $range->getStartAddress() : ($range->getStart() ?? null);
            if (! $start) {
                $fail('Invalid CIDR range');
                return;
            }

            $startIp = method_exists($start, 'toString') ? $start->toString() : (string) $start;
            $parts = explode('/', $value);
            $ipPart = trim($parts[0] ?? '');

            if ($ipPart !== $startIp) {
                $mask = $parts[1] ?? null;
                $suggestion = $startIp . ($mask ? "/{$mask}" : '');
                $fail("This is not a valid network address. Did you mean {$suggestion}?");
            }
        } catch (\Throwable $e) {
            $fail('Invalid CIDR');
        }
    }
}
