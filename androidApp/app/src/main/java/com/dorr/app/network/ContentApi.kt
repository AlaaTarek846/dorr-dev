package com.dorr.app.network

import com.google.gson.annotations.SerializedName
import retrofit2.http.GET
import retrofit2.http.Query

data class FaqDto(
    val id: Int,
    val question: String,
    val answer: String,
    val status: Boolean = true,
    @SerializedName("sort_order") val sortOrder: Int? = null,
)

data class LegalPageDto(
    val id: Int? = null,
    val type: String? = null,
    val content: String? = null,
    val status: Boolean? = null,
)

interface ContentApi {
    /** GET /api/mobile/v1/faqs — active general FAQs for the mobile app */
    @GET("mobile/v1/faqs")
    suspend fun getFaqs(): ApiEnvelope<List<FaqDto>>

    /** GET /api/mobile/v1/legal-pages?type= — active general legal page (privacy/term) */
    @GET("mobile/v1/legal-pages")
    suspend fun getLegalPage(@Query("type") type: String = "privacy"): ApiEnvelope<LegalPageDto?>
}
