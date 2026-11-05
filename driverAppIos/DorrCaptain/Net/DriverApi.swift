import Foundation

// driverApp `net/DriverApi.kt` — the Retrofit interface as one enum of calls, same function names, paths, methods, headers,
// query names and bodies. `@Body JsonObject` → `body: JsonBody`; `@Header("Idempotency-Key") key` → `key:` sent as that header;
// `@Multipart` → `Api.upload` with the parts built by the caller (`FilePart`, field name included, like `MultipartBody.Part`).
// A call answering `ApiEnvelope<JsonElement>` answers `ApiResult<JSONAny>`: read it or ignore it (`data: null` is a success, `.null`).

/// `/api/driver/v1/…` — the driver's own API (guard `driver_api`, Modules/Driver + Modules/Rides).
enum DriverApi {
    private static let d = Api.driver

    private static func idem(_ key: String) -> [String: String] { ["Idempotency-Key": key] }

    private static func pin(_ pin: String?) -> [String: String] { pin.map { ["X-Wallet-Pin": $0] } ?? [:] }

    // MARK: - account

    static func requestOtp(body: PhoneBody) async -> ApiResult<OtpSent> { await Api.call("POST", d + "auth/otp", body: body.json) }

    static func resendOtp(body: PhoneBody) async -> ApiResult<OtpSent> { await Api.call("POST", d + "auth/resend", body: body.json) }

    static func verify(body: VerifyBody) async -> ApiResult<SignedIn> { await Api.call("POST", d + "auth/verify", body: body.json) }

    static func me() async -> ApiResult<DriverDto> { await Api.call("GET", d + "auth/me") }

    static func logout() async -> ApiResult<Ignored> { await Api.call("POST", d + "auth/logout") }

    /// The countries open for sign-in (flag, dial code, phone length / first digits), the same list as the DORR app's.
    static func countries() async -> ApiResult<[CountryDto]> { await Api.call("GET", "general/v1/countries/dropdown") }

    /// The caller's country by IP (the default one when it can't be told), in the same shape.
    static func detectCountry() async -> ApiResult<CountryDto> { await Api.call("GET", "general/v1/countries/detect") }

    /// The platform's colours, the same ones the DORR app reads (public — also before signing in).
    static func appearanceDefaults() async -> ApiResult<AppearanceDefaultsDto> { await Api.call("GET", "general/v1/mobile-appearance-defaults") }

    static func registerDevice(body: JsonBody) async -> ApiResult<JSONAny> { await Api.json("POST", d + "notifications/devices", body: body) }

    /// `@HTTP(method = "DELETE", hasBody = true)`.
    static func unregisterDevice(body: JsonBody) async -> ApiResult<JSONAny> { await Api.json("DELETE", d + "notifications/devices", body: body) }

    // MARK: - real-time + the notification list

    static func realtimeConfig() async -> ApiResult<RealtimeConfigDto> { await Api.call("GET", d + "realtime-config") }

    static func notifications(page: Int = 1, perPage: Int = 30) async -> ApiResult<[NotificationDto]> {
        await Api.call("GET", d + "notifications", query: ["page": String(page), "per_page": String(perPage)])
    }

    static func unreadCount() async -> ApiResult<UnreadCountDto> { await Api.call("GET", d + "notifications/unread-count") }

    static func markRead(id: String) async -> ApiResult<UnreadCountDto> { await Api.call("POST", d + "notifications/\(id)/read") }

    static func markAllRead() async -> ApiResult<UnreadCountDto> { await Api.call("POST", d + "notifications/read-all") }

    // MARK: - signing up

    static func onboarding() async -> ApiResult<OnboardingDto> { await Api.call("GET", d + "onboarding") }

    static func onboardingOptions() async -> ApiResult<OnboardingOptionsDto> { await Api.call("GET", d + "onboarding/options") }

