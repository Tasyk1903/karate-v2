<?php

namespace App\Services\Account;

use App\Models\User;
use App\Services\Team\TeamActivity;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class Agreements
{
    public const REQUIRED = [2 => 'success_politic', 3 => 'data_processing'];

    public function title(object $document): string
    {
        $titles = __('account.agreement_titles');

        return $titles[$document->type] ?? (string) $document->type;
    }

    public function version(object $document): string
    {
        return hash('sha256', $document->type."\n".$document->description.(empty($document->description_en) ? '' : "\nen\n".$document->description_en));
    }

    public function content(object $document): string
    {
        return app()->getLocale() === 'en' && ! empty($document->description_en)
            ? $document->description_en : $document->description;
    }

    public function contentLocale(object $document): string
    {
        return app()->getLocale() === 'en' && ! empty($document->description_en) ? 'en' : 'ru';
    }

    public function pending(User $user): Collection
    {
        $documents = DB::table('agreements')->whereIn('id', array_keys(self::REQUIRED))->get();
        $accepted = DB::table('agreement_acceptances')->where('user_id', $user->id)->get(['agreement_id', 'version']);

        return $documents->reject(fn ($document) => $accepted->contains(fn ($row) => $row->agreement_id == $document->id && hash_equals($row->version, $this->version($document))));
    }

    public function accept(User $user, int $id, string $version): void
    {
        DB::transaction(function () use ($user, $id, $version): void {
            $actor = User::query()->lockForUpdate()->findOrFail($user->id);
            $document = DB::table('agreements')->where('id', $id)->lockForUpdate()->first();
            abort_unless($document, 404);
            if (! hash_equals($this->version($document), $version)) {
                throw ValidationException::withMessages(['version' => __('account.agreement_changed')]);
            }
            $where = ['user_id' => $actor->id, 'agreement_id' => $id, 'version' => $version];
            if (DB::table('agreement_acceptances')->where($where)->exists()) {
                $flag = self::REQUIRED[$id] ?? null;
                if ($flag && ! $actor->$flag) {
                    $actor->forceFill([$flag => true])->save();
                    TeamActivity::record($actor, 'agreement.consent.restored', 'App\\Models\\Agreement', $id,
                        ['old' => [$flag => false], 'new' => [$flag => true], 'version' => $version]);
                }

                return;
            }
            DB::table('agreement_acceptances')->insert($where + ['agreement_type' => $document->type, 'content' => $this->content($document), 'locale' => $this->contentLocale($document), 'accepted_at' => now()]);
            if (isset(self::REQUIRED[$id])) {
                $actor->forceFill([self::REQUIRED[$id] => true])->save();
            }
            TeamActivity::record($actor, 'agreement.accepted', 'App\\Models\\Agreement', $id, ['old' => null, 'new' => ['version' => $version, 'accepted_at' => now()->toISOString()]]);
        });
    }
}
