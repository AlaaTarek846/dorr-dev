import SwiftUI

// androidApp `freelance/FreelanceTeamFavorites.kt` — FS-213: the freelancers' teams the client keeps (next to the freelancers he keeps), and
// inviting a team to one of his private projects. The invitation goes to the team's Owner, who answers and offers in the team's name. A
// project takes invitations while it is private and open for offers (FL-205) — the sheet lists those, and says so when there is none.

/// The heart on a team's page: keeps the team or lets it go.
@MainActor
struct TeamFavoriteHeart: View {
    @Environment(\.wa) private var wa
    let team: String
    let saved: Bool
    let onChanged: (Bool) -> Void
    @State private var busy = false

    var body: some View {
        Button {
            busy = true
            Task {
                let ok: Bool
                if saved { ok = (try? await FreelanceApi.removeTeamFavorite(team)) != nil } else { ok = (try? await FreelanceApi.addTeamFavorite(team)) != nil }
                if ok { onChanged(!saved) }
                busy = false
            }
        } label: {
            Image(systemName: saved ? "heart.fill" : "heart").font(.system(size: 20, weight: .semibold))
                .foregroundStyle(saved ? flRed : wa.settingsMut)
                .frame(width: 40, height: 40)
                .contentShape(Circle())
        }
        .buttonStyle(.plain)
        .disabled(busy)
        .accessibilityLabel(L(saved ? "fl_favorite_remove" : "fl_favorite_add"))
    }
}

/// The kept teams in the favorites list: logo, name, figures, «worked together»; tap for its page, the send icon invites it, the heart
/// lets it go. `onAppeared` passes each row's place on to the paging. (`teamFavoriteRows` on Android, a `LazyListScope` extension.)
struct TeamFavoriteRows: View {
    @Environment(\.wa) private var wa
    let rows: [FreelanceTeamFavoriteDto]
    let onOpen: (String) -> Void
    let onInvite: (FreelanceTeamFavoriteDto) -> Void
    let onRemove: (String) -> Void
    var onAppeared: (Int) -> Void = { _ in }

    var body: some View {
        ForEach(Array(rows.enumerated()), id: \.element.uuid) { i, t in
            HStack(spacing: 10) {
                Button { onOpen(t.uuid) } label: {
                    HStack(spacing: 10) {
                        TeamLogo(url: t.logoUrl, size: 46)
                        VStack(alignment: .leading, spacing: 3) {
                            HStack(spacing: 4) {
                                Text(verbatim: t.name ?? "—").font(.dorr(15, .bold)).foregroundStyle(wa.settingsInk).lineLimit(1)
                                if t.verified == true {
                                    Image(systemName: "checkmark.seal.fill").font(.system(size: 14)).foregroundStyle(flGreen).accessibilityLabel(L("fl_verified"))
                                }
                            }
                            let finished: String? = t.completedProjects.flatMap { $0 > 0 ? L("fl_st_completed_n", $0) : nil }
                            let figures: [String?] = [teamRatingText(FreelancerTeamBadgeDto(rating: t.rating, reviewsCount: t.reviewsCount)), finished]
                            Text(verbatim: figures.compactMap { $0 }.joined(separator: "  ·  ")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                            HStack(spacing: 6) {
                                FreelancerTeamPill()
                                if t.workedWith == true { TeamTag(text: L("fteam_worked_with"), color: flGreen) }
                                if t.acceptingProjects != true { TeamTag(text: L("fteam_not_accepting_short"), color: flAmber) }
                            }
                        }
                        Spacer(minLength: 0)
                    }
                    .contentShape(Rectangle())
                }
                .buttonStyle(.plain)
                if t.acceptingProjects == true {
                    Button { onInvite(t) } label: {
                        Image(systemName: "paperplane.fill").font(.system(size: 17)).foregroundStyle(wa.accent).frame(width: 36, height: 36).contentShape(Circle())
                    }
                    .buttonStyle(.plain)
                    .accessibilityLabel(L("fteam_invite"))
                }
                Button { onRemove(t.uuid) } label: {
                    Image(systemName: "heart.fill").font(.system(size: 18)).foregroundStyle(flRed).frame(width: 36, height: 36).contentShape(Circle())
                }
                .buttonStyle(.plain)
                .accessibilityLabel(L("fl_favorite_remove"))
            }
            .padding(14)
            .settingsSurface(18)
            .onAppear { onAppeared(i) }
        }
    }
}

private struct TeamTag: View {
    let text: String
    let color: Color

