<?php

namespace Modules\Freelance\Services\Rights;

use App\Services\General\AuditLogger;
use Illuminate\Support\Facades\DB;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceRightsTerm as Term;

/**
 * The standard text of each rights model (FL-L13). The platform ships one in Arabic and English for every model; the staff may write
 * another for a country (or for all). `snapshot()` is what an agreement keeps: the text in force that day, with its version.
 * These are guiding texts, not legal advice — each country's text should be read by a lawyer before launch.
 */
class RightsTerms
{
    /** @var array<string, array{title: array{ar: string, en: string}, body: array{ar: string, en: string}}> */
    public const DEFAULTS = [
        Rights::TRANSFER => [
            'title' => ['ar' => 'نقل الملكية كاملة', 'en' => 'Full transfer of ownership'],
            'body' => [
                'ar' => "بعد اعتماد كل جزء من العمل وتحرير دفعته تنتقل إلى العميل ملكية هذا الجزء كاملة، بما فيها حق الاستخدام والتعديل والنشر والاستغلال التجاري دون قيد.\nلا يحتفظ المحترف بأي حق في العمل إلا ما يتيحه له العميل بموافقته، مثل عرضه في معرض أعماله.\nقبل الاعتماد والدفع يكون للعميل حق المعاينة والمراجعة فقط، ولا يجوز له استخدام العمل.\nيقرّ المحترف أن العمل من إنتاجه أو أنه يملك حق استخدامه وتسليمه، وأنه صرّح بأي مواد من خارجه استخدمها في العمل.",
                'en' => "Once a part of the work is approved and its payment released, full ownership of that part passes to the client, including the right to use, change, publish and exploit it commercially without limit.\nThe freelancer keeps no right in the work except what the client allows, such as showing it in their portfolio.\nBefore approval and payment the client may only look at the work and review it, not use it.\nThe freelancer confirms the work is their own, or that they hold the right to use and hand it over, and has declared any outside material used in it.",
            ],
        ],
        Rights::LICENSE => [
            'title' => ['ar' => 'ترخيص استخدام', 'en' => 'Licence to use'],
            'body' => [
                'ar' => "يبقى المحترف مالك العمل، ويمنح العميل بعد اعتماد كل جزء وتحرير دفعته ترخيصًا باستخدامه ضمن الحدود المكتوبة في الاتفاق: نوع الاستخدام (تجاري أو غير تجاري)، وما إذا كان الترخيص حصريًا، ومدته، ونطاقه الجغرافي.\nلا يجوز للعميل التنازل عن الترخيص لغيره أو بيع العمل أو استخدامه خارج هذه الحدود.\nقبل الاعتماد والدفع يكون للعميل حق المعاينة والمراجعة فقط.\nيقرّ المحترف أن العمل من إنتاجه أو أنه يملك حق ترخيصه، وأنه صرّح بأي مواد من خارجه استخدمها في العمل.",
                'en' => "The freelancer stays the owner of the work and, once a part is approved and its payment released, gives the client a licence to use it within the limits written in the agreement: the kind of use (commercial or not), whether the licence is exclusive, how long it lasts and where it applies.\nThe client may not hand the licence to anyone else, sell the work or use it beyond those limits.\nBefore approval and payment the client may only look at the work and review it.\nThe freelancer confirms the work is their own, or that they hold the right to license it, and has declared any outside material used in it.",
            ],
        ],
        Rights::RETAINED => [
            'title' => ['ar' => 'المحترف يحتفظ بالملكية', 'en' => 'The freelancer keeps ownership'],
            'body' => [
                'ar' => "يحتفظ المحترف بملكية العمل ويجوز له إعادة استخدام أصله (مثل القالب أو الشيفرة أو التصميم الجاهز) مع عملاء آخرين.\nبعد اعتماد كل جزء وتحرير دفعته يحصل العميل على حق دائم غير حصري في استخدام ما سُلّم له في مشروعه، ولا يجوز له بيعه أو إعادة توزيعه كمنتج مستقل.\nقبل الاعتماد والدفع يكون للعميل حق المعاينة والمراجعة فقط.\nيقرّ المحترف أن العمل من إنتاجه أو أنه يملك حق استخدامه وتسليمه، وأنه صرّح بأي مواد من خارجه استخدمها في العمل.",
                'en' => "The freelancer keeps ownership of the work and may reuse its basis (such as a template, code or a ready design) with other clients.\nOnce a part is approved and its payment released, the client gets a lasting, non-exclusive right to use what was delivered in their own project, and may not sell it or redistribute it as a product of its own.\nBefore approval and payment the client may only look at the work and review it.\nThe freelancer confirms the work is their own, or that they hold the right to use and hand it over, and has declared any outside material used in it.",
            ],
        ],
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * What an agreement keeps: the text for this country (its own, else the general one, else the platform's), with the version.
     *
     * @return array{id: ?int, version: int, title: array{ar: string, en: string}, body: array{ar: string, en: string}}
     */
    public function snapshot(int $countryId, string $model): array
    {
        $row = $this->effective($countryId, $model);
        if ($row !== null) {
            return ['id' => $row->id, 'version' => $row->version, 'title' => ['ar' => $row->title_ar, 'en' => $row->title_en], 'body' => ['ar' => $row->body_ar, 'en' => $row->body_en]];
        }
        $d = self::DEFAULTS[$model];

        return ['id' => null, 'version' => 0, 'title' => $d['title'], 'body' => $d['body']];
    }

    /** The text in the request's language, from a snapshot. @param array<string, mixed> $snapshot @return array{title: string, body: string, version: int} */
    public static function localized(array $snapshot): array
    {
        $lang = app()->getLocale() === 'ar' ? 'ar' : 'en';

        return ['title' => (string) ($snapshot['title'][$lang] ?? ''), 'body' => (string) ($snapshot['body'][$lang] ?? ''), 'version' => (int) ($snapshot['version'] ?? 0)];
    }

    // -------------------------------------------------------------- the staff

    /**
     * The three models for one scope: the platform's text, and the staff's own when they wrote one there.
     *
     * @return list<array<string, mixed>>
     */
    public function forScope(?int $countryId): array
    {
        $rows = Term::query()->where('country_id', $countryId)->get()->keyBy('model');

        return array_map(function (string $model) use ($rows, $countryId): array {
            /** @var ?Term $row */
            $row = $rows->get($model);
            $d = self::DEFAULTS[$model];

            return [
                'model' => $model,
                'label' => __('freelance.rights.models.'.$model),
                'custom' => $row !== null,
                'id' => $row?->id,
                'version' => $row?->version,
                'active' => $row?->active ?? true,
                'title_ar' => $row?->title_ar ?? $d['title']['ar'], 'title_en' => $row?->title_en ?? $d['title']['en'],
                'body_ar' => $row?->body_ar ?? $d['body']['ar'], 'body_en' => $row?->body_en ?? $d['body']['en'],
                // What would be used for this scope if the row were off or gone.
                'fallback' => $countryId === null ? 'platform' : ($this->general($model) !== null ? 'general' : 'platform'),
                'updated_at' => $row?->updated_at?->toIso8601String(),
            ];
        }, Rights::MODELS);
    }

    /** @param array{title_ar: string, title_en: string, body_ar: string, body_en: string, active?: bool} $data */
    public function save(?int $countryId, string $model, array $data, int $adminId): Term
    {
        if (! in_array($model, Rights::MODELS, true)) {
            throw new FreelanceException('rights_model_invalid', 422);
        }

        return DB::transaction(function () use ($countryId, $model, $data, $adminId): Term {
            $row = Term::query()->where('country_id', $countryId)->where('model', $model)->lockForUpdate()->first();
            $fields = [
                'title_ar' => trim(strip_tags($data['title_ar'])), 'title_en' => trim(strip_tags($data['title_en'])),
                'body_ar' => trim(strip_tags($data['body_ar'])), 'body_en' => trim(strip_tags($data['body_en'])),
                'active' => (bool) ($data['active'] ?? true),
            ];
            if ($row === null) {
                $row = Term::query()->create($fields + ['country_id' => $countryId, 'model' => $model, 'version' => 1]);
            } else {
                $row->fill($fields + ['version' => $row->version + 1])->save();
            }
            $this->audit->record('freelance.rights_terms_saved', $row, null, ['admin' => $adminId, 'country_id' => $countryId, 'model' => $model, 'version' => $row->version]);

            return $row;
        });
    }

    /** Back to the platform's own text for that scope. */
    public function reset(Term $row, int $adminId): void
    {
        $this->audit->record('freelance.rights_terms_reset', $row, null, ['admin' => $adminId, 'country_id' => $row->country_id, 'model' => $row->model]);
        $row->delete();
    }

    private function effective(int $countryId, string $model): ?Term
    {
        return Term::query()->where('model', $model)->where('active', true)->where(fn ($q) => $q->where('country_id', $countryId)->orWhereNull('country_id'))
            // The country's own text wins over the general one.
            ->orderByRaw('country_id is null')->first();
    }

    private function general(string $model): ?Term
    {
        return Term::query()->whereNull('country_id')->where('model', $model)->where('active', true)->first();
    }
}
