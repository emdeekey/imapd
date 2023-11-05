<?php

namespace TheFox\Imap\Storage;

use SplFileInfo;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use TheFox\Imap\MessageDatabase;

class DirectoryStorage extends AbstractStorage
{
    public function getDirectorySeperator(): string
    {
        return DIRECTORY_SEPARATOR;
    }

    public function setPath(string $path): static
    {
        parent::setPath($path);

        if (!file_exists($this->getPath())) {
            $filesystem = new Filesystem();
            $filesystem->mkdir($this->getPath(), 0755);
        }

        return $this;
    }

    public function folderExists(string $folder): bool
    {
        $path = $this->genFolderPath($folder);

        return file_exists($path) && is_dir($path);
    }

    public function createFolder(string $folder): bool
    {
        if ($this->folderExists($folder)) {
            return false;
        }

        $path = $this->genFolderPath($folder);

        if (file_exists($path)) {
            return false;
        }

        $filesystem = new Filesystem();
        $filesystem->mkdir($path, 0755);

        return file_exists($path);
    }

    /** @return SplFileInfo[] */
    private function recursiveDirectorySearch(string $path, string $pattern, bool $recursive = false, int $level = 0): array
    {
        $folders = [];

        if (!is_dir($path)) {
            return [];
        }

        $dirHandle = opendir($path);

        if (!$dirHandle) {
            return [];
        }

        while (($fileName = readdir($dirHandle)) !== false) {
            if ($fileName == '.' || $fileName == '..') {
                continue;
            }

            $dir = $path . DIRECTORY_SEPARATOR . $fileName;

            if (!is_dir($dir)) {
                continue;
            }

            if (fnmatch($pattern, $fileName)) {
                $folders[] = new SplFileInfo($dir);
            }

            if ($recursive) {
                $recursiveFolders = $this->recursiveDirectorySearch($dir, $pattern, $recursive, $level + 1);

                // Append Folders.
                $folders = array_merge($folders, $recursiveFolders);
            }
        }
        closedir($dirHandle);

        return $folders;
    }

    /** @return string[] */
    public function getFolders(string $baseFolder, string $searchFolder, bool $recursive = false): array
    {
        $basePath = $this->genFolderPath($baseFolder);

        /** @var SplFileInfo[] $foundFolders */
        $foundFolders = $this->recursiveDirectorySearch($basePath, $searchFolder, $recursive);

        $folders = [];

        foreach ($foundFolders as $dir) {
            $folderPath = $dir->getPathname();
            $folderPath = substr($folderPath, $this->getPathLen());

            if ($folderPath[0] == '/') {
                $folderPath = substr($folderPath, 1);
            }

            $folders[] = $folderPath;
        }

        return $folders;
    }

    /**
     * @param array<mixed> $flags
     */
    public function getMailsCountByFolder(string $folder, array $flags = []): int
    {
        $path = $this->genFolderPath($folder);
        $finder = $this->getFinderForPath($path, '*.eml');

        if (!$finder || !count($flags)) {
            return count($finder);
        }

        $db = $this->getMessageDatabase();

        if (!$db) {
            return 0;
        }

        $count = 0;

        foreach ($finder as $file) {
            $msgId = $db->getMsgIdByPath($file->getPathname());
            $msgFlags = $db->getFlagsById($msgId);

            foreach ($flags as $flag) {
                if (!in_array($flag, $msgFlags)) {
                    break;
                }

                $count++;
            }
        }

        return $count;
    }

    /**
     * @param ?array<mixed> $flags
     */
    public function addMail(string $mailStr, string $folder, ?array $flags = null, bool $recent = true): int
    {
        $msgId = 0;

        $path = $this->genFolderPath($folder);
        $fileName = 'mail_' . sprintf('%.32f', microtime(true)) . '_' . mt_rand(100000, 999999) . '.eml';
        $filePath = $path . '/' . $fileName;

        $db = $this->getMessageDatabase();

        if ($db) {
            $msgId = $db->addMsg($filePath, $flags, $recent);

            $fileName = 'mail_' . sprintf('%032d', $msgId) . '.eml';
            $filePath = $path . '/' . $fileName;

            $db->setPathById($msgId, $filePath);
        }

        $filesystem = new Filesystem();
        $filesystem->dumpFile($filePath, $mailStr);

        return $msgId;
    }

    public function removeMail(int $msgId): void
    {
        $db = $this->getMessageDatabase();

        if ($db) {
            $msg = $db->removeMsg($msgId);

            $filesystem = new Filesystem();
            $filesystem->remove($msg['path']);
        }
    }

    public function copyMailById(int $msgId, string $folder): void
    {
        $mailStr = $this->getPlainMailById($msgId);

        if ($mailStr !== '') {
            $this->addMail($mailStr, $folder);
        }
    }

