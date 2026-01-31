<?php

namespace App\Command;

use App\Entity\Client;
use App\Entity\Rate;
use App\Entity\Transaction;
use App\Repository\ClientRepository;
use App\Repository\RateRepository;
use App\Repository\TransactionRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:import-csv',
    description: 'Imports clients.csv, rates.csv, transactions.csv from a given folder (logs inconsistencies).'
)]
class ImportCsvCommand extends Command
{
    private const TIERS = ['BRONZE', 'SILVER', 'GOLD'];

    /** @var array<string, true> */
    private array $dryRunClientIds = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClientRepository $clients,
        private readonly RateRepository $rates,
        private readonly TransactionRepository $transactions,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Folder containing clients.csv, rates.csv, transactions.csv', '/var/www/html/data')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Validate + log inconsistencies, but do NOT write to DB')
            ->addOption('reset', null, InputOption::VALUE_NONE, 'Truncate tables before import (clients, rates, transactions)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $basePath = rtrim((string)$input->getOption('path'), '/');
        $dryRun = (bool)$input->getOption('dry-run');
        $reset = (bool)$input->getOption('reset');

        $files = [
            'clients' => $basePath . '/clients.csv',
            'rates' => $basePath . '/rates.csv',
            'transactions' => $basePath . '/transactions.csv',
        ];

        foreach ($files as $path) {
            if (!is_file($path)) {
                $output->writeln("<error>Missing file: {$path}</error>");
                return Command::FAILURE;
            }
        }

        $output->writeln("Import path: {$basePath}");
        $output->writeln("Mode: " . ($dryRun ? 'DRY RUN (no DB writes, in-memory reference checks)' : 'WRITE'));
        if ($reset) {
            $output->writeln("Reset: " . ($dryRun ? 'SKIPPED (dry-run)' : 'YES (truncate tables first)'));
        }

        $stats = [
            'clients' => ['processed' => 0, 'created' => 0, 'updated' => 0, 'warnings' => 0, 'errors' => 0],
            'rates' => ['processed' => 0, 'created' => 0, 'updated' => 0, 'warnings' => 0, 'errors' => 0],
            'transactions' => ['processed' => 0, 'created' => 0, 'updated' => 0, 'warnings' => 0, 'errors' => 0],
        ];

        if ($reset && !$dryRun) {
            $this->truncateAll();
        }

        $this->dryRunClientIds = [];

        $this->importClients($files['clients'], $stats['clients'], $dryRun);
        $this->importRates($files['rates'], $stats['rates'], $dryRun);
        $this->importTransactions($files['transactions'], $stats['transactions'], $dryRun);

        $output->writeln("Done.");
        foreach ($stats as $k => $s) {
            $output->writeln(sprintf(
                "%s: processed=%d created=%d updated=%d warnings=%d errors=%d",
                $k, $s['processed'], $s['created'], $s['updated'], $s['warnings'], $s['errors']
            ));
        }

        return Command::SUCCESS;
    }

    private function truncateAll(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement('TRUNCATE TABLE transactions RESTART IDENTITY CASCADE');
        $conn->executeStatement('TRUNCATE TABLE rates RESTART IDENTITY CASCADE');
        $conn->executeStatement('TRUNCATE TABLE clients RESTART IDENTITY CASCADE');
    }

    private function importClients(string $file, array &$stat, bool $dryRun): void
    {
        $rows = $this->readCsv($file);
        $i = 0;

        foreach ($rows as $row) {
            $stat['processed']++;

            $clientId = trim((string)($row['client_id'] ?? ''));
            $name = trim((string)($row['name'] ?? ''));
            $registeredAtRaw = trim((string)($row['registered_at'] ?? ''));
            $tierLockedRaw = trim((string)($row['tier_locked'] ?? ''));
            $tierLockedValueRaw = trim((string)($row['tier_locked_value'] ?? ''));

            $rowKey = "clients client_id={$clientId}";

            if ($clientId === '' || $name === '' || $registeredAtRaw === '') {
                $stat['errors']++;
                $this->err("clients.csv missing required fields", $row, $rowKey);
                continue;
            }

            // dry-run: budujemy mapę ID dla walidacji transakcji
            $this->dryRunClientIds[$clientId] = true;

            $registeredAt = \DateTimeImmutable::createFromFormat('Y-m-d', $registeredAtRaw);
            if (!$registeredAt) {
                $stat['errors']++;
                $this->err("clients.csv invalid registered_at={$registeredAtRaw}", $row, $rowKey);
                continue;
            }

            $tierLocked = ($tierLockedRaw !== '' && $tierLockedRaw !== '0');
            $tierLockedValue = $tierLockedValueRaw !== '' ? strtoupper($tierLockedValueRaw) : null;

            if ($tierLocked && $tierLockedValue === null) {
                $stat['warnings']++;
                $this->warn("clients.csv tier_locked=1 but tier_locked_value empty", $row, $rowKey);
            }

            if ($tierLockedValue !== null && !in_array($tierLockedValue, self::TIERS, true)) {
                $stat['errors']++;
                $this->err("clients.csv invalid tier_locked_value={$tierLockedValue}", $row, $rowKey);
                $tierLockedValue = null;
            }

            // WRITE mode: upsert do DB
            if ($dryRun) {
                // nie zapisujemy, ale liczniki nadal symulujemy jako "created/updated" po DB nie rozstrzygniemy
                $stat['created']++;
                continue;
            }

            $client = $this->clients->findOneByClientId($clientId);
            $isNew = false;
            if (!$client) {
                $client = new Client();
                $client->setClientId($clientId);
                $isNew = true;
            }

            $client->setName($name);
            $client->setRegisteredAt($registeredAt);
            $client->setTierLocked($tierLocked ? true : null);
            $client->setTierLockedValue($tierLockedValue);

            $this->em->persist($client);
            $isNew ? $stat['created']++ : $stat['updated']++;

            $i++;
            if (($i % 500) === 0) {
                $this->safeFlush($stat, 'clients');
                $this->em->clear();
            }
        }

        if (!$dryRun) {
            $this->safeFlush($stat, 'clients');
            $this->em->clear();
        }
    }

    private function importRates(string $file, array &$stat, bool $dryRun): void
    {
        $rows = $this->readCsv($file);
        $i = 0;

        foreach ($rows as $row) {
            $stat['processed']++;

            $source = strtoupper(trim((string)($row['source'] ?? '')));
            $target = strtoupper(trim((string)($row['target'] ?? '')));
            $rateRaw = trim((string)($row['rate'] ?? ''));
            $validFromRaw = trim((string)($row['valid_from'] ?? ''));

            $rowKey = "rates {$source}/{$target} valid_from={$validFromRaw}";

            if ($source === '' || $target === '' || $rateRaw === '' || $validFromRaw === '') {
                $stat['errors']++;
                $this->err("rates.csv missing required fields", $row, $rowKey);
                continue;
            }

            if (!$this->isCurrencyCode($source) || !$this->isCurrencyCode($target)) {
                $stat['errors']++;
                $this->err("rates.csv invalid currency code(s) source={$source} target={$target}", $row, $rowKey);
                continue;
            }

            $validFrom = \DateTimeImmutable::createFromFormat('Y-m-d', $validFromRaw);
            if (!$validFrom) {
                $stat['errors']++;
                $this->err("rates.csv invalid valid_from={$validFromRaw}", $row, $rowKey);
                continue;
            }

            if (!$this->isPositiveDecimal($rateRaw)) {
                $stat['errors']++;
                $this->err("rates.csv invalid rate={$rateRaw}", $row, $rowKey);
                continue;
            }

            if ($dryRun) {
                $stat['created']++;
                continue;
            }

            $rate = $this->rates->findOneByPairAndDate($source, $target, $validFrom);
            $isNew = false;

            if (!$rate) {
                $rate = new Rate();
                $rate->setSourceCurrency($source);
                $rate->setTargetCurrency($target);
                $rate->setValidFrom($validFrom);
                $isNew = true;
            }

            $rate->setRate($rateRaw);

            $this->em->persist($rate);
            $isNew ? $stat['created']++ : $stat['updated']++;

            $i++;
            if (($i % 1000) === 0) {
                $this->safeFlush($stat, 'rates');
                $this->em->clear();
            }
        }

        if (!$dryRun) {
            $this->safeFlush($stat, 'rates');
            $this->em->clear();
        }
    }

    private function importTransactions(string $file, array &$stat, bool $dryRun): void
    {
        $rows = $this->readCsv($file);
        $i = 0;

        foreach ($rows as $row) {
            $stat['processed']++;

            $transactionId = trim((string)($row['transaction_id'] ?? ''));
            $clientId = trim((string)($row['client_id'] ?? ''));
            $amountRaw = trim((string)($row['amount'] ?? ''));
            $source = strtoupper(trim((string)($row['source_currency'] ?? '')));
            $target = strtoupper(trim((string)($row['target_currency'] ?? '')));
            $createdAtRaw = trim((string)($row['created_at'] ?? ''));
            $refundedAtRaw = trim((string)($row['refunded_at'] ?? ''));
            $originalFeeRaw = trim((string)($row['original_fee'] ?? ''));
            $originalFinalRaw = trim((string)($row['original_final_amount'] ?? ''));

            $rowKey = "tx {$transactionId} client_id={$clientId}";

            if ($transactionId === '' || $clientId === '' || $amountRaw === '' || $source === '' || $target === '' || $createdAtRaw === '') {
                $stat['errors']++;
                $this->err("transactions.csv missing required fields", $row, $rowKey);
                continue;
            }

            if (!$this->isCurrencyCode($source) || !$this->isCurrencyCode($target)) {
                $stat['errors']++;
                $this->err("transactions.csv invalid currency code(s) source={$source} target={$target}", $row, $rowKey);
            }

            if (!$this->isPositiveMoney2($amountRaw)) {
                $stat['errors']++;
                $this->err("transactions.csv invalid amount={$amountRaw}", $row, $rowKey);
                continue;
            }

            $createdAt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $createdAtRaw);
            if (!$createdAt) {
                $stat['errors']++;
                $this->err("transactions.csv invalid created_at={$createdAtRaw}", $row, $rowKey);
                continue;
            }

            $refundedAt = null;
            if ($refundedAtRaw !== '') {
                $refundedAt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $refundedAtRaw) ?: null;
                if ($refundedAt === null) {
                    $stat['errors']++;
                    $this->err("transactions.csv invalid refunded_at={$refundedAtRaw}", $row, $rowKey);
                }
            }

            if ($refundedAt !== null && $refundedAt < $createdAt) {
                $stat['warnings']++;
                $this->warn("transactions.csv refunded_at < created_at", $row, $rowKey);
            }

            $originalFee = null;
            if ($originalFeeRaw !== '') {
                if (!$this->isMoney2($originalFeeRaw)) {
                    $stat['warnings']++;
                    $this->warn("transactions.csv original_fee invalid={$originalFeeRaw}", $row, $rowKey);
                } else {
                    $originalFee = $originalFeeRaw;
                }
            }

            $originalFinal = null;
            if ($originalFinalRaw !== '') {
                if (!$this->isMoney2($originalFinalRaw)) {
                    $stat['warnings']++;
                    $this->warn("transactions.csv original_final_amount invalid={$originalFinalRaw}", $row, $rowKey);
                } else {
                    $originalFinal = $originalFinalRaw;
                }
            }

            // Reference check:
            if ($dryRun) {
                if (!isset($this->dryRunClientIds[$clientId])) {
                    $stat['warnings']++;
                    $this->warn("transactions.csv missing client reference client_id={$clientId}", $row, $rowKey);
                }
                // dry-run: nie zapisujemy
                $stat['created']++;
                continue;
            }

            $tx = $this->transactions->findOneByTransactionId($transactionId);
            $isNew = false;
            if (!$tx) {
                $tx = new Transaction();
                $tx->setTransactionId($transactionId);
                $isNew = true;
            }

            $tx->setClientExternalId($clientId);

            $client = $this->clients->findOneByClientId($clientId);
            if (!$client) {
                $stat['warnings']++;
                $this->warn("transactions.csv missing client reference client_id={$clientId}", $row, $rowKey);
                $tx->setClient(null);
            } else {
                $tx->setClient($client);
            }

            $tx->setAmount($amountRaw);
            $tx->setSourceCurrency($source);
            $tx->setTargetCurrency($target);
            $tx->setCreatedAt($createdAt);
            $tx->setRefundedAt($refundedAt);
            $tx->setOriginalFee($originalFee);
            $tx->setOriginalFinalAmount($originalFinal);

            $this->em->persist($tx);
            $isNew ? $stat['created']++ : $stat['updated']++;

            $i++;
            if (($i % 500) === 0) {
                $this->safeFlush($stat, 'transactions');
                $this->em->clear();
            }
        }

        if (!$dryRun) {
            $this->safeFlush($stat, 'transactions');
            $this->em->clear();
        }
    }

    private function safeFlush(array &$stat, string $section): void
    {
        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException $e) {
            $stat['errors']++;
            $this->logger->error("DB unique constraint violation during flush ({$section})", ['exception' => $e->getMessage()]);
            $this->em->clear();
        } catch (\Throwable $e) {
            $stat['errors']++;
            $this->logger->error("DB error during flush ({$section})", ['exception' => $e->getMessage()]);
            $this->em->clear();
        }
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function readCsv(string $file): array
    {
        $fh = fopen($file, 'rb');
        if ($fh === false) {
            throw new \RuntimeException("Cannot open CSV: {$file}");
        }

        $header = null;
        $out = [];
        $line = 0;

        while (($data = fgetcsv($fh)) !== false) {
            $line++;

            if ($data === [null] || $data === false) {
                continue;
            }

            if ($header === null) {
                $header = array_map(static fn($h) => trim((string)$h), $data);
                continue;
            }

            $row = [];
            foreach ($header as $idx => $key) {
                $row[$key] = isset($data[$idx]) ? (string)$data[$idx] : '';
            }
            $row['_line'] = (string)$line;

            $out[] = $row;
        }

        fclose($fh);
        return $out;
    }

    private function warn(string $message, array $row, string $rowKey): void
    {
        $this->logger->warning($message, [
            'row_key' => $rowKey,
            'line' => $row['_line'] ?? null,
            'row' => $row,
        ]);
    }

    private function err(string $message, array $row, string $rowKey): void
    {
        $this->logger->error($message, [
            'row_key' => $rowKey,
            'line' => $row['_line'] ?? null,
            'row' => $row,
        ]);
    }

    private function isCurrencyCode(string $code): bool
    {
        return (bool)preg_match('/^[A-Z]{3}$/', $code);
    }

    private function isPositiveDecimal(string $value): bool
    {
        if (!preg_match('/^\d+(\.\d+)?$/', $value)) {
            return false;
        }
        return bccomp($value, '0', 10) === 1;
    }

    private function isMoney2(string $value): bool
    {
        return (bool)preg_match('/^\d+(\.\d{1,2})?$/', $value);
    }

    private function isPositiveMoney2(string $value): bool
    {
        if (!$this->isMoney2($value)) {
            return false;
        }
        return bccomp($value, '0', 2) === 1;
    }
}
