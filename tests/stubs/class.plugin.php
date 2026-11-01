<?php
// Minimal stand-ins, signatures from osTicket 1.18.4 include/class.plugin.php.
class Plugin
{
    var $config;
    function getConfig() { return $this->config; }
}
class PluginConfig
{
    var $instance;
    var $values = array();
    function get($key, $default = null) { return $this->values[$key] ?? $default; }
    function getInstance() { return $this->instance; }
}
