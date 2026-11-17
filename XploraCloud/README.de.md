# Xplora Cloud

Hält die Anmeldung an einem Xplora-Elternkonto und leitet die Anfragen der Instanzen „Xplora Watch“ und
„Xplora Configurator“ an die Xplora-Cloud weiter.

[English version](README.md)

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Installation](#3-installation)
4. [Konfiguration](#4-konfiguration)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [PHP-Befehlsreferenz](#6-php-befehlsreferenz)
7. [Fehlersuche](#7-fehlersuche)

### 1. Funktionsumfang

* Anmeldung mit E-Mail-Adresse oder Telefonnummer und Passwort der Xplora-Eltern-App
* Hält die Sitzung und meldet sich bei Ablauf automatisch neu an
* Pausiert alle Anfragen für eine Stunde, wenn Xplora drosselt (HTTP 429)

### 2. Voraussetzungen

- IP-Symcon 7.0 oder neuer
- Xplora-Elternkonto mit mindestens einer Uhr
- Internetzugang zu `api.prod.myxplora.com`

### 3. Installation

* Bibliothek „Xplora“ über den Module Store oder Module Control installieren.
* Die Instanz wird mit der ersten Instanz „Xplora Configurator“ bzw. „Xplora Watch“ automatisch angelegt,
  oder manuell über „Instanz hinzufügen“ → „Xplora Cloud“.

### 4. Konfiguration

| Name | Beschreibung |
| --- | --- |
| E-Mail-Adresse | Anmeldung der Eltern-App (wenn das Konto eine E-Mail-Adresse nutzt) |
| Ländervorwahl | ohne „+“, z. B. 43 oder 49 (Anmeldung mit Telefonnummer) |
| Telefonnummer (ohne führende 0) | Anmeldung mit Telefonnummer |
| Passwort | Passwort der Eltern-App |
| Sprache | Sprache von Adressen und Meldungen von Xplora |
| Verbindung testen | meldet sich an und zeigt die Uhren des Kontos |

Das Passwort wird – wie in der Eltern-App – nur als MD5-Prüfsumme an Xplora gesendet.

### 5. Statusvariablen und Profile

Keine.

### 6. PHP-Befehlsreferenz

`string XPLC_TestConnection(int $InstanzID);`
Meldet sich an und liefert einen Text mit dem Ergebnis und den Namen der Uhren.

Beispiel: `echo XPLC_TestConnection(12345);`

### 7. Fehlersuche

| Status / Meldung | Ursache und Abhilfe |
| --- | --- |
| Keine Zugangsdaten eingetragen | E-Mail-Adresse bzw. Ländervorwahl + Telefonnummer und Passwort eintragen |
| Anmeldung abgelehnt | Zugangsdaten in der Eltern-App prüfen |
| Von Xplora gedrosselt | zu viele Anfragen; Abfragen ruhen eine Stunde – Abfrageintervalle der Uhren verlängern |
| Keine Verbindung zur Xplora-Cloud | Internetverbindung prüfen |
