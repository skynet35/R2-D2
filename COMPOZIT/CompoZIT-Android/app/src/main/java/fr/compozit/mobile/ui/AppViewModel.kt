package fr.compozit.mobile.ui

import android.app.Application
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import fr.compozit.mobile.data.Component
import fr.compozit.mobile.data.CompozitApi
import fr.compozit.mobile.data.Prefs
import fr.compozit.mobile.data.SessionExpired
import fr.compozit.mobile.data.SiteConfig
import fr.compozit.mobile.data.User
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

/** Ecrans de l'application, dans l'ordre du parcours : reglages, identification, inventaire, fiche. */
sealed interface Screen {
    data object Config : Screen
    data object Login : Screen
    data object Inventory : Screen
    data class Detail(val id: Int) : Screen
}

class AppViewModel(application: Application) : AndroidViewModel(application) {

    private val prefs = Prefs(application)

    /** Adresse du site CompoZ'IT : le seul reglage de l'application. */
    var config by mutableStateOf(prefs.loadConfig())
        private set

    /** Jeton remis par le site. Garde en memoire, jamais dans le code. */
    private var token: String? = null

    var screen by mutableStateOf<Screen>(Screen.Login)
        private set

    var user by mutableStateOf<User?>(null)
        private set

    var savedEmail by mutableStateOf(prefs.savedEmail)
        private set

    var busy by mutableStateOf(false)
        private set

    /** Message affiche en bas de l'ecran (erreur ou confirmation). */
    var message by mutableStateOf<String?>(null)
        private set

    var search by mutableStateOf("")
        private set

    /** Categorie retenue (categorie « tete »), ou null pour « Toutes ». */
    var categoryFilter by mutableStateOf<String?>(null)
        private set

    /** Sous-categorie retenue, ou null pour « Toutes ». */
    var subCategoryFilter by mutableStateOf<String?>(null)
        private set

    var components by mutableStateOf<List<Component>>(emptyList())
        private set

    var loadingList by mutableStateOf(false)
        private set

    var detail by mutableStateOf<Component?>(null)
        private set

    private var searchJob: Job? = null

    init {
        val saved = prefs.savedSession()
        if (saved == null) {
            screen = Screen.Login
        } else {
            if (saved.email.isNotBlank()) savedEmail = saved.email
            resume(saved.token)
        }
    }

    // ------------------------------------------------------------- Reglages

    fun saveConfig(updated: SiteConfig) {
        val changed = updated.siteUrl != config.siteUrl
        config = updated
        prefs.saveConfig(updated)

        /* Le jeton appartient au site precedent : on repart de l'identification. */
        if (changed) {
            resetSession()
        }
    }

    suspend fun testConnection(candidate: SiteConfig): Result<String> = withContext(Dispatchers.IO) {
        runCatching { CompozitApi.ping(candidate) }
    }

    fun openSettings() {
        message = null
        screen = Screen.Config
    }

    fun leaveSettings() {
        screen = if (user == null) Screen.Login else Screen.Inventory
    }

    // -------------------------------------------------------- Identification

    /** Revalide au demarrage le jeton conserve sur le telephone. */
    private fun resume(savedToken: String) {
        viewModelScope.launch {
            busy = true
            val attempt = io { CompozitApi.me(config, savedToken) }
            busy = false

            val account = attempt.getOrNull()
            if (account == null) {
                val error = attempt.exceptionOrNull()
                /* Hors jeton perime (site injoignable), on garde la session pour
                   la prochaine ouverture. */
                if (error is SessionExpired) prefs.clearSession()
                token = null
                screen = Screen.Login
                if (error !is SessionExpired) fail(error)
            } else {
                token = savedToken
                user = account
                savedEmail = account.email
                screen = Screen.Inventory
                refresh()
            }
        }
    }

    fun login(email: String, password: String, remember: Boolean) {
        if (email.isBlank() || password.isEmpty()) {
            message = "Renseignez l'adresse e-mail et le mot de passe."
            return
        }

        viewModelScope.launch {
            busy = true
            val attempt = io { CompozitApi.login(config, email.trim(), password) }
            busy = false

            val session = attempt.getOrNull()
            if (session == null) {
                fail(attempt.exceptionOrNull())
            } else {
                token = session.token
                user = session.user
                savedEmail = session.user.email
                if (remember) {
                    prefs.saveSession(session.token, session.user.email)
                } else {
                    prefs.clearSession()
                }
                screen = Screen.Inventory
                refresh()
            }
        }
    }

