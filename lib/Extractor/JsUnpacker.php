<?php
declare(strict_types=1);

/**
 * These hosts hide the real file url behind the same handful of tricks: a packed
 * script, escaped string literals, or a url chopped into concatenated pieces.
 */
final class JsUnpacker
{
    private const ALPHABET = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    /**
     * Decode the Dean Edwards p,a,c,k,e,d packer.
     *   eval(function(p,a,c,k,e,d){while(c--)if(k[c])p=p.replace(...)}(
     *       '<packed>',36,299,'|||a|b|c'.split('|')))
     * Returns the original source when the input is not packed.
     */
    public static function unpack(string $js): string
    {
        $pattern = '#\}\s*\(\s*([\'"])((?:\\\\.|(?!\1).)*)\1\s*,\s*(\d+)\s*,\s*(\d+)\s*,\s*([\'"])((?:\\\\.|(?!\5).)*)\5\s*\.\s*split\s*\(\s*([\'"])\|\7\s*\)#s';
        $out = $js;
        $guard = 0;
        while ($guard++ < 6 && preg_match($pattern, $out, $m)) {
            $packed = stripcslashes($m[2]);
            $radix = (int) $m[3];
            $count = (int) $m[4];
            $words = explode('|', stripcslashes($m[6]));
            if ($radix < 2 || $radix > 62 || $count < 1 || count($words) < 2) {
                break;
            }
            $decoded = self::unpackArgs($packed, $radix, $words, min($count, count($words)));
            $needleAt = (int) strpos($out, $m[0]);
            $evalAt = strrpos(substr($out, 0, $needleAt), 'eval(');
            $from = $evalAt === false ? $needleAt : $evalAt;
            $to = $needleAt + strlen($m[0]);
            // Swallow the rest of the eval(...) call, including the trailing ))
            $closer = strpos($out, '))', $needleAt);
            if ($closer !== false && $closer - $to < 8) {
                $to = $closer + 2;
            }
            $out = substr($out, 0, $from) . $decoded . substr($out, $to);
        }
        return $out;
    }

    private static function unpackArgs(string $packed, int $radix, array $words, int $count): string
    {
        $out = $packed;
        for ($i = $count - 1; $i >= 0; $i--) {
            if (!isset($words[$i]) || $words[$i] === '') {
                continue;
            }
            $token = self::base($i, $radix);
            $replacement = str_replace(['\\', '$'], ['\\\\', '\\$'], $words[$i]);
            // Mirrors the packer's \b..\b: a token only stands alone between word chars.
            $out = preg_replace(
                '/(?<![A-Za-z0-9_])' . preg_quote($token, '/') . '(?![A-Za-z0-9_])/',
                $replacement,
                $out
            ) ?? $out;
        }
        return $out;
    }

    private static function base(int $num, int $radix): string
    {
        if ($num === 0) {
            return '0';
        }
        $s = '';
        while ($num > 0) {
            $s = self::ALPHABET[$num % $radix] . $s;
            $num = intdiv($num, $radix);
        }
        return $s;
    }

    /** \x41, \u0041, %41 and json escaped string literals. */
    public static function unescape(string $js): string
    {
        $out = preg_replace_callback('#\\\\x([0-9a-fA-F]{2})#', static fn($m) => chr((int) hexdec($m[1])), $js);
        $out = preg_replace_callback('#\\\\u([0-9a-fA-F]{4})#', static fn($m) => mb_chr((int) hexdec($m[1]), 'UTF-8') ?? '', (string) $out);
        // A config handed over as a json string carries \/ and \" that the url
        // regexes would otherwise read as a truncated address.
        $out = preg_replace('#\\\\(/|"|\\?)#', '$1', (string) $out);
        return (string) preg_replace_callback('#%([0-9a-fA-F]{2})#', static function ($m) {
            $c = chr((int) hexdec($m[1]));
            return in_array($c, ['/', ':', '?', '&', '=', '.', '-', '_'], true) ? $c : $m[0];
        }, (string) $out);
    }

    /** All three hiding tricks at once, the shape a reader wants before it looks for urls. */
    public static function flatten(string $html): string
    {
        return self::joinConcatenations(self::unpack(self::unescape($html)));
    }

    /**
     * Reassemble urls built by string concatenation, e.g.
     *   "https://host/" + tok + "/file" + ".mp4"
     * Each candidate run is emitted on its own line so url regexes see it whole.
     */
    public static function joinConcatenations(string $js): string
    {
        $extra = [];
        $len = strlen($js);
        $i = 0;
        while ($i < $len) {
            $quote = $js[$i];
            if ($quote !== "'" && $quote !== '"' && $quote !== '`') {
                $i++;
                continue;
            }
            $j = $i + 1;
            $run = '';
            $pieces = [];
            while ($j < $len && $js[$j] !== $quote) {
                if ($js[$j] === '\\') {
                    $run .= $js[$j + 1] ?? '';
                    $j += 2;
                    continue;
                }
                $run .= $js[$j];
                $j++;
            }
            $pieces[] = $run;
            // Keep eating ` + <ident-or-literal> ` while it stays on one line.
            $k = $j + 1;
            while ($k < $len) {
                while ($k < $len && ($js[$k] === ' ' || $js[$k] === "\t")) {
                    $k++;
                }
                if (($js[$k] ?? '') !== '+') {
                    break;
                }
                $k++;
                while ($k < $len && ($js[$k] === ' ' || $js[$k] === "\t")) {
                    $k++;
                }
                $q2 = $js[$k] ?? '';
                if ($q2 !== "'" && $q2 !== '"' && $q2 !== '`') {
                    // Non-literal tail (a variable): stop the run, emit what we have.
                    break;
                }
                $m2 = strpos($js, $q2, $k + 1);
                if ($m2 === false) {
                    break;
                }
                $piece = substr($js, $k + 1, $m2 - $k - 1);
                if (str_contains($piece, "\n")) {
                    break;  // the run left the line, so it is not one expression
                }
                $pieces[] = stripcslashes($piece);
                $k = $m2 + 1;
            }
            if (count($pieces) > 1) {
                $extra[] = implode('', $pieces);
            }
            $i = $j + 1;
        }
        return ($extra ? implode("\n", $extra) . "\n" : '') . $js;
    }
}
