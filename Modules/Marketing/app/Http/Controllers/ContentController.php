<?php

namespace Modules\Marketing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Modules\Marketing\Http\Resources\ContentItemResource;
use Modules\Marketing\Http\Resources\JobResource;
use Modules\Marketing\Models\MarketingContentItem;
use Modules\Marketing\Models\MarketingProject;
use Modules\Marketing\Services\Content\ContentService;
use Modules\Marketing\Services\MarketingAccess;

/** The calendar (U06) and the ad studio (U07). */
class ContentController extends Controller
{
    public function __construct(private readonly ContentService $content, private readonly MarketingAccess $access) {}

    /** GET marketing/projects/{project}/calendar?from=&to= — at most 62 days, by planned time */
    public function calendar(Request $request, MarketingProject $project)
    {
        $this->access->assert($project, $request->user());
        $data = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $from = isset($data['from']) ? Carbon::parse($data['from'])->startOfDay() : now()->startOfDay();
        $to = isset($data['to']) ? Carbon::parse($data['to'])->endOfDay() : $from->copy()->addDays(6)->endOfDay();
        if ($from->diffInDays($to) > 62) {
            $to = $from->copy()->addDays(62)->endOfDay();
        }
        $items = MarketingContentItem::query()->where('project_id', $project->id)->whereBetween('planned_at', [$from, $to])
            ->with('plan')->orderBy('planned_at')->orderBy('id')->get();

        return ApiResponse::success([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'items' => ContentItemResource::collection($items),
            'unplanned' => MarketingContentItem::query()->where('project_id', $project->id)->whereNull('planned_at')
                ->whereNotIn('status', [MarketingContentItem::PUBLISHED, MarketingContentItem::DONE])->count(),
        ], __('api.retrieved'));
    }

    /** POST marketing/projects/{project}/calendar/generate  {days, from?, plan?, channels?, replace_drafts?, timezone?} — 202 */
    public function generateCalendar(Request $request, MarketingProject $project)
    {
        $data = $request->validate([
            'days' => ['required', 'integer', Rule::in([7, 14, 30])],
            'from' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.now()->subDay()->toDateString(), 'before_or_equal:'.now()->addDays(60)->toDateString()],
            'plan' => ['nullable', 'uuid'],
            'channels' => ['nullable', 'array', 'max:8'],
            'channels.*' => [Rule::in(MarketingContentItem::CHANNELS)],
            'replace_drafts' => ['nullable', 'boolean'],
            'timezone' => ['nullable', 'timezone:all'],
        ]);
        $started = $this->content->generateCalendar($project, $request->user(), $data);

