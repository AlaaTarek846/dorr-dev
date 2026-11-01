#if DEBUG
import Foundation

/// DEBUG builds only — the iOS counterpart of the Android previews' `initial*` seeds: launch the app with
///
///     -DorrFixtures YES                    every API call is answered from the sample JSON below
///     -DorrWalletRoute home|topup|transfer|confirm|qr|scanner|history|pin|checkout|gate
///                                          signed in, the wallet open on that page (the PIN gate skipped,
///                                          except for "gate")
///     -DorrWalletSheet explain|tx|pin      and that bottom sheet open over it
///     -DorrStartRoute onboarding|login|otp|main  the app opens straight on that screen
///     -DorrProfileRoute settings|security|addresses|support|quick|appearance|font|…  the Account tab on that page
///
/// so each screen can be looked at (simulator screenshots) without an account on a real server. The answers
/// go through the real decoding and the real screens. Never compiled into a Release build.
enum DebugFlags {
    static var fixtures: Bool { UserDefaults.standard.bool(forKey: "DorrFixtures") }
    static var walletRoute: String? { UserDefaults.standard.string(forKey: "DorrWalletRoute") }
    static var walletSheet: String? { UserDefaults.standard.string(forKey: "DorrWalletSheet") }

    /// The sheet a debug launch opens over the first page.
    @MainActor
    static func sheet() -> WaSheet? {
        switch walletSheet {
        case "explain": return .explain
        case "tx":
            let rows = try? JSONDecoder().decode([WalletTransactionDto].self, from: Data(Fixtures.transactions.utf8))
            return rows.map { .tx($0[1]) }
        case "pin":
            return .pin(PinSheet(subtitle: L("wa_pin_transfer_sub"), onSubmit: { _ in .retry("") }, onClose: { _ in }, lockedUntil: nil))
        default: return nil
        }
    }

    @MainActor
    static func apply(router: AppRouter) {
        switch UserDefaults.standard.string(forKey: "DorrStartRoute") {
        case "onboarding": router.route = .onboarding
        case "login": router.route = .login
        case "main":
            // No token is saved: the fixtures answer without one, so a normal launch afterwards is untouched.
            router.route = .main
        case "otp":
            router.pendingDialCode = "+966"
            router.pendingPhone = "512345678"
            router.route = .otp
        default: break
        }
        // -DorrProfileRoute settings|security|addresses|… : the Account tab, on that page.
        if let page = UserDefaults.standard.string(forKey: "DorrProfileRoute") {
            OnboardingStore.markCompleted()
            router.route = .main
            router.mainTab = 3
            let pages: [String: ProfileSub] = [
                "menu": .none, "personal": .personalData, "settings": .settings, "notifications": .notifications, "security": .security,
                "legal": .legal, "addresses": .addresses, "invite": .invite, "rate": .rate, "support": .support, "ticket": .ticket,
                "quick": .quickChat, "appearance": .appearance, "font": .font,
            ]
            router.profileSub = pages[page] ?? .none
        }
        guard walletRoute != nil else { return }
        if AuthSession.shared.token == nil { AuthSession.shared.token = "debug-fixture-token" }
        OnboardingStore.markCompleted()
        router.walletOpen = true
    }

    /// The page the wallet opens on, and whether the PIN gate is skipped.
    @MainActor
    static var walletStart: (route: WaRoute, unlocked: Bool)? {
        switch walletRoute {
        case nil: return nil
        case "gate": return (.home, false)
        case "topup": return (.topup, true)
        case "transfer": return (.transfer, true)
        case "confirm":
            let who = try? JSONDecoder().decode(TransferRecipientDto.self, from: Data(Fixtures.recipient.utf8))
            return who.map { (.transferConfirm($0), true) } ?? (.home, true)
        case "qr": return (.myQr, true)
        case "scanner": return (.scanner, true)
        case "history": return (.history, true)
        case "pin": return (.pinSettings, true)
        case "checkout": return (.checkout(CheckoutState(request: CheckoutRequest(purpose: "listing", reference: ["listing_id": .int(1)]))), true)
        default: return (.home, true)
        }
    }
}

/// Answers API requests from [Fixtures] when `-DorrFixtures YES` is passed.
final class FixtureProtocol: URLProtocol {
    override class func canInit(with request: URLRequest) -> Bool { DebugFlags.fixtures }
    override class func canonicalRequest(for request: URLRequest) -> URLRequest { request }

    override func startLoading() {
        let path = request.url?.path ?? ""
        let body = Fixtures.answer(path: path, method: request.httpMethod ?? "GET")
        let response = HTTPURLResponse(url: request.url!, statusCode: 200, httpVersion: "HTTP/1.1", headerFields: ["Content-Type": "application/json"])!
        // A little latency, so loading states show for a moment like on a real network.
        DispatchQueue.global().asyncAfter(deadline: .now() + 0.35) {
            self.client?.urlProtocol(self, didReceive: response, cacheStoragePolicy: .notAllowed)
            self.client?.urlProtocol(self, didLoad: Data(body.utf8))
            self.client?.urlProtocolDidFinishLoading(self)
        }
    }

    override func stopLoading() {}
}

enum Fixtures {
    private static let convC1 = "{\"id\":\"c1\",\"type\":\"direct\",\"status\":\"accepted\",\"title\":\"سارة العتيبي\",\"peer\":{\"type\":\"user\",\"id\":2,\"key\":\"user:2\",\"name\":\"سارة العتيبي\",\"status\":{\"emoji\":\"🏖️\",\"text\":\"في إجازة\"}},\"my_role\":\"member\",\"is_member\":true,\"can_send\":true,\"can_call\":true,\"presence\":{\"online\":true}}"

    private static func msg(_ id: String, _ mine: Bool, _ at: String, _ rest: String) -> String {
        let sender = mine ? "{\"type\":\"user\",\"id\":3,\"key\":\"user:3\",\"name\":\"منى\",\"is_me\":true}" : "{\"type\":\"user\",\"id\":2,\"key\":\"user:2\",\"name\":\"سارة العتيبي\"}"
        return "{\"id\":\"\(id)\",\"conversation_id\":\"c1\",\"is_mine\":\(mine),\"status\":\"read\",\"created_at\":\"2026-10-31T\(at):00Z\",\"sender\":\(sender),\(rest)}"
    }

