package com.dorr.app.network

import com.google.gson.annotations.SerializedName
import retrofit2.http.GET

data class FaqDto(
    val id: Int,
    val question: String,
    val answer: String,
    val status: Boolean = true,
    @SerializedName("sort_order") val sortOrder: Int? = null,
)

data class PrivacyPolicyDto(
    val id: Int? = null,
    val content: String? = null,
    val status: Boolean? = null,
    @SerializedName("sort_order") val sortOrder: Int? = null,
)

interface ContentApi {
    /** GET /api/mobile/v1/faqs — active general FAQs for the mobile app */
    @GET("mobile/v1/faqs")
    suspend fun getFaqs(): ApiEnvelope<List<FaqDto>>

    /** GET /api/mobile/v1/privacy-policy — active general privacy policy */
    @GET("mobile/v1/privacy-policy")
    suspend fun getPrivacyPolicy(): ApiEnvelope<PrivacyPolicyDto?>
}
