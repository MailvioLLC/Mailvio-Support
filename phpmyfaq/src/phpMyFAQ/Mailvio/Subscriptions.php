<?php

/**
 * Subscription state of an email address in the Mailvio account the site sends from.
 *
 * This Source Code Form is subject to the terms of the Mozilla Public License,
 * v. 2.0. If a copy of the MPL was not distributed with this file, You can
 * obtain one at https://mozilla.org/MPL/2.0/.
 *
 * @package   phpMyFAQ
 * @author    Steven Mey <darkretailer@users.noreply.github.com>
 * @copyright 2026 phpMyFAQ Team
 * @license   https://www.mozilla.org/MPL/2.0/ Mozilla Public License Version 2.0
 * @link      https://www.phpmyfaq.de
 * @since     2026-11-19
 */

declare(strict_types=1);

namespace phpMyFAQ\Mailvio;

interface Subscriptions
{
    /**
     * Mailvio refuses transactional mail, support tickets included, to such an address.
     *
     * @throws MailvioRequestFailed when the state could not be looked up
     */
    public function isUnsubscribedFromTransactionalEmails(string $emailAddress): bool;

    /**
     * @throws MailvioRequestFailed when the address could not be re-subscribed
     */
    public function resubscribe(string $emailAddress): void;
}
