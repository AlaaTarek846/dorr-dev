package com.dorr.app.ui.screens.freelance

/**
 * A page of the client's freelance section. [key] is its written form ("show:<project>", "offer:<project>:<offer>", …): the form a tapped
 * notification names (see [FreelanceLink]) and the form the open pages are kept in while the app is in the background. The hub ([Home]) is
 * where the section opens; the other pages open over it, and back returns to the page they were opened from.
 */
internal sealed interface FlRoute {
    val key: String

    data object Home : FlRoute { override val key = "home" }
    data object Projects : FlRoute { override val key = "list" }
    data object NewProject : FlRoute { override val key = "new" }
    data class EditProject(val project: String) : FlRoute { override val key get() = "edit:$project" }
    data class Project(val project: String) : FlRoute { override val key get() = "show:$project" }
    data class Preview(val project: String) : FlRoute { override val key get() = "preview:$project" }
    data class Invitations(val project: String) : FlRoute { override val key get() = "invites:$project" }
    data class Offers(val project: String) : FlRoute { override val key get() = "offers:$project" }
    data class Compare(val project: String, val offers: List<String>) : FlRoute { override val key get() = "compare:$project:" + offers.joinToString(",") }
    data class Offer(val project: String, val offer: String) : FlRoute { override val key get() = "offer:$project:$offer" }
    data class Freelancer(val project: String, val offer: String) : FlRoute { override val key get() = "freelancer:$project:$offer" }
    // FS-210: a freelancers' team's profile (the team an offer or an agreement is made in the name of; not my own project team, `Team`).
    data class FreelancerTeam(val team: String) : FlRoute { override val key get() = "ftprofile:$team" }
    data class Chat(val project: String, val offer: String, val room: String) : FlRoute { override val key get() = "chat:$project:$offer:$room" }
    data class Agreement(val project: String) : FlRoute { override val key get() = "agreement:$project" }
    data class Dispute(val project: String, val dispute: String) : FlRoute { override val key get() = "dispute:$project:$dispute" }
    data class NewDispute(val project: String, val milestone: String) : FlRoute { override val key get() = "dispute-new:$project:$milestone" }
    // FS-150e: the Workroom of a running work (a tab named by a notice: tasks · files · decisions · timeline), and one of its files.
    data class Workroom(val project: String, val tab: String = "") : FlRoute { override val key get() = if (tab.isBlank()) "workroom:$project" else "workroom:$project:$tab" }
    data class WorkroomFile(val project: String, val file: String) : FlRoute { override val key get() = "wrfile:$project:$file" }
    data object Favorites : FlRoute { override val key = "favorites" }
    data object Receipts : FlRoute { override val key = "receipts" }

    // FS-103: my lists of freelancers.
    data object TalentLists : FlRoute { override val key = "talents" }
    data class TalentList(val list: String) : FlRoute { override val key get() = "talent:$list" }

    // FS-104: my project's team, and the projects others brought me into.
    data class Team(val project: String) : FlRoute { override val key get() = "team:$project" }
    data object Shared : FlRoute { override val key = "shared" }

    // FL-L04: ready-made services and my orders.
    data object Services : FlRoute { override val key = "market" }
    data class Service(val service: String) : FlRoute { override val key get() = "svc:$service" }
    data object Orders : FlRoute { override val key = "orders" }
    data class Order(val order: String) : FlRoute { override val key get() = "order:$order" }

    // FL-L07: paid consultations.
    data object ConsultMarket : FlRoute { override val key = "cmarket" }
    data object Consultations : FlRoute { override val key = "consults" }
    data class Consultation(val consultation: String) : FlRoute { override val key get() = "consult:$consultation" }
    data class Book(val offer: String) : FlRoute { override val key get() = "book:$offer" }

    // FL-L09: special offers from freelancers I worked with, and my loyalty discounts.
    data object SpecialOffers : FlRoute { override val key = "spos" }
    data class SpecialOffer(val special: String) : FlRoute { override val key get() = "spo:$special" }
    data class SpecialBook(val special: String, val offer: String) : FlRoute { override val key get() = "spbook:$special:$offer" }
    data object Loyalty : FlRoute { override val key = "loyalty" }

