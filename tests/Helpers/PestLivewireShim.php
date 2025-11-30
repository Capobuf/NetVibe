<?php

namespace Pest\Livewire;

// Simple shim for environments where the pest livewire plugin isn't present.
if (! function_exists(__NAMESPACE__ . '\\livewire')) {
    function livewire($component, $params = []) {
        return \Livewire\Livewire::test($component, $params);
    }
}
