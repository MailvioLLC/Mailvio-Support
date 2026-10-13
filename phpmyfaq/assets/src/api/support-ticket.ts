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
 * Rejected tickets come back with an error message for the visitor, so the body of a failed
 * response is returned as well; only a response without such a message is thrown.
 */
export const sendSupportTicket = async (data: FormData): Promise<ApiResponse> => {
  const response: Response = await fetch('api/support-ticket', {
    method: 'POST',
    cache: 'no-cache',
    headers: {
      'Content-Type': 'application/json',
    },
    body: JSON.stringify(serialize(data)),
    redirect: 'follow',
    referrerPolicy: 'no-referrer',
  });

  const result: ApiResponse | null = await response.json().catch(() => null);

  if (!response.ok && !result?.error) {
    throw new Error(`HTTP ${response.status}`);
  }

  return result ?? {};
};
