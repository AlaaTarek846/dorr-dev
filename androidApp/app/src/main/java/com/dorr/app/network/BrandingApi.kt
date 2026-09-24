package com.dorr.app.network

import retrofit2.http.GET

interface BrandingApi {
    /** GET /api/general/v1/platform-settings/branding — app name and site images, pre-login. */
    @GET("general/v1/platform-settings/branding")
    suspend fun get(): ApiEnvelope<BrandingDto>
}
