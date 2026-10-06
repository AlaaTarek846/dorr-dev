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
import retrofit2.http.PUT
import retrofit2.http.Path
import retrofit2.http.Query

/** DORR Calendar & DORR Today (spec 201–207). Times come as UTC instants; days are cut in [timezone]. */
interface CalendarApi {
    @GET("mobile/v1/chat/calendar")
    suspend fun feed(
        @Header("Authorization") auth: String,
        @Query("from") from: String,
        @Query("to") to: String,
        @Query("timezone") timezone: String,
    ): ApiEnvelope<CalFeedDto>

    @GET("mobile/v1/chat/calendar/today")
    suspend fun today(@Header("Authorization") auth: String, @Query("timezone") timezone: String): ApiEnvelope<CalTodayDto>

    @GET("mobile/v1/chat/calendar/search")
    suspend fun search(@Header("Authorization") auth: String, @Query("q") q: String, @Query("timezone") timezone: String): ApiEnvelope<CalSearchDto>

    /** `{title, starts_at | (all_day + date), ends_at?, timezone, location?, note?, color?, reminders?[], message_id?}` */
    @POST("mobile/v1/chat/calendar/items")
    suspend fun create(@Header("Authorization") auth: String, @Body body: JsonObject): ApiEnvelope<CalItemDto>

    @PATCH("mobile/v1/chat/calendar/items/{id}")
    suspend fun update(@Header("Authorization") auth: String, @Path("id") id: String, @Body body: JsonObject): ApiEnvelope<CalItemDto>

    @DELETE("mobile/v1/chat/calendar/items/{id}")
    suspend fun delete(@Header("Authorization") auth: String, @Path("id") id: String): ApiEnvelope<JsonElement?>

    @GET("mobile/v1/chat/calendar/preferences")
    suspend fun preferences(@Header("Authorization") auth: String): ApiEnvelope<CalPrefsDto>

    @PUT("mobile/v1/chat/calendar/preferences")
    suspend fun savePreferences(@Header("Authorization") auth: String, @Body body: JsonObject): ApiEnvelope<CalPrefsDto>
}

/**
 * One thing on the calendar: `type` = event (my appointment) · task · reminder (on a message) ·
 * moment (an occasion) · personal (one of my own dates) · capsule (search only). `date` is the day
 * it falls on where I am; `origin_time` is its time where it was set, when that's elsewhere.
 */
data class CalItemDto(
    val id: String,
    val type: String,
    val ref: String,
    val title: String,
    @SerializedName("starts_at") val startsAt: String? = null,
    @SerializedName("ends_at") val endsAt: String? = null,
    @SerializedName("all_day") val allDay: Boolean = false,
    val date: String? = null,
    @SerializedName("end_date") val endDate: String? = null,
    val timezone: String? = null,
    @SerializedName("origin_time") val originTime: String? = null,
    val location: String? = null,
    val note: String? = null,
    val color: String? = null,
    @SerializedName("secondary_color") val secondaryColor: String? = null,
    val emoji: String? = null,
    val source: String? = null,
    val reminders: List<Int>? = null,
    val done: Boolean = false,
    val overdue: Boolean = false,
    @SerializedName("message_id") val messageId: String? = null,
    @SerializedName("conversation_id") val conversationId: String? = null,
    val kind: String? = null,
    val turns: Int? = null,
    val count: Int? = null,
    val duplicate: Boolean = false,
)

data class CalFeedDto(val enabled: Boolean = true, val items: List<CalItemDto> = emptyList())

data class CalSectionsDto(val order: List<String> = emptyList(), val hidden: List<String> = emptyList())

data class CalMissDto(
    val kind: String,
    val count: Int? = null,
    val title: String? = null,
    val date: String? = null,
    @SerializedName("starts_at") val startsAt: String? = null,
    val emoji: String? = null,
)

data class CalBasedOnDto(
    val country: String? = null,
    @SerializedName("picked_occasions") val pickedOccasions: Int = 0,
    val sources: List<String> = emptyList(),
)

data class CalAroundDto(
    @SerializedName("week_count") val weekCount: Int = 0,
    val coming: List<CalItemDto> = emptyList(),
    @SerializedName("might_miss") val mightMiss: List<CalMissDto> = emptyList(),
    @SerializedName("based_on") val basedOn: CalBasedOnDto? = null,
)

data class CalTodayDto(
    val enabled: Boolean = true,
    val date: String? = null,
    val timezone: String? = null,
    val sections: CalSectionsDto? = null,
    val next: CalItemDto? = null,
    val events: List<CalItemDto> = emptyList(),
    val tasks: List<CalItemDto> = emptyList(),
    val reminders: List<CalItemDto> = emptyList(),
    val moments: List<CalItemDto> = emptyList(),
    val around: CalAroundDto? = null,
)

data class CalPrefsDto(
    val sources: Map<String, Boolean> = emptyMap(),
    @SerializedName("default_reminders") val defaultReminders: List<Int> = emptyList(),
    @SerializedName("all_day_reminders") val allDayReminders: List<Int> = emptyList(),
    @SerializedName("reminder_choices") val reminderChoices: List<Int> = emptyList(),
    @SerializedName("respect_quiet") val respectQuiet: Boolean = true,
    val personalised: Boolean = true,
    @SerializedName("today_sections") val todaySections: CalSectionsDto? = null,
)

data class CalSearchDto(val items: List<CalItemDto> = emptyList())