    static func updateProfile(body: JsonBody) async -> ApiResult<OnboardingDto> { await Api.call("PUT", d + "profile", body: body) }

    static func addVehicle(body: JsonBody) async -> ApiResult<OnboardingDto> { await Api.call("POST", d + "vehicles", body: body) }

    static func updateVehicle(id: Int, body: JsonBody) async -> ApiResult<OnboardingDto> { await Api.call("PUT", d + "vehicles/\(id)", body: body) }

    /// `@PartMap fields` + `@Part front` + `@Part back?`.
    static func uploadDocument(fields: [String: String], front: FilePart, back: FilePart?,
                               onProgress: (@Sendable (Double) -> Void)? = nil) async -> ApiResult<OnboardingDto> {
        await Api.upload(d + "documents", fields: fields, files: [front] + [back].compactMap { $0 }, onProgress: onProgress)
    }

    static func submitOnboarding() async -> ApiResult<OnboardingDto> { await Api.call("POST", d + "onboarding/submit") }

    /// O-64: `{on}` — no vehicle (the vehicle step and its documents are skipped).
    static func noVehicle(body: JsonBody) async -> ApiResult<OnboardingDto> { await Api.call("POST", d + "onboarding/no-vehicle", body: body) }

    static func regulator() async -> ApiResult<RegulatorDto> { await Api.call("GET", d + "regulator") }

    static func registerRegulator() async -> ApiResult<RegulatorDto> { await Api.call("POST", d + "regulator/register") }

    static func checkRegulator() async -> ApiResult<RegulatorDto> { await Api.call("POST", d + "regulator/check") }

    // MARK: - presence & location

    static func presence() async -> ApiResult<PresenceDto> { await Api.call("GET", d + "presence") }

    static func goOnline(body: JsonBody) async -> ApiResult<PresenceDto> { await Api.call("POST", d + "presence/online", body: body) }

    static func goOffline() async -> ApiResult<PresenceDto> { await Api.call("POST", d + "presence/offline") }

    static func pause(body: JsonBody) async -> ApiResult<PresenceDto> { await Api.call("POST", d + "presence/pause", body: body) }

    static func lastRide(body: JsonBody) async -> ApiResult<PresenceDto> { await Api.call("POST", d + "presence/last-ride", body: body) }

    /// The server answers an empty list here, not a presence — read it as plain JSON.
    static func gps(body: JsonBody) async -> ApiResult<JSONAny> { await Api.json("POST", d + "presence/gps", body: body) }

    static func locations(body: JsonBody) async -> ApiResult<JSONAny> { await Api.json("POST", d + "locations/batch", body: body) }

    // MARK: - requests & rides

    static func offers() async -> ApiResult<[OfferDto]> { await Api.call("GET", d + "offers") }

    static func accept(key: String, uuid: String) async -> ApiResult<TripDto> {
        await Api.call("POST", d + "offers/\(uuid)/accept", headers: idem(key))
    }

    /// Bidding: my price for the request (`{price_minor}`; equal to the rider's = accepting it).
    static func bid(key: String, uuid: String, body: JsonBody) async -> ApiResult<JSONAny> {
        await Api.json("POST", d + "offers/\(uuid)/bid", body: body, headers: idem(key))
    }

    static func decline(uuid: String, body: JsonBody) async -> ApiResult<JSONAny> { await Api.json("POST", d + "offers/\(uuid)/decline", body: body) }

    /// The ride running now, or `.null` when there is none (read it with `has("uuid")` / `decoded(TripDto.self)`).
    static func currentRide() async -> ApiResult<JSONAny> { await Api.json("GET", d + "rides/current") }

    // MARK: - safety (D-141, D-142)

    static func shareRide(uuid: String) async -> ApiResult<ShareDto> { await Api.call("POST", d + "rides/\(uuid)/share") }

    static func sos(uuid: String, body: JsonBody) async -> ApiResult<SosDto> { await Api.call("POST", d + "rides/\(uuid)/sos", body: body) }

