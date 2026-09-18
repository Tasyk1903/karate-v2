<?php

namespace App\Http\Controllers;

use App\Models\ExternalForm;
use App\Services\Tournaments\ExternalFormRows;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicExternalFormController extends Controller
{
    public function show(string $token, ExternalFormRows $forms): JsonResponse
    {
        $form = ExternalForm::query()
            ->where('token', $token)
            ->with('championship.tournaments:id,championship_id,tournament_type,tournament_type_kata')
            ->firstOrFail();

        return response()->json([
            'form' => [
                'id' => $form->id,
                'organization_name' => $form->organization_name,
                'status' => $form->status,
                'participants' => $forms->rows($form),
                'revision' => $forms->revision($form),
            ],
            'category_options' => $forms->categoryOptions($form),
        ]);
    }

    public function save(Request $request, string $token, ExternalFormRows $forms): JsonResponse
    {
        $form = ExternalForm::query()->where('token', $token)->firstOrFail();
        abort_unless($form->status !== 'closed', 403, 'Форма закрыта.');

        $data = $request->validate([
            'participants' => ['array'],
            'participants.*' => ['array'],
            'revision' => ['nullable', 'string', 'size:64'],
        ]);

        $rows = $forms->saveRows($form, $data['participants'] ?? [], null, $data['revision'] ?? null);

        return response()->json([
            'participants' => $rows,
            'revision' => $forms->revision($form->fresh()),
        ]);
    }
}
