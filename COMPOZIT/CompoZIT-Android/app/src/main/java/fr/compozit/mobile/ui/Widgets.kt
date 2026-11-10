package fr.compozit.mobile.ui

import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import android.content.Intent
import android.net.Uri
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.ArrowDropDown
import androidx.compose.material.icons.filled.Check
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.graphics.vector.path
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp

/** Bandeau degrade commun aux ecrans, aux couleurs de l'application bureau. */
@Composable
fun GradientHeader(title: String, subtitle: String, modifier: Modifier = Modifier) {
    Box(
        modifier = modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(0.dp, 0.dp, 20.dp, 20.dp))
            .background(Brush.linearGradient(listOf(CompozitIndigo, CompozitViolet)))
            .padding(horizontal = 22.dp, vertical = 24.dp)
    ) {
        Column {
            Text(
                text = title,
                style = MaterialTheme.typography.headlineSmall,
                fontWeight = FontWeight.Bold,
                color = androidx.compose.ui.graphics.Color.White
            )
            if (subtitle.isNotBlank()) {
                Text(
                    text = subtitle,
                    style = MaterialTheme.typography.bodyMedium,
                    color = androidx.compose.ui.graphics.Color.White.copy(alpha = 0.85f)
                )
            }
        }
    }
}

/**
 * Compteur de stock : bouton moins, quantite, bouton plus. Utilise a
 * l'identique dans la liste et dans la fiche pour que l'ajustement soit
 * toujours au meme endroit.
 */
@Composable
fun QuantityStepper(
    quantity: Int,
    onDelta: (Int) -> Unit,
    modifier: Modifier = Modifier,
    compact: Boolean = false
) {
    val buttonSize = if (compact) 30.dp else 40.dp

    Row(
        modifier = modifier,
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(2.dp)
    ) {
        IconButton(onClick = { onDelta(-1) }, modifier = Modifier.size(buttonSize)) {
            Text(
                text = "−",
                style = if (compact) MaterialTheme.typography.titleMedium else MaterialTheme.typography.titleLarge,
                color = MaterialTheme.colorScheme.primary
            )
        }

        Text(
            text = quantity.toString(),
            style = if (compact) MaterialTheme.typography.titleMedium else MaterialTheme.typography.headlineSmall,
            fontWeight = FontWeight.SemiBold,
            textAlign = TextAlign.Center,
            color = if (quantity == 0) MaterialTheme.colorScheme.error else MaterialTheme.colorScheme.onSurface,
            modifier = Modifier.width(if (compact) 34.dp else 52.dp)
        )

        IconButton(onClick = { onDelta(1) }, modifier = Modifier.size(buttonSize)) {
            Text(
                text = "+",
                style = if (compact) MaterialTheme.typography.titleMedium else MaterialTheme.typography.titleLarge,
                color = MaterialTheme.colorScheme.primary
            )
        }
    }
}

/** Ligne « libelle : valeur » de la fiche composant. */
@Composable
fun InfoRow(label: String, value: String) {
    Row(modifier = Modifier.fillMaxWidth().padding(vertical = 5.dp)) {
        Text(
            text = label,
            style = MaterialTheme.typography.bodySmall,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
            modifier = Modifier.width(118.dp)
        )
        Text(
            text = value.ifBlank { "—" },
            style = MaterialTheme.typography.bodyMedium,
            modifier = Modifier.weight(1f)
        )
    }
}

/**
 * Liste deroulante compacte utilisee pour les filtres de l'inventaire. Le
 * bouton affiche le libelle du filtre et la valeur retenue ; la liste propose
 * « Toutes » pour revenir a l'inventaire complet.
 */
@Composable
fun FilterDropdown(
    label: String,
    options: List<String>,
    selected: String?,
    onSelect: (String?) -> Unit,
    modifier: Modifier = Modifier
) {
    var open by remember { mutableStateOf(false) }

    Box(modifier = modifier) {
        OutlinedButton(
            onClick = { open = true },
            modifier = Modifier.fillMaxWidth(),
            contentPadding = PaddingValues(horizontal = 12.dp, vertical = 6.dp)
        ) {
            Column(modifier = Modifier.weight(1f)) {
                Text(
                    text = label,
                    style = MaterialTheme.typography.labelSmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                    maxLines = 1
                )
                Text(
                    text = selected ?: "Toutes",
                    style = MaterialTheme.typography.bodyMedium,
                    fontWeight = if (selected == null) FontWeight.Normal else FontWeight.SemiBold,
                    color = MaterialTheme.colorScheme.onSurface,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis
                )
            }
            Icon(
                imageVector = Icons.Default.ArrowDropDown,
                contentDescription = null,
                tint = MaterialTheme.colorScheme.onSurfaceVariant
            )
        }

        DropdownMenu(expanded = open, onDismissRequest = { open = false }) {
            DropdownMenuItem(
                text = { Text("Toutes") },
                trailingIcon = { if (selected == null) Icon(Icons.Default.Check, contentDescription = null) },
                onClick = {
                    open = false
                    onSelect(null)
                }
            )
            options.forEach { option ->
                DropdownMenuItem(
                    text = { Text(option) },
                    trailingIcon = { if (option == selected) Icon(Icons.Default.Check, contentDescription = null) },
                    onClick = {
                        open = false
                        onSelect(option)
                    }
                )
            }
        }
    }
}

/** Logo « datasheet » : une feuille avec un coin plie, dessinee localement. */
val IconDatasheet: ImageVector by lazy {
    ImageVector.Builder(
        name = "Datasheet",
        defaultWidth = 24.dp,
        defaultHeight = 24.dp,
        viewportWidth = 24f,
        viewportHeight = 24f
    ).apply {
        path(fill = SolidColor(Color.Black)) {
            moveTo(14f, 2f)
            horizontalLineTo(6f)
            curveTo(4.9f, 2f, 4.01f, 2.9f, 4.01f, 4f)
            lineTo(4f, 20f)
            curveTo(4f, 21.1f, 4.89f, 22f, 6f, 22f)
            horizontalLineTo(18f)
            curveTo(19.1f, 22f, 20f, 21.1f, 20f, 20f)
            verticalLineTo(8f)
            lineTo(14f, 2f)
            close()
            moveTo(16f, 18f)
            horizontalLineTo(8f)
            verticalLineTo(16f)
            horizontalLineTo(16f)
            verticalLineTo(18f)
            close()
            moveTo(16f, 14f)
            horizontalLineTo(8f)
            verticalLineTo(12f)
            horizontalLineTo(16f)
            verticalLineTo(14f)
            close()
            moveTo(13f, 9f)
            verticalLineTo(3.5f)
            lineTo(18.5f, 9f)
            horizontalLineTo(13f)
            close()
        }
    }.build()
}

/** Ouvre l'URL dans l'application adaptee. Renvoie false si aucune ne repond. */
fun openUrl(context: Context, url: String): Boolean = runCatching {
    context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url)).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
}.isSuccess

/** Repli quand aucun navigateur ni lecteur PDF ne repond au lien. */
fun copyToClipboard(context: Context, url: String) {
    val clipboard = context.getSystemService(Context.CLIPBOARD_SERVICE) as ClipboardManager
    clipboard.setPrimaryClip(ClipData.newPlainText("datasheet", url))
}
