package com.dorr.freelance.net

import com.dorr.core.network.ApiEnvelope
import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.HTTP

/** The freelancer's own account (FL-003): deleting it. */
interface AccountApi {
    /** Deletes the account (and its works and files); the caller must confirm. */
    @HTTP(method = "DELETE", path = "${ApiClient.FREELANCE}account", hasBody = true)
    suspend fun delete(@Body body: ConfirmRequest = ConfirmRequest()): Response<ApiEnvelope<Any>>
}

data class ConfirmRequest(val confirm: Boolean = true)
