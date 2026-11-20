<?php

/**
 * Sends transactional mail through the Mailvio transaction API.
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

/**
 * Unlike mail relayed through Mailvio's SMTP server, which arrives without Reply-To and Cc, a mail sent
 * through the API keeps its reply address. An unsubscribed recipient is refused before anything is sent.
 *
 * @see https://github.com/MailvioLLC/mailvio-api/blob/master/docs/API/081-transactional-emails.md
 */
final readonly class MailvioApiTransactionalMailer implements TransactionalMailer
{
    private const string UNSUBSCRIBED_ERROR = 'Unsubscribed Previously';

    public function __construct(
        private MailvioApi $api,
    ) {
    }

    public function send(TransactionalMail $mail): void
    {
        try {
            $this->api->request('POST', '/transaction', ['json' => [
                'fromAddress' => $mail->fromAddress,
                'fromName' => $mail->fromName,
                'replyAddress' => $mail->replyAddress,
                'toAddress' => $mail->toAddress,
                'toName' => $mail->toName,
                'subject' => $mail->subject,
                'html' => $mail->html,
                'trackOpens' => false,
                'trackLinks' => false,
            ]]);
        } catch (MailvioRequestFailed $exception) {
            if (stripos($exception->apiError, self::UNSUBSCRIBED_ERROR) === false) {
                throw $exception;
            }

            throw new RecipientUnsubscribed(
                sprintf('Mailvio refused the mail to "%s": %s', $mail->toAddress, $exception->apiError),
                $exception->httpStatus,
                $exception->apiError,
                $exception,
            );
        }
    }
}
