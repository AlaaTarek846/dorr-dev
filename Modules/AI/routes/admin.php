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
use Modules\AI\Http\Controllers\AiSiteDownloadSettingController;
use Modules\AI\Http\Controllers\AiSiteHostingPlanController;
use Modules\AI\Http\Controllers\AiSiteOfferController;
use Modules\AI\Http\Controllers\Ar\AiArAdminController;
use Modules\AI\Http\Controllers\World\AiWorldAdminController;
use Modules\AI\Http\Controllers\AiSiteServiceRequestAdminController;
use Modules\AI\Http\Controllers\AiSubscriptionAdminController;
use Modules\AI\Http\Controllers\AiSubscriptionController;
use Modules\AI\Http\Controllers\AiTrialControlController;
use Modules\AI\Http\Controllers\AiUsageController;
use Modules\AI\Http\Controllers\AiUsageSessionController;
use Modules\AI\Http\Controllers\AiUserLanguagePreferenceController;
use Modules\AI\Http\Controllers\AiVerificationController;
use Modules\AI\Support\AiAdminScreens;

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
    // Catalog CRUD like countries (routes/admin.php's catalog loop): bulk delete, trash, status.
    Route::get('/', [AiPlanController::class, 'index']);
    Route::post('/', [AiPlanController::class, 'store']);
    Route::get('dropdown', [AiPlanController::class, 'dropdown']);
    Route::post('delete-multiple', [AiPlanController::class, 'deleteMultiple']);
    Route::get('{plan}', [AiPlanController::class, 'show'])->whereNumber('plan');
    Route::put('{plan}', [AiPlanController::class, 'update'])->whereNumber('plan');
    Route::delete('{plan}', [AiPlanController::class, 'destroy'])->whereNumber('plan');
    Route::post('{plan}/restore', [AiPlanController::class, 'restore'])->whereNumber('plan');
    Route::delete('{plan}/force', [AiPlanController::class, 'forceDestroy'])->whereNumber('plan');
    Route::patch('{plan}/status', [AiPlanController::class, 'changeStatus'])->whereNumber('plan');
    Route::get('{plan}/prices', [AiPlanPriceController::class, 'index']);
    Route::post('{plan}/prices', [AiPlanPriceController::class, 'store']);
    Route::delete('{plan}/prices/{price}', [AiPlanPriceController::class, 'destroy']);
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general', AiAdminScreens::middleware('ai-subscriptions')])->prefix('admin/v1/ai-subscriptions')->group(function () {
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

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general', AiAdminScreens::middleware('ai-usage-sessions')])->prefix('admin/v1/ai-usage-sessions')->group(function () {
    Route::get('/', [AiUsageSessionController::class, 'index']);
    Route::get('{usageSession}', [AiUsageSessionController::class, 'show']);
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general', AiAdminScreens::middleware('ai-trial-control')])->prefix('admin/v1/ai-trial-control')->group(function () {
    Route::get('/', [AiTrialControlController::class, 'index']);
    Route::get('{trialControl}', [AiTrialControlController::class, 'show']);
    Route::put('{trialControl}', [AiTrialControlController::class, 'update']);
});

// Catalog CRUD like countries (no translations) + stats and the "try a phrase" tester; guarded by admin.screen.
Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general', AiAdminScreens::middleware('ai-learned-intents')])->prefix('admin/v1/ai-learned-intents')->group(function () {
    Route::get('/', [AiLearnedIntentController::class, 'index']);
    Route::get('stats', [AiLearnedIntentController::class, 'stats']);
    Route::post('test', [AiLearnedIntentController::class, 'test']);
    Route::post('/', [AiLearnedIntentController::class, 'store']);
    Route::post('delete-multiple', [AiLearnedIntentController::class, 'deleteMultiple']);
    Route::get('{learnedIntent}', [AiLearnedIntentController::class, 'show'])->whereNumber('learnedIntent');
    Route::put('{learnedIntent}', [AiLearnedIntentController::class, 'update'])->whereNumber('learnedIntent');
    Route::delete('{learnedIntent}', [AiLearnedIntentController::class, 'destroy'])->whereNumber('learnedIntent');
    Route::post('{learnedIntent}/restore', [AiLearnedIntentController::class, 'restore'])->whereNumber('learnedIntent');
    Route::delete('{learnedIntent}/force', [AiLearnedIntentController::class, 'forceDestroy'])->whereNumber('learnedIntent');
    Route::patch('{learnedIntent}/status', [AiLearnedIntentController::class, 'changeStatus'])->whereNumber('learnedIntent');
});

