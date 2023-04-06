<?php

namespace App;

/**
 * Config class
 * 
 * LICENSE: The MIT License (MIT) - see LICENSE.md 
 */
class Config {
    
    /** @var string $server_name The name of the server reported to clients */
    public static $server_name = "my.custom.server.com";    
    
    /** @var array $accounts A list of accounts allowed to login into the POP3 server. Each entry has the username/email as key and the password as value */
    public static $accounts = [
        'alex@testserver' => 'pass1',
        'john@testserver' => 'pass2',
    ];
    
    /** @var array $smtp The address and port on which the smtp server is listening on */
    public static $smtp = [
        'address' => '127.0.0.1',
        'port' => 9125,
    ];
    
    /** @var array $pop3 The address and port on which the pop3 server is listening on */
    public static $pop3 = [
        'address' => '127.0.0.1',
        'port' => 9110,
    ];
    
    public static $mailboxes_dir = 'mailboxes';
}
