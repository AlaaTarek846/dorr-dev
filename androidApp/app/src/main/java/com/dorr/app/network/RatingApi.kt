package com.dorr.app.network

import com.google.gson.annotations.SerializedName
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.Header
import retrofit2.http.POST

/**
 * App rating under /api/mobile/v1/ratings. One row per user: GET tells whether it exists, POST
 * creates it or updates the same stars and comment.
 */
interface RatingApi {
    @GET("mobile/v1/ratings/mine")
    suspend fun mine(
        @Header("Authorization") authorization: String,
    ): ApiEnvelope<MyRatingDto>

    @POST("mobile/v1/ratings")
    suspend fun submit(
        @Header("Authorization") authorization: String,
        @Body body: SubmitRatingRequest,
    ): ApiEnvelope<RatingDto>
}

data class SubmitRatingRequest(
    val stars: Float,
    val comment: String? = null,
)

data class RatingDto(
    val id: Int,
    val stars: Double,
    val comment: String? = null,
    val type: String? = null,
    /** True for 4–5 stars: the app may show the Play in-app review. */
    @SerializedName("prompt_store_review") val promptStoreReview: Boolean = false,
)

data class MyRatingDto(
    val rated: Boolean = false,
    val rating: RatingDto? = null,
)
