# IPSymconIntegrityCheck

[![IPS-Version](https://img.shields.io/badge/Symcon_Version-5.5+-red.svg)](https://www.symcon.de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/)
![Code](https://img.shields.io/badge/Code-PHP-blue.svg)
[![License](https://img.shields.io/badge/License-CC%20BY--NC--SA%204.0-green.svg)](https://creativecommons.org/licenses/by-nc-sa/4.0/)

## Dokumentation

**Inhaltsverzeichnis**

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Installation](#3-installation)
4. [Funktionsreferenz](#4-funktionsreferenz)
5. [Konfiguration](#5-konfiguration)
6. [Anhang](#6-anhang)
7. [Versions-Historie](#7-versions-historie)

## 1. Funktionsumfang

Prüfung der internen Integrität des IP-Symcon-Installation

## 2. Voraussetzungen

 - IP-Symcon ab Version 5.5

## 3. Installation

### zentrale Funktion

`boolean IntegrityCheck_PerformTest(integer $InstanzID, integer $preferred_server, string $exclude_server)`<br>

## 5. Konfiguration:

### Variablen

| Eigenschaft                     | Typ     | Standardwert | Beschreibung |
| :------------------------------ | :------ | :----------- | :----------- |
| Instanz ist deaktiviert         | boolean | false        | Instanz temporär deaktivieren |
|                                 |         |              | |
| Aktualisiere Daten ...          | integer | 60           | Aktualisierungsintervall, Angabe in Minuten |

Dіe Gesamtliste der Server erhält man mittels Shell-Kommand `speedtest-cli --list` resp. `speedtest --servers`<br>

## 6. Anhang

GUIDs

- Modul: `{661B9CEA-A3E8-4CE9-8DDA-F5EA62604474}`
- Instanzen:
  - IntegrityCheck: `{673E38D6-DB64-2834-28EE-26B338A9A5C2}`

## 7. Versions-Historie

- 0.9 @ dd.mm.yyyy
  - Initiale Version