    public function copyMailBySequenceNum(int $seqNum, string $folder, string $dstFolder): void
    {
        $msgId = $this->getMsgIdBySeq($seqNum, $folder);
        if ($msgId) {
            $this->copyMailById($msgId, $dstFolder);
        }
    }

    public function getPlainMailById(int $msgId): string
    {
        $msg = $this->getMessageDatabase()?->getMsgById($msgId) ?? [];
        $path = $msg['path'] ?? null;

        $filesystem = new Filesystem();

        return $filesystem->exists($path) ? file_get_contents($path) : '';
    }

    public function getMsgSeqById(int $msgId): int
    {
        $msg = $this->getMessageDatabase()?->getMsgById($msgId) ?? null;

        if (!$msg) {
            return 0;
        }

        $pathinfo = (array) pathinfo($msg['path'] ?? '');
        $path = $pathinfo['dirname'] ?? null;
        $finder = $path ? $this->getFinderForPath($path, '*.eml') : null;
        $basename = $pathinfo['basename'];

        if (!$finder || count($finder) === 0) {
            return 0;
        }

        $finder->sort(function (SplFileInfo $a, SplFileInfo $b) {
            return $a->getPathname() <=> $b->getPathname();
        });

        $seq = 0;

        foreach ($finder as $file) {
            $seq++;

            if ($file->getFilename() === $basename) {
                return $seq;
            }
        }

        return $seq;
    }

    public function getMsgIdBySeq(int $seqNum, string $folder): int
    {
        $path = $this->genFolderPath($folder);
        $finder = $this->getFinderForPath($path, '*.eml');

        if (!$finder || count($finder) === 0) {
            return 0;
        }

        $finder->sort(function (SplFileInfo $a, SplFileInfo $b) {
            return $a->getPathname() <=> $b->getPathname();
        });

        $seq = 0;

        foreach ($finder as $file) {
            $seq++;

            if ($seq >= $seqNum) {
                return $this->getMessageDatabase()?->getMsgIdByPath($file->getPathname()) ?? 0;
            }
        }

        return $seq;
    }

    /**
     * @param array<mixed> $flags
     * @return array<mixed>
     */
    public function getMsgsByFlags(array $flags): array
    {
        return $this->getMessageDatabase()?->getMsgIdsByFlags($flags) ?? [];
    }

    /**
     * @return array<mixed>
     */
    public function getFlagsById(int $msgId): array
    {
        return $this->getMessageDatabase()?->getFlagsById($msgId) ?? [];
    }

    /**
     * @param array<mixed> $flags
     */
    public function setFlagsById(int $msgId, array $flags): static
    {
        $db = $this->getMessageDatabase();

        if ($db) {
            $db->setFlagsById($msgId, $flags);
        }

        return $this;
    }

    /**
     * @return array<mixed>
     */
    public function getFlagsBySeq(int $seqNum, string $folder): array
    {
        $db = $this->getMessageDatabase();
        $path = $this->genFolderPath($folder);
        $finder = $this->getFinderForPath($path, '*.eml');

        if (!$db || !$finder || count($finder) === 0) {
            return [];
        }

        $finder->sort(function (SplFileInfo $a, SplFileInfo $b) {
            return $a->getPathname() <=> $b->getPathname();
        });

        $seq = 0;
        foreach ($finder as $file) {
            $seq++;

            if ($seq >= $seqNum) {
                $msgId = $db->getMsgIdByPath($file->getPathname());
                $flags = $this->getFlagsById($msgId);
                return $flags;
            }
        }

        return [];
    }

    /**
     * @param array<mixed> $flags
     */
    public function setFlagsBySeq(int $seqNum, string $folder, array $flags): static
    {
        $db = $this->getMessageDatabase();

        if ($db) {
            $msgId = $this->getMsgIdBySeq($seqNum, $folder);
            if ($msgId) {
                $db->setFlagsById($msgId, $flags);
            }
        }

        return $this;
    }

    public function getNextMsgId(): int
    {
        $db = $this->getMessageDatabase();

        if ($db) {
            return $db->getNextId();
        }

        return 0;
    }

    private function getMessageDatabase(): ?MessageDatabase
    {
        $db = $this->getDb();

        return ($db instanceof MessageDatabase) ? $db : null;
    }

    private function getFinderForPath(string $path, ?string $withNameLike = null): ?Finder
    {
        $filesystem = new Filesystem();
        
        if (!$filesystem->exists($path)) {
            return null;
        }
        
        $finder = new Finder();
        
        if ($withNameLike) {
            $finder->name($withNameLike);
        } 

        return $finder->in($path);
    }
}