// Catalog CRUD like countries (translations, status, trash); guarded by admin.screen (routing rules may read it).
Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general', AiAdminScreens::middleware('ai-intents')])->prefix('admin/v1/ai-intents')->group(function () {
    Route::get('/', [AiIntentController::class, 'index']);
    Route::post('/', [AiIntentController::class, 'store']);
    Route::get('dropdown', [AiIntentController::class, 'dropdown']);
    Route::post('delete-multiple', [AiIntentController::class, 'deleteMultiple']);
    Route::get('{intent}', [AiIntentController::class, 'show'])->whereNumber('intent');
    Route::put('{intent}', [AiIntentController::class, 'update'])->whereNumber('intent');
    Route::delete('{intent}', [AiIntentController::class, 'destroy'])->whereNumber('intent');
    Route::post('{intent}/restore', [AiIntentController::class, 'restore'])->whereNumber('intent');
    Route::delete('{intent}/force', [AiIntentController::class, 'forceDestroy'])->whereNumber('intent');
    Route::patch('{intent}/status', [AiIntentController::class, 'changeStatus'])->whereNumber('intent');
});

// Catalog CRUD like countries (translations, status, trash); guarded by admin.screen (gateways and routing rules may read it).
Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general', AiAdminScreens::middleware('ai-routing-policies')])->prefix('admin/v1/ai-routing-policies')->group(function () {
    Route::get('/', [AiRoutingPolicyController::class, 'index']);
    Route::post('/', [AiRoutingPolicyController::class, 'store']);
    Route::get('dropdown', [AiRoutingPolicyController::class, 'dropdown']);
    Route::post('delete-multiple', [AiRoutingPolicyController::class, 'deleteMultiple']);
    Route::get('{policy}', [AiRoutingPolicyController::class, 'show'])->whereNumber('policy');
    Route::put('{policy}', [AiRoutingPolicyController::class, 'update'])->whereNumber('policy');
    Route::delete('{policy}', [AiRoutingPolicyController::class, 'destroy'])->whereNumber('policy');
    Route::post('{policy}/restore', [AiRoutingPolicyController::class, 'restore'])->whereNumber('policy');
    Route::delete('{policy}/force', [AiRoutingPolicyController::class, 'forceDestroy'])->whereNumber('policy');
    Route::patch('{policy}/status', [AiRoutingPolicyController::class, 'changeStatus'])->whereNumber('policy');
});

// Catalog CRUD like countries (translations, status, trash); guarded by admin.screen.
Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general', AiAdminScreens::middleware('ai-gateways')])->prefix('admin/v1/ai-gateways')->group(function () {
    Route::get('/', [AiGatewayController::class, 'index']);
    Route::post('/', [AiGatewayController::class, 'store']);
    Route::post('delete-multiple', [AiGatewayController::class, 'deleteMultiple']);
    Route::get('{gateway}', [AiGatewayController::class, 'show'])->whereNumber('gateway');
    Route::put('{gateway}', [AiGatewayController::class, 'update'])->whereNumber('gateway');
    Route::delete('{gateway}', [AiGatewayController::class, 'destroy'])->whereNumber('gateway');
    Route::post('{gateway}/restore', [AiGatewayController::class, 'restore'])->whereNumber('gateway');
    Route::delete('{gateway}/force', [AiGatewayController::class, 'forceDestroy'])->whereNumber('gateway');
    Route::patch('{gateway}/status', [AiGatewayController::class, 'changeStatus'])->whereNumber('gateway');
});

// Catalog CRUD like countries (no translations: a rule has no text to translate); guarded by admin.screen.
Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general', AiAdminScreens::middleware('ai-routing-rules')])->prefix('admin/v1/ai-routing-rules')->group(function () {
    Route::get('/', [AiRoutingRuleController::class, 'index']);
    Route::post('/', [AiRoutingRuleController::class, 'store']);
    Route::post('delete-multiple', [AiRoutingRuleController::class, 'deleteMultiple']);
    Route::get('{rule}', [AiRoutingRuleController::class, 'show'])->whereNumber('rule');
    Route::put('{rule}', [AiRoutingRuleController::class, 'update'])->whereNumber('rule');
    Route::delete('{rule}', [AiRoutingRuleController::class, 'destroy'])->whereNumber('rule');
    Route::post('{rule}/restore', [AiRoutingRuleController::class, 'restore'])->whereNumber('rule');
    Route::delete('{rule}/force', [AiRoutingRuleController::class, 'forceDestroy'])->whereNumber('rule');
    Route::patch('{rule}/status', [AiRoutingRuleController::class, 'changeStatus'])->whereNumber('rule');
});

// Catalog CRUD like countries (translations, status, trash); guarded by admin.screen (safety rules may read it).
Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general', AiAdminScreens::middleware('ai-safety-policies')])->prefix('admin/v1/ai-safety-policies')->group(function () {
    Route::get('/', [AiSafetyPolicyController::class, 'index']);
    Route::post('/', [AiSafetyPolicyController::class, 'store']);
    Route::get('dropdown', [AiSafetyPolicyController::class, 'dropdown']);
    Route::post('delete-multiple', [AiSafetyPolicyController::class, 'deleteMultiple']);
    Route::get('{policy}', [AiSafetyPolicyController::class, 'show'])->whereNumber('policy');
    Route::put('{policy}', [AiSafetyPolicyController::class, 'update'])->whereNumber('policy');
    Route::delete('{policy}', [AiSafetyPolicyController::class, 'destroy'])->whereNumber('policy');
    Route::post('{policy}/restore', [AiSafetyPolicyController::class, 'restore'])->whereNumber('policy');
    Route::delete('{policy}/force', [AiSafetyPolicyController::class, 'forceDestroy'])->whereNumber('policy');
    Route::patch('{policy}/status', [AiSafetyPolicyController::class, 'changeStatus'])->whereNumber('policy');
});

