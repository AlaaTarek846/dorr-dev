<?php

namespace Modules\WatchEarn\Services;

use App\Models\Country;
use Carbon\CarbonInterface;
use Modules\AI\Services\DocumentGeneration\AiPdfDocumentRenderer;
use Modules\AI\Services\DocumentGeneration\AiXlsxDocumentRenderer;
use Modules\WatchEarn\Models\Ad;

/**
 * PDF / Excel reports (WE-024 for the advertiser, WE-043 for the admin, REQ-024 / SC-18): counts and money only —
 * never who watched, and never the platform/viewer split to the advertiser. Rendered by the AI module's Arabic-correct
 * document renderers (Cairo font, RTL), from the same blocks for both formats.
 */
class ExportService
{
    public function __construct(private readonly StatsService $stats) {}

    /** @return array{0: string, 1: string, 2: string} [bytes, file name, mime] */
    public function ad(Ad $ad, string $format): array
    {
        $ad->loadMissing('country.currency');
        $s = $this->stats->forAd($ad);
        $money = fn (int $minor) => $this->money($minor, $ad->country);
        $t = $s['totals'];
        $m = $s['money'];

        $blocks = [
            ['type' => 'heading', 'level' => 1, 'text' => __('watch_earn.export.ad_title', ['title' => $ad->title])],
            ['type' => 'paragraph', 'text' => __('watch_earn.export.period', ['from' => $ad->starts_at->toDateString(), 'to' => $ad->ends_at->toDateString()])
                .' · '.__('watch_earn.export.status').': '.__('watch_earn.status.'.$ad->status->value)],
            ['type' => 'heading', 'level' => 2, 'text' => __('watch_earn.export.results')],
            ['type' => 'table', 'headers' => [__('watch_earn.export.metric'), __('watch_earn.export.value')], 'rows' => [
                [__('watch_earn.export.impressions'), $t['impressions']],
                [__('watch_earn.export.completed'), $t['completed']],
                [__('watch_earn.export.completion_rate'), $t['completion_rate'].'%'],
                [__('watch_earn.export.interactions'), $t['interactions']],
                [__('watch_earn.export.interaction_rate'), $t['interaction_rate'].'%'],
                [__('watch_earn.export.comments'), $t['comments']],
                [__('watch_earn.export.reactions'), $t['reactions']],
            ]],
            ['type' => 'heading', 'level' => 2, 'text' => __('watch_earn.export.money')],
            ['type' => 'table', 'headers' => [__('watch_earn.export.metric'), __('watch_earn.export.value')], 'rows' => [
                [__('watch_earn.export.budget'), $money($m['budget_minor'])],
                [__('watch_earn.export.spent'), $money($m['spent_minor'])],
                [__('watch_earn.export.remaining'), $money($m['remaining_minor'])],
                [__('watch_earn.export.price_per_view'), $money($m['price_per_view_minor'])],
                [__('watch_earn.export.views_left'), $m['views_left']],
                [__('watch_earn.export.cost_per_interaction'), $m['cost_per_interaction_minor'] === null ? '—' : $money($m['cost_per_interaction_minor'])],
            ]],
            ['type' => 'heading', 'level' => 2, 'text' => __('watch_earn.export.daily')],
            ['type' => 'table', 'headers' => [__('watch_earn.export.date'), __('watch_earn.export.completed'), __('watch_earn.export.interactions')],
                'rows' => array_map(fn ($d) => [$d['date'], $d['completed'], $d['interactions']], $s['daily'])],
            ['type' => 'heading', 'level' => 2, 'text' => __('watch_earn.export.by_city')],
            ['type' => 'table', 'headers' => [__('watch_earn.export.city'), __('watch_earn.export.completed')],
                'rows' => array_map(fn ($c) => [$c['name'] ?? __('watch_earn.export.unknown_city'), $c['completed']], $s['by_city'])],
            ['type' => 'heading', 'level' => 2, 'text' => __('watch_earn.export.by_gender')],
            ['type' => 'table', 'headers' => [__('watch_earn.export.gender'), __('watch_earn.export.completed')],
                'rows' => array_map(fn ($k, $n) => [__('watch_earn.export.gender_'.$k), $n], array_keys($s['by_gender']), $s['by_gender'])],
        ];

        return $this->render($blocks, __('watch_earn.export.ad_title', ['title' => $ad->title]), 'watch-earn-'.substr($ad->uuid, 0, 8), $format);
    }

    /** @param  array<string, mixed>  $report  WatchEarnAdController::finance()'s numbers */
    public function finance(Country $country, CarbonInterface $from, CarbonInterface $to, array $report, string $format): array
    {
        $country->loadMissing('currency');
        $money = fn (int $minor) => $this->money($minor, $country);
        $rows = [];
        foreach (['platform_revenue_minor', 'spent_minor', 'rewards_paid_minor', 'rewards_waiting_minor', 'ad_wallets_free_minor', 'ads_locked_minor'] as $key) {
            $rows[] = [__('watch_earn.export.finance_'.$key), $money((int) $report[$key])];
        }
        $rows[] = [__('watch_earn.export.completed'), $report['completed_views']];

        $title = __('watch_earn.export.finance_title', ['country' => $country->translatedName() ?? $country->code]);

        return $this->render([
            ['type' => 'heading', 'level' => 1, 'text' => $title],
            ['type' => 'paragraph', 'text' => __('watch_earn.export.period', ['from' => $from->toDateString(), 'to' => $to->toDateString()])],
            ['type' => 'table', 'headers' => [__('watch_earn.export.metric'), __('watch_earn.export.value')], 'rows' => $rows],
        ], $title, 'watch-earn-finance-'.$country->code.'-'.$from->toDateString(), $format);
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function render(array $blocks, string $title, string $name, string $format): array
    {
        return $format === 'xlsx'
            ? [app(AiXlsxDocumentRenderer::class)->render($title, $blocks), $name.'.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
            : [app(AiPdfDocumentRenderer::class)->render($title, $blocks), $name.'.pdf', 'application/pdf'];
    }

    private function money(int $minor, ?Country $country): string
    {
        $decimals = (int) ($country?->currency?->decimal_places ?? 2);

        return number_format($minor / (10 ** $decimals), $decimals).' '.($country?->currency?->code ?? '');
    }
}