    // MARK: - the captain's wallet (same wallet as the rider's)

    static func wallet() async -> ApiResult<WalletDto> { await Api.call("GET", d + "wallet") }

    static func walletTransactions(page: Int = 1, perPage: Int = 25, type: String? = nil, direction: String? = nil,
                                   from: String? = nil, to: String? = nil) async -> ApiResult<[WalletRowDto]> {
        await Api.call("GET", d + "wallet/transactions", query: [
            "page": String(page), "per_page": String(perPage), "type": type, "direction": direction, "from": from, "to": to,
        ])
    }

    static func walletTransaction(uuid: String) async -> ApiResult<WalletDetailDto> { await Api.call("GET", d + "wallet/transactions/\(uuid)") }

    static func walletPin() async -> ApiResult<WalletPinDto> { await Api.call("GET", d + "wallet/pin") }

    static func createWalletPin(body: JsonBody) async -> ApiResult<JSONAny> { await Api.json("POST", d + "wallet/pin", body: body) }

    static func verifyWalletPin(pin: String) async -> ApiResult<JSONAny> { await Api.json("POST", d + "wallet/pin/verify", headers: Self.pin(pin)) }

    // Top-up with a card or a gateway — paid on the gateway's page; the result arrives as a real-time event.
    static func paymentMethods() async -> ApiResult<[PaymentMethodDto]> { await Api.call("GET", d + "wallet/payment-methods") }

    static func topupQuote(body: JsonBody) async -> ApiResult<TopupQuoteDto> { await Api.call("POST", d + "wallet/topups/quote", body: body) }

    static func topup(pin: String, key: String, body: JsonBody) async -> ApiResult<TopupDto> {
        await Api.call("POST", d + "wallet/topups", body: body, headers: Self.pin(pin).merging(idem(key)) { a, _ in a })
    }

    static func topupStatus(uuid: String) async -> ApiResult<TopupDto> { await Api.call("GET", d + "wallet/topups/\(uuid)") }

    static func confirmTopup(pin: String, uuid: String, body: JsonBody) async -> ApiResult<TopupDto> {
        await Api.call("POST", d + "wallet/topups/\(uuid)/confirm", body: body, headers: Self.pin(pin))
    }

    static func withdrawalMethods() async -> ApiResult<[WithdrawalMethodDto]> { await Api.call("GET", d + "wallet/withdrawal-methods") }

    static func addWithdrawalMethod(pin: String, body: JsonBody) async -> ApiResult<WithdrawalMethodDto> {
        await Api.call("POST", d + "wallet/withdrawal-methods", body: body, headers: Self.pin(pin))
    }

    static func deleteWithdrawalMethod(id: Int) async -> ApiResult<JSONAny> { await Api.json("DELETE", d + "wallet/withdrawal-methods/\(id)") }

    static func withdrawals(page: Int = 1, perPage: Int = 25) async -> ApiResult<[WithdrawalDto]> {
        await Api.call("GET", d + "wallet/withdrawals", query: ["page": String(page), "per_page": String(perPage)])
    }

    static func withdraw(pin: String, key: String, body: JsonBody) async -> ApiResult<WithdrawalDto> {
        await Api.call("POST", d + "wallet/withdrawals", body: body, headers: Self.pin(pin).merging(idem(key)) { a, _ in a })
    }

    // MARK: - the Safety Center (D-140..160)

    static func safety() async -> ApiResult<DriverSafetyDto> { await Api.call("GET", d + "safety") }

    static func addContact(body: JsonBody) async -> ApiResult<JSONAny> { await Api.json("POST", d + "safety/contacts", body: body) }

    static func updateContact(id: Int, body: JsonBody) async -> ApiResult<JSONAny> { await Api.json("PUT", d + "safety/contacts/\(id)", body: body) }

