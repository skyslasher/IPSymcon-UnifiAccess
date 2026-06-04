# IPSymcon-UnifiAccess

IP-Symcon-Bibliothek zur Anbindung von **UniFi Access** über die Developer API. Verwaltet ausschließlich **Besucher** (Visitor API), nicht reguläre Access-Benutzer.

Die **PIN** dient als eindeutige Kennung (UID) und wird im Feld `remarks` als `PIN:123456` gespeichert (die API liefert beim Auslesen nur einen PIN-Hash, nicht die Klartext-PIN).

## Voraussetzungen

- IP-Symcon mit PHP 8.x und cURL
- UniFi Access (getestet mit 4.2.x) auf UDM Pro
- API-Token: UniFi OS → **Access** → **Einstellungen** → **API** (Port standardmäßig **12445**)

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
