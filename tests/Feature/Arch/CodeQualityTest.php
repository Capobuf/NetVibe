<?php

/**
 * Architecture / quality rules to prevent namespace hallucinations and legacy
 * code creeping back into the codebase.
 *
 * Note: The `Pest\Arch\arch()` helper isn't available in this environment by
 * default. Implement the checks here with file-scans so the tests run reliably
 * in CI and local containers.
 */

test('codebase does not use incorrect Section namespace', function () {
    $files = \Illuminate\Support\Facades\File::allFiles(app_path());

    foreach ($files as $file) {
        $path = $file->getRealPath();

        if (! str_ends_with($path, '.php')) {
            continue;
        }

        $content = file_get_contents($path);

        // Sections are layout components and belong to Filament\Schemas\Components
        // If someone imported Section from Forms it's wrong and will cause Class not found
        if (str_contains($content, 'Filament\\Forms\\Components\\Section')) {
            $this->fail("Found incorrect Section namespace 'Filament\\Forms\\Components\\Section' in file: " . $file->getRelativePathname());
        }

        // Check for Infolists Section which also doesn't exist in v4
        if (str_contains($content, 'Filament\\Infolists\\Components\\Section')) {
             $this->fail("Found incorrect Section namespace 'Filament\\Infolists\\Components\\Section' in file: " . $file->getRelativePathname());
        }
    }

    $this->assertTrue(true);
});

test('codebase does not use deprecated Card component', function () {
    $files = \Illuminate\Support\Facades\File::allFiles(app_path());

    foreach ($files as $file) {
        $path = $file->getRealPath();

        if (! str_ends_with($path, '.php')) {
            continue;
        }

        $content = file_get_contents($path);

        if (str_contains($content, 'Filament\\Forms\\Components\\Card') || str_contains($content, 'Card::make')) {
            $this->fail("Found banned 'Card' usage in file: " . $file->getRelativePathname());
        }
    }
    $this->assertTrue(true);
});

test('codebase does not contain legacy is_gateway column reference', function () {
    $files = \Illuminate\Support\Facades\File::allFiles(app_path());

    foreach ($files as $file) {
        $content = file_get_contents($file->getRealPath());

        if (str_contains($content, "'is_gateway'") || str_contains($content, '"is_gateway"')) {
            $this->fail("Found legacy 'is_gateway' in file: " . $file->getRelativePathname());
        }
    }
    $this->assertTrue(true);
});

test('codebase does not use non-existent TextEntrySize enum', function () {
    $files = \Illuminate\Support\Facades\File::allFiles(app_path());

    foreach ($files as $file) {
        $path = $file->getRealPath();

        if (! str_ends_with($path, '.php')) {
            continue;
        }

        $content = file_get_contents($path);

        // TextEntrySize enum doesn't exist in Filament v4
        if (str_contains($content, 'TextEntry\\TextEntrySize') || str_contains($content, 'TextEntrySize::')) {
            $this->fail("Found non-existent TextEntrySize enum usage in file: " . $file->getRelativePathname());
        }
    }
    $this->assertTrue(true);
});

test('subnet view uses correct component namespaces', function () {
    $subnetResource = file_get_contents(app_path('Filament/Clusters/Ipam/Resources/Subnets/SubnetResource.php'));
    
    // Ensure infolist uses correct Section from Schemas, not Infolists
    $this->assertStringContainsString('Filament\\Schemas\\Components\\Section', $subnetResource);
    
    // Ensure TextEntry comes from Infolists
    $this->assertStringContainsString('Filament\\Infolists\\Components\\TextEntry', $subnetResource);
    
    // Ensure no usage of non-existent classes
    $this->assertStringNotContainsString('Filament\\Infolists\\Components\\Section', $subnetResource);
    $this->assertStringNotContainsString('TextEntrySize', $subnetResource);
});

test('subnet view page has required methods', function () {
    $viewSubnet = file_get_contents(app_path('Filament/Clusters/Ipam/Resources/Subnets/Pages/ViewSubnet.php'));
    
    // Ensure Edit action is present
    $this->assertStringContainsString('getHeaderActions', $viewSubnet);
    $this->assertStringContainsString('EditAction::make()', $viewSubnet);
    // Ensure Delete action is NOT present on the view page (delete allowed only on Edit page)
    $this->assertStringNotContainsString('DeleteAction::make()', $viewSubnet);

    // Ensure the delete action exists on the edit page and contains confirmation modal config
    $editSubnet = file_get_contents(app_path('Filament/Clusters/Ipam/Resources/Subnets/Pages/EditSubnet.php'));
    $this->assertStringContainsString('DeleteAction::make()', $editSubnet);
    $this->assertStringContainsString("modalHeading('Delete Subnet')", $editSubnet);
    $this->assertStringContainsString('modalDescription', $editSubnet);
    
    // Ensure Visual Grid widget is not used in the Subnet view (we replaced it with the IP Table UX)
    $this->assertStringNotContainsString('IpAddressVisualGrid::class', $viewSubnet);
});

test('relation managers and table resources do not expose DeleteAction in record actions', function () {
    $ipRel = file_get_contents(app_path('Filament/Clusters/Ipam/Resources/Subnets/RelationManagers/IpAddressesRelationManager.php'));
    $this->assertStringNotContainsString('DeleteAction::make()', $ipRel, 'Relation manager should not expose DeleteAction in recordActions');

    $customers = file_get_contents(app_path('Filament/Clusters/Organization/Resources/Customers/CustomerResource.php'));
    $this->assertStringNotContainsString('DeleteAction::make()', $customers, 'CustomerResource table should not include DeleteAction as a record action');

    $locations = file_get_contents(app_path('Filament/Clusters/Organization/Resources/Locations/LocationResource.php'));
    $this->assertStringNotContainsString('DeleteAction::make()', $locations, 'LocationResource table should not include DeleteAction as a record action');

    $this->assertTrue(true);
});

test('subnet resource has required configuration', function () {
    $subnetResource = file_get_contents(app_path('Filament/Clusters/Ipam/Resources/Subnets/SubnetResource.php'));
    
    // Ensure VRF field has dehydratedWhenHidden
    $this->assertStringContainsString('dehydratedWhenHidden', $subnetResource);
    
    // Ensure ValidCidr rule is used
    $this->assertStringContainsString('ValidCidr', $subnetResource);
    
    // Ensure infolist method exists
    $this->assertStringContainsString('public static function infolist', $subnetResource);
});

test('rack table name column links to view page', function () {
    $racksTable = file_get_contents(app_path('Filament/Clusters/Racks/Resources/Racks/Tables/RacksTable.php'));

    // The name column should link to the resource "view" page rather than edit
    $this->assertStringContainsString("RackResource::getUrl('view'", $racksTable);
});

test('clusters do not depend on root resources', function () {
    $files = \Illuminate\Support\Facades\File::allFiles(app_path('Filament/Clusters'));

    foreach ($files as $file) {
        $content = file_get_contents($file->getRealPath());

        if (str_contains($content, 'App\\Filament\\Resources')) {
            $this->fail("Found forbidden dependency 'App\\Filament\\Resources' in file: " . $file->getRelativePathname());
        }
    }
    $this->assertTrue(true);
});
