<?php

namespace Modules\Marketing\Services\Plans;

use Modules\AI\Services\DocumentGeneration\AiDocumentRenderer;
use Modules\Marketing\Models\MarketingPlan;
use Modules\Marketing\Models\MarketingPlanVersion;
use Modules\Marketing\Models\MarketingSource;

/**
 * PDF / DOCX of one version (MK-A09), from the same structured JSON the apps show (step 07), through the AI module's
 * document renderers (Arabic and RTL handled there). Sources are listed with their dates.
 */
class PlanExporter
{
    public function __construct(private readonly AiDocumentRenderer $renderer) {}

    /** @return array{bytes: string, extension: string, mime: string, filename: string} */
    public function export(MarketingPlan $plan, MarketingPlanVersion $version, string $format): array
    {
        $s = $version->structured_json;
        $title = (string) ($s['title'] ?? __('marketing.plans.types.'.$plan->plan_type));
        $blocks = [['type' => 'heading', 'level' => 1, 'text' => $title]];
        $blocks[] = ['type' => 'paragraph', 'text' => __('marketing.export.meta', [
            'type' => __('marketing.plans.types.'.$plan->plan_type),
            'version' => $version->version_no,
            'date' => $version->created_at?->toDateString(),
            'status' => __('marketing.plans.status.'.$plan->status->value),
        ])];
        if (! empty($s['summary'])) {
            $blocks[] = ['type' => 'heading', 'level' => 2, 'text' => __('marketing.export.summary')];
            $blocks[] = ['type' => 'paragraph', 'text' => $s['summary']];
        }

        foreach (match ($plan->plan_type) {
            'business' => $this->business($s),
            'marketing' => $this->marketing($s),
            'gtm' => $this->gtm($s),
        } as $block) {
            $blocks[] = $block;
        }

        foreach (['assumptions', 'risks', 'confidence_notes'] as $list) {
            if (! empty($s[$list])) {
                $blocks[] = ['type' => 'heading', 'level' => 2, 'text' => __('marketing.export.'.$list)];
                $blocks[] = ['type' => 'bullets', 'items' => array_values($s[$list])];
            }
        }

        $scenarios = $version->scenarios()->get();
        if ($scenarios->isNotEmpty()) {
            $blocks[] = ['type' => 'heading', 'level' => 2, 'text' => __('marketing.export.finance')];
            $metrics = array_keys($scenarios->first()->outputs);
            $blocks[] = [
                'type' => 'table',
                'headers' => [__('marketing.export.metric'), ...$scenarios->map(fn ($sc) => __('marketing.finance.scenarios.'.$sc->scenario))->all()],
                'rows' => array_map(fn ($m) => [
                    __('marketing.finance.metrics.'.$m),
                    ...$scenarios->map(fn ($sc) => $this->metric($m, $sc->outputs[$m] ?? null, $s['finance']['currency'] ?? ''))->all(),
                ], $metrics),
            ];
            $blocks[] = ['type' => 'paragraph', 'text' => __('marketing.export.finance_note')];
        }

        $ids = collect($s['sources'] ?? [])->map(fn ($r) => (int) substr((string) $r, 1))->filter()->all();
        $sources = MarketingSource::query()->whereIn('id', $ids)->get();
        $blocks[] = ['type' => 'heading', 'level' => 2, 'text' => __('marketing.export.sources')];
        $blocks[] = $sources->isEmpty()
            ? ['type' => 'paragraph', 'text' => __('marketing.export.no_sources')]
            : ['type' => 'bullets', 'items' => $sources->map(fn ($src) => sprintf('[S%d] %s — %s (%s: %s)',
                $src->id, $src->title ?? $src->publisher ?? '-', $src->url, __('marketing.export.retrieved'), $src->retrieved_at?->toDateString()))->all()];

        $file = $this->renderer->render($format, $title, $blocks);
        $file['filename'] = sprintf('%s-v%d.%s', $plan->plan_type, $version->version_no, $file['extension']);

        return $file;
    }

