<?php

/**
 * A single transactional mail to one recipient.
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
 * @since     2026-11-20
 */

declare(strict_types=1);

namespace phpMyFAQ\Mailvio;

use InvalidArgumentException;

/**
 * Mailvio shows the sender name in front of the reply address as well, so the sender name should be
 * the name of whoever a reply reaches.
 */
final readonly class TransactionalMail
{
    /**
     * @throws InvalidArgumentException when an address is invalid or the subject or body is empty
     */
    public function __construct(
        public string $fromAddress,
        public string $fromName,
        public string $replyAddress,
        public string $toAddress,
        public string $toName,
        public string $subject,
        public string $html,
    ) {
        foreach ([
            'fromAddress' => $fromAddress,
            'replyAddress' => $replyAddress,
            'toAddress' => $toAddress,
        ] as $field => $address) {
            if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                throw new InvalidArgumentException(sprintf('%s "%s" is not a valid email address.', $field, $address));
            }
        }

        if ($subject === '' || $html === '') {
            throw new InvalidArgumentException('A transactional mail needs a subject and a body.');
        }
    }
}
