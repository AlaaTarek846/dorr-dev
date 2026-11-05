package com.dorr.freelance.net

import com.dorr.freelance.navigation.Routes

/**
 * Where a notice leads: the screen of what it is about. The same answer serves a card in the notifications list and a push tapped in
 * the phone's tray. `null` means the notice has no screen of its own (it then opens its details, or the list).
 */
object NoticeRoute {
    /** @param event the notice's event (`freelance.offer.viewed`…); @param field a value of the notice's data (`project`, `dispute`…) */
    fun resolve(event: String?, field: (String) -> String?): String? {
        // FL-L12: a community notice (`freelance_community_*`) opens the post it is about, or the member who followed me.
        if (event?.startsWith("freelance.community") == true || field("type")?.startsWith("freelance_community_") == true) {
            return field("community_post")?.let(Routes::communityPost) ?: field("community_member")?.let(Routes::communityMember) ?: Routes.COMMUNITY
        }
        val e = event ?: return null
        val project = field("project")
        return when {
            e == "freelance.message" || e.startsWith("freelance.interview") -> field("conversation_uuid")?.let(Routes::chat)
            e.startsWith("wallet.") || e == "freelance.payment.available" -> Routes.WALLET
            e.startsWith("freelance.verification") -> Routes.VERIFICATION
            e.startsWith("freelance.skill") -> Routes.SKILLS
            e == "freelance.portfolio.hidden" -> Routes.PORTFOLIO
            e == "freelance.quota.low" -> Routes.MY_OFFERS
            e.startsWith("freelance.subscription") -> Routes.PLANS
            // FL-L13: a client's leave to show a finished work (or taking it back) opens that agreement.
            e.startsWith("freelance.rights") -> project?.let(Routes::agreement)
            e == "freelance.invitation.received" -> Routes.INVITATIONS
            // FL-L09: an answer to my special offer opens it.
            e.startsWith("freelance.special_offer") -> field("special_offer")?.let(Routes::specialOffer) ?: Routes.SPECIAL_OFFERS
            // FL-L04: a new order opens it; a decision on a service, my services.
            e == "freelance.order.received" -> field("order")?.let(Routes::serviceOrder) ?: Routes.SERVICE_ORDERS
            // FL-L07: a consultation notice opens the booking.
            // A listing the staff decided on opens its editor; a booking opens the booking.
            e.startsWith("freelance.consultation.listing") -> field("consultation_offer")?.let { Routes.consultationEdit(it) } ?: Routes.CONSULTATIONS
            // FL-L16: the session's notices (session_joined · session_ending · session_closed · session_reopened · recording_ready) open the
            // booking too — its session card has "join", the time left and the recordings.
            e.startsWith("freelance.consultation") -> field("consultation")?.let(Routes::consultation) ?: Routes.CONSULTATIONS
            e.startsWith("freelance.service.") -> field("service")?.let { Routes.service(it) } ?: Routes.SERVICES
            // A client's monthly service: a renewal opens its order (or my orders); someone waiting for a place opens the service.
            e.startsWith("freelance.retainer") -> field("order")?.let(Routes::serviceOrder) ?: Routes.SERVICE_ORDERS
            e.startsWith("freelance.waitlist") -> field("service")?.let { Routes.service(it) } ?: Routes.SERVICES
            // A sanction (and its appeal) is shown on Home, in the card above the numbers.
            e.startsWith("freelance.sanction") -> Routes.HOME
            e.startsWith("freelance.dispute") -> {
                val dispute = field("dispute")
                if (project != null && dispute != null) "dispute/$project/$dispute" else project?.let(Routes::agreement)
            }
            // The work itself: the agreement, its parts, their payments and changes.
            // FS-106: a health notice (health.at_risk · delayed · blocked) opens the agreement, where its card explains it.
            e.startsWith("freelance.milestone") || e.startsWith("freelance.report") || e.startsWith("freelance.change") || e.startsWith("freelance.payment") || e.startsWith("freelance.health") ||
                e == "freelance.agreement.received" || e == "freelance.project.completed" -> project?.let(Routes::agreement)
            // Before the work: the project and my offer on it.
            e.startsWith("freelance.offer") || e.startsWith("freelance.project") || e.startsWith("freelance.agreement") ||
                e.startsWith("freelance.review") -> project?.let(Routes::project)
            else -> null
        }
    }
}