        return ApiResponse::success(['job' => new JobResource($started['job']), 'plan_id' => $started['plan']->uuid], __('marketing.messages.calendar_started'), 202);
    }

    /** GET marketing/projects/{project}/content?status=&kind=&channel= */
    public function index(Request $request, MarketingProject $project)
    {
        $this->access->assert($project, $request->user());
        $data = $request->validate([
            'status' => ['nullable', 'string', 'max:20'],
            'kind' => ['nullable', Rule::in(MarketingContentItem::KINDS)],
            'channel' => ['nullable', Rule::in(MarketingContentItem::CHANNELS)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $page = MarketingContentItem::query()->where('project_id', $project->id)->with('plan')
            ->when($data['status'] ?? null, fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
            ->when($data['kind'] ?? null, fn ($q, $k) => $q->where('kind', $k))
            ->when($data['channel'] ?? null, fn ($q, $c) => $q->where('channel', $c))
            ->orderByRaw('planned_at IS NULL')->orderByDesc('updated_at')->paginate($data['per_page'] ?? 20);

        return ApiResponse::fromPaginator($page, ContentItemResource::collection($page->getCollection()), __('api.retrieved'));
    }

    /** POST marketing/projects/{project}/content — a post, an ad or a task written by hand */
    public function store(Request $request, MarketingProject $project)
    {
        $data = $request->validate($this->rules(true));
        $item = $this->content->create($project, $request->user(), $data);

        return ApiResponse::created(new ContentItemResource($item->load('plan')), __('marketing.messages.content_saved'));
    }

    /** POST marketing/projects/{project}/content/generate  {channel, brief, kind?, format?, objective?, variants?, planned_at?, plan?} — 202 */
    public function generate(Request $request, MarketingProject $project)
    {
        $data = $request->validate([
            'channel' => ['required', Rule::in(MarketingContentItem::CHANNELS)],
            'kind' => ['nullable', Rule::in(['post', 'ad'])],
            'format' => ['nullable', Rule::in(MarketingContentItem::FORMATS)],
            'objective' => ['nullable', 'string', 'max:300'],
            'brief' => ['required', 'string', 'min:5', 'max:1000'],
            'variants' => ['nullable', 'integer', 'min:2', 'max:3'],
            'planned_at' => ['nullable', 'date', 'after:now'],
            'plan' => ['nullable', 'uuid'],
        ]);
        $started = $this->content->generate($project, $request->user(), $data);

        return ApiResponse::success(['job' => new JobResource($started['job'])], __('marketing.messages.content_started'), 202);
    }

    /** GET marketing/content/{item} */
    public function show(Request $request, MarketingContentItem $item)
    {
        $this->access->assert($item->project, $request->user());

        return ApiResponse::success(new ContentItemResource($item->load('plan')), __('api.retrieved'));
    }

    /** PATCH marketing/content/{item}  {lock_version, …} — new words on an approved item send it back to draft */
    public function update(Request $request, MarketingContentItem $item)
    {
        $data = $request->validate(['lock_version' => ['required', 'integer']] + $this->rules(false));

        return ApiResponse::success(new ContentItemResource($this->content->update($item, $request->user(), $data)->load('plan')), __('marketing.messages.content_saved'));
    }

    /** POST marketing/content/{item}/submit */
    public function submit(Request $request, MarketingContentItem $item)
    {
        return ApiResponse::success(new ContentItemResource($this->content->submit($item, $request->user())->load('plan')), __('marketing.messages.content_submitted'));
    }

    /** POST marketing/content/{item}/approve  {lock_version} */
    public function approve(Request $request, MarketingContentItem $item)
    {
        $data = $request->validate(['lock_version' => ['required', 'integer']]);

        return ApiResponse::success(new ContentItemResource($this->content->approve($item, $request->user(), (int) $data['lock_version'])->load('plan')), __('marketing.messages.content_approved'));
    }

    /** POST marketing/content/{item}/reject  {note?} */
    public function reject(Request $request, MarketingContentItem $item)
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        return ApiResponse::success(new ContentItemResource($this->content->reject($item, $request->user(), $data['note'] ?? null)->load('plan')), __('marketing.messages.content_rejected'));
    }

    /** POST marketing/content/{item}/schedule  {planned_at} */
    public function schedule(Request $request, MarketingContentItem $item)
    {
        $data = $request->validate(['planned_at' => ['required', 'date', 'after:now']]);

        return ApiResponse::success(new ContentItemResource($this->content->schedule($item, $request->user(), Carbon::parse($data['planned_at']))->load('plan')), __('marketing.messages.content_scheduled'));
    }

    /** POST marketing/content/{item}/outcome  {published: bool, url?, note?} — the person posted it themselves */
    public function outcome(Request $request, MarketingContentItem $item)
    {
        $data = $request->validate([
            'published' => ['required', 'boolean'],
            'url' => ['nullable', 'url:http,https', 'max:500'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $item = $this->content->outcome($item, $request->user(), (bool) $data['published'], $data['url'] ?? null, $data['note'] ?? null);

        return ApiResponse::success(new ContentItemResource($item->load('plan')), __('marketing.messages.content_saved'));
    }

    /** POST marketing/content/{item}/done  {done: bool} — tasks */
    public function done(Request $request, MarketingContentItem $item)
    {
        $data = $request->validate(['done' => ['required', 'boolean']]);

        return ApiResponse::success(new ContentItemResource($this->content->done($item, $request->user(), (bool) $data['done'])->load('plan')), __('marketing.messages.content_saved'));
    }

    /** DELETE marketing/content/{item} */
    public function destroy(Request $request, MarketingContentItem $item)
    {
        $this->content->delete($item, $request->user());

        return ApiResponse::success(null, __('marketing.messages.content_deleted'));
    }

    private function rules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'kind' => [$creating ? 'required' : 'prohibited', Rule::in(MarketingContentItem::KINDS)],
            'channel' => [$req, Rule::in(MarketingContentItem::CHANNELS)],
            'format' => ['nullable', Rule::in(MarketingContentItem::FORMATS)],
            'title' => [$req, 'string', 'min:2', 'max:160'],
            'objective' => ['nullable', 'string', 'max:300'],
            'variants' => [$creating ? 'required_unless:kind,task' : 'sometimes', 'array', 'max:3'],
            'variants.*.headline' => ['nullable', 'string', 'max:120'],
            'variants.*.body' => ['nullable', 'string', 'max:2200'],
            'variants.*.cta' => ['nullable', 'string', 'max:60'],
            'variants.*.hashtags' => ['nullable', 'array', 'max:10'],
            'variants.*.hashtags.*' => ['nullable', 'string', 'max:40'],
            'variants.*.visual_brief' => ['nullable', 'string', 'max:500'],
            'selected' => ['nullable', Rule::in(['A', 'B', 'C'])],
            'planned_at' => ['nullable', 'date'],
            'cost_estimate_minor' => ['nullable', 'integer', 'min:0', 'max:100000000000'],
            'plan' => [$creating ? 'nullable' : 'prohibited', 'uuid'],
        ];
    }
}
