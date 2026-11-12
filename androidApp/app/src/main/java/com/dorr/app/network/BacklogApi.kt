package com.dorr.app.network

import com.google.gson.JsonObject
import com.google.gson.annotations.SerializedName
import okhttp3.ResponseBody
import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.Header
import retrofit2.http.PATCH
import retrofit2.http.POST
import retrofit2.http.Path
import retrofit2.http.Query
import retrofit2.http.Streaming

/**
 * The simple items from the WhatsApp list in the spec's backlog: export a chat (25), manage
 * storage (46–48), a channel's numbers and reactions (107, 110), a business's numbers (116), a
 * broadcast list from a folder (129), a click-to-chat link (130), and sharing a status that
 * mentions me (85).
 */
interface BacklogApi {
    /** A .txt (or a .zip with the files when `media` = 1). */
    @Streaming
    @GET("mobile/v1/chat/conversations/{id}/export")
    suspend fun export(@Header("Authorization") auth: String, @Path("id") conversationId: String, @Query("media") media: Int): Response<ResponseBody>

    @GET("mobile/v1/chat/storage")
    suspend fun storage(@Header("Authorization") auth: String): ApiEnvelope<StorageDto>

    /** `kind` all · large · forwarded. Pages of 15. */
    @GET("mobile/v1/chat/storage/files")
    suspend fun storageFiles(
        @Header("Authorization") auth: String,
        @Query("kind") kind: String,
        @Query("conversation_id") conversationId: String? = null,
        @Query("page") page: Int = 1,
        @Query("per_page") perPage: Int = 15,
    ): ApiEnvelope<StorageFilesDto>

    /** `{messages[]}` — deleted for me only. */
    @POST("mobile/v1/chat/storage/delete")
    suspend fun deleteStorage(@Header("Authorization") auth: String, @Body body: JsonObject): ApiEnvelope<JsonObject>

    @GET("mobile/v1/chat/channels/{id}/stats")
    suspend fun channelStats(@Header("Authorization") auth: String, @Path("id") conversationId: String, @Query("days") days: Int): ApiEnvelope<ChannelStatsDto>

    /** `{reactions: [..] | null}` */
    @PATCH("mobile/v1/chat/channels/{id}/reactions")
    suspend fun channelReactions(@Header("Authorization") auth: String, @Path("id") conversationId: String, @Body body: JsonObject): ApiEnvelope<ConversationDto>

    @GET("mobile/v1/chat/business/stats")
    suspend fun businessStats(@Header("Authorization") auth: String, @Query("days") days: Int): ApiEnvelope<BusinessStatsDto>

    /** `{folder_id, name?}` */
    @POST("mobile/v1/chat/broadcasts/from-folder")
    suspend fun broadcastFromFolder(@Header("Authorization") auth: String, @Body body: JsonObject): ApiEnvelope<BroadcastDto>

    @GET("mobile/v1/chat/contacts/chat-link")
    suspend fun chatLink(@Header("Authorization") auth: String, @Query("text") text: String?): ApiEnvelope<ChatLinkDto>

    @POST("mobile/v1/chat/stories/{id}/reshare")
    suspend fun reshareStory(@Header("Authorization") auth: String, @Path("id") storyId: String): ApiEnvelope<JsonObject>
}

data class StorageChatDto(
    @SerializedName("conversation_id") val conversationId: String = "",
    val title: String? = null,
    val type: String = "direct",
    val bytes: Long = 0,
    val files: Int = 0,
)

data class StorageDto(
    @SerializedName("total_bytes") val totalBytes: Long = 0,
    val files: Int = 0,
    val large: Int = 0,
    val forwarded: Int = 0,
    val chats: List<StorageChatDto> = emptyList(),
)

data class StorageFileDto(
    @SerializedName("message_id") val messageId: String = "",
    @SerializedName("conversation_id") val conversationId: String = "",
    val chat: String? = null,
    val type: String = "document",
    val bytes: Long = 0,
    val files: Int = 1,
    val name: String? = null,
    @SerializedName("mime_type") val mimeType: String? = null,
    val thumbnail: String? = null,
    val forwarded: Boolean = false,
    @SerializedName("created_at") val createdAt: String? = null,
)

data class StorageFilesDto(val items: List<StorageFileDto> = emptyList(), @SerializedName("has_more") val hasMore: Boolean = false)

data class ChannelTopPostDto(val id: String? = null, val type: String = "text", val preview: String? = null, val views: Int = 0, @SerializedName("created_at") val createdAt: String? = null)

data class ChannelStatsDto(
    val days: Int = 30,
    val followers: Int = 0,
    val joined: Int = 0,
    val left: Int = 0,
    val posts: Int = 0,
    val views: Int = 0,
    @SerializedName("average_views") val averageViews: Int = 0,
    @SerializedName("reach_percent") val reachPercent: Int = 0,
    @SerializedName("top_posts") val topPosts: List<ChannelTopPostDto> = emptyList(),
)

data class BusinessStatsDto(val days: Int = 30, val sent: Int = 0, val delivered: Int = 0, val read: Int = 0, val received: Int = 0, val chats: Int = 0)

data class ChatLinkDto(val token: String = "", val payload: String = "", val link: String = "", val text: String? = null)
