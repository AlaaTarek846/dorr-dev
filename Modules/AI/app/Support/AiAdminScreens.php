<?php

namespace Modules\AI\Support;

/**
 * Permission groups of the AI assistant's admin screens (docs/screens/README.md "Screen standard").
 * One group per screen API (admin/v1/<group>), guarded by `admin.screen:<group>` in Modules/AI/routes/admin.php.
 *
 * Source for AdminPermissionSeeder. The migrations that create these rows on existing databases keep their own frozen
 * copy (a migration must not change after it ran) - add a new migration when this list grows.
 */
final class AiAdminScreens
{
    private const CATALOG = ['view', 'create', 'update', 'delete', 'change-status', 'multiple-delete'];

    /**
     * group => actions. ai-settings, ai-plans, ai-site-offers and ai-site-hosting-plans guard themselves in their
     * (catalog) controllers; every other group is guarded by admin.screen on its route group.
     *
     * @var array<string, list<string>>
     */
    public const GROUPS = [
        // Core & plans
        'ai-settings' => ['view', 'update'],
        'ai-plans' => self::CATALOG,
        'ai-site-offers' => self::CATALOG,
        'ai-site-requests' => ['view', 'update', 'change-status'],
        'ai-ar' => ['view', 'update'],
        'ai-world' => ['view', 'update'],
        'ai-site-download' => ['view', 'update'],
        'ai-sites' => ['view', 'update', 'delete'],
        'ai-site-hosting-plans' => self::CATALOG,
        'ai-site-hostings' => ['view', 'update', 'delete'],
        'ai-subscriptions' => ['view', 'create', 'update', 'delete'],
        'ai-usage-sessions' => ['view'],
        'ai-trial-control' => ['view', 'update'],
        // Routing & policies
        'ai-intents' => self::CATALOG,
        'ai-learned-intents' => self::CATALOG,
        'ai-routing-policies' => self::CATALOG,
        'ai-gateways' => self::CATALOG,
        'ai-routing-rules' => self::CATALOG,
        'ai-feature-flags' => self::CATALOG,
        'ai-domain-policies' => self::CATALOG,
        // Safety
        'ai-safety-policies' => self::CATALOG,
        'ai-safety-rules' => self::CATALOG,
        'ai-safety-events' => ['view'],
        'ai-safety-scans' => ['view'],
        // Security & privacy
        'ai-security-policies' => self::CATALOG,
        'ai-data-policies' => self::CATALOG,
        'ai-provider-data-rules' => self::CATALOG,
        'ai-security-events' => ['view'],
        // Requests & responses
        'ai-requests' => ['view'],
        'ai-responses' => ['view'],
        'ai-usage' => ['view'],
        'ai-verifications' => ['view'],
        'ai-code-executions' => ['view'],
        'ai-audit-events' => ['view'],
        // Conversations
        'ai-conversation-contexts' => ['view'],
        'ai-conversation-attachments' => ['view'],
        'ai-conversation-instructions' => self::CATALOG,
        // Providers & monitoring
        'ai-provider-logs' => ['view'],
        'ai-provider-health' => ['view'],
        'ai-failovers' => ['view'],
        'ai-reliability-metrics' => ['view'],
        // Language
        'ai-languages' => ['view', 'update'],
        'ai-language-variants' => self::CATALOG,
        'ai-user-language-preferences' => ['view', 'update'],
        'ai-language-evaluations' => self::CATALOG,
        // Knowledge & files
        'ai-files' => ['view'],
        'ai-file-processing' => ['view'],
        'ai-knowledge-sources' => ['view', 'create', 'update'],
        'ai-knowledge-chunks' => ['view'],
        'ai-document-generations' => ['view'],
        // Projects
        'ai-project-instructions' => self::CATALOG,
        'ai-project-context' => ['view'],
        // Quality
        'ai-benchmark-cases' => self::CATALOG,
        'ai-benchmark-runs' => ['view', 'create'],
    ];

    /**
     * Lists another screen's form reads for its dropdowns: holders of these screens' view may READ the group too.
     *
     * @var array<string, list<string>>
     */
    public const READERS = [
        // The site-download screen shows the download offer's per-country prices (changing them stays ai-site-offers.update).
        'ai-site-offers' => ['ai-site-download'],
        'ai-intents' => ['ai-routing-rules'],
        'ai-routing-policies' => ['ai-gateways', 'ai-routing-rules'],
        'ai-safety-policies' => ['ai-safety-rules'],
        'ai-languages' => ['ai-language-variants', 'ai-user-language-preferences', 'ai-language-evaluations'],
        'ai-language-variants' => ['ai-language-evaluations', 'ai-user-language-preferences'],
    ];

    /** The route middleware for a group: `admin.screen:<group>[,<reader>...]`. */
    public static function middleware(string $group): string
    {
        return 'admin.screen:'.implode(',', [$group, ...(self::READERS[$group] ?? [])]);
    }
}
