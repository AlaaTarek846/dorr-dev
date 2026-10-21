package com.dorr.app.network

import com.google.gson.JsonObject
import com.google.gson.annotations.SerializedName
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.Header
import retrofit2.http.POST
import retrofit2.http.Path
import retrofit2.http.Query

/** Driving lessons (docs/rides_tasks.md O-26b): packs near me, buy, book lessons, cancel, stop. */
interface LessonsApi {
    @GET("mobile/v1/rides/lessons/packs")
    suspend fun packs(
        @Header("Authorization") auth: String,
        @Query("lat") lat: Double,
        @Query("lng") lng: Double,
        @Query("transmission") transmission: String? = null,
    ): ApiEnvelope<List<LessonPackDto>>

    @POST("mobile/v1/rides/lessons/packs/{uuid}/enroll")
    suspend fun enroll(@Header("Authorization") auth: String, @Header("Idempotency-Key") key: String, @Path("uuid") uuid: String): ApiEnvelope<LessonEnrollmentDto>

    @GET("mobile/v1/rides/lessons/enrollments")
    suspend fun mine(@Header("Authorization") auth: String): ApiEnvelope<List<LessonEnrollmentDto>>

    @GET("mobile/v1/rides/lessons/enrollments/{uuid}")
    suspend fun enrollment(@Header("Authorization") auth: String, @Path("uuid") uuid: String): ApiEnvelope<LessonEnrollmentDto>

    /** `{starts_at, pickup{lat,lng,address}}` */
    @POST("mobile/v1/rides/lessons/enrollments/{uuid}/sessions")
    suspend fun book(@Header("Authorization") auth: String, @Path("uuid") uuid: String, @Body body: JsonObject): ApiEnvelope<LessonEnrollmentDto>

    @POST("mobile/v1/rides/lessons/enrollments/{uuid}/quit")
    suspend fun quit(@Header("Authorization") auth: String, @Path("uuid") uuid: String): ApiEnvelope<LessonEnrollmentDto>

    @POST("mobile/v1/rides/lessons/sessions/{uuid}/cancel")
    suspend fun cancelSession(@Header("Authorization") auth: String, @Path("uuid") uuid: String): ApiEnvelope<LessonEnrollmentDto>
}

data class LessonTrainerDto(val name: String? = null, val avatar: String? = null, val gender: String? = null)

data class LessonPackDto(
    val uuid: String,
    val status: String = "active",
    val title: String,
    val description: String? = null,
    val transmission: String = "automatic",
    val lessons: Int = 1,
    @SerializedName("minutes_per_lesson") val minutesPerLesson: Int = 60,
    @SerializedName("price_minor") val priceMinor: Long = 0,
    @SerializedName("per_lesson_minor") val perLessonMinor: Long = 0,
    @SerializedName("own_car") val ownCar: Boolean = true,
    val area: BusPlaceDto,
    val currency: RideCurrencyDto? = null,
    val trainer: LessonTrainerDto? = null,
)

data class LessonSessionDto(
    val uuid: String,
    val number: Int,
    /** requested / confirmed / done / declined / canceled */
    val status: String,
    @SerializedName("starts_at") val startsAt: String? = null,
    val pickup: BusPlaceDto? = null,
)

data class LessonEnrollmentDto(
    val uuid: String,
    val status: String,
    /** O-67: «unpaid» — held for the payment screen until then. */
    @SerializedName("payment_expires_at") val paymentExpiresAt: String? = null,
    @SerializedName("discount_minor") val discountMinor: Long = 0,
    @SerializedName("lessons_total") val lessonsTotal: Int = 0,
    @SerializedName("lessons_done") val lessonsDone: Int = 0,
    @SerializedName("lessons_left") val lessonsLeft: Int = 0,
    @SerializedName("amount_minor") val amountMinor: Long = 0,
    @SerializedName("refunded_minor") val refundedMinor: Long = 0,
    val pack: LessonPackDto,
    @SerializedName("trainer_phone") val trainerPhone: String? = null,
    val sessions: List<LessonSessionDto> = emptyList(),
)
