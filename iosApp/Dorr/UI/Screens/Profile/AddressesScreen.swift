import SwiftUI

// androidApp `profile/AddressesScreen.kt`.

private struct SavedAddress: Equatable, Identifiable {
    var id: Int
    var kind: String
    var label = ""
    var line = ""
    var building = ""
    var floor = ""
    var landmark = ""
    var isDefault = false

    init(id: Int, kind: String, label: String = "", line: String = "", building: String = "", floor: String = "", landmark: String = "", isDefault: Bool = false) {
        self.id = id
        self.kind = kind
        self.label = label
        self.line = line
        self.building = building
        self.floor = floor
        self.landmark = landmark
        self.isDefault = isDefault
    }

    init(_ dto: AddressDto) {
        self.init(id: dto.id, kind: dto.type, label: dto.title ?? "", line: dto.addressDetails ?? "", building: dto.buildingNumber ?? "",
                  floor: dto.floor ?? "", landmark: dto.landmark ?? "", isDefault: dto.isDefault)
    }

    var request: SaveAddressRequest {
        func orNil(_ s: String) -> String? { s.trimmingCharacters(in: .whitespaces).isEmpty ? nil : s }
        return SaveAddressRequest(type: kind, title: orNil(label), buildingNumber: orNil(building), floor: orNil(floor),
                                  addressDetails: orNil(line), landmark: orNil(landmark), isDefault: isDefault)
    }
}

private enum AddressListState { case loading, empty, content }

/// The light page behind the list and the editor (the pink backdrop at night).
private struct AddressesBackground: View {
    @Environment(\.wa) private var wa
    var body: some View {
        if wa.night {
            PinkBackdrop()
        } else {
            GeometryReader { proxy in
                // Compose's verticalGradient(endY = 500px) — about 190 pt on the phone, then flat.
                let stop = min(190 / max(proxy.size.height, 1), 1)
                LinearGradient(stops: [
                    .init(color: Color(hex: 0xFFE0E5), location: 0),
                    .init(color: Color(hex: 0xFFF2F4), location: stop / 3),
                    .init(color: Color(hex: 0xF9FAFB), location: stop * 2 / 3),
                    .init(color: Color(hex: 0xF9FAFB), location: 1),
                ], startPoint: .top, endPoint: .bottom)
            }
            .ignoresSafeArea()
        }
    }
}