    companion object {
        /** The page a written key names; the hub for anything it does not know (or a key with an id missing). */
        fun parse(key: String): FlRoute {
            val p = key.split(':')
            fun at(i: Int): String? = p.getOrNull(i)?.takeIf { it.isNotBlank() }
            val a = at(1)
            val b = at(2)
            val c = at(3)
            return when (p[0]) {
                "home" -> Home
                "list" -> Projects
                "new" -> NewProject
                "favorites" -> Favorites
                "receipts" -> Receipts
                "talents" -> TalentLists
                "talent" -> a?.let(::TalentList)
                "team" -> a?.let(::Team)
                "shared" -> Shared
                "market" -> Services
                "orders" -> Orders
                "cmarket" -> ConsultMarket
                "consults" -> Consultations
                "spos" -> SpecialOffers
                "loyalty" -> Loyalty
                "edit" -> a?.let(::EditProject)
                "show" -> a?.let(::Project)
                "preview" -> a?.let(::Preview)
                "invites" -> a?.let(::Invitations)
                "offers" -> a?.let(::Offers)
                "agreement" -> a?.let(::Agreement)
                "workroom" -> a?.let { Workroom(it, b.orEmpty()) }
                "wrfile" -> if (a != null && b != null) WorkroomFile(a, b) else null
                "svc" -> a?.let(::Service)
                "order" -> a?.let(::Order)
                "consult" -> a?.let(::Consultation)
                "book" -> a?.let(::Book)
                "spo" -> a?.let(::SpecialOffer)
                "compare" -> if (a != null && b != null) Compare(a, b.split(',').filter { it.isNotBlank() }) else null
                "offer" -> if (a != null && b != null) Offer(a, b) else null
                "freelancer" -> if (a != null && b != null) Freelancer(a, b) else null
                "ftprofile" -> a?.let(::FreelancerTeam)
                "dispute" -> if (a != null && b != null) Dispute(a, b) else null
                "dispute-new" -> if (a != null && b != null) NewDispute(a, b) else null
                "spbook" -> if (a != null && b != null) SpecialBook(a, b) else null
                "chat" -> if (a != null && b != null && c != null) Chat(a, b, c) else null
                else -> null
            } ?: Home
        }
    }
}

/**
 * The open pages, the hub first and the page on screen last. Opening a page that is already open goes back to it (so «Browse» from my
 * orders and «My orders» from the services never pile up).
 */
internal class FlStack(val routes: List<FlRoute>) {
    val top: FlRoute get() = routes.last()

    fun open(route: FlRoute): FlStack {
        val at = routes.indexOfFirst { it.key == route.key }
        return if (at >= 0) FlStack(routes.take(at + 1)) else FlStack(routes + route)
    }

    /** The page on screen becomes [route] (a form that saved, a booking made): back skips the one replaced. */
    fun replace(route: FlRoute): FlStack = if (routes.size > 1) FlStack(routes.dropLast(1)).open(route) else open(route)

    /** Back to the last page that [match]es, then [route] over it; just [route] over the current page when none matches. */
    fun after(match: (FlRoute) -> Boolean, route: FlRoute): FlStack {
        val at = routes.indexOfLast(match)
        return (if (at >= 0) FlStack(routes.take(at + 1)) else this).open(route)
    }

    fun back(): FlStack = if (routes.size > 1) FlStack(routes.dropLast(1)) else this

    /** Written as the keys joined by «|» (a key never holds one). */
    fun write(): String = routes.joinToString("|") { it.key }

    companion object {
        val start = FlStack(listOf(FlRoute.Home))

        fun read(text: String): FlStack {
            val routes = text.split('|').filter { it.isNotBlank() }.map(FlRoute::parse).filter { it != FlRoute.Home }.distinctBy { it.key }
            return FlStack(listOf(FlRoute.Home) + routes)
        }

        /** A page named by a notification: the hub under it, so back lands on the hub. */
        fun linked(key: String): FlStack = read(key)
    }
}
