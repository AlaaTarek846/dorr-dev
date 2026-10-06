package com.dorr.app.network

import com.google.gson.JsonElement
import com.google.gson.JsonObject
import com.google.gson.annotations.SerializedName
import okhttp3.MultipartBody
import okhttp3.RequestBody
import retrofit2.http.Body
import retrofit2.http.DELETE
import retrofit2.http.GET
import retrofit2.http.Header
import retrofit2.http.Multipart
import retrofit2.http.PATCH
import retrofit2.http.POST
import retrofit2.http.PUT
import retrofit2.http.Part
import retrofit2.http.PartMap
import retrofit2.http.Path
import retrofit2.http.Query

/** DORR Moments (spec 157–168): occasions, my preferences, my own dates. */
interface MomentsApi {
    @GET("mobile/v1/chat/moments")
    suspend fun center(@Header("Authorization") auth: String): ApiEnvelope<MomentsCenterDto>

    @GET("mobile/v1/chat/moments/catalog")
    suspend fun catalog(@Header("Authorization") auth: String, @Query("country_id") countryId: Int? = null): ApiEnvelope<List<MomentCatalogItemDto>>

    /** `{enabled?, country_id?, effects?, on?[], off?[]}` → the center again. */
    @PUT("mobile/v1/chat/moments/preferences")
    suspend fun preferences(@Header("Authorization") auth: String, @Body body: JsonObject): ApiEnvelope<MomentsCenterDto>

    @POST("mobile/v1/chat/moments/personal")
    suspend fun addPersonal(@Header("Authorization") auth: String, @Body body: JsonObject): ApiEnvelope<List<PersonalMomentDto>>

    @PATCH("mobile/v1/chat/moments/personal/{id}")
    suspend fun updatePersonal(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: JsonObject): ApiEnvelope<List<PersonalMomentDto>>

