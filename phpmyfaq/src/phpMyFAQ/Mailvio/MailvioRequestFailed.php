<?php

/**
 * Raised when a request to the Mailvio API fails or Mailvio lacks the expected data.
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

use RuntimeException;
use Throwable;

class MailvioRequestFailed extends RuntimeException
{
    /**
     * @param int $httpStatus 0 when Mailvio did not answer
     * @param string $apiError the "error" text of Mailvio's answer, if any
     */
    public function __construct(
        string $message,
        public readonly int $httpStatus = 0,
        public readonly string $apiError = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