// Catalog CRUD like countries (translations, status, trash); guarded by admin.screen.
Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general', AiAdminScreens::middleware('ai-safety-rules')])->prefix('admin/v1/ai-safety-rules')->group(function () {
    Route::get('/', [AiSafetyRuleController::class, 'index']);
    Route::post('/', [AiSafetyRuleController::class, 'store']);
    Route::post('delete-multiple', [AiSafetyRuleController::class, 'deleteMultiple']);
    Route::get('{rule}', [AiSafetyRuleController::class, 'show'])->whereNumber('rule');
    Route::put('{rule}', [AiSafetyRuleController::class, 'update'])->whereNumber('rule');
    Route::delete('{rule}', [AiSafetyRuleController::class, 'destroy'])->whereNumber('rule');
    Route::post('{rule}/restore', [AiSafetyRuleController::class, 'restore'])->whereNumber('rule');
    Route::delete('{rule}/force', [AiSafetyRuleController::class, 'forceDestroy'])->whereNumber('rule');
    Route::patch('{rule}/status', [AiSafetyRuleController::class, 'changeStatus'])->whereNumber('rule');
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general', AiAdminScreens::middleware('ai-safety-events')])->prefix('admin/v1/ai-safety-events')->group(function () {
    Route::get('/', [AiSafetyEventController::class, 'index']);
    Route::get('{safetyEvent}', [AiSafetyEventController::class, 'show']);
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general', AiAdminScreens::middleware('ai-safety-scans')])->prefix('admin/v1/ai-safety-scans')->group(function () {
    Route::get('/', [AiSafetyScanController::class, 'index']);
    Route::get('{safetyScan}', [AiSafetyScanController::class, 'show']);
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general', AiAdminScreens::middleware('ai-security-policies')])->prefix('admin/v1/ai-security-policies')->group(function () {
    Route::get('/', [AiSecurityPolicyController::class, 'index']);
    Route::post('/', [AiSecurityPolicyController::class, 'store']);
    Route::post('delete-multiple', [AiSecurityPolicyController::class, 'deleteMultiple']);
    Route::get('{policy}', [AiSecurityPolicyController::class, 'show'])->whereNumber('policy');
    Route::put('{policy}', [AiSecurityPolicyController::class, 'update'])->whereNumber('policy');
    Route::delete('{policy}', [AiSecurityPolicyController::class, 'destroy'])->whereNumber('policy');
    Route::post('{policy}/restore', [AiSecurityPolicyController::class, 'restore'])->whereNumber('policy');
    Route::delete('{policy}/force', [AiSecurityPolicyController::class, 'forceDestroy'])->whereNumber('policy');
    Route::patch('{policy}/status', [AiSecurityPolicyController::class, 'changeStatus'])->whereNumber('policy');
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general', AiAdminScreens::middleware('ai-data-policies')])->prefix('admin/v1/ai-data-policies')->group(function () {
    Route::get('/', [AiDataPolicyController::class, 'index']);
    Route::post('/', [AiDataPolicyController::class, 'store']);
    Route::post('delete-multiple', [AiDataPolicyController::class, 'deleteMultiple']);
    Route::get('{policy}', [AiDataPolicyController::class, 'show'])->whereNumber('policy');
    Route::put('{policy}', [AiDataPolicyController::class, 'update'])->whereNumber('policy');
    Route::delete('{policy}', [AiDataPolicyController::class, 'destroy'])->whereNumber('policy');
    Route::post('{policy}/restore', [AiDataPolicyController::class, 'restore'])->whereNumber('policy');
    Route::delete('{policy}/force', [AiDataPolicyController::class, 'forceDestroy'])->whereNumber('policy');
    Route::patch('{policy}/status', [AiDataPolicyController::class, 'changeStatus'])->whereNumber('policy');
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general', AiAdminScreens::middleware('ai-provider-data-rules')])->prefix('admin/v1/ai-provider-data-rules')->group(function () {
    Route::get('/', [AiProviderDataRuleController::class, 'index']);
    Route::post('/', [AiProviderDataRuleController::class, 'store']);
    Route::post('delete-multiple', [AiProviderDataRuleController::class, 'deleteMultiple']);
    Route::get('{rule}', [AiProviderDataRuleController::class, 'show'])->whereNumber('rule');
    Route::put('{rule}', [AiProviderDataRuleController::class, 'update'])->whereNumber('rule');
    Route::delete('{rule}', [AiProviderDataRuleController::class, 'destroy'])->whereNumber('rule');
    Route::post('{rule}/restore', [AiProviderDataRuleController::class, 'restore'])->whereNumber('rule');
    Route::delete('{rule}/force', [AiProviderDataRuleController::class, 'forceDestroy'])->whereNumber('rule');
    Route::patch('{rule}/status', [AiProviderDataRuleController::class, 'changeStatus'])->whereNumber('rule');
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general', AiAdminScreens::middleware('ai-security-events')])->prefix('admin/v1/ai-security-events')->group(function () {
    Route::get('/', [AiSecurityEventController::class, 'index']);
    Route::get('{securityEvent}', [AiSecurityEventController::class, 'show']);
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general', AiAdminScreens::middleware('ai-requests')])->prefix('admin/v1/ai-requests')->group(function () {
    Route::get('/', [AiRequestController::class, 'index']);
    Route::get('{aiRequest}', [AiRequestController::class, 'show']);
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general', AiAdminScreens::middleware('ai-responses')])->prefix('admin/v1/ai-responses')->group(function () {
    Route::get('/', [AiResponseController::class, 'index']);
    Route::get('{response}', [AiResponseController::class, 'show']);
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general', AiAdminScreens::middleware('ai-usage')])->prefix('admin/v1/ai-usage')->group(function () {
    Route::get('/', [AiUsageController::class, 'index']);
    Route::get('{usage}', [AiUsageController::class, 'show']);
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general', AiAdminScreens::middleware('ai-conversation-contexts')])->prefix('admin/v1/ai-conversation-contexts')->group(function () {
    Route::get('/', [AiConversationContextController::class, 'index']);
    Route::get('{context}', [AiConversationContextController::class, 'show']);
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general', AiAdminScreens::middleware('ai-conversation-attachments')])->prefix('admin/v1/ai-conversation-attachments')->group(function () {
    Route::get('/', [AiConversationAttachmentController::class, 'index']);
    Route::get('{attachment}', [AiConversationAttachmentController::class, 'show']);
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general', AiAdminScreens::middleware('ai-conversation-instructions')])->prefix('admin/v1/ai-conversation-instructions')->group(function () {
    Route::get('/', [AiConversationInstructionController::class, 'index']);
    Route::post('/', [AiConversationInstructionController::class, 'store']);
    Route::post('delete-multiple', [AiConversationInstructionController::class, 'deleteMultiple']);
    Route::get('{instruction}', [AiConversationInstructionController::class, 'show'])->whereNumber('instruction');
    Route::put('{instruction}', [AiConversationInstructionController::class, 'update'])->whereNumber('instruction');
    Route::delete('{instruction}', [AiConversationInstructionController::class, 'destroy'])->whereNumber('instruction');
    Route::post('{instruction}/restore', [AiConversationInstructionController::class, 'restore'])->whereNumber('instruction');
    Route::delete('{instruction}/force', [AiConversationInstructionController::class, 'forceDestroy'])->whereNumber('instruction');
    Route::patch('{instruction}/status', [AiConversationInstructionController::class, 'changeStatus'])->whereNumber('instruction');
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general', AiAdminScreens::middleware('ai-provider-logs')])->prefix('admin/v1/ai-provider-logs')->group(function () {
    Route::get('/', [AiProviderLogController::class, 'index']);
    Route::get('{log}', [AiProviderLogController::class, 'show']);
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general', AiAdminScreens::middleware('ai-audit-events')])->prefix('admin/v1/ai-audit-events')->group(function () {
    Route::get('/', [AiAuditEventController::class, 'index']);
    Route::get('{event}', [AiAuditEventController::class, 'show']);
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general', AiAdminScreens::middleware('ai-provider-health')])->prefix('admin/v1/ai-provider-health')->group(function () {
    Route::get('/', [AiProviderHealthController::class, 'index']);
    Route::get('{health}', [AiProviderHealthController::class, 'show']);
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general', AiAdminScreens::middleware('ai-failovers')])->prefix('admin/v1/ai-failovers')->group(function () {
    Route::get('/', [AiFailoverController::class, 'index']);
    Route::get('{failover}', [AiFailoverController::class, 'show']);
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general', AiAdminScreens::middleware('ai-verifications')])->prefix('admin/v1/ai-verifications')->group(function () {
    Route::get('/', [AiVerificationController::class, 'index']);
    Route::get('{verification}', [AiVerificationController::class, 'show']);
});

