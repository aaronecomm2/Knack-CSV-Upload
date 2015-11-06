<?php

class Configuration
{

    private $m_ini;

    public function __construct(array $ini) {
        $this->m_ini = $ini;
    }

    public function getKey($key, $defaultValue = null) {
        return isset($this->m_ini[$key])? $this->m_ini[$key]: $defaultValue;
    }

    public function getMandatoryKey($key) {
        if(!isset($this->m_ini[$key])) {
            throw new Exception(sprintf('Mandatory configuration key "%s" is missing.', $key));
        }

        return $this->getKey($key);
    }

}