@MainActor
struct AddressesScreen: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let onBack: () -> Void

    @State private var query = ""
    @State private var debouncedQuery = ""
    @State private var addresses: [SavedAddress] = []
    @State private var loading = true
    @State private var page = 1
    @State private var hasMore = false
    @State private var reloadKey = 0
    @State private var editing: SavedAddress?
    @State private var adding = false
    @State private var deleting: SavedAddress?
    @State private var saving = false
    @State private var toast: String?
    @State private var toastShown = ""

    private var inEditor: Bool { adding || editing != nil }

    var body: some View {
        ZStack {
            if inEditor {
                AddressEditor(initial: editing, saving: saving, onBack: { closeEditor() }, onSave: save)
                    .transition(.asymmetric(insertion: .move(edge: .trailing).combined(with: .opacity),
                                            removal: .move(edge: .trailing).combined(with: .opacity)))
            } else {
                list
                    .transition(.asymmetric(insertion: .offset(x: -130).combined(with: .opacity),
                                            removal: .offset(x: -130).combined(with: .opacity)))
            }
            AddressToastHost(toast: toast, last: toastShown)
        }
        .animation(.easeInOut(duration: 0.3), value: inEditor)
        .task(id: query) {
            try? await Task.sleep(nanoseconds: 400_000_000)
            guard !Task.isCancelled, debouncedQuery != query else { return }
            debouncedQuery = query
            page = 1
        }
        // A reconnect restarts the list from the first page: appending a fresh page onto stale rows would duplicate them.
        .onChange(of: network.reconnectTick) { _ in page = 1 }
        .task(id: "\(debouncedQuery)|\(page)|\(reloadKey)|\(network.reconnectTick)") { await load() }
        .task(id: toast) {
            guard toast != nil else { return }
            try? await Task.sleep(nanoseconds: 2_200_000_000)
            guard !Task.isCancelled else { return }
            withAnimation(.easeIn(duration: 0.25)) { toast = nil }
        }
        .dorrDialog(isPresented: Binding(get: { deleting != nil }, set: { if !$0 { deleting = nil } })) {
            if let item = deleting {
                DeleteAddressDialog(name: kindTitle(item), onDismiss: { deleting = nil }, onConfirm: { confirmDelete(item) })
            }
        }
    }

    private var list: some View {
        let state: AddressListState = loading && addresses.isEmpty ? .loading : (addresses.isEmpty ? .empty : .content)
        return ZStack {
            AddressesBackground()
            VStack(spacing: 0) {
                AddressesTopHeader(title: L("addr_title"), onBack: onBack).padding(.top, 16)
                CustomSearchPill(query: $query, placeholder: L("addr_search")).padding(.top, 16)
                ZStack {
                    switch state {
                    case .loading:
                        ProgressView().tint(wa.red).scaleEffect(1.2).frame(maxWidth: .infinity, maxHeight: .infinity).transition(.opacity)
                    case .empty:
                        EmptyAddressView(isSearching: !debouncedQuery.trimmingCharacters(in: .whitespaces).isEmpty,
                                         onAddClick: { adding = true }, onClearSearch: { query = "" })
                            .frame(maxWidth: .infinity, maxHeight: .infinity)
                            .transition(.opacity)
                    case .content:
                        ScrollView {
                            LazyVStack(spacing: 14) {
                                ForEach(addresses) { item in
                                    AddressCard(item: item, onSelect: { setDefault(item) }, onEdit: { editing = item }, onDelete: {
                                        // The default address is protected: it can only be replaced, never deleted.
                                        if item.isDefault { showToast(L("addr_toast_cannot_delete_default")) } else { deleting = item }
                                    })
                                    .transition(.opacity)
                                }
                                if hasMore {
                                    ShowMoreButton(loading: loading && !addresses.isEmpty) { page += 1 }
                                }
                            }
                            .padding(.vertical, 4)
                            .animation(.easeInOut(duration: 0.25), value: addresses)
                        }
                        .transition(.opacity)
                    }
                }
                .animation(.easeInOut(duration: 0.26), value: state)
                .padding(.top, 16)
                Spacer().frame(height: 14)
                // Only when addresses exist (the empty state has its own add action).
                if !addresses.isEmpty {
                    SettingsPrimaryButton(text: L("addr_add")) { adding = true }
                        .padding(.bottom, 20)
                }
            }
            .padding(.horizontal, 20)
        }
    }

    private func kindTitle(_ item: SavedAddress) -> String {
        switch item.kind {
        case "home": return L("addr_home")
        case "work": return L("addr_work")
        default: return item.label.trimmingCharacters(in: .whitespaces).isEmpty ? L("addr_other") : item.label
        }
    }

    private func load() async {
        loading = true
        do {
            let q = debouncedQuery.trimmingCharacters(in: .whitespaces)
            let envelope = try await AddressApi.list(search: q.isEmpty ? nil : debouncedQuery, page: page)
            let rows = (envelope.data ?? []).map(SavedAddress.init)
            addresses = page == 1 ? rows : addresses + rows
            hasMore = envelope.pagination?.hasMorePages == true
            loading = false
        } catch {
            if error is CancellationError { return }
            // No error card on purpose: offline is covered app-wide and a reconnect reloads. A first-page failure
            // keeps the spinner; a later page toasts and keeps the rows on screen.
            if page != 1 {
                AppToast.show(error.apiFailure.message ?? L("wallet_error_generic"))
                loading = false
            }
        }
    }

    private func refreshFirstPage() {
        page = 1
        reloadKey += 1
    }

    private func showToast(_ message: String) {
        toastShown = message
        withAnimation(.easeOut(duration: 0.3)) { toast = message }
    }

    private func closeEditor() {
        adding = false
        editing = nil
    }

    private func save(_ draft: SavedAddress) {
        Task {
            saving = true
            let wasEditing = editing
            do {
                if let wasEditing {
                    _ = try await AddressApi.update(wasEditing.id, draft.request)
                } else {
                    _ = try await AddressApi.store(draft.request)
                }
                saving = false
                closeEditor()
                refreshFirstPage()
                showToast(L(wasEditing != nil ? "addr_toast_updated" : "addr_toast_saved"))
            } catch {
                saving = false
                AppToast.show(error.apiFailure.message ?? L("wallet_error_generic"))
            }
        }
    }

    private func setDefault(_ item: SavedAddress) {
        Task {
            do {
                _ = try await AddressApi.setDefault(item.id, SetDefaultRequest(isDefault: true))
                refreshFirstPage()
                showToast(L("addr_toast_default"))
            } catch {
                AppToast.show(error.apiFailure.message ?? L("wallet_error_generic"))
            }
        }
    }

    private func confirmDelete(_ item: SavedAddress) {
        // Second guard: the row could have become the default while the dialog was open.
        deleting = nil
        if item.isDefault {
            showToast(L("addr_toast_cannot_delete_default"))
            return
        }
        Task {
            do {
                _ = try await AddressApi.delete(item.id)
                refreshFirstPage()
                showToast(L("addr_toast_deleted"))
            } catch {
                AppToast.show(error.apiFailure.message ?? L("wallet_error_generic"))
            }
        }
    }
}

