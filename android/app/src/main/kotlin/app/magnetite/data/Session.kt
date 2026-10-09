package app.magnetite.data

import android.content.Context
import android.content.SharedPreferences
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey

/** Server url, token and user name, kept encrypted (Android Keystore). */
class Session(context: Context) {
    private val prefs: SharedPreferences = EncryptedSharedPreferences.create(
        context,
        "magnetite_session",
        MasterKey.Builder(context).setKeyScheme(MasterKey.KeyScheme.AES256_GCM).build(),
        EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
        EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM,
    )

    var serverUrl: String
        get() = prefs.getString("server", "") ?: ""
        set(v) = prefs.edit().putString("server", v.trim().trimEnd('/')).apply()

    var token: String?
        get() = prefs.getString("token", null)
        set(v) = prefs.edit().apply { if (v == null) remove("token") else putString("token", v) }.apply()

    var username: String?
        get() = prefs.getString("username", null)
        set(v) = prefs.edit().apply { if (v == null) remove("username") else putString("username", v) }.apply()

    val signedIn: Boolean get() = !token.isNullOrEmpty() && serverUrl.isNotEmpty()

    fun clear() {
        token = null
        username = null
    }
}
