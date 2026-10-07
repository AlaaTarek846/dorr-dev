package com.dorr.app.network

import com.google.gson.annotations.SerializedName

/**
 * DTOs for the AI Assistant chat under /api/user/v1/ai-chat (and the identical
 * /api/provider/v1/ai-chat for the provider app) — Modules/AI's AiChatController.
 * Mirrors AiConversationResource / AiMessageResource / AiConversationAttachmentResource
 * field-for-field; see AiChatService::usageSummary() for the usage block.
 */

data class AiStatusDto(
    val available: Boolean,
    @SerializedName("brand_name") val brandName: String?,
    // Phase 11 (doc S32): server-authoritative file limits for the
    // attachment picker - never hardcode a different limit client-side.
    @SerializedName("file_limits") val fileLimits: AiFileLimitsDto? = null,
)

data class AiFileLimitsDto(
    @SerializedName("max_size_bytes") val maxSizeBytes: Long?,
    @SerializedName("allowed_mime_types") val allowedMimeTypes: List<String> = emptyList(),
    @SerializedName("max_conversation_files") val maxConversationFiles: Int? = null,
)

data class AiUsageDto(
    val allowed: Boolean,
    val reason: String?,
    @SerializedName("plan_name") val planName: String?,
    @SerializedName("plan_is_trial") val planIsTrial: Boolean?,
    @SerializedName("trial_status") val trialStatus: String?,
    @SerializedName("remaining_seconds") val remainingSeconds: Long?,
    @SerializedName("cooldown_seconds_left") val cooldownSecondsLeft: Long?,
)

data class AiConversationDto(
    val id: Int,
    val title: String?,
    @SerializedName("provider_key") val providerKey: String?,
    @SerializedName("messages_count") val messagesCount: Int?,
    @SerializedName("last_message_preview") val lastMessagePreview: String?,
    val messages: List<AiMessageDto>?,
    @SerializedName("created_at") val createdAt: String?,
    @SerializedName("updated_at") val updatedAt: String?,
)

data class AiMessageDto(
    val id: Int,
    /** "user" | "assistant". */
    val role: String,
    val content: String,
    @SerializedName("is_error") val isError: Boolean = false,
    val model: String?,
    @SerializedName("provider_key") val providerKey: String?,
    val attachments: List<AiAttachmentDto> = emptyList(),
    @SerializedName("generated_file") val generatedFile: AiGeneratedFileDto? = null,
    @SerializedName("confidence_score") val confidenceScore: Double? = null,
    @SerializedName("verification_warnings") val verificationWarnings: List<String>? = null,
    // Phase 11 (doc S14/S37): file-grounded citations for this turn -
    // see AiFileCitationResource server-side. Only real, already-
    // computed metadata; never rendered unless this list is non-empty.
    val citations: List<AiFileCitationDto> = emptyList(),
    @SerializedName("created_at") val createdAt: String?,
) {
    val isUser: Boolean get() = role == "user"
}

/** Mirrors Modules/AI's AiFileCitationResource field-for-field. */
data class AiFileCitationDto(
    val id: Int,
    val number: Int?,
    @SerializedName("file_id") val fileId: Int,
    @SerializedName("file_name") val fileName: String?,
    val excerpt: String?,
    val score: Double?,
    @SerializedName("retrieval_method") val retrievalMethod: String?,
    // Only the subset of keys the chunk's own content type produced -
    // see AiRetrievalEngine::sourceReference(). Never invented.
    val location: AiCitationLocationDto? = null,
)

data class AiCitationLocationDto(
    val page: Int? = null,
    val section: String? = null,
    val sheet: String? = null,
    @SerializedName("row_start") val rowStart: Int? = null,
    @SerializedName("row_end") val rowEnd: Int? = null,
    val slide: Int? = null,
    @SerializedName("timestamp_start") val timestampStart: Double? = null,
    @SerializedName("timestamp_end") val timestampEnd: Double? = null,
)

/**
 * Phase 11 (doc S10/S27): one file attached to (or available in) a
 * conversation - mirrors Modules/AI's AiFileResource (a subset of its
 * fields; the rest - checksum, owner, chunking/embedding internals -
 * are deliberately never surfaced to this client, doc S37).
 */
data class AiFileDto(
    val id: Int,
    @SerializedName("file_name") val fileName: String?,
    @SerializedName("mime_type") val mimeType: String?,
    @SerializedName("file_size") val fileSize: Long?,
    // "uploading" | "processing" | "ready" | "failed" (AiFile lifecycle).
    val status: String?,
    @SerializedName("processing_error") val processingError: String?,
)

data class AiAttachmentDto(
    val id: Int,
    @SerializedName("message_id") val messageId: Int?,
    @SerializedName("file_name") val fileName: String?,
    @SerializedName("mime_type") val mimeType: String?,
    @SerializedName("file_size") val fileSize: Long?,
    @SerializedName("is_image") val isImage: Boolean = false,
    val url: String?,
    // Client-only, never sent by or to the server (no @SerializedName, so
    // Gson just leaves it null on every parsed response). Lets the
    // composer's optimistic pending-message bubble (AiConversationPage.kt)
    // show the user's own just-picked image or just-recorded voice note
    // immediately from the local cache file, instead of rendering nothing
    // at all until the real attachment comes back from the server with a
    // `url` - which is exactly the "voice/image doesn't show until the AI
    // replies" bug this field exists to fix.
    val localUri: String? = null,
)

data class AiGeneratedFileDto(
    val name: String?,
    val url: String?,
)

data class AiSourceDto(
    val source: String?,
    val publisher: String?,
    val position: Int?,
    val score: Double?,
)

