package fr.compozit.mobile.ui

import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color

/** Couleurs reprises de l'application bureau : degrade indigo vers violet. */
val CompozitIndigo = Color(0xFF667EEA)
val CompozitViolet = Color(0xFF764BA2)

private val LightColors = lightColorScheme(
    primary = CompozitIndigo,
    onPrimary = Color.White,
    primaryContainer = Color(0xFFE3E7FF),
    onPrimaryContainer = Color(0xFF10194F),
    secondary = CompozitViolet,
    onSecondary = Color.White,
    secondaryContainer = Color(0xFFEADDFF),
    onSecondaryContainer = Color(0xFF26104A),
    surface = Color(0xFFFFFFFF),
    surfaceVariant = Color(0xFFF1F2F8),
    onSurfaceVariant = Color(0xFF4A4C57),
    background = Color(0xFFF7F8FC),
    outline = Color(0xFFC7C9D6)
)

private val DarkColors = darkColorScheme(
    primary = Color(0xFF9AA6F5),
    onPrimary = Color(0xFF141A45),
    primaryContainer = Color(0xFF2A3374),
    onPrimaryContainer = Color(0xFFDDE1FF),
    secondary = Color(0xFFC6A9F0),
    onSecondary = Color(0xFF23123F),
    surface = Color(0xFF17181C),
    surfaceVariant = Color(0xFF26282F),
    onSurfaceVariant = Color(0xFFC6C7D0),
    background = Color(0xFF101114),
    outline = Color(0xFF454852)
)

@Composable
fun CompozitTheme(dark: Boolean = isSystemInDarkTheme(), content: @Composable () -> Unit) {
    MaterialTheme(
        colorScheme = if (dark) DarkColors else LightColors,
        content = content
    )
}
