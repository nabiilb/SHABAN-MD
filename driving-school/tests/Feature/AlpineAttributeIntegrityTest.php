<?php

namespace Tests\Feature;

use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * That the Alpine expressions survive being HTML attributes.
 *
 * An Alpine component is written inside a double-quoted HTML attribute, and
 * HTML ends an attribute value at the first double quote it meets — it knows
 * nothing about JavaScript strings and honours no backslash. So one
 * `querySelector('meta[name="csrf-token"]')` in the middle of an x-data ends
 * the attribute there, and everything after it becomes stray markup.
 *
 * The page still renders. The button still draws. Alpine gets half an object
 * literal, throws "Invalid or unexpected token", and every method on the
 * component is gone — so "+ Add Student" is visible and does nothing, with
 * nothing on the page to say why. That is the shape of the bug this guards,
 * and it is invisible to every test that only looks at the server.
 */
class AlpineAttributeIntegrityTest extends TestCase
{
    use RefreshDatabase;

    /** Attributes whose value is evaluated as JavaScript. */
    private const JS_ATTRIBUTES = '(?:x-data|x-init|x-show|x-if|x-text|x-html|x-model[\w.]*|x-effect|'
        .'x-on:[\w.:-]+|@[\w.:-]+|x-bind:[\w.:-]+|:[\w.-]+)';

    /**
     * Every Alpine expression on a rendered page closes.
     *
     * Rendered, not the template: Blade's own `@js()` writes a double quote as
     * \u0022 precisely so it is safe in an attribute, so the source can look
     * broken where the page is fine. What the browser parses is the only thing
     * worth checking, and it is the thing that was broken.
     */
    public function test_rendered_pages_carry_whole_alpine_expressions(): void
    {
        $this->seedReferenceData();

        $teacher = $this->makeUser(Role::INSTRUCTOR);
        $this->makeInstructor('Xasan Teacher', $teacher);
        $admin = $this->makeUser(Role::ADMIN);

        $pages = [
            [$teacher, 'instructor.training.index'],
            [$teacher, 'instructor.dashboard'],
            [$admin, 'admin.dashboard'],
            [$admin, 'admin.students.index'],
            [$admin, 'admin.training.index'],
        ];

        foreach ($pages as [$user, $route]) {
            $html = $this->actingAs($user)->get(route($route))->assertOk()->getContent();

            foreach ($this->jsAttributes($html) as [$name, $value, $line]) {
                $this->assertTrue(
                    $this->isBalanced($value),
                    sprintf(
                        '%s line %d: %s="…%s" does not close — HTML cut the value at a quote.',
                        $route, $line, $name, mb_substr(trim($value), -70),
                    ),
                );
            }
        }
    }

    public function test_the_training_console_ships_a_whole_alpine_component(): void
    {
        $this->seedReferenceData();

        $teacher = $this->makeUser(Role::INSTRUCTOR);
        $this->makeInstructor('Xasan Teacher', $teacher);

        $html = $this->actingAs($teacher)->get(route('instructor.training.index'))
            ->assertOk()
            ->getContent();

        $queue = $this->withoutBlade((string) $this->componentContaining($html, 'openAdd'));

        $this->assertNotSame('', $queue, 'The waiting-queue component is not in the page at all.');

        // Everything the dialog needs has to be inside the one attribute: if
        // the value were cut short the later methods would simply be absent.
        foreach (['openAdd', 'searchStudents', 'startEdit', 'saveRemaining', 'cancelEdit', 'pickedName'] as $method) {
            $this->assertStringContainsString($method, $queue, "{$method} is missing from the component.");
        }

        $this->assertTrue($this->isBalanced($queue), 'The component expression does not close.');

        // And the button that opens it is still wired to it.
        $this->assertStringContainsString('openAdd()', $html);
    }

    /* ------------------------------------------------------------------ */

    /**
     * Every JavaScript-valued attribute in a template, as HTML reads it: from
     * the opening quote to the very next one.
     *
     * @return array<int, array{0: string, 1: string, 2: int}>
     */
    private function jsAttributes(string $markup): array
    {
        preg_match_all(
            '/\s('.self::JS_ATTRIBUTES.')="([^"]*)"/',
            $markup,
            $matches,
            PREG_OFFSET_CAPTURE | PREG_SET_ORDER,
        );

        return array_map(fn ($match) => [
            $match[1][0],
            $match[2][0],
            substr_count(substr($markup, 0, $match[0][1]), "\n") + 1,
        ], $matches);
    }

    /**
     * The same attribute with Blade's own constructs taken out.
     *
     * `@js(__('…'))` and `{{ … }}` are not JavaScript and do not have to
     * balance as JavaScript: Blade replaces them with a value before the
     * browser ever sees them. Leaving them in would report a template that is
     * perfectly correct.
     */
    private function withoutBlade(string $value): string
    {
        $value = preg_replace('/\{\{.*?\}\}|\{!!.*?!!\}/s', "'x'", $value) ?? $value;

        // A directive and its arguments, parentheses nested to any depth.
        return preg_replace('/@\w+(\((?:[^()]++|(?1))*\))?/', "'x'", $value) ?? $value;
    }

    /** The x-data expression of the component mentioning a given method. */
    private function componentContaining(string $html, string $needle): ?string
    {
        foreach ($this->jsAttributes($html) as [$name, $value]) {
            if ($name === 'x-data' && str_contains($value, $needle)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Whether an expression's brackets close.
     *
     * Quotes and comments are stepped over so that a brace inside a string or
     * a `//` note does not look like structure. An expression HTML cut short
     * is left with something open, which is exactly what is being looked for.
     */
    private function isBalanced(string $expression): bool
    {
        $pairs = ['}' => '{', ']' => '[', ')' => '('];
        $stack = [];
        $length = mb_strlen($expression);

        for ($i = 0; $i < $length; $i++) {
            $character = mb_substr($expression, $i, 1);

            // A string, in any of the three flavours JavaScript has.
            if (in_array($character, ["'", '`'], true)) {
                $i++;

                while ($i < $length && mb_substr($expression, $i, 1) !== $character) {
                    $i += mb_substr($expression, $i, 1) === '\\' ? 2 : 1;
                }

                continue;
            }

            if ($character === '/' && mb_substr($expression, $i + 1, 1) === '/') {
                while ($i < $length && mb_substr($expression, $i, 1) !== "\n") {
                    $i++;
                }

                continue;
            }

            if ($character === '/' && mb_substr($expression, $i + 1, 1) === '*') {
                $close = mb_strpos($expression, '*/', $i);

                if ($close === false) {
                    return false;
                }

                $i = $close + 1;

                continue;
            }

            if (in_array($character, ['{', '[', '('], true)) {
                $stack[] = $character;

                continue;
            }

            if (isset($pairs[$character])) {
                if (array_pop($stack) !== $pairs[$character]) {
                    return false;
                }
            }
        }

        return $stack === [];
    }
}