/// The delete confirmation: a soft trash icon, a red question, the item's name, and solid "yes" beside an
/// outlined "cancel". Also used to confirm removing the profile photo.
struct DeleteAddressDialog: View {
    @Environment(\.wa) private var wa
    var title = L("addr_delete_ask")
    let name: String
    let onDismiss: () -> Void
    let onConfirm: () -> Void

    var body: some View {
        DialogCard {
            VStack(spacing: 0) {
                Image(systemName: "trash").font(.system(size: 26, weight: .medium)).foregroundStyle(wa.red)
                    .frame(width: 64, height: 64)
                    .background(wa.red.opacity(0.14), in: RoundedRectangle(cornerRadius: 20, style: .continuous))
                Text(verbatim: title).font(.dorr(18, .heavy)).foregroundStyle(wa.red).multilineTextAlignment(.center).padding(.top, 16)
                Text(verbatim: name).font(.dorr(14, .medium)).foregroundStyle(wa.night ? wa.mut : Color(hex: 0x4B5563))
                    .multilineTextAlignment(.center).lineLimit(1).padding(.top, 6)
                HStack(spacing: 12) {
                    Button(action: onConfirm) {
                        Text(L("addr_yes")).font(.dorr(15, .bold)).foregroundStyle(.white)
                            .frame(maxWidth: .infinity, minHeight: 52)
                            .background(wa.red, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                            .shadow(color: wa.red.opacity(0.4), radius: 8, y: 3)
                    }
                    .buttonStyle(.plain)
                    Button(action: onDismiss) {
                        Text(L("common_cancel")).font(.dorr(15, .bold)).foregroundStyle(wa.red)
                            .frame(maxWidth: .infinity, minHeight: 52)
                            .background(wa.night ? Color.clear : Color.white, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                            .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(wa.night ? wa.line : wa.red.opacity(0.3), lineWidth: 1))
                    }
                    .buttonStyle(.plain)
                }
                .padding(.top, 22)
            }
            .padding(.horizontal, 22)
            .padding(.vertical, 26)
        }
        .onTapGesture {}
    }
}

private struct AddressesTopHeader: View {
    @Environment(\.wa) private var wa
    let title: String
    let onBack: () -> Void

    var body: some View {
        HStack {
            SettingsScreenTitle(title: title)
            Spacer(minLength: 8)
            Button(action: onBack) {
                Image(systemName: "chevron.forward").font(.system(size: 17, weight: .bold)).foregroundStyle(wa.red)
                    .frame(width: 42, height: 42)
                    .background(wa.settingsCard, in: Circle())
                    .overlay(Circle().stroke(wa.night ? wa.line : .clear, lineWidth: 1))
                    .shadow(color: wa.night ? .clear : Color.black.opacity(0.12), radius: 6, y: 2)
            }
            .buttonStyle(.plain)
        }
        .padding(.vertical, 4)
    }
}

private struct CustomSearchPill: View {
    @Environment(\.wa) private var wa
    @Binding var query: String
    let placeholder: String