    static func deleteContact(id: Int) async -> ApiResult<JSONAny> { await Api.json("DELETE", d + "safety/contacts/\(id)") }

    static func unblock(id: Int) async -> ApiResult<JSONAny> { await Api.json("DELETE", d + "safety/blocks/\(id)") }

    static func shareWithContacts(uuid: String, body: JsonBody) async -> ApiResult<JSONAny> {
        await Api.json("POST", d + "rides/\(uuid)/contacts/share", body: body)
    }

    static func blockRider(uuid: String, body: JsonBody) async -> ApiResult<JSONAny> { await Api.json("POST", d + "rides/\(uuid)/block", body: body) }

    static func report(uuid: String, body: JsonBody) async -> ApiResult<JSONAny> { await Api.json("POST", d + "rides/\(uuid)/report", body: body) }

    static func answerCheck(uuid: String, check: String, body: JsonBody) async -> ApiResult<SosDto> {
        await Api.call("POST", d + "rides/\(uuid)/safety-checks/\(check)", body: body)
    }

    // MARK: - the rider's change of the trip (D-131..133)

    static func acceptChange(uuid: String, change: String) async -> ApiResult<JSONAny> { await Api.json("POST", d + "rides/\(uuid)/changes/\(change)/accept") }

    static func rejectChange(uuid: String, change: String) async -> ApiResult<JSONAny> { await Api.json("POST", d + "rides/\(uuid)/changes/\(change)/reject") }

    // MARK: - airport queue (D-276..D-283)

    static func airportQueue() async -> ApiResult<AirportQueueDto> { await Api.call("GET", d + "airport-queue") }

    static func joinAirportQueue() async -> ApiResult<AirportQueueDto> { await Api.call("POST", d + "airport-queue/join") }

    static func leaveAirportQueue() async -> ApiResult<AirportQueueDto> { await Api.call("POST", d + "airport-queue/leave") }

    static func restrictedZones() async -> ApiResult<[RestrictedZoneDto]> { await Api.call("GET", d + "restricted-zones") }

    // MARK: - scheduled rides (D-260..269)

    static func scheduled() async -> ApiResult<[ScheduledRideDto]> { await Api.call("GET", d + "scheduled") }

    static func scheduledMine() async -> ApiResult<[ScheduledRideDto]> { await Api.call("GET", d + "scheduled/mine") }

    static func reserve(key: String, uuid: String) async -> ApiResult<ScheduledRideDto> {
        await Api.call("POST", d + "scheduled/\(uuid)/reserve", headers: idem(key))
    }

    static func releaseReservation(uuid: String, body: JsonBody) async -> ApiResult<JSONAny> {
        await Api.json("POST", d + "scheduled/\(uuid)/release", body: body)
    }

    static func startScheduled(key: String, uuid: String) async -> ApiResult<TripDto> {
        await Api.call("POST", d + "scheduled/\(uuid)/start", headers: idem(key))
    }

    /// O-03: which map to draw — Google, or Mapbox with its public token and style.
    static func mapConfig() async -> ApiResult<MapConfigDto> { await Api.call("GET", d + "map-config") }

    // O-30: With Me — where I'm going, how far off my road, the riders in the car.
    static func pool() async -> ApiResult<PoolStateDto> { await Api.call("GET", d + "pool") }

    static func openPool(body: JsonBody) async -> ApiResult<PoolStateDto> { await Api.call("POST", d + "pool", body: body) }

    static func closePool() async -> ApiResult<PoolStateDto> { await Api.call("DELETE", d + "pool") }

    static func ride(uuid: String) async -> ApiResult<TripDto> { await Api.call("GET", d + "rides/\(uuid)") }

    static func arrive(key: String, uuid: String, body: JsonBody) async -> ApiResult<TripDto> {
        await Api.call("POST", d + "rides/\(uuid)/arrive", body: body, headers: idem(key))
    }

