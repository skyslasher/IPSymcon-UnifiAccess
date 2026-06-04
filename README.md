# IPSymcon-UnifiAccess

IP-Symcon-Bibliothek zur Anbindung von **UniFi Access** über die Developer API. Verwaltet ausschließlich **Besucher** (Visitor API), nicht reguläre Access-Benutzer.

Die **Booking-ID** dient als eindeutige Kennung (UID) und wird beim Anlegen im Feld `remarks` als `BOOKING:{bookingId}` gespeichert. Die **PIN** wird ausschließlich über die Credential-Ressource `PUT /visitors/:id/pin_codes` zugewiesen – nicht in `remarks` und nicht in der öffentlichen Besucherliste.

Die UniFi-API liefert die Klartext-PIN **nicht** zuverlässig über `expand[]=pin_code`. `GET /visitors` (Liste) enthält `remarks` oft leer oder gar nicht.

`UAF_GetAllVisitors` und die Booking-Suche reichern Besucher deshalb bei leeren `remarks` per `GET /visitors/:id` an. Die normalisierte Liste enthält **keine** PIN-Felder (`pin`, `pin_code_token`) – nur `booking_id` als UID.

### PIN ändern (`UpdateVisitor`)

Wird `$pin` übergeben (nicht `null` und nicht leer), vergleicht das Modul die neue PIN intern mit der aktuellen Klartext-PIN (falls lesbar). Ist kein Klartext verfügbar, wird die PIN-Ressource neu gesetzt:

1. optional `DELETE /visitors/:id/pin_codes`
2. `PUT /visitors/:id/pin_codes` mit `{"pin_code":"..."}`

Stammdaten (Name, Zeiten, Policy) werden weiterhin über `PUT /visitors/:id` aktualisiert; `remarks` bleibt `BOOKING:{bookingId}`.

## Türgruppen (`UAF_GetDoorGroups`)

Für die Auswahl von Zugangsressourcen beim Anlegen von Besuchern ist die **Türgruppen-Liste** die primäre Funktion (Berechtigung `view:space`).

Quelle: `GET /api/v1/developer/door_groups/topology` (Gebäudegruppe „All Doors“ + benutzerdefinierte Gruppen), ergänzt um `GET /door_groups` falls einzelne `access`-Gruppen in der Topology fehlen.

Jeder Eintrag:

| Feld | Beschreibung |
|------|----------------|
| `id` | UUID der Türgruppe (für `resources` bei Besuchern) |
| `name` | Anzeigename (z. B. „All Doors“, „Eingang Nord“) |
| `type` | `building` = alle Türen des Standorts; `door_group` = benutzerdefinierte Gruppe (API-Typ `access`) |
| `resource_topologies` | optional: Etagen und Türen (nur aus Topology, bei `building` und oft bei Gruppen) |
| `resources` | optional: flache Tür-IDs (aus `GET /door_groups`, wenn vorhanden) |

`UAF_GetAccessProfiles` bleibt für **Policy-basiertes** Anlegen über `UAF_CreateVisitor` (Parameter `$policyId` → Ressourcen aus der Access Policy). Für direkte Türgruppen-Zuweisung an Besucher ist die Visitor-API künftig erweiterbar – aktuell nur Listing.

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
| `view:space` | Verbindungstest, Türgruppen (`GetDoorGroups`) |
| `view:policy` | Zugangsprofile lesen (`GetAccessProfiles`, nur für Policy-basiertes CreateVisitor) |
| `view:visitor` | Besucher suchen und auslesen |
| `edit:visitor` | Besucher anlegen, ändern, löschen |
| `edit:credential` | PIN und QR-Code zuweisen |

4. Token, Host (IP der UDM) und Port `12445` in der Instanz eintragen

**Verbindungstest schlägt mit HTTP 401 fehl?**

- Falscher Token-Typ (Network/Protect-Key statt Access-API-Token) – siehe Pfad oben
- Token abgelaufen oder gelöscht – neuen Token anlegen
- Fehlende Berechtigungen – mindestens `view:space` für den Test und Türgruppen, alle fünf Keys für volle Funktion inkl. Policy-Anlegen
- Falscher Host/Port oder Firewall blockiert Port 12445

## Installation in IP-Symcon

1. Repository klonen oder ZIP laden:
   ```bash
   git clone https://github.com/skyslasher/IPSymcon-UnifiAccess.git
   ```
