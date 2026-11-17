# Xplora

[Deutsche Anleitung](README.de.md)

Integration of Xplora kids smartwatches via the Xplora cloud (the same service the Xplora parent app uses).

The Xplora API is not officially documented. This library uses the same requests as the open source projects
[pyxplora_api](https://github.com/Ludy87/pyxplora_api) and [ha-xplora-watch](https://github.com/acdcnow/ha-xplora-watch).
Xplora may change the API at any time.

## Modules

* [Xplora Cloud](XploraCloud/README.md) – login to the Xplora parent account, connection to the cloud
* [Xplora Configurator](XploraConfigurator/README.md) – lists the watches of the account and creates the watch instances
* [Xplora Watch](XploraWatch/README.md) – location, address, safe zone, battery and charging state of one watch

## Quick start

1. Install the library via the Module Store (or Module Control with the URL of this repository).
2. Add an instance "Xplora Configurator". IP-Symcon also creates the "Xplora Cloud" instance.
3. Enter the login data of the Xplora parent app in the "Xplora Cloud" instance and press "Test connection".
4. Open the configurator and create an instance for each watch.

## License

MIT, see [LICENSE](LICENSE).
