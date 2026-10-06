package com.dorr.app.ui.screens.wallet

import android.content.Context
import android.content.ContextWrapper
import android.content.SharedPreferences
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Base64
import androidx.biometric.BiometricManager
import androidx.biometric.BiometricPrompt
import androidx.fragment.app.FragmentActivity
import java.security.KeyStore
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec

/**
 * Fingerprint/Face unlock for the wallet gate (docs/wallet-tasks.md §10.10, wallet policy bend 3) —
 * optional, off by default, and *only* a faster way to reach the same PIN check the gate already does:
 * the PIN itself is stored on the device only inside the Android Keystore, encrypted with a key that
 * never leaves hardware and can only be used after a successful biometric prompt. It is never sent
 * anywhere unencrypted except in the one `pin/verify` call it stands in for — exactly the call typing
 * the PIN would make. A later financial action (transfer, top-up…) still asks for the PIN itself; this
 * never touches those.
 *
 * The Keystore key is created with `setInvalidatedByBiometricEnrollment(true)`, so adding or removing a
 * fingerprint/face invalidates it automatically — re-enabling needs the PIN typed once again, matching
 * "عند تغيير الجهاز أو إضافة بصمة جديد يطلب PIN" in the policy.
 */
object WaBiometric {
    private const val PREFS_NAME = "dorr_biometric_pin"
    private const val KEY_ALIAS = "dorr_wallet_pin_key"
    /** For face unlock that Android only rates "weak" (most phones): a hardware key not tied to the prompt. */
    private const val SOFT_ALIAS = "dorr_wallet_pin_key_soft"
    private const val KEY_CIPHERTEXT = "ciphertext"
    private const val KEY_IV = "iv"
    private const val KEY_MODE = "mode"