    private static let convMessages = "[" + [
        msg("m1", false, "08:01", "\"type\":\"text\",\"body\":\"صباح الخير يا منى ☀️\""),
        msg("m2", false, "08:02", "\"type\":\"text\",\"body\":\"فاكرة عزومة بكرة؟ *الساعة ٧* بالليل، وهبعتلك اللوكيشن\""),
        msg("m3", true, "08:05", "\"type\":\"text\",\"body\":\"طبعاً فاكرة 😍 ابعتيه\",\"reply_to\":{\"id\":\"m2\",\"type\":\"text\",\"body\":\"فاكرة عزومة بكرة؟\",\"sender\":{\"type\":\"user\",\"id\":2,\"key\":\"user:2\",\"name\":\"سارة العتيبي\"}},\"reactions\":{\"summary\":[{\"emoji\":\"❤️\",\"count\":1}],\"total\":1}"),
        msg("m4", false, "08:07", "\"type\":\"location\",\"meta\":{\"latitude\":24.77,\"longitude\":46.64,\"name\":\"حي النرجس\"}"),
        msg("m5", false, "08:08", "\"type\":\"voice\",\"meta\":{\"duration_ms\":14000},\"attachments\":[{\"id\":\"a1\",\"url\":\"/v.m4a\"}]"),
        msg("m6", true, "08:10", "\"type\":\"poll\",\"body\":\"نجيب إيه معانا؟\",\"poll\":{\"question\":\"نجيب إيه معانا؟\",\"multiple\":false,\"options\":[{\"id\":1,\"text\":\"حلويات\",\"votes\":2},{\"id\":2,\"text\":\"ورد\",\"votes\":1}],\"my_votes\":[1],\"voters\":3}"),
        msg("m7", false, "08:12", "\"type\":\"money_request\",\"body\":\"حق الكيكة 🎂\",\"payment\":{\"kind\":\"request\",\"status\":\"pending\",\"amount_minor\":5000,\"currency_symbol\":\"ر.س\",\"is_requester\":false,\"can_pay\":true}"),
        msg("m8", true, "08:15", "\"type\":\"text\",\"body\":\"تمام، أشوفك بكرة الساعة ٧ 👋\",\"status\":\"delivered\""),
    ].joined(separator: ",") + "]"

