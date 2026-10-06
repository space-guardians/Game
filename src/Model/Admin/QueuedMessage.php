<?php

declare(strict_types=1);

namespace App\Model\Admin;

/**
 * Message d'une file Messenger, tel que l'affiche la supervision de l'exploitation (§5.6.1).
 */
final readonly class QueuedMessage implements \Stringable
{
    public function __construct(
        /** Identifiant dans le transport (TransportMessageIdStamp) */
        public string $id,
        public string $transport,
        /** Classe du message (FQCN) */
        public string $class,
        public ?string $error = null,
        public ?string $errorClass = null,
        public ?\DateTimeImmutable $failedAt = null,
        /** Transport d'origine d'un message en échec, où le renvoyer en cas de relance */
        public ?string $originalTransport = null,
    ) {}

    public function shortClass(): string
    {
        $position = strrpos($this->class, '\\');

        return false === $position ? $this->class : substr($this->class, $position + 1);
    }

    public function __toString(): string
    {
        return \sprintf('Message %s #%s (file %s)', $this->shortClass(), $this->id, $this->transport);
    }
}
