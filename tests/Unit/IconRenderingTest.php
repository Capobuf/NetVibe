<?php

namespace Tests\Unit;

use Filament\Support\generate_icon_html;
use Illuminate\Support\HtmlString;
use Tests\TestCase;

class IconRenderingTest extends TestCase
{
    public function test_generate_icon_html_returns_html_for_valid_heroicon()
    {
        // This should not throw SvgNotFound for a valid icon name
        $html = generate_icon_html('heroicon-s-question-mark-circle');

        $this->assertInstanceOf(HtmlString::class, $html);
        $this->assertNotEmpty((string) $html);
    }
}
