<?php
/**
  * LICENSE: The Unlicense - see LICENSE.md 
 */
namespace App;

use App\Config;
use App\Common;
use Amp;
use Amp\Socket;
use Amp\ByteStream;

class Pop3 extends Common {
    
    public function run() {
    
        $this->server = Socket\listen(Config::$pop3['address'].':'.Config::$pop3['port']);

        $socketAddress = $this->server->getAddress();
        
        $this->info("Running POP3 server on " . $socketAddress->getAddress() . ':' . $socketAddress->getPort());

        while ($socket = $this->server->accept()) {
            
            $clientKey = null;

            Amp\async(function () use ($socket) {
                
                $address = $socket->getRemoteAddress();
                
                $clientKey = $address->toString();
                $this->clients[$clientKey] = [
                    'socket' => $socket,
                    'username' => '',
                    'authenticated' => false,
                    'messages' => [],
                ];
                
                //When the client connects the server must send a OK response, otherwise the client will just hang
                $this->writeToSocket($socket, "+OK POP3 server ready\r\n");
                
                foreach (ByteStream\splitLines($socket) as $line) {
                    
                    $this->info("CLIENT: ".$line);
                    
                    //the client will always send a command followed by some parameters
                    list($command, $params) = $this->parseLine($line);
                    
                    //Each command should have it's own method in this class that accepts two parameters:
                    //$params - an array of parameters (the string sent by the client without the first word)
                    //$clientKey - the remote address of the client. It's string representation is used as key for the clients array
                    //Each command ends in \r\n and each response must be finished with the same sequence: \r\n                    
                    
                    if (method_exists($this, $command)) {

                        //Every command must have a response so we process the input and dump the output
                        $response = $this->$command($params, $clientKey);
                        $this->writeToSocket($socket, $response);

                        //if the command we received was "QUIT", after we send the response we close the connection
                        if ($command == "QUIT") {
                            $this->clients[$clientKey]['socket']->close();                        
                        }
                        
                    } else {
                        //Can't handle the command? We tell the client so and dump a red message on the console.
                        $this->writeToSocket($socket, "-ERR Unrecognized command " . $command . "\r\n");
                        $this->error("Unrecognized command: ".$line);
                    }                    
                }                
            });

            //When the connection closes we remove the client data from the array
            $socket->onClose(function () use ($clientKey) {
                $this->info("Connection closed");
                if(isset($this->clients[$clientKey])) {
                    unset($this->clients[$clientKey]);
                }                
            });            
            
        }
    }
    
    /*
     * Checks if the username exists
     * 
     * @param array $params
     * @param string $clientKey
     * @return string
     */
    protected function USER($params, $clientKey) {
        
        $username = trim($params[0]);
        
        if(!array_key_exists($username, Config::$accounts)) {
            return "-ERR never heard of mailbox name\r\n";
        }
        
        $this->clients[$clientKey]['username'] = $username;
        return "+OK name is a valid mailbox\r\n";
    }
    
    /**
     * Check the password for the username sent above
     * 
     * @param array $params
     * @param string $clientKey
     * @return string
     */
    protected function PASS($params, $clientKey) {

        $password = str_replace(array("\n", "\r"), '', $params[0]);
        $username = $this->clients[$clientKey]['username'];
        
        if(!array_key_exists($username, Config::$accounts)) {
            return "-ERR never heard of mailbox name\r\n";
        }

        if (Config::$accounts[$username] != $password) {
            return "-ERR invalid password\r\n";
        }

        $this->clients[$clientKey]['password'] = $password;
        $this->clients[$clientKey]['authenticated'] = true;
        $this->clients[$clientKey]['messages'] = [];

        $folder = $this->getUserMailbox($username);

        return "+OK maildrop locked and ready\r\n";
    }    
    
    /**
     * Returns details about the mailbox: number of messages and total size
     * 
     * Internally we also build the messages structure for the current user. 
     * Each message in the mailbox will receive a fixed number starting with 1 - we use the array key+1 for this purpose. 
     * The message number is used by the other commands to query a specific message.
     * Each message also has a unique ID. For our purposes we use the file name.
     * 
     * Note about message numbers: The message number is fixed during a user's session and cannot change. When we later delete a message 
     * we mark it's entry in the messages array as null. Any new message found receives a new message number (here we just dump it at the end of the array)
     * 
     * @param type $params
     * @param type $clientKey
     * @return string
     */
    protected function STAT($params, $clientKey) {
        
        if (!$this->clients[$clientKey]['authenticated']) {
            return "-ERR not logged in\r\n";
        }
        
        $username = $this->clients[$clientKey]['username'];
        $folder = $this->getUserMailbox($username);
        $files = glob("$folder/*.txt");
        
        if (!empty($files)) {

            foreach ($files as $file) {
                
                $exists = false;
                $uniqueId = str_replace(".txt", "", basename($file));
                
                foreach ($this->clients[$clientKey]['messages'] as $message) {
                    if (!is_null($message) && $message['id'] == $uniqueId) {
                        $exists = true;
                    }
                }

                if (!$exists) {
                    $this->clients[$clientKey]['messages'][] = ['id' => $uniqueId, 'file' => $file];
                }                
            }
        }
        
        $numFiles = 0;
        $size = 0;

        foreach ($this->clients[$clientKey]['messages'] as $message) {
            if (!is_null($message)) {
                $numFiles = $numFiles + 1;
                $size = $size + filesize($message['file']);
            }
        }

        return "+OK " . $numFiles . " " . $size . "\r\n";
    }
    
