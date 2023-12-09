<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/common.php';
require_once __DIR__ . '/../libs/local.php';

class IntegrityCheck extends IPSModule
{
    use IntegrityCheck\StubsCommonLib;
    use IntegrityCheckLocalLib;

    public function __construct(string $InstanceID)
    {
        parent::__construct($InstanceID);

        $this->CommonContruct(__DIR__);
    }

    public function __destruct()
    {
        $this->CommonDestruct();
    }

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyBoolean('module_disable', false);

        $this->RegisterPropertyInteger('update_interval', 60);
        $this->RegisterPropertyString('ignore_objects', json_encode([]));
        $this->RegisterPropertyInteger('ignore_category', 1);
        $this->RegisterPropertyString('ignore_nums', json_encode([]));
        $this->RegisterPropertyString('no_id_check', '/*NO_ID_CHECK*/');
        $this->RegisterPropertyBoolean('modulstatus_as_error', true);
        $this->RegisterPropertyBoolean('save_checkResult', false);
        $this->RegisterPropertyInteger('post_script', 0);

        $this->RegisterPropertyInteger('monitor_interval', 60);
        $this->RegisterPropertyBoolean('monitor_with_logging', true);

        $this->RegisterPropertyInteger('thread_limit_info', 10);
        $this->RegisterPropertyInteger('thread_limit_warn', 60);
        $this->RegisterPropertyInteger('thread_limit_error', 300);
        $this->RegisterPropertyInteger('thread_warn_usage', 10);
        $this->RegisterPropertyString('thread_ignore_scripts', json_encode([]));

        $this->RegisterAttributeString('UpdateInfo', json_encode([]));
        $this->RegisterAttributeString('ModuleStats', json_encode([]));

        $this->InstallVarProfiles(false);

