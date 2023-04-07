<?php
/**
 * Class Common
 * 
 * Common stuff. The other classes extend this one.
 * 
 * LICENSE: The Unlicense - see LICENSE.md 
 */
namespace App;

use App\Config;
use League\CLImate\CLImate;

class Common {

    protected $server;
    
    /**
     * 
     * @var array $clients - a list of clients connected to the server. Each entry holds the socket, username, auth. status and messages for a particular connected client
     */
    protected $clients;

    public function run() {
        
    }
    /**
     * Write some data to a socket
     * 
     * @param \Amp\Socket\ResourceSocket $socket
     * @param string $response
     */
    protected function writeToSocket($socket, $response) {
        
         $socket->write($response);
         $this->info("SERVER: ".$response);
    }    
    
    /**
     * Saves a message into a mailbox.
     * 
     * @param string $receiver
     * @param string $contents
     */
    protected function saveMessage($receiver, $contents) {
        $folder = $this->getUserMailbox($receiver);
        $uniqueId = $this->guidv4();
        file_put_contents($folder . '/' . $uniqueId . '.txt', $contents);        
    }
    
    /**
     * Parses the command and parameters from a line. The first word is the command, everything else are parameters
     * 
     * @param string $line
     * @return array
     */
    protected function parseLine($line) {

        $tmp = explode(' ', $line);
        $command = trim($tmp[0]);
        unset($tmp[0]);
        $params = array_values($tmp);
        
        return [$command, $params];
    }

    /**
     * Display a white info message at the console
     * 
     * @param string $message
     */
    protected function info($message) {
        $climate = new CLImate;
        $climate->white()->out($message);
    }

    /**
     * Show a red error message at the console
     * 
     * @param string $message
     */
    protected function error($message) {
        $climate = new CLImate;
        $climate->lightRed()->out($message);
    }

    /**
     * Get a user's mailbox folder on the server and creates it if it doesn't exist
     * 
     * @param string $username
     * @return string
     */
    protected function getUserMailbox($username) {
        
        $folder = Config::$mailboxes_dir.'/' . str_replace('@', '_', $username);
        $deleted = $folder.'/deleted';

        if (!is_dir($folder)) {
            $oldmask = umask(0);
            mkdir($folder, 0777, true);
            umask($oldmask);
        }
        
        if(!is_dir($deleted)) {
            $oldmask = umask(0);
            mkdir($deleted, 0777, true);
            umask($oldmask);            
        }

        return $folder;
    }
    
    /**
     * Generate a v4 guid. I know, it's weak but for this purpose it's more than enough.
     *
     * @param string $data
     * @return string
     */
    function guidv4($data = null) {
        // Generate 16 bytes (128 bits) of random data or use the data passed into the function.
        $data = $data ?? random_bytes(16);
        assert(strlen($data) == 16);

        // Set version to 0100
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        // Set bits 6-7 to 10
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        // Output the 36 character UUID.
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }    

}
