package fr.compozit.mobile

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.lifecycle.viewmodel.compose.viewModel
import fr.compozit.mobile.ui.AppViewModel
import fr.compozit.mobile.ui.CompozitTheme
import fr.compozit.mobile.ui.ConfigScreen
import fr.compozit.mobile.ui.DetailScreen
import fr.compozit.mobile.ui.InventoryScreen
import fr.compozit.mobile.ui.LoginScreen
import fr.compozit.mobile.ui.Screen

class MainActivity : ComponentActivity() {

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        setContent {
            CompozitTheme {
                val vm: AppViewModel = viewModel()
                val snackbarHost = remember { SnackbarHostState() }

                LaunchedEffect(vm.message) {
                    val text = vm.message ?: return@LaunchedEffect
                    snackbarHost.showSnackbar(text)
                    vm.dismissMessage()
                }

                Box(modifier = Modifier.fillMaxSize()) {
                    when (val current = vm.screen) {
                        Screen.Config -> ConfigScreen(
                            initial = vm.config,
                            onSave = vm::saveConfig,
                            onBack = vm::leaveSettings,
                            test = vm::testConnection
                        )

                        Screen.Login -> LoginScreen(
                            siteLabel = vm.config.label,
                            initialEmail = vm.savedEmail,
                            busy = vm.busy,
                            onLogin = vm::login,
                            onSettings = vm::openSettings
                        )

                        Screen.Inventory -> InventoryScreen(vm)

                        is Screen.Detail -> DetailScreen(vm)
                    }

                    SnackbarHost(
                        hostState = snackbarHost,
                        modifier = Modifier.align(Alignment.BottomCenter)
                    )
                }
            }
        }
    }
}
