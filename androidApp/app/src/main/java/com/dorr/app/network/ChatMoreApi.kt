package com.dorr.app.network

import com.google.gson.JsonElement
import com.google.gson.JsonObject
import com.google.gson.annotations.SerializedName
import retrofit2.http.Body
import retrofit2.http.DELETE
import retrofit2.http.GET
import retrofit2.http.HTTP
import retrofit2.http.Header
import retrofit2.http.PATCH
import retrofit2.http.POST
import retrofit2.http.PUT
import retrofit2.http.Path
import retrofit2.http.Query

/**
 * The chat items that were partial, closed (docs/remaining_chat.md): favourites folders (24), a PIN
 * for one chat (25), @usernames (81), "what I missed" (123), money in a chat (66), the decision
 * room (153) and the privacy center (127).
 */
interface ChatMoreApi {
    @GET("mobile/v1/chat/star-folders")
    suspend fun starFolders(@Header("Authorization") auth: String): ApiEnvelope<StarFoldersDto>

    @POST("mobile/v1/chat/star-folders")
    suspend fun createStarFolder(@Header("Authorization") auth: String, @Body body: JsonObject): ApiEnvelope<StarFolderDto>

    @PATCH("mobile/v1/chat/star-folders/{id}")
    suspend fun updateStarFolder(@Header("Authorization") auth: String, @Path("id") id: Int, @Body body: JsonObject): ApiEnvelope<StarFolderDto>

    @DELETE("mobile/v1/chat/star-folders/{id}")
    suspend fun deleteStarFolder(@Header("Authorization") auth: String, @Path("id") id: Int): ApiEnvelope<JsonElement?>

    /** `{starred, folder_id?}` */
    @PUT("mobile/v1/chat/messages/{id}/star")
    suspend fun star(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: JsonObject): ApiEnvelope<JsonElement?>

    /** `folder` = a folder id, or "none" (starred in no folder). */
    @GET("mobile/v1/chat/messages/starred")
    suspend fun starred(@Header("Authorization") auth: String, @Query("folder") folder: String? = null): ApiEnvelope<List<MessageDto>>

    /** `{pin, current_pin?}` — 4 digits. */
    @PUT("mobile/v1/chat/conversations/{id}/lock-pin")
    suspend fun setLockPin(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: JsonObject): ApiEnvelope<JsonElement?>

    @HTTP(method = "DELETE", path = "mobile/v1/chat/conversations/{id}/lock-pin", hasBody = true)
    suspend fun removeLockPin(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: JsonObject): ApiEnvelope<JsonElement?>

    @POST("mobile/v1/chat/conversations/{id}/unlock")
    suspend fun unlock(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: JsonObject): ApiEnvelope<JsonElement?>

    @GET("mobile/v1/chat/username")
    suspend fun username(@Header("Authorization") auth: String): ApiEnvelope<UsernameDto>

    /** `{username}` (null removes it). */
    @PUT("mobile/v1/chat/username")
    suspend fun setUsername(@Header("Authorization") auth: String, @Body body: JsonObject): ApiEnvelope<UsernameDto>

    @GET("mobile/v1/chat/users/by-username")
    suspend fun byUsername(@Header("Authorization") auth: String, @Query("u") username: String): ApiEnvelope<ProfileDto>

    @GET("mobile/v1/chat/catch-up")
    suspend fun catchUp(@Header("Authorization") auth: String, @Query("since") since: String? = null): ApiEnvelope<CatchUpDto>

