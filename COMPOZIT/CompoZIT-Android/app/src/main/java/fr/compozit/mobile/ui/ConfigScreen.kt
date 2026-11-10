package fr.compozit.mobile.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import fr.compozit.mobile.data.SiteConfig
import kotlinx.coroutines.launch

/**
 * Reglages de l'application : la seule adresse du site CompoZ'IT.
 *
 * Les acces MySQL ne sont plus saisis sur le telephone. Le site detient la
 * connexion a la base et expose une API JSON ; l'application ne parle qu'en
 * HTTPS, ce qui reste valable quelle que soit l'adresse IP du telephone.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ConfigScreen(
    initial: SiteConfig,
    onSave: (SiteConfig) -> Unit,
    onBack: () -> Unit,
    test: suspend (SiteConfig) -> Result<String>
) {
    var siteUrl by remember { mutableStateOf(initial.siteUrl) }

    var testing by remember { mutableStateOf(false) }
    var result by remember { mutableStateOf<String?>(null) }
    var resultOk by remember { mutableStateOf(false) }

    val scope = rememberCoroutineScope()

    fun candidate() = SiteConfig(siteUrl.trim())

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("Réglages du site") },
                navigationIcon = {
                    IconButton(onClick = onBack) {
                        Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Retour")
                    }
                }
            )
        }
    ) { insets ->
        Column(
            modifier = Modifier
                .padding(insets)
                .fillMaxSize()
                .verticalScroll(rememberScrollState())
                .padding(20.dp),
            verticalArrangement = Arrangement.spacedBy(14.dp)
        ) {
            Text(
                text = "Indiquez l'adresse du site CompoZ'IT. L'application s'y connecte en HTTPS : " +
                    "aucun identifiant de base de données n'est enregistré sur ce téléphone.",
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant
            )

            OutlinedTextField(
                value = siteUrl,
                onValueChange = { siteUrl = it },
                label = { Text("Adresse du site") },
                supportingText = { Text("Exemple : ${SiteConfig.DEFAULT_SITE}") },
                singleLine = true,
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Uri),
                modifier = Modifier.fillMaxWidth()
            )

            Row(horizontalArrangement = Arrangement.spacedBy(12.dp), modifier = Modifier.fillMaxWidth()) {
                OutlinedButton(
                    onClick = {
                        val target = candidate()
                        if (!target.isComplete) {
                            resultOk = false
                            result = "L'adresse doit commencer par http:// ou https://"
                            return@OutlinedButton
                        }
                        testing = true
                        result = null
                        scope.launch {
                            val outcome = test(target)
                            testing = false
                            resultOk = outcome.isSuccess
                            result = outcome.getOrElse { it.message ?: "Échec de la connexion." }
                        }
                    },
                    enabled = !testing,
                    modifier = Modifier.weight(1f)
                ) {
                    if (testing) {
                        CircularProgressIndicator(modifier = Modifier.padding(end = 8.dp), strokeWidth = 2.dp)
                    }
                    Text("Tester")
                }

                Button(
                    onClick = {
                        val target = candidate()
                        if (!target.isComplete) {
                            resultOk = false
                            result = "L'adresse doit commencer par http:// ou https://"
                            return@Button
                        }
                        onSave(target)
                        onBack()
                    },
                    modifier = Modifier.weight(1f)
                ) {
                    Text("Enregistrer")
                }
            }

            result?.let { text ->
                Text(
                    text = text,
                    style = MaterialTheme.typography.bodyMedium,
                    color = if (resultOk) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.error,
                    modifier = Modifier.fillMaxWidth()
                )
            }

            Card(modifier = Modifier.fillMaxWidth()) {
                Column(
                    modifier = Modifier.fillMaxWidth().padding(14.dp),
                    verticalArrangement = Arrangement.spacedBy(6.dp)
                ) {
                    Text(
                        text = "Adresse appelée",
                        style = MaterialTheme.typography.titleSmall,
                        fontWeight = FontWeight.SemiBold
                    )
                    Text(
                        text = candidate().apiUrl,
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant
                    )
                }
            }
        }
    }
}
