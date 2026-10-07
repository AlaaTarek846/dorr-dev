package com.dorr.app.network

import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext

object ReferralTracker {
    suspend fun submitPending() = withContext(Dispatchers.IO) {
        val token = AuthSession.token ?: return@withContext
        val code = ReferralStore.pending() ?: return@withContext
        runCatching {
            ApiClient.referrals.track("Bearer $token", TrackReferralRequest(code))
        }.onSuccess {
            ReferralStore.clearPending()
        }
    }
}