    var body: some View {
        HStack(spacing: 10) {
            Image(systemName: "magnifyingglass").font(.system(size: 17, weight: .semibold)).foregroundStyle(wa.red)
            ZStack(alignment: .leading) {
                if query.isEmpty { Text(verbatim: placeholder).font(.dorr(14)).foregroundStyle(wa.settingsMut) }
                TextField("", text: $query).font(.dorr(14, .medium)).foregroundStyle(wa.settingsInk).tint(wa.red)
            }
            if !query.isEmpty {
                Button { query = "" } label: {
                    Image(systemName: "xmark").font(.system(size: 13, weight: .bold)).foregroundStyle(Color(hex: 0x9CA3AF))
                }
                .buttonStyle(.plain)
            }
        }
        .padding(.horizontal, 16)
        .padding(.vertical, 12)
        .background(wa.settingsCard, in: Capsule())
        .overlay(Capsule().stroke(wa.night ? wa.line : .clear, lineWidth: 1))
        .shadow(color: wa.night ? .clear : Color.black.opacity(0.07), radius: 6, y: 2)
    }
}

/// In-page success notice — the wallet toast's look: a dark pill with a check sliding up from the bottom.
private struct AddressToastHost: View {
    let toast: String?
    let last: String

    var body: some View {
        VStack {
            Spacer()
            if toast != nil {
                HStack(spacing: 8) {
                    Image(systemName: "checkmark.circle.fill").font(.system(size: 14)).foregroundStyle(.white)
                    Text(verbatim: last).font(.dorr(13, .semibold)).foregroundStyle(.white)
                }
                .padding(.horizontal, 18)
                .padding(.vertical, 11)
                .background(AppColors.textPrimary, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                .padding(.bottom, 100)
                .transition(.move(edge: .bottom).combined(with: .opacity))
            }
        }
        .allowsHitTesting(false)
    }
}

private struct AddressCard: View {
    @Environment(\.wa) private var wa
    let item: SavedAddress
    let onSelect: () -> Void
    let onEdit: () -> Void
    let onDelete: () -> Void

