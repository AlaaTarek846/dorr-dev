package com.dorr.app.network

import com.google.gson.JsonElement
import com.google.gson.JsonObject
import com.google.gson.annotations.SerializedName
import retrofit2.http.Body
import retrofit2.http.DELETE
import retrofit2.http.GET
import retrofit2.http.Header
import retrofit2.http.PATCH
import retrofit2.http.POST
import retrofit2.http.Path
import retrofit2.http.Query

/**
 * DORR AI tools in the chat (spec 31, 36–42, 46, 48, 49) and my tasks (38). Each call is one
 * explicit tap; every free answer comes with `safety` (spec 350–362) when it applies.
 */
interface AiToolsApi {
    /** `{question, messages?[], history?[{q, a}]}` — DORR AI inside this chat, only for me. */
    @POST("mobile/v1/chat/conversations/{id}/assistant")
    suspend fun assistant(@Header("Authorization") auth: String, @Path("id") conversationId: String, @Body body: JsonObject): ApiEnvelope<AssistantAnswerDto>

    /** `{text}` — the text I'm writing, spelling and grammar fixed. */
    @POST("mobile/v1/chat/ai/proofread")
    suspend fun proofread(@Header("Authorization") auth: String, @Body body: JsonObject): ApiEnvelope<ProofreadDto>

    @POST("mobile/v1/chat/messages/{id}/understand")
    suspend fun understand(@Header("Authorization") auth: String, @Path("id") messageId: String): ApiEnvelope<UnderstandDto>

    @POST("mobile/v1/chat/messages/{id}/simplify")
    suspend fun simplify(@Header("Authorization") auth: String, @Path("id") messageId: String): ApiEnvelope<SimplifyDto>

    /** `{timezone}` — suggested tasks from a message (saved only with [createTasks]). */
    @POST("mobile/v1/chat/messages/{id}/tasks")
    suspend fun tasksFrom(@Header("Authorization") auth: String, @Path("id") messageId: String, @Body body: JsonObject): ApiEnvelope<TaskSuggestionsDto>

    /** `{messages[]}` — a short note, saved into "Notes (you)". */
    @POST("mobile/v1/chat/conversations/{id}/note")
    suspend fun note(@Header("Authorization") auth: String, @Path("id") conversationId: String, @Body body: JsonObject): ApiEnvelope<JsonElement>

    /** `{messages?[], timezone}` — dates mentioned in the chat (suggestions). */
    @POST("mobile/v1/chat/conversations/{id}/dates")
    suspend fun dates(@Header("Authorization") auth: String, @Path("id") conversationId: String, @Body body: JsonObject): ApiEnvelope<DatesDto>

    /** `{conversation_id?}` — what deserves attention: in one chat, or across my unread chats. */
    @POST("mobile/v1/chat/ai/important")
    suspend fun important(@Header("Authorization") auth: String, @Body body: JsonObject): ApiEnvelope<ImportantDto>

    @POST("mobile/v1/chat/conversations/{id}/related-files")
    suspend fun relatedFiles(@Header("Authorization") auth: String, @Path("id") conversationId: String): ApiEnvelope<RelatedFilesDto>

    /** `{timezone}` — today across my chats. */
    @POST("mobile/v1/chat/ai/today")
    suspend fun today(@Header("Authorization") auth: String, @Body body: JsonObject): ApiEnvelope<TodayDto>

    @GET("mobile/v1/chat/tasks")
    suspend fun tasks(@Header("Authorization") auth: String, @Query("status") status: String? = null): ApiEnvelope<List<TaskDto>>

    /** `{tasks: [{text, due_at?}], message_id?}` */
    @POST("mobile/v1/chat/tasks")
    suspend fun createTasks(@Header("Authorization") auth: String, @Body body: JsonObject): ApiEnvelope<List<TaskDto>>

    /** `{text?, due_at?, done?}` */
    @PATCH("mobile/v1/chat/tasks/{id}")
    suspend fun updateTask(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: JsonObject): ApiEnvelope<TaskDto>

    @DELETE("mobile/v1/chat/tasks/{id}")
    suspend fun deleteTask(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<JsonElement?>
}

/**
 * DORR AI safety (spec 350–362) for one answer: its domain (religion · law · medicine ·
 * engineering · code), whether it's a personal / specific case, and the approved texts the system
 * added — shown as the alert card inside the reply.
 */
data class SafetyDto(
    val domain: String,
    val specific: Boolean = false,
    val disclaimer: String? = null,
    val notice: String? = null,
)

data class AssistantAnswerDto(
    val answer: String,
    val safety: SafetyDto? = null,
    val references: List<AskReferenceDto> = emptyList(),
)

data class ProofreadDto(val text: String, val changed: Boolean = false)

data class UnderstandDto(
    val intent: String = "other",
    val tone: String = "neutral",
    val summary: String = "",
    @SerializedName("tone_note") val toneNote: String? = null,
    val actions: List<String> = emptyList(),
    val replies: List<String> = emptyList(),
    val safety: SafetyDto? = null,
)

data class SimplifyDto(val short: String = "", val points: List<String> = emptyList())

data class TaskSuggestionDto(val text: String, @SerializedName("due_at") val dueAt: String? = null)

data class TaskSuggestionsDto(val tasks: List<TaskSuggestionDto> = emptyList())

data class TaskDto(
    val id: String,
    val text: String,
    @SerializedName("due_at") val dueAt: String? = null,
    val done: Boolean = false,
    @SerializedName("message_id") val messageId: String? = null,
    @SerializedName("conversation_id") val conversationId: String? = null,
)

data class DateFoundDto(
    val title: String,
    val at: String,
    @SerializedName("all_day") val allDay: Boolean = false,
    @SerializedName("message_id") val messageId: String? = null,
)

data class DatesDto(val dates: List<DateFoundDto> = emptyList(), val messages: Int = 0)

/** A message the AI pointed at (important / today), with where it lives. */
data class AiMessageRefDto(
    @SerializedName("message_id") val messageId: String,
    @SerializedName("conversation_id") val conversationId: String? = null,
    val chat: String? = null,
    val sender: String? = null,
    val excerpt: String? = null,
    @SerializedName("created_at") val createdAt: String? = null,
    val level: String? = null,
    val why: String? = null,
    val text: String? = null,
)

data class ImportantDto(val items: List<AiMessageRefDto> = emptyList(), val messages: Int = 0, val chats: Int = 0)

data class TodayDto(val summary: List<String> = emptyList(), val highlights: List<AiMessageRefDto> = emptyList(), val messages: Int = 0, val chats: Int = 0)

data class RelatedFileDto(
    @SerializedName("message_id") val messageId: String,
    val type: String,
    val name: String? = null,
    val url: String? = null,
    @SerializedName("created_at") val createdAt: String? = null,
    val why: String? = null,
)

data class RelatedFilesDto(val files: List<RelatedFileDto> = emptyList())
