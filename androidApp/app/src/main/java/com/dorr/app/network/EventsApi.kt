package com.dorr.app.network

import com.google.gson.JsonElement
import com.google.gson.JsonObject
import com.google.gson.annotations.SerializedName
import retrofit2.http.Body
import retrofit2.http.DELETE
import retrofit2.http.GET
import retrofit2.http.Header
import retrofit2.http.Multipart
import retrofit2.http.Part
import retrofit2.http.PartMap
import retrofit2.http.POST
import retrofit2.http.PUT
import retrofit2.http.Path
import retrofit2.http.Query

/**
 * DORR Discover (spec 169–182): trusted public events by city, interest and date. Every event
 * carries its own local time (`local_date` / `local_time` in `timezone`) and mine (`my_time`) when
 * I'm somewhere else; `timezone` on each call is where I am now.
 */
interface EventsApi {
    @GET("mobile/v1/discover/home")
    suspend fun home(@Header("Authorization") auth: String, @Query("timezone") timezone: String): ApiEnvelope<DiscHomeDto>

    /** The next 15 of a home strip — the app scrolls the row; it does not open a new page. */
    @GET("mobile/v1/discover/sections/{key}")
    suspend fun section(
        @Header("Authorization") auth: String,
        @Path("key") key: String,
        @Query("timezone") timezone: String,
        @Query("page") page: Int = 1,
        @Query("per_page") perPage: Int = 15,
    ): ApiEnvelope<List<DiscEventDto>>

    @GET("mobile/v1/discover/events")
    suspend fun events(
        @Header("Authorization") auth: String,
        @Query("timezone") timezone: String,
        @Query("city_id") cityId: Int? = null,
        @Query("category_ids[]") categories: List<Int>? = null,
        @Query("region_id") regionId: Int? = null,
        @Query("country_id") countryId: Int? = null,
        @Query("from") from: String? = null,
        @Query("to") to: String? = null,
        @Query("free") free: Int? = null,
        @Query("family") family: Int? = null,
        @Query("q") q: String? = null,
        @Query("sort") sort: String? = null,
        @Query("page") page: Int = 1,
        @Query("per_page") perPage: Int = 15,
    ): ApiEnvelope<List<DiscEventDto>>

    /** What's on where I'm going, on my dates — the days cut in that city's own zone (AT-DISC-03). */
    @GET("mobile/v1/discover/travel")
    suspend fun travel(
        @Header("Authorization") auth: String,
        @Query("from") from: String,
        @Query("to") to: String,
        @Query("timezone") timezone: String,
        @Query("city_id") cityId: Int? = null,
        @Query("region_id") regionId: Int? = null,
        @Query("country_id") countryId: Int? = null,
        @Query("category_ids[]") categories: List<Int>? = null,
    ): ApiEnvelope<DiscTravelDto>

    /** `{text}` — DORR AI turns it into filters; the events are real ones. */
    @POST("mobile/v1/discover/ask")
    suspend fun ask(@Header("Authorization") auth: String, @Body body: JsonObject, @Query("timezone") timezone: String): ApiEnvelope<DiscAskDto>

    @GET("mobile/v1/discover/categories")
    suspend fun categories(
        @Header("Authorization") auth: String,
        @Query("page") page: Int = 1,
        @Query("per_page") perPage: Int = 15,
    ): ApiEnvelope<List<DiscCategoryDto>>

    @GET("mobile/v1/discover/cities")
    suspend fun cities(
        @Header("Authorization") auth: String,
        @Query("country_id") countryId: Int? = null,
        @Query("region_id") regionId: Int? = null,
        @Query("with_events") withEvents: Int? = null,
    ): ApiEnvelope<List<DiscCityDto>>

    /** A country's regions, a batch at a time (default: where I am). `with_events=1` (home) keeps those with something on. */
    @GET("mobile/v1/discover/regions")
    suspend fun regions(
        @Header("Authorization") auth: String,
        @Query("country_id") countryId: Int? = null,
        @Query("page") page: Int = 1,
        @Query("per_page") perPage: Int = 10,
        @Query("with_events") withEvents: Int? = null,
    ): ApiEnvelope<List<DiscRegionDto>>

    /** Where Discover is on — the place filter's first step. */
    @GET("mobile/v1/discover/countries")
    suspend fun countries(@Header("Authorization") auth: String): ApiEnvelope<List<DiscCountryDto>>

    @GET("mobile/v1/discover/events/{id}")
    suspend fun event(@Header("Authorization") auth: String, @Path("id") id: String, @Query("timezone") timezone: String): ApiEnvelope<DiscEventDto>

    /** `{notify}` — saved, in my calendar, told when it changes. */
    @POST("mobile/v1/discover/events/{id}/interest")
    suspend fun interest(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: JsonObject, @Query("timezone") timezone: String): ApiEnvelope<DiscEventDto>

