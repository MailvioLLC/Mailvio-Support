<?php

/**
 * The phpMyFAQ Cloudflare Turnstile class.
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

namespace phpMyFAQ\Captcha;

/**
 * Verifies Turnstile tokens against Cloudflare's siteverify endpoint. Each token is single-use:
 * Cloudflare rejects a token that was already verified.
 */
class Turnstile implements CaptchaInterface
{
    public const string SITEVERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /** Environment variable that holds the secret key, set in the gitignored phpmyfaq/.env file */
    public const string SECRET_KEY_ENVIRONMENT_VARIABLE = 'TURNSTILE_SECRET_KEY';

    private bool $userIsLoggedIn = false;

    public function __construct(
        private readonly string $secretKey,
    ) {
    }

    public function checkCaptchaCode(string $code): bool
    {
        if ($this->isUserIsLoggedIn()) {
            return true;
        }

        if ($this->secretKey === '' || $code === '') {
            return false;
        }

        $response = $this->postForm(self::SITEVERIFY_URL, [
            'secret' => $this->secretKey,
            'response' => $code,
        ]);

        if (!is_string($response) || $response === '') {
            return false;
        }

        try {
            return $this->isSuccess(json_decode($response, associative: true, depth: 512, flags: JSON_THROW_ON_ERROR));
        } catch (\JsonException) {
            return false;
        }
    }

    private function isSuccess(mixed $siteverifyResult): bool
    {
        return is_array($siteverifyResult) && ($siteverifyResult['success'] ?? false) === true;
    }

    /**
     * Posts the fields form-encoded, as siteverify only accepts POST requests.
     *
     * @param array<string, string> $fields
     */
    protected function postForm(string $url, array $fields): string|false
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => 'Content-Type: application/x-www-form-urlencoded',
                'content' => http_build_query($fields, encoding_type: PHP_QUERY_RFC3986),
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]);

        $response = false;
        set_error_handler(static fn() => true);
        try {
            $response = file_get_contents($url, use_include_path: false, context: $context);
        } finally {
            restore_error_handler();
        }

        return $response;
    }

    public function isUserIsLoggedIn(): bool
    {
        return $this->userIsLoggedIn;
    }

    public function setUserIsLoggedIn(bool $userIsLoggedIn): Turnstile
    {
        $this->userIsLoggedIn = $userIsLoggedIn;
        return $this;
    }
}
