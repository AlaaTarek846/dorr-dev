<?php

use Illuminate\Support\Facades\Route;
use Modules\AI\Enums\AiProviderKey;
use Modules\AI\Http\Controllers\AiAuditEventController;
use Modules\AI\Http\Controllers\AiBenchmarkCaseController;
use Modules\AI\Http\Controllers\AiBenchmarkRunController;
use Modules\AI\Http\Controllers\AiCodeExecutionController;
use Modules\AI\Http\Controllers\AiConversationAttachmentController;
use Modules\AI\Http\Controllers\AiConversationContextController;
use Modules\AI\Http\Controllers\AiConversationInstructionController;
use Modules\AI\Http\Controllers\AiDataPolicyController;
use Modules\AI\Http\Controllers\AiDocumentGenerationController;
use Modules\AI\Http\Controllers\AiDomainPolicyController;
use Modules\AI\Http\Controllers\AiFailoverController;
use Modules\AI\Http\Controllers\AiFeatureFlagController;
use Modules\AI\Http\Controllers\AiFileController;
use Modules\AI\Http\Controllers\AiFileProcessingController;
use Modules\AI\Http\Controllers\AiGatewayController;
use Modules\AI\Http\Controllers\AiIntentController;
use Modules\AI\Http\Controllers\AiKnowledgeChunkController;
use Modules\AI\Http\Controllers\AiKnowledgeSourceController;
use Modules\AI\Http\Controllers\AiLanguageController;
use Modules\AI\Http\Controllers\AiLanguageEvaluationController;
use Modules\AI\Http\Controllers\AiLanguageVariantController;
use Modules\AI\Http\Controllers\AiLearnedIntentController;
use Modules\AI\Http\Controllers\AiPlanController;
use Modules\AI\Http\Controllers\AiPlanPriceController;
use Modules\AI\Http\Controllers\AiProjectContextController;
use Modules\AI\Http\Controllers\AiProjectInstructionController;
use Modules\AI\Http\Controllers\AiProviderController;
use Modules\AI\Http\Controllers\AiProviderDataRuleController;
use Modules\AI\Http\Controllers\AiProviderHealthController;
use Modules\AI\Http\Controllers\AiProviderLogController;
use Modules\AI\Http\Controllers\AiProviderModelController;
use Modules\AI\Http\Controllers\AiReliabilityMetricController;
use Modules\AI\Http\Controllers\AiRequestController;
use Modules\AI\Http\Controllers\AiResponseController;
use Modules\AI\Http\Controllers\AiRoutingPolicyController;
use Modules\AI\Http\Controllers\AiRoutingRuleController;
use Modules\AI\Http\Controllers\AiSafetyEventController;
use Modules\AI\Http\Controllers\AiSafetyPolicyController;
use Modules\AI\Http\Controllers\AiSafetyRuleController;
use Modules\AI\Http\Controllers\AiSafetyScanController;
use Modules\AI\Http\Controllers\AiSecurityEventController;
use Modules\AI\Http\Controllers\AiSecurityPolicyController;
use Modules\AI\Http\Controllers\AiSiteAdminHostingController;
use Modules\AI\Http\Controllers\AiSiteAdminProjectController;
use Modules\AI\Http\Controllers\AiSiteHostingPlanController;
use Modules\AI\Http\Controllers\AiSiteOfferController;
use Modules\AI\Http\Controllers\AiSubscriptionAdminController;
use Modules\AI\Http\Controllers\AiSubscriptionController;
use Modules\AI\Http\Controllers\AiTrialControlController;
use Modules\AI\Http\Controllers\AiUsageController;
use Modules\AI\Http\Controllers\AiUsageSessionController;
use Modules\AI\Http\Controllers\AiUserLanguagePreferenceController;
use Modules\AI\Http\Controllers\AiVerificationController;

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general'])->prefix('admin/v1/ai-providers')->group(function () {
    Route::get('/', [AiProviderController::class, 'index']);

    Route::post('{provider}', [AiProviderController::class, 'update'])
        ->whereIn('provider', AiProviderKey::values());

    Route::post('{provider}/test', [AiProviderController::class, 'testConnection'])
        ->whereIn('provider', AiProviderKey::values());

    Route::post('{provider}/set-default', [AiProviderController::class, 'setDefault'])
        ->whereIn('provider', AiProviderKey::values());

    Route::get('{provider}/models', [AiProviderModelController::class, 'index'])
        ->whereIn('provider', AiProviderKey::values());
    Route::post('{provider}/models', [AiProviderModelController::class, 'store'])
        ->whereIn('provider', AiProviderKey::values());
    Route::post('{provider}/models/reclassify', [AiProviderModelController::class, 'reclassify'])
        ->whereIn('provider', AiProviderKey::values());
    Route::post('{provider}/models/sync', [AiProviderModelController::class, 'sync'])
        ->whereIn('provider', AiProviderKey::values());
    Route::post('{provider}/models/normalize', [AiProviderModelController::class, 'normalize'])
        ->whereIn('provider', AiProviderKey::values());
    Route::put('{provider}/models/{model}', [AiProviderModelController::class, 'update'])
        ->whereIn('provider', AiProviderKey::values())->whereNumber('model');
    Route::delete('{provider}/models/{model}', [AiProviderModelController::class, 'destroy'])
        ->whereIn('provider', AiProviderKey::values())->whereNumber('model');
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general'])->prefix('admin/v1/ai-plans')->group(function () {
    Route::get('/', [AiPlanController::class, 'index']);
    Route::post('/', [AiPlanController::class, 'store']);
    Route::get('{plan}', [AiPlanController::class, 'show']);
    Route::put('{plan}', [AiPlanController::class, 'update']);
    Route::delete('{plan}', [AiPlanController::class, 'destroy']);
    Route::get('{plan}/prices', [AiPlanPriceController::class, 'index']);
    Route::post('{plan}/prices', [AiPlanPriceController::class, 'store']);
    Route::delete('{plan}/prices/{price}', [AiPlanPriceController::class, 'destroy']);
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general'])->prefix('admin/v1/ai-subscriptions')->group(function () {
    Route::get('overview', [AiSubscriptionAdminController::class, 'overview']);
    Route::get('/', [AiSubscriptionController::class, 'index']);
    Route::post('/', [AiSubscriptionController::class, 'store']);
    Route::get('{subscription}', [AiSubscriptionController::class, 'show']);
    Route::put('{subscription}', [AiSubscriptionController::class, 'update']);
    Route::delete('{subscription}', [AiSubscriptionController::class, 'destroy']);
    Route::post('{subscription}/extend', [AiSubscriptionAdminController::class, 'extend']);
    Route::post('{subscription}/suspend', [AiSubscriptionAdminController::class, 'suspend']);
    Route::post('{subscription}/reactivate', [AiSubscriptionAdminController::class, 'reactivate']);
    Route::post('{subscription}/cancel', [AiSubscriptionAdminController::class, 'cancel']);
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general'])->prefix('admin/v1/ai-usage-sessions')->group(function () {
    Route::get('/', [AiUsageSessionController::class, 'index']);
    Route::get('{usageSession}', [AiUsageSessionController::class, 'show']);
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general'])->prefix('admin/v1/ai-trial-control')->group(function () {
    Route::get('/', [AiTrialControlController::class, 'index']);
    Route::get('{trialControl}', [AiTrialControlController::class, 'show']);
    Route::put('{trialControl}', [AiTrialControlController::class, 'update']);
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general'])->prefix('admin/v1/ai-learned-intents')->group(function () {
    Route::get('/', [AiLearnedIntentController::class, 'index']);
    Route::get('stats', [AiLearnedIntentController::class, 'stats']);
    Route::post('test', [AiLearnedIntentController::class, 'test']);
    Route::post('/', [AiLearnedIntentController::class, 'store']);
    Route::put('{learnedIntent}', [AiLearnedIntentController::class, 'update'])->whereNumber('learnedIntent');
    Route::delete('{learnedIntent}', [AiLearnedIntentController::class, 'destroy'])->whereNumber('learnedIntent');
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general'])->prefix('admin/v1/ai-intents')->group(function () {
    Route::get('/', [AiIntentController::class, 'index']);
    Route::post('/', [AiIntentController::class, 'store']);
    Route::get('{intent}', [AiIntentController::class, 'show']);
    Route::put('{intent}', [AiIntentController::class, 'update']);
    Route::delete('{intent}', [AiIntentController::class, 'destroy']);
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general'])->prefix('admin/v1/ai-routing-policies')->group(function () {
    Route::get('/', [AiRoutingPolicyController::class, 'index']);
    Route::post('/', [AiRoutingPolicyController::class, 'store']);
    Route::get('{policy}', [AiRoutingPolicyController::class, 'show']);
    Route::put('{policy}', [AiRoutingPolicyController::class, 'update']);
    Route::delete('{policy}', [AiRoutingPolicyController::class, 'destroy']);
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general'])->prefix('admin/v1/ai-gateways')->group(function () {
    Route::get('/', [AiGatewayController::class, 'index']);
    Route::post('/', [AiGatewayController::class, 'store']);
    Route::get('{gateway}', [AiGatewayController::class, 'show']);
    Route::put('{gateway}', [AiGatewayController::class, 'update']);
    Route::delete('{gateway}', [AiGatewayController::class, 'destroy']);
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general'])->prefix('admin/v1/ai-routing-rules')->group(function () {
    Route::get('/', [AiRoutingRuleController::class, 'index']);
    Route::post('/', [AiRoutingRuleController::class, 'store']);
    Route::get('{rule}', [AiRoutingRuleController::class, 'show']);
    Route::put('{rule}', [AiRoutingRuleController::class, 'update']);
    Route::delete('{rule}', [AiRoutingRuleController::class, 'destroy']);
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general'])->prefix('admin/v1/ai-safety-policies')->group(function () {
    Route::get('/', [AiSafetyPolicyController::class, 'index']);
    Route::post('/', [AiSafetyPolicyController::class, 'store']);
    Route::get('{policy}', [AiSafetyPolicyController::class, 'show']);
    Route::put('{policy}', [AiSafetyPolicyController::class, 'update']);
    Route::delete('{policy}', [AiSafetyPolicyController::class, 'destroy']);
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general'])->prefix('admin/v1/ai-safety-rules')->group(function () {
    Route::get('/', [AiSafetyRuleController::class, 'index']);
    Route::post('/', [AiSafetyRuleController::class, 'store']);
    Route::get('{rule}', [AiSafetyRuleController::class, 'show']);
    Route::put('{rule}', [AiSafetyRuleController::class, 'update']);
    Route::delete('{rule}', [AiSafetyRuleController::class, 'destroy']);
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general'])->prefix('admin/v1/ai-safety-events')->group(function () {
    Route::get('/', [AiSafetyEventController::class, 'index']);
    Route::get('{safetyEvent}', [AiSafetyEventController::class, 'show']);
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general'])->prefix('admin/v1/ai-safety-scans')->group(function () {
    Route::get('/', [AiSafetyScanController::class, 'index']);
    Route::get('{safetyScan}', [AiSafetyScanController::class, 'show']);
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general'])->prefix('admin/v1/ai-security-policies')->group(function () {
    Route::get('/', [AiSecurityPolicyController::class, 'index']);
    Route::post('/', [AiSecurityPolicyController::class, 'store']);
    Route::get('{policy}', [AiSecurityPolicyController::class, 'show']);
    Route::put('{policy}', [AiSecurityPolicyController::class, 'update']);
    Route::delete('{policy}', [AiSecurityPolicyController::class, 'destroy']);
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general'])->prefix('admin/v1/ai-data-policies')->group(function () {
    Route::get('/', [AiDataPolicyController::class, 'index']);
    Route::post('/', [AiDataPolicyController::class, 'store']);
    Route::get('{policy}', [AiDataPolicyController::class, 'show']);
    Route::put('{policy}', [AiDataPolicyController::class, 'update']);
    Route::delete('{policy}', [AiDataPolicyController::class, 'destroy']);
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general'])->prefix('admin/v1/ai-provider-data-rules')->group(function () {
    Route::get('/', [AiProviderDataRuleController::class, 'index']);
    Route::post('/', [AiProviderDataRuleController::class, 'store']);
    Route::get('{providerDataRule}', [AiProviderDataRuleController::class, 'show']);
    Route::put('{providerDataRule}', [AiProviderDataRuleController::class, 'update']);
    Route::delete('{providerDataRule}', [AiProviderDataRuleController::class, 'destroy']);
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general'])->prefix('admin/v1/ai-security-events')->group(function () {
    Route::get('/', [AiSecurityEventController::class, 'index']);
    Route::get('{securityEvent}', [AiSecurityEventController::class, 'show']);
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general'])->prefix('admin/v1/ai-requests')->group(function () {
    Route::get('/', [AiRequestController::class, 'index']);
    Route::get('{aiRequest}', [AiRequestController::class, 'show']);
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general'])->prefix('admin/v1/ai-responses')->group(function () {
    Route::get('/', [AiResponseController::class, 'index']);
    Route::get('{response}', [AiResponseController::class, 'show']);
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general'])->prefix('admin/v1/ai-usage')->group(function () {
    Route::get('/', [AiUsageController::class, 'index']);
    Route::get('{usage}', [AiUsageController::class, 'show']);
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general'])->prefix('admin/v1/ai-conversation-contexts')->group(function () {
    Route::get('/', [AiConversationContextController::class, 'index']);
    Route::get('{context}', [AiConversationContextController::class, 'show']);
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general'])->prefix('admin/v1/ai-conversation-attachments')->group(function () {
    Route::get('/', [AiConversationAttachmentController::class, 'index']);
    Route::get('{attachment}', [AiConversationAttachmentController::class, 'show']);
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general'])->prefix('admin/v1/ai-conversation-instructions')->group(function () {
    Route::get('/', [AiConversationInstructionController::class, 'index']);
    Route::post('/', [AiConversationInstructionController::class, 'store']);
    Route::get('{instruction}', [AiConversationInstructionController::class, 'show']);
    Route::put('{instruction}', [AiConversationInstructionController::class, 'update']);
    Route::delete('{instruction}', [AiConversationInstructionController::class, 'destroy']);
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general'])->prefix('admin/v1/ai-provider-logs')->group(function () {
    Route::get('/', [AiProviderLogController::class, 'index']);
    Route::get('{log}', [AiProviderLogController::class, 'show']);
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general'])->prefix('admin/v1/ai-audit-events')->group(function () {
    Route::get('/', [AiAuditEventController::class, 'index']);
    Route::get('{event}', [AiAuditEventController::class, 'show']);
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general'])->prefix('admin/v1/ai-provider-health')->group(function () {
    Route::get('/', [AiProviderHealthController::class, 'index']);
    Route::get('{health}', [AiProviderHealthController::class, 'show']);
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general'])->prefix('admin/v1/ai-failovers')->group(function () {
    Route::get('/', [AiFailoverController::class, 'index']);
    Route::get('{failover}', [AiFailoverController::class, 'show']);
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general'])->prefix('admin/v1/ai-verifications')->group(function () {
    Route::get('/', [AiVerificationController::class, 'index']);
    Route::get('{verification}', [AiVerificationController::class, 'show']);
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general'])->prefix('admin/v1/ai-feature-flags')->group(function () {
    Route::get('/', [AiFeatureFlagController::class, 'index']);
    Route::post('/', [AiFeatureFlagController::class, 'store']);
    Route::get('{flag}', [AiFeatureFlagController::class, 'show']);
    Route::put('{flag}', [AiFeatureFlagController::class, 'update']);
    Route::delete('{flag}', [AiFeatureFlagController::class, 'destroy']);
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general'])->prefix('admin/v1/ai-domain-policies')->group(function () {
    Route::get('/', [AiDomainPolicyController::class, 'index']);
    Route::post('/', [AiDomainPolicyController::class, 'store']);
    Route::get('{policy}', [AiDomainPolicyController::class, 'show']);
    Route::put('{policy}', [AiDomainPolicyController::class, 'update']);
    Route::delete('{policy}', [AiDomainPolicyController::class, 'destroy']);
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general'])->prefix('admin/v1/ai-code-executions')->group(function () {
    Route::get('/', [AiCodeExecutionController::class, 'index']);
    Route::get('{execution}', [AiCodeExecutionController::class, 'show']);
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general'])->prefix('admin/v1/ai-reliability-metrics')->group(function () {
    Route::get('/', [AiReliabilityMetricController::class, 'index']);
    Route::get('{metric}', [AiReliabilityMetricController::class, 'show']);
});

