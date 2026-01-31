<?php

namespace App\Entity;

use App\Repository\ClientRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ClientRepository::class)]
#[ORM\Table(name: 'clients')]
class Client
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 10, unique: true)]
    private string $clientId;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $registeredAt;

    #[ORM\Column(type: 'boolean', nullable: true)]
    private ?bool $tierLocked = null;

    #[ORM\Column(type: 'string', length: 10, nullable: true)]
    private ?string $tierLockedValue = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getClientId(): string
    {
        return $this->clientId;
    }

    public function setClientId(string $clientId): self
    {
        $this->clientId = $clientId;
        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getRegisteredAt(): \DateTimeImmutable
    {
        return $this->registeredAt;
    }

    public function setRegisteredAt(\DateTimeImmutable $registeredAt): self
    {
        $this->registeredAt = $registeredAt;
        return $this;
    }

    public function isTierLocked(): ?bool
    {
        return $this->tierLocked;
    }

    public function setTierLocked(?bool $tierLocked): self
    {
        $this->tierLocked = $tierLocked;
        return $this;
    }

    public function getTierLockedValue(): ?string
    {
        return $this->tierLockedValue;
    }

    public function setTierLockedValue(?string $tierLockedValue): self
    {
        $this->tierLockedValue = $tierLockedValue;
        return $this;
    }
}
