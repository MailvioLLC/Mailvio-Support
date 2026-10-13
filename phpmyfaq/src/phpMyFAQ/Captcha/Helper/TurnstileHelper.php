<?php

/**
 * Helper class for the Cloudflare Turnstile captcha.
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

namespace phpMyFAQ\Captcha\Helper;

use phpMyFAQ\Captcha\CaptchaInterface;
use phpMyFAQ\Configuration;
use phpMyFAQ\Helper\AbstractHelper;

/**
 * Renders the Turnstile widget. The widget adds the token as the hidden "cf-turnstile-response"
 * field to the surrounding form. It needs no label: there is no code to enter, and the widget
 * labels itself.
 */
class TurnstileHelper extends AbstractHelper implements CaptchaHelperInterface
{
    public const string SCRIPT_URL = 'https://challenges.cloudflare.com/turnstile/v0/api.js';

    public function __construct(
        protected Configuration $configuration,
    ) {
    }

    public function renderCaptcha(
        CaptchaInterface $captcha,
        string $action = '',
        string $label = '',
        bool $auth = false,
    ): string {
        if (true !== $this->configuration->get(item: 'spam.enableCaptchaCode') || $auth) {
            return '';
        }

        $html = '<div class="row mb-2">';
        $html .= '    <div class="col-sm-9 offset-sm-3">';
        $html .= sprintf('        <script src="%s" async defer></script>', self::SCRIPT_URL);
        $html .= sprintf('<div class="cf-turnstile" data-sitekey="%s"></div>', htmlspecialchars(
            (string) $this->configuration->get(item: 'security.turnstileSiteKey'),
            ENT_QUOTES,
        ));
        $html .= '    </div>';
        $html .= '</div>';

        return $html;
    }
}
