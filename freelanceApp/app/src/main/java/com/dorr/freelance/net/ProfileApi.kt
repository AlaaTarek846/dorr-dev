package com.dorr.freelance.net

import com.dorr.core.network.ApiEnvelope
import com.google.gson.annotations.SerializedName
import okhttp3.MultipartBody
import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.Multipart
import retrofit2.http.POST
import retrofit2.http.PUT
import retrofit2.http.Part

/** The freelancer's own profile (FL-103), under `/api/freelance/v1`. Every answer is the updated freelancer. */
interface ProfileApi {
    /** The profile fields (`name` is always required); a blank text clears its field. */
    @PUT("${ApiClient.FREELANCE}profile")
    suspend fun update(@Body body: Map<String, @JvmSuppressWildcards Any>): Response<ApiEnvelope<FreelancerDto>>

    /** A code goes to that address. */
    @POST("${ApiClient.FREELANCE}profile/email/send-code")
    suspend fun sendEmailCode(@Body body: EmailRequest): Response<ApiEnvelope<Any>>

    @POST("${ApiClient.FREELANCE}profile/email/confirm")
    suspend fun confirmEmail(@Body body: CodeRequest): Response<ApiEnvelope<FreelancerDto>>

    @Multipart
    @POST("${ApiClient.FREELANCE}profile/avatar")
    suspend fun avatar(@Part avatar: MultipartBody.Part): Response<ApiEnvelope<FreelancerDto>>

    @POST("${ApiClient.FREELANCE}terms/accept")
    suspend fun acceptTerms(@Body body: AcceptTermsRequest): Response<ApiEnvelope<FreelancerDto>>
}

data class EmailRequest(val email: String)

data class CodeRequest(val code: String)

data class AcceptTermsRequest(@SerializedName("version") val version: String)
