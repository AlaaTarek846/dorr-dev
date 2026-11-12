import Foundation

/// providerApp `net/Workspace.kt` — the establishments I hold a seat in and the invitations waiting for me (`GET workspace`),
/// kept in memory for the header, the account's switcher and the invitations page. Read when the app opens, when a notice
/// arrives and after I answer an invitation, create an establishment or leave one — never on a timer.
@MainActor
final class Workspace: ObservableObject {
    static let shared = Workspace()

    @Published private(set) var state: WorkspaceDto?

    @discardableResult
    func reload() async -> ApiResult<WorkspaceDto> {
        let result = await WorkspaceApi.workspace()
        if case .ok(let data, _) = result { state = data }
        return result
    }

    /// The establishment to work in from `workspace`: the one chosen last time when I still hold a seat in it, else the first
    /// (the server lists my own establishment first). Stores it as the `X-Establishment` of every request; nil when I hold no seat.
    @discardableResult
    func pick(_ workspace: WorkspaceDto) -> String? {
        let seats = (workspace.memberships ?? []).filter { !($0.establishment?.uuid ?? "").trimmingCharacters(in: .whitespaces).isEmpty }
        let chosen = seats.first { $0.establishment?.uuid == Session.shared.establishment } ?? seats.first
        let uuid = chosen?.establishment?.uuid
        Session.shared.chooseEstablishment(uuid)
        return uuid
    }

    func clear() { state = nil }
}