// Root-cause fix (languages consolidation): lists the platform's general
// languages and toggles whether each is AI-enabled - creating/editing/
// deleting a language is the general Languages admin screen's job (see
// AiLanguageController's docblock). The old "ai-locales" endpoint group
// (an unused, never-wired-up duplicate of ai-language-variants) is gone.
Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general'])->prefix('admin/v1/ai-languages')->group(function () {
    Route::get('/', [AiLanguageController::class, 'index']);
    Route::get('{language}', [AiLanguageController::class, 'show']);
    Route::put('{language}', [AiLanguageController::class, 'update']);
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general'])->prefix('admin/v1/ai-language-variants')->group(function () {
    Route::get('/', [AiLanguageVariantController::class, 'index']);
    Route::post('/', [AiLanguageVariantController::class, 'store']);
    Route::get('{variant}', [AiLanguageVariantController::class, 'show']);
    Route::put('{variant}', [AiLanguageVariantController::class, 'update']);
    Route::delete('{variant}', [AiLanguageVariantController::class, 'destroy']);
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general'])->prefix('admin/v1/ai-user-language-preferences')->group(function () {
    Route::get('/', [AiUserLanguagePreferenceController::class, 'index']);
    Route::get('{preference}', [AiUserLanguagePreferenceController::class, 'show']);
    Route::put('{preference}', [AiUserLanguagePreferenceController::class, 'update']);
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general'])->prefix('admin/v1/ai-language-evaluations')->group(function () {
    Route::get('/', [AiLanguageEvaluationController::class, 'index']);
    Route::post('/', [AiLanguageEvaluationController::class, 'store']);
    Route::get('{evaluation}', [AiLanguageEvaluationController::class, 'show']);
    Route::put('{evaluation}', [AiLanguageEvaluationController::class, 'update']);
    Route::delete('{evaluation}', [AiLanguageEvaluationController::class, 'destroy']);
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general'])->prefix('admin/v1/ai-files')->group(function () {
    Route::get('/', [AiFileController::class, 'index']);
    Route::get('{file}', [AiFileController::class, 'show']);
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general'])->prefix('admin/v1/ai-file-processing')->group(function () {
    Route::get('/', [AiFileProcessingController::class, 'index']);
    Route::get('{processing}', [AiFileProcessingController::class, 'show']);
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general'])->prefix('admin/v1/ai-knowledge-sources')->group(function () {
    Route::get('/', [AiKnowledgeSourceController::class, 'index']);
    Route::post('/', [AiKnowledgeSourceController::class, 'store']);
    Route::get('{source}', [AiKnowledgeSourceController::class, 'show']);
    Route::post('{source}/approve', [AiKnowledgeSourceController::class, 'approve']);
    Route::post('{source}/reject', [AiKnowledgeSourceController::class, 'reject']);
    Route::post('{source}/deprecate', [AiKnowledgeSourceController::class, 'deprecate']);
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general'])->prefix('admin/v1/ai-knowledge-chunks')->group(function () {
    Route::get('/', [AiKnowledgeChunkController::class, 'index']);
    Route::get('{chunk}', [AiKnowledgeChunkController::class, 'show']);
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general'])->prefix('admin/v1/ai-document-generations')->group(function () {
    Route::get('/', [AiDocumentGenerationController::class, 'index']);
    Route::get('{generation}', [AiDocumentGenerationController::class, 'show']);
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general'])->prefix('admin/v1/ai-project-instructions')->group(function () {
    Route::get('/', [AiProjectInstructionController::class, 'index']);
    Route::post('/', [AiProjectInstructionController::class, 'store']);
    Route::get('{instruction}', [AiProjectInstructionController::class, 'show']);
    Route::put('{instruction}', [AiProjectInstructionController::class, 'update']);
    Route::delete('{instruction}', [AiProjectInstructionController::class, 'destroy']);
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general'])->prefix('admin/v1/ai-project-context')->group(function () {
    Route::get('/', [AiProjectContextController::class, 'index']);
    Route::get('{context}', [AiProjectContextController::class, 'show']);
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general'])->prefix('admin/v1/ai-benchmark-cases')->group(function () {
    Route::get('/', [AiBenchmarkCaseController::class, 'index']);
    Route::post('/', [AiBenchmarkCaseController::class, 'store']);
    Route::get('{case}', [AiBenchmarkCaseController::class, 'show']);
    Route::put('{case}', [AiBenchmarkCaseController::class, 'update']);
    Route::delete('{case}', [AiBenchmarkCaseController::class, 'destroy']);
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general'])->prefix('admin/v1/ai-benchmark-runs')->group(function () {
    Route::get('/', [AiBenchmarkRunController::class, 'index']);
    Route::post('/', [AiBenchmarkRunController::class, 'store']);
    // Must be registered before the {run} route below, or "config" would
    // be swallowed as a $run id and 404 on model binding.
    Route::get('config', [AiBenchmarkRunController::class, 'config']);
    Route::get('{run}', [AiBenchmarkRunController::class, 'show']);
});

