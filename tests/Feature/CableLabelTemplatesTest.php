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
            'length_mode' => 'auto', 'face_length' => 45, 'fit' => 'extend',
            'front' => [['blocks' => [['text' => '{{estremita}}', 'size' => 2.5, 'align' => 'center']], 'separator' => ' | ']],
            'back' => [['blocks' => [['text' => '{{nome_cavo}}', 'size' => 2.5, 'align' => 'center']], 'separator' => ' / ']],
        ];
        $data = ['name' => 'Rete completa', 'width' => 12, 'cable_type' => 'copper', 'revision' => 0, 'settings' => $settings];
        $url = route('administration.labels.store');
        $this->actingAs(User::factory()->create())->post($url, $data)->assertForbidden();
        $this->get(route('administration.labels.index'))->assertForbidden();
        $this->actingAs(User::factory()->create(['is_superadmin' => true]));
        $this->post($url, $data)->assertSessionHasNoErrors();
        $template = CableLabelTemplate::sole();
        $this->assertSame($settings['endpoint_format'], $template->settings['endpoint_format']);
        $this->assertSame(' | ', $template->settings['front'][0]['separator']);
        $updateUrl = route('administration.labels.update', $template);
        $deleteUrl = route('administration.labels.destroy', $template);
        $this->post($url, [...$data, 'name' => 'Rete sintetica'])->assertSessionHasNoErrors();
        $copy = CableLabelTemplate::query()->whereKeyNot($template->id)->sole();
        $this->put($updateUrl, $data)->assertSessionHasErrors('revision');
        $this->put($updateUrl, [...$data, 'revision' => 1, 'settings' => [...$settings, 'endpoint_format' => '{{esegui_codice}}']])->assertSessionHasErrors('settings.endpoint_format');
        $this->assertSame(1, $template->fresh()->revision);
        $settings['front'][0]['blocks'][0]['text'] = ' {{a.dispositivo}} @ {{b.porta}} ';
        $this->put($updateUrl, [...$data, 'revision' => 1, 'settings' => $settings])->assertSessionHasNoErrors();
        $this->assertSame(' {{a.dispositivo}} @ {{b.porta}} ', $template->fresh()->settings['front'][0]['blocks'][0]['text']);
        $this->assertSame(2, $template->fresh()->revision);
        $this->assertSame('{{estremita}}', $copy->fresh()->settings['front'][0]['blocks'][0]['text']);
        $this->put($updateUrl, [...$data, 'revision' => 1])->assertSessionHasErrors('revision');
        $this->delete($deleteUrl, ['revision' => 1])->assertSessionHasErrors('revision');
        $this->actingAs(User::factory()->create());
        $this->put($updateUrl, [...$data, 'revision' => 2])->assertForbidden();
        $this->delete($deleteUrl, ['revision' => 2])->assertForbidden();
        $this->actingAs(User::factory()->create(['is_superadmin' => true]));
        $this->put($updateUrl, [...$data, 'revision' => 2, 'name' => 'Rinominato', 'settings' => [...$settings, 'print_mode' => 'single', 'length_mode' => 'fixed', 'fit' => 'shrink']])->assertSessionHasNoErrors();
        $this->assertSame('Rinominato', $template->fresh()->name);
        $this->assertArrayNotHasKey('print_mode', $template->fresh()->settings);
        $this->delete($deleteUrl, ['revision' => 3])->assertSessionHasNoErrors();
        $this->assertModelMissing($template);
        $this->assertModelExists($copy);
    }
}