    var body: some View {
        let kindTitle: String = {
            switch item.kind {
            case "home": return L("addr_home")
            case "work": return L("addr_work")
            default: return item.label.trimmingCharacters(in: .whitespaces).isEmpty ? L("addr_other") : item.label
            }
        }()
        let summary = [
            item.line,
            item.building.isEmpty ? "" : "\(L("addr_building")) \(item.building)",
            item.floor.isEmpty ? "" : "\(L("addr_floor")) \(item.floor)",
            item.landmark,
        ].filter { !$0.trimmingCharacters(in: .whitespaces).isEmpty }.joined(separator: "، ")

        HStack(spacing: 0) {
            Image(systemName: kindIcon(item.kind)).font(.system(size: 19, weight: .semibold)).foregroundStyle(wa.red)
                .frame(width: 46, height: 46)
                .background(wa.red.opacity(0.14), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
            Spacer().frame(width: 12)
            VStack(alignment: .leading, spacing: 3) {
                HStack(spacing: 8) {
                    Text(verbatim: kindTitle).font(.dorr(15, .bold)).foregroundStyle(wa.settingsInk).lineLimit(1)
                    if item.isDefault {
                        Text(L("addr_default")).font(.dorr(11, .bold)).foregroundStyle(wa.red)
                            .padding(.horizontal, 8).padding(.vertical, 2)
                            .background(wa.red.opacity(0.14), in: Capsule())
                    }
                }
                Text(verbatim: summary.isEmpty ? L("addr_details_hint") : summary).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineLimit(1)
            }
            Spacer(minLength: 8)
            Button(action: onEdit) {
                Image(systemName: "pencil").font(.system(size: 15, weight: .semibold)).foregroundStyle(wa.red)
                    .frame(width: 36, height: 36)
                    .background(wa.red.opacity(0.14), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
            }
            .buttonStyle(.plain)
            .accessibilityLabel(L("addr_edit"))
            Spacer().frame(width: 8)
            // Dimmed on the default address as a hint that it is protected — tapping it explains why.
            Button(action: onDelete) {
                Image(systemName: "trash").font(.system(size: 15, weight: .semibold)).foregroundStyle(item.isDefault ? wa.settingsMut : wa.red)
                    .frame(width: 36, height: 36)
                    .background(wa.settingsCard, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                    .overlay(RoundedRectangle(cornerRadius: 12, style: .continuous).stroke(wa.night ? wa.line : wa.red.opacity(0.3), lineWidth: 1))
                    .opacity(item.isDefault ? 0.35 : 1)
            }
            .buttonStyle(.plain)
            .accessibilityLabel(L("addr_delete"))
        }
        .padding(14)
        .settingsSurface(22)
        .contentShape(Rectangle())
        .onTapGesture(perform: onSelect)
    }
}

private func kindIcon(_ kind: String) -> String {
    switch kind {
    case "home": return "house.fill"
    case "work": return "briefcase.fill"
    default: return "mappin.circle.fill"
    }
}

// MARK: - Editor

private struct AddressEditor: View {
    @Environment(\.wa) private var wa
    let initial: SavedAddress?
    let saving: Bool
    let onBack: () -> Void
    let onSave: (SavedAddress) -> Void

    @State private var kind = "home"
    @State private var label = ""
    @State private var details = ""
    @State private var building = ""
    @State private var floor = ""
    @State private var landmark = ""
    @State private var isDefault = false
    @State private var isDetailsExpanded = false
    @State private var started = false
    @FocusState private var focusedField: String?

    var body: some View {
        ZStack {
            AddressesBackground()
            ScrollViewReader { proxy in
                ScrollView {
                    VStack(spacing: 0) {
                        AddressesTopHeader(title: L(initial == nil ? "addr_add_title" : "addr_edit_title"), onBack: onBack).padding(.top, 16)
                        HStack(spacing: 10) {
                            EditorKindChip(selected: kind == "home", label: L("addr_home"), icon: "house.fill") { kind = "home" }
                            EditorKindChip(selected: kind == "work", label: L("addr_work"), icon: "briefcase.fill") { kind = "work" }
                            EditorKindChip(selected: kind == "other", label: L("addr_other"), icon: "mappin.circle.fill") { kind = "other" }
                        }
                        .padding(.top, 16)
                        MapPreviewCard().padding(.top, 16)
                        detailsCard.padding(.top, 16)
                        HStack(spacing: 10) {
                            Image(systemName: "tag.fill").font(.system(size: 18)).foregroundStyle(wa.red)
                            Text(L("addr_set_default")).font(.dorr(15, .bold)).foregroundStyle(wa.settingsInk)
                            Spacer(minLength: 8)
                            RedToggle(on: isDefault) { isDefault.toggle() }
                        }
                        .padding(.horizontal, 16)
                        .padding(.vertical, 14)
                        .settingsSurface(22)
                        .padding(.top, 16)
                        SettingsPrimaryButton(text: L(initial == nil ? "common_save" : "addr_update"), loading: saving) {
                            onSave(SavedAddress(id: initial?.id ?? 0, kind: kind, label: trim(label), line: trim(details), building: trim(building),
                                                floor: trim(floor), landmark: trim(landmark), isDefault: isDefault))
                        }
                        .padding(.top, 22)
                        Spacer().frame(height: 30)
                    }
                    .padding(.horizontal, 20)
                }
                .scrollDismissesKeyboard(.interactively)
                // Focus-centering: a tapped field scrolls to the middle of what is visible above the keyboard.
                .onChange(of: focusedField) { field in
                    guard let field else { return }
                    DispatchQueue.main.asyncAfter(deadline: .now() + 0.3) {
                        withAnimation(.easeInOut(duration: 0.3)) { proxy.scrollTo(field, anchor: .center) }
                    }
                }
            }
        }
        .onAppear {
            guard !started else { return }
            started = true
            kind = initial?.kind ?? "home"
            label = initial?.label ?? ""
            details = initial?.line ?? ""
            building = initial?.building ?? ""
            floor = initial?.floor ?? ""
            landmark = initial?.landmark ?? ""
            isDefault = initial?.isDefault ?? false
        }
    }

    private func trim(_ s: String) -> String { s.trimmingCharacters(in: .whitespacesAndNewlines) }

    private var detailsCard: some View {
        VStack(spacing: 0) {
            Button { withAnimation(.easeInOut(duration: 0.25)) { isDetailsExpanded.toggle() } } label: {
                HStack(spacing: 0) {
                    Image(systemName: "doc.text.fill").font(.system(size: 18)).foregroundStyle(wa.red)
                    Spacer().frame(width: 10)
                    Text(L("addr_details_section")).font(.dorr(15, .bold)).foregroundStyle(wa.settingsInk)
                    Spacer().frame(width: 6)
                    Text(L("addr_optional")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                    Spacer(minLength: 0)
                    Image(systemName: isDetailsExpanded ? "chevron.up" : "chevron.down").font(.system(size: 15, weight: .bold))
                        .foregroundStyle(Color(hex: 0xFCA5A5))
                }
                .contentShape(Rectangle())
            }
            .buttonStyle(.plain)
            if isDetailsExpanded {
                VStack(spacing: 14) {
                    if kind == "other" {
                        EditorFieldSection(title: L("addr_name_label"), value: $label, placeholder: L("addr_name_hint"), icon: "mappin.circle.fill",
                                           focus: $focusedField, id: "label")
                    }
                    EditorFieldSection(title: L("addr_building"), value: $building, placeholder: L("addr_building"), icon: "building.2.fill",
                                       focus: $focusedField, id: "building")
                    EditorFieldSection(title: L("addr_floor"), value: $floor, placeholder: L("addr_floor"), icon: "chart.line.uptrend.xyaxis",
                                       focus: $focusedField, id: "floor")
                    EditorFieldSection(title: L("addr_details_section"), value: $details, placeholder: L("addr_details_hint"), icon: "mappin.circle.fill",
                                       focus: $focusedField, id: "details")
                    EditorFieldSection(title: L("addr_landmark"), value: $landmark, placeholder: L("addr_landmark_hint"), icon: "flag.fill",
                                       focus: $focusedField, id: "landmark")
                }
                .padding(.top, 16)
                .transition(.opacity.combined(with: .move(edge: .top)))
            }
        }
        .padding(16)
        .frame(maxWidth: .infinity)
        .settingsSurface(22)
        .clipped()
    }
}

private struct EditorKindChip: View {
    @Environment(\.wa) private var wa
    let selected: Bool
    let label: String
    let icon: String
    let action: () -> Void

    var body: some View {
        let content: Color = selected ? .white : wa.red
        Button(action: action) {
            HStack(spacing: 6) {
                Image(systemName: icon).font(.system(size: 15, weight: .semibold))
                Text(verbatim: label).font(.dorr(14, .bold)).lineLimit(1)
            }
            .foregroundStyle(content)
            .frame(maxWidth: .infinity)
            .padding(.vertical, 12)
            .background(selected ? wa.red : wa.settingsCard, in: Capsule())
            .shadow(color: selected ? wa.red.opacity(0.3) : Color.black.opacity(0.06), radius: selected ? 6 : 3, y: 2)
        }
        .buttonStyle(.plain)
        .animation(.easeInOut(duration: 0.2), value: selected)
    }
}

private struct EditorFieldSection: View {
    @Environment(\.wa) private var wa
    let title: String
    @Binding var value: String
    let placeholder: String
    let icon: String
    var focus: FocusState<String?>.Binding
    let id: String

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            Text(verbatim: title).font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk)
            HStack(spacing: 12) {
                Image(systemName: icon).font(.system(size: 16, weight: .semibold)).foregroundStyle(wa.red).frame(width: 20)
                ZStack(alignment: .leading) {
                    if value.isEmpty { Text(verbatim: placeholder).font(.dorr(14)).foregroundStyle(wa.settingsMut) }
                    TextField("", text: $value).font(.dorr(14, .medium)).foregroundStyle(wa.settingsInk).tint(wa.red)
                        .focused(focus, equals: id)
                }
            }
            .padding(.horizontal, 16)
            .padding(.vertical, 12)
            .background(wa.night ? wa.darkCard : Color(hex: 0xFBFBFD), in: Capsule())
            .overlay(Capsule().stroke(wa.night ? wa.line : Color(hex: 0xF1F5F9), lineWidth: 1))
            .shadow(color: Color.black.opacity(0.03), radius: 2, y: 1)
        }
        .id(id)
    }
}

/// A drawn map (no live map yet, as on Android): a few roads, the pin in the middle, zoom buttons that do nothing.
private struct MapPreviewCard: View {
    @Environment(\.wa) private var wa

