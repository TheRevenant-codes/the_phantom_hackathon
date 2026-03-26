-- ══════════════════════════════════════════════════════
-- PARASITE DATABASE SCHEMA
-- Run this entire file in phpMyAdmin SQL tab once
-- ══════════════════════════════════════════════════════

CREATE DATABASE IF NOT EXISTS parasite_db;
USE parasite_db;

-- ── USERS ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(100) NOT NULL,
    email         VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    phone         VARCHAR(30),
    session_token VARCHAR(255),
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ── LINKED BANK ACCOUNTS (via Mono) ───────────────────
CREATE TABLE IF NOT EXISTS accounts (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL,
    mono_account_id VARCHAR(100) UNIQUE,  -- Mono's account ID
    bank_name       VARCHAR(100),
    account_number  VARCHAR(20),
    account_name    VARCHAR(150),
    account_type    VARCHAR(50),
    balance         DECIMAL(18,2) DEFAULT 0,
    currency        VARCHAR(10) DEFAULT 'NGN',
    linked_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_synced     TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ── TRANSACTIONS (fetched from Mono) ──────────────────
CREATE TABLE IF NOT EXISTS transactions (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL,
    account_id      INT NOT NULL,
    mono_txn_id     VARCHAR(100) UNIQUE,  -- Mono's transaction ID
    merchant        VARCHAR(200),
    amount          DECIMAL(18,2) NOT NULL,
    type            VARCHAR(20),           -- debit / credit
    currency        VARCHAR(10) DEFAULT 'NGN',
    category        VARCHAR(80),
    narration       TEXT,
    txn_date        DATE,
    balance_after   DECIMAL(18,2),
    flagged         TINYINT(1) DEFAULT 0,
    flag_type       VARCHAR(50),           -- overcharge/subscription/cashback
    flag_reason     VARCHAR(300),
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id)    REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
);

-- ── SUBSCRIPTIONS (detected from transactions) ─────────
CREATE TABLE IF NOT EXISTS subscriptions (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT NOT NULL,
    merchant      VARCHAR(200) NOT NULL,
    amount        DECIMAL(18,2),
    currency      VARCHAR(10) DEFAULT 'NGN',
    frequency     VARCHAR(30) DEFAULT 'monthly',
    last_charged  DATE,
    next_renewal  DATE,
    status        VARCHAR(20) DEFAULT 'active',  -- active/cancelled
    detected_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ── CASHBACK CLAIMS ────────────────────────────────────
CREATE TABLE IF NOT EXISTS cashback_claims (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT NOT NULL,
    transaction_id INT,
    merchant      VARCHAR(200),
    amount        DECIMAL(18,2),
    currency      VARCHAR(10) DEFAULT 'NGN',
    claim_type    VARCHAR(50),   -- cashback/price_drop/refund
    status        VARCHAR(20) DEFAULT 'pending',  -- pending/confirmed/rejected
    claimed_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    confirmed_at  TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE SET NULL
);

-- ── DISPUTES ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS disputes (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    user_id        INT NOT NULL,
    transaction_id INT,
    merchant       VARCHAR(200),
    amount         DECIMAL(18,2),
    currency       VARCHAR(10) DEFAULT 'NGN',
    reason         TEXT,
    status         VARCHAR(20) DEFAULT 'filed',  -- filed/pending/won/rejected
    swarm_id       INT DEFAULT NULL,
    filed_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    resolved_at    TIMESTAMP NULL,
    FOREIGN KEY (user_id)        REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE SET NULL
);

-- ── SWARM CLUSTERS ─────────────────────────────────────
-- When same overcharge hits 100+ users = swarm
CREATE TABLE IF NOT EXISTS swarm_clusters (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    merchant       VARCHAR(200) NOT NULL,
    pattern_type   VARCHAR(100),
    amount_each    DECIMAL(18,2),
    affected_users INT DEFAULT 0,
    total_amount   DECIMAL(18,2) DEFAULT 0,
    status         VARCHAR(20) DEFAULT 'building',  -- building/filing/settled/rejected
    detected_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    resolved_at    TIMESTAMP NULL
);

-- ── RECOVERIES (money confirmed back to user) ──────────
CREATE TABLE IF NOT EXISTS recoveries (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT NOT NULL,
    dispute_id    INT,
    cashback_id   INT,
    source        VARCHAR(50),  -- dispute/cashback/subscription/swarm
    amount        DECIMAL(18,2),
    currency      VARCHAR(10) DEFAULT 'NGN',
    platform_fee  DECIMAL(18,2),  -- 10% of amount
    net_to_user   DECIMAL(18,2),  -- 90% of amount
    recovered_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ── INDEXES for fast queries ────────────────────────────
CREATE INDEX idx_txn_user    ON transactions(user_id);
CREATE INDEX idx_txn_account ON transactions(account_id);
CREATE INDEX idx_txn_flagged ON transactions(flagged);
CREATE INDEX idx_txn_date    ON transactions(txn_date);
CREATE INDEX idx_txn_merchant ON transactions(merchant);
CREATE INDEX idx_sub_user    ON subscriptions(user_id);
CREATE INDEX idx_dispute_user ON disputes(user_id);
CREATE INDEX idx_recovery_user ON recoveries(user_id);
CREATE INDEX idx_swarm_merchant ON swarm_clusters(merchant);
