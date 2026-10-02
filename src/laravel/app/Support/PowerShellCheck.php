<?php

namespace App\Support;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Checks PowerShell code before a script is saved: with the PowerShell parser when pwsh is
 * installed on the server (MDM_PWSH, or pwsh in PATH), otherwise with a light check of its
 * structure (unterminated strings, here-strings and block comments, unbalanced brackets).
 */
class PowerShellCheck
{
    /** The syntax errors ("line 3: ..."), empty when the code parses. */
    public static function errors(string $code): array
    {
        return self::parse($code) ?? self::lex($code)['errors'];
    }

    /** Whether the code (outside comments and strings) has an "exit <code>". */
    public static function exits(string $code, int $exitCode): bool
    {
        return (bool) preg_match('/(?<![\w$-])exit\s*\(?\s*'.$exitCode.'(?!\w)/i', self::lex($code)['code']);
    }

    /** The PowerShell parser of pwsh, null without pwsh (or when it does not answer). */
    private static function parse(string $code): ?array
    {
        $pwsh = config('mdm.pwsh') ?: (new ExecutableFinder)->find('pwsh');
        if (! $pwsh) {
            return null;
        }
        $command = '$code = [Console]::In.ReadToEnd(); $errors = $null; '
            .'[void][System.Management.Automation.Language.Parser]::ParseInput($code, [ref]$null, [ref]$errors); '
            .'foreach ($e in $errors) { "line $($e.Extent.StartLineNumber): $($e.Message)" }';
        try {
            $process = new Process([$pwsh, '-NoLogo', '-NoProfile', '-NonInteractive', '-Command', $command]);
            $process->setInput($code)->setTimeout(20)->run();
            if (! $process->isSuccessful()) {
                return null;
            }

            return array_values(array_filter(array_map('trim', explode("\n", $process->getOutput()))));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Walks the code: comments and strings are blanked out in the returned code (so "exit 1" in a
     * comment does not count), brackets must pair up, strings and comments must end.
     *
     * @return array{errors: array<int, string>, code: string}
     */
    public static function lex(string $code): array
    {
        $code = str_replace("\r\n", "\n", $code);
        $length = strlen($code);
        $out = '';
        $errors = [];
        // Open brackets and double-quoted strings: [char, line]; '"' is a string, '$(' its subexpression.
        $stack = [];
        $line = 1;
        $pairs = [')' => ['(', '$('], '}' => ['{'], ']' => ['[']];
        $blank = fn (string $text) => preg_replace('/[^\n]/', ' ', $text);

        for ($i = 0; $i < $length; $i++) {
            $char = $code[$i];
            $next = $code[$i + 1] ?? '';
            $top = $stack === [] ? null : $stack[count($stack) - 1][0];

            if ($top === '"') {
                // Inside "...": `x escapes, "" is a quote, $( opens a subexpression.
                if ($char === '`') {
                    $out .= '  ';
                    $line += substr_count($char.$next, "\n");
                    $i++;
                } elseif ($char === '"' && $next === '"') {
                    $out .= '  ';
                    $i++;
                } elseif ($char === '"') {
                    array_pop($stack);
                    $out .= '"';
                } elseif ($char === '$' && $next === '(') {
                    $stack[] = ['$(', $line];
                    $out .= '$(';
                    $i++;
                } else {
                    $out .= $char === "\n" ? "\n" : ' ';
                    $line += $char === "\n" ? 1 : 0;
                }

                continue;
            }

            if ($char === "\n") {
                $out .= "\n";
                $line++;

                continue;
            }
            if ($char === '`') {
                // Escape or line continuation.
                $out .= $next === "\n" ? " \n" : '  ';
                $line += $next === "\n" ? 1 : 0;
                $i++;

                continue;
            }
            if ($char === '<' && $next === '#') {
                $end = strpos($code, '#>', $i + 2);
                if ($end === false) {
                    $errors[] = __('line :line: the block comment <# is not closed with #>', ['line' => $line]);
                    $end = $length - 2;
                }
                $text = substr($code, $i, $end + 2 - $i);
                $out .= $blank($text);
                $line += substr_count($text, "\n");
                $i = $end + 1;

                continue;
            }
            if ($char === '#' && ($i === 0 || preg_match('/[\s;|&(){}\[\]]/', $code[$i - 1]))) {
                $end = strpos($code, "\n", $i);
                $end = $end === false ? $length : $end;
                $out .= $blank(substr($code, $i, $end - $i));
                $i = $end - 1;

                continue;
            }
            if ($char === '@' && ($next === '"' || $next === "'") && preg_match('/\G[ \t]*\n/', $code, $m, 0, $i + 2)) {
                // Here-string: ends with "@ or '@ at the start of a line.
                $close = "\n".$next.'@';
                $end = strpos($code, $close, $i + 2);
                if ($end === false) {
                    $errors[] = __('line :line: the here-string :open is not closed with :close at the start of a line', ['line' => $line, 'open' => '@'.$next, 'close' => $next.'@']);
                    $end = $length - 3;
                }
                $text = substr($code, $i, $end + 3 - $i);
                $out .= $blank($text);
                $line += substr_count($text, "\n");
                $i = $end + 2;

                continue;
            }
            if ($char === "'") {
                // '...' with '' as a quote.
                $j = $i + 1;
                while (true) {
                    $end = strpos($code, "'", $j);
                    if ($end === false) {
                        $errors[] = __('line :line: the string \' is not closed', ['line' => $line]);
                        $end = $length - 1;
                        break;
                    }
                    if (($code[$end + 1] ?? '') === "'") {
                        $j = $end + 2;

                        continue;
                    }
                    break;
                }
                $text = substr($code, $i, $end + 1 - $i);
                $out .= $blank($text);
                $line += substr_count($text, "\n");
                $i = $end;

                continue;
            }
            if ($char === '"') {
                $stack[] = ['"', $line];
                $out .= '"';

                continue;
            }
            if (in_array($char, ['(', '{', '['], true)) {
                $stack[] = [$char, $line];
            } elseif (isset($pairs[$char])) {
                if ($top === null || ! in_array($top, $pairs[$char], true)) {
                    $errors[] = $top === null
                        ? __('line :line: :char has no opening bracket', ['line' => $line, 'char' => $char])
                        : __('line :line: :char does not close :open of line :opened', ['line' => $line, 'char' => $char, 'open' => $top, 'opened' => $stack[count($stack) - 1][1]]);
                } else {
                    array_pop($stack);
                }
            }
            $out .= $char;
        }

        foreach (array_reverse($stack) as [$open, $opened]) {
            $errors[] = $open === '"'
                ? __('line :line: the string " is not closed', ['line' => $opened])
                : __('line :line: :open is not closed', ['line' => $opened, 'open' => $open]);
        }

        return ['errors' => $errors, 'code' => $out];
    }
}
