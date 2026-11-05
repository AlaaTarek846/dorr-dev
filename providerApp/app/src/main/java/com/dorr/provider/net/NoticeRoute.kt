package com.dorr.provider.net

import com.dorr.provider.navigation.Routes

/**
 * Where a notice leads: the screen of what it is about. The same answer serves a card in the notifications list and a push tapped in
 * the phone's tray. `null` means the notice has no screen of its own (it then opens the list). The moving screens (C-04, C-05) add
 * their own destinations here when they arrive.
 */
object NoticeRoute {
    /** @param event the notice's event (`wallet.topup.completed`, `provider.establishment.reviewed`…); @param field a value of the notice's data */
    fun resolve(event: String?, field: (String) -> String?): String? {
        val e = event ?: return null
        // A moving notice carries the request uuid (`request`).
        val request = field("request")
        return when {
            e.startsWith("wallet.") -> Routes.WALLET
            e.startsWith("provider.invitation") -> Routes.INVITATIONS
            e.startsWith("provider.document") -> Routes.DOCUMENTS
            e == "moving.published" -> request?.let(Routes::request) ?: Routes.REQUESTS
            // To a driver: a job handed to them.
            e == "moving.assigned" -> Routes.MY_JOBS
            e == "moving.ticket_opened" || e == "moving.reported" -> Routes.TICKETS
            e.startsWith("moving.") -> request?.let { Routes.job(it) } ?: Routes.HOME
            e.startsWith("provider.") -> Routes.HOME
            else -> null
        }
    }
}
