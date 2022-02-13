<?php

declare(strict_types=1);

trait IntegrityCheckCommonLib
{
    protected function SetValue($Ident, $Value)
    {
        @$varID = $this->GetIDForIdent($Ident);
        if ($varID == false) {
            $this->SendDebug(__FUNCTION__, 'missing variable ' . $Ident, 0);
            return;
        }

        @$ret = parent::SetValue($Ident, $Value);
        if ($ret == false) {
            $this->SendDebug(__FUNCTION__, 'mismatch of value "' . $Value . '" for variable ' . $Ident, 0);
        }
    }

    protected function GetValue($Ident)
    {
        @$varID = $this->GetIDForIdent($Ident);
        if ($varID == false) {
            $this->SendDebug(__FUNCTION__, 'missing variable ' . $Ident, 0);
            return false;
        }

        $ret = parent::GetValue($Ident);
        return $ret;
    }

    private function CreateVarProfile($Name, $ProfileType, $Suffix, $MinValue, $MaxValue, $StepSize, $Digits, $Icon, $Associations = '')
    {
        if (!IPS_VariableProfileExists($Name)) {
            IPS_CreateVariableProfile($Name, $ProfileType);
            IPS_SetVariableProfileText($Name, '', $Suffix);
            if (in_array($ProfileType, [VARIABLETYPE_INTEGER, VARIABLETYPE_FLOAT])) {
                IPS_SetVariableProfileValues($Name, $MinValue, $MaxValue, $StepSize);
                IPS_SetVariableProfileDigits($Name, $Digits);
            }
            IPS_SetVariableProfileIcon($Name, $Icon);
            if ($Associations != '') {
                foreach ($Associations as $a) {
                    $w = isset($a['Wert']) ? $a['Wert'] : '';
                    $n = isset($a['Name']) ? $a['Name'] : '';
                    $i = isset($a['Icon']) ? $a['Icon'] : '';
                    $f = isset($a['Farbe']) ? $a['Farbe'] : 0;
                    IPS_SetVariableProfileAssociation($Name, $w, $n, $i, $f);
                }
            }
        }
    }

    private function GetArrayElem($data, $var, $dflt)
    {
        $ret = $data;
        $vs = explode('.', $var);
        foreach ($vs as $v) {
            if (!isset($ret[$v])) {
                $ret = $dflt;
                break;
            }
            $ret = $ret[$v];
        }
        return $ret;
    }

    private function bool2str($bval)
    {
        if (is_bool($bval)) {
            return $bval ? 'true' : 'false';
        }
        return $bval;
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

    private function GetStatusText()
    {
        $txt = false;
        $status = $this->GetStatus();
        $formStatus = $this->GetFormStatus();
        foreach ($formStatus as $item) {
            if ($item['code'] == $status) {
                $txt = $item['caption'];
                break;
            }
        }

        return $txt;
    }

    private function TranslateFormat(string $str, array $vars = null)
    {
        $str = $this->Translate($str);
        if ($vars != null) {
            $str = strtr($str, $vars);
        }
        return $str;
    }

    private function LimitOutput($str, int $maxLength = null)
    {
        $lim = IPS_GetOption('ScriptOutputBufferLimit');
        if (is_null($maxLength)) {
            $maxLength = intval($lim / 10);
        } elseif ($maxLength == 0) {
            $maxLength = $lim - 1024;
        } elseif ($maxLength < 0) {
            $maxLength = $lim - $maxLength;
        } elseif ($maxLength > $lim) {
            $maxLength = $lim;
        }

        if (is_array($str)) {
            $str = print_r($str, true);
        }

        $len = strlen($str);
        if ($len > $maxLength) {
            $s = '»[cut=' . $maxLength . '/' . $len . ']';
            $cutLen = $maxLength - strlen($s);
            $str = substr($str, 0, $cutLen) . $s;
        }
        return $str;
    }

    private function InstanceInfo(int $instID)
    {
        $obj = IPS_GetObject($instID);
        $inst = IPS_GetInstance($instID);
        $mod = IPS_GetModule($inst['ModuleInfo']['ModuleID']);
        $lib = IPS_GetLibrary($mod['LibraryID']);

        $s = '';
        $m = [];

        $s .= 'Modul "' . $mod['ModuleName'] . '"' . PHP_EOL;
        $s .= '  GUID: ' . $mod['ModuleID'] . PHP_EOL;
        $m[] = 'module=' . $mod['ModuleName'];

        $s .= PHP_EOL;

        $s .= 'Library "' . $lib['Name'] . '"' . PHP_EOL;
        $s .= '  GUID: ' . $lib['LibraryID'] . PHP_EOL;
        $s .= '  Version: ' . $lib['Version'] . PHP_EOL;
        $m[] = 'version=' . $lib['Version'];
        if ($lib['Build'] > 0) {
            $s .= '  Build: ' . $lib['Build'] . PHP_EOL;
            $m[] = 'build=' . $lib['Build'];
        }
        $ts = $lib['Date'];
        $d = $ts > 0 ? date('d.m.Y H:i:s', $ts) : '';
        $s .= '  Date: ' . $d . PHP_EOL;
        $m[] = 'date=' . $d;

        $src = '';
        $scID = IPS_GetInstanceListByModuleID('{F45B5D1F-56AE-4C61-9AB2-C87C63149EC3}')[0];
        $scList = SC_GetModuleInfoList($scID);
        foreach ($scList as $sc) {
            if ($sc['LibraryID'] == $lib['LibraryID']) {
                $src = ($src != '' ? ' + ' : '') . 'ModuleStore';
                switch ($sc['Channel']) {
                    case 1:
                        $src .= '/Beta';
                        break;
                    case 2:
                        $src .= '/Testing';
                        break;
                    default:
                        break;
                }
                $m[] = 'source=' . $src;
                break;
            }
        }
        $mcID = IPS_GetInstanceListByModuleID('{B8A5067A-AFC2-3798-FEDC-BCD02A45615E}')[0];
        $mcList = MC_GetModuleList($mcID);
        foreach ($mcList as $mc) {
            @$g = MC_GetModule($mcID, $mc);
            if ($g == false) {
                continue;
            }
            if ($g['LibraryID'] == $lib['LibraryID']) {
                @$r = MC_GetModuleRepositoryInfo($mcID, $mc);
                if ($r == false) {
                    continue;
                }
                $url = $r['ModuleURL'];
                if (preg_match('/^([^:]*):\/\/[^@]*@(.*)$/', $url, $p)) {
                    $url = $p[1] . '://' . $p[2];
                }
                $src = ($src != '' ? ' + ' : '') . $url;
                $branch = $r['ModuleBranch'];
                switch ($branch) {
                    case 'master':
                    case 'main':
                        $m[] = 'source=git';
                        break;
                    default:
                        $src .= ' [' . $branch . ']';
                        $m[] = 'source=git' . $src . '[' . $branch . ']';
                        break;
                }
                break;
            }
        }
        $s .= '  Source: ' . $src . PHP_EOL;

        $this->SendDebug(__FUNCTION__, implode(', ', $m), 0);
        return $s;
    }
}
