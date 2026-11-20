<?php

/**
 * Support tickets that wait in the visitor's session until the visitor re-subscribes.
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

use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * A ticket stays on the server, so the visitor can only re-subscribe the address of a ticket they
 * sent in this session, never an address they pick afterwards.
 */
final readonly class PendingSupportTickets
{
    private const string SESSION_KEY = 'pmf-support-ticket.pending';

    /** Older tickets are dropped, so the session cannot grow without bound. */
    private const int MAX_TICKETS = 5;

    public function __construct(
        private SessionInterface $session,
    ) {
    }

    /**
     * @return string the id the visitor sends back to release the ticket
     */
    public function hold(SupportTicket $ticket): string
    {
        $id = bin2hex(random_bytes(16));
        $tickets = [...$this->all(), $id => $ticket->toArray()];
        $this->session->set(self::SESSION_KEY, array_slice($tickets, -self::MAX_TICKETS, preserve_keys: true));

        return $id;
    }

    public function find(string $id): ?SupportTicket
    {
        return SupportTicket::fromArray($this->all()[$id] ?? null);
    }

    public function release(string $id): void
    {
        $tickets = $this->all();
        unset($tickets[$id]);
        $this->session->set(self::SESSION_KEY, $tickets);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function all(): array
    {
        // Anything but an array in the session counts as no tickets
        return array_filter([$this->session->get(self::SESSION_KEY)], is_array(...))[0] ?? [];
    }
}