    static func start(key: String, uuid: String, body: JsonBody) async -> ApiResult<TripDto> {
        await Api.call("POST", d + "rides/\(uuid)/start", body: body, headers: idem(key))
    }

    static func stop(uuid: String, seq: Int, body: JsonBody) async -> ApiResult<TripDto> {
        await Api.call("POST", d + "rides/\(uuid)/stops/\(seq)", body: body)
    }

    static func end(key: String, uuid: String, body: JsonBody) async -> ApiResult<TripDto> {
        await Api.call("POST", d + "rides/\(uuid)/end", body: body, headers: idem(key))
    }

    static func collectCash(uuid: String, body: JsonBody) async -> ApiResult<TripDto> {
        await Api.call("POST", d + "rides/\(uuid)/collect-cash", body: body)
    }

    static func cancel(key: String, uuid: String, body: JsonBody) async -> ApiResult<JSONAny> {
        await Api.json("POST", d + "rides/\(uuid)/cancel", body: body, headers: idem(key))
    }

    static func noShow(key: String, uuid: String) async -> ApiResult<TripDto> {
        await Api.call("POST", d + "rides/\(uuid)/no-show", headers: idem(key))
    }

    static func rate(uuid: String, body: JsonBody) async -> ApiResult<JSONAny> { await Api.json("POST", d + "rides/\(uuid)/rate", body: body) }

    static func cancelReasons() async -> ApiResult<[ReasonDto]> { await Api.call("GET", d + "rides/cancel-reasons") }

    static func ratingTags() async -> ApiResult<[TagDto]> { await Api.call("GET", d + "rides/rating-tags") }

    /// O-58: where requests come from — the last hour's requests around me, in ~1 km cells.
    static func demandHeat(lat: Double, lng: Double) async -> ApiResult<[HeatCellDto]> {
        await Api.call("GET", d + "demand-heat", query: ["lat": String(lat), "lng": String(lng)])
    }

    /// O-47: «why don't requests reach me?» — every dispatch condition, passed or not.
    static func dispatchCheck() async -> ApiResult<DispatchCheckDto> { await Api.call("GET", d + "dispatch-check") }

    /// O-09: an in-app voice call with the rider (LiveKit).
    static func startCall(uuid: String) async -> ApiResult<TripCallStartDto> { await Api.call("POST", d + "rides/\(uuid)/calls") }

    static func acceptCall(uuid: String, call: String) async -> ApiResult<TripCallStartDto> {
        await Api.call("POST", d + "rides/\(uuid)/calls/\(call)/accept")
    }

    static func endCall(uuid: String, call: String) async -> ApiResult<TripCallDto> { await Api.call("POST", d + "rides/\(uuid)/calls/\(call)/end") }

    static func messages(uuid: String) async -> ApiResult<ChatDto> { await Api.call("GET", d + "rides/\(uuid)/messages") }

    static func send(uuid: String, body: JsonBody) async -> ApiResult<MessageDto> { await Api.call("POST", d + "rides/\(uuid)/messages", body: body) }

    // MARK: - settings and the account (D-304, D-313, D-314, D-316..D-318)

    static func alerts() async -> ApiResult<AlertsDto> { await Api.call("GET", d + "account/alerts") }

    static func saveAlerts(body: JsonBody) async -> ApiResult<AlertsDto> { await Api.call("PUT", d + "account/alerts", body: body) }

    static func devices() async -> ApiResult<[DeviceDto]> { await Api.call("GET", d + "account/devices") }

    static func forgetDevice(id: Int) async -> ApiResult<[DeviceDto]> { await Api.call("DELETE", d + "account/devices/\(id)") }

    /// `X-Wallet-Pin` is left out when `pin` is nil (Retrofit drops a null header).
    static func startPhoneChange(pin: String?, body: JsonBody) async -> ApiResult<JSONAny> {
        await Api.json("POST", d + "account/phone", body: body, headers: Self.pin(pin))
    }

