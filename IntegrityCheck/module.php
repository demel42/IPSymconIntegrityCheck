<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/common.php';  // globale Funktionen
require_once __DIR__ . '/../libs/local.php';   // lokale Funktionen

class IntegrityCheck extends IPSModule
{
    use IntegrityCheckCommonLib;
    use IntegrityCheckLocalLib;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyBoolean('module_disable', false);

        $this->RegisterPropertyInteger('update_interval', '60');
        $this->RegisterPropertyString('ignore_objects', json_encode([]));

        $this->RegisterPropertyString('ignore_nums', json_encode([]));

        $this->RegisterPropertyString('no_id_check', '/*NO_ID_CHECK*/');

        $this->RegisterPropertyBoolean('save_checkResult', false);

        $this->RegisterPropertyInteger('post_script', 0);

        $this->RegisterTimer('PerformCheck', 0, 'IntegrityCheck_PerformCheck(' . $this->InstanceID . ');');
    }

    public function ApplyChanges()
    {
        $save_checkResult = $this->ReadPropertyBoolean('save_checkResult');

        parent::ApplyChanges();

        $vpos = 0;

        $this->MaintainVariable('Overview', $this->Translate('Overview'), VARIABLETYPE_STRING, '~HTMLBox', $vpos++, true);
        $this->MaintainVariable('StartTime', $this->Translate('Start time'), VARIABLETYPE_INTEGER, '~UnixTimestamp', $vpos++, true);
        $this->MaintainVariable('ErrorCount', $this->Translate('Count of errors'), VARIABLETYPE_INTEGER, '', $vpos++, true);
        $this->MaintainVariable('WarnCount', $this->Translate('Count of warnings'), VARIABLETYPE_INTEGER, '', $vpos++, true);
        $this->MaintainVariable('InfoCount', $this->Translate('Count of informations'), VARIABLETYPE_INTEGER, '', $vpos++, true);
        $this->MaintainVariable('LastUpdate', $this->Translate('Last update'), VARIABLETYPE_INTEGER, '~UnixTimestamp', $vpos++, true);

        $this->MaintainVariable('CheckResult', $this->Translate('Check result'), VARIABLETYPE_STRING, '', $vpos++, $save_checkResult);

        $module_disable = $this->ReadPropertyBoolean('module_disable');
        if ($module_disable) {
            $this->SetTimerInterval('PerformCheck', 0);
            $this->SetStatus(IS_INACTIVE);
            return;
        }

        $refs = $this->GetReferenceList();
        foreach ($refs as $ref) {
            $this->UnregisterReference($ref);
        }
        $propertyNames = ['post_script'];
        foreach ($propertyNames as $name) {
            $oid = $this->ReadPropertyInteger($name);
            if ($oid > 0) {
                $this->RegisterReference($oid);
            }
        }

        $ignore_objects = $this->ReadPropertyString('ignore_objects');
        $objectList = json_decode($ignore_objects, true);
        if ($ignore_objects != false) {
            foreach ($objectList as $obj) {
                $oid = $obj['ObjectID'];
                if ($oid > 0) {
                    $this->RegisterReference($oid);
                }
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
            'caption' => 'Disable instance'
        ];

        $formElements[] = [
            'type'    => 'Label',
            'caption' => 'Perform check every X minutes'
        ];
        $formElements[] = [
            'type'    => 'IntervalBox',
            'name'    => 'update_interval',
            'caption' => 'Minutes'
        ];

        $formElements[] = [
            'type'    => 'Label'
        ];
        $formElements[] = [
            'type'    => 'Label',
            'caption' => 'Comment in PHP-Code to exclude this line from checking for Object-ID\'s'
        ];
        $formElements[] = [
            'type'    => 'ValidationTextBox',
            'name'    => 'no_id_check',
            'caption' => 'PHP-Comment'
        ];

        $items = [];
        $items[] = [
            'type'     => 'List',
            'name'     => 'ignore_objects',
            'rowCount' => 5,
            'add'      => true,
            'delete'   => true,
            'columns'  => [
                [
                    'caption'  => 'objects',
                    'name'     => 'ObjectID',
                    'width'    => 'auto',
                    'add'      => '',
                    'edit'     => [
                        'type'    => 'SelectObject',
                        'caption' => 'Target'
                    ]
                ]
            ]
        ];
        $items[] = [
            'type'     => 'List',
            'name'     => 'ignore_nums',
            'rowCount' => 5,
            'add'      => true,
            'delete'   => true,
            'columns'  => [
                [
                    'caption'  => 'Numbers',
                    'name'     => 'ID',
                    'width'    => '100px',
                    'add'      => '',
                    'edit'     => [
                        'type'     => 'ValidationTextBox',
                        'validate' => '^[0-9]{5}$',
                    ]
                ],
                [
                    'caption'  => 'Notice',
                    'name'     => 'notice',
                    'width'    => '200px',
                    'add'      => '',
                    'edit'     => [
                        'type'    => 'ValidationTextBox',
                    ]
                ]
            ]
        ];

        $formElements[] = [
            'type'    => 'Label',
            'caption' => 'to be ignored ...'
        ];
        $formElements[] = [
            'type'    => 'RowLayout',
            'items'   => $items
        ];

        $formElements[] = [
            'type'    => 'CheckBox',
            'name'    => 'save_checkResult',
            'caption' => 'Save results of the test'
        ];

        $formElements[] = [
            'type'         => 'SelectScript',
            'name'         => 'post_script',
            'caption'      => 'Script called after test execution'
        ];

        return $formElements;
    }

    private function GetFormActions()
    {
        $formActions = [];

        $formActions[] = [
            'type'    => 'Button',
            'caption' => 'Perform check',
            'onClick' => 'IntegrityCheck_PerformCheck($id);'
        ];

        return $formActions;
    }

    protected function SetUpdateInterval()
    {
        $min = $this->ReadPropertyInteger('update_interval');
        $msec = $min > 0 ? $min * 1000 * 60 : 0;
        $this->SetTimerInterval('PerformCheck', $msec);
    }

    public function PerformCheck()
    {
        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return;
        }

        // Kommentar mit Schlüsselwort, der besagt, das in dieser Zeile eines Scriptes Objekt-ID's nicht auf Gültigkeit geprüft werden sollen
        $no_id_check = $this->ReadPropertyString('no_id_check');

        // zu ignorierende Objekt-IDs
        $ignoreObjects = [];
        $ignore_objects = $this->ReadPropertyString('ignore_objects');
        $objectList = json_decode($ignore_objects, true);
        if ($objectList != false) {
            foreach ($objectList as $obj) {
                $oid = $obj['ObjectID'];
                if ($oid > 0) {
                    $ignoreObjects[] = $oid;
                }
            }
        }
        $this->SendDebug(__FUNCTION__, 'ignoreObjects=' . print_r($ignoreObjects, true), 0);

        // zu ignorierende Zahlen
        $ignoreNums = [];
        $ignore_nums = $this->ReadPropertyString('ignore_nums');
        $numList = json_decode($ignore_nums, true);
        if ($numList != false) {
            foreach ($numList as $num) {
                $id = $num['ID'];
                if ($id > 0) {
                    $ignoreNums[] = $id;
                }
            }
        }
        $this->SendDebug(__FUNCTION__, 'ignoreNums=' . print_r($ignoreNums, true), 0);

        $startTime = IPS_GetKernelStartTime();

        $now = time();

        $counterList = [];
        $messageList = [];

        // Objekte
        $objectList = IPS_GetObjectList();
        foreach ($objectList as $objectID) {
            if (in_array($objectID, $ignoreObjects)) {
                continue;
            }
            $object = IPS_GetObject($objectID);
            $parentID = $object['ParentID'];
            if ($parentID != 0 && IPS_ObjectExists($parentID) == false) {
                $s = $this->TranslateFormat('parent object with ID {$parentID} doesn\'t exists', ['{$parentID}' => $parentID]);
                $this->AddMessageEntry($messageList, 'objects', $objectID, $s, self::$LEVEL_ERROR);
            }
            $childrenIDs = $object['ChildrenIDs'];
            $badIDs = [];
            foreach ($childrenIDs as $childrenID) {
                if (IPS_ObjectExists($childrenID) == false) {
                    $s = $this->TranslateFormat('child object with ID {$childrenID} doesn\'t exists', ['{$childrenID}' => $childrenID]);
                    $this->AddMessageEntry($messageList, 'objects', $objectID, $s, self::$LEVEL_ERROR);
                }
            }
        }
        $counterList['objects'] = [
            'total' => count($objectList)
        ];

        // Instanzen
        $instanceStatusCodes = [
            IS_CREATING   => 'Instance getting created',
            IS_ACTIVE     => 'Instance is active',
            IS_DELETING   => 'Instance is deleted',
            IS_INACTIVE   => 'Instance is inactive',
            IS_NOTCREATED => 'Instance is not created',
        ];

        $instanceList = IPS_GetInstanceList();
        $instanceActive = 0;
        foreach ($instanceList as $instanceID) {
            if (in_array($instanceID, $ignoreObjects)) {
                continue;
            }
            $instance = IPS_GetInstance($instanceID);
            $instanceStatus = $instance['InstanceStatus'];
            if ($instanceStatus == IS_ACTIVE) {
                $instanceActive++;
                continue;
            }
            if (isset($instanceStatusCodes[$instanceStatus])) {
                $s = $this->Translate($instanceStatusCodes[$instanceStatus]);
            } else {
                $s = $this->TranslateFormat('Status {$instanceStatus}', ['{$instanceStatus}' => $instanceStatus]);
            }
            switch ($instanceStatus) {
                case IS_CREATING:
                case IS_DELETING:
                    $lvl = self::$LEVEL_WARN;
                    break;
                case IS_INACTIVE:
                    $lvl = self::$LEVEL_INFO;
                    break;
                case IS_NOTCREATED:
                    $lvl = self::$LEVEL_ERROR;
                    break;
                default:
                    $lvl = self::$LEVEL_INFO;
                    break;
            }
            $this->AddMessageEntry($messageList, 'instances', $instanceID, $s, $lvl);
        }

        // Referenzen der Instanzen
        foreach ($instanceList as $instanceID) {
            if (in_array($instanceID, $ignoreObjects)) {
                continue;
            }
            $refIDs = IPS_GetReferenceList($instanceID);
            if ($refIDs != false) {
                foreach ($refIDs as $refID) {
                    if (IPS_ObjectExists($refID) == false) {
                        $s = $this->TranslateFormat('referenced object with ID {$refID} doesn\'t exists', ['{$refID}' => $refID]);
                        $this->AddMessageEntry($messageList, 'instances', $instanceID, $s, self::$LEVEL_ERROR);
                    }
                }
            }
        }

        $counterList['instances'] = [
            'total'  => count($instanceList),
            'active' => $instanceActive,
        ];

        // Scripte
        $scriptList = IPS_GetScriptList();
        $scriptTypeCount = [];
        $scriptTypes = [SCRIPTTYPE_PHP];
        $scriptTypeNames = ['php-script'];
        if (IPS_GetKernelVersion() >= 6) {
            define('SCRIPTTYPE_FLOW', 1);
            $scriptTypes[] = SCRIPTTYPE_FLOW;
            $scriptTypeNames[] = 'flow-script';
        }
        foreach ($scriptTypes as $scriptType) {
            $fileListIPS = [];
            $fileListSYS = [];
            $fileListINC = [];

            $scriptTypeCount[$scriptType] = 0;
            $scriptTypeName = $scriptTypeNames[$scriptType];

            foreach ($scriptList as $scriptID) {
                $script = IPS_GetScript($scriptID);
                if ($script['ScriptType'] != $scriptType) {
                    continue;
                }
                $scriptTypeCount[$scriptType]++;
                $fileListIPS[] = $script['ScriptFile'];
                if (in_array($scriptID, $ignoreObjects)) {
                    continue;
                }
                if ($script['ScriptIsBroken']) {
                    $s = $this->Translate('ist fehlerhaft');
                    $this->AddMessageEntry($messageList, $this->Translate('scripts'), $scriptID, $s, self::$LEVEL_ERROR);
                }
            }
            $this->SendDebug(__FUNCTION__, $scriptTypeName . ' from IPS: fileListIPS=' . print_r($fileListIPS, true), 0);

            // Script im Filesystem
            $path = IPS_GetKernelDir() . 'scripts';
            $handle = opendir($path);
            while ($file = readdir($handle)) {
                if (!is_file($path . '/' . $file)) {
                    continue;
                }
                if ($scriptType == SCRIPTTYPE_PHP) {
                    if (!preg_match('/^.*\.php$/', $file)) {
                        continue;
                    }
                    if (preg_match('/^.*\.inc\.php$/', $file)) {
                        continue;
                    }
                }
                if (IPS_GetKernelVersion() >= 6 && $scriptType == SCRIPTTYPE_FLOW) {
                    if (!preg_match('/^.*\.json$/', $file)) {
                        continue;
                    }
                }
                $fileListSYS[] = $file;
            }
            closedir($handle);
            $this->SendDebug(__FUNCTION__, $scriptTypeName . ' in filesystem: fileListSYS=' . print_r($fileListSYS, true), 0);

            if ($scriptType == SCRIPTTYPE_PHP) {
                foreach ($fileListIPS as $file) {
                    $text = @file_get_contents($path . '/' . $file);
                    if ($text == false) {
                        $this->SendDebug(__FUNCTION__, $scriptTypeName . '/include - no content: file=' . $file, 0);
                        continue;
                    }
                    $scriptID = @IPS_GetScriptIDByFile($file);
                    if (in_array($scriptID, $ignoreObjects)) {
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
                            $this->SendDebug(__FUNCTION__, $scriptTypeName . '/include - match#1 file=' . $x[1] . ': file=' . $file . ', line=' . $line, 0);
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
                            $this->SendDebug(__FUNCTION__, $scriptTypeName . '/include - match#2 id=' . $x[1] . ': file=' . $file . ', line=' . $line, 0);
                            $id = $x[1];
                            $incFile = @IPS_GetScriptFile($id);
                            if ($incFile == false) {
                                $s = $this->TranslateFormat($scriptTypeName . ' with ID {$id} doesn\'t exists', ['{$id}' => $id]);
                                $this->AddMessageEntry($messageList, $this->Translate('scripts'), $scriptID, $s, self::$LEVEL_ERROR);
                            } else {
                                if (!in_array($incFile, $fileListINC)) {
                                    $fileListINC[] = $incFile;
                                }
                            }
                        } else {
                            $this->SendDebug(__FUNCTION__, $scriptTypeName . '/include - no match: file=' . $file . ', line=' . $line, 0);
                        }
                    }
                }
                $this->SendDebug(__FUNCTION__, $scriptTypeName . '/include: fileListINC=' . print_r($fileListINC, true), 0);
            }

            // überflüssige Scripte
            $scriptError = 0;
            foreach ($fileListSYS as $file) {
                if (in_array($file, $fileListIPS) || in_array($file, $fileListINC)) {
                    continue;
                }
                $s = $this->TranslateFormat('file "{$file}" is unused', ['{$file}' => $file]);
                $this->AddMessageEntry($messageList, $this->Translate('scripts'), 0, $s, self::$LEVEL_INFO);
            }

            // fehlende Scripte
            $scriptError = 0;
            foreach ($scriptList as $scriptID) {
                if (in_array($scriptID, $ignoreObjects)) {
                    continue;
                }
                $script = IPS_GetScript($scriptID);
                if ($script['ScriptType'] != $scriptType) {
                    continue;
                }
                $file = $script['ScriptFile'];
                if (in_array($file, $fileListSYS)) {
                    continue;
                }
                $s = $this->TranslateFormat('file "{$file}" is missing', ['{$file}' => $file]);
                $this->AddMessageEntry($messageList, $this->Translate('scripts'), $scriptID, $s, self::$LEVEL_ERROR);
            }

            if ($scriptType == SCRIPTTYPE_PHP) {
                // Objekt-ID's in Scripten
                foreach ($fileListSYS as $file) {
                    if (!in_array($file, $fileListIPS)) {
                        continue;
                    }
                    $text = @file_get_contents($path . '/' . $file);
                    if ($text == false) {
                        $this->SendDebug(__FUNCTION__, $scriptTypeName . '/object-id - no content: file=' . $file, 0);
                        continue;
                    }
                    $scriptID = @IPS_GetScriptIDByFile($file);
                    if (in_array($scriptID, $ignoreObjects)) {
                        continue;
                    }
                    $ret = $this->parseText4ObjectIDs($file, $text, $objectList, $ignoreNums);
                    foreach ($ret as $r) {
                        $id = $r['id'];
                        $row = $r['row'];
                        if ($id != false) {
                            $s = $this->TranslateFormat('row {$row} - a object with ID {$id} doesn\'t exists', ['{$row}' => $row, '{$id}' => $id]);
                            $this->AddMessageEntry($messageList, $this->Translate('scripts'), $scriptID, $s, self::$LEVEL_ERROR);
                        }
                    }
                }
            }

            if (IPS_GetKernelVersion() >= 6 && $scriptType == SCRIPTTYPE_FLOW) {
                foreach ($fileListSYS as $file) {
                    if (!in_array($file, $fileListIPS)) {
                        continue;
                    }
                    $text = @file_get_contents($path . '/' . $file);
                    if ($text == false) {
                        $this->SendDebug(__FUNCTION__, $scriptTypeName . ' - no content: file=' . $file, 0);
                        continue;
                    }
                    $scriptID = @IPS_GetScriptIDByFile($file);
                    if (in_array($scriptID, $ignoreObjects)) {
                        continue;
                    }

					/*
                    $jtext = json_decode($text, true);
                    $this->SendDebug(__FUNCTION__, 'flow-script - text=' . print_r($jtext, true), 0);
                    foreach ($jtext['actions'] as $action) {
                        $this->SendDebug(__FUNCTION__, 'flow-script - action=' . print_r($action, true), 0);
                        if (isset($action['parameters']['SCRIPT'])) {
                            $text = $action['parameters']['SCRIPT'];
                            $this->SendDebug(__FUNCTION__, $scriptTypeName . '/object-ids - lines=' . print_r($lines, true), 0);
                            $ret = $this->parseText4ObjectIDs($file, $text, $objectList, $ignoreNums);
                            foreach ($ret as $r) {
                                $id = $r['id'];
                                $row = $r['row'];
                                if ($id != false) {
                                    $s = $this->TranslateFormat('flow/script - a object with ID {$id} doesn\'t exists', ['{$row}' => $row, '{$id}' => $id]);
                                    $this->AddMessageEntry($messageList, $this->Translate('scripts'), $scriptID, $s, self::$LEVEL_ERROR);
                                }
                            }
                        }
                        if (isset($action['parameters']['VARIABLE'])) {
                            $varID = intval($action['parameters']['VARIABLE']);
                            $this->SendDebug(__FUNCTION__, $scriptTypeName . '/object-ids - varID=' . $varID, 0);
                            if ($varID != 0 && IPS_VariableExists($varID) == false) {
                                $s = $this->TranslateFormat('flow/variable {$varID} doesn\'t exists', ['{$varID}' => $varID]);
                                $this->AddMessageEntry($messageList, 'events', $eventID, $s, self::$LEVEL_ERROR);
                            }
                        }
                    }
					*/
                }
            }
        }

        $counterList['scripts'] = [
            'total' => count($scriptList),
            'types' => $scriptTypeCount,
        ];

        // Events
        $eventList = IPS_GetEventList();
        $eventActive = 0;
        foreach ($eventList as $eventID) {
            if (in_array($eventID, $ignoreObjects)) {
                continue;
            }
            $event = IPS_GetEvent($eventID);
            $active = $event['EventActive'];
            if ($active) {
                $eventActive++;
            }
            $err = 0;
            $varID = $event['TriggerVariableID'];
            if ($varID != 0 && IPS_VariableExists($varID) == false) {
                $s = $this->TranslateFormat('triggering variable {$varID} doesn\'t exists', ['{$varID}' => $varID]);
                $this->AddMessageEntry($messageList, 'events', $eventID, $s, self::$LEVEL_ERROR);
            }
            $eventConditions = $event['EventConditions'];
            foreach ($eventConditions as $eventCondition) {
                $variableRules = $eventCondition['VariableRules'];
                foreach ($variableRules as $variableRule) {
                    $varID = $variableRule['VariableID'];
                    if ($varID != 0 && IPS_VariableExists($varID) == false) {
                        $s = $this->TranslateFormat('condition variable {$varID} doesn\'t exists', ['{$varID}' => $varID]);
                        $this->AddMessageEntry($messageList, 'events', $eventID, $s, self::$LEVEL_ERROR);
                    }
                }
            }
        }
        $counterList['events'] = [
            'total'  => count($eventList),
            'active' => $eventActive,
        ];

        // Variablen
        $variableList = IPS_GetVariableList();
        foreach ($variableList as $variableID) {
            if (in_array($variableID, $ignoreObjects)) {
                continue;
            }
            $variable = IPS_GetVariable($variableID);

            // Variablenprofile
            $variableType = $variable['VariableType'];
            $variableProfile = $variable['VariableProfile'];
            if ($variableProfile != false) {
                $profile = @IPS_GetVariableProfile($variableProfile);
                if ($profile == false) {
                    $s = $this->TranslateFormat('default profile "{$variableProfile}" doesn\'t exists', ['{$variableProfile}' => $variableProfile]);
                    $this->AddMessageEntry($messageList, 'variables', $variableID, $s, self::$LEVEL_ERROR);
                } else {
                    $profileType = $profile['ProfileType'];
                    if ($variableType != $profileType) {
                        $s = $this->TranslateFormat('default profile "{$variableProfile}" has wrong type', ['{$variableProfile}' => $variableProfile]);
                        $this->AddMessageEntry($messageList, 'variables', $variableID, $s, self::$LEVEL_ERROR);
                    }
                }
            }
            $variableCustomProfile = $variable['VariableCustomProfile'];
            if ($variableCustomProfile != false) {
                $profile = @IPS_GetVariableProfile($variableCustomProfile);
                if ($profile == false) {
                    $s = $this->TranslateFormat('user profile "{$variableCustomProfile}" doesn\'t exists', ['{$variableCustomProfile}' => $variableCustomProfile]);
                    $this->AddMessageEntry($messageList, 'variables', $variableID, $s, self::$LEVEL_ERROR);
                } else {
                    $profileType = $profile['ProfileType'];
                    if ($variableType != $profileType) {
                        $s = $this->TranslateFormat('user profile "{$variableProfile}" has wrong type', ['{$variableProfile}' => $variableProfile]);
                        $this->AddMessageEntry($messageList, 'variables', $variableID, $s, self::$LEVEL_ERROR);
                    }
                }
            }

            // Variableaktionen
            $variableAction = $variable['VariableAction'];
            if ($variableAction > 0 && IPS_InstanceExists($variableAction) == false) {
                $s = $this->TranslateFormat('default action with ID {$variableAction} doesn\'t exists', ['{$variableAction}' => $variableAction]);
                $this->AddMessageEntry($messageList, 'variables', $variableID, $s, self::$LEVEL_ERROR);
            }
            $variableCustomAction = $variable['VariableCustomAction'];
            if ($variableCustomAction > 1 && IPS_ScriptExists($variableCustomAction) == false) {
                $s = $this->TranslateFormat('user action with ID {$variableAction} doesn\'t exists', ['{$variableCustomAction}' => $variableCustomAction]);
                $this->AddMessageEntry($messageList, 'variables', $variableID, $s, self::$LEVEL_ERROR);
            }
        }
        $counterList['variables'] = [
            'total' => count($variableList)
        ];

        // Medien
        $path = IPS_GetKernelDir();
        $mediaList = IPS_GetMediaList();
        foreach ($mediaList as $mediaID) {
            if (in_array($mediaID, $ignoreObjects)) {
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
                $this->AddMessageEntry($messageList, 'media', $mediaID, $s, self::$LEVEL_WARN);
            } else {
                $s = $this->TranslateFormat('file "{$file}" is missing', ['{$file}' => $file]);
                $this->AddMessageEntry($messageList, 'media', $mediaID, $s, self::$LEVEL_ERROR);
            }
        }
        $counterList['media'] = [
            'total' => count($mediaList)
        ];

        // Kategorien
        $categoryList = IPS_GetCategoryList();
        $counterList['categories'] = [
            'total' => count($categoryList)
        ];

        // Links
        $linkList = IPS_GetLinkList();
        foreach ($linkList as $linkID) {
            $link = IPS_GetLink($linkID);
            $targetID = $link['TargetID'];
            if (!IPS_ObjectExists($targetID)) {
                $s = $this->TranslateFormat('target object with ID {$targetID} doesn\'t exists', ['{$targetID}' => $targetID]);
                $this->AddMessageEntry($messageList, 'links', $linkID, $s, self::$LEVEL_ERROR);
            }
        }
        $counterList['links'] = [
            'total' => count($linkList)
        ];

        // Module
        $moduleList = IPS_GetModuleList();
        foreach ($moduleList as $guid) {
            $module = IPS_GetModule($guid);
            $moduleID = $module['ModuleID'];
            if (IPS_ModuleExists($moduleID) == false) {
                $s = $this->TranslateFormat('module {$moduleName}: module "{$moduleID}" is missing', ['{$moduleName}' => $moduleName, '{$moduleID}' => $moduleID]);
                $this->AddMessageEntry($messageList, 'modules', 0, $s, self::$LEVEL_ERROR);
            }
            $libraryID = $module['LibraryID'];
            if (IPS_LibraryExists($libraryID) == false) {
                $s = $this->TranslateFormat('module {$moduleName}: library "{$libraryID}" is missing', ['{$moduleName}' => $moduleName, '{$libraryID}' => $libraryID]);
                $this->AddMessageEntry($messageList, 'modules', 0, $s, self::$LEVEL_ERROR);
            }
        }
        $counterList['modules'] = [
            'total' => count($moduleList)
        ];

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
        $counterList['timer'] = [
            'total' => $timerCount,
            '1min'  => $timer1MinCount,
            '5min'  => $timer5MinCount,
        ];

        // Threads
        $threadList = IPS_GetScriptThreadList();
        $threadUsed = 0;
        foreach ($threadList as $t => $i) {
            $thread = IPS_GetScriptThread($i);
            // $this->SendDebug(__FUNCTION__, 'thread=' . print_r($thread, true) . ', t=' . print_r($t, true) . ', i=' . $i, 0);

            $ScriptID = $thread['ScriptID'];
            if ($ScriptID != 0) {
                $threadUsed++;
            }
        }
        $counterList['threads'] = [
            'total' => count($threadList),
            'used'  => $threadUsed
        ];

        /*
        $check_script = $this->ReadPropertyInteger('check_script');
        if ($check_script > 0) {
            $checkResult = [
                'counterList'  => $counterList,
                'messageList'  => $messageList,
            ];
            $ret = IPS_RunScriptWaitEx($check_script, ['InstanceID' => $this->InstanceID, 'CheckResult' => json_encode($checkResult)]);
            if ($ret) {
                $jret = json_decode($ret, true);
                if (isset($jret['counterList'])
                    $counterList = $jret['counterList'];
                if (isset($jret['messageList']))
                    $messageList = $jret['messageList'];
            }
            $this->SendDebug(__FUNCTION__, 'call script ' . IPS_GetParent($post_script) . '\\' . IPS_GetName($post_script) . ', ret=' . $ret, 0);
        }
         */

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
        $this->SendDebug(__FUNCTION__, 'counterList=' . print_r($counterList, true), 0);
        $this->SendDebug(__FUNCTION__, 'messageList,=' . print_r($messageList, true), 0);
        $this->SendDebug(__FUNCTION__, ' errorCount=' . $errorCount . ', warnCount=' . $warnCount . ', infoCount=' . $infoCount, 0);

        // HTML-Text aufbauen
        $html = '';
        $html .= '<head>' . PHP_EOL;
        $html .= '<style>' . PHP_EOL;
        $html .= 'body { margin: 1; padding: 0; font-family: "Open Sans", sans-serif; font-size: 16px; }' . PHP_EOL;
        $html .= 'table { border-collapse: collapse; border: 0px solid; margin: 0.5em;}' . PHP_EOL;
        $html .= 'th, td { padding: 1; }' . PHP_EOL;
        $html .= 'thead, tdata { text-align: left; }' . PHP_EOL;
        $html .= '#spalte_title { width: 250px; }' . PHP_EOL;
        $html .= '#spalte_value { }' . PHP_EOL;
        $html .= '</style>' . PHP_EOL;
        $html .= '</head>' . PHP_EOL;
        $html .= '<body>' . PHP_EOL;
        $html .= '<table>' . PHP_EOL;
        $html .= '<colgroup><col id="spalte_title"></colgroup>' . PHP_EOL;
        $html .= '<colgroup><col id="spalte_value"></colgroup>' . PHP_EOL;

        $html .= '<tr><td>' . $this->Translate('Timestamp') . '</td><td>' . date('d.m.Y H:i:s', $now) . '</td></tr>' . PHP_EOL;
        $html .= '<tr><td>&nbsp;</td></tr>' . PHP_EOL;

        foreach ($counterList as $tag => $counters) {
            $total = $counters['total'];
            switch ($tag) {
                case 'timer':
                    $s = ' (1m=' . $counters['1min'] . ', 5m=' . $counters['5min'] . ')';
                    break;
                case 'instances':
                    $s = ' (' . $this->Translate('active') . '=' . $counters['active'] . ')';
                    break;
                case 'scripts':
                    $s = '';
                    if (IPS_GetKernelVersion() >= 6) {
                        foreach ($scriptTypes as $scriptType) {
                            if ($s != '') {
                                $s .= ', ';
                            }
                            $scriptTypeName = $scriptTypeNames[$scriptType];
                            $s .= $this->Translate($scriptTypeName) . '=' . $counters['types'][$scriptType];
                        }
                        $s = ' (' . $s . ')';
                    }
                    break;
                case 'events':
                    $s = ' (' . $this->Translate('active') . '=' . $counters['active'] . ')';
                    break;
                case 'threads':
                    $s = ' (' . $this->Translate('used') . '=' . $counters['used'] . ')';
                    break;
                default:
                    $s = '';
                    break;
            }
            $html .= '<tr><td>' . $this->Translate($tag) . '</td><td>' . $total . $s . '</td></tr>' . PHP_EOL;
        }

        $html .= '</table>' . PHP_EOL;
        if (count($messageList)) {
            foreach ($messageList as $tag => $entries) {
                $html .= '<b>' . $this->Translate($tag) . ':</b><br>' . PHP_EOL;
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

        $checkResult = [
            'timestamp'    => $now,
            'counterList'  => $counterList,
            'messageList'  => $messageList,
            'errorCount'   => $errorCount,
            'warnCount'    => $warnCount,
            'infoCount'    => $infoCount,
        ];

        $save_checkResult = $this->ReadPropertyBoolean('save_checkResult');
        if ($save_checkResult) {
            $this->SetValue('CheckResult', json_encode($checkResult));
        }

        if ($errorCount) {
            $s = $this->TranslateFormat('found {$errorCount} errors, {$warnCount} warnings and {$infoCount} informations',
                [
                    '{$errorCount}' => $errorCount,
                    '{$warnCount}'  => $warnCount,
                    '{$infoCount}'  => $infoCount
                ]);
            $this->LogMessage($s, KL_WARNING);
        }

        $post_script = $this->ReadPropertyInteger('post_script');
        if ($post_script > 0) {
            $ret = IPS_RunScriptEx($post_script, ['InstanceID' => $this->InstanceID, 'CheckResult' => json_encode($checkResult)]);
            $this->SendDebug(__FUNCTION__, 'call script ' . IPS_GetParent($post_script) . '\\' . IPS_GetName($post_script) . ', ret=' . $ret, 0);
        }
    }

    private function cmp_messages($a, $b)
    {
        $a_id = $a['ID'];
        $b_id = $b['ID'];
        if ($a_id != $b_id) {
            return ($a_id < $b_id) ? -1 : 1;
        }
        $a_level = $a['Level'];
        $b_level = $b['Level'];
        if ($a_level != $b_level) {
            return ($a_level > $b_level) ? -1 : 1;
        }
        $a_msg = $a['Msg'];
        $b_msg = $b['Msg'];
        return (strcmp($a_msg, $b_msg) < 0) ? -1 : 1;
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
        usort($entV, ['IntegrityCheck', 'cmp_messages']);
        $lst[$tag] = $entV;
    }

    private function parseText4ObjectIDs($file, $text, $objectList, $ignoreNums)
    {
        $no_id_check = $this->ReadPropertyString('no_id_check');

        $ret = [];
        $lines = explode(PHP_EOL, $text);
        $row = 0;
        foreach ($lines as $line) {
            $row++;
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
            if (in_array($id, $ignoreNums)) {
                continue;
            }
            if (!in_array($id, $objectList)) {
                $ret[] = [
                    'id' => $id,
                    'row'=> $row,
                ];
            }
        }
        return $ret;
    }
}
