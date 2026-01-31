<?php

namespace App\Entity;

use App\Repository\TransactionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TransactionRepository::class)]
#[ORM\Table(name: 'transactions')]
#[ORM\Index(columns: ['client_id', 'created_at'], name: 'idx_tx_client_createdat')]
#[ORM\Index(columns: ['transaction_id'], name: 'idx_tx_transaction_id')]
#[ORM\Index(columns: ['client_external_id', 'created_at'], name: 'idx_tx_clientext_createdat')]
class Transaction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'transaction_id', type: 'string', length: 20, unique: true)]
    private string $transactionId;

    // CSV client_id zawsze zapisujemy tutaj (nawet jeśli brak klienta w tabeli clients)
    #[ORM\Column(name: 'client_external_id', type: 'string', length: 10)]
    private string $clientExternalId;

    // Relacja może być null - pozwala zaimportować transakcje z brakującym klientem i zalogować niespójność
    #[ORM\ManyToOne(targetEntity: Client::class)]
    #[ORM\JoinColumn(name: 'client_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Client $client = null;

    #[ORM\Column(type: 'decimal', precision: 18, scale: 2)]
    private string $amount;

    #[ORM\Column(name: 'source_currency', type: 'string', length: 3)]
    private string $sourceCurrency;

    #[ORM\Column(name: 'target_currency', type: 'string', length: 3)]
    private string $targetCurrency;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'refunded_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $refundedAt = null;

    #[ORM\Column(name: 'original_fee', type: 'decimal', precision: 18, scale: 2, nullable: true)]
    private ?string $originalFee = null;

    #[ORM\Column(name: 'original_final_amount', type: 'decimal', precision: 18, scale: 2, nullable: true)]
    private ?string $originalFinalAmount = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTransactionId(): string
    {
        return $this->transactionId;
    }

    public function setTransactionId(string $transactionId): self
    {
        $this->transactionId = $transactionId;
        return $this;
    }

    public function getClientExternalId(): string
    {
        return $this->clientExternalId;
    }

    public function setClientExternalId(string $clientExternalId): self
    {
        $this->clientExternalId = $clientExternalId;
        return $this;
    }

    public function getClient(): ?Client
    {
        return $this->client;
    }

    public function setClient(?Client $client): self
    {
        $this->client = $client;
        return $this;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function setAmount(string $amount): self
    {
        $this->amount = $amount;
        return $this;
    }

    public function getSourceCurrency(): string
    {
        return $this->sourceCurrency;
    }

    public function setSourceCurrency(string $sourceCurrency): self
    {
        $this->sourceCurrency = strtoupper($sourceCurrency);
        return $this;
    }

    public function getTargetCurrency(): string
    {
        return $this->targetCurrency;
    }

    public function setTargetCurrency(string $targetCurrency): self
    {
        $this->targetCurrency = strtoupper($targetCurrency);
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getRefundedAt(): ?\DateTimeImmutable
    {
        return $this->refundedAt;
    }

    public function setRefundedAt(?\DateTimeImmutable $refundedAt): self
    {
        $this->refundedAt = $refundedAt;
        return $this;
    }

    public function getOriginalFee(): ?string
    {
        return $this->originalFee;
    }

    public function setOriginalFee(?string $originalFee): self
    {
        $this->originalFee = $originalFee;
        return $this;
    }

    public function getOriginalFinalAmount(): ?string
    {
        return $this->originalFinalAmount;
    }

    public function setOriginalFinalAmount(?string $originalFinalAmount): self
    {
        $this->originalFinalAmount = $originalFinalAmount;
        return $this;
    }
}
