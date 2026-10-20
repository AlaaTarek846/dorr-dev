package com.dorr.driver.net

import com.dorr.core.network.ApiEnvelope
import com.google.gson.JsonObject
import com.google.gson.annotations.SerializedName
import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.POST
import retrofit2.http.Path

/** Driving lessons, the trainer's side (docs/rides_tasks.md O-26b). */
interface DriverLessonsApi {
    @GET("driver/v1/lessons/packs")
    suspend fun packs(): Response<ApiEnvelope<List<TrainerPackDto>>>

    /** `{title, description?, transmission, lessons, minutes_per_lesson, price_minor, own_car, area{lat,lng,address}}` */
    @POST("driver/v1/lessons/packs")
    suspend fun publish(@Body body: JsonObject): Response<ApiEnvelope<TrainerPackDto>>

    /** `{status: active | paused}` */
    @POST("driver/v1/lessons/packs/{uuid}/status")
    suspend fun status(@Path("uuid") uuid: String, @Body body: JsonObject): Response<ApiEnvelope<TrainerPackDto>>

    @GET("driver/v1/lessons/sessions")
    suspend fun sessions(): Response<ApiEnvelope<List<TrainerSessionDto>>>

    /** `{approve}` */
    @POST("driver/v1/lessons/sessions/{uuid}/answer")
    suspend fun answer(@Path("uuid") uuid: String, @Body body: JsonObject): Response<ApiEnvelope<TrainerSessionDto>>

    @POST("driver/v1/lessons/sessions/{uuid}/done")
    suspend fun done(@Path("uuid") uuid: String): Response<ApiEnvelope<TrainerSessionDto>>
}

data class TrainerPackDto(
    val uuid: String,
    val status: String = "active",
    val title: String,
    val transmission: String = "automatic",
    val lessons: Int = 1,
    @SerializedName("minutes_per_lesson") val minutesPerLesson: Int = 60,
    @SerializedName("price_minor") val priceMinor: Long = 0,
    @SerializedName("own_car") val ownCar: Boolean = true,
    val area: PlaceDto,
    val currency: CurrencyDto? = null,
    val learners: Int? = null,
)

data class TrainerLearnerDto(val name: String? = null, val phone: String? = null)

data class TrainerSessionPackDto(
    val title: String? = null,
    @SerializedName("minutes_per_lesson") val minutesPerLesson: Int? = null,
    val transmission: String? = null,
    val lessons: Int? = null,
)

data class TrainerSessionDto(
    val uuid: String,
    val number: Int,
    val status: String,
    @SerializedName("starts_at") val startsAt: String? = null,
    val pickup: PlaceDto? = null,
    val learner: TrainerLearnerDto? = null,
    val pack: TrainerSessionPackDto? = null,
)