    /**
     * Nothing much, we just tell the client bye! bye!
     * The actual disconnection is handled in the main loop
     * 
     * @param string $params
     * @param string $clientKey
     * @return string
     */    
    protected function QUIT($params, $clientKey) {
        return "+OK POP3 server signing off (maildrop empty)\r\n";
    }
    
    /**
     * Returns a list of messages. Each message has a number and a size in bytes.
     * 
     * @param string $params
     * @param string $clientKey
     * @return string
     */
    protected function LIST($params, $clientKey) {

        if (!$this->clients[$clientKey]['authenticated']) {
            return "-ERR not logged in\r\n";
        }

        $response = [
            "+OK Mailbox scan listing follows",
        ];

        foreach ($this->clients[$clientKey]['messages'] as $key => $message) {
            if (!is_null($message)) {
                $response[] = ($key + 1) . " " . filesize($message['file']);
            }
        }

        $response[] = ".";
        return implode("\r\n", $response) . "\r\n";
    }
    
    /**
     * Retrieve partial message (usually headers + a set number of lines).     
     * 
     * @todo Implement proper TOP behavior. For now just redirect to RETR.
     * 
     * @param string $params
     * @param string $clientKey
     * @return string
     */    
    protected function TOP($params, $clientKey) {
        return $this->RETR($params, $clientKey);
    }

    /**
     * Retrieves a message by it's number
     * 
     * @param string $params
     * @param string $clientKey
     * @return string
     */
    protected function RETR($params, $clientKey) {

        if (!$this->clients[$clientKey]['authenticated']) {
            return "-ERR not logged in\r\n";
        }

        $messageNumber = $params[0];

        if (isset($this->clients[$clientKey]['messages'][$messageNumber - 1])) {
            $message = $this->clients[$clientKey]['messages'][$messageNumber - 1];
            $response = [
                "+OK " . filesize($message['file']) . " octets",
                file_get_contents($message['file']),
                '.',
            ];
            return implode("\r\n", $response) . "\r\n";
        }

        return "-ERR no such message\r\n";
    }    
    
    /**
     * Returns the unique ids for each message
     * 
     * @param string $params
     * @param string $clientKey
     * @return string
     */
    protected function UIDL($params, $clientKey) {

        if (!$this->clients[$clientKey]['authenticated']) {
            return "-ERR not logged in\r\n";
        }

        $messageNumber = false;
        if (!empty($params)) {
            $messageNumber = (int) $params[0];
        }

        $messages = $this->clients[$clientKey]['messages'];

        if ($messageNumber !== false) {
            if (!isset($messages[$messageNumber - 1]) || is_null($messages[$messageNumber - 1])) {
                return "-ERR no such message\r\n";
            }
            $messages = [$messages[$messageNumber - 1]];
        }

        $response = [
            "+OK",
        ];

        foreach ($messages as $key => $message) {
            if (!is_null($message)) {
                $response[] = ($key + 1) . " " . $message['id'];
            }
        }

        $response[] = ".";
        return implode("\r\n", $response) . "\r\n";
    }
    
    /**
     * Deletes a message by number
     * 
     * @param string $params
     * @param string $clientKey
     * @return string
     */
    protected function DELE($params, $clientKey) {

        if (!$this->clients[$clientKey]['authenticated']) {
            return "-ERR not logged in\r\n";
        }

        $messageNumber = (int) $params[0];
        $messages = $this->clients[$clientKey]['messages'];
        if (!isset($messages[$messageNumber - 1])) {
            return "-ERR no such message\r\n";
        }
        if (is_null($messages[$messageNumber - 1])) {
            return "-ERR message " . $messageNumber . " already deleted\r\n";
        }

        $username = $this->clients[$clientKey]['username'];
        $folder = $this->getUserMailbox($username) . '/deleted';

        $filename = basename($messages[$messageNumber - 1]['file']);
        rename($messages[$messageNumber - 1]['file'], $folder . '/' . $filename);
        $this->clients[$clientKey]['messages'][$messageNumber - 1] = null;
        return "+OK message " . $messageNumber . " deleted\r\n";
    }      

}
