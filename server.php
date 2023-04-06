<?php
/**
 * boot script
 * 
 * LICENSE: The MIT License (MIT) - see LICENSE.md 
 */

require __DIR__ . "/vendor/autoload.php";

use League\CLImate;

$climate = new CLImate\CLImate();

$climate->arguments->add([
    'type' => [
        'prefix'      => 't',
        'longPrefix'  => 'type',
        'description' => 'Server Type (pop3 or smtp)',
        'required'    => true,
    ],
    'help' => [
        'longPrefix'  => 'help',
        'description' => 'Prints a usage statement',
        'noValue'     => true,
    ],    
]);

if ($climate->arguments->defined('help')) {
    $climate->usage();
    exit;
}

try {
    
    $climate->arguments->parse();
    
    $serverType = $climate->arguments->get('type');
    $className = 'App\\'.ucfirst(strtolower($serverType));
    
    if(!class_exists($className)) {
        throw new \Exception("Invalid Server Type: $className");
    }
    
    $server = new $className;
    $server->run();    
    
} catch (\Exception $ex) {
    $climate->lightRed()->out($ex->getMessage());
    $climate->usage();
}
