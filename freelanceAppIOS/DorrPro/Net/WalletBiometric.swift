// freelanceApp `net/WalletBiometric.kt`
import Foundation
import LocalAuthentication
import Security

/// Face ID / Touch ID unlock for the wallet gate (docs/wallet-tasks.md §10.10, wallet policy bend 3) — optional, off by
/// default, and *only* a faster way to reach the same PIN check the gate already does: the PIN itself is stored on the device
/// only in the Keychain, behind `.biometryCurrentSet` access control, so the system releases it only after a successful
/// Face ID / Touch ID check. It is never sent anywhere except in the one `pin/verify` call it stands in for — exactly the call
/// typing the PIN would make. A later financial action (top-up, withdrawal…) still asks for the PIN itself.
///
/// Adding or removing a face or finger destroys the item (Android's `setInvalidatedByBiometricEnrollment(true)`), so
/// re-enabling needs the PIN typed once again, matching "عند تغيير الجهاز أو إضافة بصمة جديد يطلب PIN" in the policy.
/// (Android's "weak face unlock" mode has no iOS counterpart: Face ID is always a strong biometric.)
enum WalletBiometric {
    private static let account = "dorr_freelance_wallet_pin_key"
    private static let service = "dorr_freelance_biometric_pin"
    private static let enabledKey = "dorr_freelance_biometric_pin.enabled"

    /// A face or a finger enrolled on this phone.
    static func isAvailable() -> Bool {
        var error: NSError?
        return LAContext().canEvaluatePolicy(.deviceOwnerAuthenticationWithBiometrics, error: &error)
    }

    static func isEnabled() -> Bool { UserDefaults.standard.bool(forKey: enabledKey) }

    static func disable() {
        UserDefaults.standard.removeObject(forKey: enabledKey)
        SecItemDelete(baseQuery() as CFDictionary)
    }

    /// Prompts once, then stores `pin` behind the biometric check. Calls back on the main thread.
    static func enable(pin: String, onResult: @escaping (Bool) -> Void) {
        let context = makeContext()
        context.evaluatePolicy(.deviceOwnerAuthenticationWithBiometrics, localizedReason: L("wa_biometric_prompt_title")) { ok, _ in
            guard ok, let access = SecAccessControlCreateWithFlags(nil, kSecAttrAccessibleWhenPasscodeSetThisDeviceOnly, .biometryCurrentSet, nil) else {
                DispatchQueue.main.async { onResult(false) }
                return
            }
            SecItemDelete(baseQuery() as CFDictionary)
            var item = baseQuery()
            item[kSecValueData as String] = Data(pin.utf8)
            item[kSecAttrAccessControl as String] = access
            // The check just made covers this write, so the person is asked once, not twice.
            item[kSecUseAuthenticationContext as String] = context
            let stored = SecItemAdd(item as CFDictionary, nil) == errSecSuccess
            if stored { UserDefaults.standard.set(true, forKey: enabledKey) }
            DispatchQueue.main.async { onResult(stored) }
        }
    }

    /// Prompts, then returns the stored PIN — or nil if the item was invalidated (re-enrolled biometrics) or cancelled.
    /// Calls back on the main thread.
    static func unlock(onResult: @escaping (String?) -> Void) {
        DispatchQueue.global(qos: .userInitiated).async {
            var query = baseQuery()
            query[kSecReturnData as String] = true
            query[kSecMatchLimit as String] = kSecMatchLimitOne
            let context = makeContext()
            context.localizedReason = L("wa_biometric_prompt_title")
            query[kSecUseAuthenticationContext as String] = context
            var result: AnyObject?
            let status = SecItemCopyMatching(query as CFDictionary, &result)
            if status == errSecItemNotFound {
                disable() // the item is gone or unusable — nothing left to unlock with
            }
            let pin = status == errSecSuccess ? (result as? Data).flatMap { String(data: $0, encoding: .utf8) } : nil
            DispatchQueue.main.async { onResult(pin) }
        }
    }

    private static func makeContext() -> LAContext {
        let context = LAContext()
        context.localizedCancelTitle = L("wa_biometric_prompt_cancel")
        // No "Enter Password" button: the PIN pad under the prompt is the fallback.
        context.localizedFallbackTitle = ""
        return context
    }

    private static func baseQuery() -> [String: Any] {
        [kSecClass as String: kSecClassGenericPassword, kSecAttrService as String: service, kSecAttrAccount as String: account]
    }
}
