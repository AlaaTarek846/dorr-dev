<?php

namespace Modules\Marketing\Services\Results;

use App\Models\Currency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Marketing\Exceptions\MarketingException;
use Modules\Marketing\Models\MarketingContentItem;
use Modules\Marketing\Models\MarketingMetric;
use Modules\Marketing\Models\MarketingProject;
use Modules\Marketing\Services\Budgets\BudgetService;
use Modules\Marketing\Services\MarketingAccess;
use Modules\Marketing\Services\MarketingAudit;
use Modules\Marketing\Services\Optimisation\AlertRuleService;

/**
 * Results entered by hand or from a CSV file (MK-B04). One row per project, channel, day, basis and content item:
 * entering the same day again (or importing the same file twice) updates it instead of counting it twice. A file with
 * any bad line is refused whole, with the line numbers, so nothing half-imported is ever shown.
 */
class MetricsService
{
    public const MAX_ROWS = 200;

    public const MAX_FILE_ROWS = 2000;

    /** Column names a file may use (English or Arabic). */
    private const COLUMNS = [
        'date' => ['date', 'day', 'التاريخ', 'اليوم'],
        'channel' => ['channel', 'platform', 'القناة', 'المنصة'],
        'impressions' => ['impressions', 'views', 'reach', 'الظهور', 'المشاهدات', 'مرات الظهور'],
        'clicks' => ['clicks', 'link clicks', 'النقرات'],
        'spend' => ['spend', 'cost', 'amount spent', 'amount spent (sar)', 'الإنفاق', 'التكلفة', 'المبلغ المنفق'],
        'conversions' => ['conversions', 'results', 'orders', 'purchases', 'التحويلات', 'النتائج', 'الطلبات'],
        'revenue' => ['revenue', 'sales', 'purchase value', 'الإيراد', 'الإيرادات', 'المبيعات'],
    ];

    private const CHANNEL_ALIASES = [
        'meta' => 'facebook', 'fb' => 'facebook', 'ig' => 'instagram', 'twitter' => 'x', 'snap' => 'snapchat', 'google ads' => 'google',
        'انستجرام' => 'instagram', 'انستقرام' => 'instagram', 'فيسبوك' => 'facebook', 'تيك توك' => 'tiktok', 'سناب' => 'snapchat',
        'سناب شات' => 'snapchat', 'تويتر' => 'x', 'جوجل' => 'google', 'يوتيوب' => 'youtube', 'واتساب' => 'whatsapp',
    ];