2. In IP-Symcon: **Modulverwaltung** → **Modul hinzufügen** → Pfad zum Repository-Ordner
3. **Instanz hinzufügen** → **UniFi Access IO**
4. Host (IP der UDM), Port `12445`, API-Token eintragen, SSL-Prüfung bei selbstsigniertem Zertifikat deaktivieren

## Migration von Build 2 (PIN als UID)

Besucher aus älteren Versionen haben `PIN:{pin}` in `remarks`. Ab Build 3 ist die UID `BOOKING:{bookingId}`.

**Optionen:**

- Besucher neu anlegen mit `UAF_CreateVisitor` und echter Booking-ID
- Oder `remarks` in UniFi Access manuell auf `BOOKING:{deineBookingId}` setzen (PIN-Ressource bleibt unverändert)

Die veraltete Funktion `UAF_FindVisitorByPin` findet weiterhin Besucher mit Legacy-`PIN:`-remarks oder per Klartext-PIN aus der API.

## Skript-Funktionen (Prefix `UAF_`)

| Funktion | Beschreibung |
|----------|--------------|
| `UAF_TestConnection($InstanceID)` | API-Verbindung prüfen (auch über Button in der Instanzkonfiguration nach „Übernehmen“) |
| `UAF_GetDoorGroups($InstanceID)` | Türgruppen: `id`, `name`, `type` (`building` / `door_group`), optional `resource_topologies` / `resources` |
| `UAF_GetAccessProfiles($InstanceID)` | Zugangsprofile (Access Policies) – für `CreateVisitor` mit `$policyId` |
| `UAF_CreateVisitor($InstanceID, $bookingId, $pin, $vorname, $nachname, $policyId, $start, $ende, $email, $telefon)` | Besucher anlegen: Booking-ID in remarks, PIN über Credential-Ressource |
| `UAF_FindVisitorByBookingId($InstanceID, $bookingId)` | Besucher anhand Booking-ID finden |
| `UAF_GetAllVisitors($InstanceID)` | Alle Besucher als normalisierte Liste (`id`, Name, `booking_id`, Zeiten, Status, Ressourcen – ohne PIN-Felder) |
| `UAF_UpdateVisitor($InstanceID, $bookingId, $vorname, $nachname, $policyId, $start, $ende, $email, $telefon, $pin)` | Besucher ändern; `$pin` optional – bei Angabe PIN über Credential-Ressource neu setzen |
| `UAF_DeleteVisitor($InstanceID, $bookingId)` | Besucher löschen |
| `UAF_CreateQrCode($InstanceID, $bookingId)` | QR-Code für Besucher erzeugen |
| `UAF_DownloadQrCode($InstanceID, $bookingId, $zielPfad)` | QR-Code als Datei speichern |

**Veraltet:** `UAF_FindVisitorByPin` – Suche nach Klartext-PIN (Legacy).

**Alias** (ältere Benennung): `UAF_CreateUser`, `UAF_FindUserByPin`, `UAF_UpdateUser`, `UAF_DeleteUser` – rufen veraltete bzw. kompatible Besucher-Funktionen auf.

### Beispiel

```php
$io = 12345; // Instanz-ID
$bookingId = 'RES-2026-0042';
$pin = '47110815';

$groups = UAF_GetDoorGroups($io);
foreach ($groups as $group) {
    echo $group['name'] . ' [' . $group['type'] . '] → ' . $group['id'] . "\n";
}

// Policy-basiertes Anlegen (weiterhin über Access Profile):
$profile = UAF_GetAccessProfiles($io);
$policyId = $profile[0]['id'];

UAF_CreateVisitor($io, $bookingId, $pin, 'Max', 'Mustermann', $policyId, time(), time() + 86400 * 7);

$visitor = UAF_FindVisitorByBookingId($io, $bookingId);

// PIN ändern
UAF_UpdateVisitor($io, $bookingId, 'Max', 'Mustermann', $policyId, 0, 0, '', '', '99887766');

$alle = UAF_GetAllVisitors($io);
foreach ($alle as $eintrag) {
    echo $eintrag['first_name'] . ' ' . $eintrag['last_name']
        . ' (Booking: ' . ($eintrag['booking_id'] ?? '–') . ")\n";
}

UAF_CreateQrCode($io, $bookingId);
UAF_DownloadQrCode($io, $bookingId, IPS_GetLogDir() . 'besucher-qr.png');
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