// Catalog CRUD like countries (translations, status, trash); guarded by admin.screen.
Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general', AiAdminScreens::middleware('ai-feature-flags')])->prefix('admin/v1/ai-feature-flags')->group(function () {
    Route::get('/', [AiFeatureFlagController::class, 'index']);
    Route::post('/', [AiFeatureFlagController::class, 'store']);
    Route::post('delete-multiple', [AiFeatureFlagController::class, 'deleteMultiple']);
    Route::get('{flag}', [AiFeatureFlagController::class, 'show'])->whereNumber('flag');
    Route::put('{flag}', [AiFeatureFlagController::class, 'update'])->whereNumber('flag');
    Route::delete('{flag}', [AiFeatureFlagController::class, 'destroy'])->whereNumber('flag');
    Route::post('{flag}/restore', [AiFeatureFlagController::class, 'restore'])->whereNumber('flag');
    Route::delete('{flag}/force', [AiFeatureFlagController::class, 'forceDestroy'])->whereNumber('flag');
    Route::patch('{flag}/status', [AiFeatureFlagController::class, 'changeStatus'])->whereNumber('flag');
});

// Catalog CRUD like countries (translations, status, trash); guarded by admin.screen.
Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general', AiAdminScreens::middleware('ai-domain-policies')])->prefix('admin/v1/ai-domain-policies')->group(function () {
    Route::get('/', [AiDomainPolicyController::class, 'index']);
    Route::post('/', [AiDomainPolicyController::class, 'store']);
    Route::post('delete-multiple', [AiDomainPolicyController::class, 'deleteMultiple']);
    Route::get('{policy}', [AiDomainPolicyController::class, 'show'])->whereNumber('policy');
    Route::put('{policy}', [AiDomainPolicyController::class, 'update'])->whereNumber('policy');
    Route::delete('{policy}', [AiDomainPolicyController::class, 'destroy'])->whereNumber('policy');
    Route::post('{policy}/restore', [AiDomainPolicyController::class, 'restore'])->whereNumber('policy');
    Route::delete('{policy}/force', [AiDomainPolicyController::class, 'forceDestroy'])->whereNumber('policy');
    Route::patch('{policy}/status', [AiDomainPolicyController::class, 'changeStatus'])->whereNumber('policy');
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general', AiAdminScreens::middleware('ai-code-executions')])->prefix('admin/v1/ai-code-executions')->group(function () {
    Route::get('/', [AiCodeExecutionController::class, 'index']);
    Route::get('{execution}', [AiCodeExecutionController::class, 'show']);
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general', AiAdminScreens::middleware('ai-reliability-metrics')])->prefix('admin/v1/ai-reliability-metrics')->group(function () {
    Route::get('/', [AiReliabilityMetricController::class, 'index']);
    Route::get('{metric}', [AiReliabilityMetricController::class, 'show']);
});