    public function __construct(
        private readonly MarketingAccess $access,
        private readonly MarketingAudit $audit,
        private readonly BudgetService $budgets,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $rows  validated
     * @return array{created: int, updated: int}
     */
    public function save(MarketingProject $project, Model $actor, array $rows): array
    {
        $this->access->assert($project, $actor, 'research');
        $contentIds = $this->contentIds($project, collect($rows)->pluck('content_id')->filter()->unique()->values()->all());

        $normalized = [];
        foreach ($rows as $i => $row) {
            $contentId = isset($row['content_id']) ? ($contentIds[$row['content_id']] ?? null) : null;
            if (isset($row['content_id']) && $contentId === null) {
                throw new MarketingException('invalid_rows', 422, [], ['errors' => [['line' => $i + 1, 'field' => 'content_id']]]);
            }
            $normalized[] = [
                'channel' => $row['channel'],
                'metric_date' => Carbon::parse($row['date'])->toDateString(),
                'basis' => $row['basis'] ?? 'imported',
                'content_item_id' => $contentId,
                'impressions' => $row['impressions'] ?? null,
                'clicks' => $row['clicks'] ?? null,
                'spend_minor' => $row['spend_minor'] ?? null,
                'conversions' => $row['conversions'] ?? null,
                'revenue_minor' => $row['revenue_minor'] ?? null,
                'note' => $row['note'] ?? null,
            ];
        }
        $this->assertConsistent($normalized);

        return $this->store($project, $actor, $normalized, 'manual');
    }

    /** @return array{created: int, updated: int, rows: int} */
    public function import(MarketingProject $project, Model $actor, UploadedFile $file): array
    {
        $this->access->assert($project, $actor, 'research');
        $decimals = $this->decimals($project);
        $lines = $this->readCsv($file);
        if (count($lines) < 2) {
            throw new MarketingException('invalid_file', 422, [], ['errors' => [['line' => 1, 'field' => 'header']]]);
        }
        $map = $this->header(array_shift($lines));
        if (! isset($map['date'], $map['channel'])) {
            throw new MarketingException('invalid_file', 422, [], ['errors' => [['line' => 1, 'field' => isset($map['date']) ? 'channel' : 'date']]]);
        }
        if (count($lines) > self::MAX_FILE_ROWS) {
            throw new MarketingException('invalid_file', 422, [], ['errors' => [['line' => self::MAX_FILE_ROWS + 2, 'field' => 'rows']]]);
        }

        $rows = [];
        $errors = [];
        foreach ($lines as $i => $cells) {
            $line = $i + 2;
            if (count(array_filter($cells, fn ($c) => trim((string) $c) !== '')) === 0) {
                continue;
            }
            $get = fn (string $key) => isset($map[$key]) ? trim((string) ($cells[$map[$key]] ?? '')) : '';
            $date = $this->date($get('date'));
            $channel = $this->channel($get('channel'));
            if ($date === null || $date > now()->toDateString()) {
                $errors[] = ['line' => $line, 'field' => 'date'];

                continue;
            }
            if ($channel === null) {
                $errors[] = ['line' => $line, 'field' => 'channel'];

                continue;
            }
            $row = ['channel' => $channel, 'metric_date' => $date, 'basis' => 'imported', 'content_item_id' => null, 'note' => null];
            foreach (['impressions' => 0, 'clicks' => 0, 'conversions' => 0, 'spend' => $decimals, 'revenue' => $decimals] as $key => $places) {
                $value = $this->number($get($key), $places);
                if ($value === false) {
                    $errors[] = ['line' => $line, 'field' => $key];

                    continue 2;
                }
                $row[in_array($key, ['spend', 'revenue'], true) ? $key.'_minor' : $key] = $value;
            }
            $rows[] = $row + ['line' => $line];
        }
        // The same day and channel twice in one file is a mistake in the file, not two results.
        $seen = [];
        foreach ($rows as $row) {
            $k = $row['channel'].'|'.$row['metric_date'];
            if (isset($seen[$k])) {
                $errors[] = ['line' => $row['line'], 'field' => 'duplicate'];
            }
            $seen[$k] = true;
        }
        if ($errors !== [] || $rows === []) {
            throw new MarketingException('invalid_file', 422, [], ['errors' => array_slice($errors ?: [['line' => 2, 'field' => 'rows']], 0, 50)]);
        }
        $rows = array_map(fn ($r) => collect($r)->except('line')->all(), $rows);
        $this->assertConsistent($rows);

        return $this->store($project, $actor, $rows, 'csv') + ['rows' => count($rows)];
    }

    public function delete(MarketingMetric $metric, Model $actor): void
    {
        $this->access->assert($metric->project, $actor, 'research');
        $this->audit->record($metric->project, $actor, 'metric.deleted', $metric, before: $metric->only(['channel', 'basis', 'impressions', 'clicks', 'spend_minor', 'conversions', 'revenue_minor']) + ['date' => $metric->metric_date->toDateString()]);
        $metric->delete();
        $this->budgets->checkAlerts($metric->project, 'ads');
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{created: int, updated: int}
     */
    private function store(MarketingProject $project, Model $actor, array $rows, string $source): array
    {
        $batch = (string) Str::uuid();
        $counts = DB::transaction(function () use ($project, $actor, $rows, $source, $batch) {
            $created = 0;
            $updated = 0;
            foreach ($rows as $row) {
                $key = MarketingMetric::key($row['channel'], $row['metric_date'], $row['basis'], $row['content_item_id']);
                $metric = MarketingMetric::query()->firstOrNew(['project_id' => $project->id, 'row_key' => $key]);
                $metric->exists ? $updated++ : $created++;
                $metric->fill($row + [
                    'source' => $source,
                    'currency' => $project->budget_currency,
                    'import_batch' => $batch,
                    'entered_by_type' => $actor->getMorphClass(),
                    'entered_by_id' => $actor->getKey(),
                ])->save();
            }

            return ['created' => $created, 'updated' => $updated];
        });
        $this->audit->record($project, $actor, 'metrics.saved', null, meta: $counts + ['source' => $source, 'batch' => $batch]);
        $this->budgets->checkAlerts($project, 'ads');
        app(AlertRuleService::class)->check($project);

        return $counts;
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function assertConsistent(array $rows): void
    {
        $errors = [];
        foreach ($rows as $i => $row) {
            if ($row['clicks'] !== null && $row['impressions'] !== null && $row['clicks'] > $row['impressions']) {
                $errors[] = ['line' => $i + 1, 'field' => 'clicks'];
            }
            if ($row['metric_date'] > now()->toDateString()) {
                $errors[] = ['line' => $i + 1, 'field' => 'date'];
            }
            if (collect(MarketingMetric::FIELDS)->every(fn ($f) => $row[$f] === null)) {
                $errors[] = ['line' => $i + 1, 'field' => 'values'];
            }
        }
        if ($errors !== []) {
            throw new MarketingException('invalid_rows', 422, [], ['errors' => array_slice($errors, 0, 50)]);
        }
    }

    /** @return array<string, int> uuid => id */
    private function contentIds(MarketingProject $project, array $uuids): array
    {
        return $uuids === [] ? [] : MarketingContentItem::query()->where('project_id', $project->id)->whereIn('uuid', $uuids)->pluck('id', 'uuid')->all();
    }

    private function decimals(MarketingProject $project): int
    {
        $places = Currency::query()->where('code', $project->budget_currency)->value('decimal_places');

        return $places === null ? 2 : max(0, min(3, (int) $places));
    }

    /** @return list<list<string>> */
    private function readCsv(UploadedFile $file): array
    {
        $text = (string) file_get_contents($file->getRealPath());
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1256');
        }
        $first = strtok($text, "\n") ?: '';
        $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : (substr_count($first, "\t") > substr_count($first, ',') ? "\t" : ',');
        $rows = [];
        foreach (preg_split('/\r\n|\n|\r/', $text) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $rows[] = str_getcsv($line, $delimiter);
            if (count($rows) > self::MAX_FILE_ROWS + 1) {
                break;
            }
        }

        return $rows;
    }

    /** @return array<string, int> field => column index */
    private function header(array $cells): array
    {
        $map = [];
        foreach ($cells as $index => $cell) {
            $name = mb_strtolower(trim((string) $cell));
            foreach (self::COLUMNS as $field => $names) {
                if (! isset($map[$field]) && in_array($name, $names, true)) {
                    $map[$field] = $index;
                }
            }
        }

        return $map;
    }

    private function date(string $value): ?string
    {
        $value = strtr($value, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'Y/m/d', 'd.m.Y'] as $format) {
            $date = \DateTime::createFromFormat('!'.$format, $value);
            if ($date !== false && $date->format($format) === $value) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    private function channel(string $value): ?string
    {
        $value = mb_strtolower(trim($value));
        $value = self::CHANNEL_ALIASES[$value] ?? $value;

        return in_array($value, MarketingContentItem::CHANNELS, true) ? $value : null;
    }

    /** "1,234.50" → minor units with `$places` decimals; '' → null; anything else → false. */
    private function number(string $value, int $places): int|false|null
    {
        $value = strtr($value, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '٫' => '.', '٬' => ',']);
        $value = trim(str_replace([',', ' ', "\u{00A0}"], '', $value));
        $value = preg_replace('/^(?:SAR|EGP|AED|USD|ر\.س|ج\.م)\s*|\s*(?:SAR|EGP|AED|USD|ر\.س|ج\.م)$/u', '', $value);
        if ($value === '' || $value === '-') {
            return null;
        }
        if (! preg_match('/^\d+(?:\.\d+)?$/', $value)) {
            return false;
        }
        $minor = (int) round(((float) $value) * (10 ** $places));

        return $minor > 9_000_000_000_000 ? false : $minor;
    }
}
