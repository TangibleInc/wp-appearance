<?php

declare(strict_types=1);

namespace Tangible\WP\Appearance\Tests;

use PHPUnit\Framework\TestCase;
use Tangible\WP\Appearance\PreviewRegistry;

final class PreviewRegistryTest extends TestCase {
    protected function setUp(): void {
        unset($GLOBALS['__wp_test_filters'], $GLOBALS['__wp_test_doing_it_wrong']);
    }

    protected function tearDown(): void {
        unset($GLOBALS['__wp_test_filters'], $GLOBALS['__wp_test_doing_it_wrong']);
    }

    public function testNothingRegisteredIsAnEmptyList(): void {
        $this->assertSame([], PreviewRegistry::collect());
    }

    public function testPreviewsAreEnqueuedAndRenderedInRegistrationOrder(): void {
        $log = [];
        add_filter(PreviewRegistry::FILTER, static function (array $previews) use (&$log): array {
            $previews[] = [
                'id' => 'quiz',
                'label' => 'Quiz',
                'enqueue' => static function () use (&$log): void { $log[] = 'enqueue quiz'; },
                'render' => static function () use (&$log): string {
                    $log[] = 'render quiz';

                    return '<div data-quiz-player></div>';
                },
            ];

            return $previews;
        });
        add_filter(PreviewRegistry::FILTER, static function (array $previews): array {
            $previews[] = ['id' => 'lms', 'label' => ' Courses ', 'render' => static fn (): string => '<article>card</article>'];

            return $previews;
        }, 20);

        $this->assertSame([
            ['id' => 'quiz', 'label' => 'Quiz', 'html' => '<div data-quiz-player></div>'],
            ['id' => 'lms', 'label' => 'Courses', 'html' => '<article>card</article>'],
        ], PreviewRegistry::collect());
        $this->assertSame(['enqueue quiz', 'render quiz'], $log);
    }

    public function testADuplicateIdKeepsTheFirst(): void {
        add_filter(PreviewRegistry::FILTER, static fn (array $p): array => [
            ...$p,
            ['id' => 'quiz', 'label' => 'First', 'render' => static fn (): string => '1'],
            ['id' => 'quiz', 'label' => 'Second', 'render' => static fn (): string => '2'],
        ]);

        $this->assertSame([['id' => 'quiz', 'label' => 'First', 'html' => '1']], PreviewRegistry::collect());
    }

    /**
     * A broken registration is one plugin's bug; the page — and the other
     * plugins' previews — carry on, with a notice for whoever is looking.
     */
    public function testAnInvalidEntryIsDroppedWithANoticeNotAFatal(): void {
        add_filter(PreviewRegistry::FILTER, static fn (array $p): array => [
            ...$p,
            ['id' => 'Bad Id', 'label' => 'x', 'render' => static fn (): string => ''],
            ['id' => 'nolabel', 'label' => '  ', 'render' => static fn (): string => ''],
            ['id' => 'norender', 'label' => 'x'],
            ['id' => 'badenqueue', 'label' => 'x', 'render' => static fn (): string => '', 'enqueue' => 'not callable'],
            'not even an array',
            ['id' => 'fine', 'label' => 'Fine', 'render' => static fn (): string => '<p>ok</p>'],
        ]);

        $this->assertSame([['id' => 'fine', 'label' => 'Fine', 'html' => '<p>ok</p>']], PreviewRegistry::collect());
        $this->assertCount(5, $GLOBALS['__wp_test_doing_it_wrong']);
    }

    /**
     * Well-formed but broken at call time is still one plugin's bug: its
     * preview is lost, the others render, the page stands.
     */
    public function testAPreviewThatThrowsOrReturnsANonStringIsSkippedNotFatal(): void {
        add_filter(PreviewRegistry::FILTER, static fn (array $p): array => [
            ...$p,
            ['id' => 'boom', 'label' => 'Boom', 'render' => static function (): string { throw new \RuntimeException('nope'); }],
            ['id' => 'enqboom', 'label' => 'Boom', 'enqueue' => static function (): void { throw new \LogicException('no assets'); }, 'render' => static fn (): string => 'x'],
            ['id' => 'array', 'label' => 'Array', 'render' => static fn (): array => ['not', 'a', 'string']],
            ['id' => 'fine', 'label' => 'Fine', 'render' => static fn (): string => '<p>ok</p>'],
        ]);

        $this->assertSame([['id' => 'fine', 'label' => 'Fine', 'html' => '<p>ok</p>']], PreviewRegistry::collect());
        $this->assertCount(3, $GLOBALS['__wp_test_doing_it_wrong']);
    }

    public function testAFilterThatReturnsGarbageYieldsNoPreviews(): void {
        add_filter(PreviewRegistry::FILTER, static fn (): string => 'oops');

        $this->assertSame([], PreviewRegistry::collect());
    }
}
