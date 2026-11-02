<?php

namespace App\Http\Controllers;

use App\Http\Requests\CableLabelTemplateRequest;
use App\Models\CableLabelTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CableLabelTemplateController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('administration/labels', [
            'templates' => CableLabelTemplate::query()->orderBy('name')->orderBy('id')->get(),
            'selectedTemplateId' => $request->integer('profile') ?: null,
        ]);
    }

    public function store(CableLabelTemplateRequest $request): RedirectResponse
    {
        $template = CableLabelTemplate::create([...$this->data($request), 'revision' => 1]);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Profilo creato.']);

        return to_route('administration.labels.index', ['profile' => $template->id]);
    }

    public function update(CableLabelTemplateRequest $request, CableLabelTemplate $template): RedirectResponse
    {
        $data = $this->data($request);
        $data['settings'] = json_encode($data['settings'], JSON_THROW_ON_ERROR);
        $data['revision'] = $request->integer('revision') + 1;
        if (! CableLabelTemplate::query()->whereKey($template->id)->where('revision', $request->integer('revision'))->update($data)) {
            $this->stale();
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Profilo salvato.']);

        return to_route('administration.labels.index', ['profile' => $template->id]);
    }

    public function destroy(Request $request, CableLabelTemplate $template): RedirectResponse
    {
        $request->validate(['revision' => ['required', 'integer', 'min:1']]);
        if (! CableLabelTemplate::query()->whereKey($template->id)->where('revision', $request->integer('revision'))->delete()) {
            $this->stale();
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Profilo eliminato.']);

        return to_route('administration.labels.index');
    }

    private function data(CableLabelTemplateRequest $request): array
    {
        $data = $request->safe()->only(['name', 'width', 'cable_type', 'settings']);
        foreach (['diameter', 'wrap', 'max_length', 'face_length'] as $field) {
            $data['settings'][$field] = (float) $data['settings'][$field];
        }
        $data['settings']['back_rotation'] = (int) $data['settings']['back_rotation'];
        foreach (['front', 'back'] as $face) {
            foreach ($data['settings'][$face] as &$row) {
                foreach ($row['blocks'] as &$block) {
                    $block['size'] = (float) $block['size'];
                }
                unset($block);
            }
            unset($row);
        }

        return $data;
    }

    private function stale(): never
    {
        throw ValidationException::withMessages(['revision' => 'Il profilo è cambiato. Ricarica la pagina prima di continuare.']);
    }
}
