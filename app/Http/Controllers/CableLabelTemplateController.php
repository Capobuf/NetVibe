<?php

namespace App\Http\Controllers;

use App\Http\Requests\CableLabelTemplateRequest;
use App\Models\CableLabelTemplate;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CableLabelTemplateController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('administration/labels', ['templates' => CableLabelTemplate::all()]);
    }

    public function store(CableLabelTemplateRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['revision'] = $request->integer('revision');
        foreach (['diameter', 'wrap', 'max_length'] as $field) {
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
        $key = $request->safe()->only(['width', 'cable_type']);
        try {
            if ($data['revision'] === 0) {
                CableLabelTemplate::create([...$key, 'settings' => $data['settings'], 'revision' => 1]);
            } else {
                $updated = CableLabelTemplate::query()->where($key)->where('revision', $data['revision'])->update([
                    'settings' => json_encode($data['settings'], JSON_THROW_ON_ERROR),
                    'revision' => $data['revision'] + 1,
                ]);
                if (! $updated) {
                    $this->stale();
                }
            }
        } catch (UniqueConstraintViolationException) {
            $this->stale();
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Template salvato.']);

        return to_route('administration.labels.index');
    }

    private function stale(): never
    {
        throw ValidationException::withMessages(['revision' => 'Il template è cambiato. Ricarica la pagina prima di salvare.']);
    }
}
