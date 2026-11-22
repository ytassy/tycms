<?php

declare(strict_types=1);

namespace YTassy\Tycms\User;

use YTassy\Tycms\Application;
use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\Response\RespLevel;

class PendingMessageQueue extends BaseApplicationItem
{
    private const SESSION_KEY = '_pending_message_queue';

    /**
     * Ajoute un message à la file d'attente.
     */
    public function add(string $message, RespLevel $type = RespLevel::INFO): self
    {
        $messages = $this->getQueue();
        $messages[] = [
            'message' => $message,
            'type'    => $type,
        ];

        $this->session->set(self::SESSION_KEY, $messages);

        return $this;
    }

    /**
     * Raccourcis pour les types courants.
     */
    public function success(string $message): self
    {
        return $this->add($message, RespLevel::SUCCESS);
    }

    public function error(string $message): self
    {
        return $this->add($message, RespLevel::ERROR);
    }

    public function warning(string $message): self
    {
        return $this->add($message, RespLevel::WARNING);
    }

    public function info(string $message): self
    {
        return $this->add($message, RespLevel::INFO);
    }

    public function danger(string $message): self
    {
        return $this->add($message, RespLevel::DANGER);
    }

    /**
     * Récupère tous les messages et vide la file d'attente (dépilage).
     * 
     * @return array<int, array{message: string, type: string}>
     */
    public function flush(): array
    {
        $messages = $this->getQueue();
        $this->session->set(self::SESSION_KEY, []);

        return $messages;
    }

    /**
     * Vérifie si des messages sont en attente.
     */
    public function hasMessages(): bool
    {
        return !empty($this->getQueue());
    }

    /**
     * Compte le nombre de messages en attente sans dépiler.
     */
    public function count(): int
    {
        return count($this->getQueue());
    }

    /**
     * Récupère la file actuelle depuis la session sous forme de tableau.
     * 
     * @return array<int, array{message: string, type: string}>
     */
    private function getQueue(): array
    {
        $queue = $this->session->get(self::SESSION_KEY);

        return is_array($queue) ? $queue : [];
    }
}