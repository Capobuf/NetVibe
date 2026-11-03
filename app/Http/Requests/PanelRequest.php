<?php

namespace App\Http\Requests;

use App\Support\MikrotikPortMap;
use App\Support\UnifiPortMap;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PanelRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $json = $this->input('document');
        $panel = is_string($json) && strlen($json) <= 1048576 ? json_decode($json, true) : null;
        foreach (['front', 'rear'] as $side) {
            $ports = $panel['faces'][$side]['ports'] ?? null;
            if (! is_array($ports)) {
                continue;
            }
            foreach ($ports as $index => $port) {
                if (is_array($port)) {
                    // Open editor sessions can still contain block options copied into ports.
                    $panel['faces'][$side]['ports'][$index] = Arr::except($port, ['count', 'columns', 'gapX', 'gapY', 'columnFirst']);
                }
            }
        }
        $this->merge(['panel' => $panel]);
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        $coordinate = ['required', 'numeric:strict', 'between:-50000,50000'];
        $rules = [
            'revision' => ['required', 'integer', 'min:0'],
            'panel' => ['required', 'array:version,name,shell,faces,numbering'],
            'panel.version' => ['required', 'integer:strict', 'in:3'],
            'panel.name' => ['required', 'string', 'max:255'],
            'panel.shell' => ['required', 'array:x,y,width,height,radius,border'],
            'panel.shell.x' => $coordinate, 'panel.shell.y' => $coordinate,
            'panel.shell.width' => ['required', 'numeric:strict', 'between:40,5000'],
            'panel.shell.height' => ['required', 'numeric:strict', 'between:40,5000'],
            'panel.shell.radius' => ['required', 'numeric:strict', 'between:0,2500'],
            'panel.shell.border' => ['required', 'numeric:strict', 'between:0,100'],
            'panel.numbering' => ['required', 'array:next,prefix'],
            'panel.numbering.next' => ['required', 'integer:strict', 'between:0,9007199254740991'],
            'panel.numbering.prefix' => ['present', 'string', 'max:255'],
            'panel.faces' => ['required', 'array:front,rear'],
            'images' => ['sometimes', 'array:front,rear'],
        ];
        foreach (['front', 'rear'] as $side) {
            $f = "panel.faces.$side";
            $rules += [
                $f => ['required', 'array:ports,image,order,shellState'],
                "$f.ports" => ['present', 'array', 'list', 'max:1024'],
                "$f.order" => ['present', 'array', 'list', 'max:1025'],
                "$f.order.*" => ['required', 'string', 'max:64', 'distinct'],
                "$f.shellState" => ['required', 'array:hidden,locked'],
                "$f.shellState.hidden" => ['required', 'boolean:strict'],
                "$f.shellState.locked" => ['required', 'boolean:strict'],
                "$f.ports.*" => ['required', 'array:id,type,x,y,rotation,scale,label,labelPosition,poe,group,opacity,opacityCustomized,borderColor,hidden,locked,mikrotikName,unifiPort'],
                "$f.ports.*.id" => ['required', 'string', 'regex:/^[a-f0-9]{32}$/', 'distinct'],
                "$f.ports.*.type" => ['required', Rule::in(['rj45', 'sfp', 'qsfp', 'console', 'usbA', 'usbC', 'usbMiniB', 'usbMicroB', 'ac', 'acC19', 'acC7', 'acC5', 'dc'])],
                "$f.ports.*.x" => $coordinate, "$f.ports.*.y" => $coordinate,
                "$f.ports.*.rotation" => ['required', 'integer:strict', 'in:0,90,180,270'],
                "$f.ports.*.scale" => ['required', 'numeric:strict', 'between:0.2,8'],
                "$f.ports.*.label" => ['present', 'string', 'max:255'],
                "$f.ports.*.mikrotikName" => ['sometimes', 'nullable', 'string', 'regex:/^[a-zA-Z0-9_.-]+$/', 'max:255'],
                "$f.ports.*.unifiPort" => ['sometimes', 'nullable', 'integer:strict', 'min:1'],
                "$f.ports.*.labelPosition" => ['required', 'in:center,top,bottom,left,right,hidden'],
                "$f.ports.*.poe" => ['required', 'boolean:strict'],
                "$f.ports.*.group" => ['present', 'nullable', 'string', 'regex:/^[a-f0-9]{32}$/'],
                "$f.ports.*.opacity" => ['required', 'numeric:strict', 'between:0,1'],
                "$f.ports.*.opacityCustomized" => ['required', 'boolean:strict'],
                "$f.ports.*.borderColor" => ['required', 'regex:/^#[a-fA-F0-9]{6}$/'],
                "$f.ports.*.hidden" => ['required', 'boolean:strict'],
                "$f.ports.*.locked" => ['required', 'boolean:strict'],
                "$f.image" => ['present', 'nullable', 'array:assetId,x,y,width,height,scale,opacity,clip,crop,hidden,locked'],
                "images.$side" => [$this->input("$f.image") ? 'required' : 'prohibited', 'file', 'image', 'mimes:png,jpg,jpeg,webp', 'max:12288', 'dimensions:max_width=8192,max_height=8192'],
            ];
            if ($this->input("$f.image") !== null) {
                $rules += [
                    "$f.image.assetId" => ['required', 'string', 'regex:/^[a-f0-9]{32}$/'],
                    "$f.image.x" => $coordinate, "$f.image.y" => $coordinate,
                    "$f.image.width" => ['required', 'integer:strict', 'between:1,8192'],
                    "$f.image.height" => ['required', 'integer:strict', 'between:1,8192'],
                    "$f.image.scale" => ['required', 'numeric:strict', 'between:0.001,8'],
                    "$f.image.opacity" => ['required', 'numeric:strict', 'between:0,1'],
                    "$f.image.clip" => ['required', 'boolean:strict'],
                    "$f.image.hidden" => ['required', 'boolean:strict'],
                    "$f.image.locked" => ['required', 'boolean:strict'],
                    "$f.image.crop" => ['required', 'array:x,y,width,height'],
                    "$f.image.crop.x" => ['required', 'numeric:strict', 'min:0'],
                    "$f.image.crop.y" => ['required', 'numeric:strict', 'min:0'],
                    "$f.image.crop.width" => ['required', 'numeric:strict', 'gt:0'],
                    "$f.image.crop.height" => ['required', 'numeric:strict', 'gt:0'],
                ];
            }
        }

        return $rules;
    }

    /** @return list<\Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $ids = $labels = $groups = $assetIds = $mikrotikNames = $unifiPorts = [];
            $allPorts = [...$this->input('panel.faces.front.ports'), ...$this->input('panel.faces.rear.ports')];
            $observed = collect(collect($allPorts)->contains(fn ($port) => isset($port['unifiPort']))
                ? UnifiPortMap::observed($this->route('model')) : [])->keyBy('idx');
            foreach (['front', 'rear'] as $side) {
                $face = $this->input("panel.faces.$side");
                foreach ($face['ports'] as $port) {
                    if (($idx = $port['unifiPort'] ?? null) !== null) {
                        $type = UnifiPortMap::type($observed->get($idx)['connector'] ?? null);
                        if (! in_array($port['type'], ['rj45', 'sfp', 'qsfp'], true) || in_array($idx, $unifiPorts, true)
                            || ($type !== null && $type !== $port['type'])) {
                            $validator->errors()->add('document', 'Ogni porta UniFi può essere associata a una sola porta di rete compatibile.');
                        }
                        $unifiPorts[] = $idx;
                    }
                    if ($name = $port['mikrotikName'] ?? null) {
                        if (! in_array($port['type'], ['rj45', 'sfp', 'qsfp'], true) || in_array($name, $mikrotikNames, true)
                            || (MikrotikPortMap::type($name) !== null && MikrotikPortMap::type($name) !== $port['type'])) {
                            $validator->errors()->add('document', 'Ogni interfaccia MikroTik può essere associata a una sola porta di rete.');
                        }
                        $mikrotikNames[] = $name;
                    }
                    if (in_array($port['id'], $ids, true) || ($port['label'] !== '' && in_array(trim($port['label'] ?? ''), $labels, true))) {
                        $validator->errors()->add('document', 'ID o etichette delle porte duplicati.');
                    }
                    $ids[] = $port['id'];
                    if (trim($port['label'] ?? '') !== '') {
                        $labels[] = trim($port['label']);
                    }
                    if ($port['group'] && isset($groups[$port['group']]) && $groups[$port['group']] !== $side) {
                        $validator->errors()->add('document', 'Un gruppo non può appartenere a entrambe le facce.');
                    }
                    if ($port['group']) {
                        $groups[$port['group']] = $side;
                    }
                }
                if (array_diff($face['order'], ['image', ...array_column($face['ports'], 'id')])) {
                    $validator->errors()->add('document', 'Ordine dei livelli non valido.');
                }
                if ($image = $face['image']) {
                    if (in_array($image['assetId'], $assetIds, true)) {
                        $validator->errors()->add('document', 'Le immagini delle due facce devono avere ID distinti.');
                    }
                    $assetIds[] = $image['assetId'];
                    $dimensions = getimagesize($this->file("images.$side")->getRealPath());
                    if (! $dimensions || $dimensions[0] !== $image['width'] || $dimensions[1] !== $image['height'] || $image['width'] * $image['height'] > 16000000
                        || $image['crop']['x'] + $image['crop']['width'] > $image['width'] + 0.000001
                        || $image['crop']['y'] + $image['crop']['height'] > $image['height'] + 0.000001) {
                        $validator->errors()->add('document', 'Dimensioni o ritaglio dell’immagine non validi (massimo 16 megapixel).');
                    }
                }
            }
        }];
    }
}
