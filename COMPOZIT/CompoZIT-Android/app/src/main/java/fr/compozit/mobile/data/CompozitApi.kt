package fr.compozit.mobile.data

import org.json.JSONArray
import org.json.JSONObject
import java.io.IOException
import java.net.HttpURLConnection
import java.net.SocketTimeoutException
import java.net.URL
import java.net.URLEncoder
import java.net.UnknownHostException
import javax.net.ssl.SSLException

/** Jeton et compte renvoyes par l'action « login ». */
data class Session(val token: String, val user: User)

/** Le jeton n'est plus valable : l'application revient a l'identification. */
class SessionExpired : Exception("Session expiree, reconnectez-vous.")

/**
 * Acces a l'inventaire par l'API JSON du site (mobile_api.php).
 *
 * Le site detient les identifiants MySQL et verifie les mots de passe : rien
 * de sensible ne transite ni ne reste sur le telephone, hors le jeton de
 * session. Comme tout passe en HTTPS sur le port 443, le changement d'IP du
 * telephone en 4G/5G est sans consequence.
 */
object CompozitApi {

    private const val CONNECT_TIMEOUT = 10_000
    private const val READ_TIMEOUT = 20_000

    /** Test de joignabilite du site, utilise par le bouton « Tester ». */
    fun ping(config: SiteConfig): String {
        val body = call(config, "ping", emptyMap())
        return "Site joignable et API active" + body.optString("time").let { if (it.isBlank()) "." else "" }
    }

    fun login(config: SiteConfig, email: String, password: String): Session {
        val body = call(config, "login", mapOf("email" to email, "password" to password))
        val token = body.optString("token")
        if (token.isBlank()) {
            throw IllegalStateException("Reponse du site illisible.")
        }
        val user = parseUser(body.optJSONObject("user"))
            ?: throw IllegalStateException("Reponse du site illisible.")
        return Session(token, user)
    }

    fun me(config: SiteConfig, token: String): User =
        parseUser(call(config, "me", emptyMap(), token).optJSONObject("user"))
            ?: throw IllegalStateException("Reponse du site illisible.")

    fun logout(config: SiteConfig, token: String) {
        runCatching { call(config, "logout", emptyMap(), token) }
    }

    /** Inventaire complet, ou restreint au terme cherche. */
    fun list(config: SiteConfig, token: String, term: String): List<Component> {
        val body = call(config, "list", mapOf("q" to term, "limit" to "500"), token)
        val array = body.optJSONArray("components") ?: JSONArray()
        return (0 until array.length()).mapNotNull { index -> parseComponent(array.optJSONObject(index)) }
    }

    fun detail(config: SiteConfig, token: String, id: Int): Component =
        parseComponent(call(config, "detail", mapOf("id" to id.toString()), token).optJSONObject("component"))
            ?: throw IllegalStateException("Reponse du site illisible.")

    /**
     * Applique un ecart de quantite. Le calcul est fait par le site, ce qui
     * evite d'ecraser une modification faite entre-temps ailleurs.
     */
    fun adjustQuantity(config: SiteConfig, token: String, id: Int, delta: Int): Int {
        val body = call(config, "quantity", mapOf("id" to id.toString(), "delta" to delta.toString()), token)
        return body.optInt("quantity")
    }

    // ------------------------------------------------------------ Transport

    private fun call(
        config: SiteConfig,
        action: String,
        params: Map<String, String>,
        token: String? = null
    ): JSONObject {
        val form = buildString {
            append("action=").append(encode(action))
            params.forEach { (key, value) -> append('&').append(encode(key)).append('=').append(encode(value)) }
            /* Le jeton voyage aussi dans le corps : certains hebergements
               mutualises retirent l'en-tete Authorization avant PHP. */
            if (!token.isNullOrBlank()) append("&token=").append(encode(token))
        }

        var connection: HttpURLConnection? = null
        try {
            connection = (URL(config.apiUrl).openConnection() as HttpURLConnection).apply {
                requestMethod = "POST"
                connectTimeout = CONNECT_TIMEOUT
                readTimeout = READ_TIMEOUT
                doOutput = true
                setRequestProperty("Content-Type", "application/x-www-form-urlencoded; charset=UTF-8")
                setRequestProperty("Accept", "application/json")
                if (!token.isNullOrBlank()) setRequestProperty("Authorization", "Bearer $token")
            }

            connection.outputStream.use { it.write(form.toByteArray(Charsets.UTF_8)) }

            val code = connection.responseCode
            val stream = if (code in 200..299) connection.inputStream else connection.errorStream
            val text = stream?.bufferedReader()?.use { it.readText() }.orEmpty()
            val body = if (text.isBlank()) null else runCatching { JSONObject(text) }.getOrNull()

            return when {
                code == 401 -> throw SessionExpired()
                body == null -> throw IllegalStateException(
                    if (code in 200..299) "Reponse du site illisible." else "Le site a repondu $code."
                )
                !body.optBoolean("ok", true) -> throw IllegalStateException(
                    body.optString("error").ifBlank { "Erreur du site ($code)." }
                )
                code !in 200..299 -> throw IllegalStateException(
                    body.optString("error").ifBlank { "Le site a repondu $code." }
                )
                else -> body
            }
        } catch (error: Throwable) {
            throw explain(error)
        } finally {
            connection?.disconnect()
        }
    }

    private fun encode(value: String): String = URLEncoder.encode(value, "UTF-8")

    /** Traduit les pannes reseau en messages comprehensibles. */
    private fun explain(error: Throwable): Exception = when (error) {
        is SessionExpired -> error
        is UnknownHostException -> IllegalStateException("Site introuvable : verifiez l'adresse dans les reglages.")
        is SocketTimeoutException -> IllegalStateException("Le site ne repond pas (delai depasse).")
        is SSLException -> IllegalStateException("Connexion securisee impossible avec ce site.")
        is IOException -> IllegalStateException("Site injoignable : verifiez la connexion et l'adresse.")
        is Exception -> error
        else -> IllegalStateException("Erreur inattendue.")
    }

    // -------------------------------------------------------------- Lecture

    private fun parseUser(json: JSONObject?): User? {
        if (json == null) return null
        return User(
            id = json.optInt("id"),
            email = json.optString("email"),
            role = json.optString("role").ifBlank { "utilisateur" }
        )
    }

    private fun parseComponent(json: JSONObject?): Component? {
        if (json == null) return null
        return Component(
            id = json.optInt("id"),
            name = json.optString("name"),
            manufacturer = json.optString("manufacturer"),
            packageName = json.optString("package_name"),
            pins = if (json.isNull("pins")) null else json.optInt("pins"),
            quantity = json.optInt("quantity"),
            datasheet = json.optString("datasheet"),
            datasheetFile = json.optString("datasheet_file"),
            comment = json.optString("comment"),
            supplierReference = json.optString("supplier_reference"),
            imagePath = json.optString("image_path"),
            price = json.optString("price"),
            locationLabel = json.optString("location_label"),
            categorySub = json.optString("category_sub"),
            categoryHead = json.optString("category_head")
        )
    }
}
