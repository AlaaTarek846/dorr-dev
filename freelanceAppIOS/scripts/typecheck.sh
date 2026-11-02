#!/bin/bash
# Fast compile check of part of the app without an Xcode build (no DerivedData): the shared core (Net, Kit, Components,
# Theme, Routes, MainActions) plus the folders / files given, against the simulator SDK. Realtime.swift (Pusher / OneSignal)
# is swapped for a small shim. Usage: scripts/typecheck.sh DorrPro/UI/Screens/Profile [more paths…]
set -e
cd "$(dirname "$0")/.."
SDK=$(xcrun --sdk iphonesimulator --show-sdk-path)
ARCH=$(uname -m); [ "$ARCH" = "arm64" ] || ARCH=x86_64
SHIM=$(mktemp -d)/RealtimeShim.swift
cat > "$SHIM" <<'SWIFT'
import Combine
import Foundation
struct LiveEvent { let name: String; let data: JSONAny }
@MainActor final class Realtime: ObservableObject {
    static let shared = Realtime()
    let changed = PassthroughSubject<Void, Never>()
    let events = PassthroughSubject<LiveEvent, Never>()
    @Published private(set) var connected = false
    @Published private(set) var unread = 0
    @Published private(set) var opened: String?
    @Published private(set) var openedRoute: Route?
    func consumeOpened() {}
    func start() {}
    func stop() {}
    func refreshUnread() async {}
    func setUnread(_ count: Int) {}
    func pushOptedIn() -> Bool { true }
    func setPushOptedIn(_ on: Bool) {}
    func requestPushPermission() {}
    func pushId() -> String? { nil }
}
SWIFT
CORE=$(find DorrPro/Net DorrPro/UI/Kit DorrPro/UI/Components DorrPro/UI/Theme DorrPro/UI/Wallet DorrPro/UI/Screens/Wallet/WalletFormat.swift DorrPro/UI/Screens/Wallet/WalletKit.swift \
  DorrPro/UI/L10n.swift DorrPro/UI/DownloadedTranslations.swift DorrPro/UI/AppLanguage.swift DorrPro/App/Routes.swift DorrPro/App/MainActions.swift \
  -name '*.swift' ! -name Realtime.swift)
EXTRA=$(for p in "$@"; do find "$p" -name '*.swift'; done)
xcrun swiftc -typecheck -sdk "$SDK" -target "$ARCH-apple-ios16.0-simulator" -swift-version 5 $CORE $EXTRA "$SHIM" 2>&1 | grep -E "error:" | sort -u | head -80
echo "typecheck done"
