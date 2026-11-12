#!/bin/bash
# Fast compile check without an Xcode build (no DerivedData): every Swift file of the app that doesn't import a binary SDK
# (GoogleMaps, MapboxMaps, LiveKit, PusherSwift, OneSignal), plus the files / folders given, against the simulator SDK.
# Usage: scripts/typecheck.sh                       → the whole app (SDK files left out)
#        scripts/typecheck.sh --only <paths…>       → the core (Net, UI/Theme, UI/Kit, App, Live, Duty) plus the paths given
set -e
cd "$(dirname "$0")/.."
SDK=$(xcrun --sdk iphonesimulator --show-sdk-path)
ARCH=$(uname -m); [ "$ARCH" = "arm64" ] || ARCH=x86_64
SDKS='^import (GoogleMaps|MapboxMaps|LiveKit|PusherSwift|OneSignalFramework|OneSignal)'
if [ "$1" = "--only" ]; then
  shift
  FILES=$( (find DorrCaptain/Net DorrCaptain/UI/Theme DorrCaptain/UI/Kit DorrCaptain/App DorrCaptain/Live DorrCaptain/Duty -name '*.swift' 2>/dev/null; for p in "$@"; do find "$p" -name '*.swift'; done) | sort -u)
else
  FILES=$(find DorrCaptain -name '*.swift' | sort -u)
fi
KEEP=$(for f in $FILES; do grep -qE "$SDKS" "$f" || echo "$f"; done)
# Stand-ins for the SDK halves the rest of the app calls (their real files are left out above).
SHIM=$(mktemp -d)/SdkShims.swift
cat > "$SHIM" <<'SWIFT'
import Foundation
@MainActor enum DriverLivePusher {
    static func connect(_ live: DriverLive, key: String, channel: String, config: RealtimeConfigDto) {}
    static func disconnect(_ live: DriverLive) {}
    static func subscribeRide(_ live: DriverLive, _ uuid: String) {}
    static func unsubscribe(_ live: DriverLive, _ name: String) {}
}
@MainActor enum RideCallLiveKit {
    static func connect(_ session: RideCallSession, url: String, token: String, microphone: Bool) async throws -> Bool { false }
    static func setMicrophone(_ session: RideCallSession, enabled: Bool) async {}
    static func applySpeaker(_ on: Bool) {}
    static func disconnect(_ session: RideCallSession) {}
}
@MainActor enum DriverPushOneSignal {
    static func initialise(_ appId: String) {}
    static func requestPermission() {}
    static func subscriptionId() -> String? { nil }
    static func logout() {}
}
SWIFT
EXTRA_SHIMS=""
[ -f DorrCaptain/Map/DorrMapShim.swift ] && EXTRA_SHIMS=""
# The map: its SDK-free declarations (DorrCamera, MapPin…) and a stand-in DorrMap view / MapEngine — keep scripts/shims/MapShim.swift in step with Map/DorrMap.swift.
KEEP="$KEEP $SHIM scripts/shims/MapShim.swift"
xcrun swiftc -typecheck -sdk "$SDK" -target "$ARCH-apple-ios16.0-simulator" -swift-version 5 $KEEP 2>&1 | grep -E "error:" | sort -u | head -80
echo "typecheck done (files importing an SDK were left out: $(for f in $FILES; do grep -qE "$SDKS" "$f" && echo -n "$(basename $f) "; done))"
