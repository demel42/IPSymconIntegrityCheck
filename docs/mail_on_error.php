<?php

declare(strict_types=1);

// SMTP Instanz
$smptID = 0;

$scriptName = IPS_GetName($_IPS['SELF']) . '(' . $_IPS['SELF'] . ')';
$scriptInfo = IPS_GetName(IPS_GetParent($_IPS['SELF'])) . '\\' . IPS_GetName($_IPS['SELF']);

// IPS_LogMessage($scriptName, $scriptInfo . ': _IPS=' . print_r($_IPS, true));

$instID = $_IPS['InstanceID'];
$checkResult = json_decode($_IPS['CheckResult'], true);

// IPS_LogMessage($scriptName, $scriptInfo . ': checkResult=' . print_r($checkResult, true));

$messageList = $checkResult['messageList'];
$errorCount = $checkResult['errorCount'];

if ($errorCount > 0) {
    $txt = '';
    foreach ($messageList as $tag => $entries) {
        foreach ($entries as $entry) {
            $lvl = $entry['Level'];
            if ($lvl == 2 /* Error */) {
                $id = $entry['ID'];
                if ($id != 0) {
                    $txt .= '#' . $id;
                    $loc = @IPS_GetLocation($id);
                    if ($loc != false) {
                        $txt .= '(' . $loc . ')';
                    }
                    $txt .= ': ';
                }
                $txt .= $entry['Msg'];
                $txt .= PHP_EOL;
            }
        }
        $txt .= PHP_EOL;
    }
    IPS_LogMessage($scriptName, $scriptInfo . ': send mail with ' . $errorCount . ' errors');
    SMTP_SendMail($smptID, 'IPS-Check', $txt);
}

return true;