    private fun prefs(context: Context): SharedPreferences =
        context.applicationContext.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)

    private fun strong(context: Context): Boolean =
        BiometricManager.from(context).canAuthenticate(BiometricManager.Authenticators.BIOMETRIC_STRONG) == BiometricManager.BIOMETRIC_SUCCESS

    private fun weak(context: Context): Boolean =
        BiometricManager.from(context).canAuthenticate(BiometricManager.Authenticators.BIOMETRIC_WEAK) == BiometricManager.BIOMETRIC_SUCCESS

    /**
     * A fingerprint or a face enrolled on this phone — like the chat lock. A "strong" one keeps the
     * PIN behind a key only that prompt can open; a "weak" one (many phones' face unlock) still
     * asks the system prompt every time, with the PIN kept in a hardware key.
     */
    fun isAvailable(context: Context): Boolean = strong(context) || weak(context)

    fun isEnabled(context: Context): Boolean = prefs(context).contains(KEY_CIPHERTEXT)

    fun disable(context: Context) {
        prefs(context).edit().clear().apply()
        runCatching { KeyStore.getInstance("AndroidKeyStore").apply { load(null) }.deleteEntry(KEY_ALIAS) }
        runCatching { KeyStore.getInstance("AndroidKeyStore").apply { load(null) }.deleteEntry(SOFT_ALIAS) }
    }

    /** Prompts once, then stores [pin] encrypted behind that same prompt's key. */
    fun enable(activity: FragmentActivity, pin: String, onResult: (Boolean) -> Unit) {
        if (!strong(activity)) {
            enableWeak(activity, pin, onResult)
            return
        }
        val cipher = runCatching { encryptCipher() }.getOrNull()
        if (cipher == null) {
            onResult(false)
            return
        }
        prompt(activity, cipher) { authenticated ->
            val usedCipher = authenticated?.cryptoObject?.cipher
            if (usedCipher == null) {
                onResult(false)
                return@prompt
            }
            val result = runCatching {
                val bytes = usedCipher.doFinal(pin.toByteArray(Charsets.UTF_8))
                prefs(activity).edit()
                    .putString(KEY_CIPHERTEXT, Base64.encodeToString(bytes, Base64.NO_WRAP))
                    .putString(KEY_IV, Base64.encodeToString(usedCipher.iv, Base64.NO_WRAP))
                    .putString(KEY_MODE, "strong")
                    .apply()
            }
            onResult(result.isSuccess)
        }
    }

    /** Weak face unlock: the system prompt first, then the PIN kept in a hardware key. */
    private fun enableWeak(activity: FragmentActivity, pin: String, onResult: (Boolean) -> Unit) {
        promptWeak(activity) { ok ->
            if (!ok) {
                onResult(false)
                return@promptWeak
            }
            val result = runCatching {
                val cipher = Cipher.getInstance("AES/GCM/NoPadding").apply { init(Cipher.ENCRYPT_MODE, softKey()) }
                val bytes = cipher.doFinal(pin.toByteArray(Charsets.UTF_8))
                prefs(activity).edit()
                    .putString(KEY_CIPHERTEXT, Base64.encodeToString(bytes, Base64.NO_WRAP))
                    .putString(KEY_IV, Base64.encodeToString(cipher.iv, Base64.NO_WRAP))
                    .putString(KEY_MODE, "weak")
                    .apply()
            }
            onResult(result.isSuccess)
        }
    }

    /** Prompts, then returns the stored PIN — or null if the key was invalidated (re-enrolled biometrics) or cancelled. */
    fun unlock(activity: FragmentActivity, onResult: (String?) -> Unit) {
        if (prefs(activity).getString(KEY_MODE, "strong") == "weak") {
            promptWeak(activity) { ok ->
                onResult(if (!ok) null else runCatching {
                    val iv = Base64.decode(prefs(activity).getString(KEY_IV, null), Base64.NO_WRAP)
                    val cipher = Cipher.getInstance("AES/GCM/NoPadding").apply { init(Cipher.DECRYPT_MODE, softKey(), GCMParameterSpec(128, iv)) }
                    String(cipher.doFinal(Base64.decode(prefs(activity).getString(KEY_CIPHERTEXT, null), Base64.NO_WRAP)), Charsets.UTF_8)
                }.getOrNull())
            }
            return
        }
        val iv = prefs(activity).getString(KEY_IV, null)?.let { Base64.decode(it, Base64.NO_WRAP) }
        val cipher = if (iv != null) runCatching { decryptCipher(iv) }.getOrNull() else null
        if (cipher == null) {
            disable(activity) // the key is gone or unusable — nothing left to unlock with
            onResult(null)
            return
        }
        prompt(activity, cipher) { authenticated ->
            val pin = authenticated?.cryptoObject?.cipher?.let { c ->
                runCatching {
                    val bytes = Base64.decode(prefs(activity).getString(KEY_CIPHERTEXT, null), Base64.NO_WRAP)
                    String(c.doFinal(bytes), Charsets.UTF_8)
                }.getOrNull()
            }
            onResult(pin)
        }
    }

    private fun prompt(activity: FragmentActivity, cipher: Cipher, onResult: (BiometricPrompt.AuthenticationResult?) -> Unit) {
        val executor = androidx.core.content.ContextCompat.getMainExecutor(activity)
        val callback = object : BiometricPrompt.AuthenticationCallback() {
            override fun onAuthenticationSucceeded(result: BiometricPrompt.AuthenticationResult) = onResult(result)
            override fun onAuthenticationError(errorCode: Int, errString: CharSequence) = onResult(null)
            override fun onAuthenticationFailed() {} // a single wrong attempt — the system prompt itself lets the person retry
        }
        val info = BiometricPrompt.PromptInfo.Builder()
            .setTitle(activity.getString(com.dorr.app.R.string.wa_biometric_prompt_title))
            .setNegativeButtonText(activity.getString(com.dorr.app.R.string.wa_biometric_prompt_cancel))
            .setAllowedAuthenticators(BiometricManager.Authenticators.BIOMETRIC_STRONG)
            .build()
        BiometricPrompt(activity, executor, callback).authenticate(info, BiometricPrompt.CryptoObject(cipher))
    }

    private fun promptWeak(activity: FragmentActivity, onResult: (Boolean) -> Unit) {
        val executor = androidx.core.content.ContextCompat.getMainExecutor(activity)
        val callback = object : BiometricPrompt.AuthenticationCallback() {
            override fun onAuthenticationSucceeded(result: BiometricPrompt.AuthenticationResult) = onResult(true)
            override fun onAuthenticationError(errorCode: Int, errString: CharSequence) = onResult(false)
            override fun onAuthenticationFailed() {}
        }
        val info = BiometricPrompt.PromptInfo.Builder()
            .setTitle(activity.getString(com.dorr.app.R.string.wa_biometric_prompt_title))
            .setNegativeButtonText(activity.getString(com.dorr.app.R.string.wa_biometric_prompt_cancel))
            .setAllowedAuthenticators(BiometricManager.Authenticators.BIOMETRIC_WEAK)
            .build()
        BiometricPrompt(activity, executor, callback).authenticate(info)
    }

    private fun softKey(): SecretKey {
        val keyStore = KeyStore.getInstance("AndroidKeyStore").apply { load(null) }
        (keyStore.getKey(SOFT_ALIAS, null) as? SecretKey)?.let { return it }
        val generator = KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, "AndroidKeyStore")
        generator.init(
            KeyGenParameterSpec.Builder(SOFT_ALIAS, KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT)
                .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
                .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
                .build(),
        )
        return generator.generateKey()
    }

    private fun secretKey(): SecretKey {
        val keyStore = KeyStore.getInstance("AndroidKeyStore").apply { load(null) }
        (keyStore.getKey(KEY_ALIAS, null) as? SecretKey)?.let { return it }

        val generator = KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, "AndroidKeyStore")
        generator.init(
            KeyGenParameterSpec.Builder(KEY_ALIAS, KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT)
                .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
                .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
                .setUserAuthenticationRequired(true)
                .setInvalidatedByBiometricEnrollment(true)
                .build(),
        )
        return generator.generateKey()
    }

    private fun encryptCipher(): Cipher =
        Cipher.getInstance("AES/GCM/NoPadding").apply { init(Cipher.ENCRYPT_MODE, secretKey()) }

    private fun decryptCipher(iv: ByteArray): Cipher =
        Cipher.getInstance("AES/GCM/NoPadding").apply { init(Cipher.DECRYPT_MODE, secretKey(), GCMParameterSpec(128, iv)) }
}

/**
 * The Activity behind a context. Compose's [androidx.compose.ui.platform.LocalContext] is the app's
 * language wrapper around it, so a plain `as? FragmentActivity` was always null — and the
 * fingerprint / face unlock silently never started.
 */
internal fun Context.findFragmentActivity(): FragmentActivity? {
    var context: Context? = this
    while (context is ContextWrapper) {
        if (context is FragmentActivity) return context
        context = context.baseContext
    }
    return null
}
