<?php

/**
 * Sends requests to the Mailvio REST API (apiv2.mailvio.com).
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

use phpMyFAQ\Environment;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The API key comes from phpmyfaq/.env (MAILVIO_API_KEY) and must belong to the Mailvio account the site
 * sends from.
 *
 * @see https://github.com/MailvioLLC/mailvio-api/tree/master/docs/API
 */
final readonly class MailvioApi
{
    public const string DEFAULT_BASE_URL = 'https://apiv2.mailvio.com';

    public function __construct(
        private HttpClientInterface $httpClient,
        #[\SensitiveParameter]
        private string $apiKey,
        private string $baseUrl = self::DEFAULT_BASE_URL,
    ) {
    }

    public static function fromEnvironment(HttpClientInterface $httpClient): self
    {
        return new self($httpClient, (string) Environment::get('MAILVIO_API_KEY', ''));
    }

    /**
     * @param array<string, mixed> $options Symfony HttpClient options, such as query or json
     * @return array<array-key, mixed> the decoded JSON answer
     * @throws MailvioRequestFailed when the request fails or Mailvio answers with an error
     */
    public function request(string $method, string $path, array $options = []): array
    {
        if ($this->apiKey === '') {
            throw new MailvioRequestFailed('Cannot reach the Mailvio API: set MAILVIO_API_KEY in phpmyfaq/.env.');
        }

        try {
            $response = $this->httpClient->request($method, rtrim($this->baseUrl, characters: '/') . $path, [
                ...$options,
                'headers' => ['x-access-token' => $this->apiKey],
                'timeout' => 10,
            ]);
            $status = $response->getStatusCode();
            $body = $response->getContent(throw: false);
        } catch (ExceptionInterface $exception) {
            throw new MailvioRequestFailed(
                sprintf('Mailvio API %s %s failed: %s', $method, $path, $exception->getMessage()),
                previous: $exception,
            );
        }

        $decoded = array_filter([json_decode($body, associative: true)], is_array(...))[0] ?? [];

        if ($status < 200 || $status >= 300) {
            throw new MailvioRequestFailed(
                sprintf(
                    'Mailvio API %s %s answered HTTP %d: %s',
                    $method,
                    $path,
                    $status,
                    mb_strimwidth($body, start: 0, width: 300, trim_marker: '…'),
                ),
                $status,
                is_string($decoded['error'] ?? null) ? $decoded['error'] : '',
            );
        }

        return $decoded;
    }
}