    var body: some View {
        ZStack {
            Canvas { gc, size in
                let w = size.width, h = size.height
                gc.fill(Path(CGRect(origin: .zero, size: size)), with: .color(Color(hex: 0xF2EFE9)))
                // Android draws in pixels; stroke widths divided by the phone's ~2.75 density.
                func line(_ hex: UInt32, _ a: CGPoint, _ b: CGPoint, _ width: CGFloat) {
                    var p = Path()
                    p.move(to: a)
                    p.addLine(to: b)
                    gc.stroke(p, with: .color(Color(hex: hex)), lineWidth: width / 2.75)
                }
                line(0xFFC085, CGPoint(x: 0, y: h * 0.35), CGPoint(x: w, y: h * 0.45), 14)
                line(0xFFC085, CGPoint(x: 0, y: h * 0.7), CGPoint(x: w, y: h * 0.6), 12)
                line(0xFF9E79, CGPoint(x: w * 0.4, y: 0), CGPoint(x: w * 0.6, y: h), 16)
                line(0xFFFFFF, CGPoint(x: w * 0.15, y: 0), CGPoint(x: w * 0.25, y: h), 8)
                line(0xFFFFFF, CGPoint(x: w * 0.8, y: 0), CGPoint(x: w * 0.75, y: h), 8)
                line(0xFFFFFF, CGPoint(x: 0, y: h * 0.2), CGPoint(x: w, y: h * 0.25), 8)
                line(0xFFFFFF, CGPoint(x: 0, y: h * 0.85), CGPoint(x: w, y: h * 0.9), 8)
                line(0xE5E7EB, CGPoint(x: w * 0.2, y: 0), CGPoint(x: 0, y: h * 0.5), 5)
                line(0xE5E7EB, CGPoint(x: w * 0.9, y: 0), CGPoint(x: w * 0.4, y: h), 5)
                line(0xE5E7EB, CGPoint(x: w, y: h * 0.3), CGPoint(x: w * 0.5, y: h), 5)
            }
            .environment(\.layoutDirection, .leftToRight)

            HStack(spacing: 4) {
                Image(systemName: "mappin.circle.fill").font(.system(size: 13)).foregroundStyle(wa.red)
                Text(L("addr_map")).font(.dorr(12, .bold)).foregroundStyle(wa.settingsInk)
            }
            .padding(.horizontal, 12).padding(.vertical, 6)
            .background(Color.white, in: Capsule())
            .shadow(color: Color.black.opacity(0.1), radius: 4, y: 2)
            .padding([.top, .leading], 12)
            .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .topLeading)

            VStack(spacing: 0) {
                Text(verbatim: "+").font(.system(size: 18, weight: .bold)).foregroundStyle(Color(hex: 0x334155)).frame(width: 32, height: 32)
                Color(hex: 0xE2E8F0).frame(width: 32, height: 1)
                Text(verbatim: "-").font(.system(size: 18, weight: .bold)).foregroundStyle(Color(hex: 0x334155)).frame(width: 32, height: 32)
            }
            .background(Color.white, in: RoundedRectangle(cornerRadius: 8, style: .continuous))
            .shadow(color: Color.black.opacity(0.1), radius: 4, y: 2)
            .padding([.top, .trailing], 12)
            .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .topTrailing)

