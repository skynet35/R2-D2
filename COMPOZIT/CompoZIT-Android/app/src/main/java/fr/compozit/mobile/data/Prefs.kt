package fr.compozit.mobile.data

import android.content.Context

/**
 * Adresse du site CompoZ'IT qui expose l'API JSON. C'est le seul reglage de
 * l'application : les identifiants MySQL restent sur le serveur, ils ne sont
 * jamais ecrits sur le telephone.
 */
data class SiteConfig(val siteUrl: String = DEFAULT_SITE) {

    /** Point d'entree de l'API mobile, sur le site. */
    val apiUrl: String get() = "$siteUrl/mobile_api.php"

    val label: String get() = siteUrl

    val isComplete: Boolean
        get() = siteUrl.startsWith("http://") || siteUrl.startsWith("https://")

    companion object {
        const val DEFAULT_SITE = "https://mr-j.fr/copozit"
    }
}

/** Session conservee sur le telephone quand « rester connecte » est coche. */
data class SavedSession(val token: String, val email: String)

/**
 * Stockage local de l'adresse du site, du jeton de session et du dernier
 * e-mail utilise. Aucun mot de passe n'est enregistre.
 */
class Prefs(context: Context) {

    private val sp = context.getSharedPreferences("compozit", Context.MODE_PRIVATE)

    fun loadConfig(): SiteConfig =
        SiteConfig(siteUrl = (sp.getString(KEY_SITE_URL, "") ?: "").ifBlank { SiteConfig.DEFAULT_SITE })

    fun saveConfig(config: SiteConfig) {
        sp.edit().putString(KEY_SITE_URL, normalize(config.siteUrl)).apply()
    }

    val savedEmail: String get() = sp.getString(KEY_EMAIL, "") ?: ""

    fun savedSession(): SavedSession? {
        val token = sp.getString(KEY_TOKEN, "") ?: ""
        val email = sp.getString(KEY_EMAIL, "") ?: ""
        return if (token.isNotBlank()) SavedSession(token, email) else null
    }

    fun saveSession(token: String, email: String) {
        sp.edit().putString(KEY_TOKEN, token).putString(KEY_EMAIL, email).apply()
    }

    fun clearSession() {
        sp.edit().remove(KEY_TOKEN).apply()
    }

    /** Retire la barre oblique finale pour que les URL de l'API restent propres. */
    private fun normalize(url: String): String = url.trim().trimEnd('/')

    private companion object {
        const val KEY_SITE_URL = "site_url"
        const val KEY_TOKEN = "session_token"
        const val KEY_EMAIL = "user_email"
    }
}