    @GET("mobile/v1/chat/conversations/{id}/money")
    suspend fun money(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<ChatMoneyDto>

    @GET("mobile/v1/chat/privacy/center")
    suspend fun privacyCenter(@Header("Authorization") auth: String): ApiEnvelope<PrivacyCenterDto>

    @GET("mobile/v1/chat/decisions/{id}")
    suspend fun decisionRoom(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<DecisionRoomDto>

    /** `{stance: pro|con|note, text, option_id?}` */
    @POST("mobile/v1/chat/decisions/{id}/arguments")
    suspend fun argue(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: JsonObject): ApiEnvelope<DecisionRoomDto>

    @DELETE("mobile/v1/chat/decisions/{id}/arguments/{argument}")
    suspend fun removeArgument(@Header("Authorization") auth: String, @Path("id") id: String, @Path("argument") argument: Int): ApiEnvelope<DecisionRoomDto>
}

data class StarFolderDto(val id: Int, val name: String, val emoji: String? = null, val color: String? = null, val count: Int = 0)

data class StarFoldersDto(@SerializedName("unsorted_count") val unsortedCount: Int = 0, val folders: List<StarFolderDto> = emptyList())

data class UsernameDto(val username: String? = null)

/** A message an overview points at, with where it lives. */
data class MsgRefDto(
    @SerializedName("message_id") val messageId: String,
    @SerializedName("conversation_id") val conversationId: String? = null,
    val chat: String? = null,
    val sender: String? = null,
    val excerpt: String? = null,
    val note: String? = null,
    @SerializedName("created_at") val createdAt: String? = null,
)

data class MissedCallDto(
    val id: String,
    val type: String,
    @SerializedName("conversation_id") val conversationId: String? = null,
    val chat: String? = null,
    val caller: String? = null,
    @SerializedName("created_at") val createdAt: String? = null,
)

data class OpenDecisionDto(
    val id: String,
    val title: String,
    @SerializedName("conversation_id") val conversationId: String? = null,
    val chat: String? = null,
    @SerializedName("deadline_at") val deadlineAt: String? = null,
)

data class UnreadChatDto(
    @SerializedName("conversation_id") val conversationId: String? = null,
    val chat: String? = null,
    val unread: Int = 0,
    val mention: Boolean = false,
)

data class CatchUpDto(
    val since: String? = null,
    val mentions: List<MsgRefDto> = emptyList(),
    val replies: List<MsgRefDto> = emptyList(),
    val urgent: List<MsgRefDto> = emptyList(),
    val owed: List<MsgRefDto> = emptyList(),
    @SerializedName("missed_calls") val missedCalls: List<MissedCallDto> = emptyList(),
    val reminders: List<MsgRefDto> = emptyList(),
    val decisions: List<OpenDecisionDto> = emptyList(),
    @SerializedName("unread_chats") val unreadChats: List<UnreadChatDto> = emptyList(),
)

data class MoneyItemDto(
    @SerializedName("message_id") val messageId: String,
    val type: String,
    @SerializedName("amount_minor") val amountMinor: Long = 0,
    val currency: String? = null,
    val status: String? = null,
    @SerializedName("is_mine") val isMine: Boolean = false,
    val note: String? = null,
    @SerializedName("created_at") val createdAt: String? = null,
)

data class MoneyTotalsDto(
    @SerializedName("sent_minor") val sentMinor: Long = 0,
    @SerializedName("received_minor") val receivedMinor: Long = 0,
    @SerializedName("i_owe_minor") val iOweMinor: Long = 0,
    @SerializedName("owed_to_me_minor") val owedToMeMinor: Long = 0,
    val currency: String? = null,
)

data class ChatMoneyDto(val items: List<MoneyItemDto> = emptyList(), val totals: MoneyTotalsDto = MoneyTotalsDto())

data class PrivacyCenterDto(
    @SerializedName("who_sees") val whoSees: Map<String, Any> = emptyMap(),
    @SerializedName("who_can") val whoCan: Map<String, String> = emptyMap(),
    val notifications: String = "all",
    @SerializedName("privacy_mode") val privacyMode: Map<String, Boolean> = emptyMap(),
    val quiet: Map<String, Boolean> = emptyMap(),
    @SerializedName("block_screenshots") val blockScreenshots: Boolean = false,
    val circles: Map<String, Int> = emptyMap(),
    @SerializedName("locked_chats") val lockedChats: Int = 0,
    @SerializedName("pin_locked_chats") val pinLockedChats: Int = 0,
    val blocked: Int = 0,
    val username: String? = null,
    val ai: Map<String, Any> = emptyMap(),
)

data class DecisionArgumentDto(
    val id: Int,
    val stance: String,
    @SerializedName("option_id") val optionId: String? = null,
    val text: String,
    val by: ProfileDto? = null,
    @SerializedName("is_mine") val isMine: Boolean = false,
    @SerializedName("created_at") val createdAt: String? = null,
)

data class DecisionRoomDto(
    val id: String,
    val title: String,
    val status: String,
    val outcome: String? = null,
    val description: String? = null,
    @SerializedName("deadline_at") val deadlineAt: String? = null,
    @SerializedName("is_closed") val isClosed: Boolean = false,
    @SerializedName("can_decide") val canDecide: Boolean = false,
    @SerializedName("poll_message_id") val pollMessageId: String? = null,
    val options: List<DecisionOptionDto> = emptyList(),
    val voters: Int = 0,
    @SerializedName("created_by") val createdBy: ProfileDto? = null,
    val arguments: List<DecisionArgumentDto> = emptyList(),
)
