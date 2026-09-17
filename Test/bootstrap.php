<?php

require_once __DIR__ . '/../vendor/autoload.php';

// Register the plugin's PSR-4 namespace when running from a Magento installation
// that has the plugin as a path repository but not yet installed in vendor/
$pluginBase = dirname(__DIR__);
spl_autoload_register(function ($class) use ($pluginBase) {
    $prefix = 'DigitalFemsa\\Payments\\';
    if (str_starts_with($class, $prefix)) {
        $relative = substr($class, strlen($prefix));
        $file = $pluginBase . '/' . str_replace('\\', '/', $relative) . '.php';
        if (file_exists($file)) {
            require_once $file;
            return true;
        }
    }
    return false;
});

// Magento auto-generated factory classes that don't exist in vendor
if (!class_exists(\Magento\Framework\Controller\Result\RawFactory::class)) {
    require_once __DIR__ . '/Stub/RawFactory.php';
}
if (!class_exists(\Magento\Sales\Model\OrderFactory::class)) {
    require_once __DIR__ . '/Stub/OrderFactory.php';
}
