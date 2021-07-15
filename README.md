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

Prüfung der internen Integrität des IP-Symcon-Installation, überprüft werden 

### Objekte
- ParentID, ChildrenIDs auf Vorhandensein
### Instanzen
- InstanceStatus
- Referenzen der Instanze auf Vorhandensein
### PHP-Scripte:
- ob die Dateien vorhanden sind
- ob überflüssige Scripte vorhanden sind
- es werden include/include_once/require/require_once-Anweisungen überprüft, ob die referenzierten Datein vorhanden sind
- es wird versucht, im PHP-Code vorhandene ID's zu erkennen und diese zu überprüfen
### Ereignisse
- TriggerVariableID auf Gültigkeit
- EventConditions.VariableID auf Gültigkeit
### Variablen
- VariableProfile, VariableCustomProfile auf Vorhandensein und passenden Variablen-Typ
- VariableAction, VariableCustomAction auf Gültigkeit
### Medien
- ob die entsprechenden Dateien vorhanden sind
- Information, wenn ein Medien.Objekt nur im Cache vorliegt
### Verknüpfungen
- TargetID auf Vorhandensein
### Module
### Kategorien
### Timer
- Anzahl der aktiven Timer
Threads
- Anzahl der genutzten Threads

Die Auffälligkeiten werden detailliert ausgegeben.

## 2. Voraussetzungen

 - IP-Symcon ab Version 5.5

## 3. Installation

### a. Laden des Moduls

**Installieren über den Module-Store**

Die Webconsole von IP-Symcon mit `http://\<IP-Symcon IP\>:3777/console/` öffnen.

Anschließend oben rechts auf das Symbol für den Modulstore klicken

Im Suchfeld nun *Integrity Check* eingeben, das Modul auswählen und auf Installieren drücken.

**Installieren über die Modules-Instanz**

Die Konsole von IP-Symcon öffnen. Im Objektbaum unter Kerninstanzen die Instanz __*Modules*__ durch einen doppelten Mausklick öffnen.

In der _Modules_ Instanz rechts oben auf den Button __*Hinzufügen*__ drücken.

In dem sich öffnenden Fenster folgende URL hinzufügen: `https://github.com/demel42/IPSymconIntegrityCheck.git` 
und mit _OK_ bestätigen. Ggfs. auf anderen Branch wechseln (Modul-Eintrag editieren, _Zweig_ auswählen).

Anschließend erscheint ein Eintrag für das Modul in der Liste der Instanz _Modules_

### b. Einrichtung in IPS

Nun _Instanz hinzufügen_ anwählen und als Hersteller _(sonstiges)_ sowie als Gerät _Symcon Integrity-Check_ auswählen.

### zentrale Funktion

`boolean IntegrityCheck_PerformCheck(integer $InstanzID)`<br>

## 5. Konfiguration:

### Variablen

| Eigenschaft                      | Typ     | Standardwert | Beschreibung |
| :------------------------------- | :------ | :----------- | :----------- |
| Instanz deaktivieren             | boolean | false        | Instanz temporär deaktivieren |
|                                  |         |              | |
| PHP-Kommentar                    | string  |              | PHP-Kommentar, um einzelne Zeilen auszunehmen |
| zu ignorierende Objekte          | table   |              | Liste von Objekten, die nicht geprüft werden sollen |
| Ergebnisse der Prüfung speichern | boolean |              | JSON-Struktur mit der ermittelten Werten in Variable _CheckResult_ speichern |
| Script nach Test ...             | integer |              | Script, das nach dem Testdurchlauf aufgerufen wird |
|                                  |         |              | |
| Prüfung durchführen ...          | integer | 60           | Durchführungsintervall, Angabe in Minuten |

- Script nach Test
Das Script kann z.B. dazu dienen eine Benachrichtigung auszulösen. Beipiel siehe [docs/mail_on_error.php](docs/mail_on_error.php).

## 6. Anhang

GUIDs

- Modul: `{661B9CEA-A3E8-4CE9-8DDA-F5EA62604474}`
- Instanzen:
  - IntegrityCheck: `{673E38D6-DB64-2834-28EE-26B338A9A5C2}`

## 7. Versions-Historie

- 0.9 @ 14.07.2021 15:47 (beta)
  - Initiale Version
