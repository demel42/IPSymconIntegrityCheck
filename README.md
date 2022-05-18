# IPSymconIntegrityCheck

[![IPS-Version](https://img.shields.io/badge/Symcon_Version-6.0+-red.svg)](https://www.symcon.de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/)
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

#### Objekte
- _ParentID_, _ChildrenIDs_ auf Vorhandensein
#### Instanzen
- _InstanceStatus_ prüfen
- Referenzen der Instanze auf Vorhandensein
#### Script:
- ob die benötigen Dateien vorhanden sind
- ob überflüssige Dateien vorhanden sind
##### PHP-Script:
- es werden include/include_once/require/require_once-Anweisungen überprüft, ob die referenzierten Datein vorhanden sind
- es wird versucht, im PHP-Code vorhandene ID's zu erkennen und diese zu überprüfen
##### Ablaufplan:
- es werden die referenzierten Variablen, Ziele, eingebettete Skripte sowie die Bedingungen überprüft
#### Ereignisse
- _TriggerVariableID_ auf Gültigkeit
- _EventConditions.VariableID_ auf Gültigkeit
#### Variablen
- _VariableProfile_, _VariableCustomProfile_ auf Vorhandensein und passenden Variablen-Typ
- _VariableAction_, _VariableCustomAction_ auf Gültigkeit
#### Medien
- ob die entsprechenden Dateien vorhanden sind
- Information, wenn ein Medien-Objekt nur im Cache vorliegt
#### Verknüpfungen
- _TargetID_ auf Vorhandensein
#### Kategorien
#### Module
- _ModuleID_, _LibraryID_ auf Vorhandensein prüfen
#### Timer
- Anzahl der aktiven Timer
#### Threads
- Anzahl der genutzten Threads, Prüfung auf Langläufer

Die Auffälligkeiten werden detailliert ausgegeben und je nach Schwere als _Error_, _Warning_ oder _Information_ gekennzeichnet.

## 2. Voraussetzungen

 - IP-Symcon ab Version 6.0

## 3. Installation

### a. Laden des Moduls

**Installieren über den Module-Store**

Die Webconsole von IP-Symcon mit `http://\<IP-Symcon IP\>:3777/console/` öffnen.

Anschließend oben rechts auf das Symbol für den Modulstore klicken

Im Suchfeld nun *Symcon Integrity-Check* eingeben, das Modul auswählen und auf Installieren drücken.

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

`boolean IntegrityCheck_MonitorThreads(integer $InstanzID)`<br>

## 5. Konfiguration:

### Variablen

| Eigenschaft                                       | Typ     | Standardwert | Beschreibung |
| :------------------------------------------------ | :------ | :----------- | :----------- |
| Instanz deaktivieren                              | boolean | false        | Instanz temporär deaktivieren |
|                                                   |         |              | |
| Ergebnisse der Prüfung speichern                  | boolean |              | JSON-Struktur mit der ermittelten Werten in Variable _CheckResult_ speichern |
| Script, das nach Testdurchführung aufgerufen wird | integer |              | Script, das nach dem Testdurchlauf aufgerufen wird |
|                                                   |         |              | |
| zu ignorierende Elemente                          |         |              | |
| ... Objekte                                       | table   |              | Liste von Objekten, die nicht geprüft werden sollen |
| ... Zahlen                                        | table   |              | Liste von Zahlen, die nicht als Objekt-ID erkannt werden sollen |
| Objekte unterhalb der Kategorie ignorieren        | integer |              | Kategorie für zu ignorierende Objekte |
| PHP-Kommentar                                     | string  |              | PHP-Kommentar, um einzelne Zeilen auszunehmen |
|                                                   |         |              | |
| Prüfung durchführen ...                           | integer | 60           | Durchführungsintervall, Angabe in Minuten |
|                                                   |         |              | |
| Threads-Infogrenze                                | integer | 10           | Grenzwert der Laufzeit in Sekunden (Information) |
| Threads-Warngrenze                                | integer | 30           | Grenzwert der Laufzeit in Sekunden (Warnung) |
| Threads-Fehlergrenze                              | integer | 120          | Grenzwert der Laufzeit in Sekunden (Fehlermeldung) |
|                                                   |         |              | |

- *... Zahlen*
die hier angegebenen 5-stelligen Zahlen werden bei der Prüfung von Scripten nicht als Objekt-ID's behandelt und geprüft.
Die Spalte *Notiz* ist nur optional und als Hiweis, wo si h die Zehl befindet bzw die Bedeutung

- *Objekte unterhalb der Kategorie*
alle Objekte unterhalb der angegebenen Kategorie werden ignoriert, dabei werden Verknüpfungen *nicht* gefolgt.

- *Script nach Test*
Das Script kann z.B. dazu dienen eine Benachrichtigung auszulösen. Beipiel siehe [docs/mail_on_error.php](docs/mail_on_error.php).

## 6. Anhang

GUIDs

- Modul: `{673E38D6-DB64-2834-28EE-26B338A9A5C2}`
- Instanzen:
  - IntegrityCheck: `{9BC98F5F-A5F1-7980-D9C9-11C29B64F288}`

## 7. Versions-Historie

- 1.7.4 @ 18.05.2022 11:18
  - Update-Funktion um der in 6.2 geänderten ID-Bedeutung Rechnung zu tragen
    'ignore_category' von 0 auf 1 korrigieren
  - Default für property 'ignore_category' ist nun 1 (= keine Auswahl)

- 1.7.3 @ 17.05.2022 15:38
  - update submodule CommonStubs
    Fix: Absicherung gegen fehlende Objekte

- 1.7.2 @ 10.05.2022 15:06
  - update submodule CommonStubs
  - SetLocation() -> GetConfiguratorLocation()
  - weitere Absicherung ungültiger ID's

- 1.7.1 @ 29.04.2022 18:10
  - Überlagerung von Translate und Aufteilung von locale.json in 3 translation.json (Modul, libs und CommonStubs)

- 1.7 @ 26.04.2022 15:41
  - Implememtierung einer Update-Logik
  - IPS-Version ist nun minimal 6.0
  - Übersetzung vervollständigt
  - diverse interne Änderungen

- 1.6.8 @ 16.04.2022 11:57
  - potentieller Namenskonflikt behoben (trait CommonStubs)
  - Aktualisierung von submodule CommonStubs

- 1.6.7 @ 21.03.2022 17:05
  - Fix in CommonStub
  - Anzeige referenzierten Statusvariablen

- 1.6.6 @ 01.03.2022 21:55
  - Anzeige der Referenzen der Instanz

- 1.6.5 @ 26.02.2022 12:19
  - Fix

- 1.6.5 @ 21.02.2022 15:37
  - Überprüfung von Ablaufplänen
    Es werden überprüft: die referenzierten Variablen, Ziele, eingebettete Skripte sowie die Bedingungen

- 1.6.4 @ 20.02.2022 18:15
  - Überprüfung der Aktions-Scripte von Ereignissen auf ungültige Objekt-ID's
  - Anpassungen an IPS 6.2 (Prüfung auf ungültige ID's)
  - libs/common.php -> submodule CommonStubs

- 1.6.3 @ 13.02.2022 12:06
  - Thread-Monitoring: nur Warnung nur ab einer bestimmten AUsnutzung des Thread-Pools

- 1.6.2 @ 06.02.2022 14:08
  - Thread-Monitoring: nur optional benachrichtigen

- 1.6.1 @ 06.02.2022 14:08
  - Threads überwachen (Langläufer)

- 1.6 @ 20.01.2022 11:57
  - Ausgabe der ungenutzen Variablen

- 1.5 @ 17.12.2021 14:21
  - Korrektur der Breite der zu ignorierenden Objekte

- 1.4 @ 16.12.2021 13:53
  - Absicherung der Anzeige der Modul/Bibliotheks-Informationen

- 1.3 @ 10.12.2021 12:00
  - geänderte Behandlung von "*.inc.php"

- 1.2 @ 13.08.2021 18:05
  - alle ID's in einer Zeile im PHP-Code eines Scriptes werden geprüft
  - Abfangen eines Fehlers bei ungültigen Instanzen

- 1.1 @ 25.07.2021 18:19
  - IPS 6.0: define SCRIPTTYPE_FLOW existiert nun standardmässig
  - Fix für zu großen Debug ("Outputbuffer exceeds limits")

- 1.0 @ 18.07.2021 15:36
  - Initiale Version