/** The full reply envelope from sendMessage()/streamMessage()'s "done" event. */
data class AiSendMessageResultDto(
    @SerializedName("user_message") val userMessage: AiMessageDto?,
    @SerializedName("assistant_message") val assistantMessage: AiMessageDto?,
    val conversation: AiConversationDto?,
    val usage: AiUsageDto?,
    @SerializedName("trace_id") val traceId: String?,
    val status: String?,
    val confidence: Double?,
    val sources: List<AiSourceDto>?,
    val warnings: List<String>?,
)

data class AiEraseDataResultDto(
    @SerializedName("deleted_conversations") val deletedConversations: Int,
)

/**
 * Phase 7 (realtime voice) request/response DTOs — see AiRealtimeService
 * server-side and AiChatApi.createRealtimeSession()/endRealtimeSession().
 */
data class AiRealtimeSessionRequestDto(
    @SerializedName("conversation_id") val conversationId: Int? = null,
    val instructions: String? = null,
)

data class AiRealtimeSessionDto(
    @SerializedName("session_id") val sessionId: Int,
    @SerializedName("client_secret") val clientSecret: String,
    @SerializedName("expires_at") val expiresAt: Long?,
    val model: String,
    @SerializedName("webrtc_endpoint") val webrtcEndpoint: String,
)

data class AiRealtimeSessionEndRequestDto(
    @SerializedName("duration_seconds") val durationSeconds: Int? = null,
)

// Professional AI subscription system linked to the wallet (2026-09-29).
// Mirrors AiPlanResource / AiSubscriptionResource / AiSubscriptionPaymentResource
// field-for-field (Modules/AI's AiUserSubscriptionController).

data class AiPlanDto(
    val id: Int,
    val name: String,
    val code: String,
    val description: String?,
    @SerializedName("usage_minutes") val usageMinutes: Int,
    @SerializedName("cooldown_minutes") val cooldownMinutes: Int,
    @SerializedName("duration_days") val durationDays: Int,
    val price: String,
    @SerializedName("original_price") val originalPrice: String? = null,
    @SerializedName("discount_percent") val discountPercent: Int? = null,
    val currency: String,
    val badge: String? = null,
    @SerializedName("is_featured") val isFeatured: Boolean = false,
    val features: List<String> = emptyList(),
    @SerializedName("is_trial") val isTrial: Boolean,
    @SerializedName("is_active") val isActive: Boolean,
    @SerializedName("sort_order") val sortOrder: Int,
    // True when this price was set specifically for the customer's own
    // country; false means it's the plan's base price/currency (no
    // country-specific override configured for them).
    @SerializedName("is_country_specific_price") val isCountrySpecificPrice: Boolean = false,
)

data class AiSubscriptionDto(
    val id: Int,
    val plan: AiPlanDto?,
    @SerializedName("starts_at") val startsAt: String?,
    @SerializedName("ends_at") val endsAt: String?,
    val status: String,
    @SerializedName("auto_renew") val autoRenew: Boolean,
    @SerializedName("in_grace_period") val inGracePeriod: Boolean,
    @SerializedName("grace_ends_at") val graceEndsAt: String?,
    @SerializedName("days_remaining") val daysRemaining: Int?,
    @SerializedName("current_plan_price") val currentPlanPrice: String?,
)

data class AiSubscriptionPaymentDto(
    val id: Int,
    val plan: AiPlanDto?,
    /** "initial" | "renewal" | "upgrade" | "downgrade". */
    val type: String,
    /** "succeeded" | "failed". */
    val status: String,
    val amount: String,
    val currency: String,
    @SerializedName("failure_reason") val failureReason: String?,
    @SerializedName("created_at") val createdAt: String?,
)

data class AiSubscribeRequestDto(
    @SerializedName("plan_id") val planId: Int,
    @SerializedName("auto_renew") val autoRenew: Boolean = true,
)

data class AiChangePlanRequestDto(
    @SerializedName("plan_id") val planId: Int,
)

data class AiAutoRenewRequestDto(
    @SerializedName("auto_renew") val autoRenew: Boolean,
)

// Business gap fix (2026-10-03): user-facing language/dialect preference -
// see AiChatApi.languagePreference()/updateLanguagePreference()'s own
// docblock and Modules/AI's AiUserLanguagePreferenceSelfController.

data class AiLanguageDto(
    val id: Int,
    val code: String,
    val name: String,
    val direction: String?,
    @SerializedName("ai_enabled") val aiEnabled: Boolean,
)

data class AiLanguageVariantDto(
    val id: Int,
    @SerializedName("language_id") val languageId: Int?,
    val code: String,
    val name: String,
    /** "formal" | "conversational". */
    val style: String?,
    @SerializedName("is_default") val isDefault: Boolean,
    @SerializedName("is_active") val isActive: Boolean,
)

data class AiLanguageRefDto(
    val id: Int,
    val code: String,
    val name: String,
)

data class AiUserLanguagePreferenceDto(
    val id: Int,
    val language: AiLanguageRefDto?,
    val variant: AiLanguageRefDto?,
    @SerializedName("auto_detect") val autoDetect: Boolean,
    /** "follow_input" | "fixed". */
    @SerializedName("response_language_mode") val responseLanguageMode: String,
)

data class AiLanguagePreferenceShowDto(
    val preference: AiUserLanguagePreferenceDto,
    val languages: List<AiLanguageDto>,
    val variants: List<AiLanguageVariantDto>,
)

data class AiLanguagePreferenceUpdateRequestDto(
    @SerializedName("response_language_mode") val responseLanguageMode: String,
    @SerializedName("language_id") val languageId: Int?,
    @SerializedName("variant_id") val variantId: Int?,
)
