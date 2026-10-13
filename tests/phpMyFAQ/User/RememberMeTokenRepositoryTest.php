<?php

namespace phpMyFAQ\User;

use phpMyFAQ\Configuration;
use phpMyFAQ\Database;
use phpMyFAQ\Database\Sqlite3;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RememberMeTokenRepository::class)]
class RememberMeTokenRepositoryTest extends TestCase
{
    private string $databaseFile;

    private Configuration $configuration;

    private RememberMeTokenRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        Database::setTablePrefix('');

        $this->databaseFile = tempnam(sys_get_temp_dir(), 'pmf-remember-tokens-');
        self::assertNotFalse($this->databaseFile);

        $dbHandle = new Sqlite3();
        $dbHandle->connect($this->databaseFile, '', '');
        $dbHandle->query(
            'CREATE TABLE faquser_remember_tokens (token_hash VARCHAR(64) NOT NULL PRIMARY KEY, '
            . 'user_id INTEGER NOT NULL, expires INTEGER NOT NULL)',
        );

        $this->configuration = new Configuration($dbHandle);
        $this->repository = new RememberMeTokenRepository($this->configuration);
    }

    protected function tearDown(): void
    {
        $this->configuration->getDb()->close();
        @unlink($this->databaseFile);

        parent::tearDown();
    }

    public function testAddKeepsTheValidTokensOfTheUsersOtherBrowsers(): void
    {
        $this->assertTrue($this->repository->add(1, 'first', 2_000, 1_000));
        $this->assertTrue($this->repository->add(1, 'second', 2_000, 1_000));

        $this->assertSame(['first', 'second'], $this->tokenHashes());
    }

    public function testAddDropsTheExpiredTokensOfTheUser(): void
    {
        $this->repository->add(1, 'expired', 999, 500);
        $this->repository->add(2, 'expired-other-user', 999, 500);

        $this->repository->add(1, 'fresh', 3_000, 1_000);

        $this->assertSame(['expired-other-user', 'fresh'], $this->tokenHashes());
    }

    public function testRemoveRevokesOnlyTheGivenToken(): void
    {
        $this->repository->add(1, 'first', 2_000, 1_000);
        $this->repository->add(1, 'second', 2_000, 1_000);

        $this->assertTrue($this->repository->remove('first'));

        $this->assertSame(['second'], $this->tokenHashes());
    }

    public function testRemoveAllOfUserRevokesEveryBrowserOfThatUserOnly(): void
    {
        $this->repository->add(1, 'first', 2_000, 1_000);
        $this->repository->add(1, 'second', 2_000, 1_000);
        $this->repository->add(2, 'other-user', 2_000, 1_000);

        $this->assertTrue($this->repository->removeAllOfUser(1));

        $this->assertSame(['other-user'], $this->tokenHashes());
    }

    /**
     * @return string[]
     */
    private function tokenHashes(): array
    {
        $result = $this->configuration->getDb()->query('SELECT token_hash FROM faquser_remember_tokens ORDER BY token_hash');

        return array_column($this->configuration->getDb()->fetchAll($result) ?? [], 'token_hash');
    }
}