// Website builder: standalone offers (price per country) and moderation of customer sites.
Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general'])->prefix('admin/v1/ai-site-offers')->group(function () {
    Route::get('/', [AiSiteOfferController::class, 'index']);
    Route::post('/', [AiSiteOfferController::class, 'store']);
    Route::get('{offer}', [AiSiteOfferController::class, 'show']);
    Route::put('{offer}', [AiSiteOfferController::class, 'update']);
    Route::delete('{offer}', [AiSiteOfferController::class, 'destroy']);
    Route::get('{offer}/prices', [AiSiteOfferController::class, 'prices']);
    Route::put('{offer}/prices', [AiSiteOfferController::class, 'syncPrices']);
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general'])->prefix('admin/v1/ai-sites')->group(function () {
    Route::get('/', [AiSiteAdminProjectController::class, 'index']);
    Route::get('{project}', [AiSiteAdminProjectController::class, 'show'])->whereNumber('project');
    Route::post('{project}/disable', [AiSiteAdminProjectController::class, 'disable'])->whereNumber('project');
    Route::post('{project}/enable', [AiSiteAdminProjectController::class, 'enable'])->whereNumber('project');
    Route::delete('{project}', [AiSiteAdminProjectController::class, 'destroy'])->whereNumber('project');
});

// Site hosting: plans with a price per country, and every hosted site.
Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general'])->prefix('admin/v1/ai-site-hosting-plans')->group(function () {
    Route::get('/', [AiSiteHostingPlanController::class, 'index']);
    Route::post('/', [AiSiteHostingPlanController::class, 'store']);
    Route::get('{plan}', [AiSiteHostingPlanController::class, 'show']);
    Route::put('{plan}', [AiSiteHostingPlanController::class, 'update']);
    Route::delete('{plan}', [AiSiteHostingPlanController::class, 'destroy']);
    Route::get('{plan}/prices', [AiSiteHostingPlanController::class, 'prices']);
    Route::put('{plan}/prices', [AiSiteHostingPlanController::class, 'syncPrices']);
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general'])->prefix('admin/v1/ai-site-hostings')->group(function () {
    Route::get('/', [AiSiteAdminHostingController::class, 'index']);
    Route::get('{hosting}', [AiSiteAdminHostingController::class, 'show'])->whereNumber('hosting');
    Route::post('{hosting}/suspend', [AiSiteAdminHostingController::class, 'suspend'])->whereNumber('hosting');
    Route::post('{hosting}/resume', [AiSiteAdminHostingController::class, 'resume'])->whereNumber('hosting');
    Route::delete('{hosting}', [AiSiteAdminHostingController::class, 'destroy'])->whereNumber('hosting');
});
