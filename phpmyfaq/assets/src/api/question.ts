/**
 * Private Question API functionality
 *
 * This Source Code Form is subject to the terms of the Mozilla Public License,
 * v. 2.0. If a copy of the MPL was not distributed with this file, You can
 * obtain one at https://mozilla.org/MPL/2.0/.
 *
 * @package   phpMyFAQ
 * @author    Thorsten Rinne <thorsten@phpmyfaq.de>
 * @copyright 2024-2026 phpMyFAQ Team
 * @license   https://www.mozilla.org/MPL/2.0/ Mozilla Public License Version 2.0
 * @link      https://www.phpmyfaq.de
 * @since     2024-03-16
 */

import { serialize } from '../utils';
import { ApiResponse } from '../interfaces';

/**
 * A rejected question (e.g. a failed captcha) comes back with an error message for the visitor, so
 * the body of a failed response is returned as well; only a response without such a message is thrown.
 */
export const createQuestion = async (data: FormData): Promise<ApiResponse> => {
  const response: Response = await fetch('api/question/create', {
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
