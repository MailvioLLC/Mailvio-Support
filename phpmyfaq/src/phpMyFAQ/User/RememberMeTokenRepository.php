<?php

/**
 * Repository for remember-me tokens, one per browser a user ticked "remember me" in.
 *
 * This Source Code Form is subject to the terms of the Mozilla Public License,
 * v. 2.0. If a copy of the MPL was not distributed with this file, You can
 * obtain one at https://mozilla.org/MPL/2.0/.
 *
 * @package   phpMyFAQ\User
 * @author    Steven Mey <darkretailer@users.noreply.github.com>
 * @copyright 2026 phpMyFAQ Team
 * @license   https://www.mozilla.org/MPL/2.0/ Mozilla Public License Version 2.0
 * @link      https://www.phpmyfaq.de
 * @since     2026-10-13
 */

declare(strict_types=1);

namespace phpMyFAQ\User;

use phpMyFAQ\Configuration;
use phpMyFAQ\Database;

/**
 * Tokens are stored as SHA-256 hashes: the raw token only ever lives in the browser cookie.
 */
final readonly class RememberMeTokenRepository
{
    public function __construct(
        private Configuration $configuration,
    ) {
    }

    /**
     * Stores a new token for the user and drops the user's expired ones, so a login in one
     * browser leaves the tokens of the user's other browsers untouched.
     */
    public function add(int $userId, string $tokenHash, int $expires, int $now): bool
    {
        $this->removeExpired($userId, $now);

        $insert = sprintf(
            "INSERT INTO %s (token_hash, user_id, expires) VALUES ('%s', %d, %d)",
            $this->table(),
            $this->configuration->getDb()->escape($tokenHash),
            $userId,
            $expires,
        );

        return (bool) $this->configuration->getDb()->query($insert);
    }

    /**
     * Revokes a single token, e.g. the one of the browser that logs out.
     */
    public function remove(string $tokenHash): bool
    {
        $delete = sprintf(
            "DELETE FROM %s WHERE token_hash = '%s'",
            $this->table(),
            $this->configuration->getDb()->escape($tokenHash),
        );

        return (bool) $this->configuration->getDb()->query($delete);
    }

    /**
     * Revokes the tokens of every browser of the user.
     */
    public function removeAllOfUser(int $userId): bool
    {
        $delete = sprintf('DELETE FROM %s WHERE user_id = %d', $this->table(), $userId);

        return (bool) $this->configuration->getDb()->query($delete);
    }

    private function removeExpired(int $userId, int $now): void
    {
        $delete = sprintf('DELETE FROM %s WHERE user_id = %d AND expires < %d', $this->table(), $userId, $now);

        $this->configuration->getDb()->query($delete);
    }

    private function table(): string
    {
        return Database::getTablePrefix() . 'faquser_remember_tokens';
    }
}