// Root-cause fix (languages consolidation): lists the platform's general
// languages and toggles whether each is AI-enabled - creating/editing/
// deleting a language is the general Languages admin screen's job (see
// AiLanguageController's docblock). The old "ai-locales" endpoint group
// (an unused, never-wired-up duplicate of ai-language-variants) is gone.
Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general', AiAdminScreens::middleware('ai-languages')])->prefix('admin/v1/ai-languages')->group(function () {
    Route::get('/', [AiLanguageController::class, 'index']);
    Route::get('{language}', [AiLanguageController::class, 'show']);
    Route::put('{language}', [AiLanguageController::class, 'update']);
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general', AiAdminScreens::middleware('ai-language-variants')])->prefix('admin/v1/ai-language-variants')->group(function () {
    // Catalog CRUD like countries (translations, status, trash); guarded by admin.screen (the evaluations and the
    // user-preferences screens may read the list / dropdown).
    Route::get('/', [AiLanguageVariantController::class, 'index']);
    Route::post('/', [AiLanguageVariantController::class, 'store']);
    Route::get('dropdown', [AiLanguageVariantController::class, 'dropdown']);
    Route::post('delete-multiple', [AiLanguageVariantController::class, 'deleteMultiple']);
    Route::get('{variant}', [AiLanguageVariantController::class, 'show'])->whereNumber('variant');
    Route::put('{variant}', [AiLanguageVariantController::class, 'update'])->whereNumber('variant');
    Route::delete('{variant}', [AiLanguageVariantController::class, 'destroy'])->whereNumber('variant');
    Route::post('{variant}/restore', [AiLanguageVariantController::class, 'restore'])->whereNumber('variant');
    Route::delete('{variant}/force', [AiLanguageVariantController::class, 'forceDestroy'])->whereNumber('variant');
    Route::patch('{variant}/status', [AiLanguageVariantController::class, 'changeStatus'])->whereNumber('variant');
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general', AiAdminScreens::middleware('ai-user-language-preferences')])->prefix('admin/v1/ai-user-language-preferences')->group(function () {
    Route::get('/', [AiUserLanguagePreferenceController::class, 'index']);
    Route::get('{preference}', [AiUserLanguagePreferenceController::class, 'show']);
    Route::put('{preference}', [AiUserLanguagePreferenceController::class, 'update']);
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general', AiAdminScreens::middleware('ai-language-evaluations')])->prefix('admin/v1/ai-language-evaluations')->group(function () {
    // Catalog CRUD like countries (translations, status, trash); guarded by admin.screen.
    Route::get('/', [AiLanguageEvaluationController::class, 'index']);
    Route::post('/', [AiLanguageEvaluationController::class, 'store']);
    Route::post('delete-multiple', [AiLanguageEvaluationController::class, 'deleteMultiple']);
    Route::get('{evaluation}', [AiLanguageEvaluationController::class, 'show'])->whereNumber('evaluation');
    Route::put('{evaluation}', [AiLanguageEvaluationController::class, 'update'])->whereNumber('evaluation');
    Route::delete('{evaluation}', [AiLanguageEvaluationController::class, 'destroy'])->whereNumber('evaluation');
    Route::post('{evaluation}/restore', [AiLanguageEvaluationController::class, 'restore'])->whereNumber('evaluation');
    Route::delete('{evaluation}/force', [AiLanguageEvaluationController::class, 'forceDestroy'])->whereNumber('evaluation');
    Route::patch('{evaluation}/status', [AiLanguageEvaluationController::class, 'changeStatus'])->whereNumber('evaluation');
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general', AiAdminScreens::middleware('ai-files')])->prefix('admin/v1/ai-files')->group(function () {
    Route::get('/', [AiFileController::class, 'index']);
    Route::get('{file}', [AiFileController::class, 'show']);
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general', AiAdminScreens::middleware('ai-file-processing')])->prefix('admin/v1/ai-file-processing')->group(function () {
    Route::get('/', [AiFileProcessingController::class, 'index']);
    Route::get('{processing}', [AiFileProcessingController::class, 'show']);
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general', AiAdminScreens::middleware('ai-knowledge-sources')])->prefix('admin/v1/ai-knowledge-sources')->group(function () {
    Route::get('/', [AiKnowledgeSourceController::class, 'index']);
    Route::post('/', [AiKnowledgeSourceController::class, 'store']);
    Route::get('{source}', [AiKnowledgeSourceController::class, 'show']);
    Route::post('{source}/approve', [AiKnowledgeSourceController::class, 'approve']);
    Route::post('{source}/reject', [AiKnowledgeSourceController::class, 'reject']);
    Route::post('{source}/deprecate', [AiKnowledgeSourceController::class, 'deprecate']);
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general', AiAdminScreens::middleware('ai-knowledge-chunks')])->prefix('admin/v1/ai-knowledge-chunks')->group(function () {
    Route::get('/', [AiKnowledgeChunkController::class, 'index']);
    Route::get('{chunk}', [AiKnowledgeChunkController::class, 'show']);
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general', AiAdminScreens::middleware('ai-document-generations')])->prefix('admin/v1/ai-document-generations')->group(function () {
    Route::get('/', [AiDocumentGenerationController::class, 'index']);
    Route::get('{generation}', [AiDocumentGenerationController::class, 'show']);
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general', AiAdminScreens::middleware('ai-project-instructions')])->prefix('admin/v1/ai-project-instructions')->group(function () {
    Route::get('/', [AiProjectInstructionController::class, 'index']);
    Route::post('/', [AiProjectInstructionController::class, 'store']);
    Route::post('delete-multiple', [AiProjectInstructionController::class, 'deleteMultiple']);
    Route::get('{instruction}', [AiProjectInstructionController::class, 'show'])->whereNumber('instruction');
    Route::put('{instruction}', [AiProjectInstructionController::class, 'update'])->whereNumber('instruction');
    Route::delete('{instruction}', [AiProjectInstructionController::class, 'destroy'])->whereNumber('instruction');
    Route::post('{instruction}/restore', [AiProjectInstructionController::class, 'restore'])->whereNumber('instruction');
    Route::delete('{instruction}/force', [AiProjectInstructionController::class, 'forceDestroy'])->whereNumber('instruction');
    Route::patch('{instruction}/status', [AiProjectInstructionController::class, 'changeStatus'])->whereNumber('instruction');
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general', AiAdminScreens::middleware('ai-project-context')])->prefix('admin/v1/ai-project-context')->group(function () {
    Route::get('/', [AiProjectContextController::class, 'index']);
    Route::get('{context}', [AiProjectContextController::class, 'show']);
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general', AiAdminScreens::middleware('ai-benchmark-cases')])->prefix('admin/v1/ai-benchmark-cases')->group(function () {
    // Catalog list like countries (status, trash; no translations); guarded by admin.screen.
    Route::get('/', [AiBenchmarkCaseController::class, 'index']);
    Route::post('/', [AiBenchmarkCaseController::class, 'store']);
    Route::post('delete-multiple', [AiBenchmarkCaseController::class, 'deleteMultiple']);
    Route::get('{case}', [AiBenchmarkCaseController::class, 'show'])->whereNumber('case');
    Route::put('{case}', [AiBenchmarkCaseController::class, 'update'])->whereNumber('case');
    Route::delete('{case}', [AiBenchmarkCaseController::class, 'destroy'])->whereNumber('case');
    Route::post('{case}/restore', [AiBenchmarkCaseController::class, 'restore'])->whereNumber('case');
    Route::delete('{case}/force', [AiBenchmarkCaseController::class, 'forceDestroy'])->whereNumber('case');
    Route::patch('{case}/status', [AiBenchmarkCaseController::class, 'changeStatus'])->whereNumber('case');
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general', AiAdminScreens::middleware('ai-benchmark-runs')])->prefix('admin/v1/ai-benchmark-runs')->group(function () {
    Route::get('/', [AiBenchmarkRunController::class, 'index']);
    Route::post('/', [AiBenchmarkRunController::class, 'store']);
    // Must be registered before the {run} route below, or "config" would
    // be swallowed as a $run id and 404 on model binding.
    Route::get('config', [AiBenchmarkRunController::class, 'config']);
    Route::get('{run}', [AiBenchmarkRunController::class, 'show']);
});

