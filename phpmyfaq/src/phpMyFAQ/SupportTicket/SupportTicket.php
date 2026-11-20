<?php

/**
 * A support request a visitor sends through the support ticket form.
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

namespace phpMyFAQ\SupportTicket;

use InvalidArgumentException;

final readonly class SupportTicket
{
    /**
     * @throws InvalidArgumentException when a field is empty or the email address is invalid
     */
    public function __construct(
        public string $name,
        public string $email,
        public string $subject,
        public string $message,
    ) {
        if ($name === '' || $subject === '' || $message === '') {
            throw new InvalidArgumentException('A support ticket needs a name, a subject and a message.');
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException(sprintf('"%s" is not a valid email address.', $email));
        }
    }

    /**
     * Restores a ticket stored with toArray(); returns null for anything else.
     */
    public static function fromArray(mixed $data): ?self
    {
        if (!is_array($data)) {
            return null;
        }

        $field = static fn(string $key): string => is_string($data[$key] ?? null) ? $data[$key] : '';

        try {
            return new self($field('name'), $field('email'), $field('subject'), $field('message'));
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @return array{name: string, email: string, subject: string, message: string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'email' => $this->email,
            'subject' => $this->subject,
            'message' => $this->message,
        ];
    }
}
