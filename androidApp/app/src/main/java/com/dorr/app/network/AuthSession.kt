package com.dorr.app.network

/**
 * In-memory holder for the authenticated session (mobile OTP flow).
 * Kept process-wide so screens that need the Bearer token after login
 * (e.g. MRMFE_me/me or future authenticated endpoints) can read it.
 */
object AuthSession {
    var token: String? = null
    var user: UserDto? = null

    /**
     * Invoked whenever an authenticated request comes back 401 (expired or
     * revoked token). The interceptor clears the local session before calling
     * this, so callers only need to move the UI (e.g. back to Login).
     */
    var onUnauthorized: (() -> Unit)? = null

    val isAuthenticated: Boolean
        get() = !token.isNullOrBlank()

    fun clear() {
        token = null
        user = null
    }
}