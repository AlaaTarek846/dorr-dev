import SwiftUI

// freelanceApp `ui/components/Page.kt`, `EndlessScroll.kt`.

/// A page of the app: the soft backdrop, the title with its back button, and a scrolling column of cards. `onNearEnd`
/// is for a paged list: it is called when the bottom comes near (an invisible marker under the content).
struct Page<Content: View>: View {
    let title: String
    let onBack: () -> Void
    var onNearEnd: (() -> Void)?
    /// Drawn inside a hub tab under the tab's own title and parts: no title or back button of its own ([EmbeddedPage]).
    var embedded = false
    @ViewBuilder let content: () -> Content

    var body: some View {
        if embedded {
            EmbeddedPage(onNearEnd: onNearEnd, content: content)
        } else {
            page
        }
    }

    private var page: some View {
        ZStack {
            PinkBackdrop()
            VStack(spacing: 0) {
                SubHeader(title: title, onBack: onBack)
                ScrollView {
                    VStack(spacing: 14) {
                        content()
                        NearEndMarker(onNearEnd: onNearEnd)
                    }
                    .frame(maxWidth: .infinity)
                    .padding(.horizontal, 18)
                    .padding(.bottom, 28)
                }
                .scrollDismissesKeyboard(.interactively)
            }
        }
        .navigationBarHidden(true)
    }
}

/// A white card (a soft shadow by day, a hairline at night) holding a group of fields.
struct Card<Content: View>: View {
    @ViewBuilder let content: () -> Content

    var body: some View {
        VStack(alignment: .leading, spacing: 12) { content() }
            .padding(16)
            .frame(maxWidth: 440, alignment: .leading)
            .frame(maxWidth: .infinity)
            .settingsSurface(20)
    }
}

struct FieldLabel: View {
    @Environment(\.wa) private var wa
    let text: String

    var body: some View {
        Text(verbatim: text).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk)
    }
}

/// A page with nothing to show yet: a big icon on a soft disc in the middle, a title and a line under it.
struct EmptyState: View {
    @Environment(\.wa) private var wa
    let icon: String
    let title: String
    let body_: String

    init(icon: String, title: String, body: String) {
        self.icon = icon
        self.title = title
        self.body_ = body
    }

    var body: some View {
        VStack(spacing: 0) {
            Image(systemName: icon).font(.system(size: 40)).foregroundStyle(wa.settingsMut)
                .frame(width: 100, height: 100)
                .background(wa.night ? wa.darkWell : wa.red.opacity(0.08), in: Circle())
            Text(verbatim: title).font(.dorr(16, .semibold)).foregroundStyle(wa.settingsInk).multilineTextAlignment(.center).padding(.top, 24)
            Text(verbatim: body_).font(.dorr(13)).foregroundStyle(wa.settingsMut).multilineTextAlignment(.center).lineSpacing(4).padding(.top, 8)
        }
        .frame(maxWidth: .infinity)
        .padding(.horizontal, 32)
    }
}

/// A tab page: its title (no back arrow) over the content, which scrolls and asks for more near the bottom.
struct TabPage<Content: View>: View {
    let title: String
    var onBack: (() -> Void)?
    var onNearEnd: (() -> Void)?
    /// Drawn inside a hub tab under the tab's own title and parts ([EmbeddedPage]).
    var embedded = false
    @ViewBuilder let content: () -> Content

    var body: some View {
        if embedded {
            EmbeddedPage(onNearEnd: onNearEnd, content: content)
        } else {
            page
        }
    }

    private var page: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 0) {
                // A tab with a back button goes back to the Home tab, like the pages over the tabs.
                if let onBack {
                    SubHeader(title: title, onBack: onBack)
                } else {
                    SettingsScreenTitle(title: title).padding(.horizontal, 14).padding(.top, 14)
                }
                VStack(alignment: .leading, spacing: 12) {
                    content()
                    NearEndMarker(onNearEnd: onNearEnd)
                }
                .padding(.horizontal, 14)
                .padding(.top, 12)
            }
            .padding(.bottom, 16)
        }
    }
}

/// A page drawn inside a tab of the bar under the tab's own title and parts (the hub tabs): no title or back button of its
/// own, just the scrolling column, asking for more near the bottom.
struct EmbeddedPage<Content: View>: View {
    var onNearEnd: (() -> Void)?
    @ViewBuilder let content: () -> Content

    var body: some View {
        ScrollView {
            VStack(spacing: 12) {
                content()
                NearEndMarker(onNearEnd: onNearEnd)
            }
            .frame(maxWidth: .infinity)
            .padding(.horizontal, 16)
            .padding(.top, 4)
            .padding(.bottom, 20)
        }
        .scrollDismissesKeyboard(.interactively)
    }
}

/// The head of a form: an icon on a soft tile, a title and a line saying what the form is for.
struct FormIntro: View {
    @Environment(\.wa) private var wa
    let icon: String
    let title: String
    let text: String

    var body: some View {
        HStack(spacing: 12) {
            Image(systemName: icon).font(.system(size: 22, weight: .semibold)).foregroundStyle(wa.red)
                .frame(width: 52, height: 52)
                .background(wa.red.opacity(0.12), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
            VStack(alignment: .leading, spacing: 3) {
                Text(verbatim: title).font(.dorr(19, .heavy)).foregroundStyle(wa.settingsInk)
                if !text.trimmingCharacters(in: .whitespaces).isEmpty {
                    Text(verbatim: text).font(.dorr(13)).foregroundStyle(wa.settingsMut).lineSpacing(3)
                }
            }
            Spacer(minLength: 0)
        }
    }
}

/// The fields of a form laid straight on the page, not boxed in a card.
struct FormGroup<Content: View>: View {
    @ViewBuilder let content: () -> Content

    var body: some View {
        VStack(alignment: .leading, spacing: 14) { content() }.frame(maxWidth: .infinity, alignment: .leading)
    }
}

/// `NearEndEffect`: an invisible line under a scrolling page; when it comes on screen the next page is asked for (also
/// when everything loaded fits on the screen). The callback must ignore a call it can't use — see `PagedRows.loadMore`.
struct NearEndMarker: View {
    var onNearEnd: (() -> Void)?

    var body: some View {
        if let onNearEnd {
            Color.clear.frame(height: 1).onAppear(perform: onNearEnd)
        }
    }
}

/// Under a paged list: a small spinner while the next page comes, or «try again» when it failed.
struct PagedFooter<T>: View {
    @Environment(\.wa) private var wa
    @ObservedObject var paged: PagedRows<T>
    let onRetry: () -> Void

    var body: some View {
        if paged.loaded && !paged.rows.isEmpty {
            if paged.failure != nil {
                Button {
                    paged.retry()
                    onRetry()
                } label: {
                    Text(L("common_retry")).font(.dorr(13, .bold)).foregroundStyle(wa.red).padding(12)
                }
                .buttonStyle(.plain)
                .frame(maxWidth: .infinity)
            } else if paged.loading || paged.hasMore {
                ProgressView().tint(wa.red).frame(maxWidth: .infinity)
            }
        }
    }
}