    @DELETE("mobile/v1/chat/moments/personal/{id}")
    suspend fun deletePersonal(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<List<PersonalMomentDto>>

    // ------------------------------------------------------------ greeting cards (spec 161–167)

    /**
     * A card in a chat (multipart): `moment_id | personal_kind, title?, text?, reveal_at?, send_at?
     * (Y-m-d H:i), schedule_zone? (recipient | mine), gift_amount_minor?` + `voice`, `photos[]`.
     * The wallet PIN goes in `X-Wallet-Pin` with a gift. Back: the message — or `{scheduled}`.
     */
    @Multipart
    @POST("mobile/v1/chat/conversations/{id}/moment-card")
    suspend fun sendCard(
        @Header("Authorization") auth: String,
        @Header("X-Wallet-Pin") pin: String?,
        @Path("id") conversationId: String,
        @PartMap fields: Map<String, @JvmSuppressWildcards RequestBody>,
        @Part files: List<MultipartBody.Part>,
    ): ApiEnvelope<JsonElement>

    /** `{moment_id? | kind?, relation, tone, name?}` → three greetings to start from. */
    @POST("mobile/v1/chat/moments/greetings")
    suspend fun greetings(@Header("Authorization") auth: String, @Body body: JsonObject): ApiEnvelope<GreetingsDto>

    // ------------------------------------------------------------ group cards (spec 163)

    @GET("mobile/v1/chat/collab-cards")
    suspend fun collabCards(@Header("Authorization") auth: String): ApiEnvelope<List<CollabCardDto>>

    /** `{recipient_id, moment_id? | personal_kind?, title?, deadline_at?, members[]}` */
    @POST("mobile/v1/chat/collab-cards")
    suspend fun createCollab(@Header("Authorization") auth: String, @Body body: JsonObject): ApiEnvelope<CollabCardDto>

    @GET("mobile/v1/chat/collab-cards/{id}")
    suspend fun collabCard(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<CollabCardDto>

    @POST("mobile/v1/chat/collab-cards/{id}/members")
    suspend fun inviteCollab(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: JsonObject): ApiEnvelope<CollabCardDto>

    @DELETE("mobile/v1/chat/collab-cards/{id}/members/{user}")
    suspend fun removeCollabMember(@Header("Authorization") auth: String, @Path("id") id: String, @Path("user") userId: Int): ApiEnvelope<CollabCardDto>

    /** My part (multipart): `text?, clear_files?` + `voice?`, `photo?`. */
    @Multipart
    @POST("mobile/v1/chat/collab-cards/{id}/contribution")
    suspend fun contribute(
        @Header("Authorization") auth: String,
        @Path("id") id: String,
        @PartMap fields: Map<String, @JvmSuppressWildcards RequestBody>,
        @Part files: List<MultipartBody.Part>,
    ): ApiEnvelope<CollabCardDto>

    @POST("mobile/v1/chat/collab-cards/{id}/send")
    suspend fun sendCollab(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<JsonElement>

    @DELETE("mobile/v1/chat/collab-cards/{id}")
    suspend fun cancelCollab(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<JsonElement?>

    // ------------------------------------------------------------ capsules (spec 166)

    @GET("mobile/v1/chat/capsules")
    suspend fun capsules(@Header("Authorization") auth: String): ApiEnvelope<List<CapsuleDto>>

    /** `{title, emoji?, moment_id?}` */
    @POST("mobile/v1/chat/capsules")
    suspend fun createCapsule(@Header("Authorization") auth: String, @Body body: JsonObject): ApiEnvelope<CapsuleDto>

    @GET("mobile/v1/chat/capsules/{id}")
    suspend fun capsule(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<CapsuleDto>

    @PATCH("mobile/v1/chat/capsules/{id}")
    suspend fun updateCapsule(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: JsonObject): ApiEnvelope<CapsuleDto>

    @DELETE("mobile/v1/chat/capsules/{id}")
    suspend fun deleteCapsule(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<JsonElement?>

    /** `{message_id, note?}` — keep a copy of that message here. */
    @POST("mobile/v1/chat/capsules/{id}/items")
    suspend fun addToCapsule(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: JsonObject): ApiEnvelope<CapsuleDto>

    @DELETE("mobile/v1/chat/capsules/{id}/items/{item}")
    suspend fun removeFromCapsule(@Header("Authorization") auth: String, @Path("id") id: String, @Path("item") itemId: Int): ApiEnvelope<CapsuleDto>
}

data class MomentDto(
    val id: Int,
    val key: String,
    val name: String?,
    val greeting: String? = null,
    val kind: String,
    val start: String,
    val end: String,
    @SerializedName("days_left") val daysLeft: Int,
    @SerializedName("is_today") val isToday: Boolean = false,
    @SerializedName("is_visible") val isVisible: Boolean = false,
    val theme: String? = null,
    @SerializedName("primary_color") val primaryColor: String? = null,
    @SerializedName("secondary_color") val secondaryColor: String? = null,
    val emoji: String? = null,
    val animation: String? = null,
    @SerializedName("card_image") val cardImage: String? = null,
)

data class MomentLookDto(
    @SerializedName("primary_color") val primaryColor: String? = null,
    @SerializedName("secondary_color") val secondaryColor: String? = null,
    val emoji: String? = null,
    val animation: String? = null,
)

data class PersonalMomentDto(
    val id: String,
    val kind: String,
    val title: String,
    val month: Int,
    val day: Int,
    val year: Int? = null,
    val next: String,
    @SerializedName("days_left") val daysLeft: Int,
    val turns: Int? = null,
    val contact: ProfileDto? = null,
    @SerializedName("remind_days_before") val remindDaysBefore: Int = 1,
    val look: MomentLookDto? = null,
)

data class MomentCountryDto(val id: Int, val code: String, val name: String? = null)

data class MomentsCenterDto(
    val enabled: Boolean = true,
    /** full · light · off */
    val effects: String = "full",
    val country: MomentCountryDto? = null,
    val active: List<MomentDto> = emptyList(),
    val upcoming: List<MomentDto> = emptyList(),
    val personal: List<PersonalMomentDto> = emptyList(),
)

data class MomentCatalogItemDto(
    val id: Int,
    val key: String,
    val name: String?,
    val kind: String,
    val emoji: String? = null,
    val next: String? = null,
    @SerializedName("default_on") val defaultOn: Boolean = true,
    val on: Boolean = false,
)

data class GreetingsDto(val greetings: List<String> = emptyList())

/** A card's look (snapshotted on the message's `meta.card` and on a group card). */
data class CardLookDto(
    @SerializedName("moment_id") val momentId: Int? = null,
    val kind: String? = null,
    val theme: String? = null,
    val title: String? = null,
    @SerializedName("primary_color") val primaryColor: String? = null,
    @SerializedName("secondary_color") val secondaryColor: String? = null,
    val emoji: String? = null,
    val animation: String? = null,
    @SerializedName("card_image") val cardImage: String? = null,
)

data class CollabFileDto(val url: String, @SerializedName("mime_type") val mimeType: String? = null)

data class CollabMemberDto(
    val profile: ProfileDto? = null,
    val signed: Boolean = false,
    val text: String? = null,
    val files: List<CollabFileDto> = emptyList(),
)

data class CollabMineDto(val text: String? = null, val signed: Boolean = false)

data class CollabCardDto(
    val id: String,
    val title: String,
    val look: CardLookDto? = null,
    /** collecting · sent · cancelled */
    val status: String = "collecting",
    @SerializedName("deadline_at") val deadlineAt: String? = null,
    val organiser: ProfileDto? = null,
    val recipient: ProfileDto? = null,
    @SerializedName("is_organiser") val isOrganiser: Boolean = false,
    val members: List<CollabMemberDto> = emptyList(),
    val mine: CollabMineDto? = null,
)

data class CapsuleItemDto(
    val id: Int,
    val type: String,
    val text: String? = null,
    @SerializedName("sender_name") val senderName: String? = null,
    val note: String? = null,
    @SerializedName("original_at") val originalAt: String? = null,
    val files: List<CollabFileDto> = emptyList(),
)

data class CapsuleDto(
    val id: String,
    val title: String,
    val emoji: String? = null,
    @SerializedName("moment_id") val momentId: Int? = null,
    @SerializedName("items_count") val itemsCount: Int = 0,
    @SerializedName("updated_at") val updatedAt: String? = null,
    val items: List<CapsuleItemDto>? = null,
)
