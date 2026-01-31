<?php

namespace App\Entity;

use App\Repository\RateRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RateRepository::class)]
#[ORM\Table(name: 'rates')]
#[ORM\Index(columns: ['source_currency', 'target_currency', 'valid_from'], name: 'idx_rates_pair_validfrom')]
#[ORM\UniqueConstraint(name: 'uniq_rates_pair_validfrom', columns: ['source_currency', 'target_currency', 'valid_from'])]
class Rate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'source_currency', type: 'string', length: 3)]
    private string $sourceCurrency;

    #[ORM\Column(name: 'target_currency', type: 'string', length: 3)]
    private string $targetCurrency;

    #[ORM\Column(type: 'decimal', precision: 18, scale: 8)]
    private string $rate;

    #[ORM\Column(name: 'valid_from', type: 'date_immutable')]
    private \DateTimeImmutable $validFrom;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getRate(): string
    {
        return $this->rate;
    }

    public function setRate(string $rate): self
    {
        $this->rate = $rate;
        return $this;
    }

    public function getValidFrom(): \DateTimeImmutable
    {
        return $this->validFrom;
    }

    public function setValidFrom(\DateTimeImmutable $validFrom): self
    {
        $this->validFrom = $validFrom;
        return $this;
    }
}
