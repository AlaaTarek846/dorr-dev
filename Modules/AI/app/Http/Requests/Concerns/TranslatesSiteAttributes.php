<?php

namespace Modules\AI\Http\Requests\Concerns;

use Modules\AI\Services\Sites\AiSiteTokenizer;

/**
 * Field names for the AI website builder / hosting requests, so validation errors read
 * "صيغة حقل اللون الرئيسي غير صحيحة" instead of "colors.primary". Labels live in
 * lang/{locale}/validation.php under "ai_site_attributes"; a field without a label keeps Laravel's default.
 */
trait TranslatesSiteAttributes
{
    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $keys = array_keys($this->rules());

        foreach (AiSiteTokenizer::SOCIAL as $network) {
            $keys[] = 'social.'.$network;
        }

        // The whole group is read as one array: keys such as "colors.primary" contain dots, which
        // the translator would otherwise try to walk as nested levels.
        $all = __('validation.ai_site_attributes');
        $all = is_array($all) ? $all : [];

        $labels = [];

        foreach (array_unique($keys) as $key) {
            if (isset($all[$key])) {
                $labels[$key] = $all[$key];
            }
        }

        return $labels;
    }
}
