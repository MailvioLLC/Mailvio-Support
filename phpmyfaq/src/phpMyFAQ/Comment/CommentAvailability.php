<?php

/**
 * Whether a visitor can comment on a FAQ, and if not, why.
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
 * @since     2026-10-13
 */

declare(strict_types=1);

namespace phpMyFAQ\Comment;

use phpMyFAQ\Enums\FaqStatus;

enum CommentAvailability
{
    case Open;

    /** Comments are open on the FAQ, but only for registered users */
    case LogInRequired;

    case Closed;

    public static function forFaq(
        string $status,
        string $commentFlag,
        bool $isExpired,
        bool $visitorIsGuest,
        bool $guestsMayComment,
    ): self {
        if ($status !== FaqStatus::Published->value || $commentFlag === 'n' || $isExpired) {
            return self::Closed;
        }

        if ($visitorIsGuest && !$guestsMayComment) {
            return self::LogInRequired;
        }

        return self::Open;
    }
}
