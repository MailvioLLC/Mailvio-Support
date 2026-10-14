<?php

/**
 * The Support Ticket Controller: sends a private support request to the support inbox.
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

namespace phpMyFAQ\Controller\Frontend\Api;

use phpMyFAQ\Alert\AlertNotifier;
use phpMyFAQ\Controller\AbstractController;
use phpMyFAQ\Core\Exception;
use phpMyFAQ\Http\RateLimiter;
use phpMyFAQ\Mail;
use phpMyFAQ\Session\Token;
use phpMyFAQ\Translation;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Unlike a public question, a support ticket is never stored or published: it is mailed to the
 * administration address right away, with the visitor as reply-to address and in copy. When the mail
 * server refuses it, the team gets an alert.
 */
final class SupportTicketController extends AbstractController
{
    public const string CSRF_PAGE = 'support-ticket';

    public const string ALERT_TOPIC = 'Support site > Support tickets';

    private const int CREATE_LIMIT_PER_IP = 5;

    private const int CREATE_LIMIT_INTERVAL_SECONDS = 3600;

    private readonly RateLimiter $rateLimiter;

    public function __construct(
        private readonly Mail $mailer,
        private readonly AlertNotifier $alertNotifier,
        ?RateLimiter $rateLimiter = null,
    ) {
        $this->rateLimiter = $rateLimiter ?? new RateLimiter();
        parent::__construct();
    }

    /**
     * @throws \Exception
     */
    #[Route(path: 'support-ticket', name: 'api.private.support-ticket', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        if (!$this->rateLimiter->check(
            'support-ticket:create:ip:' . ($request->getClientIp() ?? 'anonymous'),
            self::CREATE_LIMIT_PER_IP,
            self::CREATE_LIMIT_INTERVAL_SECONDS,
        )) {
            return $this->json(['error' => 'Too many requests. Please retry later.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        try {
            $data = $request->toArray();
        } catch (JsonException) {
            return $this->json(['error' => 'The request body must be a JSON object'], Response::HTTP_BAD_REQUEST);
        }

        if (!Token::getInstance($this->session)->verifyToken(
            page: self::CSRF_PAGE,
            requestToken: $this->singleLine($data['pmf-csrf-token'] ?? ''),
        )) {
            return $this->json(['error' => Translation::get(key: 'ad_msg_noauth')], Response::HTTP_UNAUTHORIZED);
        }

        $name = $this->singleLine($data['name'] ?? '');
        $email = filter_var($this->singleLine($data['email'] ?? ''), FILTER_VALIDATE_EMAIL);
        $subject = $this->singleLine($data['subject'] ?? '');
        $message = trim(is_scalar($data['message'] ?? null) ? (string) $data['message'] : '');

        if ($name === '' || $email === false || $subject === '' || $message === '') {
            return $this->json([
                'error' => Translation::get(key: 'msgSupportTicketMissingFields'),
            ], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->captchaCodeIsValid($request)) {
            return $this->json(['error' => $this->captchaFailedMessage()], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->mailer->setReplyTo($email, $name);
            $this->mailer->addTo($this->configuration->getAdminEmail());
            // The mail is sent from the support address, so replying to all also reaches the visitor
            $this->mailer->addCc($email, $name);
            $this->mailer->subject = $subject;
            $this->mailer->message = $this->renderMessage($name, $email, $subject, $message);
            // Delivered right away, so the visitor learns about a failure instead of losing the ticket
            $this->mailer->send(forceSynchronousDelivery: true);
        } catch (Exception|TransportExceptionInterface $exception) {
            $this->configuration->getLogger()->error('Sending a support ticket failed: ' . $exception->getMessage());
            $this->alertNotifier->alert(self::ALERT_TOPIC, $this->renderAlert(
                $exception->getMessage(),
                $name,
                $email,
                $subject,
                $message,
            ));

            // Not 502: Cloudflare replaces the body of a 502 with its own error page
            return $this->json(['error' => sprintf(
                Translation::getString(key: 'msgSupportTicketSendFailed'),
                $this->configuration->getAdminEmail(),
            )], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $this->json(['success' => Translation::get(key: 'msgSupportTicketSent')], Response::HTTP_OK);
    }

    /**
     * Trims the value and drops line breaks, so it cannot inject mail headers.
     */
    private function singleLine(mixed $value): string
    {
        return trim(
            preg_replace(pattern: '/[\r\n]+/', replacement: ' ', subject: is_scalar($value) ? (string) $value : '')
            ?? '',
        );
    }

    /**
     * The visitor's input goes into a code block, so it cannot mention people or format the alert.
     */
    private function renderAlert(string $error, string $name, string $email, string $subject, string $message): string
    {
        $codeBlock = static fn(string $value): string => str_replace(search: '```', replace: "'''", subject: $value);

        return sprintf(
            ":warning: A support ticket could not be sent to %s. The visitor was asked to email us instead.\n\n"
            . "**Mail server error:** %s\n\n```text\nName: %s\nEmail: %s\nSubject: %s\n\n%s\n```",
            $this->configuration->getAdminEmail(),
            $codeBlock($error),
            $codeBlock($name),
            $codeBlock($email),
            $codeBlock($subject),
            $codeBlock($message),
        );
    }

    private function renderMessage(string $name, string $email, string $subject, string $message): string
    {
        $escape = static fn(string $value): string => htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_HTML5,
            encoding: 'UTF-8',
        );

        return sprintf(
            '%s: %s<br>%s: %s<br>%s: %s<br><br>%s',
            $escape(Translation::getString(key: 'msgSupportTicketName')),
            $escape($name),
            $escape(Translation::getString(key: 'msgSupportTicketEmail')),
            $escape($email),
            $escape(Translation::getString(key: 'msgSupportTicketSubject')),
            $escape($subject),
            nl2br($escape($message)),
        );
    }
}
