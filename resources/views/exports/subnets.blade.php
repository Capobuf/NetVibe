@php
    /** @var \Illuminate\Support\Collection|\App\Models\Subnet[] $records */
    $records = $records ?? collect();
    $siteName = app(\App\Settings\GeneralSettings::class)->site_name ?? 'NetVibe';
@endphp

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Subnets export - {{ $siteName }}</title>
    <style>
        body { font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; font-size: 12px; }
        h1 { font-size: 18px; margin-bottom: 8px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border: 1px solid #e5e7eb; padding: 4px 6px; text-align: left; }
        th { background: #f3f4f6; font-weight: 600; }
        .meta { font-size: 11px; color: #6b7280; }
    </style>
</head>
<body>
    <h1>Subnets export</h1>
    <div class="meta">
        <div>Instance: {{ $siteName }}</div>
        <div>Generated at: {{ now()->toIso8601String() }}</div>
    </div>

    <table>
        <thead>
            <tr>
                <th>CIDR</th>
                <th>VLAN</th>
                <th>Customer</th>
                <th>Location</th>
                <th>Description</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($records as $subnet)
                <tr>
                    <td>{{ $subnet->cidr }}</td>
                    <td>
                        @if($subnet->vlan)
                            {{ $subnet->vlan->number }} {{ $subnet->vlan->name ? ' - '.$subnet->vlan->name : '' }}
                        @endif
                    </td>
                    <td>{{ $subnet->customer?->name }}</td>
                    <td>{{ $subnet->location?->name }}</td>
                    <td>{{ $subnet->description }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5">No subnets found.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>

