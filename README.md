# Disaster Recovery System (Symfony + Doctrine + PostgreSQL)

## Overview

This project rebuilds the core accounting and fee-calculation logic of a currency exchange system
after a **total system loss**. The original production database was irrecoverable; all business‑critical
data (transactions, rates, clients) was restored exclusively from CSV exports.

The system recalculates:
- FX conversions
- monthly volumes
- client tiers
- fees

with **high precision**, **explicit business rules**, and **full test coverage**.

The primary goals of this project are:

- correctness over convenience  
- auditability over performance shortcuts  
- explainability over assumptions  

Every important rule is documented, implemented explicitly, and verified by tests.

---

## Tech Stack

- PHP 8.3 (FPM, Alpine)
- Symfony 7
- Doctrine ORM + Doctrine Migrations
- PostgreSQL 16
- Docker & Docker Compose
- PHPUnit 12

---

## Architecture Overview

The domain logic is intentionally separated into small, testable services:

### Core Services

- **RateLookupService**
  - Resolves FX rates using:
    - direct pairs
    - inverse pairs
    - cross rates via EUR → USD → CHF pivots
  - Always selects the latest applicable rate for a given date

- **MonthlyVolumeCalculator**
  - Computes monthly transaction volume in EUR
  - Detects whether a client has transaction history for a month
  - Excludes transactions refunded within 72 hours
  - Performs all monetary arithmetic with explicit rounding rules

- **TierResolver**
  - Resolves client tier based on:
    - monthly volume
    - grace period rules
    - tier locks
  - Contains no database logic — depends only on abstractions

- **FeeCalculator**
  - Calculates final transaction fee based on:
    - resolved tier
    - currency‑specific rules (CHF floor)
    - FX conversion
  - Represents the final business decision layer

---

## Business Rules (Explicit)

### Tier Rules

- Tier lock overrides **everything**
- Grace period applies only if **all** conditions are met:
  - day of month ≤ 15
  - previous month tier was GOLD
  - previous month has transaction history
- Grace **never** applies for GOLD → BRONZE
- After day 15, grace is ignored completely

### Currency Rules

- CHF transactions enforce a minimum **SILVER** tier
- FX conversion must succeed; otherwise the transaction is skipped

### Volume Rules

- Monthly volume is calculated per calendar month
- Transactions refunded within **72 hours** are excluded
- Missing or invalid timestamps do **not** crash the calculation — rows are skipped

---

## Precision, Rounding & Epsilon

### Why precision matters

This system handles **"invisible money"**:
users only see 2 decimal places, but internal calculations
(FX rates, cross conversions, chained multiplications) require higher precision.

### Implementation choices

- Monetary amounts:
  - stored and calculated as **strings**
  - rounded **HALF‑UP to 2 decimals**
- FX rates:
  - handled with up to **8 decimal places**
- BCMath is used when available, with a safe float fallback

### Why epsilon exists (tests only)

Floating‑point math can introduce microscopic differences
(e.g. `0.99999999` vs `1.00000000`) even when logic is correct.

To avoid false‑negative tests:

- PHPUnit assertions compare values using **epsilon** (e.g. `1e‑6`)
- Epsilon is used **only in tests**
- Production logic never relies on tolerance or fuzzy comparisons

This ensures:
- deterministic business behavior
- stable and trustworthy tests

No undocumented assumptions were introduced beyond the task description.

---

## Makefile Usage (Single Source of Truth)

All interactions with the project are done through `make`.

### Start / Stop Containers
```bash
make up
make down
```

### Enter PHP container
```bash
make sh
```

### Install PHP dependencies
```bash
make install
```

### View logs
```bash
make logs
```

---

## Database Management

Reset database and run migrations:
```bash
make reset-db
```

---

## CSV Import

Import restored CSV data:
```bash
make import
```

---

## Console Commands

All Symfony console commands are executed via Makefile.

### Calculate fee for transaction
```bash
make calc-fee ARGS="T0001"
```

### Debug tier resolution
```bash
make debug-tier ARGS="C009 2024-01-10"
```

### Test FX rate resolution
```bash
make test-rate ARGS="USD CHF 2024-01-05"
```

### Generate discrepancy report
```bash
make discrepancies
```

---

## Testing

Run the full test suite:
```bash
make test
```

### Current Test Status

- 18 tests
- 41 assertions
- 0 errors
- 0 warnings
- 0 notices

Tests cover:
- FX rate resolution (direct / inverse / cross)
- rounding correctness
- monthly volume logic
- tier resolution with edge cases
- fee calculation rules

---

## Discrepancy Report

Remaining discrepancies are expected and documented:

- rounding differences vs legacy system
- missing historical data for grace
- legacy fee anomalies

These discrepancies are intentional and explained by the new,
explicit rule set.

---

## Final Notes

This system prioritizes:

- explicit logic over hidden assumptions
- correctness over backward compatibility
- transparency over convenience

Every non‑obvious decision is documented, tested,
and defensible in a technical review.

