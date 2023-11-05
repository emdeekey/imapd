<?php

namespace TheFox\Imap\Storage;

use TheFox\Storage\YamlStorage;

abstract class AbstractStorage
{
    private string $path;

    private int $pathLen;

    private string $dbPath;

    private ?YamlStorage $db = null;

    private string $type = 'normal';

    abstract protected function getDirectorySeperator(): string;

    public function setPath(string $path): static
    {
        $this->path = $path;
        $this->pathLen = strlen($this->path);

        return $this;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getPathLen(): int
    {
        return $this->pathLen;
    }

    public function setDbPath(string $dbPath): static
    {
        $this->dbPath = $dbPath;

        return $this;
    }

    public function getDbPath(): string
    {
        return $this->dbPath;
    }

    public function setDb(YamlStorage $db): static
    {
        $this->db = $db;

        return $this;
    }

    public function getDb(): ?YamlStorage
    {
        return $this->db;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function genFolderPath(string $path): string
    {
        if ($path == 'INBOX') {
            $path = '.';
        }

        if ($path) {
            $seperator = $this->getDirectorySeperator();

            $path = str_replace('.', $seperator, $path);
            $path = $this->path . DIRECTORY_SEPARATOR . $path;
            $path = str_replace('//', '/', $path);

            if (substr($path, -1) == '/') {
                $path = substr($path, 0, -1);
            }
        } else {
            $path = $this->path;
        }

        return $path;
    }

    abstract protected function createFolder(string $folder): bool;

    abstract protected function getFolders(string $baseFolder, string $searchFolder, bool $recursive = false): array;

    abstract protected function folderExists(string $folder): bool;

    abstract protected function getMailsCountByFolder(string $folder, array $flags = []): int;

    abstract protected function addMail(
        string $mailStr,
        string $folder,
        array $flags = null,
        bool $recent = false
    ): int;

    abstract protected function removeMail(int $msgId);

    abstract protected function copyMailById(int $msgId, string $folder);

    abstract protected function copyMailBySequenceNum(int $seqNum, string $folder, string $dstFolder);

    abstract protected function getPlainMailById(int $msgId): string;

    abstract protected function getMsgSeqById(int $msgId): int;

    abstract protected function getMsgIdBySeq(int $seqNum, string $folder): int;

    abstract protected function getMsgsByFlags(array $flags): array;

    abstract protected function getFlagsById(int $msgId);

    abstract protected function setFlagsById(int $msgId, array $flags);

    abstract protected function getFlagsBySeq(int $seqNum, string $folder): array;

    abstract protected function setFlagsBySeq(int $seqNum, string $folder, array $flags);

    abstract protected function getNextMsgId(): int;

    public function save()
    {
        if ($this->db) {
            $this->db->save();
        }
    }
}
