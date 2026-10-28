package com.dorr.freelance.net

import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow

/**
 * The verification gate (FL-110): anything that needs a verified account answers 403 `freelance_verification_required` with
 * the step the freelancer is at. The HTTP layer puts that step here and the navigation shows "verify your account" once,
 * whatever screen made the request — a feature built later (offers, invitations) gets this for free.
 */
object Gate {
    private val _step = MutableStateFlow<String?>(null)

    /** The step to show a message for (`complete_profile`, `submit_verification`, `under_review`, `fix_documents`…), or null. */
    val step: StateFlow<String?> = _step.asStateFlow()

    fun require(step: String) {
        _step.value = step
    }

    fun dismiss() {
        _step.value = null
    }
}
