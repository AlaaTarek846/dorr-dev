<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Screen permissions for the rest of the AI service screens (frozen copy of Modules\AI\Support\AiAdminScreens::GROUPS
 * at 2026-11-10, minus ai-settings / ai-plans which 2026_11_10_100100 already added).
 * Same rows AdminPermissionSeeder declares; added here too because re-running that seeder wipes every role.
 * The super-admin role gets them, so nobody loses access; other roles get them from the roles screen.
 */
return new class extends Migration
{
    private const GUARD = 'admin_api';

    private const GROUPS = [
        'ai-site-offers' => ['view', 'create', 'update', 'delete', 'change-status', 'multiple-delete'],
        'ai-site-requests' => ['view', 'update', 'change-status'],
        'ai-ar' => ['view', 'update'],
        'ai-world' => ['view', 'update'],
        'ai-site-download' => ['view', 'update'],
        'ai-sites' => ['view', 'update', 'delete'],
        'ai-site-hosting-plans' => ['view', 'create', 'update', 'delete', 'change-status', 'multiple-delete'],
        'ai-site-hostings' => ['view', 'update', 'delete'],
        'ai-subscriptions' => ['view', 'create', 'update', 'delete'],
        'ai-usage-sessions' => ['view'],
        'ai-trial-control' => ['view', 'update'],
        'ai-intents' => ['view', 'create', 'update', 'delete', 'change-status', 'multiple-delete'],
        'ai-learned-intents' => ['view', 'create', 'update', 'delete', 'change-status', 'multiple-delete'],
        'ai-routing-policies' => ['view', 'create', 'update', 'delete', 'change-status', 'multiple-delete'],
        'ai-gateways' => ['view', 'create', 'update', 'delete', 'change-status', 'multiple-delete'],
        'ai-routing-rules' => ['view', 'create', 'update', 'delete', 'change-status', 'multiple-delete'],
        'ai-feature-flags' => ['view', 'create', 'update', 'delete', 'change-status', 'multiple-delete'],
        'ai-domain-policies' => ['view', 'create', 'update', 'delete', 'change-status', 'multiple-delete'],
        'ai-safety-policies' => ['view', 'create', 'update', 'delete', 'change-status', 'multiple-delete'],
        'ai-safety-rules' => ['view', 'create', 'update', 'delete', 'change-status', 'multiple-delete'],
        'ai-safety-events' => ['view'],
        'ai-safety-scans' => ['view'],
        'ai-security-policies' => ['view', 'create', 'update', 'delete', 'change-status', 'multiple-delete'],
        'ai-data-policies' => ['view', 'create', 'update', 'delete', 'change-status', 'multiple-delete'],
        'ai-provider-data-rules' => ['view', 'create', 'update', 'delete', 'change-status', 'multiple-delete'],
        'ai-security-events' => ['view'],
        'ai-requests' => ['view'],
        'ai-responses' => ['view'],
        'ai-usage' => ['view'],
        'ai-verifications' => ['view'],
        'ai-code-executions' => ['view'],
        'ai-audit-events' => ['view'],
        'ai-conversation-contexts' => ['view'],
        'ai-conversation-attachments' => ['view'],
        'ai-conversation-instructions' => ['view', 'create', 'update', 'delete', 'change-status', 'multiple-delete'],
        'ai-provider-logs' => ['view'],
        'ai-provider-health' => ['view'],
        'ai-failovers' => ['view'],
        'ai-reliability-metrics' => ['view'],
        'ai-languages' => ['view', 'update'],
        'ai-language-variants' => ['view', 'create', 'update', 'delete', 'change-status', 'multiple-delete'],
        'ai-user-language-preferences' => ['view', 'update'],
        'ai-language-evaluations' => ['view', 'create', 'update', 'delete', 'change-status', 'multiple-delete'],
        'ai-files' => ['view'],
        'ai-file-processing' => ['view'],
        'ai-knowledge-sources' => ['view', 'create', 'update'],
        'ai-knowledge-chunks' => ['view'],
        'ai-document-generations' => ['view'],
        'ai-project-instructions' => ['view', 'create', 'update', 'delete', 'change-status', 'multiple-delete'],
        'ai-project-context' => ['view'],
        'ai-benchmark-cases' => ['view', 'create', 'update', 'delete', 'change-status', 'multiple-delete'],
        'ai-benchmark-runs' => ['view', 'create'],
    ];

    public function up(): void
    {
        $categoryId = DB::table('service_categories')->where('module_name', 'ai_assistant')->value('id');
        $superAdminId = DB::table('roles')->where('name', 'super-admin')->where('guard_name', self::GUARD)->value('id');

        foreach (self::GROUPS as $group => $actions) {
            foreach ($actions as $action) {
                $name = "{$group}.{$action}";

                $id = DB::table('permissions')->where('name', $name)->where('guard_name', self::GUARD)->value('id');

                if ($id === null) {
                    $id = DB::table('permissions')->insertGetId([
                        'name' => $name,
                        'guard_name' => self::GUARD,
                        'group_name' => $group,
                        'service_category_id' => $categoryId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                if ($superAdminId !== null) {
                    DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $id, 'role_id' => $superAdminId]);
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $names = collect(self::GROUPS)->flatMap(fn ($actions, $group) => array_map(fn ($a) => "{$group}.{$a}", $actions));

        $ids = DB::table('permissions')->whereIn('name', $names)->where('guard_name', self::GUARD)->pluck('id');
        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
