<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/common.php';  // globale Funktionen
require_once __DIR__ . '/../libs/local.php';   // lokale Funktionen

class IntegrityCheck extends IPSModule
{
    use IntegrityCheckCommonLib;
    use IntegrityCheckLocalLib;

    public static $NUM_DEVICE = 4;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyBoolean('module_disable', false);

        $this->RegisterPropertyInteger('update_interval', '0');

        $this->RegisterTimer('UpdateData', 0, 'IntegrityCheck_UpdateData(' . $this->InstanceID . ');');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $vpos = 0;

        $this->MaintainVariable('Overview', $this->Translate('Overview'), VARIABLETYPE_STRING, '~HTMLBox', $vpos++, true);
        $this->MaintainVariable('StartTime', $this->Translate('Start time'), VARIABLETYPE_INTEGER, '~UnixTimestamp', $vpos++, true);
        $this->MaintainVariable('ErrorCount', $this->Translate('Count of errors'), VARIABLETYPE_INTEGER, '', $vpos++, true);
        $this->MaintainVariable('WarnCount', $this->Translate('Count of warnings'), VARIABLETYPE_INTEGER, '', $vpos++, true);
        $this->MaintainVariable('InfoCount', $this->Translate('Count of informations'), VARIABLETYPE_INTEGER, '', $vpos++, true);
        $this->MaintainVariable('LastUpdate', $this->Translate('Last update'), VARIABLETYPE_INTEGER, '~UnixTimestamp', $vpos++, true);

        $module_disable = $this->ReadPropertyBoolean('module_disable');
        if ($module_disable) {
            $this->SetTimerInterval('UpdateData', 0);
            $this->SetStatus(IS_INACTIVE);
            return;
        }

        $refs = $this->GetReferenceList();
        foreach ($refs as $ref) {
            $this->UnregisterReference($ref);
        }
        $propertyNames = [];
        foreach ($propertyNames as $name) {
            $oid = $this->ReadPropertyInteger($name);
            if ($oid > 0) {
                $this->RegisterReference($oid);
            }
        }

