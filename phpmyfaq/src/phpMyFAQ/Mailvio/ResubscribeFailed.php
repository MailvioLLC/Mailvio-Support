<?php

/**
 * Raised when an email address could not be re-subscribed through the Mailvio API.
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

final class ResubscribeFailed extends RuntimeException {}
