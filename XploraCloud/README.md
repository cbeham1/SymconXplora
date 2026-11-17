# Xplora Cloud

Holds the login to an Xplora parent account and forwards the requests of the Xplora Watch and Xplora Configurator instances to the Xplora cloud.

### Table of contents

1. [Features](#1-features)
2. [Requirements](#2-requirements)
3. [Installation](#3-installation)
4. [Configuration](#4-configuration)
5. [Status variables and profiles](#5-status-variables-and-profiles)
6. [PHP command reference](#6-php-command-reference)

### 1. Features

* Login with e-mail address or phone number and password of the Xplora parent app
* Keeps the session and logs in again automatically when it expires
* Pauses all requests for one hour when Xplora throttles requests (HTTP 429)

### 2. Requirements

- IP-Symcon 7.0 or newer
- Xplora parent account with at least one watch
- Internet access to `api.prod.myxplora.com`

### 3. Installation

* Install the library "Xplora" via the Module Store or Module Control.
* The instance is created automatically together with the first Xplora Configurator or Xplora Watch instance, or manually via "Add instance" → "Xplora Cloud".

### 4. Configuration

| Name                | Description                                                       |
| ------------------- | ----------------------------------------------------------------- |
| E-mail address      | Login of the parent app (if the account uses an e-mail address)   |
| Country code        | Country code without "+", e.g. 43 or 49 (login with phone number) |
| Phone number        | Phone number without leading 0 (login with phone number)          |
| Password            | Password of the parent app                                        |
| Language            | Language of addresses and messages from Xplora                    |
| Test connection     | Logs in and shows the watches of the account                      |

The password is sent to Xplora as MD5 hash only, the same way the parent app does it.

### 5. Status variables and profiles

None.

### 6. PHP command reference

`string XPLC_TestConnection(int $InstanceID);`
Logs in and returns a text with the result and the names of the watches.

Example: `echo XPLC_TestConnection(12345);`
