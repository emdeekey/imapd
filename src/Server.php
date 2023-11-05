<?php

/**
 * Main Server
 * Handles Sockets and Clients.
 */

namespace TheFox\Imap;

use Exception;
use RuntimeException;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;
use TheFox\Network\StreamSocket;
use Zend\Mail\Message as ZendMailMessage;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\OptionsResolver\OptionsResolver;
use TheFox\Imap\Storage\AbstractStorage;
use TheFox\Imap\Storage\DirectoryStorage;
use TheFox\Network\Socket;
use Zend\Mail\Message;

class Server extends Thread
{
    use LoggerAwareTrait;

    public const LOOP_USLEEP = 10000;

    private Socket $socket;

    private bool $isListening = false;

    /**
     * @var array<mixed>
     */
    private array $options;

    private int $clientsId = 0;

    /**
     * @var array<int,Client>
     */
    private array $clients = [];

    private string $defaultStoragePath = 'maildata';

    private null|AbstractStorage|DirectoryStorage $defaultStorage = null;

    /**
     * @var array<mixed>
     */
    private array $storages = [];

    private int $eventsId = 0;

    /**
     * @var array<mixed>
     */
    private array $events = [];

    /**
     * @param array<mixed> $options
     */
    public function __construct(array $options = [])
    {
        $resolver = new OptionsResolver();
        $resolver->setDefaults([
            'ip' => '127.0.0.1',
            'port' => 20143,
            'logger' => new NullLogger(),
        ]);

        $this->options = $resolver->resolve($options);
        $this->logger = $this->options['logger'];

        $this->socket = new Socket();
        $this->socket->bind(
            $this->options['ip'],
            $this->options['port'],
        );
    }

    /**
     * @param array<mixed> $contextOptions 
     * @return bool 
     * @throws Exception 
     */
    public function listen($contextOptions = []): bool
    {
        try {
            $this->socket->listen($contextOptions);
            $this->logger->notice('listen ok');

            $this->isListening = true;
        } catch (Exception $e) {
            $this->logger->error($e->getMessage());
            throw $e;
        }

        return true;
    }

    /**
     * Main Function
     * Handles everything, keeps everything up-to-date.
     */
    public function run()
    {
        if (!$this->isListening) {
            throw new RuntimeException('Socket not initialized. You need to execute listen().', 1);
        }

        $readHandles[] = $this->socket->getHandle();
        $writeHandles = [];
        $exceptHandles = [];

        foreach ($this->clients as $clientId => $client) {
            $socket = $client->getSocket();

            // Collect client handles.
            $readHandles[] = $socket->getHandle();

            // Run client.
            $client->run();
        }

        $handlesChanged = $this->socket->select($readHandles, $writeHandles, $exceptHandles);

        if ($handlesChanged) {
            foreach ($readHandles as $readableHandle) {
                if ($readableHandle == $this->socket->getHandle()) {
                    // Server
                    $socket = $this->socket->accept();

                    if ($socket) {
                        $client = $this->newClient($socket);
                        $client->sendHello();
                    }
                } else {
                    // Client
                    $client = $this->getClientByHandle($readableHandle);

                    if ($client) {
                        if (feof($client->getSocket()->getHandle())) {
                            $this->removeClient($client);
                            continue;
                        }

                        $client->dataRecv();

                        if ($client->getStatus('hasShutdown')) {
                            $this->removeClient($client);
                        }
                    }
                }
            }
        }
    }

    /**
     * Main Loop
     */
    public function loop()
    {
        while (!$this->getExit()) {
            $this->run();
            usleep(static::LOOP_USLEEP);
        }

        $this->shutdown();
    }

    /**
     * Shutdown the server.
     * Should be executed before your application exits.
     */
    public function shutdown()
    {
        // Notify all clients.
        foreach ($this->clients as $clientId => $client) {
            $client->sendBye('Server shutdown');
            $this->removeClient($client);
        }

        // Remove all temp files and save dbs.
        $this->shutdownStorages();
    }

    /**
     * @param StreamSocket $socket
     * @return Client
     */
    public function newClient(StreamSocket $socket): Client
    {
        $this->clientsId++;

        $options = [
            'logger' => $this->logger,
        ];

        $client = new Client($options);
        $client->setSocket($socket);
        $client->setId($this->clientsId);
        $client->setServer($this);

        $this->clients[$this->clientsId] = $client;

        return $client;
    }