// Website builder: standalone offers (price per country) and moderation of customer sites.
// Catalog CRUD like countries (bulk delete, trash, status); AiSiteOfferController checks ai-site-offers.* itself.
Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general'])->prefix('admin/v1/ai-site-offers')->group(function () {
    Route::get('/', [AiSiteOfferController::class, 'index']);
    Route::post('/', [AiSiteOfferController::class, 'store']);
    Route::post('delete-multiple', [AiSiteOfferController::class, 'deleteMultiple']);
    Route::get('{offer}', [AiSiteOfferController::class, 'show'])->whereNumber('offer');
    Route::put('{offer}', [AiSiteOfferController::class, 'update'])->whereNumber('offer');
    Route::delete('{offer}', [AiSiteOfferController::class, 'destroy'])->whereNumber('offer');
    Route::post('{offer}/restore', [AiSiteOfferController::class, 'restore'])->whereNumber('offer');
    Route::delete('{offer}/force', [AiSiteOfferController::class, 'forceDestroy'])->whereNumber('offer');
    Route::patch('{offer}/status', [AiSiteOfferController::class, 'changeStatus'])->whereNumber('offer');
    Route::get('{offer}/prices', [AiSiteOfferController::class, 'prices'])->whereNumber('offer');
    Route::put('{offer}/prices', [AiSiteOfferController::class, 'syncPrices'])->whereNumber('offer');
});

