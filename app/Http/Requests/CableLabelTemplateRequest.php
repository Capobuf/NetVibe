<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

class CableLabelTemplateRequest extends FormRequest
{
    public function rules(): array
    {
        $fields = ['piano', 'rack', 'dispositivo', 'porta'];
        $macros = [...$fields, 'estremita', 'estremita_a', 'estremita_b', 'nome_cavo'];
        foreach (['a', 'b'] as $side) {
            foreach ($fields as $field) {
                $macros[] = $side.'.'.$field;
            }
        }
        $format = fn (array $allowed) => function (string $attribute, mixed $value, Closure $fail) use ($allowed) {
            if (! is_string($value)) {
                return;
            }
            preg_match_all('/\{\{(.*?)\}\}/s', $value, $matches);
            if (array_diff($matches[1], $allowed)
                || preg_match('/\{\{|\}\}/', preg_replace('/\{\{(.*?)\}\}/s', '', $value))) {
                $fail('Segnaposto non valido. Scegli una delle macro disponibili.');
            }
        };

        return [
            'name' => ['required', 'string', 'max:255'],
            'width' => ['required', 'integer', 'in:12,18,24'],
            'cable_type' => ['required', 'in:copper,fiber,usb,power'],
            'revision' => ['required', 'integer', 'min:0'],
            'settings' => ['required', 'array:endpoint_format,diameter,wrap,profile,max_length,back_mode,back_rotation,front,back,length_mode,face_length,fit,print_mode'],
            'settings.endpoint_format' => ['required', 'string', 'max:500', $format($fields)],
            'settings.diameter' => ['required', 'numeric', 'between:0.5,30'],
            'settings.wrap' => ['required', 'numeric', 'between:2,160'],
            'settings.profile' => ['required', 'in:round,duplex'],
            'settings.max_length' => ['required', 'numeric', 'between:8,150'],
            'settings.length_mode' => ['required', 'in:auto,fixed'],
            'settings.face_length' => ['required', 'numeric', 'between:8,150', 'lte:settings.max_length'],
            'settings.fit' => ['required', 'in:extend,shrink,wrap'],
            // Older profiles may still submit this print-only option.
            'settings.print_mode' => ['exclude'],
            'settings.back_mode' => ['required', 'in:repeat,opposite,custom'],
            'settings.back_rotation' => ['required', 'integer', 'in:0,180'],
            ...collect(['front', 'back'])->flatMap(fn ($face) => [
                "settings.$face" => ['required', 'array', 'min:1', 'max:4'],
                "settings.$face.*" => ['required', 'array:blocks,separator'],
                "settings.$face.*.separator" => ['present', 'nullable', 'string', 'max:20', 'not_regex:/[\r\n]/'],
                "settings.$face.*.blocks" => ['required', 'array', 'min:1', 'max:3'],
                "settings.$face.*.blocks.*" => ['required', 'array:text,size,align'],
                "settings.$face.*.blocks.*.text" => ['required', 'string', 'max:500', $format($macros)],
                "settings.$face.*.blocks.*.size" => ['required', 'numeric', 'between:2,6'],
                "settings.$face.*.blocks.*.align" => ['required', 'in:left,center,right'],
            ])->all(),
        ];
    }

    public function attributes(): array
    {
        return [
            'settings.endpoint_format' => 'formato delle estremità',
            'settings.diameter' => 'diametro',
            'settings.wrap' => 'avvolgimento',
            'name' => 'nome del profilo',
            'settings.max_length' => 'lunghezza massima',
            'settings.face_length' => 'lunghezza della faccia',
            'settings.front.*.blocks.*.text' => 'testo della prima faccia',
            'settings.back.*.blocks.*.text' => 'testo della seconda faccia',
            'settings.front.*.blocks.*.size' => 'dimensione testo della prima faccia',
            'settings.back.*.blocks.*.size' => 'dimensione testo della seconda faccia',
        ];
    }
}
