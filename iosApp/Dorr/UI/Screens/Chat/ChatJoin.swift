import SwiftUI

// androidApp `ChatExtrasUi.kt` JoinGroupSheet + JoinDecisionBanner — joining a group by an invite link, and the admins'
// answer to my request.

/// An invite link was opened: the group's photo, name and size, then «Join» — or «Ask to join» when its admins approve
/// new members (then «Request sent · Cancel»).
struct JoinGroupSheet: View {
    @ObservedObject var host: ChatHost
    @ObservedObject private var ch = Ch.shared
    let token: String
    let onDismiss: () -> Void
    @State private var preview: InvitePreviewDto?
    @State private var failed: String?
    @State private var busy = false
    @State private var pop = false

    var body: some View {
        ChSheetCard {
            VStack(spacing: 0) {
                if let failed {
                    Image(systemName: "face.dashed").font(.system(size: 34)).foregroundStyle(ch.mut)
                        .frame(width: 64, height: 64).background(ch.mut.opacity(0.12), in: Circle())
                    Text(verbatim: failed.isEmpty ? L("ch_error_network") : failed).font(.dorr(15, .bold)).foregroundStyle(ch.ink).multilineTextAlignment(.center).padding(.top, 12)
                } else if let p = preview {
                    ChAvatar(url: p.avatar, name: p.name, key: p.conversationId, size: 92).scaleEffect(pop ? 1 : 0.6)
                        .onAppear { withAnimation(.spring(response: 0.45, dampingFraction: 0.45)) { pop = true } }
                    Text(verbatim: p.name).font(.dorr(20, .heavy)).foregroundStyle(ch.ink).multilineTextAlignment(.center).padding(.top, 12)
                    HStack(spacing: 4) {
                        Image(systemName: "person.3.fill").font(.system(size: 13)).foregroundStyle(ch.mut)
                        Text(L("ch_members", p.membersCount ?? 0)).font(.dorr(13)).foregroundStyle(ch.mut)
                    }
                    .padding(.top, 4)
                    if let d = p.description, !d.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
                        Text(verbatim: d).font(.dorr(13.5)).foregroundStyle(ch.mut).multilineTextAlignment(.center).lineLimit(4).padding(.top, 10)
                    }
                    if p.approveJoins == true && p.isMember != true {
                        HStack(spacing: 6) {
                            Image(systemName: "hourglass.tophalf.filled").font(.system(size: 14)).foregroundStyle(Color(hex: 0xD97706))
                            Text(L("ch_join_needs_approval")).font(.dorr(12.5, .bold)).foregroundStyle(Color(hex: 0xB45309))
                        }
                        .padding(.horizontal, 12).padding(.vertical, 8)
                        .background(Color(hex: 0xF59E0B).opacity(0.12), in: RoundedRectangle(cornerRadius: 12))
                        .padding(.top, 14)
                    }
                    action(p).padding(.top, 20).animation(.easeInOut(duration: 0.25), value: p.requestStatus)
                } else {
                    ProgressView().tint(ch.red).frame(height: 180)
                }
            }
            .frame(maxWidth: .infinity)
            .padding(.bottom, 6)
        }
        .task(id: token) {
            do { preview = try await ChatApi.previewInvite(token).data } catch { failed = error.apiFailure.message ?? "" }
        }
    }

    @ViewBuilder
    private func action(_ p: InvitePreviewDto) -> some View {
        if p.isMember == true {
            ChPrimaryButton(text: L("ch_open_group"), icon: "person.3.fill") {
                onDismiss()
                host.push(.conversation(p.conversationId))
            }
        } else if p.requestStatus == "pending" {
            VStack(spacing: 10) {
                HStack(spacing: 6) {
                    Image(systemName: "checkmark.circle.fill").font(.system(size: 18)).foregroundStyle(ch.success)
                    Text(L("ch_join_request_sent")).font(.dorr(15, .heavy)).foregroundStyle(ch.ink)
                }
                Button {
                    Task {
                        _ = try? await ChatApi.cancelJoin(token)
                        preview?.requestStatus = nil
                    }
                } label: {
                    Text(L("ch_join_cancel")).font(.dorr(14, .heavy)).foregroundStyle(ch.red).padding(.horizontal, 14).padding(.vertical, 8)
                }
                .buttonStyle(.plain)
            }
        } else {
            let ask = p.approveJoins == true
            ChPrimaryButton(text: L(ask ? "ch_join_ask" : "ch_join_group"), icon: ask ? "paperplane.fill" : "person.3.fill", enabled: !busy) { join(p) }
        }
    }

    private func join(_ p: InvitePreviewDto) {
        busy = true
        Task {
            do {
                let result = try await ChatApi.joinByInvite(token).data ?? nil
                if result?["status"]?.str == "pending" {
                    preview?.requestStatus = "pending"
                } else {
                    await host.refreshList()
                    onDismiss()
                    host.push(.conversation(result?["id"]?.str ?? p.conversationId))
                }
            } catch {
                if let m = error.apiFailure.message { host.showToast(m, error: true) }
            }
            busy = false
        }
    }
}

/// «You're in!» / «Not this time» — after the admins answered my request; gone by itself after 6 s.
struct JoinDecisionBanner: View {
    @ObservedObject private var ch = Ch.shared
    let decision: JoinDecision
    let onOpen: () -> Void
    let onDismiss: () -> Void

    var body: some View {
        let color = decision.approved ? ch.success : ch.mut
        HStack(spacing: 12) {
            Image(systemName: decision.approved ? "checkmark.circle.fill" : "face.dashed").font(.system(size: 20)).foregroundStyle(color)
                .frame(width: 42, height: 42).background(color.opacity(0.14), in: Circle())
            VStack(alignment: .leading, spacing: 1) {
                Text(L(decision.approved ? "ch_join_approved" : "ch_join_rejected")).font(.dorr(14.5, .heavy)).foregroundStyle(ch.ink)
                Text(verbatim: decision.groupName).font(.dorr(12.5)).foregroundStyle(ch.mut).lineLimit(1)
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            Button(action: onDismiss) {
                Image(systemName: "xmark").font(.system(size: 14, weight: .bold)).foregroundStyle(ch.mut).frame(width: 30, height: 30)
            }
            .buttonStyle(.plain)
        }
        .padding(14)
        .background(ch.surface, in: RoundedRectangle(cornerRadius: 20, style: .continuous))
        .shadow(color: .black.opacity(0.15), radius: 14, y: 4)
        .contentShape(Rectangle())
        .onTapGesture { if decision.approved { onOpen() } }
        .padding(.horizontal, 14).padding(.vertical, 10)
        .task(id: decision) {
            try? await Task.sleep(nanoseconds: 6_000_000_000)
            if !Task.isCancelled { onDismiss() }
        }
    }
}
