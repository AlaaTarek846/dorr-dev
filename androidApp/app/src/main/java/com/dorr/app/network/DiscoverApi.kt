package com.dorr.app.network

import com.google.gson.JsonElement
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
import retrofit2.http.Part
import retrofit2.http.PartMap
import retrofit2.http.Path
import retrofit2.http.Query

/**
 * Merchant portals, the categories channels and portals pick from, the channels directory and a
 * channel's verification (docs/remaining_chat.md ج.2 / ج.3). Paying goes through WalletApi's checkout.
 */
interface DiscoverApi {
    @GET("mobile/v1/chat/categories")
    suspend fun categories(@Header("Authorization") auth: String): ApiEnvelope<List<CategoryDto>>

    // ------------------------------------------------------------------ portals

    @GET("mobile/v1/chat/portals")
    suspend fun portals(
        @Header("Authorization") auth: String,
        @Query("search") search: String? = null,
        @Query("category_id") categoryId: Int? = null,
    ): ApiEnvelope<List<PortalGroupDto>>

    @GET("mobile/v1/chat/portals/mine")
    suspend fun myPortals(@Header("Authorization") auth: String): ApiEnvelope<List<PortalDto>>

    @GET("mobile/v1/chat/portals/packages")
    suspend fun portalPackages(@Header("Authorization") auth: String): ApiEnvelope<List<PackageDto>>

    @GET("mobile/v1/chat/portals/languages")
    suspend fun portalLanguages(@Header("Authorization") auth: String): ApiEnvelope<List<PortalLanguageDto>>

    @Multipart
    @POST("mobile/v1/chat/portals")
    suspend fun createPortal(
        @Header("Authorization") auth: String,
        @PartMap fields: Map<String, @JvmSuppressWildcards RequestBody>,
        @Part logo: MultipartBody.Part?,
    ): ApiEnvelope<PortalDto>

    @Multipart
    @POST("mobile/v1/chat/portals/{id}")
    suspend fun updatePortal(
        @Header("Authorization") auth: String,
        @Path("id") id: String,
        @PartMap fields: Map<String, @JvmSuppressWildcards RequestBody>,
        @Part logo: MultipartBody.Part?,
    ): ApiEnvelope<PortalDto>

    @DELETE("mobile/v1/chat/portals/{id}")
    suspend fun deletePortal(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<JsonElement?>

    /** Counts a view (once a day per person) and answers the website to open. */
    @POST("mobile/v1/chat/portals/{id}/open")
    suspend fun openPortal(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<PortalDto>

    /** `{from, name, description}` → `{"ar": {"name": …, "description": …}, …}` for the other languages. */
    @POST("mobile/v1/chat/portals/translate")
    suspend fun translatePortal(@Header("Authorization") auth: String, @Body body: Map<String, String?>): ApiEnvelope<Map<String, Map<String, String>>>

    // ------------------------------------------------------------------ channels

    @GET("mobile/v1/chat/channels/directory")
    suspend fun channelDirectory(@Header("Authorization") auth: String, @Query("search") search: String? = null): ApiEnvelope<List<ChannelGroupDto>>

    @GET("mobile/v1/chat/channels/discover")
    suspend fun channelsIn(
        @Header("Authorization") auth: String,
        @Query("category_id") categoryId: Int,
        @Query("search") search: String? = null,
        @Query("per_page") perPage: Int = 50,
    ): ApiEnvelope<List<ChannelCardDto>>

    @PATCH("mobile/v1/chat/channels/{id}/category")
    suspend fun channelCategory(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: Map<String, Int?>): ApiEnvelope<ConversationDto>

    @GET("mobile/v1/chat/channels/{id}/verification")
    suspend fun channelVerification(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<VerificationDto>
}

data class CategoryDto(val id: Int, val name: String?, val icon: String?)

data class PortalDto(
    val id: String,
    val name: String?,
    val description: String?,
    val logo: String?,
    @SerializedName("website_url") val websiteUrl: String,
    val category: CategoryDto?,
    @SerializedName("views_count") val viewsCount: Long = 0,
    // Mine only:
    val status: Boolean? = null,
    @SerializedName("is_listed") val isListed: Boolean? = null,
    @SerializedName("listed_until") val listedUntil: String? = null,
    val translations: List<PortalTranslationDto>? = null,
)

data class PortalTranslationDto(val locale: String, val name: String?, val description: String?)

data class PortalGroupDto(val category: CategoryDto?, val portals: List<PortalDto>)

data class PortalLanguageDto(val code: String, val name: String, val direction: String?)

data class PackageDto(
    val id: Int,
    val kind: String,
    val name: String?,
    val description: String?,
    /** week · month · year */
    val period: String,
    @SerializedName("period_count") val periodCount: Int,
    @SerializedName("amount_minor") val amountMinor: Long?,
    @SerializedName("currency_code") val currencyCode: String?,
)

data class ChannelGroupDto(val category: CategoryDto?, val channels: List<ChannelCardDto>)

data class VerificationDto(
    @SerializedName("is_verified") val isVerified: Boolean,
    @SerializedName("verified_until") val verifiedUntil: String?,
    @SerializedName("verified_by_admin") val verifiedByAdmin: Boolean,
    val packages: List<PackageDto>,
)
