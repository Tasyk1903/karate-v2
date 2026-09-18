<?php

namespace App\Services\Tournaments;

use App\Models\TemplateStudentList;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class TemplateListInput
{
    public function validated(Request $request): array
    {
        $data = array_merge([
            'kata_type' => null,
            'weight_from' => null,
            'weight_to' => null,
            'rang_from' => null,
            'rang_to' => null,
            'gender' => null,
        ], $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'list_type' => ['required', Rule::in([TemplateStudentList::KUMITE, TemplateStudentList::KATA])],
            'kata_type' => ['nullable', Rule::in([TemplateStudentList::PERSONAL, TemplateStudentList::GROUP, TemplateStudentList::FLAG])],
            'age_from' => ['required', 'integer', 'min:0', 'max:100'],
            'age_to' => ['required', 'integer', 'min:0', 'max:100', 'gte:age_from'],
            'weight_from' => ['nullable', 'integer', 'min:0', 'max:300'],
            'weight_to' => ['nullable', 'integer', 'min:0', 'max:300', 'gte:weight_from'],
            'rang_from' => ['nullable', 'integer', 'min:0', 'max:100'],
            'rang_to' => ['nullable', 'integer', 'min:0', 'max:100'],
            'gender' => ['nullable', Rule::in(['m', 'f', 'all'])],
        ]));

        if ($data['list_type'] === TemplateStudentList::KUMITE) {
            $data['kata_type'] = null;
            $this->requireFields($data, ['weight_from', 'weight_to', 'rang_from', 'rang_to', 'gender']);
        }

        if ($data['list_type'] === TemplateStudentList::KATA) {
            $this->requireFields($data, ['kata_type']);

            if ($data['kata_type'] === TemplateStudentList::PERSONAL) {
                $data['weight_from'] = null;
                $data['weight_to'] = null;
                $data['rang_from'] = null;
                $data['rang_to'] = null;
                $this->requireFields($data, ['gender']);
            }

            if ($data['kata_type'] === TemplateStudentList::GROUP) {
                $data['weight_from'] = null;
                $data['weight_to'] = null;
                $data['rang_from'] = null;
                $data['rang_to'] = null;
                $data['gender'] = null;
            }

            if ($data['kata_type'] === TemplateStudentList::FLAG) {
                $data['weight_from'] = null;
                $data['weight_to'] = null;
                $this->requireFields($data, ['rang_from', 'rang_to', 'gender']);
            }
        }

        return $data;
    }

    private function requireFields(array $data, array $fields): void
    {
        foreach ($fields as $field) {
            if (! isset($data[$field]) || $data[$field] === '') {
                throw ValidationException::withMessages([$field => __('lists.required')]);
            }
        }
    }
}
