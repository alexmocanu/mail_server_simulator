<?php

require __DIR__ . "/vendor/autoload.php";
include_once 'config.php';

use Amp\Loop;
use Amp\Socket\Socket;
use function Amp\asyncCall;

class Pop3Server {

    protected $configuration;
    protected $uri;
    protected $clients = [];

    public function __construct($configuration) {
        $this->configuration = $configuration;
        $this->uri = $configuration['pop3'];
    }

    public function listen() {
        asyncCall(function () {
            $server = Amp\Socket\Server::listen($this->uri);

            print "Listening on " . $server->getAddress() . " ..." . PHP_EOL;

            while ($socket = yield $server->accept()) {
                $this->handleClient($socket);
            }
        });
    }

    protected function handleClient(Socket $socket) {

        asyncCall(function () use ($socket) {

            $remoteAddr = $socket->getRemoteAddress();            
            //clients
            $this->clients[(string) $remoteAddr] = [
                'socket' => $socket,
                'username' => null,
                'password' => null,
                'authenticated' => false,
                'messages' => [],
            ];

            //When the client connects the server must send a OK response, otherwise the client will just hang
            yield $socket->write("+OK POP3 server ready\r\n");

            //Now we handle requests and responses (until one party quits, hangs or dies).
            while (null !== $chunk = yield $socket->read()) {
                
                //the client will always send a command followed by some parameters
                //The first word will always be the command name so we separate the command                
                $tmp = explode(' ', $chunk);
                $command = trim($tmp[0]);
                unset($tmp[0]);
                $params = array_values($tmp);

                //Each command should have it's own method in this class that accepts two parameters:
                //$params - an array of parameters (the string sent by the client without the first word)
                //$remoteAddr - the remote address of the client. It's string representation is used as key for the clients array
                //Each command ends in \r\n and each response must be finished with the same sequence: \r\n
                
                if (method_exists($this, $command)) {
                    //Every command must have a response so we process the input and dump the output
                    $response = $this->$command($params, $remoteAddr);                    
                    yield $socket->write($response);
                    
                    //if the command we received was "QUIT", after we send the response we close the connection
                    if ($command == "QUIT") {
                        $this->clients[(string) $remoteAddr]['socket']->close();                        
                    }
                } else {
                    //Can't handle the command? We tell the client so and dump a red message on the console.
                    $response = "-ERR Unrecognized command " . $command . "\r\n";
                    yield $socket->write($response);
                    print ("\033[31m UNRECOGNIZED COMMAND " . trim($chunk) . "\n\033[39m");
                }
            }

            unset($this->clients[(string) $remoteAddr]);       
        });
    }

    /**
     * Check the username
     * 
     * @param array $params
     * @param \Amp\Socket\SocketAddress $remoteAddr
     * @return string
     */
    protected function USER($params, $remoteAddr) {

        $username = trim($params[0]);
        if (!isset($this->configuration['accounts'][$username])) {
            return "-ERR never heard of mailbox name\r\n";
        }
        $this->clients[(string) $remoteAddr]['username'] = $username;
        return "+OK name is a valid mailbox\r\n";
    }

    /**
     * Check the password for the username sent above
     * 
     * @param array $params
     * @param \Amp\Socket\SocketAddress $remoteAddr
     * @return string
     */
    protected function PASS($params, $remoteAddr) {
        $password = str_replace(array("\n", "\r"), '', $params[0]);
        $username = $this->clients[(string) $remoteAddr]['username'];

        if (!isset($this->configuration['accounts'][$username])) {
            return "-ERR never heard of mailbox name\r\n";
        }

        if ($this->configuration['accounts'][$username] != $password) {
            return "-ERR invalid password\r\n";
        }

        $this->clients[(string) $remoteAddr]['password'] = $password;
        $this->clients[(string) $remoteAddr]['authenticated'] = true;
        $this->clients[(string) $remoteAddr]['messages'] = [];

        $folder = $this->getUserMailbox($username);

        return "+OK maildrop locked and ready\r\n";
    }
    
