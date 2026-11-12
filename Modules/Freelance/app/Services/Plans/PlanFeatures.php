<?php

namespace Modules\Freelance\Services\Plans;

use Illuminate\Validation\ValidationException;
use Modules\Freelance\Models\FreelancePlan;
use Modules\Freelance\Models\FreelancePlanFeature;
use Modules\Freelance\Models\FreelanceTeam;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\FreelancerTeam\TeamPlans;
use Modules\Freelance\Services\Policy\FreelanceMoney;
use Modules\Freelance\Services\Policy\FreelancePolicies;

/**
 * FS-705: what a plan gives, one catalog for every feature (`config('freelance.plan_features')`). It answers "what does this freelancer -
 * or this team - have now?" with the same rule everywhere: **a plan only ever makes it better than the country gives with no plan**.
 *
 *   switch   the plan's own when it sets one, else off
 *   level    the plan's own (up to the feature's `max`), else 0
 *   limit    no cap when the base has none or the plan lifts it; else the larger of the base and the plan's number
 *   fee_bp   the lower of the base and the plan's own (a plan never raises a fee)
 *
 * The base is what a freelancer or a team has with no plan: a policy key of the country, a config path, or a fixed number. A plan
 * "gives" a feature when it sets a value of its own: a row of `freelance_plan_features`, or - for the features from before FS-705 - its
 * column (an empty column follows the base, or means no cap where the feature says `null => no_cap`). The Free plan gives nothing.
 *
 * A team's value counts on the team's work only (owner, 2026-11-12): `forTeam` reads the team's plan, never a member's, and `forFreelancer`
 * reads the person's own plan, never a team's. A feature is checked only where it is `live` for that audience; until then it is listed as
 * coming and never counted as a reason to buy (`sellable`).
 */
class PlanFeatures
{
    public const SWITCH = 'switch';

    public const LEVEL = 'level';

    public const LIMIT = 'limit';

    public const FEE = 'fee_bp';

    public const TYPES = [self::SWITCH, self::LEVEL, self::LIMIT, self::FEE];

    public function __construct(private readonly FreelancePolicies $policies) {}

    /** @return array<string, array<string, mixed>> the whole catalog, by key */
    public function definitions(): array
    {
        return (array) config('freelance.plan_features', []);
    }

    /** @return array<string, mixed> one feature (404-free: an unknown key is a programming mistake) */
    public function definition(string $key): array
    {
        $def = $this->definitions()[$key] ?? null;
        if ($def === null) {
            throw new \InvalidArgumentException("Unknown plan feature [{$key}].");
        }

        return $def;
    }

    /** Who the plan is sold to (a plan made in code before it was read back has no `audience` yet: the column's default, a freelancer). */
    private function audienceOf(FreelancePlan $plan): string
    {
        return (string) ($plan->audience ?? FreelancePlan::FREELANCER);
    }

    public function sells(string $key, string $audience): bool
    {
        return in_array($audience, (array) ($this->definition($key)['audience'] ?? []), true);
    }

    /** Whether the checks of this audience read the feature today. */
    public function isLive(string $key, string $audience): bool
    {
        return $this->sells($key, $audience) && in_array($audience, (array) ($this->definition($key)['live'] ?? []), true);
    }

    /**
     * The features sold to an audience, in the catalog's order, with their names in the app's language.
     *
     * @return list<array{key: string, type: string, group: string, max: ?int, live: bool, name: string, hint: ?string}>
     */
    public function catalog(string $audience): array
    {
        $out = [];
        foreach ($this->definitions() as $key => $def) {
            if (! $this->sells($key, $audience)) {
                continue;
            }
            $hint = __("freelance.plan_features.{$key}.hint");
            $out[] = [
                'key' => $key, 'type' => $def['type'], 'group' => $def['group'] ?? 'other', 'max' => isset($def['max']) ? (int) $def['max'] : null,
                // A feature kept in its own column of the plan is edited with the plan's own fields, the others in the features list.
                'column' => isset($def['column']),
                'live' => $this->isLive($key, $audience), 'name' => __("freelance.plan_features.{$key}.name"),
                'hint' => $hint === "freelance.plan_features.{$key}.hint" ? null : $hint,
            ];
        }

        return $out;
    }

    // ------------------------------------------------------------------ what a plan sets

