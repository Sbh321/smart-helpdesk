<?php

declare(strict_types=1);

namespace App\Modules\Mail\Support;

use App\Modules\Mail\Contracts\InboundMailbox;
use LogicException;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Message;

/**
 * The `inbound@` mailbox of the bundled mail server over IMAP (webklex/php-imap,
 * docs/01-research/mail-and-media-options.md). Reads the configured folders (`INBOX` and Stalwart's
 * `Junk Mail`, where its spam filter files mail it doubts) and moves each handled message to
 * `Processed` or `Failed`, which it creates on first use. Nothing is deleted.
 */
final class ImapInboundMailbox implements InboundMailbox
{
    private ?Client $client = null;

    /** @var array<string, Message> */
    private array $messages = [];

    /**
     * @param  array{host: string, port: int, encryption: string, validate_cert: bool, username: string, password: string|null, folders: list<string>, processed_folder: string, failed_folder: string}  $config
     */
    public function __construct(private readonly array $config) {}

    public function fetch(int $limit): iterable
    {
        $client = $this->client();

        foreach ($this->config['folders'] as $path) {
            $folder = $client->getFolderByPath($path);
            if (! $folder instanceof Folder) {
                continue;
            }

            foreach ($folder->query()->all()->leaveUnread()->setFetchOrder('asc')->limit($limit)->get() as $message) {
                if ($limit-- <= 0) {
                    return;
                }

                $key = $path.'/'.$message->getUid();
                $this->messages[$key] = $message;
                yield new FetchedMessage($path, (string) $message->getUid(), self::rawMessage((string) $message->getHeader()?->raw, $message->getRawBody()));
            }
        }
    }

    /**
     * The full RFC 5322 message. Stalwart answers the body fetch with the whole message (headers
     * included), other servers with the body only; the header block is prepended only when missing.
     */
    public static function rawMessage(string $header, string $body): string
    {
        $header = rtrim($header, "\r\n");
        if ($header === '' || str_starts_with(ltrim($body), substr($header, 0, min(200, strlen($header))))) {
            return $body;
        }

        return $header."\r\n\r\n".$body;
    }

    public function markProcessed(FetchedMessage $message): void
    {
        $this->moveTo($message, $this->config['processed_folder']);
    }

    public function markFailed(FetchedMessage $message): void
    {
        $this->moveTo($message, $this->config['failed_folder']);
    }

    public function close(): void
    {
        $this->client?->disconnect();
        $this->client = null;
        $this->messages = [];
    }

    private function moveTo(FetchedMessage $fetched, string $target): void
    {
        $message = $this->messages[$fetched->folder.'/'.$fetched->uid] ?? throw new LogicException('The message was not fetched in this session.');
        $client = $this->client();

        if (! $client->getFolderByPath($target) instanceof Folder) {
            $client->createFolder($target, false);
        }

        $message->move($target);
        unset($this->messages[$fetched->folder.'/'.$fetched->uid]);
    }

    private function client(): Client
    {
        if ($this->client === null) {
            $encryption = $this->config['encryption'];
            $this->client = (new ClientManager([]))->make([
                'host' => $this->config['host'],
                'port' => $this->config['port'],
                'encryption' => in_array($encryption, ['ssl', 'tls'], true) ? $encryption : false,
                'validate_cert' => $this->config['validate_cert'],
                'username' => $this->config['username'],
                'password' => (string) $this->config['password'],
                'protocol' => 'imap',
                'authentication' => null,
                'timeout' => 30,
            ]);
            $this->client->connect();
        }

        return $this->client;
    }
}
