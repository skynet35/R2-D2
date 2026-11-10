package fr.compozit.mobile.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Close
import androidx.compose.material.icons.filled.Info
import androidx.compose.material.icons.filled.MoreVert
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material.icons.filled.Search
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import coil.compose.AsyncImage
import fr.compozit.mobile.data.Component

/**
 * Ecran principal : la barre de recherche et, juste en dessous, l'inventaire.
 * Le compteur de quantite est present sur chaque ligne pour ajuster le stock
 * sans ouvrir la fiche.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun InventoryScreen(vm: AppViewModel) {
    var menuOpen by remember { mutableStateOf(false) }

    Scaffold(
        topBar = {
            TopAppBar(
                title = {
                    Column {
                        Text("CompoZ'IT", fontWeight = FontWeight.Bold)
                        Text(
                            text = vm.user?.email.orEmpty(),
                            style = MaterialTheme.typography.labelSmall,
                            color = MaterialTheme.colorScheme.onSurfaceVariant
                        )
                    }
                },
                actions = {
                    IconButton(onClick = { vm.refresh() }) {
                        Icon(Icons.Default.Refresh, contentDescription = "Actualiser")
                    }
                    IconButton(onClick = { menuOpen = true }) {
                        Icon(Icons.Default.MoreVert, contentDescription = "Plus d'options")
                    }
                    DropdownMenu(expanded = menuOpen, onDismissRequest = { menuOpen = false }) {
                        DropdownMenuItem(
                            text = { Text("Réglages de la connexion") },
                            onClick = {
                                menuOpen = false
                                vm.openSettings()
                            }
                        )
                        DropdownMenuItem(
                            text = { Text("Se déconnecter") },
                            onClick = {
                                menuOpen = false
                                vm.logout()
                            }
                        )
                    }
                }
            )
        }
    ) { insets ->
        val context = LocalContext.current
        val visible = vm.visibleComponents

        Column(modifier = Modifier.padding(insets).fillMaxSize()) {

            OutlinedTextField(
                value = vm.search,
                onValueChange = vm::onSearchChanged,
                placeholder = { Text("Référence, fabricant, boîtier, commentaire…") },
                leadingIcon = { Icon(Icons.Default.Search, contentDescription = null) },
                trailingIcon = {
                    if (vm.search.isNotEmpty()) {
                        IconButton(onClick = { vm.onSearchChanged("") }) {
                            Icon(Icons.Default.Close, contentDescription = "Effacer")
                        }
                    }
                },
                singleLine = true,
                modifier = Modifier
                    .fillMaxWidth()
                    .padding(horizontal = 14.dp, vertical = 8.dp)
            )

            Row(
                modifier = Modifier
                    .fillMaxWidth()
                    .padding(horizontal = 14.dp),
                horizontalArrangement = Arrangement.spacedBy(10.dp)
            ) {
                FilterDropdown(
                    label = "Catégorie",
                    options = vm.categoryOptions,
                    selected = vm.categoryFilter,
                    onSelect = vm::onCategorySelected,
                    modifier = Modifier.weight(1f)
                )
                FilterDropdown(
                    label = "Sous-catégorie",
                    options = vm.subCategoryOptions,
                    selected = vm.subCategoryFilter,
                    onSelect = vm::onSubCategorySelected,
                    modifier = Modifier.weight(1f)
                )
            }

            Row(
                modifier = Modifier
                    .fillMaxWidth()
                    .padding(horizontal = 16.dp, vertical = 2.dp),
                horizontalArrangement = Arrangement.SpaceBetween
            ) {
                Text(
                    text = "${visible.size} fiche(s) affichée(s)",
                    style = MaterialTheme.typography.labelSmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant
                )
                if (vm.loadingList) {
                    Text(
                        text = "Recherche…",
                        style = MaterialTheme.typography.labelSmall,
                        color = MaterialTheme.colorScheme.primary
                    )
                }
            }

            if (visible.isEmpty() && !vm.loadingList) {
                Box(modifier = Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                    Text(
                        text = when {
                            vm.search.isNotBlank() -> "Aucun résultat pour « ${vm.search} »."
                            vm.categoryFilter != null || vm.subCategoryFilter != null ->
                                "Aucun composant dans ce filtre."
                            else -> "Votre inventaire est vide."
                        },
                        style = MaterialTheme.typography.bodyMedium,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                        textAlign = TextAlign.Center,
                        modifier = Modifier.padding(30.dp)
                    )
                }
            } else {
                LazyColumn(
                    modifier = Modifier.fillMaxSize(),
                    contentPadding = PaddingValues(bottom = 24.dp)
                ) {
                    items(visible, key = { it.id }) { component ->
                        ComponentRow(
                            component = component,
                            siteUrl = vm.config.siteUrl,
                            onOpen = { vm.openDetail(component.id) },
                            onDatasheet = {
                                val url = component.datasheetUrl(vm.config.siteUrl)
                                if (url != null && !openUrl(context, url)) {
                                    copyToClipboard(context, url)
                                    vm.showMessage("Lien copié dans le presse-papier.")
                                }
                            },
                            onDelta = { vm.adjustQuantity(component, it) }
                        )
                        HorizontalDivider(color = MaterialTheme.colorScheme.surfaceVariant)
                    }
                }
            }
        }
    }
}

@Composable
private fun ComponentRow(
    component: Component,
    siteUrl: String,
    onOpen: () -> Unit,
    onDatasheet: () -> Unit,
    onDelta: (Int) -> Unit
) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .clickable(onClick = onOpen)
            .padding(horizontal = 14.dp, vertical = 10.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(8.dp)
    ) {
        Box(
            modifier = Modifier
                .size(46.dp)
                .clip(RoundedCornerShape(8.dp))
                .background(MaterialTheme.colorScheme.surfaceVariant),
            contentAlignment = Alignment.Center
        ) {
            val url = component.imageUrl(siteUrl)
            if (url != null) {
                AsyncImage(
                    model = url,
                    contentDescription = component.packageName,
                    contentScale = ContentScale.Fit,
                    modifier = Modifier.fillMaxSize().padding(4.dp)
                )
            } else {
                Text(
                    text = component.name.take(2).uppercase(),
                    style = MaterialTheme.typography.labelMedium,
                    color = MaterialTheme.colorScheme.onSurfaceVariant
                )
            }
        }

        Column(modifier = Modifier.weight(1f)) {
            Text(
                text = component.name.ifBlank { "Sans référence" },
                style = MaterialTheme.typography.titleSmall,
                fontWeight = FontWeight.SemiBold,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis
            )

            val secondary = listOf(component.manufacturer, component.packageName)
                .filter { it.isNotBlank() }
                .joinToString(" · ")
            if (secondary.isNotBlank()) {
                Text(
                    text = secondary,
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis
                )
            }

            if (component.locationLabel.isNotBlank()) {
                Text(
                    text = component.locationLabel,
                    style = MaterialTheme.typography.labelSmall,
                    color = MaterialTheme.colorScheme.primary,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis
                )
            }
        }

        QuantityStepper(quantity = component.quantity, onDelta = onDelta, compact = true)

        if (component.datasheetUrl(siteUrl) != null) {
            RowIcon(
                icon = IconDatasheet,
                description = "Ouvrir la datasheet",
                onClick = onDatasheet
            )
        }

        RowIcon(
            icon = Icons.Default.Info,
            description = "Ouvrir la fiche",
            onClick = onOpen
        )
    }
}

/** Petite icone d'action de la ligne, dimensionnee pour ne pas ecraser le nom. */
@Composable
private fun RowIcon(icon: ImageVector, description: String, onClick: () -> Unit) {
    Box(
        modifier = Modifier
            .size(36.dp)
            .clip(RoundedCornerShape(50))
            .clickable(onClick = onClick),
        contentAlignment = Alignment.Center
    ) {
        Icon(
            imageVector = icon,
            contentDescription = description,
            tint = MaterialTheme.colorScheme.primary,
            modifier = Modifier.size(21.dp)
        )
    }
}
