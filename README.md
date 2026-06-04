# IPSymcon-UnifiAccess

IP-Symcon-Bibliothek zur Anbindung von **UniFi Access** über die Developer API. Verwaltet ausschließlich **Besucher** (Visitor API), nicht reguläre Access-Benutzer.

Die **PIN** dient als eindeutige Kennung (UID) und wird im Feld `remarks` als `PIN:123456` gespeichert. `expand[]=pin_code` liefert nur den PIN-Hash (`pin_code.token`), nicht die Klartext-PIN. `GET /visitors` (Liste) enthält `remarks` oft nicht; `UAF_GetAllVisitors` und die PIN-Suche laden sie bei Bedarf über `GET /visitors/:id` nach.

## Voraussetzungen

- IP-Symcon mit PHP 8.x und cURL
- UniFi Access (getestet mit 4.2.x) auf UDM Pro
- API-Port standardmäßig **12445** (HTTPS)

## API-Token einrichten

Der **Access Developer API-Token** ist **nicht** derselbe Schlüssel wie unter Network/Protect (**Einstellungen → Control Plane → Integrationen**). Für dieses Modul brauchst du einen Token direkt in der Access-App:

1. UniFi OS öffnen → **Access** starten
2. **Einstellungen** → **Allgemein** → **Erweitert** → **API**
3. Neuen API-Token anlegen und beim Erstellen diese Berechtigungen aktivieren:

| Berechtigung | Zweck in diesem Modul |
|--------------|------------------------|
| `view:space` | Verbindungstest, Türen/Türgruppen |
| `view:policy` | Zugangsprofile lesen (`GetAccessProfiles`) |
| `view:visitor` | Besucher suchen und auslesen |
| `edit:visitor` | Besucher anlegen, ändern, löschen |
| `edit:credential` | PIN und QR-Code zuweisen |

4. Token, Host (IP der UDM) und Port `12445` in der Instanz eintragen

**Verbindungstest schlägt mit HTTP 401 fehl?**

- Falscher Token-Typ (Network/Protect-Key statt Access-API-Token) – siehe Pfad oben
- Token abgelaufen oder gelöscht – neuen Token anlegen
- Fehlende Berechtigungen – mindestens `view:space` für den Test, alle fünf Keys für volle Funktion
- Falscher Host/Port oder Firewall blockiert Port 12445

## Installation in IP-Symcon

1. Repository klonen oder ZIP laden:
   ```bash
   git clone https://github.com/skyslasher/IPSymcon-UnifiAccess.git
   ```
2. In IP-Symcon: **Modulverwaltung** → **Modul hinzufügen** → Pfad zum Repository-Ordner
3. **Instanz hinzufügen** → **UniFi Access IO**
4. Host (IP der UDM), Port `12445`, API-Token eintragen, SSL-Prüfung bei selbstsigniertem Zertifikat deaktivieren

## Skript-Funktionen (Prefix `UAF_`)

| Funktion | Beschreibung |
|----------|--------------|
| `UAF_TestConnection($InstanceID)` | API-Verbindung prüfen (auch über Button in der Instanzkonfiguration nach „Übernehmen“) |
| `UAF_GetAccessProfiles($InstanceID)` | Zugangsprofile (Access Policies) als Liste mit `id` und `name` |
| `UAF_CreateVisitor($InstanceID, $pin, $vorname, $nachname, $policyId, $start, $ende, $email, $telefon)` | Besucher anlegen, Ressourcen aus Policy, PIN zuweisen |
| `UAF_FindVisitorByPin($InstanceID, $pin)` | Besucher anhand PIN finden |
| `UAF_GetAllVisitors($InstanceID)` | Alle Besucher als normalisierte Liste (`id`, Name, PIN aus `remarks` – ggf. Detail-Abfrage pro Eintrag, Zeiten, Status, Ressourcen) |
| `UAF_UpdateVisitor(...)` | Besucher ändern |
| `UAF_DeleteVisitor($InstanceID, $pin)` | Besucher löschen |
| `UAF_CreateQrCode($InstanceID, $pin)` | QR-Code für Besucher erzeugen |
| `UAF_DownloadQrCode($InstanceID, $pin, $zielPfad)` | QR-Code als Datei speichern |

**Alias** (ältere Benennung): `UAF_CreateUser`, `UAF_FindUserByPin`, `UAF_UpdateUser`, `UAF_DeleteUser` – rufen dieselben Besucher-Funktionen auf.

### Beispiel

```php
$io = 12345; // Instanz-ID

$profile = UAF_GetAccessProfiles($io);
$policyId = $profile[0]['id'];

UAF_CreateVisitor($io, '47110815', 'Max', 'Mustermann', $policyId, time(), time() + 86400 * 7);

$visitor = UAF_FindVisitorByPin($io, '47110815');

$alle = UAF_GetAllVisitors($io);
foreach ($alle as $eintrag) {
    echo $eintrag['first_name'] . ' ' . $eintrag['last_name'] . ' (PIN: ' . ($eintrag['pin'] ?? '–') . ")\n";
}

UAF_CreateQrCode($io, '47110815');
UAF_DownloadQrCode($io, '47110815', IPS_GetLogDir() . 'besucher-qr.png');
```

## Optionale Datei-Konfiguration

Für Tests außerhalb von Symcon: `libs/config.example.php` nach `libs/config.php` kopieren.

## GitHub

Repository anlegen und pushen:

```bash
cd IPSymcon-UnifiAccess
git add .
git commit -m "Initial commit: UniFi Access Besucher-Modul für IP-Symcon"
git branch -M main
git remote add origin https://github.com/skyslasher/IPSymcon-UnifiAccess.git
git push -u origin main
```

## Lizenz

MIT