    static func confirmPhoneChange(body: JsonBody) async -> ApiResult<JSONAny> { await Api.json("POST", d + "account/phone/confirm", body: body) }

    static func closeCheck() async -> ApiResult<CloseCheckDto> { await Api.call("GET", d + "account/close") }

    static func closeAccount(body: JsonBody) async -> ApiResult<JSONAny> { await Api.json("POST", d + "account/close", body: body) }

    static func legal() async -> ApiResult<[LegalDocDto]> { await Api.call("GET", d + "account/legal") }

    static func acceptLegal(body: JsonBody) async -> ApiResult<[LegalDocDto]> { await Api.call("POST", d + "account/legal/accept", body: body) }

    /// D-303: delete a notification, or every read one.
    static func deleteNotification(id: String) async -> ApiResult<JSONAny> { await Api.json("DELETE", d + "notifications/\(id)") }

    static func clearReadNotifications() async -> ApiResult<JSONAny> { await Api.json("DELETE", d + "notifications/read") }

    // MARK: - D-285..D-293: the help centre, my tickets and their conversations

    /// `q` is left out when nil.
    static func supportHelp(q: String?) async -> ApiResult<SupportHelpDto> { await Api.call("GET", d + "support/help", query: ["q": q]) }

    static func supportTickets() async -> ApiResult<[SupportTicketDto]> { await Api.call("GET", d + "support/tickets") }

    static func supportTicket(id: Int) async -> ApiResult<SupportTicketDto> { await Api.call("GET", d + "support/tickets/\(id)") }

    static func openSupportTicket(fields: [String: String], image: FilePart?) async -> ApiResult<SupportTicketDto> {
        await Api.upload(d + "support/tickets", fields: fields, files: [image].compactMap { $0 })
    }

    static func setSupportStatus(id: Int, body: JsonBody) async -> ApiResult<SupportTicketDto> {
        await Api.call("PATCH", d + "support/tickets/\(id)/status", body: body)
    }

    static func supportMessages(id: Int) async -> ApiResult<[SupportMessageDto]> { await Api.call("GET", d + "support/tickets/\(id)/messages") }

    static func sendSupportMessage(id: Int, fields: [String: String], image: FilePart?) async -> ApiResult<JSONAny> {
        Api.nullIsOk(await Api.upload(d + "support/tickets/\(id)/messages", fields: fields, files: [image].compactMap { $0 }))
    }

    // MARK: - D-294..D-297: riders' lost items in my car

    static func lostItems() async -> ApiResult<[LostItemDto]> { await Api.call("GET", d + "lost-items") }

    static func answerLostItem(uuid: String, body: JsonBody) async -> ApiResult<LostItemDto> {
        await Api.call("POST", d + "lost-items/\(uuid)/answer", body: body)
    }

    static func lostItemMessage(uuid: String, body: JsonBody) async -> ApiResult<LostItemDto> {
        await Api.call("POST", d + "lost-items/\(uuid)/messages", body: body)
    }

    static func lostItemReturned(uuid: String, photo: FilePart?) async -> ApiResult<LostItemDto> {
        await Api.upload(d + "lost-items/\(uuid)/returned", files: [photo].compactMap { $0 })
    }

    // MARK: - work services, service status, payouts and statements

    // D-216..D-224: my payout safeguards; D-227, D-228: monthly statements.
    /// O-85: what this captain's vehicle can do — the app shows only these services.
    static func workServices() async -> ApiResult<WorkServicesDto> { await Api.call("GET", d + "work-services") }

    static func chooseWorkService(key: String, body: JsonBody) async -> ApiResult<WorkServicesDto> {
        await Api.call("PUT", d + "work-services/\(key)", body: body)
    }

    /// D-329..D-344: maintenance, safe mode, switched-off service / categories, emergency review, the last withdrawal.
    static func serviceStatus() async -> ApiResult<ServiceStatusDto> { await Api.call("GET", d + "service-status") }

