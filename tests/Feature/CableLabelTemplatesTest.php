<?php

namespace Tests\Feature;

use App\Models\CableLabelTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CableLabelTemplatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_global_templates_preserve_printable_text_and_reject_stale_changes_unknown_macros_and_non_admins(): void
    {
        $settings = [
            'endpoint_format' => '[{{piano}}] {{rack}}\\{{dispositivo}}@{{porta}}',
            'diameter' => 6, 'wrap' => 21, 'profile' => 'round', 'max_length' => 100,
            'back_mode' => 'repeat', 'back_rotation' => 0,
            'front' => [['blocks' => [['text' => '{{estremita}}', 'size' => 2.5, 'align' => 'center']], 'separator' => ' | ']],
            'back' => [['blocks' => [['text' => '{{nome_cavo}}', 'size' => 2.5, 'align' => 'center']], 'separator' => ' / ']],
        ];
        $data = ['width' => 12, 'cable_type' => 'copper', 'revision' => 0, 'settings' => $settings];
        $url = route('administration.labels.store');
        $this->actingAs(User::factory()->create())->post($url, $data)->assertForbidden();
        $this->get(route('administration.labels.index'))->assertForbidden();
        $this->actingAs(User::factory()->create(['is_superadmin' => true]));
        $this->post($url, $data)->assertSessionHasNoErrors();
        $template = CableLabelTemplate::sole();
        $this->assertSame($settings['endpoint_format'], $template->settings['endpoint_format']);
        $this->assertSame(' | ', $template->settings['front'][0]['separator']);
        $this->post($url, $data)->assertSessionHasErrors('revision');
        $this->post($url, [...$data, 'revision' => 1, 'settings' => [...$settings, 'endpoint_format' => '{{esegui_codice}}']])->assertSessionHasErrors('settings.endpoint_format');
        $this->assertSame(1, $template->fresh()->revision);
        $settings['front'][0]['blocks'][0]['text'] = ' {{a.dispositivo}} @ {{b.porta}} ';
        $this->post($url, [...$data, 'revision' => 1, 'settings' => $settings])->assertSessionHasNoErrors();
        $this->assertSame(' {{a.dispositivo}} @ {{b.porta}} ', $template->fresh()->settings['front'][0]['blocks'][0]['text']);
        $this->assertSame(2, $template->fresh()->revision);
        $this->post($url, [...$data, 'width' => 18])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('cable_label_templates', 2);
    }
}