    /**
     * @param resource $handle
     */
    public function getClientByHandle($handle): ?Client
    {
        foreach ($this->clients as $clientId => $client) {
            if ($client->getSocket()->getHandle() === $handle) {
                return $client;
            }
        }

        return null;
    }

    /**
     * @param Client $client
     */
    public function removeClient(Client $client)
    {
        $this->logger->debug('client remove: ' . $client->getId());

        $client->shutdown();

        $clientsId = $client->getId();
        unset($this->clients[$clientsId]);
    }

    public function getDefaultStorage(): DirectoryStorage
    {
        if (!$this->defaultStorage instanceof DirectoryStorage) {
            $storage = (new DirectoryStorage())
                ->setPath($this->defaultStoragePath);

            $this->addStorage($storage);

            return $storage;
        }

        return $this->defaultStorage;
    }

    public function addStorage(AbstractStorage $storage): AbstractStorage
    {
        if (!$this->defaultStorage) {
            $this->defaultStorage = $storage;

            $dbPath = $storage->getPath();

            if (substr($dbPath, -1) == '/') {
                $dbPath = substr($dbPath, 0, -1);
            }

            $dbPath .= '.yml';
            $storage->setDbPath($dbPath);

            $db = new MessageDatabase($dbPath);
            $db->load();

            $storage->setDb($db);
        } else {
            $this->storages[] = $storage;
        }

        return $storage;
    }

    public function shutdownStorages(): void
    {
        $filesystem = new Filesystem();

        $this->getDefaultStorage()->save();

        foreach ($this->storages as $storageId => $storage) {
            if ($storage->getType() == 'temp') {
                $filesystem->remove($storage->getPath());

                if ($storage->getDbPath()) {
                    $filesystem->remove($storage->getDbPath());
                }
            } elseif ($storage->getType() == 'normal') {
                $storage->save();
            }
        }
    }

    public function addFolder(string $path): bool
    {
        $storage = $this->getDefaultStorage();
        $successful = $storage->createFolder($path);

        foreach ($this->storages as $storageId => $storage) {
            $storage->createFolder($path);
        }

        return $successful;
    }

    /**
     * @return string[]
     */
    public function getFolders(string $baseFolder, string $searchFolder, bool $recursive = false, int $level = 0, int $maxLevel = 100): array
    {
        $tmp = [$level, $baseFolder, $searchFolder, intval($recursive)];
        $this->logger->debug(vsprintf('getFolders%d: /%s/ /%s/ %d', $tmp));

        if ($level >= $maxLevel) {
            throw new RuntimeException(sprintf('Max depth Recursion reached: %d', $maxLevel));
        }

        if ($baseFolder == '' && $searchFolder == 'INBOX') {
            return $this->getFolders('INBOX', '*', true, $level + 1);
        }

        $storage = $this->getDefaultStorage();
        $foundFolders = $storage->getFolders($baseFolder, $searchFolder, $recursive);

        $folders = [];
        foreach ($foundFolders as $folder) {
            $folder = str_replace('/', '.', $folder);
            $folders[] = $folder;
        }

        usort($folders, function (string $a, string $b) {
            return $a <=> $b;
        });

        return $folders;
    }

    public function folderExists(string $folder): bool
    {
        $storage = $this->getDefaultStorage();
        return $storage->folderExists($folder);
    }

    public function getNextMsgId(): int
    {
        $storage = $this->getDefaultStorage();
        return $storage->getNextMsgId();
    }

    public function getMsgSeqById(int $msgId): int
    {
        $storage = $this->getDefaultStorage();

        return $storage->getMsgSeqById($msgId);
    }

    public function getMsgIdBySeq(int $seqNum, string $folder): int
    {
        $storage = $this->getDefaultStorage();

        return $storage->getMsgIdBySeq($seqNum, $folder);
    }

    /**
     * @return array<mixed>
     */
    public function getFlagsById(int $msgId): array
    {
        $storage = $this->getDefaultStorage();

        return $storage->getFlagsById($msgId);
    }

    /**
     * @param array<mixed> $flags
     */
    public function setFlagsById(int $msgId, array $flags): static
    {
        $storage = $this->getDefaultStorage();
        $storage->setFlagsById($msgId, $flags);

        return $this;
    }