    static func envelope(_ data: String, pagination: String? = nil) -> String {
        "{\"success\":true,\"status\":\"success\",\"code\":200,\"message\":\"OK\",\"data\":\(data)\(pagination.map { ",\"pagination\":\($0)" } ?? "")}"
    }

    static func answer(path: String, method: String) -> String {
        switch true {
        case path.hasSuffix("/wallet"): return envelope(balance)
        case path.hasSuffix("/wallet/transactions"): return envelope(transactions, pagination: "{\"current_page\":1,\"has_more_pages\":true}")
        case path.hasSuffix("/wallet/pin"): return envelope(pinStatus)
        case path.hasSuffix("/wallet/pin/verify"): return envelope("{\"verified\":true,\"must_change\":false,\"device_trusted\":true}")
        case path.hasSuffix("/wallet/payment-methods"): return envelope(methods)
        case path.hasSuffix("/wallet/topups/quote"): return envelope(quote)
        case path.hasSuffix("/wallet/transfers/lookup"): return envelope(recipient)
        case path.hasSuffix("/wallet/coupons"): return envelope(coupons)
        case path.contains("/wallet/checkouts"): return envelope(checkout)
        case path.hasSuffix("/general/v1/mobile-appearance-defaults"): return envelope("{\"light_tokens\":{},\"dark_tokens\":{}}")
        case path.contains("/general/v1/services"): return envelope(services)
        case path.hasSuffix("/discover/home"): return envelope(discoverHome)
        case path.contains("/discover/events/"): return envelope(discoverEvent)
        case path.hasSuffix("/discover/events"): return envelope("[\(discoverEvent)]", pagination: "{\"current_page\":1,\"has_more_pages\":false}")
        case path.hasSuffix("/freelance/projects"): return envelope("[\(flProject("p1", "published")),\(flProject("p2", "draft")),\(flProject("p3", "in_progress"))]", pagination: "{\"current_page\":1,\"has_more_pages\":false}")
        case path.hasSuffix("/freelance/projects/p1/offers"): return envelope("[\(flOffer("o1", "سارة العتيبي", 450_000, 14, "shortlisted")),\(flOffer("o2", "خالد منصور", 380_000, 21, "submitted"))]")
        case path.contains("/freelance/projects/p1/offers/"): return envelope(flOffer("o1", "سارة العتيبي", 450_000, 14, "shortlisted"))
        case path.hasSuffix("/agreement/trail"), path.hasSuffix("/freelance/receipts"): return envelope("[]")
        case path.hasSuffix("/agreement"): return envelope(flAgreement)
        case path.contains("/freelance/projects/"): return envelope(flProject("p1", "published"))
        case path.hasSuffix("/rides/current"): return envelope("null")
        case path.hasSuffix("/rides/features"):
            return envelope("{\"service\":true,\"categories\":{\"bus\":true,\"marine\":false},\"features\":{\"cash\":true,\"wallet\":true,\"card\":true,\"coupons\":true,\"multi_stop\":true,\"open_destination\":true,\"find_driver\":true}}")
        case path.hasSuffix("/rides/nearby-cars"):
            return envelope("{\"cells\":[],\"cars\":[{\"car\":\"a\",\"lat\":24.716,\"lng\":46.678,\"heading\":40},{\"car\":\"b\",\"lat\":24.710,\"lng\":46.671,\"heading\":200},{\"car\":\"c\",\"lat\":24.718,\"lng\":46.670,\"heading\":120}]}")
        case path.hasSuffix("/rides/places/popular"):
            return envelope("[{\"name\":\"الرياض بارك\",\"address\":\"طريق الملك عبدالله\",\"lat\":24.756,\"lng\":46.629},{\"name\":\"بوليفارد رياض سيتي\",\"address\":\"حطين\",\"lat\":24.769,\"lng\":46.601}]")
        case path.hasSuffix("/rides/quotes"): return envelope(rideQuotes)
        case path.hasSuffix("/ai-sites/offers"): return envelope("{\"enabled\":true,\"plan\":{\"included\":true,\"can_create\":true},\"offers\":[]}")
        case path.hasSuffix("/ai-site-requests/info"): return envelope("{\"enabled\":true}")
        case path.hasSuffix("/ai-site-requests"): return envelope("[]")
        case path.hasSuffix("/user/v1/ai-sites"): return envelope("[{\"id\":3,\"title\":\"مطعم الريف\",\"status\":\"ready\",\"access_type\":\"plan\",\"versions\":[{\"id\":1,\"number\":1,\"kind\":\"initial\",\"status\":\"completed\",\"is_current\":true}]},{\"id\":4,\"title\":\"Studio Noor\",\"status\":\"generating\",\"access_type\":\"plan\"}]")
        case path.hasSuffix("/ai-world/info"): return envelope("{\"enabled\":true}")
        case path.hasSuffix("/ai-world/projects"): return envelope("[{\"id\":5,\"title\":\"رحلة العلا\",\"status\":\"generating\",\"total_seconds\":24}]")
        case path.hasSuffix("/ai-ar/info"): return envelope("{\"enabled\":true,\"measurement_notice\":\"المقاسات من ملف النموذج نفسه.\"}")
        case path.hasSuffix("/ai-ar/assets"): return envelope("[{\"id\":1,\"name\":\"كنبة ثلاثية\",\"dimensions\":{\"width\":2.1,\"height\":0.85,\"depth\":0.9}}]")
        case path.hasSuffix("/chat/conversations/c1"): return envelope(convC1)
        case path.hasSuffix("/chat/conversations/c1/messages"): return envelope("{\"messages\":\(convMessages),\"has_more_before\":false}")
        case path.hasSuffix("/chat/conversations/c1/pinned"): return envelope("[{\"id\":\"m3\",\"conversation_id\":\"c1\",\"type\":\"text\",\"body\":\"العنوان: حي النرجس، شارع ١٢\"}]")
        case path.hasSuffix("/chat/conversations/c1/scheduled"): return envelope("[{\"id\":\"sc1\",\"body\":\"كل سنة وانتي طيبة 🎂\",\"send_at\":\"2026-11-02T09:00:00+03:00\",\"status\":\"pending\"}]")
        case path.hasSuffix("/chat/calls"): return envelope("[{\"id\":\"k1\",\"conversation_id\":\"c1\",\"conversation_type\":\"direct\",\"type\":\"video\",\"status\":\"ended\",\"is_outgoing\":true,\"duration_seconds\":754,\"created_at\":\"2026-10-31T08:00:00Z\",\"recordings_count\":1,\"participants\":[{\"status\":\"left\",\"profile\":{\"type\":\"user\",\"id\":2,\"key\":\"user:2\",\"name\":\"سارة العتيبي\",\"is_contact\":true,\"is_me\":false,\"is_deleted\":false}}]},{\"id\":\"k2\",\"conversation_id\":\"c3\",\"conversation_type\":\"direct\",\"type\":\"audio\",\"status\":\"missed\",\"is_outgoing\":false,\"created_at\":\"2026-10-30T19:20:00Z\",\"participants\":[{\"status\":\"missed\",\"profile\":{\"type\":\"user\",\"id\":4,\"key\":\"user:4\",\"name\":\"خالد منصور\",\"is_contact\":true,\"is_me\":false,\"is_deleted\":false}}]},{\"id\":\"k3\",\"conversation_id\":\"c2\",\"conversation_type\":\"group\",\"group_name\":\"عيلة الدرر\",\"type\":\"audio\",\"status\":\"ended\",\"is_outgoing\":false,\"duration_seconds\":1820,\"created_at\":\"2026-10-29T21:00:00Z\",\"silenced\":true}]")
        case path.hasSuffix("/chat/call-links"): return envelope("[{\"token\":\"AbCd1234Ef\",\"type\":\"video\",\"link\":\"https://dorr.app/call/AbCd1234Ef\",\"payload\":\"dorr://chat/call/AbCd1234Ef\",\"is_mine\":true}]")
        case path.hasSuffix("/voice-room"): return envelope("{\"id\":\"vr1\",\"conversation_id\":\"c1\",\"speak_policy\":\"everyone\",\"count\":5,\"members\":[{\"participant_id\":1,\"key\":\"user:2\",\"name\":\"سارة\",\"role\":\"host\"},{\"participant_id\":2,\"key\":\"user:4\",\"name\":\"خالد\",\"role\":\"speaker\"},{\"participant_id\":3,\"key\":\"user:5\",\"name\":\"منى\",\"role\":\"speaker\"},{\"participant_id\":4,\"key\":\"user:6\",\"name\":\"ريم\",\"role\":\"listener\",\"hand_raised\":true},{\"participant_id\":5,\"key\":\"user:7\",\"name\":\"عمر\",\"role\":\"listener\"}],\"me\":{\"in_room\":false,\"role\":\"listener\",\"can_manage\":true}}")
        case path.hasSuffix("/chat/privacy"): return envelope("{\"last_seen\":\"contacts\",\"profile_photo\":\"everyone\",\"who_can_message\":\"everyone\",\"who_can_add_to_groups\":\"contacts\",\"who_can_call\":\"everyone\",\"read_receipts\":true,\"block_screenshots\":false,\"notification_privacy\":\"all\",\"privacy_mode\":{\"on\":false,\"schedule\":{\"from\":\"23:00\",\"to\":\"07:00\",\"days\":[0,1,2,3,4]}},\"quiet\":{\"on\":false}}")
        case path.hasSuffix("/chat/realtime-config"): return envelope("{\"enabled\":false}")
        case path.hasSuffix("/chat/features"): return envelope("{\"features\":{}}")
        case path.hasSuffix("/chat/folders"): return envelope("[{\"id\":1,\"name\":\"الشغل\",\"emoji\":\"💼\",\"conversation_ids\":[\"c3\"]}]")
        case path.hasSuffix("/chat/stories/public"): return envelope("{\"enabled\":true,\"quota\":{\"free\":3,\"used\":0},\"mine\":null,\"dorr\":{\"stories\":[{\"id\":\"d1\",\"type\":\"text\",\"body\":\"عروض دُرر الجديدة 🎉\",\"style\":{\"background\":\"dorr\"},\"is_dorr\":true,\"link_url\":\"https://dorr.app\",\"seen\":false}],\"all_seen\":false},\"people\":[{\"owner\":{\"type\":\"user\",\"id\":4,\"key\":\"user:4\",\"name\":\"خالد\"},\"stories\":[{\"id\":\"p1\",\"type\":\"text\",\"body\":\"مساء الخير\",\"style\":{\"background\":\"ocean\"},\"is_public\":true,\"allow_replies\":true,\"seen\":false}],\"all_seen\":false}],\"has_more\":false}")
        case path.hasSuffix("/chat/stories"): return envelope("{\"mine\":null,\"recent\":[{\"owner\":{\"type\":\"user\",\"id\":2,\"key\":\"user:2\",\"name\":\"سارة\"},\"stories\":[{\"id\":\"s1\",\"type\":\"text\",\"body\":\"صباح الخير ☀️ يوم جميل للجميع\",\"style\":{\"background\":\"sunset\",\"font\":\"bold\",\"layers\":[{\"k\":\"t\",\"x\":0.7,\"y\":0.25,\"s\":1.8,\"r\":-12,\"text\":\"🌻\",\"c\":\"#FFFFFF\"},{\"k\":\"t\",\"x\":0.5,\"y\":0.72,\"s\":1,\"r\":0,\"text\":\"#دُرر\",\"c\":\"#FACC15\",\"f\":\"bold\",\"bg\":1}]},\"allow_replies\":true,\"seen\":false,\"created_at\":\"2026-10-31T08:00:00+03:00\"}],\"all_seen\":false},{\"owner\":{\"type\":\"user\",\"id\":4,\"key\":\"user:4\",\"name\":\"خالد\"},\"stories\":[{\"id\":\"s2\",\"type\":\"text\",\"seen\":true},{\"id\":\"s3\",\"type\":\"text\",\"seen\":false}],\"all_seen\":false}]}")
        case path.hasSuffix("/chat/conversations") && method == "GET": return "{\"success\":true,\"data\":[{\"id\":\"c1\",\"type\":\"direct\",\"status\":\"active\",\"title\":\"سارة العتيبي\",\"peer\":{\"type\":\"user\",\"id\":2,\"key\":\"user:2\",\"name\":\"سارة العتيبي\"},\"group\":null,\"my_role\":\"member\",\"is_member\":true,\"can_send\":true,\"last_message\":{\"id\":\"mc1\",\"type\":\"text\",\"body\":\"تمام، أشوفك بكرة الساعة ٧ 👋\",\"is_mine\":false,\"status\":\"read\",\"sender\":{\"type\":\"user\",\"id\":3,\"key\":\"user:3\",\"name\":\"منى\"}},\"last_message_at\":\"2026-10-31T09:10:00Z\",\"unread_count\":2,\"is_pinned\":true,\"is_muted\":false,\"has_unread_mention\":false},{\"id\":\"c2\",\"type\":\"group\",\"status\":\"active\",\"title\":\"عيلة الدرر\",\"peer\":null,\"group\":{\"members_count\":12,\"is_verified\":true},\"my_role\":\"member\",\"is_member\":true,\"can_send\":true,\"last_message\":{\"id\":\"mc2\",\"type\":\"image\",\"body\":\"\",\"is_mine\":false,\"status\":\"read\",\"sender\":{\"type\":\"user\",\"id\":3,\"key\":\"user:3\",\"name\":\"منى\"}},\"last_message_at\":\"2026-10-31T09:11:00Z\",\"unread_count\":0,\"is_pinned\":false,\"is_muted\":true,\"has_unread_mention\":true},{\"id\":\"c3\",\"type\":\"direct\",\"status\":\"active\",\"title\":\"خالد منصور\",\"peer\":{\"type\":\"user\",\"id\":4,\"key\":\"user:4\",\"name\":\"خالد منصور\"},\"group\":null,\"my_role\":\"member\",\"is_member\":true,\"can_send\":true,\"last_message\":{\"id\":\"mc3\",\"type\":\"text\",\"body\":\"أرسلت لك الملف\",\"is_mine\":true,\"status\":\"read\",\"sender\":{\"type\":\"user\",\"id\":3,\"key\":\"user:3\",\"name\":\"منى\"}},\"last_message_at\":\"2026-10-31T09:12:00Z\",\"unread_count\":0,\"is_pinned\":false,\"is_muted\":false,\"has_unread_mention\":false},{\"id\":\"c4\",\"type\":\"channel\",\"status\":\"active\",\"title\":\"أخبار دُرر\",\"peer\":null,\"group\":{\"members_count\":12,\"is_verified\":true},\"my_role\":\"member\",\"is_member\":true,\"can_send\":true,\"last_message\":{\"id\":\"mc4\",\"type\":\"text\",\"body\":\"التحديث الجديد نزل 🎉\",\"is_mine\":false,\"status\":\"read\",\"sender\":{\"type\":\"user\",\"id\":3,\"key\":\"user:3\",\"name\":\"منى\"}},\"last_message_at\":\"2026-10-31T09:13:00Z\",\"unread_count\":5,\"is_pinned\":false,\"is_muted\":false,\"has_unread_mention\":false}],\"requests_count\":2,\"pagination\":{\"current_page\":1,\"has_more_pages\":false}}"
        case path.hasSuffix("/ai-chat/status"): return envelope("{\"available\":true,\"brand_name\":\"DORR AI\",\"plan\":{\"allowed\":true,\"is_trial\":true}}")
        case path.hasSuffix("/ai-chat/usage"): return envelope("{\"allowed\":true,\"plan_is_trial\":true,\"remaining_seconds\":1800}")
        case path.hasSuffix("/ai-chat/conversations") && method == "GET": return envelope("[{\"id\":7,\"title\":\"خطة رحلة جدة\",\"messages_count\":2,\"last_message_preview\":\"تمام، دي خطة ٣ أيام…\",\"updated_at\":\"2026-10-29T10:00:00Z\",\"messages\":[{\"id\":1,\"role\":\"user\",\"content\":\"اعملي خطة ٣ أيام في جدة\"},{\"id\":2,\"role\":\"assistant\",\"content\":\"أكيد! دي **خطة ٣ أيام** في جدة:\\n\\n- اليوم الأول: البلد والكورنيش\\n- اليوم التاني: جزيرة البحر الأحمر\\n\\n```python\\nprint(\\\"Jeddah\\\")\\n```\",\"citations\":[{\"id\":1,\"number\":1,\"file_id\":3,\"file_name\":\"guide.pdf\",\"location\":{\"page\":4}}]}]},{\"id\":8,\"title\":\"ترجمة عقد\",\"updated_at\":\"2026-10-29T08:00:00Z\"}]")
        case path.hasSuffix("/ai-chat/conversations/7"): return envelope("{\"id\":7,\"title\":\"خطة رحلة جدة\",\"messages_count\":2,\"last_message_preview\":\"تمام، دي خطة ٣ أيام…\",\"updated_at\":\"2026-10-29T10:00:00Z\",\"messages\":[{\"id\":1,\"role\":\"user\",\"content\":\"اعملي خطة ٣ أيام في جدة\"},{\"id\":2,\"role\":\"assistant\",\"content\":\"أكيد! دي **خطة ٣ أيام** في جدة:\\n\\n- اليوم الأول: البلد والكورنيش\\n- اليوم التاني: جزيرة البحر الأحمر\\n\\n```python\\nprint(\\\"Jeddah\\\")\\n```\",\"citations\":[{\"id\":1,\"number\":1,\"file_id\":3,\"file_name\":\"guide.pdf\",\"location\":{\"page\":4}}]}]}")
        case path.contains("/capsule"): return envelope("{\"capsule\":null}")
        case path.hasSuffix("/rides/bus/trips"): return envelope("[{\"uuid\":\"t1\",\"status\":\"scheduled\",\"leg\":\"outbound\",\"origin\":{\"lat\":24.71,\"lng\":46.67,\"address\":\"الرياض — محطة العليا\"},\"destination\":{\"lat\":21.54,\"lng\":39.17,\"address\":\"جدة — الكورنيش\"},\"depart_at\":\"2026-10-30T07:30:00+03:00\",\"return_at\":\"2026-11-02T16:00:00+03:00\",\"prices\":{\"outbound_minor\":12000,\"return_minor\":11000,\"round_minor\":10000},\"currency\":{\"code\":\"SAR\",\"symbol\":\"ر.س\",\"decimal_places\":2},\"seats_total\":30,\"seats_per_row\":4,\"free\":{\"outbound\":24,\"return\":28},\"taken\":{\"outbound\":[1,2,7,12,13,20],\"return\":[3,4]},\"bus\":{\"make\":\"Mercedes\",\"model\":\"Tourismo\",\"plate\":\"ب ر ص 4821\"},\"captain\":{\"name\":\"فهد القحطاني\"}}]")
        case path.contains("/rides/bus/trips/"): return envelope("{\"uuid\":\"t1\",\"status\":\"scheduled\",\"leg\":\"outbound\",\"origin\":{\"lat\":24.71,\"lng\":46.67,\"address\":\"الرياض — محطة العليا\"},\"destination\":{\"lat\":21.54,\"lng\":39.17,\"address\":\"جدة — الكورنيش\"},\"depart_at\":\"2026-10-30T07:30:00+03:00\",\"return_at\":\"2026-11-02T16:00:00+03:00\",\"prices\":{\"outbound_minor\":12000,\"return_minor\":11000,\"round_minor\":10000},\"currency\":{\"code\":\"SAR\",\"symbol\":\"ر.س\",\"decimal_places\":2},\"seats_total\":30,\"seats_per_row\":4,\"free\":{\"outbound\":24,\"return\":28},\"taken\":{\"outbound\":[1,2,7,12,13,20],\"return\":[3,4]},\"bus\":{\"make\":\"Mercedes\",\"model\":\"Tourismo\",\"plate\":\"ب ر ص 4821\"},\"captain\":{\"name\":\"فهد القحطاني\"}}")
        case path.hasSuffix("/rides/map-config"):
            let engine = UserDefaults.standard.string(forKey: "DorrMapEngine") ?? "apple"
            return envelope("{\"provider\":\"google\",\"ios_provider\":\"\(engine)\",\"mapbox_token\":null,\"mapbox_style\":null}")
        case path.hasSuffix("/sports/home"): return envelope(sportsHome)
        case path.contains("/sports/matches/") && path.hasSuffix("/play"): return envelope("{\"enabled\":true,\"locked\":false,\"crowd\":{\"home\":48,\"draw\":22,\"away\":30,\"total\":1240}}")
        case path.contains("/sports/matches/") && path.hasSuffix("/insights"): return envelope("{}")
        case path.contains("/sports/matches/"): return envelope(sportsMatch)
        case path.hasSuffix("/sports/preferences"): return envelope("{\"no_spoilers\":false,\"celebration\":\"festive\",\"sounds\":true,\"vibrate\":true,\"reminder_minutes\":15}")
        case path.hasSuffix("/sports/follows"): return envelope("[]")
        case path.hasSuffix("/chat/calendar/today"): return envelope(calToday)
        case path.hasSuffix("/chat/calendar"): return envelope("{\"enabled\":true,\"items\":\(calItems)}")
        case path.hasSuffix("/chat/calendar/preferences"): return envelope("{\"sources\":{\"events\":true,\"sports\":false},\"default_reminders\":[30],\"all_day_reminders\":[0],\"respect_quiet\":true}")
        case path.hasSuffix("/chat/moments"): return envelope(momentsCenter)
        case path.hasSuffix("/chat/moments/catalog"): return envelope("[{\"id\":1,\"key\":\"eid\",\"name\":\"عيد الفطر\",\"kind\":\"religious\",\"emoji\":\"🌙\",\"next\":\"2027-03-10\",\"on\":true},{\"id\":2,\"key\":\"national\",\"name\":\"اليوم الوطني\",\"kind\":\"national\",\"emoji\":\"🇸🇦\",\"next\":\"2027-09-23\",\"on\":false}]")
        case path.hasSuffix("/chat/categories"): return envelope("[{\"id\":1,\"name\":\"مطاعم\",\"icon\":null},{\"id\":2,\"name\":\"أزياء\",\"icon\":null}]")
        case path.hasSuffix("/chat/portals"): return envelope(portalGroups)
        case path.hasSuffix("/mobile/v1/notifications"): return envelope(notifications, pagination: "{\"current_page\":1,\"has_more_pages\":false}")
        case path.hasSuffix("/notifications/unread-count"): return envelope("{\"count\":3}")
        case path.hasSuffix("/chat/conversations"): return envelope("[{},{},{},{},{}]")
        case path.hasSuffix("/addresses"): return envelope(addresses, pagination: "{\"current_page\":1,\"has_more_pages\":false}")
        case path.hasSuffix("/security/devices"): return envelope(devices)
        case path.hasSuffix("/security/events"): return envelope(securityEvents, pagination: "{\"current_page\":1,\"has_more_pages\":true}")
        case path.hasSuffix("/support-tickets"): return envelope(tickets, pagination: "{\"current_page\":1,\"has_more_pages\":false}")
        case path.hasSuffix("/messages") && path.contains("/support-tickets/"): return envelope(supportMessages, pagination: "{\"current_page\":1,\"has_more_pages\":false}")
        case path.hasSuffix("/support-help"): return envelope(supportHelp)
        case path.hasSuffix("/referrals/my-code"): return envelope("{\"code\":\"ALI7K2\",\"is_active\":true}")
        case path.hasSuffix("/ratings/mine"): return envelope("{\"rated\":true,\"rating\":{\"id\":1,\"stars\":4.5,\"comment\":\"تطبيق ممتاز وسهل الاستخدام\"}}")
        case path.hasSuffix("/faqs"): return envelope(faqs)
        case path.hasSuffix("/mobile/v1/appearance"): return envelope(appearance)
        case path.hasSuffix("/auth/me"): return envelope("{\"id\":7,\"name\":\"Ali Hassan\",\"phone\":\"+966512345678\"}")
        default: return envelope("null")
        }
    }

    static func flProject(_ id: String, _ status: String) -> String {
        "{\"uuid\":\"\(id)\",\"status\":\"\(status)\",\"title\":\"تصميم هوية بصرية لمتجر قهوة\",\"description\":\"نحتاج شعار وألوان وخطوط ودليل استخدام مختصر لمتجر قهوة مختصة في الرياض.\","
            + "\"questions\":[\"هل عندك أعمال سابقة لمقاهي؟\"],\"specialty\":{\"id\":3,\"name\":\"تصميم الهوية\"},\"skills\":[{\"id\":1,\"name\":\"Illustrator\"},{\"id\":2,\"name\":\"Branding\"}],"
            + "\"budget\":{\"type\":\"range\",\"min_minor\":300000,\"max_minor\":500000,\"currency\":\"SAR\"},\"duration_days\":20,\"visibility\":\"public\",\"attachments\":[{\"id\":1,\"name\":\"brief.pdf\"}],"
            + "\"missing\":[],\"can\":{\"edit\":\(status == "draft"),\"submit\":\(status == "draft"),\"delete\":\(status == "draft"),\"cancel\":\(status == "published")},"
            + "\"offers\":{\"accepting\":true,\"deadline_at\":\"2026-11-12T00:00:00Z\",\"can_close\":true,\"count\":2},"
            + "\"timeline\":[{\"type\":\"project.created\",\"at\":\"2026-10-20T10:12:00Z\"},{\"type\":\"project.published\",\"at\":\"2026-10-21T08:00:00Z\"}]}"
    }

    static func flOffer(_ id: String, _ name: String, _ price: Int, _ days: Int, _ status: String) -> String {
        "{\"uuid\":\"\(id)\",\"status\":\"\(status)\",\"price_minor\":\(price),\"currency\":\"SAR\",\"duration_days\":\(days),"
            + "\"details\":\"أقدم ثلاث مقترحات للشعار وجولتين تعديل، مع ملف الهوية كامل بصيغ الطباعة والويب.\",\"answers\":[{\"question\":\"هل عندك أعمال سابقة لمقاهي؟\",\"answer\":\"نعم، خمسة مشاريع.\"}],"
            + "\"freelancer\":{\"uuid\":\"f-\(id)\",\"name\":\"\(name)\",\"headline\":\"مصممة هويات بصرية\",\"verified\":true,\"completeness\":92,\"portfolio_count\":14,"
            + "\"matching_skills\":[{\"id\":2,\"name\":\"Branding\"}],\"project_skills_count\":2,\"rating\":4.8,\"reviews_count\":23,\"is_favorite\":true},"
            + "\"can\":{\"chat\":true,\"choose\":true,\"unshortlist\":true,\"reject\":true},\"client_cost\":{\"price_minor\":\(price),\"client_fee_minor\":13500,\"total_minor\":\(price + 13500),\"currency\":\"SAR\"}}"
    }

    static let flAgreement = "{\"uuid\":\"a1\",\"status\":\"active\",\"version\":2,\"scope\":\"هوية بصرية كاملة\",\"price_minor\":450000,\"currency\":\"SAR\",\"duration_days\":14,"
        + "\"money\":{\"price_minor\":450000,\"client_fee_minor\":13500,\"total_minor\":463500},\"terms\":{\"review_days\":3,\"free_revisions\":2},\"freelancer\":{\"name\":\"سارة العتيبي\"},"
        + "\"milestones\":[{\"uuid\":\"m1\",\"position\":1,\"title\":\"الشعار\",\"amount_minor\":200000,\"status\":\"delivered\",\"held_minor\":206000,\"revisions_left\":2,\"revisions_max\":2,"
        + "\"checklist\":[{\"uuid\":\"c1\",\"text\":\"ثلاث مقترحات\",\"checked\":true},{\"uuid\":\"c2\",\"text\":\"ملف SVG\",\"checked\":false}],\"can\":{\"approve\":true,\"revision\":true,\"checklist_tick\":true,\"dispute\":true}},"
        + "{\"uuid\":\"m2\",\"position\":2,\"title\":\"دليل الهوية\",\"amount_minor\":250000,\"client_fee_minor\":7500,\"status\":\"unfunded\",\"can\":{\"fund\":true,\"cancel\":true}}],"
        + "\"wallet_available_minor\":120000,\"changes\":[],\"milestone_rules\":{\"max_count\":5},\"can\":{}}"

    static var rideQuotes: String {
        func q(_ id: String, _ slug: String, _ name: String, _ total: Int, _ seats: Int, bid: Bool = false) -> String {
            "{\"uuid\":\"\(id)\",\"fingerprint\":\"f\",\"category\":{\"slug\":\"\(slug)\",\"name\":\"\(name)\",\"capacity\":\(seats),\"cash_allowed\":true},"
                + "\"currency\":{\"code\":\"SAR\",\"symbol\":\"ر.س\",\"decimal_places\":2},\"pricing_mode\":\"\(bid ? "bidding" : "upfront")\","
                + (bid ? "\"bid\":{\"suggested_minor\":\(total),\"floor_minor\":\(total - 500),\"step_minor\":500}," : "\"total_minor\":\(total),")
                + "\"wallet_available_minor\":2000,\"route\":{\"distance_m\":8400,\"duration_s\":960},"
                + "\"lines\":[{\"code\":\"fare\",\"label\":\"الأجرة\",\"amount_minor\":\(total - 300)},{\"code\":\"customer_fee\",\"label\":\"رسوم الخدمة\",\"amount_minor\":300}],"
                + "\"policy_summary\":{\"items\":[{\"code\":\"cancel\",\"text\":\"الإلغاء مجاني خلال دقيقتين من القبول.\"}],\"refund\":{}}}"
        }
        return "[\(q("q1", "economy", "اقتصادي", 2350, 4)),\(q("q2", "comfort", "مريح", 3100, 4)),\(q("q3", "family", "عائلي", 4200, 6)),\(q("q4", "bid", "حدد سعرك", 2000, 4, bid: true))]"
    }

    static let sportsTeams = [
        "{\"id\":33,\"name\":\"الهلال\",\"code\":\"HIL\",\"color\":\"#1D4ED8\"}",
        "{\"id\":34,\"name\":\"النصر\",\"code\":\"NAS\",\"color\":\"#F59E0B\"}",
        "{\"id\":35,\"name\":\"الاتحاد\",\"code\":\"ITT\",\"color\":\"#111827\"}",
        "{\"id\":36,\"name\":\"الأهلي\",\"code\":\"AHL\",\"color\":\"#16A34A\"}",
    ]
    static let sportsLeague = "{\"id\":307,\"name\":\"دوري روشن السعودي\",\"country\":\"السعودية\",\"sport\":\"football\",\"type\":\"league\"}"

    static func sportsRow(_ id: String, _ h: Int, _ a: Int, status: String, minute: Int? = nil, hs: Int? = nil, as aw: Int? = nil, time: String = "21:00") -> String {
        "{\"id\":\"\(id)\",\"sport\":\"football\",\"competition\":\(sportsLeague),\"round\":\"الجولة 12\",\"home\":\(sportsTeams[h]),\"away\":\(sportsTeams[a]),"
            + "\"starts_at\":\"\(iso(hoursAgo: minute == nil ? -3 : 1))\",\"local_time\":\"\(time)\",\"status\":\"\(status)\",\"minute\":\(minute.map(String.init) ?? "null"),"
            + "\"home_score\":\(hs.map(String.init) ?? "null"),\"away_score\":\(aw.map(String.init) ?? "null"),\"synced_at\":\"\(iso(hoursAgo: 0))\"}"
    }

    static var sportsHome: String {
        let live = sportsRow("m1", 0, 1, status: "live", minute: 67, hs: 2, as: 1)
        let next = sportsRow("m2", 2, 3, status: "scheduled", time: "21:30")
        return "{\"date\":\"\(iso(hoursAgo: 0).prefix(10))\",\"sports\":[{\"key\":\"football\",\"name\":\"كرة القدم\",\"emoji\":\"⚽\"},{\"key\":\"f1\",\"name\":\"فورمولا 1\",\"emoji\":\"🏎️\"}],"
            + "\"live_count\":1,\"mine\":[\(live)],\"competitions\":[{\"competition\":\(sportsLeague),\"followed\":true,\"matches\":[\(live),\(next)]}],"
            + "\"following\":{\"teams\":[33],\"competitions\":[307]},\"preferences\":{\"celebration\":\"festive\",\"sounds\":true}}"
    }

    static var sportsMatch: String {
        var m = sportsRow("m1", 0, 1, status: "live", minute: 67, hs: 2, as: 1)
        m.removeLast()
        return m + ",\"venue\":\"المملكة أرينا\",\"city\":\"الرياض\",\"referee\":\"محمد الهويش\",\"periods\":[{\"label\":\"HT\",\"home\":1,\"away\":1}],"
            + "\"events\":[{\"minute\":12,\"side\":\"home\",\"type\":\"goal\",\"player\":\"سالم الدوسري\",\"assist\":\"مالكوم\"},{\"minute\":31,\"side\":\"away\",\"type\":\"yellow\",\"player\":\"تاليسكا\"},"
            + "{\"minute\":40,\"side\":\"away\",\"type\":\"penalty\",\"player\":\"رونالدو\"},{\"minute\":63,\"side\":\"home\",\"type\":\"goal\",\"player\":\"ميتروفيتش\"}],"
            + "\"statistics\":[{\"type\":\"Ball Possession\",\"home\":\"58%\",\"away\":\"42%\"},{\"type\":\"Total Shots\",\"home\":14,\"away\":9},{\"type\":\"Shots on Goal\",\"home\":6,\"away\":3},{\"type\":\"Corner Kicks\",\"home\":7,\"away\":2}],"
            + "\"following\":{\"home\":true,\"away\":false}}"
    }

    static var calItems: String {
        let day = String(iso(hoursAgo: 0).prefix(10))
        return """
        [{"id":"e1","type":"event","ref":"1","title":"اجتماع الفريق","starts_at":"\(iso(hoursAgo: -2))","ends_at":"\(iso(hoursAgo: -3))","date":"\(day)","location":"مكتب الرياض","reminders":[30]},
         {"id":"t1","type":"task","ref":"2","title":"دفع فاتورة الكهرباء","all_day":true,"date":"\(day)","done":false},
         {"id":"m1","type":"moment","ref":"3","title":"عيد الفطر","all_day":true,"date":"\(day)","emoji":"🌙","color":"#065F46"}]
        """
    }

    static var calToday: String {
        let day = String(iso(hoursAgo: 0).prefix(10))
        return """
        {"enabled":true,"date":"\(day)","next":\(calItemsArray[0]),"events":[\(calItemsArray[0])],"tasks":[\(calItemsArray[1])],"reminders":[],"moments":[\(calItemsArray[2])]}
        """
    }

    private static var calItemsArray: [String] {
        let day = String(iso(hoursAgo: 0).prefix(10))
        return [
            "{\"id\":\"e1\",\"type\":\"event\",\"ref\":\"1\",\"title\":\"اجتماع الفريق\",\"starts_at\":\"\(iso(hoursAgo: -2))\",\"ends_at\":\"\(iso(hoursAgo: -3))\",\"date\":\"\(day)\",\"location\":\"مكتب الرياض\"}",
            "{\"id\":\"t1\",\"type\":\"task\",\"ref\":\"2\",\"title\":\"دفع فاتورة الكهرباء\",\"all_day\":true,\"date\":\"\(day)\"}",
            "{\"id\":\"m1\",\"type\":\"moment\",\"ref\":\"3\",\"title\":\"عيد الفطر\",\"all_day\":true,\"date\":\"\(day)\",\"emoji\":\"🌙\",\"color\":\"#065F46\"}",
        ]
    }

    static let discoverEvent = """
    {"id":"d1","title":"مهرجان الرياض للموسيقى","category":{"id":1,"key":"music","name":"موسيقى","emoji":"🎵","color":"#7C3AED"},"city":{"id":1,"name":"الرياض","timezone":"Asia/Riyadh"},"venue":"بوليفارد","local_date":"2026-11-05","local_time":"20:00","is_free":false,"price_text":"150","currency":{"code":"SAR","symbol":"ر.س"},"status":"confirmed","verified":true,"source":"admin","interested_count":42,"description":"ليلة موسيقية مع أشهر الفنانين.","booking_url":"https://example.com"}
    """

    static var discoverHome: String {
        """
        {"country":"SA","ai":true,"can_submit":true,"categories":[{"id":1,"key":"music","name":"موسيقى","emoji":"🎵","color":"#7C3AED"},{"id":2,"key":"sport","name":"رياضة","emoji":"⚽","color":"#16A34A"}],
         "regions":{"items":[{"id":1,"name":"الرياض","events_count":12},{"id":2,"name":"مكة","events_count":5}],"meta":{"current_page":1,"has_more_pages":false}},
         "sections":[{"key":"for_you","items":[\(discoverEvent),\(discoverEvent.replacingOccurrences(of: "\"d1\"", with: "\"d2\""))],"has_more":false}]}
        """
    }

    static let momentsCenter = """
    {"enabled":true,"effects":"full","country":{"id":1,"code":"SA","name":"السعودية"},
     "active":[{"id":1,"key":"eid","name":"عيد الفطر","greeting":"كل عام وأنتم بخير","kind":"religious","start":"2026-10-28","end":"2026-10-30","days_left":0,"is_today":true,"is_visible":true,"primary_color":"#065F46","secondary_color":"#FBBF24","emoji":"🌙","animation":"lanterns"}],
     "upcoming":[{"id":2,"key":"national","name":"اليوم الوطني","kind":"national","start":"2026-11-05","end":"2026-11-05","days_left":8,"primary_color":"#166534","secondary_color":"#22C55E","emoji":"🇸🇦","animation":"flags"}],
     "personal":[{"id":"a1","kind":"birthday","title":"عيد ميلاد سارة","month":11,"day":2,"next":"2026-11-02","days_left":5,"turns":7,"remind_days_before":1,"look":{"primary_color":"#EC4899","secondary_color":"#8B5CF6","emoji":"🎂"}}]}
    """

    static let portalGroups = """
    [{"category":{"id":1,"name":"مطاعم","icon":null},"portals":[{"id":"p1","name":"مطعم البيت","description":"أكلات بيتية طازجة كل يوم","logo":null,"website_url":"https://example.com","category":{"id":1,"name":"مطاعم"},"views_count":12500},{"id":"p2","name":"برجر هاوس","description":null,"logo":null,"website_url":"https://example.com","category":{"id":1,"name":"مطاعم"},"views_count":840}]},
     {"category":{"id":2,"name":"أزياء","icon":null},"portals":[{"id":"p3","name":"متجر الأناقة","description":"ملابس وإكسسوارات","logo":null,"website_url":"https://example.com","category":{"id":2,"name":"أزياء"},"views_count":3100}]}]
    """

    static let notifications = """
    [{"id":"n1","title":"تم استلام تحويل","message":"وصلك 150 ر.س من محمد إلى محفظتك.","event":"wallet.transfer.received","created_at_iso":"\(iso(hoursAgo: 0.3))","read_at":null},
     {"id":"n2","title":"عرض خاص","message":"خصم 20٪ على خدمات الصيانة هذا الأسبوع فقط.","type":"promo","created_at_iso":"\(iso(hoursAgo: 5))","read_at":null},
     {"id":"n3","title":"تحديث الطلب","message":"الفني في الطريق إليك الآن.","type":"job_update","created_at_iso":"\(iso(hoursAgo: 30))","read_at":"x"},
     {"id":"n4","title":"تغيير الرقم السري","message":"تم تغيير رقم PIN للمحفظة.","event":"wallet.pin.changed","created_at_iso":"\(iso(hoursAgo: 200))","read_at":"x"}]
    """

    static let addresses = """
    [{"id":1,"type":"home","title":null,"building_number":"12","floor":"3","address_details":"شارع الملك فهد، حي العليا","landmark":"بجوار المسجد","is_default":true},
     {"id":2,"type":"work","title":null,"building_number":"7","floor":null,"address_details":"برج المملكة","landmark":null,"is_default":false},
     {"id":3,"type":"other","title":"بيت العائلة","building_number":null,"floor":null,"address_details":"حي النرجس","landmark":null,"is_default":false}]
    """

    static let devices = """
    [{"id":1,"device_name":"iPhone 15 Pro","platform":"ios","platform_label":"iOS","app_version":"1.0","country_code":"SA","current":true,"last_active_at":"\(iso(hoursAgo: 0.1))"},
     {"id":2,"device_name":"Galaxy S23","platform":"android","platform_label":"Android","app_version":"1.4.2","country_code":"SA","current":false,"last_active_at":"\(iso(hoursAgo: 30))"}]
    """

    static let securityEvents = """
    [{"id":1,"type":"login","label":"تسجيل دخول","description":"دخول جديد للحساب","warning":false,"device_name":"iPhone 15 Pro","ip":"10.0.0.4","country_code":"SA","created_at":"\(iso(hoursAgo: 0.2))"},
     {"id":2,"type":"otp_failed","label":"رمز خاطئ","description":"تم إدخال رمز تحقق خاطئ","warning":true,"device_name":"Galaxy S23","ip":"10.0.0.9","country_code":"SA","created_at":"\(iso(hoursAgo: 26))"}]
    """

    static let tickets = """
    [{"id":11,"number":"T-1042","title":"مشكلة في الدفع","status":"opened","accepts_replies":true,"last_message":{"sender":"support","body":"أهلاً بك","has_image":false,"created_at":"\(iso(hoursAgo: 1))"},"last_message_at":"\(iso(hoursAgo: 1))","created_at":"\(iso(hoursAgo: 5))"},
     {"id":12,"number":"T-1031","title":"تعديل العنوان","status":"resolved","accepts_replies":false,"last_message_at":"\(iso(hoursAgo: 50))","created_at":"\(iso(hoursAgo: 60))"}]
    """

    static let supportMessages = """
    [{"id":3,"sender":"support","body":"أهلاً بك، سنراجع عملية الدفع الآن.","agent_name":"سارة","is_auto":false,"created_at":"\(iso(hoursAgo: 1))"},
     {"id":2,"sender":"system","body":"استلمنا تذكرتك وسنرد عليك قريباً.","is_auto":true,"auto_kind":"ack","created_at":"\(iso(hoursAgo: 4.9))"},
     {"id":1,"sender":"user","body":"تم خصم المبلغ مرتين من بطاقتي","is_auto":false,"created_at":"\(iso(hoursAgo: 5))"}]
    """

    static let supportHelp = """
    {"greeting":"أهلاً! كيف نقدر نساعدك؟","items":[{"id":1,"title":"الدفع والمحفظة","answer":"اختر المشكلة:","children":[{"id":3,"title":"خصم مرتين","answer":"يرجع المبلغ تلقائياً خلال ٣ أيام عمل.","children":[]}]},{"id":2,"title":"الحساب","answer":null,"children":[]}]}
    """

    static let faqs = """
    [{"id":1,"question":"كيف أشحن المحفظة؟","answer":"من صفحة المحفظة اختر شحن ثم طريقة الدفع.","status":true},
     {"id":2,"question":"هل يمكنني تغيير رقم الجوال؟","answer":"نعم، من البيانات الشخصية.","status":true}]
    """

    static let appearance = """
    {"uses_default_colors":true,"dark_mode":"system","mobile_app_font_id":1,"font":{"id":1,"name":"Cairo","slug":"cairo","is_default":true},
     "available_fonts":[{"id":1,"name":"Cairo","slug":"cairo","is_default":true,"sort_order":1},{"id":2,"name":"Tajawal","slug":"tajawal","sort_order":2},{"id":3,"name":"IBM Plex Sans Arabic","slug":"ibm-plex","sort_order":3}],
     "default":{"light_tokens":{"primary":"#E50914","background":"#FFFFFF","textPrimary":"#111928"},"dark_tokens":{"primary":"#FF4D57","background":"#101216","textPrimary":"#F4F5F7"}}}
    """

    static let balance = """
    {"country_code":"SA","dial_code":"+966","phone_length":9,"phone_starts_with":"5","wallet_number":"10000000009",
     "wallet_number_formatted":"100 0000 0009","qr_payload":"dorr:wallet:SA:10000000009","currency_code":"SAR","currency_symbol":"ر.س",
     "total_minor":1254075,"withdrawable_minor":1004075,"spend_only_minor":250000,"held_minor":15000,
     "other_wallets":[{"country_code":"EG","currency_code":"EGP","total_minor":4520000}]}
    """

    static let services = """
    [{"id":1,"name":"Rides","module_name":"passenger_ride","image":null,"sort_order":1,"description":"<p>Book a ride in <b>seconds</b>.</p>","children":[{"id":11,"name":"Economy","module_name":"passenger_ride"},{"id":12,"name":"Family","module_name":"passenger_ride"}]},
     {"id":2,"name":"Restaurants","module_name":"restaurants","image":null,"sort_order":2},
     {"id":3,"name":"AI Assistant","module_name":"ai_assistant","image":null,"sort_order":3},
     {"id":4,"name":"Freelance","module_name":"freelance","image":null,"sort_order":4},
     {"id":5,"name":"Car wash","module_name":"car_wash","image":null,"sort_order":5}]
    """

    static let transactions = """
    [{"uuid":"a1b2c3d4-0001","type":"topup","type_label":"Top-up","direction":"credit","bucket":"withdrawable","amount_minor":50000,
      "balance_after_minor":1254075,"note":null,"counterparty":null,"created_at":"\(iso(hoursAgo: 1))"},
     {"uuid":"a1b2c3d4-0002","type":"transfer_in","type_label":"Transfer received","direction":"credit","bucket":"spend_only","amount_minor":25000,
      "balance_after_minor":1204075,"note":null,"counterparty":{"name":"S***** A****","phone":"•••••4567"},"created_at":"\(iso(hoursAgo: 5))"},
     {"uuid":"a1b2c3d4-0003","type":"service_payment","type_label":"Ride payment","direction":"debit","bucket":"withdrawable","amount_minor":3850,
      "balance_after_minor":1179075,"note":"Ride #4821","counterparty":null,"created_at":"\(iso(hoursAgo: 30))"},
     {"uuid":"a1b2c3d4-0004","type":"transfer_out","type_label":"Transfer sent","direction":"debit","bucket":"withdrawable","amount_minor":10000,
      "balance_after_minor":1182925,"note":null,"counterparty":{"name":"M***** K****","phone":"•••••8890"},"created_at":"\(iso(hoursAgo: 80))"},
     {"uuid":"a1b2c3d4-0005","type":"topup_bonus","type_label":"Top-up bonus","direction":"credit","bucket":"spend_only","amount_minor":2500,
      "balance_after_minor":1192925,"note":null,"counterparty":null,"created_at":"\(iso(hoursAgo: 82))"}]
    """

    static let pinStatus = """
    {"has_pin":true,"must_change":false,"is_frozen":false,"locked_until":null,
     "recovery":{"method":"password","ready":true,"email":null,"pending_email":null},"request":null}
    """

    static let methods = """
    [{"id":1,"code":"mada","gateway":"myfatoorah","name":"Mada / Visa","logo_url":null,"coming_soon":false},
     {"id":2,"code":"sandbox","gateway":"sandbox","name":"Test bank","logo_url":null,"coming_soon":false},
     {"id":3,"code":"urpay","gateway":"urpay","name":"URPay","logo_url":null,"coming_soon":true}]
    """

    static let quote = """
    {"paid_amount_minor":10000,"fee_minor":150,"bonus_minor":500,"withdrawable_minor":9850,"spend_only_minor":500,"total_credited_minor":10350}
    """

    static let recipient = """
    {"recipient_token":"tok_123","via":"phone","name_masked":"S***** A*****","phone":"+966 55 123 4567","wallet_number":null,
     "country_code":"SA","currency_code":"SAR","fee_percent":"2.5000","fee_payer":"sender","number_recently_changed":true}
    """

    static let coupons = """
    [{"code":"SPORTS10","kind":"percent","value":10,"currency_code":null},{"code":"WELCOME","kind":"fixed","value":2000,"currency_code":"SAR"}]
    """

    static let checkout = """
    {"id":"chk_1","purpose":"listing","title":"Featured listing — 30 days","subtitle":"Gold package","amount_minor":15000,
     "discount_minor":0,"payable_minor":15000,"coupons_allowed":true,"coupon":null,"currency_code":"SAR","status":"pending",
     "paid_via":null,"failure_reason":null,"wallet":{"available_minor":1239075,"enough":true,"shortfall_minor":0},
     "payment_methods":[{"id":1,"code":"mada","gateway":"myfatoorah","name":"Mada / Visa","logo_url":null,"coming_soon":false,"charge_minor":15225,"available":true},
                        {"id":3,"code":"urpay","gateway":"urpay","name":"URPay","logo_url":null,"coming_soon":true,"charge_minor":null,"available":false}],
     "payment":null}
    """

    private static func iso(hoursAgo: Double) -> String {
        let formatter = ISO8601DateFormatter()
        formatter.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
        return formatter.string(from: Date().addingTimeInterval(-hoursAgo * 3600))
    }
}
#endif
