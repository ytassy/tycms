<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tools;

use RuntimeException;

class CriticalSection
{
    private mixed $fileHandle = null;
    private bool $isLocked = false;

    /**
     * @param string $lockName Nom unique de la section critique (ex: 'traitement_commandes')
     * @param string|null $lockDir Dossier de stockage du fichier temporaire
     */
    public function __construct(
        private readonly string $lockName,
        ?string $lockDir = null
    ) {
        $directory = $lockDir ?? sys_get_temp_dir();
        $filePath = rtrim($directory, DIRECTORY_SEPARATOR) . '/' . md5($this->lockName) . '.lock';

        $handle = fopen($filePath, 'c+');
        if ($handle === false) {
            throw new RuntimeException("Impossible d'ouvrir le fichier de verrou : {$filePath}");
        }

        $this->fileHandle = $handle;
    }

    /**
     * Exécute une fonction anonyme à l'intérieur de la section critique.
     *
     * @param callable $callback Le code à exécuter en toute sécurité
     * @param bool $blocking Si true, attend que le verrou se libère. Si false, abandonne immédiatement si occupé.
     * @return mixed La valeur de retour du callback
     * @throws RuntimeException Si le verrou ne peut pas être acquis
     */
    public function execute(callable $callback, bool $blocking = true): mixed
    {
        if (!$this->acquire($blocking)) {
            throw new RuntimeException("La section critique '{$this->lockName}' est actuellement occupée.");
        }

        try {
            return $callback();
        } finally {
            $this->release();
        }
    }

    /**
     * Tente d'acquérir le verrou.
     */
    private function acquire(bool $blocking): bool
    {
        $flags = LOCK_EX | ($blocking ? 0 : LOCK_NB);

        if (flock($this->fileHandle, $flags)) {
            $this->isLocked = true;
            return true;
        }

        return false;
    }

    /**
     * Libère le verrou.
     */
    private function release(): void
    {
        if ($this->isLocked && is_resource($this->fileHandle)) {
            flock($this->fileHandle, LOCK_UN);
            $this->isLocked = false;
        }
    }

    /**
     * Garantit la libération du verrou et la fermeture de la ressource si l'objet est détruit.
     */
    public function __destruct()
    {
        $this->release();

        if (is_resource($this->fileHandle)) {
            fclose($this->fileHandle);
        }
    }

}