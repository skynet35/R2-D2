package fr.compozit.mobile.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.Place
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import coil.compose.AsyncImage
import fr.compozit.mobile.data.Component

/**
 * Fiche d'un composant : image, stock ajustable, fiche technique et les
 * informations utiles. Le reste du formulaire de la version bureau n'est pas
 * repris ici.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun DetailScreen(vm: AppViewModel) {
    val component = vm.detail

    Scaffold(
        topBar = {
            TopAppBar(
                title = {
                    Text(
                        text = component?.name?.ifBlank { "Fiche composant" } ?: "Fiche composant",
                        maxLines = 1,
                        overflow = TextOverflow.Ellipsis
                    )
                },
                navigationIcon = {
                    IconButton(onClick = { vm.closeDetail() }) {
                        Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Retour")
                    }
                }
            )
        }
    ) { insets ->
        if (component == null) {
            Box(
                modifier = Modifier.padding(insets).fillMaxSize(),
                contentAlignment = Alignment.Center
            ) {
                CircularProgressIndicator()
            }
            return@Scaffold
        }

        Column(
            modifier = Modifier
                .padding(insets)
                .fillMaxSize()
                .verticalScroll(rememberScrollState())
        ) {
            Box(
                modifier = Modifier
                    .fillMaxWidth()
                    .height(180.dp)
                    .background(MaterialTheme.colorScheme.surfaceVariant),
                contentAlignment = Alignment.Center
            ) {
                val url = component.imageUrl(vm.config.siteUrl)
                if (url != null) {
                    AsyncImage(
                        model = url,
                        contentDescription = component.packageName,
                        contentScale = ContentScale.Fit,
                        modifier = Modifier.fillMaxSize().padding(12.dp)
                    )
                } else {
                    Text(
                        text = "Aucune image pour ce composant",
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant
                    )
                }
            }

            Column(
                modifier = Modifier.padding(16.dp),
                verticalArrangement = Arrangement.spacedBy(14.dp)
            ) {
                LocationCard(component = component)
                StockCard(component = component, vm = vm)
                DatasheetCard(component = component, vm = vm)
                InformationsCard(component = component)
            }
        }
    }
}

/**
 * Emplacement physique du composant : casier, tiroir et compartiment. C'est
 * l'information la plus utile quand on cherche la piece devant l'etagere,
 * elle a donc sa propre carte mise en avant.
 */
@Composable
private fun LocationCard(component: Component) {
    Card(
        modifier = Modifier.fillMaxWidth(),
        colors = CardDefaults.cardColors(
            containerColor = MaterialTheme.colorScheme.primaryContainer
        )
    ) {
        Row(
            modifier = Modifier.fillMaxWidth().padding(16.dp),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(16.dp)
        ) {
            Icon(
                imageVector = Icons.Filled.Place,
                contentDescription = null,
                tint = MaterialTheme.colorScheme.onPrimaryContainer,
                modifier = Modifier.size(32.dp)
            )

            Column {
                Text(
                    text = "Emplacement",
                    style = MaterialTheme.typography.labelMedium,
                    color = MaterialTheme.colorScheme.onPrimaryContainer
                )
                Text(
                    text = component.locationLabel.ifBlank { "Non renseigné" },
                    style = MaterialTheme.typography.headlineSmall,
                    fontWeight = FontWeight.SemiBold,
                    color = MaterialTheme.colorScheme.onPrimaryContainer
                )
            }
        }
    }
}

@Composable
private fun StockCard(component: Component, vm: AppViewModel) {
    Card(modifier = Modifier.fillMaxWidth()) {
        Row(
            modifier = Modifier.fillMaxWidth().padding(14.dp),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.SpaceBetween
        ) {
            Column {
                Text("Stock", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.SemiBold)
                Text(
                    text = "Quantité en inventaire",
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant
                )
            }
            QuantityStepper(quantity = component.quantity, onDelta = { vm.adjustQuantity(component, it) })
        }
    }
}

@Composable
private fun DatasheetCard(component: Component, vm: AppViewModel) {
    val context = LocalContext.current
    val url = component.datasheetUrl(vm.config.siteUrl)

    Card(modifier = Modifier.fillMaxWidth()) {
        Column(
            modifier = Modifier.fillMaxWidth().padding(14.dp),
            verticalArrangement = Arrangement.spacedBy(8.dp)
        ) {
            Text("Fiche technique", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.SemiBold)

            Button(
                onClick = {
                    if (url != null && !openUrl(context, url)) {
                        copyToClipboard(context, url)
                        vm.showMessage("Lien copié dans le presse-papier.")
                    }
                },
                enabled = url != null,
                modifier = Modifier.fillMaxWidth()
            ) {
                Text("Ouvrir le datasheet")
            }

            if (url == null) {
                Text(
                    text = "Aucun datasheet enregistré pour ce composant.",
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant
                )
            }
        }
    }
}

@Composable
private fun InformationsCard(component: Component) {
    Card(modifier = Modifier.fillMaxWidth()) {
        Column(modifier = Modifier.fillMaxWidth().padding(14.dp)) {
            Text("Informations", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.SemiBold)

            InfoRow("Référence", component.name)
            InfoRow("Fabricant", component.manufacturer)
            InfoRow("Boîtier", component.packageName)
            InfoRow("Broches", component.pins?.toString().orEmpty())
            InfoRow(
                label = "Catégorie",
                value = listOf(component.categoryHead, component.categorySub)
                    .filter { it.isNotBlank() }
                    .joinToString(" / ")
            )
            InfoRow("Réf. fournisseur", component.supplierReference)
            InfoRow("Prix", if (component.price.isBlank()) "" else "${component.price} €")
            InfoRow("Commentaire", component.comment)
        }
    }
}