    fun logout() {
        val current = token
        if (current != null) {
            viewModelScope.launch { io { CompozitApi.logout(config, current) } }
        }
        resetSession()
    }

    // ----------------------------------------------------------- Inventaire

    fun onSearchChanged(value: String) {
        search = value
        searchJob?.cancel()
        searchJob = viewModelScope.launch {
            delay(250)
            refresh()
        }
    }

    fun refresh() {
        val current = token ?: return
        viewModelScope.launch {
            loadingList = true
            val attempt = io { CompozitApi.list(config, current, search) }
            loadingList = false

            attempt
                .onSuccess { components = it }
                .onFailure { fail(it) }
        }
    }

    /** Categories presentes dans la liste chargee, triees par nom. */
    val categoryOptions: List<String>
        get() = components.asSequence()
            .map { it.categoryHead }
            .filter { it.isNotBlank() }
            .distinct()
            .sorted()
            .toList()

    /** Sous-categories de la categorie retenue, ou de toute la liste. */
    val subCategoryOptions: List<String>
        get() = components.asSequence()
            .filter { categoryFilter == null || it.categoryHead == categoryFilter }
            .map { it.categorySub }
            .filter { it.isNotBlank() }
            .distinct()
            .sorted()
            .toList()

    /** Liste affichee : resultat de la recherche serveur, puis filtres locaux. */
    val visibleComponents: List<Component>
        get() = components.filter {
            (categoryFilter == null || it.categoryHead == categoryFilter) &&
                (subCategoryFilter == null || it.categorySub == subCategoryFilter)
        }

    fun onCategorySelected(value: String?) {
        categoryFilter = value
        /* La sous-categorie retenue appartenait a l'ancienne categorie : on
           l'efface si elle n'existe plus dans la nouvelle selection. */
        if (subCategoryFilter != null && subCategoryFilter !in subCategoryOptions) {
            subCategoryFilter = null
        }
    }

    fun onSubCategorySelected(value: String?) {
        subCategoryFilter = value
    }

    fun openDetail(id: Int) {
        detail = components.firstOrNull { it.id == id }
        screen = Screen.Detail(id)
        reloadDetail(id)
    }

    fun closeDetail() {
        detail = null
        screen = Screen.Inventory
    }

    private fun reloadDetail(id: Int) {
        val current = token ?: return
        viewModelScope.launch {
            io { CompozitApi.detail(config, current, id) }
                .onSuccess { detail = it }
                .onFailure { fail(it) }
        }
    }

    // ------------------------------------------------------------ Quantites

    fun adjustQuantity(component: Component, delta: Int) {
        val current = token ?: return
        viewModelScope.launch {
            io { CompozitApi.adjustQuantity(config, current, component.id, delta) }
                .onSuccess { applyQuantity(component.id, it) }
                .onFailure { fail(it) }
        }
    }

    /** Recopie la quantite confirmee par le site dans la liste et la fiche. */
    private fun applyQuantity(id: Int, quantity: Int) {
        components = components.map { if (it.id == id) it.copy(quantity = quantity) else it }
        detail = detail?.let { if (it.id == id) it.copy(quantity = quantity) else it }
    }

    // ----------------------------------------------------------------- Outils

    private suspend fun <T> io(block: () -> T): Result<T> =
        withContext(Dispatchers.IO) { runCatching(block) }

    /**
     * Traduit une panne. Un jeton refuse renvoie a l'identification : le site
     * a expire la session, il n'y a rien d'autre a tenter.
     */
    private fun fail(error: Throwable?) {
        if (error is SessionExpired) {
            resetSession()
            message = error.message
            return
        }
        message = error?.message ?: "Une erreur est survenue."
    }

    private fun resetSession() {
        prefs.clearSession()
        token = null
        user = null
        components = emptyList()
        detail = null
        search = ""
        categoryFilter = null
        subCategoryFilter = null
        screen = Screen.Login
    }

    fun showMessage(text: String) {
        message = text
    }

    fun dismissMessage() {
        message = null
    }
}
