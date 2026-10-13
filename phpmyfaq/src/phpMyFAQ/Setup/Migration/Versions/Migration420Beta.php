<?php

/**
 * Migration for phpMyFAQ 4.2.0-beta.
 *
 * This Source Code Form is subject to the terms of the Mozilla Public License,
 * v. 2.0. If a copy of the MPL was not distributed with this file, You can
 * obtain one at https://mozilla.org/MPL/2.0/.
 *
 * @package   phpMyFAQ
 * @author    Thorsten Rinne <thorsten@phpmyfaq.de>
 * @copyright 2026 phpMyFAQ Team
 * @license   https://www.mozilla.org/MPL/2.0/ Mozilla Public License Version 2.0
 * @link      https://www.phpmyfaq.de
 * @since     2026-09-13
 */

declare(strict_types=1);

namespace phpMyFAQ\Setup\Migration\Versions;

use phpMyFAQ\Setup\Installation\DatabaseSchema;
use phpMyFAQ\Setup\Migration\AbstractMigration;
use phpMyFAQ\Setup\Migration\Operations\OperationRecorder;
use phpMyFAQ\Setup\Migration\QueryBuilder\DialectFactory;

readonly class Migration420Beta extends AbstractMigration
{
    public function getVersion(): string
    {
        return '4.2.0-beta';
    }

    public function getDependencies(): array
    {
        return ['4.2.0-alpha.2'];
    }

    public function getDescription(): string
    {
        return (
            'Add server-side expiry for remember-me tokens, store one remember-me token per browser, '
            . 'bind Microsoft Entra ID logins to the immutable object identifier, record the last '
            . 'accepted TOTP time slice, and add the upgrade.allowUnverifiedNightly configuration'
        );
    }

    /**
     * Every step is guarded by a column or table check so the migration stays safe to re-run.
     */
    public function up(OperationRecorder $recorder): void
    {
        if (!$this->columnExists('faquser', 'remember_me_expires')) {
            $recorder->addSql(
                $this->addColumn('faquser', 'remember_me_expires', $this->integerType() . ' NULL'),
                'Add remember_me_expires column to faquser',
            );
        }

        if (!$this->columnExists('faquserdata', 'entra_oid')) {
            $recorder->addSql(
                $this->addColumn('faquserdata', 'entra_oid', $this->varcharType(255) . ' NULL'),
                'Add entra_oid column to faquserdata',
            );

            $recorder->addSql(
                $this->createUniqueIndex('faquserdata', 'idx_faquserdata_entra_oid', 'entra_oid'),
                'Create unique entra_oid index on faquserdata',
            );
        }

        if (!$this->columnExists('faquserdata', 'twofactor_last_slice')) {
            $recorder->addSql(
                $this->addColumn('faquserdata', 'twofactor_last_slice', $this->integerType() . ' NULL'),
                'Add twofactor_last_slice column to faquserdata',
            );
        }

        if (!$this->tableExists('faquser_remember_tokens')) {
            $rememberTokens = new DatabaseSchema(DialectFactory::create())->faquserRememberTokens();
            $recorder->addSql($rememberTokens->build(), 'Create faquser_remember_tokens table');
            foreach ($rememberTokens->buildIndexStatements() as $indexStatement) {
                $recorder->addSql($indexStatement, 'Create user_id index on faquser_remember_tokens');
            }

            // Keep the browser that is remembered today logged in
            $recorder->addSql(
                sprintf(
                    'INSERT INTO %s (token_hash, user_id, expires) SELECT remember_me, user_id, remember_me_expires '
                    . 'FROM %s WHERE remember_me IS NOT NULL AND remember_me_expires IS NOT NULL',
                    $this->table('faquser_remember_tokens'),
                    $this->table('faquser'),
                ),
                'Move remember-me tokens to faquser_remember_tokens',
            );
        }

        // Nightly update packages without a published digest are refused unless opted in
        $recorder->addConfig('upgrade.allowUnverifiedNightly', 'false');
    }

    private function tableExists(string $table): bool
    {
        $tableName = $this->table($table);

        $query = match (true) {
            $this->isMySql() => sprintf("SHOW TABLES LIKE '%s'", $tableName),
            $this->isPostgreSql() => sprintf(
                "SELECT tablename FROM pg_catalog.pg_tables WHERE tablename = '%s'",
                $tableName,
            ),
            $this->isSqlite() => sprintf(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name = '%s'",
                $tableName,
            ),
            default => sprintf("SELECT name FROM sysobjects WHERE name = '%s' AND xtype = 'U'", $tableName),
        };

        $result = $this->configuration->getDb()->query($query);

        return $this->configuration->getDb()->numRows($result) > 0;
    }

    private function columnExists(string $table, string $column): bool
    {
        $tableName = $this->table($table);

        $query = match (true) {
            $this->isMySql() => sprintf("SHOW COLUMNS FROM %s LIKE '%s'", $tableName, $column),
            $this->isPostgreSql() => sprintf(
                "SELECT column_name FROM information_schema.columns WHERE table_name = '%s' AND column_name = '%s'",
                $tableName,
                $column,
            ),
            $this->isSqlite() => sprintf(
                "SELECT name FROM pragma_table_info('%s') WHERE name = '%s'",
                $tableName,
                $column,
            ),
            default => sprintf(
                "SELECT name FROM sys.columns WHERE object_id = OBJECT_ID('%s') AND name = '%s'",
                $tableName,
                $column,
            ),
        };

        $result = $this->configuration->getDb()->query($query);

        return $this->configuration->getDb()->numRows($result) > 0;
    }
}
