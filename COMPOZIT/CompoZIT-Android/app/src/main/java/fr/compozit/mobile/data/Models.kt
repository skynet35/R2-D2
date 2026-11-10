package fr.compozit.mobile.data

/** Compte de la table `users`. */
data class User(
    val id: Int,
    val email: String,
    val role: String
) {
    val roleLabel: String get() = if (role == "administrateur") "Administrateur" else "Utilisateur"
}

/** Fiche d'un composant de l'inventaire (table `data`). */
data class Component(
    val id: Int,
    val name: String,
    val manufacturer: String,
    val packageName: String,
    val pins: Int?,
    val quantity: Int,
    val datasheet: String,
    val datasheetFile: String,
    val comment: String,
    val supplierReference: String,
    val imagePath: String,
    val price: String,
    val locationLabel: String,
    val categorySub: String,
    val categoryHead: String
) {
    /** Emplacement complet : « casier tiroir compartiment » (deja assemble par SQL). */
    val location: String get() = locationLabel.ifBlank { "—" }

    /**
     * URL de la vignette. Les chemins en base sont relatifs
     * (« img/TO-220.jpg ») : ils sont completes par la racine du site.
     */
    fun imageUrl(siteUrl: String): String? = when {
        imagePath.isBlank() -> null
        imagePath.startsWith("http") -> imagePath
        siteUrl.isBlank() -> null
        else -> "$siteUrl/${imagePath.trimStart('/')}"
    }

    /**
     * URL de la fiche technique : le lien externe quand il existe, sinon le
     * PDF depose sur le site (colonne `datasheet_file`).
     */
    fun datasheetUrl(siteUrl: String): String? = when {
        datasheet.isNotBlank() && datasheet.startsWith("http") -> datasheet
        datasheet.isNotBlank() && siteUrl.isNotBlank() -> "$siteUrl/${datasheet.trimStart('/')}"
        datasheetFile.isNotBlank() && siteUrl.isNotBlank() -> "$siteUrl/${datasheetFile.trimStart('/')}"
        else -> null
    }
}