    static func payoutStatus() async -> ApiResult<PayoutStatusDto> { await Api.call("GET", d + "payouts/status") }

    static func requestInternationalPayouts(body: JsonBody) async -> ApiResult<PayoutStatusDto> {
        await Api.call("POST", d + "payouts/international", body: body)
    }

    static func invoices() async -> ApiResult<[InvoiceDto]> { await Api.call("GET", d + "invoices") }

    static func invoice(month: String) async -> ApiResult<InvoiceDto> { await Api.call("GET", d + "invoices/\(month)") }

    /// D-299: no sign-in — a code to the number, then the freeze.
    static func emergencyFreezeRequest(body: JsonBody) async -> ApiResult<JSONAny> { await Api.json("POST", d + "emergency/freeze/request", body: body) }

    static func emergencyFreezeConfirm(body: JsonBody) async -> ApiResult<JSONAny> { await Api.json("POST", d + "emergency/freeze/confirm", body: body) }

    /// D-5, D-6, D-239..D-259: my rank, my numbers, my record.
    static func rank() async -> ApiResult<RankInfoDto> { await Api.call("GET", d + "rank") }

    /// `{text}` — one appeal on a note / warning / rank change.
    static func appealNote(id: Int, body: JsonBody) async -> ApiResult<PerformanceNoteDto> {
        await Api.call("POST", d + "rank/notes/\(id)/appeal", body: body)
    }

    static func earnings() async -> ApiResult<EarningsDto> { await Api.call("GET", d + "earnings") }

    static func drivingHours() async -> ApiResult<DrivingHoursDto> { await Api.call("GET", d + "driving-hours") }

    // MARK: - cases (D-229..D-238)

    static func rideHistory(page: Int = 1) async -> ApiResult<DriverRideListDto> { await Api.call("GET", d + "rides", query: ["page": String(page)]) }

    static func caseOptions() async -> ApiResult<CaseOptionsDto> { await Api.call("GET", d + "cases/options") }

    static func cases() async -> ApiResult<[CaseDto]> { await Api.call("GET", d + "cases") }

    static func openCase(body: JsonBody) async -> ApiResult<CaseDto> { await Api.call("POST", d + "cases", body: body) }

    static func caseOf(uuid: String) async -> ApiResult<CaseDto> { await Api.call("GET", d + "cases/\(uuid)") }

    static func caseMessage(uuid: String, body: JsonBody) async -> ApiResult<CaseDto> { await Api.call("POST", d + "cases/\(uuid)/messages", body: body) }

    static func caseFile(uuid: String, file: FilePart) async -> ApiResult<CaseDto> { await Api.upload(d + "cases/\(uuid)/files", files: [file]) }

    static func appealCase(uuid: String, body: JsonBody) async -> ApiResult<CaseDto> { await Api.call("POST", d + "cases/\(uuid)/appeal", body: body) }

    // MARK: - driving preferences (D-32..D-37)

    static func preferences() async -> ApiResult<PreferencesDto> { await Api.call("GET", d + "preferences") }

    static func savePreferences(body: JsonBody) async -> ApiResult<PreferencesDto> { await Api.call("PUT", d + "preferences", body: body) }

    static func earningsReport(period: String, from: String? = nil, to: String? = nil) async -> ApiResult<EarningsReportDto> {
        await Api.call("GET", d + "earnings/report", query: ["period": period, "from": from, "to": to])
    }

    // MARK: - Block 06: DORR AI for the captain

    /// Hot zones now (everyone); the forecast and booked-ahead demand on a DORR AI plan.
    static func demand(lat: Double, lng: Double) async -> ApiResult<DemandDto> {
        await Api.call("GET", d + "demand", query: ["lat": String(lat), "lng": String(lng)])
    }

