# Quick and dirty mail server emulation

This is just a simple pop3/smtp server emulator written in PHP using the amphp/socket package I wrote for fun in a few hours so I can learn a bit about sockets. 

It's nothing fancy, just the minimum required functionality to allow email clients to receive or send emails between them. All messages are stored on disk so nothing is being sent. There is no proper data validation or security to speak of, just some basic username and password checks on the POP3 side to make email clients happy. 
Each message (headers, content, attachments, etc.) is stored in a text message for ease of examination. 

To get this thing running follow these steps:
1. clone the repository and run a composer install 
2. make a new directory named "mailboxes" in the same place as the php files. 
3. change the Config.php file to suit your needs.
4. Run the following commands in separate consoles:
    - php server.php -t smtp
    - php server.php -t pop3

If you really need a decent email testing suite I recommend you use Mailhog or GreenMail, they're much more usefull than this pile of...code.