    /**
     * @return array<mixed>
     */
    public function getFlagsBySeq(int $seqNum, string $folder): array
    {
        $storage = $this->getDefaultStorage();
        return $storage->getFlagsBySeq($seqNum, $folder);
    }

    /**
     * @param array<mixed> $flags
     */
    public function setFlagsBySeq(int $seqNum, string $folder, array $flags): static
    {
        $storage = $this->getDefaultStorage();
        $storage->setFlagsBySeq($seqNum, $folder, $flags);

        return $this;
    }

    /**
     * @param array<mixed> $flags
     */
    public function getCountMailsByFolder(string $folder, array $flags = []): int
    {
        /** @var DirectoryStorage $storage */
        $storage = $this->getDefaultStorage();
        return $storage->getMailsCountByFolder($folder, $flags);
    }

    public function addMail(ZendMailMessage $mail, ?string $folder = null, ?array $flags = null, bool $recent = true): int
    {
        if (!$folder) {
            $folder = '';
        }

        $this->executeEvent(Event::TRIGGER_MAIL_ADD_PRE);

        $storage = $this->getDefaultStorage();
        $mailStr = $mail->toString();

        $msgId = $storage->addMail($mailStr, $folder, $flags, $recent);
        $storage->save();

        foreach ($this->storages as $storageId => $storage) {
            $storage->addMail($mailStr, $folder, $flags, $recent);
            $storage->save();
        }

        $this->executeEvent(Event::TRIGGER_MAIL_ADD, [$mail]);

        $this->executeEvent(Event::TRIGGER_MAIL_ADD_POST, [$msgId]);

        return $msgId;
    }

    public function removeMailById(int $msgId): void
    {
        $storage = $this->getDefaultStorage();
        $this->logger->debug('remove msgId: /' . $msgId . '/');
        $storage->removeMail($msgId);

        foreach ($this->storages as $storageId => $storage) {
            $storage->removeMail($msgId);
        }
    }

    public function removeMailBySeq(int $seqNum, string $folder): void
    {
        $this->logger->debug('remove seq: /' . $seqNum . '/');

        $msgId = $this->getMsgIdBySeq($seqNum, $folder);
        if ($msgId) {
            $this->removeMailById($msgId);
        }
    }

    public function copyMailById(int $msgId, string $dstFolder): void
    {
        $storage = $this->getDefaultStorage();
        $this->logger->debug('copy msgId: /' . $msgId . '/');
        $storage->copyMailById($msgId, $dstFolder);

        foreach ($this->storages as $storageId => $storage) {
            $storage->copyMailById($msgId, $dstFolder);
        }
    }

    public function copyMailBySequenceNum(int $seqNum, string $folder, string $dstFolder): void
    {
        $storage = $this->getDefaultStorage();
        $this->logger->debug('copy seq: /' . $seqNum . '/');
        $storage->copyMailBySequenceNum($seqNum, $folder, $dstFolder);

        foreach ($this->storages as $storageId => $storage) {
            $storage->copyMailBySequenceNum($seqNum, $folder, $dstFolder);
        }
    }

    public function getMailById(int $msgId): ?ZendMailMessage
    {
        $mailStr = $this->getDefaultStorage()->getPlainMailById($msgId);

        if (!$mailStr) {
            return null;
        }

        try {
            $mail = ZendMailMessage::fromString($mailStr);
            return $mail;
        } catch (\Error $e) {
            $this->logger->error('ZendMailMessage::fromString ERROR: ' . $e);
        }

        return null;
    }

    public function getMailBySeq(int $seqNum, string $folder): ?Message
    {
        $msgId = $this->getMsgIdBySeq($seqNum, $folder);
        if (!$msgId) {
            return null;
        }

        return $this->getMailById($msgId);
    }

    /**
     * @param array<mixed> $flags
     * @return array<mixed>
     */
    public function getMailIdsByFlags(array $flags): array
    {
        $storage = $this->getDefaultStorage();

        $msgsIds = $storage->getMsgsByFlags($flags);

        return $msgsIds;
    }

    public function addEvent(Event $event): void
    {
        $this->eventsId++;
        $this->events[$this->eventsId] = $event;
    }

    /**
     * @param array<mixed> $args
     */
    private function executeEvent(int $trigger, array $args = []): void
    {
        foreach ($this->events as $eventId => $event) {
            if ($event->getTrigger() != $trigger) {
                continue;
            }

            $event->execute($args);
        }
    }
}
