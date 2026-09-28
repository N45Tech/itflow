<?php

require_once dirname(__DIR__).'/libs/vendor/autoload.php';

use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Connection\Streams\FakeStream;
use DirectoryTree\ImapEngine\Exceptions\ImapConnectionFailedException;

$stream = new class extends FakeStream
{
    public function setSocketSetCrypto(bool $enabled, ?int $method): bool|int
    {
        return false;
    }
};

$stream->feed([
    '* OK IMAP server ready',
    'TAG1 OK Begin TLS negotiation',
]);

$connection = new ImapConnection($stream);

try {
    $connection->connect('mail.example.test', 143, ['encryption' => 'starttls']);
} catch (ImapConnectionFailedException $exception) {
    echo "Failed STARTTLS handshakes are rejected.\n";
    exit(0);
}

fwrite(STDERR, "A failed STARTTLS handshake did not abort the connection.\n");
exit(1);
