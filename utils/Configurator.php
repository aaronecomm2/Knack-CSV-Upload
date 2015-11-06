<?php

final class Configurator
{

    const DEFAULT_PATH = 'config%sConfigurator.ini'; // "%s" to be replaced with DIRECTORY_SEPARATOR

    private static $c_instance = null;

    public static function getInstance() {
        if(is_null(self::$c_instance)) {
            self::$c_instance = new Configurator();
        }

        return self::$c_instance;
    }

    private $m_configurations = null;

    private function __construct() {
        $defaultPath = sprintf(self::DEFAULT_PATH, DIRECTORY_SEPARATOR);
        if(defined('CONFIGURATOR_PATH')
                && file_exists(CONFIGURATOR_PATH)) {
            $this->load(CONFIGURATOR_PATH);
        } else if(file_exists($defaultPath)) {
            $this->load($defaultPath);
        } else {
            throw new Exception('Cannot find configuration file.');
        }
    }

    public function getConfig($section) {
        if(!$this->m_configurations) {
            throw new Exception('Configuration was not loaded.');
        }

        if(!isset($this->m_configurations[$section])) {
            throw new Exception(sprintf('Configuration section "%s" is missing.', $section));
        }

        return $this->m_configurations[$section];
    }

    private function load($path) {
        $ini = parse_ini_file($path, true);
        if(!$ini) {
            throw new Exception(sprintf('Configuration file "%s" is corrupted.', $path));
        }

        $this->m_configurations = array();
        foreach($ini as $section => $pairs) {
            $this->m_configurations[$section] = new Configuration($pairs);
        }
    }

}

