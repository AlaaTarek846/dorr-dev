package com.dorr.app.network

import com.google.gson.annotations.SerializedName
import retrofit2.http.Body
import retrofit2.http.DELETE
import retrofit2.http.GET
import retrofit2.http.Header
import retrofit2.http.POST
import retrofit2.http.PUT
import retrofit2.http.Path
import retrofit2.http.Query

/**
 * DORR AI's own tools (2026-10-14) under /api/user/v1/ai-chat — Modules/AI's AiMemoryController,
 * AiHistoryController and AiUserProjectController: memory (spec 140–144), search by word and by
 * meaning (133–139), the outputs gallery (344), "continue in a new chat" with its Context Capsule
 * (121–132), and projects (151). Same conventions as [AiChatApi].
 */
interface AiAssistantApi {
    // ---------------------------------------------------------------- memory
    @GET("user/v1/ai-chat/memories")
    suspend fun memories(@Header("Authorization") auth: String): ApiEnvelope<AiMemoriesDto>

    @POST("user/v1/ai-chat/memories")
    suspend fun addMemory(@Header("Authorization") auth: String, @Body body: AiMemoryRequestDto): ApiEnvelope<AiMemoryDto>

    @PUT("user/v1/ai-chat/memories/{id}")
    suspend fun updateMemory(@Header("Authorization") auth: String, @Path("id") id: Int, @Body body: AiMemoryRequestDto): ApiEnvelope<AiMemoryDto>

    @DELETE("user/v1/ai-chat/memories/{id}")
    suspend fun deleteMemory(@Header("Authorization") auth: String, @Path("id") id: Int): ApiEnvelope<Any?>

    @DELETE("user/v1/ai-chat/memories")
    suspend fun deleteAllMemories(@Header("Authorization") auth: String): ApiEnvelope<Any?>

    @PUT("user/v1/ai-chat/memories/settings")
    suspend fun memorySettings(@Header("Authorization") auth: String, @Body body: AiMemorySettingsDto): ApiEnvelope<AiMemorySettingsDto>

    // ---------------------------------------------------------------- history
    @GET("user/v1/ai-chat/search")
    suspend fun search(@Header("Authorization") auth: String, @Query("q") query: String): ApiEnvelope<AiSearchResultDto>

    @GET("user/v1/ai-chat/outputs")
    suspend fun outputs(@Header("Authorization") auth: String, @Query("kind") kind: String? = null): ApiEnvelope<List<AiOutputDto>>

    @POST("user/v1/ai-chat/conversations/continue")
    suspend fun continueConversations(@Header("Authorization") auth: String, @Body body: AiContinueRequestDto): ApiEnvelope<AiContinueResultDto>

    @GET("user/v1/ai-chat/conversations/{id}/capsule")
    suspend fun capsule(@Header("Authorization") auth: String, @Path("id") id: Int): ApiEnvelope<AiCapsuleHolderDto>

    @DELETE("user/v1/ai-chat/conversations/{id}/capsule")
    suspend fun deleteCapsule(@Header("Authorization") auth: String, @Path("id") id: Int): ApiEnvelope<Any?>

    // ---------------------------------------------------------------- projects
    @GET("user/v1/ai-chat/projects")
    suspend fun projects(@Header("Authorization") auth: String): ApiEnvelope<List<AiProjectDto>>

    @GET("user/v1/ai-chat/projects/{id}")
    suspend fun project(@Header("Authorization") auth: String, @Path("id") id: Int): ApiEnvelope<AiProjectDto>

    @POST("user/v1/ai-chat/projects")
    suspend fun createProject(@Header("Authorization") auth: String, @Body body: AiProjectRequestDto): ApiEnvelope<AiProjectDto>

    @PUT("user/v1/ai-chat/projects/{id}")
    suspend fun updateProject(@Header("Authorization") auth: String, @Path("id") id: Int, @Body body: AiProjectRequestDto): ApiEnvelope<AiProjectDto>

    @DELETE("user/v1/ai-chat/projects/{id}")
    suspend fun deleteProject(@Header("Authorization") auth: String, @Path("id") id: Int): ApiEnvelope<Any?>

    @POST("user/v1/ai-chat/projects/{id}/conversations/{conversation}")
    suspend fun addProjectConversation(@Header("Authorization") auth: String, @Path("id") id: Int, @Path("conversation") conversation: Int): ApiEnvelope<AiProjectDto>

    @DELETE("user/v1/ai-chat/projects/{id}/conversations/{conversation}")
    suspend fun removeProjectConversation(@Header("Authorization") auth: String, @Path("id") id: Int, @Path("conversation") conversation: Int): ApiEnvelope<Any?>
}

data class AiMemoriesDto(
    val enabled: Boolean = true,
    val limit: Int = 100,
    val memories: List<AiMemoryDto> = emptyList(),
)

data class AiMemoryDto(
    val id: Int,
    val content: String,
    @SerializedName("created_at") val createdAt: String? = null,
)

data class AiMemoryRequestDto(val content: String)

data class AiMemorySettingsDto(val enabled: Boolean)

data class AiSearchResultDto(
    val query: String = "",
    val terms: List<String> = emptyList(),
    val results: List<AiSearchHitDto> = emptyList(),
)

data class AiSearchHitDto(
    /** "title" | "message" | "file" | "project". */
    val type: String,
    /** "lexical" (the words) | "semantic" (what they mean). */
    val match: String,
    val term: String? = null,
    val conversation: AiRefDto,
    @SerializedName("message_id") val messageId: Int? = null,
    val snippet: String = "",
    val highlights: List<AiHighlightDto> = emptyList(),
    @SerializedName("created_at") val createdAt: String? = null,
    val project: AiRefDto? = null,
)

data class AiRefDto(
    val id: Int,
    val title: String? = null,
    val name: String? = null,
)

data class AiHighlightDto(val start: Int, val length: Int)

data class AiOutputDto(
    /** "image" | "video" | "audio" | "document". */
    val kind: String,
    val name: String? = null,
    @SerializedName("mime_type") val mimeType: String? = null,
    val url: String? = null,
    val conversation: AiRefDto? = null,
    @SerializedName("message_id") val messageId: Int? = null,
    @SerializedName("created_at") val createdAt: String? = null,
)

data class AiContinueRequestDto(@SerializedName("conversation_ids") val conversationIds: List<Int>)

data class AiContinueResultDto(
    val conversation: AiConversationDto,
    val capsule: AiCapsuleDto? = null,
)

data class AiCapsuleHolderDto(val capsule: AiCapsuleDto? = null)

data class AiCapsuleDto(
    val goal: String? = null,
    val decisions: List<String> = emptyList(),
    val facts: List<String> = emptyList(),
    @SerializedName("open_tasks") val openTasks: List<String> = emptyList(),
    val done: List<String> = emptyList(),
    val files: List<String> = emptyList(),
    val sources: List<AiRefDto> = emptyList(),
)

data class AiProjectDto(
    val id: Int,
    val name: String,
    val description: String? = null,
    val instructions: String? = null,
    @SerializedName("conversations_count") val conversationsCount: Int? = null,
    @SerializedName("files_count") val filesCount: Int? = null,
    val conversations: List<AiRefDto>? = null,
)

data class AiProjectRequestDto(
    val name: String,
    val description: String?,
    val instructions: String?,
)
