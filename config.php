<?php

$configuration = [
    'server_name' => "my.custom.server.com",
    'accounts' => [
        'alex@testserver' => 'pass1',
        'john@testserver' => 'pass2',
    ],
    'smtp' => 'tcp://127.0.0.1:9125',  //Port 25 is not available to non-root scripts on linux
    'pop3' => 'tcp://127.0.0.1:9110',  //Port 110 is not available to non-root scripts on linux
];