    /**
     * What the plan sets of its own for a feature: `given` false = nothing (the base rules); `value` null with `given` true = no cap.
     *
     * @return array{given: bool, value: ?int}
     */
    public function raw(FreelancePlan $plan, string $key): array
    {
        $def = $this->definition($key);
        if ($plan->isFree() || ! $this->sells($key, $this->audienceOf($plan))) {
            return ['given' => false, 'value' => null];
        }
        if (isset($def['column'])) {
            if ($key === 'offers_monthly' && $plan->offers_unlimited) {
                return ['given' => true, 'value' => null];
            }
            $value = $plan->getAttribute($def['column']);
            if ($value === null) {
                return ['given' => ($def['null'] ?? 'base') === 'no_cap', 'value' => null];
            }

            return ['given' => true, 'value' => (int) $value];
        }
        $row = $plan->relationLoaded('features')
            ? $plan->features->firstWhere('key', $key)
            : FreelancePlanFeature::query()->where('plan_id', $plan->id)->where('key', $key)->first();

        return $row === null ? ['given' => false, 'value' => null] : ['given' => true, 'value' => $row->value === null ? null : (int) $row->value];
    }

    /** @return array<string, array{given: bool, value: ?int}> everything the plan sets, for the admin's matrix */
    public function matrix(FreelancePlan $plan): array
    {
        $plan->loadMissing('features');
        $out = [];
        foreach ($this->definitions() as $key => $def) {
            if ($this->sells($key, $this->audienceOf($plan))) {
                $out[$key] = $this->raw($plan, $key);
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------ what someone has

    /** What a freelancer has now on their own plan (Free when none): never a team's plan. */
    public function forFreelancer(Freelancer $freelancer, string $key): int|bool|null
    {
        return $this->value(app(FreelancePlans::class)->planFor($freelancer), $key, FreelancePlan::FREELANCER, $freelancer->country_id === null ? null : (int) $freelancer->country_id);
    }

    /** What a team has now on its plan (the free baseline when none), for the team's work only: never a member's own plan. */
    public function forTeam(FreelanceTeam $team, string $key): int|bool|null
    {
        return $this->value(app(TeamPlans::class)->plan($team), $key, FreelancePlan::TEAM, $team->country_id === null ? null : (int) $team->country_id);
    }

    /** The value on a plan (null plan = no plan) for an audience in a country, by the feature's type (see the class comment). */
    public function value(?FreelancePlan $plan, string $key, string $audience, ?int $countryId): int|bool|null
    {
        $def = $this->definition($key);
        $base = $this->base($key, $audience, $countryId);
        $raw = $plan === null ? ['given' => false, 'value' => null] : $this->raw($plan, $key);

        return $this->better($def, $base, $raw);
    }

    /** What the audience has with no plan in a country (`null`: no cap for a limit, nothing set otherwise). */
    public function base(string $key, string $audience, ?int $countryId): ?int
    {
        $def = $this->definition($key);
        $source = $def['base'] ?? null;
        if (is_array($source) && isset($source[$audience]) && is_array($source[$audience])) {
            $source = $source[$audience];
        }
        if (! is_array($source)) {
            return null;
        }
        if (array_key_exists('value', $source)) {
            return $source['value'] === null ? null : (int) $source['value'];
        }
        if (isset($source['config'])) {
            $v = config($source['config']);

            return $v === null ? null : (int) $v;
        }
        if (isset($source['policy'])) {
            $v = $countryId === null
                ? config('freelance.policy_defaults.'.$source['policy'])
                : $this->policies->for($countryId)->get($source['policy']);

            return $v === null ? null : (int) $v;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $def
     * @param  array{given: bool, value: ?int}  $raw
     */
    private function better(array $def, ?int $base, array $raw): int|bool|null
    {
        return match ($def['type']) {
            self::SWITCH => $raw['given'] ? (bool) $raw['value'] : (bool) $base,
            self::LEVEL => $raw['given'] ? max(0, min((int) ($def['max'] ?? 1), (int) $raw['value'])) : max(0, (int) $base),
            self::LIMIT => match (true) {
                $base === null => null,
                ! $raw['given'] => $base,
                $raw['value'] === null => null,
                default => max($base, (int) $raw['value']),
            },
            self::FEE => match (true) {
                ! $raw['given'] || $raw['value'] === null => $base,
                $base === null => (int) $raw['value'],
                default => min($base, (int) $raw['value']),
            },
        };
    }

    // ------------------------------------------------------------------ selling

    /**
     * Whether a plan gives this audience more than the base on a live feature: what is not wired yet never counts, so nobody pays for it.
     */
    public function sellable(FreelancePlan $plan, string $audience, ?int $countryId): bool
    {
        foreach (array_keys($this->definitions()) as $key) {
            if ($this->isLive($key, $audience) && $this->value($plan, $key, $audience, $countryId) !== $this->value(null, $key, $audience, $countryId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The lowest plan on sale (offered, with a price in the freelance currency) whose value beats `$than`: what an upsell names.
     *
     * @return array{slug: string, name: ?string}|null
     */
    public function lowestPlanBeyond(string $audience, string $key, int|bool|null $than, ?int $countryId): ?array
    {
        $priceCountry = app(FreelanceMoney::class)->countryId();
        $plans = FreelancePlan::query()->where('audience', $audience)->where('status', true)->where('rank', '>', 0)
            ->whereHas('prices', fn ($p) => $p->where('country_id', $priceCountry)->where('status', true))
            ->with('features')->orderBy('rank')->get();
        $def = $this->definition($key);
        foreach ($plans as $plan) {
            if ($this->beats($def, $this->value($plan, $key, $audience, $countryId), $than)) {
                return ['slug' => $plan->slug, 'name' => $plan->text('name')];
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $def */
    private function beats(array $def, int|bool|null $value, int|bool|null $than): bool
    {
        return match ($def['type']) {
            self::SWITCH => (bool) $value && ! $than,
            self::LEVEL => (int) $value > (int) $than,
            self::LIMIT => $than !== null && ($value === null || (int) $value > (int) $than),
            self::FEE => $value !== null && ($than === null || (int) $value < (int) $than),
        };
    }

    // ------------------------------------------------------------------ the admin and the defaults

    /**
     * Sets what a plan gives (`$value` null on a limit = no cap; `$clear` = the plan no longer sets it, the base rules). Validated by type and
     * audience; Free sets nothing.
     */
    public function set(FreelancePlan $plan, string $key, int|bool|null $value, bool $clear = false): void
    {
        $def = $this->definition($key);
        $field = "features.{$key}";
        if ($plan->isFree() || ! $this->sells($key, $this->audienceOf($plan))) {
            throw ValidationException::withMessages([$field => __('validation.prohibited', ['attribute' => $key])]);
        }
        if (! $clear) {
            $value = $this->validValue($def, $value, $field);
        }
        if (isset($def['column'])) {
            if ($key === 'offers_monthly') {
                $plan->forceFill(['offers_unlimited' => ! $clear && $value === null, 'offers_monthly' => $clear || $value === null ? null : $value])->save();

                return;
            }
            $plan->forceFill([$def['column'] => $clear ? $this->columnCleared($def) : $value])->save();

            return;
        }
        if ($clear) {
            FreelancePlanFeature::query()->where('plan_id', $plan->id)->where('key', $key)->delete();
        } else {
            FreelancePlanFeature::query()->updateOrCreate(['plan_id' => $plan->id, 'key' => $key], ['value' => $value === null ? null : (int) $value]);
        }
        $plan->unsetRelation('features');
    }

    /** @param  array<string, mixed>  $def */
    private function validValue(array $def, int|bool|null $value, string $field): ?int
    {
        $fail = fn () => throw ValidationException::withMessages([$field => __('validation.invalid')]);

        return match ($def['type']) {
            self::SWITCH => $value === null ? $fail() : (int) (bool) $value,
            self::LEVEL => $value === null || (int) $value < 0 || (int) $value > (int) ($def['max'] ?? 1) ? $fail() : (int) $value,
            self::LIMIT => $value === null ? null : ((int) $value < 0 || (int) $value > 1000000 ? $fail() : (int) $value),
            self::FEE => $value === null || (int) $value < 0 || (int) $value > 10000 ? $fail() : (int) $value,
        };
    }

    /** What a column holds when the plan stops setting it: empty for numbers, off for a switch, 0 for a level. @param array<string, mixed> $def */
    private function columnCleared(array $def): int|bool|null
    {
        return match ($def['type']) {
            self::SWITCH => false,
            self::LEVEL => 0,
            default => null,
        };
    }

    /**
     * The catalog values a plan starts with (`plans.<slug>.features`): adds what is missing and never overwrites an admin's value.
     *
     * @param  array<string, int|bool|null>  $features
     * @return int rows added
     */
    public function seedDefaults(FreelancePlan $plan, array $features): int
    {
        $added = 0;
        foreach ($features as $key => $value) {
            $def = $this->definitions()[$key] ?? null;
            if ($def === null || isset($def['column']) || $plan->isFree() || ! $this->sells($key, $this->audienceOf($plan))) {
                continue;
            }
            $row = FreelancePlanFeature::query()->firstOrCreate(['plan_id' => $plan->id, 'key' => $key], ['value' => $value === null ? null : (int) $value]);
            $added += $row->wasRecentlyCreated ? 1 : 0;
        }

        return $added;
    }
}
