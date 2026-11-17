# Xplora

Einbindung von Xplora-Kinder-Smartwatches über die Xplora-Cloud (derselbe Dienst, den die Xplora-Eltern-App nutzt).

[English version](README.md)

Die Xplora-Schnittstelle ist nicht offiziell dokumentiert. Die Bibliothek verwendet dieselben Anfragen wie die
Open-Source-Projekte [pyxplora_api](https://github.com/Ludy87/pyxplora_api) und
[ha-xplora-watch](https://github.com/acdcnow/ha-xplora-watch). Xplora kann die Schnittstelle jederzeit ändern.

## Module

* [Xplora Cloud](XploraCloud/README.de.md) – Anmeldung am Xplora-Elternkonto, Verbindung zur Cloud
* [Xplora Configurator](XploraConfigurator/README.de.md) – listet die Uhren des Kontos und legt die Uhren-Instanzen an
* [Xplora Watch](XploraWatch/README.de.md) – Standort, Adresse, Sicherheitszone, Akku und Ladezustand einer Uhr, optional Statuskachel und Verlauf

## Schnellstart

1. Bibliothek über den Module Store oder Kern Instanzen → Modules (Module Control) → **+** → `https://github.com/cbeham1/SymconXplora` installieren.
2. Instanz **Xplora Configurator** hinzufügen. IP-Symcon legt dabei die Instanz **Xplora Cloud** mit an.
3. In der Instanz „Xplora Cloud“ die Zugangsdaten der Xplora-Eltern-App eintragen und **Verbindung testen** klicken.
4. Den Konfigurator öffnen und für jede Uhr eine Instanz erstellen.
5. Optional in der Uhren-Instanz Zuhause, Statuskachel und Verlauf einstellen.

## Lizenz

MIT, siehe [LICENSE](LICENSE).