// Source download (ZIP) of a built site: on/off and free/paid.
Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general', AiAdminScreens::middleware('ai-site-download')])->prefix('admin/v1/ai-site-download')->group(function () {
    Route::get('/', [AiSiteDownloadSettingController::class, 'show']);
    Route::put('/', [AiSiteDownloadSettingController::class, 'update']);
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general', AiAdminScreens::middleware('ai-sites')])->prefix('admin/v1/ai-sites')->group(function () {
    Route::get('/', [AiSiteAdminProjectController::class, 'index']);
    Route::get('{project}', [AiSiteAdminProjectController::class, 'show'])->whereNumber('project');
    Route::post('{project}/disable', [AiSiteAdminProjectController::class, 'disable'])->whereNumber('project');
    Route::post('{project}/enable', [AiSiteAdminProjectController::class, 'enable'])->whereNumber('project');
    Route::delete('{project}', [AiSiteAdminProjectController::class, 'destroy'])->whereNumber('project');
});

// Site hosting: plans with a price per country, and every hosted site.
// Catalog CRUD like countries (bulk delete, trash, status); AiSiteHostingPlanController checks ai-site-hosting-plans.* itself.
Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general'])->prefix('admin/v1/ai-site-hosting-plans')->group(function () {
    Route::get('/', [AiSiteHostingPlanController::class, 'index']);
    Route::post('/', [AiSiteHostingPlanController::class, 'store']);
    Route::post('delete-multiple', [AiSiteHostingPlanController::class, 'deleteMultiple']);
    Route::get('{plan}', [AiSiteHostingPlanController::class, 'show'])->whereNumber('plan');
    Route::put('{plan}', [AiSiteHostingPlanController::class, 'update'])->whereNumber('plan');
    Route::delete('{plan}', [AiSiteHostingPlanController::class, 'destroy'])->whereNumber('plan');
    Route::post('{plan}/restore', [AiSiteHostingPlanController::class, 'restore'])->whereNumber('plan');
    Route::delete('{plan}/force', [AiSiteHostingPlanController::class, 'forceDestroy'])->whereNumber('plan');
    Route::patch('{plan}/status', [AiSiteHostingPlanController::class, 'changeStatus'])->whereNumber('plan');
    Route::get('{plan}/prices', [AiSiteHostingPlanController::class, 'prices'])->whereNumber('plan');
    Route::put('{plan}/prices', [AiSiteHostingPlanController::class, 'syncPrices'])->whereNumber('plan');
});

Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general', AiAdminScreens::middleware('ai-site-hostings')])->prefix('admin/v1/ai-site-hostings')->group(function () {
    Route::get('/', [AiSiteAdminHostingController::class, 'index']);
    Route::get('{hosting}', [AiSiteAdminHostingController::class, 'show'])->whereNumber('hosting');
    Route::post('{hosting}/suspend', [AiSiteAdminHostingController::class, 'suspend'])->whereNumber('hosting');
    Route::post('{hosting}/resume', [AiSiteAdminHostingController::class, 'resume'])->whereNumber('hosting');
    Route::delete('{hosting}', [AiSiteAdminHostingController::class, 'destroy'])->whereNumber('hosting');
});