    @DELETE("mobile/v1/discover/events/{id}/interest")
    suspend fun uninterest(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<JsonElement?>

    @GET("mobile/v1/discover/interests")
    suspend fun interests(
        @Header("Authorization") auth: String,
        @Query("timezone") timezone: String,
        @Query("past") past: Int = 0,
        @Query("page") page: Int = 1,
        @Query("per_page") perPage: Int = 15,
    ): ApiEnvelope<List<DiscEventDto>>

    @PUT("mobile/v1/discover/preferences")
    suspend fun savePreferences(@Header("Authorization") auth: String, @Body body: JsonObject): ApiEnvelope<DiscPrefsDto>

    /** `{kind: city | country, target_id}` */
    @POST("mobile/v1/discover/follows")
    suspend fun follow(@Header("Authorization") auth: String, @Body body: JsonObject): ApiEnvelope<List<DiscFollowDto>>

    @DELETE("mobile/v1/discover/follows/{id}")
    suspend fun unfollow(@Header("Authorization") auth: String, @Path("id") id: Int): ApiEnvelope<List<DiscFollowDto>>

    /** `{conversation_id, comment?, poll?}` → `{message_id, poll_id, conversation_id}` */
    @POST("mobile/v1/discover/events/{id}/share")
    suspend fun share(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: JsonObject): ApiEnvelope<JsonObject>

    /** `{members: [user ids]}` → `{conversation, not_added}` */
    @POST("mobile/v1/discover/events/{id}/room")
    suspend fun room(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: JsonObject): ApiEnvelope<JsonObject>

    @GET("mobile/v1/discover/organizer")
    suspend fun organizer(
        @Header("Authorization") auth: String,
        @Query("timezone") timezone: String,
        @Query("page") page: Int = 1,
        @Query("per_page") perPage: Int = 15,
        @Query("q") q: String? = null,
    ): ApiEnvelope<DiscOrganizerHomeDto>

    /** `{name, about?, website?, phone?, email?}` */
    @POST("mobile/v1/discover/organizer")
    suspend fun applyOrganizer(@Header("Authorization") auth: String, @Body body: JsonObject): ApiEnvelope<DiscOrganizerDto>

    /** The event's own local time in `starts_at` ("2026-11-05 20:00"), read in the city's zone. */
    @POST("mobile/v1/discover/organizer/events")
    suspend fun submit(@Header("Authorization") auth: String, @Body body: JsonObject, @Query("timezone") timezone: String): ApiEnvelope<DiscEventDto>

    /** The same with a cover photo (multipart: the fields and `cover`). */
    @Multipart
    @POST("mobile/v1/discover/organizer/events")
    suspend fun submitWithCover(
        @Header("Authorization") auth: String,
        @PartMap fields: Map<String, @JvmSuppressWildcards okhttp3.RequestBody>,
        @Part cover: okhttp3.MultipartBody.Part,
        @Query("timezone") timezone: String,
    ): ApiEnvelope<DiscEventDto>

    /** An organizer's own event: any field, and the cover (multipart). */
    @Multipart
    @POST("mobile/v1/discover/organizer/events/{id}")
    suspend fun updateEvent(
        @Header("Authorization") auth: String,
        @Path("id") id: String,
        @PartMap fields: Map<String, @JvmSuppressWildcards okhttp3.RequestBody>,
        @Part cover: okhttp3.MultipartBody.Part?,
        @Query("timezone") timezone: String,
    ): ApiEnvelope<DiscEventDto>

    /** `{status, note?, starts_at?}` */
    @POST("mobile/v1/discover/organizer/events/{id}/status")
    suspend fun organizerStatus(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: JsonObject, @Query("timezone") timezone: String): ApiEnvelope<DiscEventDto>
}

data class DiscCategoryDto(
    val id: Int = 0,
    val key: String = "",
    val name: String? = null,
    val emoji: String? = null,
    val color: String? = null,
)

data class DiscRegionDto(
    val id: Int = 0,
    val name: String? = null,
    @SerializedName("country_id") val countryId: Int? = null,
    @SerializedName("events_count") val eventsCount: Int = 0,
)

data class DiscRegionsMetaDto(@SerializedName("current_page") val currentPage: Int = 1, @SerializedName("has_more_pages") val hasMorePages: Boolean = false)

/** The home's first batch of regions; more come from GET regions. */
data class DiscRegionsDto(val items: List<DiscRegionDto> = emptyList(), val meta: DiscRegionsMetaDto = DiscRegionsMetaDto())

data class DiscCountryDto(val id: Int = 0, val name: String? = null, val code: String? = null)

data class DiscCurrencyDto(
    val code: String? = null,
    val symbol: String? = null,
    @SerializedName("decimal_places") val decimalPlaces: Int = 2,
)

data class DiscCityDto(
    val id: Int = 0,
    val name: String? = null,
    val country: String? = null,
    @SerializedName("country_id") val countryId: Int? = null,
    @SerializedName("region_id") val regionId: Int? = null,
    val timezone: String? = null,
    val currency: DiscCurrencyDto? = null,
)

data class DiscOrganizerRefDto(val name: String? = null, val verified: Boolean = false)

data class DiscHistoryDto(
    val from: String? = null,
    val to: String? = null,
    val note: String? = null,
    @SerializedName("old_starts_at") val oldStartsAt: String? = null,
    val at: String? = null,
)

/** One event. `status`: confirmed · postponed · cancelled · sold_out · ended. `verified` only for trusted sources. */
data class DiscEventDto(
    val id: String = "",
    val title: String = "",
    val category: DiscCategoryDto? = null,
    val city: DiscCityDto? = null,
    val country: String? = null,
    val venue: String? = null,
    val address: String? = null,
    val lat: Double? = null,
    val lng: Double? = null,
    @SerializedName("starts_at") val startsAt: String? = null,
    @SerializedName("ends_at") val endsAt: String? = null,
    val timezone: String? = null,
    @SerializedName("local_date") val localDate: String? = null,
    @SerializedName("local_time") val localTime: String? = null,
    @SerializedName("local_end") val localEnd: String? = null,
    @SerializedName("my_time") val myTime: String? = null,
    @SerializedName("is_free") val isFree: Boolean = false,
    @SerializedName("price_text") val priceText: String? = null,
    @SerializedName("price_display") val priceDisplay: String? = null,
    val currency: DiscCurrencyDto? = null,
    @SerializedName("family_friendly") val familyFriendly: Boolean = false,
    val status: String = "confirmed",
    val verified: Boolean = false,
    /** organizer | admin */
    val source: String? = null,
    val organizer: DiscOrganizerRefDto? = null,
    val cover: String? = null,
    @SerializedName("interested_count") val interestedCount: Int = 0,
    val interested: Boolean = false,
    val notify: Boolean = false,
    @SerializedName("distance_km") val distanceKm: Double? = null,
    val description: String? = null,
    @SerializedName("source_url") val sourceUrl: String? = null,
    @SerializedName("booking_url") val bookingUrl: String? = null,
    @SerializedName("last_verified_at") val lastVerifiedAt: String? = null,
    @SerializedName("status_note") val statusNote: String? = null,
    @SerializedName("old_starts_at") val oldStartsAt: String? = null,
    val history: List<DiscHistoryDto>? = null,
    val mine: Boolean = false,
    @SerializedName("review_status") val reviewStatus: String? = null,
    @SerializedName("review_note") val reviewNote: String? = null,
)

data class DiscSectionDto(
    val key: String = "",
    val items: List<DiscEventDto> = emptyList(),
    @SerializedName("has_more") val hasMore: Boolean = false,
)

data class DiscPrefsDto(
    val categories: List<Int> = emptyList(),
    val alerts: Boolean = true,
    @SerializedName("alert_days") val alertDays: Int = 14,
    @SerializedName("family_only") val familyOnly: Boolean = false,
)

data class DiscFollowDto(
    val id: Int = 0,
    val kind: String = "city",
    @SerializedName("target_id") val targetId: Int = 0,
    val name: String? = null,
    val country: String? = null,
)

data class DiscHomeDto(
    val country: String? = null,
    val categories: List<DiscCategoryDto> = emptyList(),
    val cities: List<DiscCityDto> = emptyList(),
    val regions: DiscRegionsDto = DiscRegionsDto(),
    val follows: List<DiscFollowDto> = emptyList(),
    val preferences: DiscPrefsDto? = null,
    @SerializedName("can_submit") val canSubmit: Boolean = false,
    val ai: Boolean = false,
    val sections: List<DiscSectionDto> = emptyList(),
)

/** Where the search ran: its time zone (the days are cut in it). */
data class DiscTravelPlaceDto(val timezone: String? = null)

data class DiscTravelDto(
    val city: DiscCityDto? = null,
    val place: DiscTravelPlaceDto? = null,
    val from: String? = null,
    val to: String? = null,
    val items: List<DiscEventDto> = emptyList(),
)

data class DiscAskFiltersDto(
    @SerializedName("city_id") val cityId: Int? = null,
    val city: String? = null,
    val categories: List<String> = emptyList(),
    val from: String? = null,
    val to: String? = null,
    val free: Boolean? = null,
    val family: Boolean? = null,
)

data class DiscAskDto(val filters: DiscAskFiltersDto? = null, val items: List<DiscEventDto> = emptyList())

/** `status`: pending · verified · rejected · suspended. */
data class DiscOrganizerDto(
    val id: Int = 0,
    val name: String = "",
    val about: String? = null,
    val website: String? = null,
    val phone: String? = null,
    val email: String? = null,
    val status: String = "pending",
    @SerializedName("review_note") val reviewNote: String? = null,
    val verified: Boolean = false,
)

data class DiscOrganizerHomeDto(
    val organizer: DiscOrganizerDto? = null,
    @SerializedName("can_submit") val canSubmit: Boolean = false,
    val events: List<DiscEventDto> = emptyList(),
)