            Image(systemName: "mappin.circle.fill").font(.system(size: 40)).foregroundStyle(Color(hex: 0x84CC16))
                .shadow(color: Color.black.opacity(0.25), radius: 8, y: 3)

            Text(verbatim: "Report a problem | © OpenStreetMap contributors ♥ Make a Donation. Website and API terms")
                .font(.system(size: 8.5)).foregroundStyle(Color(hex: 0x475569)).lineLimit(2).multilineTextAlignment(.center)
                .frame(maxWidth: .infinity)
                .padding(.horizontal, 8).padding(.vertical, 4)
                .background(Color.white.opacity(0.85))
                .frame(maxHeight: .infinity, alignment: .bottom)
        }
        .frame(height: 200)
        .clipShape(RoundedRectangle(cornerRadius: 22, style: .continuous))
        .shadow(color: Color.black.opacity(0.06), radius: 6, y: 2)
    }
}

private struct EmptyAddressView: View {
    @Environment(\.wa) private var wa
    let isSearching: Bool
    let onAddClick: () -> Void
    let onClearSearch: () -> Void

    var body: some View {
        VStack(spacing: 0) {
            // A slow 2-second float and pulse (FastOutSlowIn, reversing), on the clock like Compose's infinite transition.
            TimelineView(.animation) { context in
                let t = context.date.timeIntervalSinceReferenceDate.truncatingRemainder(dividingBy: 4) / 2
                let phase = fastOutSlowIn(t <= 1 ? t : 2 - t)
                let dy = -9 * phase
                let pulseScale = 0.94 + 0.12 * phase
                let pulseAlpha = 0.35 + 0.45 * phase
                ZStack {
                    Circle()
                        .fill(RadialGradient(colors: [wa.red.opacity(0.20), wa.red.opacity(0.04), .clear], center: .center, startRadius: 0, endRadius: 62))
                        .frame(width: 124, height: 124)
                        .scaleEffect(pulseScale)
                        .opacity(pulseAlpha)
                    Circle().fill(wa.red.opacity(0.09)).frame(width: 88, height: 88)
                        .overlay(Circle().stroke(wa.red.opacity(0.18), lineWidth: 1.5))
                    Image(systemName: isSearching ? "magnifyingglass" : "location.slash.fill").font(.system(size: 24, weight: .semibold)).foregroundStyle(.white)
                        .frame(width: 58, height: 58)
                        .background(LinearGradient(colors: [wa.red, Color(hex: 0xBA0912)], startPoint: .topLeading, endPoint: .bottomTrailing), in: Circle())
                        .shadow(color: wa.red.opacity(0.38), radius: 10, y: 4)
                }
                .frame(width: 130, height: 130)
                .offset(y: dy)
            }
            .frame(width: 130, height: 130)
            Text(L(isSearching ? "addr_empty" : "addr_none")).font(.dorr(17, .bold)).foregroundStyle(wa.settingsInk)
                .multilineTextAlignment(.center).padding(.top, 18)
            Text(L(isSearching ? "addr_empty_sub" : "addr_none_sub")).font(.dorr(13)).lineSpacing(6).foregroundStyle(wa.settingsMut)
                .multilineTextAlignment(.center).padding(.horizontal, 8).padding(.top, 6)
            Group {
                if isSearching {
                    Button(action: onClearSearch) {
                        HStack(spacing: 6) {
                            Image(systemName: "xmark").font(.system(size: 13, weight: .bold))
                            Text(L("addr_clear_search")).font(.dorr(13.5, .bold))
                        }
                        .foregroundStyle(wa.red)
                        .padding(.horizontal, 12).padding(.vertical, 8)
                    }
                } else {
                    Button(action: onAddClick) {
                        HStack(spacing: 8) {
                            Image(systemName: "mappin.and.ellipse").font(.system(size: 15, weight: .semibold))
                            Text(L("addr_add")).font(.dorr(13.5, .bold))
                        }
                        .foregroundStyle(wa.red)
                        .padding(.horizontal, 20).padding(.vertical, 10)
                        .background(wa.red.opacity(0.10), in: Capsule())
                    }
                }
            }
            .buttonStyle(.plain)
            .padding(.top, 18)
        }
        .padding(24)
    }
}

private struct ShowMoreButton: View {
    @Environment(\.wa) private var wa
    let loading: Bool
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            ZStack {
                if loading { ProgressView().tint(wa.red) } else { Text(L("wallet_history_more")).font(.dorr(13, .bold)).foregroundStyle(wa.red) }
            }
            .frame(maxWidth: .infinity, minHeight: 20)
            .padding(.vertical, 12)
            .background(wa.settingsCard, in: Capsule())
            .overlay(Capsule().stroke(wa.night ? wa.line : wa.red.opacity(0.35), lineWidth: 1.5))
        }
        .buttonStyle(.plain)
        .allowsHitTesting(!loading)
    }
}
