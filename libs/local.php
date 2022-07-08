<?php

declare(strict_types=1);

trait IntegrityCheckLocalLib
{
    private function GetFormStatus()
    {
        $formStatus = $this->GetCommonFormStatus();

        return $formStatus;
    }

    public static $STATUS_INVALID = 0;
    public static $STATUS_VALID = 1;
    public static $STATUS_RETRYABLE = 2;

    private function CheckStatus()
    {
        switch ($this->GetStatus()) {
            case IS_ACTIVE:
                $class = self::$STATUS_VALID;
                break;
            default:
                $class = self::$STATUS_INVALID;
                break;
        }

        return $class;
    }

    public static $LEVEL_INFO = 0;
    public static $LEVEL_WARN = 1;
    public static $LEVEL_ERROR = 2;

    private function InstallVarProfiles(bool $reInstall = false)
    {
        if ($reInstall) {
            $this->SendDebug(__FUNCTION__, 'reInstall=' . $this->bool2str($reInstall), 0);
        }
    }
}
