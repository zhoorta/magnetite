package app.magnetite.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.verticalScroll
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.Button
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import app.magnetite.data.Api
import app.magnetite.data.ApiException
import app.magnetite.data.DeviceCode
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/** TV sign-in: type the server once, then approve a short code on a phone or computer. The password form stays as a fallback. */
@Composable
fun TvLoginScreen(api: Api, lastServer: String, lastUsername: String, onSignedIn: () -> Unit) {
    var server by remember { mutableStateOf(lastServer) }
    var code by remember { mutableStateOf<DeviceCode?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var usePassword by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()

    if (usePassword) {
        LoginScreen(api, server, lastUsername, onSignedIn)
        return
    }

    // While a code is on screen, ask the server until it is approved; a new code replaces one that expired.
    val shown = code
    LaunchedEffect(shown?.deviceCode) {
        if (shown == null) return@LaunchedEffect
        val until = System.currentTimeMillis() + shown.expiresIn * 1000L
        while (System.currentTimeMillis() < until) {
            delay(shown.interval * 1000L)
            try {
                if (api.pollDeviceLink(shown.deviceCode) != null) { onSignedIn(); return@LaunchedEffect }
            } catch (e: CancellationException) {
                throw e
            } catch (e: ApiException) {
                if (e.message == "expired_token") break
                // Any other answer (a hiccup, throttling): keep asking until the code runs out.
            } catch (e: Exception) {
                // Offline for a moment: keep asking.
            }
        }
        code = null
        error = "The code expired. Get a new one."
    }

    androidx.compose.material3.Surface(Modifier.fillMaxSize(), color = MaterialTheme.colorScheme.background) {
    Column(
        Modifier.fillMaxSize().verticalScroll(androidx.compose.foundation.rememberScrollState()).padding(horizontal = 48.dp, vertical = 24.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp, Alignment.CenterVertically),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        if (shown == null) {
            Logo(Modifier.width(200.dp).padding(bottom = 8.dp))
            OutlinedTextField(
                server, { server = it }, Modifier.widthIn(max = 480.dp).fillMaxWidth().dpadLeavesField(), singleLine = true,
                label = { Text("Server (https://…)") },
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Uri),
            )
            error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
            Button(
                onClick = {
                    error = null
                    scope.launch {
                        try { code = api.startDeviceLink(server) } catch (e: CancellationException) { throw e } catch (e: Exception) { error = e.message ?: "Could not reach the server" }
                    }
                },
                enabled = server.isNotBlank(),
            ) { Text("Sign in with a code") }
            TextButton({ usePassword = true }, enabled = server.isNotBlank()) { Text("Use username and password") }
        } else {
            Text("On your phone or computer, open", style = MaterialTheme.typography.titleMedium)
            Text(shown.verificationUrl, style = MaterialTheme.typography.headlineSmall, color = MaterialTheme.colorScheme.primary)
            Text("and enter", style = MaterialTheme.typography.titleMedium)
            Text(shown.userCode, fontSize = 56.sp, fontFamily = FontFamily.Monospace, letterSpacing = 6.sp)
            Text("Waiting for you to approve…", style = MaterialTheme.typography.bodyMedium)
            TextButton({ code = null }) { Text("Cancel") }
        }
    }
    }
}
