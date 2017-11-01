<?php
/**
 * MIT License
 *
 * Copyright (c) 2017, Pentagonal
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NON INFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

declare(strict_types=1);

namespace Pentagonal\PhpEvaluator;

/**
 * Class Evaluator
 * @package Pentagonal\PhpEvaluator
 */
final class Evaluator
{
    const E_TAG      = E_STRICT;
    const E_BUFFER   = E_WARNING;
    const E_PARSE    = E_PARSE;
    const E_ERROR    = E_COMPILE_ERROR;

    /**
     * Suppress fallback
     *
     * @param bool $suppress
     */
    private function suppressErrorReport(bool $suppress = true)
    {
        static $error_reporting;
        static $log_errors;
        if (!isset($error_reporting)) {
            $error_reporting = error_reporting();
            $log_errors      = ini_get('log_errors');
        }

        $reporting = $suppress ? 0 : $error_reporting;
        $logging   = $suppress ? 'off' : $log_errors;
        error_reporting($reporting);
        ini_set('log_errors', $logging);
    }

    /**
     * Testing string with @uses token_get_all()
     *
     * @param string $content content to check
     *
     * @return array|bool
     */
    private function tokenTestReport(string $content)
    {
        // suppress warning
        $this->suppressErrorReport(true);
        token_get_all($content);
        // fall back to original
        $this->suppressErrorReport(false);
        // get last error
        $lastError = error_get_last();
        // if not empty & last error is current execution
        $trace = is_array($lastError)
                 && $lastError['file'] === __FILE__
                 && $lastError['line'] < __LINE__
                 && $lastError['line'] > (__LINE__ - 10) # on function
                 ? debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)
                 : null;
        $trace = !empty($trace[0]) ? $trace[0] : null;
        if (is_array($trace)
            && !empty($trace['class'])
            && $trace['class'] === __CLASS__
            && $trace['function'] === __FUNCTION__
            && $trace['file'] === __FILE__
            && !empty($lastError = error_get_last())
        ) {
            // clear last errors -> the token_get_all() error
            error_clear_last();
            return $lastError;
        }

        return false;
    }

    /**
     * Check & Validate php code by string, this only works on some certains conditions
     * because this is simple php code evaluator.
     *
     * @param string $content   string that php full of php code
     * @param string|null $file file to append as error file info
     *
     * @return bool
     * @throws BadSyntaxExceptions
     * @throws \Throwable
     */
    public static function check(string $content, string $file = null)
    {
        $suffix = '};';
        if (stripos($content, '<?php') !== 0) {
            throw new BadSyntaxExceptions(
                'Invalid open tag that not start with <?php or maybe contains white space',
                self::E_TAG,
                $file?:__FILE__,
                1
            );
        }

        $object = new self();

        /* ------------------------------------------------
         * TEST TOKEN
         * ---------------------------------------------- */
        if (is_array($lastError = $object->tokenTestReport($content))
            && !empty($lastError['message'])
        ) {
            $line = 0;
            // on last error get line
            if (preg_match('/(.*)\s*starting\s*line\s*([0-9]+)\s*$/i', $lastError['message'], $match)
                && !empty($match[2])
            ) {
                $lastError['message'] = $match[1];
                $lastError['line'] = $match[2];
            }

            throw new BadSyntaxExceptions(
                $lastError['message'],
                self::E_ERROR,
                $file?:__FILE__,
                $line
            );
        }

        if (($endTag = substr(rtrim($content), -2)) === '?>') {
            if (substr($content, -2) !== $endTag) {
                throw new BadSyntaxExceptions(
                    'File content buffer on after closing php tag',
                    self::E_BUFFER,
                    $file ?:__FILE__,
                    strrpos($content, '?>') + 1
                );
            }
            $suffix = "<?php\n{$suffix}";
        }

        try {
            // evaluate
            eval("return true; if (0) { ?>{$content}\n{$suffix}");
        } catch (\Throwable $e) {
            throw new BadSyntaxExceptions(
                $e->getMessage(),
                self::E_PARSE,
                $file ?:__FILE__,
                $e->getLine(),
                $e
            );
        }

        return true;
    }
}
