<?php

/**
 * Posts alerts to a Zulip stream, the way the other Mailvio apps do.
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

namespace phpMyFAQ\Alert;

use phpMyFAQ\Configuration;
use phpMyFAQ\Environment;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The bot credentials come from phpmyfaq/.env (ZULIP_URL, ZULIP_BOT_EMAIL, ZULIP_BOT_API_KEY, and
 * the optional ZULIP_STREAM and ZULIP_TOPIC). Without them, alerts only go to the log. A configured
 * ZULIP_TOPIC collects every alert of this site in one topic; otherwise each alert names its own.
 */
final readonly class ZulipAlertNotifier implements AlertNotifier
{
    public const string DEFAULT_STREAM = 'error_logs';

    private const int MAX_CONTENT_LENGTH = 1500;

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private string $url,
        private string $botEmail,
        #[\SensitiveParameter]
        private string $botApiKey,
        private string $stream = self::DEFAULT_STREAM,
        private string $topic = '',
    ) {
    }

    public static function fromEnvironment(HttpClientInterface $httpClient, Configuration $configuration): self
    {
        return new self(
            $httpClient,
            $configuration->getLogger(),
            (string) Environment::get('ZULIP_URL', ''),
            (string) Environment::get('ZULIP_BOT_EMAIL', ''),
            (string) Environment::get('ZULIP_BOT_API_KEY', ''),
            self::streamOrDefault((string) Environment::get('ZULIP_STREAM', '')),
            (string) Environment::get('ZULIP_TOPIC', ''),
        );
    }

    private static function streamOrDefault(string $stream): string
    {
        return $stream !== '' ? $stream : self::DEFAULT_STREAM;
    }

    public function alert(string $topic, string $message): void
    {
        if ($this->url === '' || $this->botEmail === '' || $this->botApiKey === '') {
            $this->logger->warning(
                'Zulip alert not sent: set ZULIP_URL, ZULIP_BOT_EMAIL and ZULIP_BOT_API_KEY in phpmyfaq/.env. '
                . 'Alert: '
                . $message,
            );
            return;
        }

        try {
            $response = $this->httpClient->request('POST', rtrim($this->url, characters: '/') . '/api/v1/messages', [
                'auth_basic' => [$this->botEmail, $this->botApiKey],
                'body' => [
                    'type' => 'stream',
                    'to' => $this->stream,
                    'topic' => $this->topic !== '' ? $this->topic : $topic,
                    'content' => $this->truncate($message),
                ],
                'timeout' => 10,
            ]);

            if ($response->getStatusCode() !== 200) {
                $this->logger->error(sprintf(
                    'Zulip alert failed with HTTP %d: %s. Alert: %s',
                    $response->getStatusCode(),
                    $response->getContent(throw: false),
                    $message,
                ));
            }
        } catch (ExceptionInterface $exception) {
            $this->logger->error('Zulip alert failed: ' . $exception->getMessage() . '. Alert: ' . $message);
        }
    }

    private function truncate(string $message): string
    {
        $length = strlen($message);
        if ($length <= self::MAX_CONTENT_LENGTH) {
            return $message;
        }

        $suffix = sprintf("\n\n[Message truncated. Original length: %d bytes.]", $length);

        return (
            mb_strcut($message, start: 0, length: self::MAX_CONTENT_LENGTH - strlen($suffix), encoding: 'UTF-8')
            . $suffix
        );
    }
}
