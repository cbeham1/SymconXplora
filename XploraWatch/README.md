# Xplora Watch

Shows location, address, safe zone, battery and charging state of one Xplora watch.

### Table of contents

1. [Features](#1-features)
2. [Requirements](#2-requirements)
3. [Compatibility](#3-compatibility)
4. [Installation](#4-installation)
5. [Configuration](#5-configuration)
6. [Status variables and profiles](#6-status-variables-and-profiles)
7. [PHP command reference](#7-php-command-reference)

### 1. Features

* Reads the last location of the watch at a configurable interval
* Battery level and charging state
* Address, accuracy, location type (GPS/WIFI/…) and time of the location
* Safe zone of the Xplora app the watch is in
* Optional: distance to a home location and "At home" (home radius plus accuracy of the location, max. 200 m)
* Optional: asks the watch for a new location if the last one is too old (costs some battery of the watch)
* Optional: map (OpenStreetMap) as HTML box

### 2. Requirements

- IP-Symcon 7.0 or newer
- An active "Xplora Cloud" instance

### 3. Compatibility

Watches that can be used with the Xplora parent app (tested with Xplora X6).

### 4. Installation

Best via the Xplora Configurator. Manually: "Add instance" → "Xplora Watch" and enter the watch ID.

### 5. Configuration

| Name                          | Description                                                                   |
| ----------------------------- | ----------------------------------------------------------------------------- |
| Watch ID                      | ID of the watch in the Xplora cloud (set by the configurator)                 |
| Update interval               | Seconds between two requests (minimum 60, default 300)                         |
| Request a new location …      | Minutes after which the watch is asked for a new location; 0 = never (default) |
| Home location                 | Optional; creates "At home" and "Distance to home"                            |
| Home radius                   | Meters around the home location that count as home (default 150)             |
| Create map variable           | HTML box with an OpenStreetMap map of the last location                        |
| Update now                    | Reads the last location immediately                                           |
| Request location from watch   | Asks the watch to determine its location now                                   |

Xplora limits the number of requests. Short intervals and frequent location requests for several watches can lead to throttling (see Xplora Cloud).

### 6. Status variables and profiles

| Ident      | Name             | Type    | Profile        |
| ---------- | ---------------- | ------- | -------------- |
| Battery    | Battery          | integer | ~Battery.100   |
| Charging   | Charging         | boolean |                |
| AtHome     | At home          | boolean | ~Presence      |
| Distance   | Distance to home | float   | XPL.Kilometer  |
| InSafeZone | In safe zone     | boolean |                |
| SafeZone   | Safe zone        | string  |                |
| Address    | Address          | string  |                |
| Latitude   | Latitude         | float   | XPL.Degree     |
| Longitude  | Longitude        | float   | XPL.Degree     |
| Accuracy   | Accuracy         | integer | XPL.Meter      |
| LocateType | Location type    | string  |                |
| LocateTime | Location time    | integer | ~UnixTimestamp |
| Map        | Map              | string  | ~HTMLBox       |

"At home" and "Distance to home" exist only with a home location, "Map" only if enabled.

Profiles created by the module: `XPL.Kilometer`, `XPL.Degree`, `XPL.Meter`.

### 7. PHP command reference

`bool XPLW_Update(int $InstanceID);`
Reads the last location and updates the variables.

`bool XPLW_RequestLocation(int $InstanceID);`
Asks the watch to determine its location. The new location is available with one of the next updates.

Example: `XPLW_RequestLocation(12345);`
