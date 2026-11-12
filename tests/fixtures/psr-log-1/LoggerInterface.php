<?php

// psr/log 1.x LoggerInterface, untyped, as shipped by many Dolibarr modules and
// by the Dolibarr core itself (htdocs/includes/Psr/Log). Fixture for
// tests/runtime_isolation_test.php: loading it before a psr/log 3 class is what
// broke emMCP in production in November 2026.

namespace Psr\Log;

interface LoggerInterface
{
    public function emergency($message, array $context = array());
    public function alert($message, array $context = array());
    public function critical($message, array $context = array());
    public function error($message, array $context = array());
    public function warning($message, array $context = array());
    public function notice($message, array $context = array());
    public function info($message, array $context = array());
    public function debug($message, array $context = array());
    public function log($level, $message, array $context = array());
}