    var body: some View {
        Text(verbatim: text).font(.dorr(10.5, .bold)).foregroundStyle(color).lineLimit(1)
            .padding(.horizontal, 8).padding(.vertical, 2)
            .background(color.opacity(0.12), in: Capsule())
    }
}

/// «Invite the team to a project»: my private projects that take invitations now; a tap sends the invitation to the team's Owner. Says what
/// happened (sent, or already invited / not taking new projects) and stays open, so the next project is a tap away. Shown with
/// `.dorrDialog(isPresented:, bottom: true)`.
@MainActor
struct InviteTeamSheet: View {
    @Environment(\.wa) private var wa
    let team: String
    let teamName: String
    let onDismiss: () -> Void
    @State private var projects: [FreelanceProjectDto]?
    @State private var error: String?
    /// The last answer: its text and whether it went out.
    @State private var done: (text: String, ok: Bool)?
    @State private var busy: String?
    @State private var invited: Set<String> = []

    var body: some View {
        FlSheet(icon: "paperplane.fill", title: L("fteam_invite_title", teamName), text: L("fteam_invite_body")) {
            if let done {
                FlNotice(text: done.text, icon: done.ok ? "checkmark.circle.fill" : "exclamationmark.circle", color: done.ok ? flGreen : flAmber)
            }
            if let ps = projects {
                if ps.isEmpty {
                    FlHint(text: L("fteam_invite_none"))
                } else {
                    ForEach(ps) { p in row(p) }
                }
            } else if let error {
                FlNotice(text: error, icon: "exclamationmark.circle", color: flRed)
            } else {
                ForEach(0..<3, id: \.self) { _ in WaSkeleton(cornerRadius: 14).frame(height: 56) }
            }
            FlHint(text: L("fteam_invite_hint"))
        }
        .task(id: team) {
            do {
                projects = (try await FreelanceApi.projects(status: "published", perPage: 50).data ?? [])
                    .filter { $0.visibility == "private" && $0.invitations?.canInvite == true }
                error = nil
            } catch is CancellationError {
                return
            } catch {
                self.error = flError(error)
            }
        }
    }

    private func row(_ p: FreelanceProjectDto) -> some View {
        let sent = invited.contains(p.uuid)
        return Button { invite(p.uuid) } label: {
            HStack(spacing: 10) {
                Image(systemName: "lock.fill").font(.system(size: 17)).foregroundStyle(wa.accent)
                VStack(alignment: .leading, spacing: 0) {
                    Text(verbatim: p.title ?? "").font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk).lineLimit(1)
                    if let s = p.invitations {
                        Text(verbatim: L("fteam_invite_count", (s.sent ?? 0) + (s.accepted ?? 0), s.max ?? 0)).font(.dorr(11.5)).foregroundStyle(wa.settingsMut)
                    }
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                if busy == p.uuid {
                    ProgressView().tint(wa.accent).scaleEffect(0.8)
                } else if sent {
                    Image(systemName: "checkmark.circle.fill").font(.system(size: 19)).foregroundStyle(flGreen)
                } else {
                    Image(systemName: "paperplane.fill").font(.system(size: 16)).foregroundStyle(wa.accent)
                }
            }
            .padding(.horizontal, 14).padding(.vertical, 12)
            .background(wa.accent.opacity(sent ? 0.14 : 0.06), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .disabled(busy != nil || sent)
    }

    private func invite(_ project: String) {
        busy = project
        Task {
            do {
                let r = try await FreelanceApi.invite(project, teams: [team]).data?.result
                let reason = r?.skipped?.first?.reason
                if (r?.invited ?? 0) > 0 {
                    invited.insert(project)
                    done = (L("fteam_invite_sent", teamName), true)
                } else if reason == "already_invited" || reason == "already_offered" {
                    invited.insert(project)
                    done = (L("fteam_invite_already"), false)
                } else if reason == "team_not_accepting" {
                    done = (L("fteam_not_accepting"), false)
                } else {
                    done = (L("fl_error_generic"), false)
                }
            } catch {
                done = (flError(error), false)
            }
            busy = nil
        }
    }
}

/// The «Invite to a project» button on a team's page (only while it takes new projects).
struct InviteTeamAction: View {
    let team: String
    let teamName: String
    @State private var open = false

    var body: some View {
        FlAction(text: L("fteam_invite"), icon: "paperplane.fill", tone: .primary) { open = true }
            .dorrDialog(isPresented: $open, bottom: true) { InviteTeamSheet(team: team, teamName: teamName) { open = false } }
    }
}

/// The empty «Teams» tab.
struct TeamFavoritesEmpty: View {
    var body: some View {
        WaEmpty(icon: "person.3.fill", tone: .pink, title: L("fteam_fav_title"), text: L("fteam_fav_empty"))
    }
}
