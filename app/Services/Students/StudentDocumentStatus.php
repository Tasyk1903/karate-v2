<?php

namespace App\Services\Students;

use Carbon\Carbon;

final class StudentDocumentStatus
{
    public const KEYS = ['passport' => 'passport', 'brand' => 'brand', 'insurance' => 'insurance', 'iko_card' => 'ikoCard', 'certificate' => 'certificate'];

    public function evaluate(object $student, mixed $through = null): array
    {
        $through = $through ? Carbon::parse($through)->toDateString() : today()->toDateString();
        $expires = $this->date($student->insurance_close_date ?? null);
        $context = ['insurance_close_date' => $expires ? Carbon::parse($expires)->format('d.m.Y') : null,
            'tournament_finish_date' => Carbon::parse($through)->format('d.m.Y')];
        $issues = [];
        $items = [];
        foreach (self::KEYS as $field => $key) {
            $included = ! in_array($field, ['iko_card', 'certificate'], true) || (bool) ($student->{'is_'.$field.'_included_check'} ?? false);
            $confirmed = (bool) ($student->{'is_success_'.$field} ?? false);
            $issue = null;
            if ($included && ! $confirmed) {
                $issue = 'documentIssue'.ucfirst($key);
            } elseif ($included && $field === 'insurance') {
                $issue = ! $expires ? 'documentIssueInsuranceDate' : ($expires < $through ? 'documentIssueInsuranceExpired' : null);
            }
            if ($issue) {
                $issues[] = $issue;
            }
            $items[$field] = ['key' => $key, 'field' => $field, 'included' => $included, 'confirmed' => $confirmed,
                'ok' => $issue === null, 'issue_key' => $issue, 'expires_at' => $field === 'insurance' ? $context['insurance_close_date'] : null];
        }

        return ['ok' => $issues === [], 'key' => $issues[0] ?? 'documentStatusOk', 'issues' => $issues, 'context' => $context, 'items' => $items];
    }

    private function date(mixed $value): ?string
    {
        if (! $value) {
            return null;
        }
        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