    /**
     * Returns details about the mailbox: number of messages and total size
     * Internally we also build the messages structure for the current user. 
     * During a session each message receives a fixed number (starting with 1) - we use the array key+1 for this purpose
     * This number is used by the other commands to query a specific message    
     * Each message also has a unique ID. For our purposes we use the file name.
     * 
     * @param string $params
     * @param \Amp\Socket\SocketAddress $remoteAddr
     * @return string
     */
    protected function STAT($params, $remoteAddr) {

        if (!$this->clients[(string) $remoteAddr]['authenticated']) {
            return "-ERR not logged in\r\n";
        }

        $username = $this->clients[(string) $remoteAddr]['username'];
        $folder = $this->getUserMailbox($username);
        $files = glob("$folder/*.txt");

        if (!empty($files)) {
            foreach ($files as $file) {
                $exists = false;
                $uniqueId = str_replace(".txt", "", basename($file));
                if (!empty($this->clients[(string) $remoteAddr]['messages'])) {
                    foreach ($this->clients[(string) $remoteAddr]['messages'] as $message) {
                        if (!is_null($message) && $message['id'] == $uniqueId) {
                            $exists = true;
                        }
                    }
                }

                if (!$exists) {
                    $this->clients[(string) $remoteAddr]['messages'][] = [
                        'id' => $uniqueId,
                        'file' => $file
                    ];
                }
            }
        }

        $numFiles = 0;
        $size = 0;
        foreach ($this->clients[(string) $remoteAddr]['messages'] as $message) {
            if (!is_null($message)) {
                $numFiles = $numFiles + 1;
                $size = $size + filesize($message['file']);
            }
        }

        return "+OK " . $numFiles . " " . $size . "\r\n";
    }

    /**
     * Nothing much, we just tell the client bye! bye!
     * 
     * @param type $params
     * @param \Amp\Socket\SocketAddress $remoteAddr
     * @return string
     */
    protected function QUIT($params, $remoteAddr) {
        return "+OK POP3 server signing off (maildrop empty)\r\n";
    }

    /**
     * Returns a list of messages. Each message has a number and a size in bytes.
     * 
     * @param string $params
     * @param \Amp\Socket\SocketAddress $remoteAddr
     * @return string
     */
    protected function LIST($params, $remoteAddr) {

        if (!$this->clients[(string) $remoteAddr]['authenticated']) {
            return "-ERR not logged in\r\n";
        }

        $response = [
            "+OK Mailbox scan listing follows",
        ];

        foreach ($this->clients[(string) $remoteAddr]['messages'] as $key => $message) {
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
     * @param \Amp\Socket\SocketAddress $remoteAddr
     * @return string
     */    
    protected function TOP($params, $remoteAddr) {
        return $this->RETR($params, $remoteAddr);
    }

    /**
     * Retrieves a message by it's number
     * 
     * @param string $params
     * @param \Amp\Socket\SocketAddress $remoteAddr
     * @return string
     */
    protected function RETR($params, $remoteAddr) {

        if (!$this->clients[(string) $remoteAddr]['authenticated']) {
            return "-ERR not logged in\r\n";
        }

        $messageNumber = $params[0];
        if (isset($this->clients[(string) $remoteAddr]['messages'][$messageNumber - 1])) {
            $message = $this->clients[(string) $remoteAddr]['messages'][$messageNumber - 1];
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
     * @param \Amp\Socket\SocketAddress $remoteAddr
     * @return string
     */
    protected function UIDL($params, $remoteAddr) {

        if (!$this->clients[(string) $remoteAddr]['authenticated']) {
            return "-ERR not logged in\r\n";
        }

        $messageNumber = false;
        if (!empty($params)) {
            $messageNumber = (int) $params[0];
        }

        $messages = $this->clients[(string) $remoteAddr]['messages'];

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
     * @param \Amp\Socket\SocketAddress $remoteAddr
     * @return string
     */
    protected function DELE($params, $remoteAddr) {

        if (!$this->clients[(string) $remoteAddr]['authenticated']) {
            return "-ERR not logged in\r\n";
        }

        $messageNumber = (int) $params[0];
        $messages = $this->clients[(string) $remoteAddr]['messages'];
        if (!isset($messages[$messageNumber - 1])) {
            return "-ERR no such message\r\n";
        }
        if (is_null($messages[$messageNumber - 1])) {
            return "-ERR message " . $messageNumber . " already deleted\r\n";
        }

        $username = $this->clients[(string) $remoteAddr]['username'];
        $folder = $this->getUserMailbox($username) . '/deleted';
        if (!is_dir($folder)) {
            $oldmask = umask(0);
            mkdir($folder, 0777);
            umask($oldmask);
        }

        $filename = basename($messages[$messageNumber - 1]['file']);
        rename($messages[$messageNumber - 1]['file'], $folder . '/' . $filename);
        $this->clients[(string) $remoteAddr]['messages'][$messageNumber - 1] = null;
        return "+OK message " . $messageNumber . " deleted\r\n";
    }
    
    /**
     * Returns the mailbox folder for a particular username (and creates it if it's missing)
     * @param type $username
     * @return string
     */
    protected function getUserMailbox($username) {
        $folder = 'mailboxes/' . str_replace('@', '_', $username);
        if (!is_dir($folder)) {
            $oldmask = umask(0);
            mkdir($folder, 0777);
            umask($oldmask);
        }
        return $folder;
    }

}

Loop::run(function () use ($configuration) {
    $server = new Pop3Server($configuration);
    $server->listen();
});
?>