        $this->RegisterTimer('PerformCheck', 0, 'IPS_RequestAction(' . $this->InstanceID . ', "PerformCheck", "");');
        $this->RegisterTimer('MonitorThreads', 0, 'IPS_RequestAction(' . $this->InstanceID . ', "MonitorThreads", "");');

        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
    }

    public function MessageSink($tstamp, $senderID, $message, $data)
    {
        parent::MessageSink($tstamp, $senderID, $message, $data);

        if ($message == IPS_KERNELMESSAGE && $data[0] == KR_READY) {
            $this->SetUpdateInterval();
        }
    }

    private function CheckModuleConfiguration()
    {
        $r = [];

        $monitor_interval = $this->ReadPropertyInteger('monitor_interval');
        $save_checkResult = $this->ReadPropertyBoolean('save_checkResult');
        $monitor_with_logging = $this->ReadPropertyBoolean('monitor_with_logging');
        if ($monitor_interval > 0 && $save_checkResult == false && $monitor_with_logging == false) {
            $r[] = $this->Translate('Thread monitoring is useless without saving results or notification');
        }

        $thread_limit_info = $this->ReadPropertyInteger('thread_limit_info');
        $thread_limit_warn = $this->ReadPropertyInteger('thread_limit_warn');
        $thread_limit_error = $this->ReadPropertyInteger('thread_limit_error');
        if ($thread_limit_info > $thread_limit_warn || $thread_limit_warn > $thread_limit_error) {
            $r[] = $this->Translate('Runtime limit must be ascending');
        }

        return $r;
    }

    private function CheckModuleUpdate(array $oldInfo, array $newInfo)
    {
        $r = [];

        if ($this->version2num($oldInfo) < $this->version2num('1.7.4')) {
            $ignore_category = $this->ReadPropertyInteger('ignore_category');
            if ($ignore_category == 0) {
                $r[] = $this->Translate('Adjust Field "ignore objects below this category"');
            }
        }

        return $r;
    }

    private function CompleteModuleUpdate(array $oldInfo, array $newInfo)
    {
        if ($this->version2num($oldInfo) < $this->version2num('1.7.4')) {
            $ignore_category = $this->ReadPropertyInteger('ignore_category');
            if ($ignore_category == 0) {
                IPS_SetProperty($this->InstanceID, 'ignore_category', 1);
            }
        }

        return '';
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $propertyNames = ['ignore_category', 'post_script'];
        $this->MaintainReferences($propertyNames);

        $ignore_objects = $this->ReadPropertyString('ignore_objects');
        $objectList = json_decode($ignore_objects, true);
        if ($ignore_objects != false) {
            foreach ($objectList as $obj) {
                $oid = $obj['ObjectID'];
                if ($this->IsValidID($oid)) {
                    $this->RegisterReference($oid);
                }
            }
        }

        $ignore_scripts = $this->ReadPropertyString('thread_ignore_scripts');
        $scriptList = json_decode($ignore_scripts, true);
        if ($ignore_scripts != false) {
            foreach ($scriptList as $scr) {
                $sid = $scr['ScriptID'];
                if ($this->IsValidID($sid)) {
                    $this->RegisterReference($sid);
                }
            }
        }

        if ($this->CheckPrerequisites() != false) {
            $this->MaintainTimer('PerformCheck', 0);
            $this->MaintainTimer('MonitorThreads', 0);
            $this->MaintainStatus(self::$IS_INVALIDPREREQUISITES);
            return;
        }

        if ($this->CheckUpdate() != false) {
            $this->MaintainTimer('PerformCheck', 0);
            $this->MaintainTimer('MonitorThreads', 0);
            $this->MaintainStatus(self::$IS_UPDATEUNCOMPLETED);
            return;
        }

        if ($this->CheckConfiguration() != false) {
            $this->MaintainTimer('PerformCheck', 0);
            $this->MaintainTimer('MonitorThreads', 0);
            $this->MaintainStatus(self::$IS_INVALIDCONFIG);
            return;
        }

        $save_checkResult = $this->ReadPropertyBoolean('save_checkResult');

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
            $this->MaintainTimer('PerformCheck', 0);
            $this->MaintainTimer('MonitorThreads', 0);
            $this->MaintainStatus(IS_INACTIVE);
            return;
        }

        $this->MaintainStatus(IS_ACTIVE);

        if (IPS_GetKernelRunlevel() == KR_READY) {
            $this->SetUpdateInterval();
        }
    }

    private function GetFormElements()
    {
        $formElements = $this->GetCommonFormElements('Symcon integrity check');

        if ($this->GetStatus() == self::$IS_UPDATEUNCOMPLETED) {
            return $formElements;
        }

        $formElements[] = [
            'type'    => 'CheckBox',
            'name'    => 'module_disable',
            'caption' => 'Disable instance',
        ];

        $formElements[] = [
            'type'    => 'ExpansionPanel',
            'items'   => [
                [
                    'type'    => 'NumberSpinner',
                    'name'    => 'update_interval',
                    'minimum' => 0,
                    'suffix'  => 'Minutes',
                    'caption' => 'Interval of the full test',
                ],
                [
                    'type'    => 'CheckBox',
                    'name'    => 'save_checkResult',
                    'caption' => 'Save complete results of the test',
                ],
                [
                    'type'    => 'SelectScript',
                    'name'    => 'post_script',
                    'caption' => 'Script called after test execution',
                ],
            ],
            'caption' => 'Basic settings',
        ];

        $formElements[] = [
            'type'    => 'ExpansionPanel',
            'items'   => [
                [
                    'type'    => 'RowLayout',
                    'items'   => [
                        [
                            'type'     => 'List',
                            'name'     => 'ignore_objects',
                            'rowCount' => 5,
                            'add'      => true,
                            'delete'   => true,
                            'columns'  => [
                                [
                                    'caption'  => 'objects',
                                    'name'     => 'ObjectID',
                                    'width'    => '400px',
                                    'add'      => -1,
                                    'edit'     => [
                                        'type'    => 'SelectObject',
                                    ]
                                ],
                                [
                                    'caption'  => 'including children',
                                    'name'     => 'with_childs',
                                    'width'    => '200px',
                                    'add'      => false,
                                    'edit'     => [
                                        'type'    => 'CheckBox',
                                    ]
                                ]
                            ]
                        ],
                        [
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
                        ]
                    ]
                ],
                [
                    'type'    => 'SelectCategory',
                    'name'    => 'ignore_category',
                    'caption' => 'ignore objects below this category'
                ],
                [
                    'type'    => 'ValidationTextBox',
                    'name'    => 'no_id_check',
                    'caption' => 'don\'t check PHP-line with this comment'
                ],
                [
                    'type'    => 'CheckBox',
                    'name'    => 'modulstatus_as_error',
                    'caption' => 'Report module-specific status (>= 200) as error',
                ],
            ],
            'caption' => 'Elements to be ignored ...',
        ];

        $formElements[] = [
            'type'    => 'ExpansionPanel',
            'caption' => 'Threads',
            'items'   => [
                [
                    'type'     => 'Label',
                    'caption'  => 'Runtime limit',
                ],
                [
                    'type'    => 'NumberSpinner',
                    'suffix'  => 's',
                    'name'    => 'thread_limit_info',
                    'caption' => 'Information',
                    'width'   => '200px',
                ],
                [
                    'type'    => 'RowLayout',
                    'items'   => [
                        [
                            'type'    => 'NumberSpinner',
                            'suffix'  => 's',
                            'name'    => 'thread_limit_warn',
                            'caption' => 'Warning',
                            'width'   => '200px',
                        ],
                        [
                            'type'    => 'NumberSpinner',
                            'suffix'  => '%',
                            'name'    => 'thread_warn_usage',
                            'caption' => 'Only when utilisation above',
                            'width'   => '200px',
                        ],
                    ],
                ],
                [
                    'type'    => 'NumberSpinner',
                    'suffix'  => 's',
                    'name'    => 'thread_limit_error',
                    'caption' => 'Error',
                    'width'   => '200px',
                ],
                [
                    'type'     => 'List',
                    'name'     => 'thread_ignore_scripts',
                    'rowCount' => 5,
                    'add'      => true,
                    'delete'   => true,
                    'columns'  => [
                        [
                            'caption'  => 'Scripts to be ignored ...',
                            'name'     => 'ScriptID',
                            'width'    => '400px',
                            'add'      => -1,
                            'edit'     => [
                                'type'    => 'SelectScript',
                                'caption' => 'Scripts to be ignored ...'
                            ]
                        ]
                    ]
                ],
                [
                    'type'    => 'NumberSpinner',
                    'name'    => 'monitor_interval',
                    'minimum' => 0,
                    'suffix'  => 'Seconds',
                    'caption' => 'Thread check interval'
                ],
                [
                    'type'    => 'CheckBox',
                    'name'    => 'monitor_with_logging',
                    'caption' => 'Notify about long-running threads'
                ],
            ],
        ];

        return $formElements;
    }

    private function GetFormActions()
    {
        $formActions = [];

        if ($this->GetStatus() == self::$IS_UPDATEUNCOMPLETED) {
            $formActions[] = $this->GetCompleteUpdateFormAction();

            $formActions[] = $this->GetInformationFormAction();
            $formActions[] = $this->GetReferencesFormAction();

            return $formActions;
        }

        $formActions[] = [
            'type'    => 'Button',
            'caption' => 'Perform check',
            'onClick' => 'IPS_RequestAction(' . $this->InstanceID . ', "PerformCheck", "");',
        ];

        $formActions[] = $this->GetInformationFormAction();
        $formActions[] = $this->GetReferencesFormAction();

        return $formActions;
    }

    private function LocalRequestAction($ident, $value)
    {
        $r = true;
        switch ($ident) {
            case 'PerformCheck':
                $this->PerformCheck();
                break;
            case 'MonitorThreads':
                $this->MonitorThreads();
                break;
            default:
                $r = false;
                break;
        }
        return $r;
    }

    public function RequestAction($ident, $value)
    {
        if ($this->LocalRequestAction($ident, $value)) {
            return;
        }
        if ($this->CommonRequestAction($ident, $value)) {
            return;
        }
        switch ($ident) {
            default:
                $this->SendDebug(__FUNCTION__, 'invalid ident ' . $ident, 0);
                break;
        }
    }

    private function SetUpdateInterval()
    {
        $min = $this->ReadPropertyInteger('update_interval');
        $msec = $min > 0 ? $min * 1000 * 60 : 0;
        $this->MaintainTimer('PerformCheck', $msec);

        $sec = $this->ReadPropertyInteger('monitor_interval');
        $msec = $sec > 0 ? $sec * 1000 : 0;
        $this->MaintainTimer('MonitorThreads', $msec);
    }

    private function BuildOverview($checkResult)
    {
        $thread_limit_info = $this->ReadPropertyInteger('thread_limit_info');
        $thread_limit_warn = $this->ReadPropertyInteger('thread_limit_warn');
        $thread_limit_error = $this->ReadPropertyInteger('thread_limit_error');

        $tstamp = $checkResult['timestamp'];
        $counterList = $checkResult['counterList'];
        $messageList = $checkResult['messageList'];

        $scriptTypes = [SCRIPTTYPE_PHP];
        $scriptTypeNames = ['php script'];
        if (IPS_GetKernelVersion() >= 6) {
            if (!defined('SCRIPTTYPE_FLOW')) {
                define('SCRIPTTYPE_FLOW', 1);
            }
            $scriptTypes[] = SCRIPTTYPE_FLOW;
            $scriptTypeNames[] = 'flow plan';
        }

        // HTML-Text aufbauen
        $html = '';
        $html .= '<head>' . PHP_EOL;
        $html .= '<style>' . PHP_EOL;
        // $html .= 'body { margin: 1; padding: 0; font-family: "Open Sans", sans-serif; font-size: 16px; }' . PHP_EOL;
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

        $html .= '<tr><td>' . $this->Translate('Timestamp') . '</td><td>' . date('d.m.Y H:i:s', $tstamp) . '</td></tr>' . PHP_EOL;
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
                case 'variables':
                    $s = ' (' . $this->Translate('unused') . '=' . $counters['unused'] . ')';
                    break;
                case 'events':
                    $s = ' (' . $this->Translate('active') . '=' . $counters['active'] . ')';
                    break;
                case 'threads':
                    $s = ' (' . $this->Translate('used') . '=' . $counters['used'];
                    if ($counters['error']) {
                        $s .= ', >' . $thread_limit_error . 's=' . $counters['error'];
                    }
                    if ($counters['warn']) {
                        $s .= ', >' . $thread_limit_warn . 's=' . $counters['warn'];
                    }
                    if ($counters['info']) {
                        $s .= ', >' . $thread_limit_info . 's=' . $counters['info'];
                    }
                    $s .= ')';
                    break;
                default:
                    $s = '';
                    break;
            }
            $html .= '<tr><td>' . $this->Translate($tag) . '</td><td>' . $total . $s . '</td></tr>' . PHP_EOL;
        }

        $html .= '</table>' . PHP_EOL;
        $n_messages = 0;
        foreach ($messageList as $tag => $entries) {
            if ($entries == []) {
                continue;
            }
            $n_messages++;
            $html .= $this->Translate($tag) . ':<br>' . PHP_EOL;
            foreach ($entries as $entry) {
                $lvl = $entry['Level'];
                switch ($lvl) {
                        case self::$LEVEL_INFO:
                            $col = 'grey';
                            break;
                        case self::$LEVEL_WARN:
                            $col = 'gold';
                            break;
                        case self::$LEVEL_ERROR:
                        default:
                            $col = 'red';
                            break;
                    }
                $html .= '<span style="color: ' . $col . ';">&nbsp;&nbsp;&nbsp;';
                $id = $entry['ID'];
                if ($this->IsValidID($id) && IPS_ObjectExists($id)) {
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
        if ($n_messages == 0) {
            $html .= '<br>' . $this->Translate('no abnormalities') . '<br>' . PHP_EOL;
        }
        $html .= '</body>' . PHP_EOL;

        return $html;
    }

    private function decodeAction4Event($actionID, $actionParameters, $eventID, $eventTypeName, &$messageList, $objectList, $ignoreNums, $fileListINC, $fileListIPS)
    {
        $this->SendDebug(__FUNCTION__, 'event=' . IPS_GetName($eventID) . '(' . $eventID . '), actionID=' . $actionID . ', actionParameters=' . print_r($actionParameters, true), 0);

        if (isset($actionParameters['VARIABLE'])) {
            $varID = intval($actionParameters['VARIABLE']);
            if ($this->IsValidID($varID) && IPS_VariableExists($varID) == false) {
                $this->SendDebug(__FUNCTION__, $eventTypeName . ' - variable ' . $varID . ' doesn\'t exists', 0);
                $s = $this->TranslateFormat($eventTypeName . ' - variable {$varID} doesn\'t exists', ['{$varID}' => $varID]);
                $this->AddMessageEntry($messageList, 'events', $eventID, $s, self::$LEVEL_ERROR);
            }
        }
        if (isset($actionParameters['SCRIPT'])) {
            $file = 'Action #' . $eventID;
            $text = $actionParameters['SCRIPT'];
            $this->SendDebug(__FUNCTION__, 'script=' . $text, 0);
            $ret = $this->parseText4ObjectIDs($file, $text, $objectList, $ignoreNums);
            foreach ($ret as $r) {
                $row = $r['row'];
                $id = $r['id'];
                if ($id != false) {
                    $s = $this->TranslateFormat($eventTypeName . ', script row {$row} - a object with ID {$id} doesn\'t exists', ['{$row}' => $row, '{$id}' => $id]);
                    $this->AddMessageEntry($messageList, 'events', $eventID, $s, self::$LEVEL_ERROR);
                }
            }
            $ret = $this->parseText4Includes($file, $text, $objectList, $ignoreNums, $eventTypeName, $fileListINC, $fileListIPS);
            foreach ($ret as $r) {
                $row = $r['row'];
                if (isset($r['file'])) {
                    $file = $r['file'];
                    $s = $this->TranslateFormat($eventTypeName . ', script row {$row} - file "{$file}" is missing', ['{$row}' => $row, '{$file}' => $file]);
                    $this->AddMessageEntry($messageList, 'scripts', $scriptID, $s, self::$LEVEL_ERROR);
                } else {
                    $id = $r['id'];
                    if ($id != false) {
                        $s = $this->TranslateFormat($eventTypeName . ', script row {$row} - script with ID {$id} doesn\'t exists', ['{$row}' => $row, '{$id}' => $id]);
                        $this->AddMessageEntry($messageList, 'events', $eventID, $s, self::$LEVEL_ERROR);
                    }
                }
            }
        }
    }

    private function decodeAction4FlowScript($action, $steps, $lvl, $scriptID, $scriptTypeName, &$messageList, $objectList, $ignoreNums, $fileListINC, $fileListIPS)
    {
        $step = '';
        for ($l = 0; $l <= $lvl; $l++) {
            if ($step != '') {
                $step .= '-';
            }
            $step .= strval($steps[$l]);
        }
        $this->SendDebug(__FUNCTION__, $scriptTypeName . '/script=' . IPS_GetName($scriptID) . '(' . $scriptID . '), step=' . $step . ', action=' . print_r($action, true), 0);

        if (isset($action['parameters']['TARGET'])) {
            $objID = intval($action['parameters']['TARGET']);
            if ($objID != -1) {
                if ($this->IsValidID($objID) && IPS_ObjectExists($objID) == false) {
                    $this->SendDebug(__FUNCTION__, $scriptTypeName . '/action step=' . $step . ' - object ' . $objID . ' doesn\'t exists', 0);
                    $s = $this->TranslateFormat('flow plan step {$step} - target {$objID} doesn\'t exists', ['{$step}' => $step, '{$objID}' => $objID]);
                    $this->AddMessageEntry($messageList, 'scripts', $scriptID, $s, self::$LEVEL_ERROR);
                }
            }
        }

        if (isset($action['parameters']['VARIABLE'])) {
            $varID = intval($action['parameters']['VARIABLE']);
            if ($this->IsValidID($varID) && IPS_VariableExists($varID) == false) {
                $this->SendDebug(__FUNCTION__, $scriptTypeName . '/action step=' . $step . ' - variable ' . $varID . ' doesn\'t exists', 0);
                $s = $this->TranslateFormat('flow plan step {$step} - variable {$varID} doesn\'t exists', ['{$step}' => $step, '{$varID}' => $varID]);
                $this->AddMessageEntry($messageList, 'scripts', $scriptID, $s, self::$LEVEL_ERROR);
            }
        }

        if (isset($action['parameters']['CONDITION'])) {
            $conditions = json_decode($action['parameters']['CONDITION'], true);
            if ($conditions != false) {
                foreach ($conditions as $condition) {
                    $vars = $condition['rules']['variable'];
                    foreach ($vars as $var) {
                        $this->SendDebug(__FUNCTION__, $scriptTypeName . '/var=' . print_r($var, true), 0);
                        $varID = $this->GetArrayElem($var, 'variableID', 0);
                        if ($this->IsValidID($varID) && IPS_VariableExists($varID) == false) {
                            $this->SendDebug(__FUNCTION__, $scriptTypeName . '/action step=' . $step . ' - condition/variable ' . $varID . ' doesn\'t exists', 0);
                            $s = $this->TranslateFormat('flow plan step {$step} - variable {$varID} doesn\'t exists', ['{$step}' => $step, '{$varID}' => $varID]);
                            $this->AddMessageEntry($messageList, 'scripts', $scriptID, $s, self::$LEVEL_ERROR);
                        }
                        if ($this->GetArrayElem($var, 'type', 0) == 1 /* compare with variable */) {
                            $varID = $var['value'];
                            if ($this->IsValidID($varID) && IPS_VariableExists($varID) == false) {
                                $this->SendDebug(__FUNCTION__, $scriptTypeName . '/action step=' . $step . ' - condition/variable ' . $varID . ' doesn\'t exists', 0);
                                $s = $this->TranslateFormat('flow plan step {$step} - variable {$varID} doesn\'t exists', ['{$step}' => $step, '{$varID}' => $varID]);
                                $this->AddMessageEntry($messageList, 'scripts', $scriptID, $s, self::$LEVEL_ERROR);
                            }
                        }
                    }
                }
            }
        }
        if (isset($action['parameters']['SCRIPT'])) {
            $file = 'Action #' . $scriptID . '_' . $step;
            $text = $action['parameters']['SCRIPT'];
            $ret = $this->parseText4ObjectIDs($file, $text, $objectList, $ignoreNums);
            foreach ($ret as $r) {
                $row = $r['row'];
                $id = $r['id'];
                if ($id != false) {
                    $s = $this->TranslateFormat('flow plan step {$step}, script row {$row} - a object with ID {$id} doesn\'t exists', ['{$step}' => $step, '{$row}' => $row, '{$id}' => $id]);
                    $this->AddMessageEntry($messageList, 'scripts', $scriptID, $s, self::$LEVEL_ERROR);
                }
            }
            $ret = $this->parseText4Includes($file, $text, $objectList, $ignoreNums, $scriptTypeName, $fileListINC, $fileListIPS);
            foreach ($ret as $r) {
                $row = $r['row'];
                if (isset($r['file'])) {
                    $file = $r['file'];
                    $s = $this->TranslateFormat('flow plan step {$step}, script row {$row} - file "{$file}" is missing', ['{$step}' => $step, '{$row}' => $row, '{$file}' => $file]);
                    $this->AddMessageEntry($messageList, 'scripts', $scriptID, $s, self::$LEVEL_ERROR);
                } else {
                    $id = $r['id'];
                    if ($id != false) {
                        $s = $this->TranslateFormat('flow-plan step {$step}, script row {$row} - script with ID {$id} doesn\'t exists', ['{$step}' => $step, '{$row}' => $row, '{$id}' => $id]);
                        $this->AddMessageEntry($messageList, 'scripts', $scriptID, $s, self::$LEVEL_ERROR);
                    }
                }
            }
        }
        if (isset($action['parameters']['ACTIONS'])) {
            $lvl++;
            $steps[$lvl] = 0;
            foreach ($action['parameters']['ACTIONS'] as $a) {
                $steps[$lvl]++;
                $this->decodeAction4FlowScript($a, $steps, $lvl, $scriptID, $scriptTypeName, $messageList, $objectList, $ignoreNums, $fileListINC, $fileListIPS);
            }
        }
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
                if (IPS_ObjectExists($oid)) {
                    $ignoreObjects[] = $oid;
                    $with_childs = isset($obj['with_childs']) ? $obj['with_childs'] : false;
                    if ($with_childs) {
                        $this->GetAllChildenIDs($oid, $ignoreObjects);
                    }
                }
            }
        }

        $ignore_category = $this->ReadPropertyInteger('ignore_category');
        if (IPS_CategoryExists($ignore_category)) {
            $this->GetAllChildenIDs($ignore_category, $ignoreObjects);
        }

        $this->SendDebug(__FUNCTION__, 'ignoreObjects=' . print_r($ignoreObjects, true), 0);

        // zu ignorierende Zahlen
        $ignoreNums = [];
        $ignore_nums = $this->ReadPropertyString('ignore_nums');
        $numList = json_decode($ignore_nums, true);
        if ($numList != false) {
            foreach ($numList as $num) {
                $id = $num['ID'];
                if ($this->IsValidID($id)) {
                    $ignoreNums[] = $id;
                }
            }
        }
        $this->SendDebug(__FUNCTION__, 'ignoreNums=' . print_r($ignoreNums, true), 0);

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
            if ($this->IsValidID($parentID) && IPS_ObjectExists($parentID) == false) {
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

        $modulstatus_as_error = $this->ReadPropertyBoolean('modulstatus_as_error');

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
                    $lvl = $modulstatus_as_error ? self::$LEVEL_ERROR : self::$LEVEL_INFO;
                    break;
            }
            $this->AddMessageEntry($messageList, 'instances', $instanceID, $s, $lvl);
        }

        // Referenzen der Instanzen
        foreach ($instanceList as $instanceID) {
            if (in_array($instanceID, $ignoreObjects)) {
                continue;
            }
            $refIDs = @IPS_GetReferenceList($instanceID);
            if ($refIDs != false) {
                foreach ($refIDs as $refID) {
                    if ($this->IsValidID($refID) && IPS_ObjectExists($refID) == false) {
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
        $scriptTypeNames = ['php script'];
        if (IPS_GetKernelVersion() >= 6) {
            if (!defined('SCRIPTTYPE_FLOW')) {
                define('SCRIPTTYPE_FLOW', 1);
            }
            $scriptTypes[] = SCRIPTTYPE_FLOW;
            $scriptTypeNames[] = 'flow plan';
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
                    $s = $this->Translate('is faulty');
                    $this->AddMessageEntry($messageList, 'scripts', $scriptID, $s, self::$LEVEL_ERROR);
                }
            }
            $this->SendDebug(__FUNCTION__, $scriptTypeName . ' from IPS: fileListIPS (count=' . count($fileListIPS) . ')=' . $this->LimitOutput($fileListIPS), 0);

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
                    if ($file == '__generated.inc.php') {
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
            $this->SendDebug(__FUNCTION__, $scriptTypeName . ' from filesystem:: fileListSYS (count=' . count($fileListSYS) . ')=' . $this->LimitOutput($fileListSYS), 0);

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
                    $ret = $this->parseText4Includes($file, $text, $objectList, $ignoreNums, $scriptTypeName, $fileListINC, $fileListIPS);
                    foreach ($ret as $r) {
                        $row = $r['row'];
                        if (isset($r['file'])) {
                            $file = $r['file'];
                            $s = $this->TranslateFormat('row {$row} - file "{$file}" is missing', ['{$row}' => $row, '{$file}' => $file]);
                            $this->AddMessageEntry($messageList, 'scripts', $scriptID, $s, self::$LEVEL_ERROR);
                        } else {
                            $id = $r['id'];
                            if ($id != false) {
                                $s = $this->TranslateFormat('row {$row} - script with ID {$id} doesn\'t exists', ['{$row}' => $row, '{$id}' => $id]);
                                $this->AddMessageEntry($messageList, 'scripts', $scriptID, $s, self::$LEVEL_ERROR);
                            }
                        }
                    }
                }
                $this->SendDebug(__FUNCTION__, $scriptTypeName . '/include: fileListINC (count=' . count($fileListINC) . ')=' . $this->LimitOutput($fileListINC), 0);
            }

            // überflüssige Scripte
            $scriptError = 0;
            foreach ($fileListSYS as $file) {
                if (in_array($file, $fileListIPS) || in_array($file, $fileListINC)) {
                    continue;
                }
                $s = $this->TranslateFormat('file "{$file}" is unused', ['{$file}' => $file]);
                $this->AddMessageEntry($messageList, 'scripts', 1, $s, self::$LEVEL_INFO);
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
                $this->AddMessageEntry($messageList, 'scripts', $scriptID, $s, self::$LEVEL_ERROR);
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
                        $row = $r['row'];
                        $id = $r['id'];
                        if ($id != false) {
                            $s = $this->TranslateFormat('row {$row} - a object with ID {$id} doesn\'t exists', ['{$row}' => $row, '{$id}' => $id]);
                            $this->AddMessageEntry($messageList, 'scripts', $scriptID, $s, self::$LEVEL_ERROR);
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
                    $jtext = json_decode($text, true);

                    $steps = [0];
                    foreach ($jtext['actions'] as $action) {
                        $steps[0]++;
                        $this->decodeAction4FlowScript($action, $steps, 0, $scriptID, $scriptTypeName, $messageList, $objectList, $ignoreNums, $fileListINC, $fileListIPS);
                    }
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
            if ($this->IsValidID($varID) && IPS_VariableExists($varID) == false) {
                $s = $this->TranslateFormat('triggering variable {$varID} doesn\'t exists', ['{$varID}' => $varID]);
                $this->AddMessageEntry($messageList, 'events', $eventID, $s, self::$LEVEL_ERROR);
            }
            $eventConditions = $event['EventConditions'];
            foreach ($eventConditions as $eventCondition) {
                $variableRules = $eventCondition['VariableRules'];
                foreach ($variableRules as $variableRule) {
                    $varID = $variableRule['VariableID'];
                    if ($this->IsValidID($varID) && IPS_VariableExists($varID) == false) {
                        $s = $this->TranslateFormat('condition variable {$varID} doesn\'t exists', ['{$varID}' => $varID]);
                        $this->AddMessageEntry($messageList, 'events', $eventID, $s, self::$LEVEL_ERROR);
                    }
                }
            }

            $actionID = $event['EventActionID'];
            $actionParameters = $event['EventActionParameters'];
            $this->decodeAction4Event($actionID, $actionParameters, $eventID, 'event action', $messageList, $objectList, $ignoreNums, $fileListINC, $fileListIPS);

            $scheduleActions = $event['ScheduleActions'];
            foreach ($scheduleActions as $scheduleAction) {
                $actionID = $scheduleAction['ActionID'];
                $actionParameters = $scheduleAction['ActionParameters'];
                $this->decodeAction4Event($actionID, $actionParameters, $eventID, 'schedule action', $messageList, $objectList, $ignoreNums, $fileListINC, $fileListIPS);
            }
        }
        $counterList['events'] = [
            'total'  => count($eventList),
            'active' => $eventActive,
        ];

        // Variablen
        $variableList = IPS_GetVariableList();
        $variableUnused = 0;
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

            // Benutzung?
            if ($variable['VariableUpdated'] == 0) {
                $obj = IPS_GetObject($variableID);
                if ($variableAction <= 0 && $variableCustomAction <= 1 && $obj['ObjectIdent'] == false) {
                    $variableUnused++;
                    $s = $this->TranslateFormat('is unused');
                    $this->AddMessageEntry($messageList, 'variables', $variableID, $s, self::$LEVEL_INFO);
                }
            }
        }
        $counterList['variables'] = [
            'total'  => count($variableList),
            'unused' => $variableUnused,
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
            if (in_array($linkID, $ignoreObjects)) {
                continue;
            }
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

        $thread_limit_info = $this->ReadPropertyInteger('thread_limit_info');
        $thread_limit_warn = $this->ReadPropertyInteger('thread_limit_warn');
        $thread_limit_error = $this->ReadPropertyInteger('thread_limit_error');
        $thread_warn_usage = $this->ReadPropertyInteger('thread_warn_usage');

        // Threads
        $threadList = IPS_GetScriptThreadList();
        $threadMaxCount = IPS_GetOption('ThreadCount');
        $threadUsed = 0;
        $threadInfo = 0;
        $threadWarn = 0;
        $threadError = 0;
        foreach ($threadList as $t => $i) {
            $thread = IPS_GetScriptThread($i);
            if ($thread['StartTime'] == 0) {
                continue;
            }
            $threadUsed++;
        }

        $doWarn = true;
        if ($thread_warn_usage > 0) {
            $u = $threadMaxCount / 100 * $thread_warn_usage;
            if ($threadUsed < $u) {
                $doWarn = false;
            }
            $this->SendDebug(__FUNCTION__, 'threads max=' . $threadMaxCount . ', used=' . $threadUsed . ', usage-limit=' . $u . ' => doWarn=' . $this->bool2str($doWarn), 0);
        }

        foreach ($threadList as $t => $i) {
            $thread = IPS_GetScriptThread($i);
            // $this->SendDebug(__FUNCTION__, 'thread=' . print_r($thread, true) . ', t=' . print_r($t, true) . ', i=' . $i, 0);

            $startTime = $thread['StartTime'];
            if ($startTime == 0) {
                continue;
            }

            $sec = $now - $startTime;
            $duration = '';
            if ($sec > 3600) {
                $duration .= sprintf('%dh', floor($sec / 3600));
                $sec = $sec % 3600;
            }
            if ($sec > 60) {
                $duration .= sprintf('%dm', floor($sec / 60));
                $sec = $sec % 60;
            }
            if ($sec > 0 || $duration == '') {
                $duration .= sprintf('%ds', $sec);
                $sec = floor($sec);
            }
            $sec = $now - $startTime;

            $scriptID = $thread['ScriptID'];
            if ($this->IsValidID($scriptID)) {
                $ident = IPS_GetName($scriptID) . '(' . $scriptID . ')';
                $s = $this->TranslateFormat('script "{$ident}" is running since {$duration}', ['{$ident}' => $ident, '{$duration}' => $duration]);
            } else {
                $ident = $thread['FilePath'];
                $s = $this->TranslateFormat('function "{$ident}" is running since {$duration}', ['{$ident}' => $ident, '{$duration}' => $duration]);
            }

            if ($sec >= $thread_limit_error) {
                $threadError++;
                $this->AddMessageEntry($messageList, 'threads', 0, $s, self::$LEVEL_ERROR);
            } elseif ($doWarn && $sec >= $thread_limit_warn) {
                $threadWarn++;
                $this->AddMessageEntry($messageList, 'threads', 0, $s, self::$LEVEL_WARN);
            } elseif ($sec >= $thread_limit_info) {
                $threadInfo++;
                $this->AddMessageEntry($messageList, 'threads', 0, $s, self::$LEVEL_INFO);
            }
        }
        $counterList['threads'] = [
            'total' => count($threadList),
            'used'  => $threadUsed,
            'info'  => $threadInfo,
            'warn'  => $threadWarn,
            'error' => $threadError,
        ];

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
        $this->SendDebug(__FUNCTION__, 'messageList=' . print_r($messageList, true), 0);
        $this->SendDebug(__FUNCTION__, 'errorCount=' . $errorCount . ', warnCount=' . $warnCount . ', infoCount=' . $infoCount, 0);

        $checkResult = [
            'timestamp'    => $now,
            'counterList'  => $counterList,
            'messageList'  => $messageList,
            'errorCount'   => $errorCount,
            'warnCount'    => $warnCount,
            'infoCount'    => $infoCount,
        ];

        $html = $this->BuildOverview($checkResult);
        $this->SetValue('Overview', $html);

        $startTime = IPS_GetKernelStartTime();
        $this->SetValue('StartTime', $startTime);

        $this->SetValue('ErrorCount', $errorCount);
        $this->SetValue('WarnCount', $warnCount);
        $this->SetValue('InfoCount', $infoCount);

        $this->SetValue('LastUpdate', $now);

        $save_checkResult = $this->ReadPropertyBoolean('save_checkResult');
        if ($save_checkResult) {
            $this->SetValue('CheckResult', json_encode($checkResult));
        }

        if ($errorCount) {
            $s = $this->TranslateFormat(
                'found {$errorCount} errors, {$warnCount} warnings and {$infoCount} informations',
                [
                    '{$errorCount}' => $errorCount,
                    '{$warnCount}'  => $warnCount,
                    '{$infoCount}'  => $infoCount
                ]
            );
            $this->LogMessage($s, KL_WARNING);
        }

        $post_script = $this->ReadPropertyInteger('post_script');
        if (IPS_ScriptExists($post_script)) {
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
            $patternV = [
                '/[^!=><]=[\t ]*([0-9]{5})[^0-9]/',
                '/[\t ]*=[\t ]*([0-9]{5})[^0-9]/',
                '/\([\t ]*([0-9]{5})[^0-9]/',
            ];
            foreach ($patternV as $pattern) {
                if (preg_match_all($pattern, $line, $r)) {
                    foreach ($r[1] as $id) {
                        $this->SendDebug(__FUNCTION__, 'script/object-id - match#1 id=' . $id . ': file=' . $file . ', line=' . $this->LimitOutput($line), 0);
                        if (in_array($id, $ignoreNums)) {
                            continue;
                        }
                        if (in_array($id, $objectList)) {
                            continue;
                        }
                        $fnd = false;
                        foreach ($ret as $r) {
                            if ($r['id'] == $id && $r['row'] == $row) {
                                $fnd = true;
                                break;
                            }
                        }
                        if ($fnd == false) {
                            $ret[] = [
                                'row' => $row,
                                'id'  => $id,
                            ];
                        }
                    }
                }
            }
        }
        return $ret;
    }

    private function parseText4Includes($file, $text, $objectList, $ignoreNums, $scriptTypeName, $fileListINC, $fileListIPS)
    {
        $no_id_check = $this->ReadPropertyString('no_id_check');

        $path = IPS_GetKernelDir() . 'scripts';

        $ret = [];
        $row = 0;
        $lines = explode(PHP_EOL, $text);
        foreach ($lines as $line) {
            $row++;
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
                $this->SendDebug(__FUNCTION__, $scriptTypeName . '/include - match#1 file=' . $x[1] . ': file=' . $file . ', line=' . $this->LimitOutput($line), 0);
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
                $r[] = [
                    'row'  => $row,
                    'file' => $incFile,
                ];
            } elseif (preg_match('/IPS_GetScriptFile[\t ]*\([\t ]*([0-9]{5})[\t ]*\)/', $a, $x)) {
                $this->SendDebug(__FUNCTION__, $scriptTypeName . '/include - match#2 id=' . $x[1] . ': file=' . $file . ', line=' . $this->LimitOutput($line), 0);
                $id = $x[1];
                $incFile = @IPS_GetScriptFile($id);
                if ($incFile == false) {
                    $r[] = [
                        'row' => $row,
                        'id'  => $id,
                    ];
                } else {
                    if (!in_array($incFile, $fileListINC)) {
                        $fileListINC[] = $incFile;
                    }
                }
            } else {
                $this->SendDebug(__FUNCTION__, $scriptTypeName . '/include - no match: file=' . $file . ', line=' . $this->LimitOutput($line), 0);
            }
        }
        return $ret;
    }

    private function GetAllChildenIDs($objID, &$objIDs)
    {
        $cIDs = IPS_GetChildrenIDs($objID);
        if ($cIDs != []) {
            $objIDs = array_merge($objIDs, $cIDs);
            foreach ($cIDs as $cID) {
                $this->GetAllChildenIDs($cID, $objIDs);
            }
        }
    }

    public function MonitorThreads()
    {
        $thread_limit_info = $this->ReadPropertyInteger('thread_limit_info');
        $thread_limit_warn = $this->ReadPropertyInteger('thread_limit_warn');
        $thread_limit_error = $this->ReadPropertyInteger('thread_limit_error');
        $thread_warn_usage = $this->ReadPropertyInteger('thread_warn_usage');
        $monitor_with_logging = $this->ReadPropertyBoolean('monitor_with_logging');

        // zu ignorierende Script-IDs
        $ignoreScripts = [];
        $ignore_scripts = $this->ReadPropertyString('thread_ignore_scripts');
        $scriptList = json_decode($ignore_scripts, true);
        if ($ignore_scripts != false) {
            foreach ($scriptList as $scr) {
                $sid = $scr['ScriptID'];
                if (IPS_ScriptExists($sid)) {
                    $this->RegisterReference($sid);
                    $ignoreScripts[] = $sid;
                }
            }
        }

        $save_checkResult = $this->ReadPropertyBoolean('save_checkResult');
        if ($save_checkResult) {
            $old_CheckResult = $this->GetValue('CheckResult');
            $checkResult = json_decode($old_CheckResult, true);
        } else {
            $checkResult = false;
        }
        $this->SendDebug(__FUNCTION__, 'checkResult=' . print_r($checkResult, true), 0);

        $now = time();

        if ($checkResult != false) {
            $messageList = $checkResult['messageList'];
            $messageList['threads'] = [];
        }

        $threadList = IPS_GetScriptThreadList();
        $threadMaxCount = IPS_GetOption('ThreadCount');
        $threadUsed = 0;
        $threadInfo = 0;
        $threadWarn = 0;
        $threadError = 0;
        foreach ($threadList as $t => $i) {
            $thread = IPS_GetScriptThread($i);
            if ($thread['StartTime'] == 0) {
                continue;
            }
            $threadUsed++;
        }

        $doWarn = true;
        if ($thread_warn_usage > 0) {
            $u = $threadMaxCount / 100 * $thread_warn_usage;
            if ($threadUsed < $u) {
                $doWarn = false;
            }
            $this->SendDebug(__FUNCTION__, 'threads max=' . $threadMaxCount . ', used=' . $threadUsed . ', usage-limit=' . $u . ' => doWarn=' . $this->bool2str($doWarn), 0);
        }

        foreach ($threadList as $t => $i) {
            $thread = IPS_GetScriptThread($i);

            $startTime = $thread['StartTime'];
            if ($startTime == 0) {
                continue;
            }

            $this->SendDebug(__FUNCTION__, 'thread #' . $i . '=' . print_r($thread, true), 0);

            $sec = $now - $startTime;
            $duration = '';
            if ($sec > 3600) {
                $duration .= sprintf('%dh', floor($sec / 3600));
                $sec = $sec % 3600;
            }
            if ($sec > 60) {
                $duration .= sprintf('%dm', floor($sec / 60));
                $sec = $sec % 60;
            }
            if ($sec > 0 || $duration == '') {
                $duration .= sprintf('%ds', $sec);
                $sec = floor($sec);
            }
            $sec = $now - $startTime;

            $sender = $thread['Sender'];
            $threadId = $thread['ThreadID'];
            $scriptID = $thread['ScriptID'];
            if ($this->IsValidID($scriptID)) {
                if (in_array($scriptID, $ignoreScripts)) {
                    continue;
                }
                $ident = IPS_GetName($scriptID) . '(' . $scriptID . ')';
                $s = $this->TranslateFormat('script "{$ident}" is running since {$duration}', ['{$ident}' => $ident, '{$duration}' => $duration]);
                $m = 'thread=' . $threadId . ', script=' . $ident . ', sender=' . $sender . ', duration=' . $duration;
            } else {
                $ident = $thread['FilePath'];
                $s = $this->TranslateFormat('function "{$ident}" is running since {$duration}', ['{$ident}' => $ident, '{$duration}' => $duration]);
                $m = 'thread=' . $threadId . ', function=' . $ident . ', sender=' . $sender . ', duration=' . $duration;
            }

            if ($sec >= $thread_limit_error) {
                $threadError++;
                if ($checkResult != false) {
                    $this->AddMessageEntry($messageList, 'threads', 0, $s, self::$LEVEL_ERROR);
                }
                if ($monitor_with_logging) {
                    $this->LogMessage(__FUNCTION__ . ': ' . $m, KL_ERROR);
                }
            } elseif ($doWarn && $sec >= $thread_limit_warn) {
                $threadWarn++;
                if ($checkResult != false) {
                    $this->AddMessageEntry($messageList, 'threads', 0, $s, self::$LEVEL_WARN);
                }
                if ($monitor_with_logging) {
                    $this->LogMessage(__FUNCTION__ . ': ' . $m, KL_WARNING);
                }
            } elseif ($sec >= $thread_limit_info) {
                $threadInfo++;
                if ($checkResult != false) {
                    $this->AddMessageEntry($messageList, 'threads', 0, $s, self::$LEVEL_INFO);
                }
            }
            $this->SendDebug(__FUNCTION__, $m, 0);
        }

        if ($checkResult != false) {
            $counterList = $checkResult['counterList'];
            $counterList['threads'] = [
                'total' => count($threadList),
                'used'  => $threadUsed,
                'info'  => $threadInfo,
                'warn'  => $threadWarn,
                'error' => $threadError,
            ];
            $checkResult['counterList'] = $counterList;
            $checkResult['messageList'] = $messageList;

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
            $checkResult['errorCount'] = $errorCount;
            $checkResult['warnCount'] = $warnCount;
            $checkResult['infoCount'] = $infoCount;

            if (json_encode($checkResult) != $old_CheckResult) {
                $checkResult['timestamp'] = $now;

                $html = $this->BuildOverview($checkResult);
                $this->SetValue('Overview', $html);

                $this->SetValue('CheckResult', json_encode($checkResult));

                $this->SetValue('ErrorCount', $errorCount);
                $this->SetValue('WarnCount', $warnCount);
                $this->SetValue('InfoCount', $infoCount);

                $this->SetValue('LastUpdate', $now);
            }
        }
    }
}
