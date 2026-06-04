<?php

/**
 * Optionale Konfiguration außerhalb von IP-Symcon (z. B. für CLI-Tests).
 * Kopieren nach config.php und Werte eintragen. config.php ist in .gitignore.
 */
return [
    'host' => '192.168.1.1',
    'port' => 12445,
    'token' => 'IHR_API_TOKEN',
    'verify_ssl' => false,
];