    /// DORR Driver AI's cards: where to work, clashes with my bookings, my daily goal.
    static func aiAssistant(lat: Double?, lng: Double?) async -> ApiResult<DriverAiDto> {
        await Api.call("GET", d + "ai/assistant", query: ["lat": lat.map { String($0) }, "lng": lng.map { String($0) }])
    }

    /// `{question, lat?, lng?}`
    static func aiAsk(body: JsonBody) async -> ApiResult<DriverAiAnswerDto> { await Api.call("POST", d + "ai/ask", body: body) }

    /// «راحتي»: `{item, reason}` — one of the rider's requests can't be done here.
    static func comfortUnable(uuid: String, body: JsonBody) async -> ApiResult<TripDto> {
        await Api.call("POST", d + "rides/\(uuid)/comfort/unable", body: body)
    }

    /// The same DORR AI plans as the DORR app, paid from my wallet with my PIN.
    static func aiPlans() async -> ApiResult<[AiPlanDto]> { await Api.call("GET", d + "ai-subscription/plans") }

    static func aiSubscribe(pin: String, body: JsonBody) async -> ApiResult<JSONAny> {
        await Api.json("POST", d + "ai-subscription/subscribe", body: body, headers: Self.pin(pin))
    }
}

// MARK: - plain JSON answers

extension Api {
    /// A call answering `ApiEnvelope<JsonElement>`. Gson reads `"data": null` as `JsonNull` — a success — so a success
    /// without data is `.ok(.null)` here, as on Android (e.g. `rides/current` with no ride running).
    static func json(_ method: String, _ path: String, query: [String: String?] = [:], body: JsonBody? = nil,
                     headers: [String: String] = [:]) async -> ApiResult<JSONAny> {
        nullIsOk(await call(method, path, query: query, body: body, headers: headers))
    }

    /// `call` fails a 2xx whose envelope has no `data`; for a `JsonElement` answer that is `.ok(.null)`.
    /// (The server's refusals come with a 4xx / 5xx status, so a 2xx refusal is not expected here.)
    static func nullIsOk(_ result: ApiResult<JSONAny>) -> ApiResult<JSONAny> {
        if case .failed(let e) = result, (200..<300).contains(e.httpStatus), e.errorCode == nil, e.fieldErrors.isEmpty {
            return .ok(.null, message: e.message)
        }
        return result
    }
}

// MARK: - bodies and DTOs of the account calls (declared in DriverApi.kt)

struct PhoneBody {
    let dialCode: String
    let phone: String

    var json: JsonBody { ["dial_code": dialCode, "phone": phone] }
}

struct VerifyBody {
    let dialCode: String
    let phone: String
    let code: String

    var json: JsonBody { ["dial_code": dialCode, "phone": phone, "code": code] }
}

struct OtpSent: Decodable, Equatable {
    var maskedPhone: String?
    var isNewDriver: Bool?
    var resendCooldownSeconds: Int?
}

struct FlagDto: Decodable, Equatable, Identifiable, Hashable {
    var id: Int?
    var code: String?
}

struct CountryDto: Decodable, Equatable, Identifiable, Hashable {
    var id: Int?
    var code: String?
    var name: String?
    var dialCode: String?
    var phoneLength: Int?
    var phoneStartsWith: String?
    var isDefault: Bool?
    var flag: FlagDto?
}

/// Token names are camelCase (`textPrimary`), so the decoder's snake_case conversion leaves them as the server sent them.
struct AppearanceDefaultsDto: Decodable, Equatable {
    var lightTokens: [String: String]?
    var darkTokens: [String: String]?
}

struct SignedIn: Decodable, Equatable {
    var driver: DriverDto?
    var token: String?
}

struct DriverDto: Decodable, Equatable {
    var uuid: String?
    var name: String?
    var phone: String?
    var status: String?
    var statusReason: String?
    var avatarUrl: String?
    var residency: String?
}
