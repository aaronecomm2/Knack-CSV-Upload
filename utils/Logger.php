<?php

/**
 * Logger.php
 * Copyright(c) 2010-2014 Vitaly Demyanenko <vitaly_demyanenko@yahoo.com>
 * 
 * Licensed under GNU GPL
 */

class Logger
{

    const LEVEL_DEBUG = 0;
    const LEVEL_INFO = 1;
    const LEVEL_ERROR = 2;
    const LEVEL_WARNING = 3;

    private static $logPath = 'logs/debug.log';
    private static $debugMode = true;
    private static $processName = null;
    private static $sendEmail = false;
    private static $mailTo = null;
    private static $timeFormat = 'Y-m-d H:i:s.u T';
    private static $historyLength = 10;

    private static $logHistory;

    public static function init() {
        if(class_exists('Configurator')) {
            $config = Configurator::getInstance()->getConfig(__CLASS__);

            if(!file_exists(self::$logPath = $config->getKey('logPath', self::$logPath))) {
                file_put_contents(self::$logPath, "Log file created.\n", FILE_APPEND | LOCK_EX);
            }
            self::$logPath = realpath(self::$logPath);

            self::$debugMode = $config->getKey('debugMode', self::$debugMode);
            self::$processName = $config->getKey('processName', self::$processName);
            self::$sendEmail = $config->getKey('sendEmail', self::$sendEmail);
            self::$mailTo = $config->getKey('mailTo', self::$mailTo);
            self::$timeFormat = $config->getKey('timeFormat', self::$timeFormat);
            self::$historyLength = $config->getKey('historyLength', self::$historyLength);
        }

        if(defined(DEBUG_MODE)) {
            self::$debugMode = DEBUG_MODE;
        }

        self::$logHistory = array();
    }

    public static function debug($message) {
        if(self::$debugMode) {
            self::message(self::LEVEL_DEBUG, $message);
        }
    }

    public static function info($message) {
        self::message(self::LEVEL_INFO, $message);
    }

    public static function warning($message) {
        self::message(self::LEVEL_WARNING, $message);
    }

    public static function error($message) {
        self::message(self::LEVEL_ERROR, $message);
    }

    private static function message($level, $message, $sendEmail = true) {
        switch($level) {
            case self::LEVEL_INFO:
                $messageLevel = 'INFO';
                break;
            case self::LEVEL_ERROR:
                $messageLevel = 'ERROR';
                break;
            case self::LEVEL_WARNING:
                $messageLevel = 'WARNING';
                break;
            case self::LEVEL_DEBUG:
            default:
                $messageLevel = 'DEBUG';
        }

        $ipAddress = '';
        if(isset($_SERVER) && isset($_SERVER['REMOTE_ADDR'])) {
            $ipAddress = ' [' . $_SERVER['REMOTE_ADDR'] . ']';
        }

        $message = self::getTimeString()
            . (empty(self::$processName)? '': ' ' . self::$processName)
            . ' [' . session_id() . ']'
            . $ipAddress
            . ' [' . $messageLevel . '] ' . ((is_object($message) || is_array($message))? print_r($message, true): $message);

        self::appendLogMessage($message);

        if((self::LEVEL_ERROR == $level)
                && (!empty(self::$sendEmail)
                && self::$sendEmail)
                && $sendEmail) {
            if(!empty(self::$mailTo)) {
                $historyMessage = '';
                foreach(self::$logHistory as $message) {
                    $historyMessage .= $message . "\n";
                }

                if(!mail(self::$mailTo, (empty(self::$processName)? '': self::$processName . ': ') . 'Error Report', $historyMessage)) {
                    self::message(self::LEVEL_ERROR, 'Failed sending email report to admin.', false);
                }
            } else {
                self::message(self::LEVEL_WARNING, 'Cannot send email report to admin. No recipients configured.', false);
            }
        }
    }

    private static function appendLogMessage($message) {
        if(self::$historyLength <= count(self::$logHistory)) {
            array_shift(self::$logHistory);
        }

        self::$logHistory[] = $message;

        file_put_contents(self::$logPath, $message . "\n", FILE_APPEND | LOCK_EX);
    }

    private static function getTimeString() {
        $uTimestamp = microtime(true);
        $timestamp = floor($uTimestamp);
        $milliseconds = '' . round(($uTimestamp - $timestamp) * 1000000);
        $milliseconds = str_pad($milliseconds, 6, '0');
        return date(preg_replace('/(?<!\\\\)u/', $milliseconds, self::$timeFormat), $timestamp);
    }

}

$loggerOldErrorHandler = set_error_handler(function($errLevel, $errMessage, $errFile, $errLine, $errContext) {
    global $loggerOldErrorHandler;

    Logger::error("[$errFile:$errLine] ($errLevel) $errMessage");

    if(!is_null($loggerOldErrorHandler)) {
        return $loggerOldErrorHandler($errLevel, $errMessage, $errFile, $errLine, $errContext);
    }
}, E_ALL | E_STRICT);

set_exception_handler(function($ex) {
    Logger::error('Uncaught exception: ' . $ex->getMessage());
    Logger::info('Exception details: ' . print_r($ex, true));
});

