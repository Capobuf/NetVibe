# Visual Rack - Manual verification

## 1) Dockerfile check

The container image used for the app includes `fping` already: `docker/php/Dockerfile` installs `fping` via apk.

## 2) Manual verification steps (inside the docker container)

Open a shell in the `app` container and run Tinker to add a rack and devices:

```powershell
# start a shell in the app container
docker-compose exec app sh

# OR run directly with artisan tinker
docker-compose exec app php artisan tinker
```

In tinker (or via Filament UI) create objects to test the visual representation:

```php
// create required customer/location
$customer = App\Models\Customer::create(['name' => 'ACME Inc.']);
$location = App\Models\Location::create(['customer_id' => $customer->id, 'name' => 'HQ']);

// device models
$m1 = App\Models\DeviceModel::create(['brand' => 'Acme', 'model' => 'Model-2U', 'type' => 'server', 'u_size' => 2]);
$m2 = App\Models\DeviceModel::create(['brand' => 'Foo', 'model' => 'Model-1U', 'type' => 'switch', 'u_size' => 1]);

// rack
$rack = App\Models\Rack::create(['name' => 'Rack-42U', 'location_id' => $location->id]);

// devices: device at position 10 size 2, device at 12 size 1
App\Models\Device::create(['hostname' => 'dev-1', 'device_model_id' => $m1->id, 'rack_id' => $rack->id, 'rack_position' => 10]);
App\Models\Device::create(['hostname' => 'dev-2', 'device_model_id' => $m2->id, 'rack_id' => $rack->id, 'rack_position' => 12]);
```

Now open Filament and navigate to the Rack View page (Records -> Racks -> view the newly created Rack). You should see a 42U stack with the devices displayed at their positions (device starting at 10 spanning 2U and another at 12).

### Interactivity & Quick actions

The Rack Visualization is now interactive:

- Device blocks are color-coded according to DeviceType (Firewall/Router/Switch/Server/Other). The color mapping comes from `App\Enums\DeviceType::getColor()` and the view maps those to Tailwind UI classes.
- Clicking a device opens its Edit page (uses `DeviceResource::getUrl('edit', ['record' => $id])`).
- Clicking an empty U slot opens the Device Create page with `rack_id` and `rack_position` pre-filled via URL query params — the Create form reads those query params and defaults the form fields accordingly.

This behaviour makes it fast to place devices into a rack from the visual representation.

If you prefer automated verification, the test `tests/Unit/RackVisualTest.php` validates the algorithm for the unit layout.

## 3) Notes
- The infolist entry `RackVisual` renders device blocks only at their starting (bottom) U, and marks >1U upper units as "spanned" so that the visual shows correctly sized devices without duplication.
- The view page is available at the Rack resource view (Filament UI) once you navigate to the record.

## 4) Dashboard

I also added a system Dashboard under Filament -> Dashboard with two widgets:

- `StatsOverview` — shows counts (Customers, Subnets, IP Addresses, Devices (Active/Total)).
- `LatestScanResultsWidget` — lists the most recently scanned subnets (last_discovery not null) and found hosts count.

You can verify the Dashboard by creating some subnets with `last_discovery` data or scanning via the FpingWrapper test harness.
