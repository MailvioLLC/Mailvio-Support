<?php

/**
 * Redirects URLs of the former Zoho Desk help center to this site.
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

namespace phpMyFAQ\Controller\Frontend;

use phpMyFAQ\Category;
use phpMyFAQ\Faq\Statistics;
use phpMyFAQ\Link\Util\TitleSlugifier;
use phpMyFAQ\Seo\LegacyHelpdeskRedirect;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class LegacyHelpdeskController extends AbstractFrontController
{
    private const int MAX_FAQS = 50_000;

    public function __construct(
        private readonly Statistics $faqStatistics,
        private readonly Category $category,
    ) {
        parent::__construct();
    }

    #[Route(
        path: '/portal/{legacyPath}',
        name: 'public.legacy-helpdesk.redirect',
        requirements: ['legacyPath' => '.*'],
        defaults: ['legacyPath' => ''],
        methods: ['GET', 'HEAD'],
    )]
    public function redirect(Request $request): Response
    {
        $target = $this->createRedirect()->targetFor((string) $request->attributes->get('legacyPath', ''));

        if ($target === null) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        if (!str_starts_with($target, 'http')) {
            $target = $this->configuration->getDefaultUrl() . $target;
        }

        return new RedirectResponse($target, Response::HTTP_MOVED_PERMANENTLY);
    }

    private function createRedirect(): LegacyHelpdeskRedirect
    {
        $faqUrlsByTitle = [];
        foreach ($this->faqStatistics->getTopTenData(self::MAX_FAQS) as $faq) {
            $faqUrlsByTitle[$faq['question']] = $faq['url'];
        }

        $categoryUrlsByName = [];
        foreach ($this->category->getAllCategories() as $id => $category) {
            $name = (string) ($category['name'] ?? '');
            $categoryUrlsByName[$name] = sprintf(
                '%scategory/%d/%s.html',
                $this->configuration->getDefaultUrl(),
                $id,
                TitleSlugifier::slug($name),
            );
        }

        return new LegacyHelpdeskRedirect($faqUrlsByTitle, $categoryUrlsByName);
    }
}
