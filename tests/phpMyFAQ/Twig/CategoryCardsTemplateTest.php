<?php

declare(strict_types=1);

namespace phpMyFAQ\Twig;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

#[CoversNothing]
final class CategoryCardsTemplateTest extends TestCase
{
    public function testCategoryCardsEscapeContentAndDoNotRequireImages(): void
    {
        $twig = new Environment(new FilesystemLoader(PMF_ROOT_DIR . '/assets/templates/default'));
        $html = $twig->render('partials/category-cards.twig', [
            'categories' => [
                [
                    'name' => '<script>alert(1)</script>',
                    'description' => '<strong>Sending</strong> guides',
                    'url' => '/category/3/getting-started.html',
                    'image' => '/unused.png',
                ],
                ['name' => 'General', 'url' => '/category/2/general.html'],
            ],
        ]);

        self::assertStringContainsString('href="/category/3/getting-started.html"', $html);
        self::assertStringContainsString('Sending guides', $html);
        self::assertStringContainsString('General', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<strong>', $html);
        self::assertStringNotContainsString('/unused.png', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertSame(1, substr_count($html, '<p class="mv-category-card__description">'));
    }
}
