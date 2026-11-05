// providerApp `duty/MoveTrackingService.kt`
import Combine
import CoreLocation
import Foundation

/// Starts and stops `MoveTrackingService`. The truck's position is sent **only** while the driver's job is in one of `tracked`
/// (docs/moving_business.md §5, task T10). The job and its establishment are kept on the phone so tracking can come back by itself.
@MainActor
enum MoveTracking {
    /// The statuses in which the customer follows the truck on the map.
    static let tracked: Set<String> = ["driver_on_way", "arrived", "loading", "in_transit", "unloading"]

    private static let keyJob = "dorr_provider_tracking.job_uuid"
    private static let keyEstablishment = "dorr_provider_tracking.establishment_uuid"

    static var job: String? { UserDefaults.standard.string(forKey: keyJob) }

    static var establishment: String? { UserDefaults.standard.string(forKey: keyEstablishment) }

    /// Sets tracking to what this job's status says. Call it from a screen (the app in front): iOS only lets background location
    /// start from there. Without the location permission nothing starts; the driver's page says so and asks again.
    static func follow(_ uuid: String, status: String?) {
        if let status, tracked.contains(status) {
            if hasLocationPermission() { start(uuid) }
        } else if job == uuid {
            stop()
        }
    }

    /// One catch-up when the app opens or reconnects: back on after the phone killed the app, off when the job ended while away.
    static func sync() async {
        guard let jobs = await MovingApi.myJobs().value else { return }
        if let running = jobs.first(where: { tracked.contains($0.status ?? "") }) {
            if hasLocationPermission() { start(running.uuid) }
        } else if establishment == Session.shared.establishment {
            // A job of another establishment isn't in this list: only this establishment's job is known to have ended.
            stop()
        }
    }

    private static func start(_ uuid: String) {
        UserDefaults.standard.set(uuid, forKey: keyJob)
        if let current = Session.shared.establishment {
            UserDefaults.standard.set(current, forKey: keyEstablishment)
        } else {
            UserDefaults.standard.removeObject(forKey: keyEstablishment)
        }
        MoveTrackingService.shared.run()
    }

    static func stop() {
        UserDefaults.standard.removeObject(forKey: keyJob)
        UserDefaults.standard.removeObject(forKey: keyEstablishment)
        MoveTrackingService.shared.shutDown()
    }
}

/// The truck's position while a job runs, even with the app in the background: the iOS twin of Android's foreground service of type
/// location (CoreLocation with background updates and the system's location indicator instead of the notification; points kept and
/// sent in batches, retried while there is no network), without the shift and without any ride request. It reads nothing on a timer:
/// it only **sends** positions, and it learns that the job ended from the move's live channel (`moving.changed`), from one read when
/// the connection returns, and from the screens (`MoveTracking.follow`).
@MainActor
final class MoveTrackingService: NSObject, CLLocationManagerDelegate {
    static let shared = MoveTrackingService()

    private static let intervalSeconds: TimeInterval = 5
    private static let flushNanoseconds: UInt64 = 8_000_000_000
    private static let maxPoints = 100

    private let manager = CLLocationManager()
    private var pending: [CLLocation] = []
    private var tracked: String?
    private var flushTask: Task<Void, Never>?
    private var watchTask: Task<Void, Never>?
    private var connection: AnyCancellable?

    /// Android's `onStartCommand`: tracks the saved job; a second call for the same job changes nothing.
    func run() {
        guard let uuid = MoveTracking.job, Session.shared.token != nil, hasLocationPermission() else {
            shutDown()
            return
        }
        if uuid == tracked { return }
        cancelWork()
        pending.removeAll()
        tracked = uuid
        flushTask = Task { [weak self] in await self?.flushLoop(uuid) }
        // `$connected` answers at once and on every change: one read each time, then the move's live channel again.
        connection = Realtime.shared.$connected.removeDuplicates().sink { [weak self] _ in
            guard let self else { return }
            self.watchTask?.cancel()
            self.watchTask = Task { [weak self] in await self?.watchJob(uuid) }
        }
        track()
    }

    /// Android's `onDestroy`.
    func shutDown() {
        manager.stopUpdatingLocation()
        manager.allowsBackgroundLocationUpdates = false
        cancelWork()
        pending.removeAll()
        tracked = nil
    }

    private func cancelWork() {
        flushTask?.cancel()
        watchTask?.cancel()
        connection?.cancel()
        flushTask = nil
        watchTask = nil
        connection = nil
    }

    private func track() {
        manager.delegate = self
        manager.desiredAccuracy = kCLLocationAccuracyBest
        manager.distanceFilter = kCLDistanceFilterNone
        manager.activityType = .automotiveNavigation
        manager.pausesLocationUpdatesAutomatically = false
        // Needs the `location` background mode in Info.plist; started with the app in front, it carries on behind it.
        manager.allowsBackgroundLocationUpdates = true
        manager.showsBackgroundLocationIndicator = true
        manager.startUpdatingLocation()
    }

    private func add(_ locations: [CLLocation]) {
        guard tracked != nil else { return }
        for location in locations {
            // One point every five seconds, as Android asks of the fused provider (CoreLocation answers about once a second).
            if let last = pending.last, location.timestamp.timeIntervalSince(last.timestamp) < Self.intervalSeconds { continue }
            pending.append(location)
        }
        // The server keeps the newest point of a batch and takes at most this many: older ones are worth nothing.
        if pending.count > Self.maxPoints { pending.removeFirst(pending.count - Self.maxPoints) }
    }

    /// Batches out every few seconds; a batch that failed (no network) stays and goes with the next one.
    private func flushLoop(_ uuid: String) async {
        while !Task.isCancelled {
            try? await Task.sleep(nanoseconds: Self.flushNanoseconds)
            if Task.isCancelled { return }
            let points = pending
            if points.isEmpty { continue }
            let body: [[String: Any]] = points.map { point in
                var row: [String: Any] = ["lat": point.coordinate.latitude, "lng": point.coordinate.longitude]
                if point.course >= 0 { row["heading"] = Int(point.course) % 360 }
                return row
            }
            let sent = await MovingApi.locations(establishment: MoveTracking.establishment, uuid, points: body)
            if Task.isCancelled { return }
            switch sent {
            case .delivered:
                // What arrived while the batch was on its way stays for the next one.
                pending.removeAll { held in points.contains { $0 === held } }
            case .refused(403), .refused(404):
                // The job is no longer this driver's (replaced, or removed from the establishment).
                MoveTracking.stop()
                return
            default:
                continue
            }
        }
    }

    /// The move's live channel says when the job leaves the running states; one read each time the connection comes back.
    private func watchJob(_ uuid: String) async {
        if let now = await MovingApi.request(uuid).value?.status, !MoveTracking.tracked.contains(now) {
            if !Task.isCancelled { MoveTracking.stop() }
            return
        }
        for await event in Realtime.shared.watchMove(uuid) {
            if event.name == "moving.changed", let status = event.data?["status"]?.str, !MoveTracking.tracked.contains(status) {
                MoveTracking.stop()
                return
            }
        }
    }

    // MARK: - CLLocationManagerDelegate

    nonisolated func locationManager(_ manager: CLLocationManager, didUpdateLocations locations: [CLLocation]) {
        Task { @MainActor in self.add(locations) }
    }

    nonisolated func locationManager(_ manager: CLLocationManager, didFailWithError error: Error) {
        // A moment without a fix is normal; the updates carry on by themselves.
    }
}
