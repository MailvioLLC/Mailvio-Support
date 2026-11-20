/**
 * Support ticket API functionality
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

import { serialize } from '../utils';
import { ApiResponse } from '../interfaces';

/**
 * Mailvio refused the visitor's address because it unsubscribed: the ticket waits on the server
 * under this id until the visitor re-subscribes.
 */
export interface UnsubscribedTicket {
  email: string;
  ticketId: string;
  resubscribeLabel: string;
}

export interface SupportTicketResponse extends ApiResponse {
  unsubscribed?: UnsubscribedTicket;
}

const postJson = async (url: string, body: unknown): Promise<SupportTicketResponse> => {
  const response: Response = await fetch(url, {
    method: 'POST',
    cache: 'no-cache',
    headers: {
      'Content-Type': 'application/json',
    },
    body: JSON.stringify(body),
    redirect: 'follow',
    referrerPolicy: 'no-referrer',
  });

  const result: SupportTicketResponse | null = await response.json().catch(() => null);

  if (!response.ok && !result?.error) {
    throw new Error(`HTTP ${response.status}`);
  }

  return result ?? {};
};

/**
 * Rejected tickets come back with an error message for the visitor, so the body of a failed
 * response is returned as well; only a response without such a message is thrown.
 */
export const sendSupportTicket = async (data: FormData): Promise<SupportTicketResponse> =>
  postJson('api/support-ticket', serialize(data));

/**
 * Re-subscribes the address of a ticket held on the server, which then sends that ticket.
 */
export const resubscribeSupportTicket = async (csrfToken: string, ticketId: string): Promise<SupportTicketResponse> =>
  postJson('api/support-ticket/resubscribe', { 'pmf-csrf-token': csrfToken, ticketId });