        $this->SetStatus(IS_ACTIVE);
        $this->SetUpdateInterval();
    }

    public function GetConfigurationForm()
    {
        $formElements = $this->GetFormElements();
        $formActions = $this->GetFormActions();
        $formStatus = $this->GetFormStatus();

        $form = json_encode(['elements' => $formElements, 'actions' => $formActions, 'status' => $formStatus]);
        if ($form == '') {
            $this->SendDebug(__FUNCTION__, 'json_error=' . json_last_error_msg(), 0);
            $this->SendDebug(__FUNCTION__, '=> formElements=' . print_r($formElements, true), 0);
            $this->SendDebug(__FUNCTION__, '=> formActions=' . print_r($formActions, true), 0);
            $this->SendDebug(__FUNCTION__, '=> formStatus=' . print_r($formStatus, true), 0);
        }
        return $form;
    }

    private function GetFormElements()
    {
        $formElements = [];

        $formElements[] = [
            'type'    => 'CheckBox',
            'name'    => 'module_disable',
            'caption' => 'Instance is disabled'
        ];

        $formElements[] = [
            'type'    => 'Label',
            'caption' => 'Update data every X minutes'
        ];
        $formElements[] = [
            'type'    => 'IntervalBox',
            'name'    => 'update_interval',
            'caption' => 'Minutes'
        ];

        return $formElements;
    }

    private function GetFormActions()
    {
        $formActions = [];

        $formActions[] = [
            'type'    => 'Button',
            'caption' => 'Update data',
            'onClick' => 'IntegrityCheck_UpdateData($id);'
        ];

        return $formActions;
    }

    protected function SetUpdateInterval()
    {
        $min = $this->ReadPropertyInteger('update_interval');
        $msec = $min > 0 ? $min * 1000 * 60 : 0;
        $this->SetTimerInterval('UpdateData', $msec);
    }

    public function UpdateData()
    {
        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return;
        }

        // Kommentar mit Schlüsselwort, der besagt, das in dieser Zeile eines Scriptes ID's nicht auf Gülrigkeit geprüft werden sollen
        $no_id_check = '/*NO_ID_CHECK*/';

        // zu ignorierende Objekt-IDs
        $ignoreIDs = [
            48146,
            40908,
        ];

        $startTime = IPS_GetKernelStartTime();

        $now = time();

        // Threads
        $threadList = IPS_GetScriptThreadList();
        $threadCount = 0;
        foreach ($threadList as $t => $i) {
            $thread = IPS_GetScriptThread($i);
            $ScriptID = $thread['ScriptID'];
            if ($ScriptID != 0) {
                $threadCount++;
            }
        }

        // Timer
        $timerCount = 0;
        $timer1MinCount = 0;
        $timer5MinCount = 0;
        $timerList = IPS_GetTimerList();
        foreach ($timerList as $t) {
            $timer = IPS_GetTimer($t);
            $next_run = $timer['NextRun'];
            if ($next_run == 0) {
                continue;
            }
            $timerCount++;
            $delay = $next_run - $now;
            if ($delay < 60) {
                $timer1MinCount++;
            } elseif ($delay < 300) {
                $timer5MinCount++;
            }
        }

        $messageList = [];

        // Objekte
        $objectList = IPS_GetObjectList();
        $objectCount = count($objectList);
        foreach ($objectList as $objectID) {
            if (in_array($objectID, $ignoreIDs)) {
                continue;
            }
            $object = IPS_GetObject($objectID);
            $parentID = $object['ParentID'];
            if ($parentID != 0 && !IPS_ObjectExists($parentID)) {
                $s = $this->TranslateFormat('parent object with ID {$parentID} is unknown', ['{$parentID}' => $parentID]);
                $this->AddMessageEntry($messageList, $this->Translate('objects'), $objectID, $s, self::$LEVEL_ERROR);
            }
            $childrenIDs = $object['ChildrenIDs'];
            $badIDs = [];
            foreach ($childrenIDs as $childrenID) {
                if (!IPS_ObjectExists($childrenID)) {
                    $s = $this->TranslateFormat('child object with ID {$childrenID} is unknown', ['{$childrenID}' => $childrenID]);
                    $this->AddMessageEntry($messageList, $this->Translate('objects'), $objectID, $s, self::$LEVEL_ERROR);
                }
            }
        }

        // Links
        $linkList = IPS_GetLinkList();
        $linkCount = count($linkList);
        foreach ($linkList as $linkID) {
            $link = IPS_GetLink($linkID);
            $targetID = $link['TargetID'];
            if (!IPS_ObjectExists($targetID)) {
                $s = $this->TranslateFormat('target object with ID {$targetID} is unknown', ['{$targetID}' => $targetID]);
                $this->AddMessageEntry($messageList, $this->Translate('links'), $linkID, $s, self::$LEVEL_ERROR);
            }
        }

        // Module
        $moduleList = IPS_GetModuleList();
        $moduleCount = count($moduleList);

        // Kategorien
        $categoryList = IPS_GetCategoryList();
        $categoryCount = count($categoryList);

        // Instanzen
        $instanceStatusCodes = [
            101 => 'Instance getting created',
            102 => 'Instance is active',
            103 => 'Instance is deleted',
            104 => 'Instance is inactive',
            105 => 'Instance is not created',
        ];

        $instanceList = IPS_GetInstanceList();
        $instanceCount = count($instanceList);
        foreach ($instanceList as $instanceID) {
            if (in_array($instanceID, $ignoreIDs)) {
                continue;
            }
            $instance = IPS_GetInstance($instanceID);
            $instanceStatus = $instance['InstanceStatus'];
            if (in_array($instanceStatus, [102])) {
                continue;
            }
            if (isset($instanceStatusCodes[$instanceStatus])) {
                $s = $this->Translate($instanceStatusCodes[$instanceStatus]);
                if (in_array($instanceStatus, [104])) {
                    $lvl = self::$LEVEL_INFO;
                } else {
                    $lvl = self::$LEVEL_WARN;
                }
            } else {
                $s = $this->TranslateFormat('Status {$instanceStatus}', ['{$instanceStatus}' => $instanceStatus]);
                $lvl = self::$LEVEL_ERROR;
            }
            $this->AddMessageEntry($messageList, $this->Translate('instances'), $instanceID, $s, $lvl);
        }

        // Referenzen der Instanzen
        foreach ($instanceList as $instanceID) {
            if (in_array($instanceID, $ignoreIDs)) {
                continue;
            }
            $refIDs = IPS_GetReferenceList($instanceID);
            foreach ($refIDs as $refID) {
                if (!IPS_ObjectExists($refID)) {
                    $s = $this->TranslateFormat('referenced object with ID {$refID} is unknown', ['{$refID}' => $refID]);
                    $this->AddMessageEntry($messageList, $this->Translate('instances'), $instanceID, $s, self::$LEVEL_ERROR);
                }
            }
        }

        // Scripte
        $fileListIPS = [];
        $fileListSYS = [];
        $fileListINC = [];

        $scriptList = IPS_GetScriptList();
        $scriptCount = count($scriptList);
        foreach ($scriptList as $scriptID) {
            $script = IPS_GetScript($scriptID);
            $fileListIPS[] = $script['ScriptFile'];
            if (in_array($scriptID, $ignoreIDs)) {
                continue;
            }
            if ($script['ScriptIsBroken']) {
                $s = $this->Translate('ist fehlerhaft');
                $this->AddMessageEntry($messageList, $this->Translate('scripts'), $scriptID, $S, self::$LEVEL_ERROR);
            }
        }
        $this->SendDebug(__FUNCTION__, 'scripts from IPS: fileListIPS=' . print_r($fileListIPS, true), 0);

        // Script im Filesystem
        $path = IPS_GetKernelDir() . 'scripts';
        $handle = opendir($path);
        while ($file = readdir($handle)) {
            if (!is_file($path . '/' . $file)) {
                continue;
            }
            if (!preg_match('/^.*\.php$/', $file)) {
                continue;
            }
            if (preg_match('/^.*\.inc\.php$/', $file)) {
                continue;
            }
            $fileListSYS[] = $file;
        }
        closedir($handle);
        $this->SendDebug(__FUNCTION__, 'script in filesystem: fileListSYS=' . print_r($fileListSYS, true), 0);

        foreach ($fileListIPS as $file) {
            $text = @file_get_contents($path . '/' . $file);
            if ($text == false) {
                $this->SendDebug(__FUNCTION__, 'script/include - no content: file=' . $file, 0);
                continue;
            }
            $scriptID = @IPS_GetScriptIDByFile($file);
            if (in_array($scriptID, $ignoreIDs)) {
                continue;
            }
            $lines = explode(PHP_EOL, $text);
            foreach ($lines as $line) {
                if (preg_match('/' . preg_quote($no_id_check, '/') . '/', $line)) {
                    continue;
                }
                if (preg_match('/^[\t ]*(require_once|require|include_once|include)[\t ]*\([\t ]*(.*)[\t ]*\)[\t ]*;/', $line, $r)) {
                    $a = $r[2];
                } elseif (preg_match('/^[\t ]*(require_once|require|include_once|include)[\t ]*(.*)[\t ]*;/', $line, $r)) {
                    $a = $r[2];
                } else {
                    continue;
                }
                if (preg_match('/^[\t ]*[\'"]([^\'"]*)[\'"][\t ]*$/', $a, $x)) {
                    $this->SendDebug(__FUNCTION__, 'script/include - match#1 file=' . $x[1] . ': file=' . $file . ', line=' . $line, 0);
                    $incFile = $x[1];
                    if (!in_array($incFile, $fileListINC)) {
                        $fileListINC[] = $incFile;
                    }
                    if (in_array($incFile, $fileListIPS)) {
                        continue;
                    }
                    if (file_exists($path . '/' . $incFile)) {
                        continue;
                    }
                    $s = $this->TranslateFormat('file "{$file}" is missing', ['{$file}' => $incFile]);
                    $this->AddMessageEntry($messageList, $this->Translate('scripts'), $scriptID, $s, self::$LEVEL_ERROR);
                } elseif (preg_match('/IPS_GetScriptFile[\t ]*\([\t ]*([0-9]{5})[\t ]*\)/', $a, $x)) {
                    $this->SendDebug(__FUNCTION__, 'script/include - match#2 id=' . $x[1] . ': file=' . $file . ', line=' . $line, 0);
                    $id = $x[1];
                    $incFile = @IPS_GetScriptFile($id);
                    if ($incFile == false) {
                        $s = $this->TranslateFormat('script with ID {$id} does not exist', ['{$id}' => $id]);
                        $this->AddMessageEntry($messageList, $this->Translate('scripts'), $scriptID, $s, self::$LEVEL_ERROR);
                    } else {
                        if (!in_array($incFile, $fileListINC)) {
                            $fileListINC[] = $incFile;
                        }
                    }
                } else {
                    $this->SendDebug(__FUNCTION__, 'script/include - no match: file=' . $file . ', line=' . $line, 0);
                }
            }
        }
        $this->SendDebug(__FUNCTION__, 'script/include: fileListINC=' . print_r($fileListINC, true), 0);

        // überflüssige Scripte
        $scriptError = 0;
        foreach ($fileListSYS as $file) {
            if (in_array($file, $fileListIPS) || in_array($file, $fileListINC)) {
                continue;
            }
            $s = $this->TranslateFormat('file "{$file}" is redundant', ['{$file}' => $file]);
            $this->AddMessageEntry($messageList, $this->Translate('scripts'), 0, $s, self::$LEVEL_INFO);
        }

        // fehlende Scripte
        $scriptError = 0;
        foreach ($scriptList as $scriptID) {
            if (in_array($scriptID, $ignoreIDs)) {
                continue;
            }
            $script = IPS_GetScript($scriptID);
            $file = $script['ScriptFile'];
            if (in_array($file, $fileListSYS)) {
                continue;
            }
            $s = $this->TranslateFormat('file "{$file}" is missing', ['{$file}' => $file]);
            $this->AddMessageEntry($messageList, $this->Translate('scripts'), $scriptID, $s, self::$LEVEL_ERROR);
        }

        // Objekt-ID's in Scripten
        foreach ($fileListSYS as $file) {
            if (!in_array($file, $fileListIPS)) {
                continue;
            }
            $text = @file_get_contents($path . '/' . $file);
            if ($text == false) {
                $this->SendDebug(__FUNCTION__, 'script/object-id - no content: file=' . $file, 0);
                continue;
            }
            $scriptID = @IPS_GetScriptIDByFile($file);
            if (in_array($scriptID, $ignoreIDs)) {
                continue;
            }
            $lines = explode(PHP_EOL, $text);
            foreach ($lines as $line) {
                if (preg_match('/' . preg_quote($no_id_check, '/') . '/', $line)) {
                    continue;
                }
                if (preg_match('/[^!=><]=[\t ]*([0-9]{5})[^0-9]/', $line, $r)) {
                    $this->SendDebug(__FUNCTION__, 'script/object-id - match#1 id=' . $r[1] . ': file=' . $file . ', line=' . $line, 0);
                    $id = $r[1];
                } elseif (preg_match('/\([\t ]*([0-9]{5})[^0-9]/', $line, $r)) {
                    $this->SendDebug(__FUNCTION__, 'script/object-id - match#2 id=' . $r[1] . ': file=' . $file . ', line=' . $line, 0);
                    $id = $r[1];
                } else {
                    continue;
                }
                if (!in_array($id, $objectList)) {
                    $s = $this->TranslateFormat('object with ID {$id} is unknown', ['{$id}' => $id]);
                    $this->AddMessageEntry($messageList, $this->Translate('scripts'), $scriptID, $s, self::$LEVEL_ERROR);
                }
            }
        }

        // Events
        $eventList = IPS_GetEventList();
        $eventCount = count($eventList);
        $eventActive = 0;
        foreach ($eventList as $eventID) {
            if (in_array($eventID, $ignoreIDs)) {
                continue;
            }
            $event = IPS_GetEvent($eventID);
            $active = $event['EventActive'];
            if ($active) {
                $eventActive++;
            }
            $err = 0;
            $varID = $event['TriggerVariableID'];
            if ($varID != 0 && IPS_ObjectExists($varID) == false) {
                $s = $this->TranslateFormat('triggering variable {$varID} is unknown', ['{$varID}' => $varID]);
                $this->AddMessageEntry($messageList, $this->Translate('events'), $eventID, $s, self::$LEVEL_ERROR);
            }
            $eventConditions = $event['EventConditions'];
            foreach ($eventConditions as $eventCondition) {
                $variableRules = $eventCondition['VariableRules'];
                foreach ($variableRules as $variableRule) {
                    $varID = $variableRule['VariableID'];
                    if ($varID != 0 && IPS_ObjectExists($varID) == false) {
                        $s = $this->TranslateFormat('condition variable {$varID} is unknown', ['{$varID}' => $varID]);
                        $this->AddMessageEntry($messageList, $this->Translate('events'), $eventID, $s, self::$LEVEL_ERROR);
                    }
                }
            }
        }

        // Variablen
        $variableList = IPS_GetVariableList();
        $variableCount = count($variableList);
        foreach ($variableList as $variableID) {
            if (in_array($variableID, $ignoreIDs)) {
                continue;
            }
            $variable = IPS_GetVariable($variableID);

            // Variablenprofile
            $variableProfile = $variable['VariableProfile'];
            if ($variableProfile != false && IPS_GetVariableProfile($variableProfile) == false) {
                $s = $this->TranslateFormat('default profile "{$variableProfile}" is unknown', ['{$variableProfile}' => $variableProfile]);
                $this->AddMessageEntry($messageList, $this->Translate('variables'), $variableID, $s, self::$LEVEL_ERROR);
            }
            $variableCustomProfile = $variable['VariableCustomProfile'];
            if ($variableCustomProfile != false && IPS_GetVariableProfile($variableCustomProfile) == false) {
                $s = $this->TranslateFormat('user profile "{$variableCustomProfile}" is unknown', ['{$variableCustomProfile}' => $variableCustomProfile]);
                $this->AddMessageEntry($messageList, $this->Translate('variables'), $variableID, $s, self::$LEVEL_ERROR);
            }

            // Variableaktionen
            $variableAction = $variable['VariableAction'];
            if ($variableAction > 0 && !IPS_ObjectExists($variableAction)) {
                $s = $this->TranslateFormat('default action with ID {$variableAction} is unknown', ['{$variableAction}' => $variableAction]);
                $this->AddMessageEntry($messageList, $this->Translate('variables'), $variableID, $s, self::$LEVEL_ERROR);
            }
            $variableCustomAction = $variable['VariableCustomAction'];
            if ($variableCustomAction > 1 && !IPS_ObjectExists($variableCustomAction)) {
                $s = $this->TranslateFormat('user action with ID {$variableAction} is unknown', ['{$variableCustomAction}' => $variableCustomAction]);
                $this->AddMessageEntry($messageList, $this->Translate('variables'), $variableID, $s, self::$LEVEL_ERROR);
            }
        }

        // Medien
        $path = IPS_GetKernelDir();
        $mediaList = IPS_GetMediaList();
        $mediaCount = count($mediaList);
        foreach ($mediaList as $mediaID) {
            if (in_array($mediaID, $ignoreIDs)) {
                continue;
            }
            $media = IPS_GetMedia($mediaID);
            if ($media['MediaType'] == MEDIATYPE_STREAM) {
                continue;
            }
            $file = $media['MediaFile'];
            if (file_exists($path . $file)) {
                continue;
            }
            if ($media['MediaIsCached']) {
                $s = $this->Translate('is not yet saved');
                $this->AddMessageEntry($messageList, $this->Translate('media'), $mediaID, $s, self::$LEVEL_WARN);
            } else {
                $s = $this->TranslateFormat('file "{$file}" is missing', ['{$file}' => $file]);
                $this->AddMessageEntry($messageList, $this->Translate('media'), $mediaID, $s, self::$LEVEL_ERROR);
            }
        }

        $errorCount = 0;
        $warnCount = 0;
        $infoCount = 0;
        foreach ($messageList as $tag => $entries) {
            foreach ($entries as $err) {
                $lvl = $err['Level'];
                switch ($lvl) {
                    case self::$LEVEL_INFO:
                        $infoCount++;
                        break;
                    case self::$LEVEL_WARN:
                        $warnCount++;
                        break;
                    case self::$LEVEL_ERROR:
                    default:
                        $errorCount++;
                        break;
                }
            }
        }
        $this->SendDebug(__FUNCTION__, 'msgList=' . print_r($messageList, true), 0);
        $this->SendDebug(__FUNCTION__, ' errorCount=' . $errorCount . ', warnCount=' . $warnCount . ', infoCount=' . $infoCount, 0);

        // HTML-Text aufbauen
        $html = '';
        $html .= '<head>' . PHP_EOL;
        $html .= '<style>' . PHP_EOL;
        $html .= 'body { margin: 1; padding: 0; font-family: "Open Sans", sans-serif; font-size: 16px; }' . PHP_EOL;
        $html .= 'table { border-collapse: collapse; border: 0px solid; margin: 0.5em;}' . PHP_EOL;
        $html .= 'th, td { padding: 1; }' . PHP_EOL;
        $html .= 'thead, tdata { text-align: left; }' . PHP_EOL;
        $html .= '#spalte_title { width: 160px; }' . PHP_EOL;
        $html .= '#spalte_value { }' . PHP_EOL;
        $html .= '</style>' . PHP_EOL;
        $html .= '</head>' . PHP_EOL;
        $html .= '<body>' . PHP_EOL;
        $html .= '<table>' . PHP_EOL;
        $html .= '<colgroup><col id="spalte_title"></colgroup>' . PHP_EOL;
        $html .= '<colgroup><col id="spalte_value"></colgroup>' . PHP_EOL;
        $html .= '<tr><td>' . $this->Translate('Stand') . '</td><td>' . date('d.m.Y H:i:s', $now) . '<br></td></tr>' . PHP_EOL;
        $html .= '<tr><td>' . $this->Translate('categories') . '</td><td>' . $categoryCount . '</td></tr>' . PHP_EOL;
        $html .= '<tr><td>' . $this->Translate('objects') . '</td><td>' . $objectCount . '</td></tr>' . PHP_EOL;
        $html .= '<tr><td>' . $this->Translate('links') . '</td><td>' . $linkCount . '</td></tr>' . PHP_EOL;
        $html .= '<tr><td>' . $this->Translate('modules') . '</td><td>' . $moduleCount . '</td></tr>' . PHP_EOL;
        $html .= '<tr><td>' . $this->Translate('instances') . '</td><td>' . $instanceCount . '</td></tr>' . PHP_EOL;
        $html .= '<tr><td>' . $this->Translate('scripts') . '</td><td>' . $scriptCount . '</td></tr>' . PHP_EOL;
        $html .= '<tr><td>' . $this->Translate('variables') . '</td><td>' . $variableCount . '</td></tr>' . PHP_EOL;
        $html .= '<tr><td>' . $this->Translate('media') . '</td><td>' . $mediaCount . '</td></tr>' . PHP_EOL;
        $html .= '<tr><td>' . $this->Translate('events') . '</td><td>' . $eventCount . ' (aktiv=' . $eventActive . ')</td></tr>' . PHP_EOL;
        $html .= '<tr><td>' . $this->Translate('timer') . '</td><td>' . $timerCount . ' (1m=' . $timer1MinCount . ', 5m=' . $timer5MinCount . ')</td></tr>' . PHP_EOL;
        $html .= '<tr><td>' . $this->Translate('threads') . '</td><td>' . $threadCount . '</td></tr>' . PHP_EOL;
        $html .= '</table>' . PHP_EOL;
        if (count($messageList)) {
            foreach ($messageList as $tag => $entries) {
                $html .= '<b>' . $tag . ':</b><br>' . PHP_EOL;
                foreach ($entries as $entry) {
                    $lvl = $entry['Level'];
                    switch ($lvl) {
                        case self::$LEVEL_INFO:
                            $col = 'grey';
                            break;
                        case self::$LEVEL_WARN:
                            $col = 'yellow';
                            break;
                        case self::$LEVEL_ERROR:
                        default:
                            $col = 'red';
                            break;
                    }
                    $html .= '<span style="color: ' . $col . ';">&nbsp;&nbsp;&nbsp;';
                    $id = $entry['ID'];
                    if ($id != 0) {
                        $html .= '#' . $id;
                        $loc = @IPS_GetLocation($id);
                        if ($loc != false) {
                            $html .= '(' . $loc . ')';
                        }
                        $html .= ': ';
                    }
                    $html .= $entry['Msg'];
                    $html .= '</span><br>' . PHP_EOL;
                }
                $html .= '<br>' . PHP_EOL;
            }
        } else {
            $html .= '<br>' . $this->Translate('no abnormalities') . '<br>' . PHP_EOL;
        }
        $html .= '</body>' . PHP_EOL;
        $this->SetValue('Overview', $html);

        $this->SetValue('StartTime', $startTime);

        $this->SetValue('ErrorCount', $errorCount);
        $this->SetValue('WarnCount', $warnCount);
        $this->SetValue('InfoCount', $infoCount);

        $this->SetValue('LastUpdate', $now);
    }

    public function AddMessageEntry(array &$lst, string $tag, int $id, string $msg, int $level)
    {
        $this->SendDebug(__FUNCTION__, 'tag=' . $tag . ', id=' . $id . ', msg=' . $msg . ', level=' . $level, 0);
        $entV = isset($lst[$tag]) ? $lst[$tag] : [];
        $entV[] = [
            'ID'    => $id,
            'Msg'   => $msg,
            'Level' => $level,
        ];
        $lst[$tag] = $entV;
    }
}