// Human-assisted site requests: inbox, quote builder, run-the-work actions, conversation, switch and statuses.
Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general', AiAdminScreens::middleware('ai-site-requests')])->prefix('admin/v1/ai-site-requests')->group(function () {
    Route::get('summary', [AiSiteServiceRequestAdminController::class, 'summary']);
    Route::get('settings', [AiSiteServiceRequestAdminController::class, 'settings']);
    Route::put('settings', [AiSiteServiceRequestAdminController::class, 'updateSettings']);
    Route::get('statuses', [AiSiteServiceRequestAdminController::class, 'statuses']);
    Route::post('statuses', [AiSiteServiceRequestAdminController::class, 'storeStatus']);
    Route::put('statuses/{status}', [AiSiteServiceRequestAdminController::class, 'updateStatus'])->whereNumber('status');
    Route::delete('statuses/{status}', [AiSiteServiceRequestAdminController::class, 'destroyStatus'])->whereNumber('status');

    Route::get('/', [AiSiteServiceRequestAdminController::class, 'index']);
    Route::get('{id}', [AiSiteServiceRequestAdminController::class, 'show'])->whereNumber('id');
    Route::put('{id}', [AiSiteServiceRequestAdminController::class, 'update'])->whereNumber('id');
    Route::post('{id}/quote', [AiSiteServiceRequestAdminController::class, 'quote'])->whereNumber('id');
    Route::post('{id}/status', [AiSiteServiceRequestAdminController::class, 'changeStatus'])->whereNumber('id');
    Route::post('{id}/extend', [AiSiteServiceRequestAdminController::class, 'extend'])->whereNumber('id');
    Route::post('{id}/milestones/{milestone}', [AiSiteServiceRequestAdminController::class, 'milestone'])->whereNumber(['id', 'milestone']);
    Route::post('{id}/installments/{installment}/release', [AiSiteServiceRequestAdminController::class, 'releaseInstallment'])->whereNumber(['id', 'installment']);
    Route::get('{id}/messages', [AiSiteServiceRequestAdminController::class, 'messages'])->whereNumber('id');
    Route::post('{id}/messages', [AiSiteServiceRequestAdminController::class, 'sendMessage'])->whereNumber('id');
    Route::get('{id}/files/{index}', [AiSiteServiceRequestAdminController::class, 'file'])->whereNumber(['id', 'index']);
});

// DORR AI WORLD ops console: settings, country modes, prices, projects with internal costs, reports.
Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general', AiAdminScreens::middleware('ai-world')])->prefix('admin/v1/ai-world')->group(function () {
    Route::get('stats', [AiWorldAdminController::class, 'stats']);
    Route::get('settings', [AiWorldAdminController::class, 'settings']);
    Route::put('settings', [AiWorldAdminController::class, 'updateSettings']);
    Route::get('country-modes', [AiWorldAdminController::class, 'countryModes']);
    Route::put('country-modes', [AiWorldAdminController::class, 'updateCountryModes']);
    Route::get('prices', [AiWorldAdminController::class, 'prices']);
    Route::put('prices', [AiWorldAdminController::class, 'syncPrices']);
    Route::get('projects', [AiWorldAdminController::class, 'projects']);
    Route::get('projects/{id}', [AiWorldAdminController::class, 'project'])->whereNumber('id');
    Route::get('reports', [AiWorldAdminController::class, 'reports']);
    Route::put('reports/{id}', [AiWorldAdminController::class, 'updateReport'])->whereNumber('id');
});

// DORR AR engine ops console: settings, model library (licences, versions), tested devices.
Route::middleware(['locale', 'auth:admin_api', 'throttle:ai-admin-general', AiAdminScreens::middleware('ai-ar')])->prefix('admin/v1/ai-ar')->group(function () {
    Route::get('settings', [AiArAdminController::class, 'settings']);
    Route::put('settings', [AiArAdminController::class, 'updateSettings']);
    Route::get('assets', [AiArAdminController::class, 'assets']);
    Route::post('assets', [AiArAdminController::class, 'storeAsset']);
    Route::put('assets/{id}', [AiArAdminController::class, 'updateAsset'])->whereNumber('id');
    Route::post('assets/{id}/versions', [AiArAdminController::class, 'addVersion'])->whereNumber('id');
    Route::post('assets/{id}/activate', [AiArAdminController::class, 'activate'])->whereNumber('id');
    Route::post('assets/{id}/publish', [AiArAdminController::class, 'publish'])->whereNumber('id');
    Route::post('assets/{id}/reject', [AiArAdminController::class, 'reject'])->whereNumber('id');
    Route::get('reviews', [AiArAdminController::class, 'reviews']);
    Route::get('orders', [AiArAdminController::class, 'orders']);
    Route::post('orders/{id}/resolve', [AiArAdminController::class, 'resolveOrder'])->whereNumber('id');
    Route::get('devices', [AiArAdminController::class, 'devices']);
    Route::post('devices', [AiArAdminController::class, 'storeDevice']);
    Route::delete('devices/{id}', [AiArAdminController::class, 'destroyDevice'])->whereNumber('id');
});