    private function business(array $s): array
    {
        $blocks = [];
        foreach ($s['sections'] ?? [] as $section) {
            $blocks[] = ['type' => 'heading', 'level' => 2, 'text' => $section['title'] ?? __('marketing.plans.sections.'.$section['key'])];
            if (! empty($section['body'])) {
                $blocks[] = ['type' => 'paragraph', 'text' => $section['body']];
            }
        }
        if (! empty($s['financial']['notes'])) {
            $blocks[] = ['type' => 'heading', 'level' => 2, 'text' => __('marketing.export.financial_notes')];
            $blocks[] = ['type' => 'paragraph', 'text' => $s['financial']['notes']];
        }

        return $blocks;
    }

    private function marketing(array $s): array
    {
        $blocks = [];
        if (! empty($s['positioning'])) {
            $blocks[] = ['type' => 'heading', 'level' => 2, 'text' => __('marketing.export.positioning')];
            $blocks[] = ['type' => 'paragraph', 'text' => $s['positioning']];
        }
        if (! empty($s['segments'])) {
            $blocks[] = ['type' => 'heading', 'level' => 2, 'text' => __('marketing.export.segments')];
            $blocks[] = ['type' => 'bullets', 'items' => array_map(fn ($x) => trim($x['name'].': '.($x['description'] ?? ''), ': '), $s['segments'])];
        }
        if (! empty($s['channels'])) {
            $blocks[] = ['type' => 'heading', 'level' => 2, 'text' => __('marketing.export.channels')];
            $blocks[] = ['type' => 'table', 'headers' => [__('marketing.export.channel'), __('marketing.export.rationale'), __('marketing.export.share')],
                'rows' => array_map(fn ($c) => [$c['channel'], $c['rationale'] ?? '', $c['budget_share_pct'] === null ? '-' : $c['budget_share_pct'].'%'], $s['channels'])];
        }
        if (! empty($s['messages'])) {
            $blocks[] = ['type' => 'heading', 'level' => 2, 'text' => __('marketing.export.messages')];
            $blocks[] = ['type' => 'bullets', 'items' => $s['messages']];
        }
        if (! empty($s['kpis'])) {
            $blocks[] = ['type' => 'heading', 'level' => 2, 'text' => __('marketing.export.kpis')];
            $blocks[] = ['type' => 'table', 'headers' => [__('marketing.export.kpi'), __('marketing.export.target')],
                'rows' => array_map(fn ($k) => [$k['name'], $k['target'] ?? ''], $s['kpis'])];
        }

        return $blocks;
    }

    private function gtm(array $s): array
    {
        $blocks = [['type' => 'paragraph', 'text' => __('marketing.export.horizon', ['days' => $s['horizon_days'] ?? 90])]];
        foreach ($s['phases'] ?? [] as $phase) {
            $blocks[] = ['type' => 'heading', 'level' => 2, 'text' => __('marketing.export.phase', [
                'from' => $phase['day_from'], 'to' => $phase['day_to'], 'title' => $phase['title'] ?? '',
            ])];
            foreach (['tasks', 'kpis', 'go_no_go_criteria'] as $list) {
                if (! empty($phase[$list])) {
                    $blocks[] = ['type' => 'heading', 'level' => 3, 'text' => __('marketing.export.'.$list)];
                    $blocks[] = ['type' => 'bullets', 'items' => $phase[$list]];
                }
            }
        }

        return $blocks;
    }

    private function metric(string $key, ?array $value, string $currency): string
    {
        if ($value === null || $value['value'] === null) {
            return __('marketing.finance.unavailable').' ('.__('marketing.finance.reasons.'.($value['reason'] ?? 'missing_input')).')';
        }
        if (str_ends_with($key, '_minor')) {
            return number_format($value['value'] / 100, 2).' '.$currency;
        }

        return (string) $value['value'];
    }
}
