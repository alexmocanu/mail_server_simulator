<?php
/**
  * LICENSE: The MIT License (MIT) - see LICENSE.md 
 */
namespace App;

use App\Config;
use App\Common;
use Amp;
use Amp\Socket;
use Amp\ByteStream;

class Smtp extends Common {

    public function run() {

        $this->server = Socket\listen(Config::$smtp['address'] . ':' . Config::$smtp['port']);
        $socketAddress = $this->server->getAddress();

        $this->info("Running server on " . $socketAddress->getAddress() . ':' . $socketAddress->getPort());

        while ($socket = $this->server->accept()) {

            $clientKey = null;

            Amp\async(function () use ($socket) {

                $address = $socket->getRemoteAddress();

                $clientKey = $address->toString();
                $this->clients[$clientKey] = [
                    'socket' => $socket,
                    'to' => [], //list of receivers for a given message created by the rcpt command
                    'from' => "", //the sender provided by the MAIL command
                    'message' => "", // here we save the message
                    'receiving' => false, //tells us if the server is in receiving or command mode for this client.
                ];
                
                //Unlike the pop3 server, the smtp server operates in two modes:
                //1. Command mode - just like the pop server - receives commands and sends responses
                //2. Receiving mode - in this mode the server builds the message to be sent. The server is put in this mode by the DATA command.

                //Clients expect some kind of response, telling them the server is listening 
                $this->writeToSocket($socket, "220 " . Config::$server_name . "\r\n");

                foreach (ByteStream\splitLines($socket) as $line) {
                    
                    $this->info("CLIENT: ".$line);
                    
                    // In message receiving mode for this client? We append any received lines until we receive the end of message signal (a single line containing only a dot).
                    if ($this->clients[$clientKey]['receiving']) {

                        //when the end of message line arrives we don't include it in the final message.
                        if (trim($line) !== ".") {
                            $this->clients[$clientKey]['message'] .= $line . "\r\n";
                        }
                    
                        //message has ended so:
                        // - we save a copy in each receiver's inbox
                        // - cleanup the user entry and disable receiving mode
                        // - notify the client that the message has been queued and we switch back to command mode
                        if (trim($line) == ".") {

                            if (!empty($this->clients[$clientKey]['to'])) {
                                foreach ($this->clients[$clientKey]['to'] as $receiver) {
                                    $this->saveMessage($receiver, $this->clients[$clientKey]['message']);
                                }
                            }

                            $this->clients[$clientKey]['to'] = [];
                            $this->clients[$clientKey]['from'] = "";
                            $this->clients[$clientKey]['message'] = "";
                            $this->clients[$clientKey]['receiving'] = false;

                            $this->writeToSocket($socket, "250 OK: queued\r\n");
                        }

                        continue;
                    }

                    //in command mode? We handle commands just like we do in the pop3 server
                    list($command, $params) = $this->parseLine($line);

                    if (method_exists($this, $command)) {

                        //Every command must have a response so we process the input and dump the output
                        $response = $this->$command($params, $clientKey);
                        $this->writeToSocket($socket, $response);

                        //if the command we received was "QUIT", after we send the response we close the connection
                        if ($command == "QUIT") {
                            $this->clients[$clientKey]['socket']->close();
                        }

                        if ($command == "DATA") {
                            $this->clients[$clientKey]['receiving'] = true;
                        }
                    } else {
                        //Can't handle the command? We tell the client so and dump a red message on the console.
                        $this->writeToSocket($socket, "-ERR Unrecognized command " . $command . "\r\n");
                        $this->error("Unrecognized command: " . $line);
                    }
                }
            });

            //When the connection closes we remove the client data from the array
            $socket->onClose(function () use ($clientKey) {
                $this->info("Connection closed");
                if (isset($this->clients[$clientKey])) {
                    unset($this->clients[$clientKey]);
                }
            });
        }
    }

    /**
     * Establishes the sender
     * @param array $params
     * @param string $clientKey
     * @return string
     */
    protected function MAIL($params, $clientKey) {
        if (strpos($params[0], "FROM:") === 0) {
            if (count($params) > 1) {
                $this->clients[$clientKey]['from'] = trim(str_replace(["FROM:", "<", ">"], "", $params[1]));
            } else {
                $this->clients[$clientKey]['from'] = trim(str_replace(["FROM:", "<", ">"], "", $params[0]));
            }
        }

        return "250 Ok\r\n";
    }
    
    /**
     * Adds receivers
     * 
     * @param array $params
     * @param string $clientKey
     * @return string
     */
    protected function RCPT($params, $clientKey) {
        if (strpos($params[0], "TO:") === 0) {
            if (count($params) > 1) {
                $this->clients[$clientKey]['to'][] = trim(str_replace(["TO:", "<", ">"], "", $params[1]));
            } else {
                $this->clients[$clientKey]['to'][] = trim(str_replace(["TO:", "<", ">"], "", $params[0]));
            }
        }
        return "250 Ok\r\n";
    }

    /**
     * 
     * @return string
     */
    protected function EHLO() {
        return "250 Ok\r\n";
    }

    protected function HELO() {
        return "250 \r\n";
    }

    protected function QUIT($params, $clientKey) {
        return "221 Bye\r\n";
    }

    /**
     * Puts the server in message building mode
     * 
     * @param string $params
     * @return string
     */
    protected function DATA($params) {
        return "354 End data with <CR><LF>.<CR><LF>\r\n";
    }

    /**
     * Should reset the SMTP connection. Does nothing here - just to make some email clients happy
     * @todo Implement reset functionality
     * @return string
     */
    protected function RSET() {
        return "250 Ok\r\n";
    }

}
