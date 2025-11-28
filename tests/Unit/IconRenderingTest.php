<?php

namespace Tests\Unit;

use function Filament\Support\generate_icon_html;
use Illuminate\Contracts\Support\Htmlable;
use Tests\TestCase;

class IconRenderingTest extends TestCase
{
    public function test_generate_icon_html_returns_html_for_valid_heroicon()
    {
        // This should not throw SvgNotFound for a valid icon name
        $html = generate_icon_html('heroicon-s-question-mark-circle');

        $this->assertInstanceOf(Htmlable::class, $html);
        // BladeUI Svg objects implement toHtml(); HtmlString also supports toHtml(). Use toHtml() to get string output.
        $this->assertNotEmpty($html->toHtml());
    }
}
