<?php

require __DIR__ . "/vendor/autoload.php";
include_once 'config.php';

use Amp\Loop;
use Amp\Socket\Socket;
use function Amp\asyncCall;

class SmtpServer {

    protected $configuration;
    protected $uri;
    protected $clients = [];    
    
    //When this flag is set to true then we append anything we receive to $newMail['message'];
    protected $receivingMessage = false;

    public function __construct($configuration) {
        $this->configuration = $configuration;
        $this->uri = $configuration['smtp'];
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

            //Array of clients.
            $remoteAddr = $socket->getRemoteAddress();
            $this->clients[(string) $remoteAddr] = [
                'socket' => $socket,
                'to' => [],
                'from' => "",
                'message' => "",
            ];

            //The server should send this message on first connection. Usually a 220 is sufficient, everything else is for convenience
            yield $socket->write("220 " . $this->configuration['server_name'] . "\r\n");

            //Now we handle requests and responses (until one party quits, hangs or dies).
            while (null !== $chunk = yield $socket->read()) {

                //the client will always send a command followed by some parameters
                //The first word will be the command name so we separate the command (except the part when the client sends the actual message).
                $tmp = explode(' ', $chunk);
                $command = trim($tmp[0]);
                unset($tmp[0]);
                $params = array_values($tmp);

                //Here we either handle and unsupported command or store the message.
                //Based on the receivingMessage flag we decide if we are receiving a unsupported command or part of the message.

                if ($this->receivingMessage) {
                    
                    echo "MESSAGE MODE\n";

                    //RECEIVIG MESSAGE MODE
                    //The message can arrive in multiple chunks so we just append them as they come until the end of message
                    //character sequence is received.

                    $this->clients[(string) $remoteAddr]['message'] .= $chunk;

                    //The end of message sequence has arrived - send the current message, clear data, exit message mode and notify the client                    
                    if (strlen($this->clients[(string) $remoteAddr]['message']) >= 5 && substr($chunk, -5) == "\r\n.\r\n") {
                        
                        if (!empty($this->clients[(string) $remoteAddr]['to'])) {
                            foreach ($this->clients[(string) $remoteAddr]['to'] as $receiver) {
                                $folder = $this->getUserMailbox($receiver);
                                $uniqueId = $this->guidv4();
                                file_put_contents($folder . '/' . $uniqueId . '.txt', $this->clients[(string) $remoteAddr]['message']);
                            }
                        }

                        $this->clients[(string) $remoteAddr]['to'] = [];
                        $this->clients[(string) $remoteAddr]['from'] = "";
                        $this->clients[(string) $remoteAddr]['message'] = "";

                        $this->receivingMessage = false;

                        yield $socket->write("250 OK: queued\r\n");
                    }
                } else {
                    //COMMAND MODE
                    //Each command should have it's own method in this class that accepts two parameters:
                    //$params - an array of parameters (the string sent by the client without the first word)
                    //$remoteAddr - the remote address of the client. It's string representation is used as key for the clients array
                    //Each command ends in \r\n and each response must be finished with the same sequence: \r\n
                    
                    echo "COMMAND MODE\n";
                    echo "CLIENT: ".$chunk;

                    if (method_exists($this, $command)) {
                        $response = $this->$command($params, $remoteAddr);
                        echo "SERVER: ".$response;
                        yield $socket->write($response);

                        //if the command we received was "QUIT", after we send the response we close the connection
                        if ($command == "QUIT") {
                            $this->clients[(string) $remoteAddr]['socket']->close();
                        }
                        
                        if($command == "DATA") {
                            $this->receivingMessage = true;
                        }
                        
                    } else {
                        //Unsupported command, we just say so.
                        $response = "500 Unknown Command: " . $command . "\r\n";
                        yield $socket->write($response);
                        print ("\033[31m UNRECOGNIZED COMMAND " . trim($command) . "\n\033[39m");
                    }
                }
            }

            unset($this->clients[(string) $remoteAddr]);
            print "Client disconnected: {$remoteAddr}" . PHP_EOL;
        });
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

    /*     * *************************** SMTP COMMANDS ============================================================ */

    protected function MAIL($params, $remoteAddr) {        
        if (strpos($params[0], "FROM:") === 0) {
            if(count($params) > 1) {
                $this->clients[(string) $remoteAddr]['from'] = trim(str_replace(["FROM:", "<", ">"], "", $params[1]));
            } else {
                $this->clients[(string) $remoteAddr]['from'] = trim(str_replace(["FROM:", "<", ">"], "", $params[0]));
            }
        }

        return "250 Ok\r\n";
    }

    protected function RCPT($params, $remoteAddr) {    
        if (strpos($params[0], "TO:") === 0) {
            if(count($params) > 1) {
                $this->clients[(string) $remoteAddr]['to'][] = trim(str_replace(["TO:", "<", ">"], "", $params[1]));
            } else {
                $this->clients[(string) $remoteAddr]['to'][] = trim(str_replace(["TO:", "<", ">"], "", $params[0]));
            }
            
        }
        return "250 Ok\r\n";
    }

    protected function EHLO() {        
        /*
        $response = [
            "250-".$this->configuration['server_name'],
            "250-SIZE 1000000",
            //"250 AUTH LOGIN PLAIN CRAM-MD5",
            "250 AUTH LOGIN PLAIN",
        ];        
        return implode("\r\n", $response)."\r\n";
         * 
         */
        return "250 Ok\r\n";
    }

    protected function HELO() {
        return "250 Ok\r\n";
    }

    protected function QUIT($params, $remoteAddr) {
        return "221 Bye\r\n";
    }

    protected function DATA($params) {        
        return "354 End data with <CR><LF>.<CR><LF>\r\n";
    }

}

Loop::run(function () use ($configuration) {
    $server = new SmtpServer($configuration);
    $server->listen();
});
?>
