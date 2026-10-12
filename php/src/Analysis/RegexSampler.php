<?php

declare(strict_types=1);

namespace Testor\Analysis;

/**
 * Builds one string matching a PCRE pattern, for the common subset used in validation code
 * (literals, classes, escapes, groups, alternation, quantifiers). The result is checked with
 * preg_match(): null when the pattern is unsupported or the sample does not match.
 */
final class RegexSampler
{
    /**
     * Characters tried, in order, for character classes and negated escapes. Lookaheads are not
     * honoured while building, so other orders are tried when the first sample is rejected
     * (e.g. a password pattern requiring a digit).
     */
    private const PROBE_ORDERS = [
        ['a', 'b', 'x', 'A', 'Z', '1', '0', '9', '_', '-', '.', '@', ' ', '/', ':', '+', '#'],
        ['1', '0', '9', 'a', 'A', '_', '-', '.', '@', ' ', '/', ':', '+', '#'],
        ['A', 'Z', 'a', '1', '_', '-', '.', '@', ' ', '/', ':', '+', '#'],
    ];

    private string $body;
    private int $pos;
    private string $flags;

    /** @param list<string> $probes */
    private function __construct(private readonly array $probes)
    {
    }

    public static function sample(string $pattern): ?string
    {
        foreach (self::PROBE_ORDERS as $probes) {
            $result = (new self($probes))->build($pattern);
            if ($result === null) {
                return null; // unsupported syntax: other orders won't help
            }
            if (@preg_match($pattern, $result) === 1) {
                return $result;
            }
        }
        return null;
    }

    private function build(string $pattern): ?string
    {
        if ($pattern === '' || ctype_alnum($pattern[0]) || $pattern[0] === '\\') {
            return null;
        }
        $close = ['(' => ')', '[' => ']', '{' => '}', '<' => '>'][$pattern[0]] ?? $pattern[0];
        $end = strrpos($pattern, $close);
        if ($end === false || $end === 0) {
            return null;
        }
        $this->flags = substr($pattern, $end + 1);
        if (str_contains($this->flags, 'x')) {
            return null; // extended syntax (comments, ignored whitespace): not supported
        }
        $this->body = substr($pattern, 1, $end - 1);
        $this->pos = 0;
        try {
            $result = $this->alternation();
        } catch (\UnexpectedValueException) {
            return null;
        }
        return $this->pos === strlen($this->body) ? $result : null;
    }

    /** First branch of an alternation (the others are skipped). */
    private function alternation(): string
    {
        $first = $this->sequence();
        while ($this->peek() === '|') {
            $this->pos++;
            $this->sequence();
        }
        return $first;
    }

    private function sequence(): string
    {
        $out = '';
        while ($this->pos < strlen($this->body) && !in_array($this->peek(), ['|', ')'], true)) {
            $atom = $this->atom();
            $out .= str_repeat($atom, $this->quantifier());
        }
        return $out;
    }

    private function atom(): string
    {
        $c = $this->body[$this->pos++];
        return match ($c) {
            '^', '$' => '',
            '.' => 'a',
            '[' => $this->charClass(),
            '(' => $this->group(),
            '\\' => $this->escape(),
            '*', '+', '?', '{' => throw new \UnexpectedValueException('dangling quantifier'),
            default => $c,
        };
    }

    private function group(): string
    {
        if ($this->peek() === '?') {
            $kind = substr($this->body, $this->pos, 3);
            if (str_starts_with($kind, '?:')) {
                $this->pos += 2;
            } elseif (preg_match('/^\?P?<(\w+)>/', substr($this->body, $this->pos), $m)) {
                $this->pos += strlen($m[0]);
            } elseif (in_array(substr($kind, 0, 2), ['?=', '?!'], true) || in_array($kind, ['?<=', '?<!'], true)) {
                // Lookaround: consumes nothing; preg_match() validates the result anyway.
                $this->pos += $kind[1] === '<' ? 3 : 2;
                $this->alternation();
                $this->expect(')');
                return '';
            } else {
                throw new \UnexpectedValueException('unsupported group');
            }
        }
        $result = $this->alternation();
        $this->expect(')');
        return $result;
    }

    private function escape(): string
    {
        $c = $this->body[$this->pos++] ?? throw new \UnexpectedValueException('trailing backslash');
        return match ($c) {
            'd' => '1',
            'w' => 'a',
            's' => ' ',
            'D', 'W', 'S' => $this->probe('\\' . $c),
            'p', 'P' => $this->probe('\\' . $c . $this->unicodeProperty()),
            'b', 'B', 'A', 'z', 'Z', 'G' => '',
            'n' => "\n",
            't' => "\t",
            'r' => "\r",
            default => ctype_alnum($c) ? throw new \UnexpectedValueException("escape \\$c") : $c,
        };
    }

    /** "{Lu}" or "L" after \p. */
    private function unicodeProperty(): string
    {
        if (preg_match('/^(\{\^?\w+\}|\w)/', substr($this->body, $this->pos), $m) !== 1) {
            throw new \UnexpectedValueException('unicode property');
        }
        $this->pos += strlen($m[0]);
        return $m[0];
    }

    /** A character matching [ ... ]: the first probe the class accepts. */
    private function charClass(): string
    {
        $start = $this->pos - 1;
        if ($this->peek() === '^') {
            $this->pos++;
        }
        if ($this->peek() === ']') {
            $this->pos++; // literal ] first in the class
        }
        while ($this->pos < strlen($this->body) && $this->body[$this->pos] !== ']') {
            if (substr($this->body, $this->pos, 2) === '[:' && ($close = strpos($this->body, ':]', $this->pos)) !== false) {
                $this->pos = $close + 2; // POSIX class: [:alpha:]
                continue;
            }
            $this->pos += $this->body[$this->pos] === '\\' ? 2 : 1;
        }
        $this->expect(']');
        $class = substr($this->body, $start, $this->pos - $start);
        // First literal of a plain range ([a-z] => a) when no probe fits, else a probe.
        return $this->probe($class);
    }

    private function probe(string $class): string
    {
        foreach ($this->probes as $char) {
            if (@preg_match('/^' . $class . '$/' . $this->flags, $char) === 1) {
                return $char;
            }
        }
        throw new \UnexpectedValueException("no probe for $class");
    }

    /** Repetitions: ?, * and + once; {n}, {n,} and {n,m} n times (at least once when allowed). */
    private function quantifier(): int
    {
        $c = $this->peek();
        $count = 1;
        if ($c === '?' || $c === '*' || $c === '+') {
            $this->pos++;
        } elseif ($c === '{' && preg_match('/^\{(\d+)(,(\d*))?\}/', substr($this->body, $this->pos), $m)) {
            $this->pos += strlen($m[0]);
            $min = (int) $m[1];
            $max = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : null;
            $count = $min > 0 ? $min : ($max === 0 ? 0 : 1);
        } else {
            return 1;
        }
        if (in_array($this->peek(), ['?', '+'], true)) {
            $this->pos++; // lazy or possessive modifier
        }
        return $count;
    }

    private function peek(): ?string
    {
        return $this->body[$this->pos] ?? null;
    }

    private function expect(string $char): void
    {
        if ($this->peek() !== $char) {
            throw new \UnexpectedValueException("expected $char");
        }
        $this->pos++;
    }
}
