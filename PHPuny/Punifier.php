<?php
/**
 * PHPuny
 *
 * Derived From:
 * =============
 * -package JShrink
 * -author Robert Hafner <tedivm@tedivm.com>
 * -license http://www.opensource.org/licenses/bsd-license.php  BSD License
 */

namespace PHPuny;

// Regular expression to match all valid php variable identifiers
// http://php.net/language.variables.basics
define('REGEX_VARIABLE', '\$([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)');
define('REGEX_CONSTANT', 'define *\([\'|"]([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)[\'|"]');
define('REGEX_FUNCTION', 'function *([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)');

/**
 * Punifier
 *
 * Usage - Punifier::punify($php);
 * Usage - Punifier::punify($php, $options);
 * Usage - Punifier::punify($php, array('flaggedComments' => false));
 *
 * @package PHPuny
 * @author Charlie McClung <charlie@cmr1.com>
 * @license http://www.opensource.org/licenses/bsd-license.php  BSD License
 */
class Punifier
{
    /**
     * The input php to be punified.
     *
     * @var string
     */
    protected $input;

    /**
     * The location of the character (in the input string) that is next to be
     * processed.
     *
     * @var int
     */
    protected $index = 0;

    /**
     * The first of the characters currently being looked at.
     *
     * @var string
     */
    protected $a = '';

    /**
     * The next character being looked at (after a);
     *
     * @var string
     */
    protected $b = '';

    /**
     * This character is only active when certain look ahead actions take place.
     *
     * @var string
     */
    protected $c;

    /**
     * Contains the options for the current punification process.
     *
     * @var array
     */
    protected $options;

    /**
     * Contains the default options for punification. This array is merged with
     * the one passed in by the user to create the request specific set of
     * options (stored in the $options attribute).
     *
     * @var array
     */
    protected static $defaultOptions = array('flaggedComments' => true);

    /**
     * Contains lock ids which are used to replace certain code patterns and
     * prevent them from being punified
     *
     * @var array
     */
    protected $locks = array();

    /**
     * @var string
     */
    protected $nextShort = 'a';

    /**
     * List of reserved PHP variables (do not shorten these names)
     * http://php.net/manual/en/reserved.variables.php
     *
     * @var array
     */
    protected static $reservedVariables = array(
        'GLOBALS',
        '_SERVER',
        '_GET',
        '_POST',
        '_FILES',
        '_REQUEST',
        '_SESSION',
        '_ENV',
        '_COOKIE',
        'php_errormsg',
        'HTTP_RAW_POST_DATA',
        'http_response_header',
        'argc',
        'argv',
        'this',
        '__construct',
        '__destruct',
    );

    /**
     * List of reserved PHP constants (do not shorten these names)
     * http://php.net/manual/en/reserved.constants.php
     *
     * @var array
     */
    protected static $reservedConstants = array(
        '__CLASS__',
        '__DIR__',
        '__FILE__',
        '__FUNCTION__',
        '__LINE__',
        '__METHOD__',
        '__NAMESPACE__',
        '__TRAIT__'
    );

    /**
     * List of reserved PHP keywords (do not use these as short names)
     * http://php.net/manual/en/reserved.keywords.php
     *
     * @var array
     */
    protected static $reservedKeywords = array(
        'abstract',
        'and',
        'array',
        'as',
        'break',
        'callable',
        'case',
        'catch',
        'class',
        'clone',
        'const',
        'continue',
        'declare',
        'default',
        'die',
        'do',
        'echo',
        'else',
        'elseif',
        'empty',
        'enddeclare',
        'endfor',
        'endforeach',
        'endif',
        'endswitch',
        'endwhile',
        'eval',
        'exit',
        'extends',
        'final',
        'for',
        'foreach',
        'function',
        'global',
        'goto',
        'if',
        'implements',
        'include',
        'include_once',
        'instanceof',
        'insteadof',
        'interface',
        'isset',
        'list',
        'namespace',
        'new',
        'or',
        'print',
        'private',
        'protected',
        'public',
        'require',
        'require_once',
        'return',
        'static',
        'switch',
        'throw',
        'trait',
        'try',
        'unset',
        'use',
        'var',
        'while',
        'xor'
    );

    /**
     * Array housing regular expressions for pulling out tokens
     *
     * @var array
     */
    protected static $tokenRegexes = array(
        'func' => REGEX_FUNCTION,
        'var' => REGEX_VARIABLE,
        'const' => REGEX_CONSTANT,
    );

    /**
     * Takes a string containing php and removes unneeded characters in
     * order to shrink the code without altering it's functionality.
     *
     * @param  string $php The raw php to be punified
     * @param  array $options Various runtime options in an associative array
     * @throws \Exception
     * @return bool|string
     */
    public static function punify($php, $options = array())
    {
        try {
            ob_start();

            $phpuny = new Punifier();
            $php = $phpuny->lock($php);
            $phpuny->punifyDirectToOutput($php, $options);

            // Sometimes there's a leading new line, so we trim that out here.
            $php = ltrim(ob_get_clean());
            $php = $phpuny->unlock($php);
            unset($phpuny);

            return $php;

        } catch (\Exception $e) {

            if (isset($phpuny)) {
                // Since the breakdownScript function probably wasn't finished
                // we clean it out before discarding it.
                $phpuny->clean();
                unset($phpuny);
            }

            // without this call things get weird, with partially outputted php.
            ob_end_clean();
            throw $e;
        }
    }

    /**
     * Processes a php string and outputs only the required characters,
     * stripping out all unneeded characters.
     *
     * @param string $php The raw php to be punified
     * @param array $options Various runtime options in an associative array
     */
    protected function punifyDirectToOutput($php, $options)
    {
        $this->initialize($php, $options);
        $this->loop();
        $this->clean();
    }

    /**
     * Initializes internal variables, normalizes new lines,
     *
     * @param string $php The raw php to be punified
     * @param array $options Various runtime options in an associative array
     */
    protected function initialize($php, $options)
    {
        $this->options = array_merge(static::$defaultOptions, $options);
        $this->input = $this->prepareInput($php);

        // We add a newline to the end of the script to make it easier to deal
        // with comments at the bottom of the script- this prevents the unclosed
        // comment error that can otherwise occur.
        $this->input .= PHP_EOL;

        // Populate "a" with a new line, "b" with the first character, before
        // entering the loop
        $this->a = "\n";
        $this->b = $this->getReal();
    }

    /**
     * Prepare the provided input string. Basic character replacements, and also shortening variable names
     *
     * @param $php
     * @return mixed
     * @throws \Exception
     */
    protected function prepareInput($php)
    {
        // Normalize extraneous characters
        $php = preg_replace('/\/\*\*\/[^ *\'|"|`]/', '', $php);
        $php = str_replace("\r\n", "\n", $php);
        $php = str_replace("\r", "\n", $php);

        $search = '';

        $i = 0;

        foreach (self::$tokenRegexes as $regex) {
            if ($i > 0) {
                $search .= '|';
            }

            $search .= $regex;

            $i++;
        }

        if ($search != '') {
            $search = "/$search/";

            preg_match_all($search, $php, $matches);

            $i = 1;

            foreach (self::$tokenRegexes as $type => $regex) {
                if (isset($matches[$i])) {
                    $php = $this->shortenMatches($php, $matches[$i], $type);
                }

                $i++;
            }
        }

        return $php;
    }

    /**
     * Go through all variables and constants to shorten identifiers
     *
     * @param $php
     * @param $matches
     * @param $type
     * @return mixed
     */
    protected function shortenMatches($php, $matches, $type)
    {
        // Only relevant if there are variables to shorten!
        if (count($matches) > 0) {
            // Variable to for first character of this type
            $t = substr($type, 0, 1);

            // Initialize an empty arrays to track variables before/after name shortening
            $old = array();

            // Loop through all matches found
            foreach ($matches as $match) {
                $match = trim($match);

                // Make sure we only track non-empty matches once
                if ($match != '' && !isset($old[$match])) {
                    // Make sure we don't replace a reserved constant or variable
                    if (!in_array($match, ($type == 'const' ? self::$reservedConstants : self::$reservedVariables))) {
                        do {
                            $next = $t . $this->nextShort++;
                        } while (in_array($next, $matches) || in_array($next, self::$reservedKeywords));

                        $old[$match] = $next;
                    }
                }
            }

            // Go through all unique variables and find a corresponding unique short name
            foreach ($old as $match => $short) {
                $search = '/' . ($type == 'var' ? '([\-\>|\$])' : '') . '\b' . $match . '\b/';
                $replace = ($type == 'var' ? '$1' : '') . $short;

                if ($type == 'const') {
                    $replace = strtoupper($replace);
                }

                $php = preg_replace($search, $replace, $php);
            }
        }

        return $php;
    }

    /**
     * The primary action occurs here. This function loops through the input string,
     * outputting anything that's relevant and discarding anything that is not.
     */
    protected function loop()
    {
        while ($this->a !== false && !is_null($this->a) && $this->a !== '') {

            switch ($this->a) {
                // new lines
                case "\n":
                    // if the next line is something that can't stand alone preserve the newline
                    if (strpos('(-+#<', $this->b) !== false) {
                        echo $this->a;
                        $this->saveString();
                        break;
                    }

                    // if B is a space we skip the rest of the switch block and go down to the
                    // string/regex check below, resetting $this->b with getReal
                    if ($this->b === ' ') {
                        break;
                    }

                // otherwise we treat the newline like a space

                case ' ':
                    if (static::isAlphaNumeric($this->b)) {
                        echo $this->a;
                    }

                    $this->saveString();
                    break;

                default:
                    switch ($this->b) {
                        case "\n":
                            if (strpos('+-"\'', $this->a) !== false) {
                                echo $this->a;
                                $this->saveString();
                                break;
                            } else {
                                if (static::isAlphaNumeric($this->a)) {
                                    echo $this->a;
                                    $this->saveString();
                                }
                            }
                            break;

                        case ' ':
                            if (!static::isAlphaNumeric($this->a)) {
                                break;
                            }

                        default:
                            // check for some regex that breaks stuff
                            if ($this->a == '/' && ($this->b == '\'' || $this->b == '"')) {
                                $this->saveRegex();
                                continue;
                            }

                            echo $this->a;
                            $this->saveString();
                            break;
                    }
            }

            // do reg check of doom
            $this->b = $this->getReal();

            if (($this->b == '/' && strpos('(,=:[!&|?', $this->a) !== false)) {
                $this->saveRegex();
            }
        }
    }

    /**
     * Resets attributes that do not need to be stored between requests so that
     * the next request is ready to go. Another reason for this is to make sure
     * the variables are cleared and are not taking up memory.
     */
    protected function clean()
    {
        unset($this->input);
        $this->index = 0;
        $this->a = $this->b = '';
        unset($this->c);
        unset($this->options);
    }

    /**
     * Returns the next string for processing based off of the current index.
     *
     * @return string
     */
    protected function getChar()
    {
        // Check to see if we had anything in the look ahead buffer and use that.
        if (isset($this->c)) {
            $char = $this->c;
            unset($this->c);

            // Otherwise we start pulling from the input.
        } else {
            $char = substr($this->input, $this->index, 1);

            // If the next character doesn't exist return false.
            if (isset($char) && $char === false) {
                return false;
            }

            // Otherwise increment the pointer and use this char.
            $this->index++;
        }

        // Normalize all whitespace except for the newline character into a
        // standard space.
        if ($char !== "\n" && ord($char) < 32) {
            return ' ';
        }

        return $char;
    }

    /**
     * This function gets the next "real" character. It is essentially a wrapper
     * around the getChar function that skips comments. This has significant
     * performance benefits as the skipping is done using native functions (ie,
     * c code) rather than in script php.
     *
     *
     * @return string            Next 'real' character to be processed.
     * @throws \RuntimeException
     */
    protected function getReal()
    {
        $startIndex = $this->index;
        $char = $this->getChar();

        // Check to see if we're potentially in a comment
        if ($char !== '/') {
            return $char;
        }

        $this->c = $this->getChar();

        if ($this->c == '/') {
            return $this->processOneLineComments($startIndex);

        } elseif ($this->c == '*') {
            return $this->processMultiLineComments($startIndex);
        }

        return $char;
    }

    /**
     * Removed one line comments, with the exception of some very specific types of
     * conditional comments.
     *
     * @param  int $startIndex The index point where "getReal" function started
     * @return string
     */
    protected function processOneLineComments($startIndex)
    {
        $thirdCommentString = substr($this->input, $this->index, 1);

        // kill rest of line
        $this->getNext("\n");

        if ($thirdCommentString == '@') {
            $endPoint = ($this->index) - $startIndex;
            unset($this->c);
            $char = "\n" . substr($this->input, $startIndex, $endPoint);
        } else {
            // first one is contents of $this->c
            $this->getChar();
            $char = $this->getChar();
        }

        return $char;
    }

    /**
     * Skips multiline comments where appropriate, and includes them where needed.
     * Conditional comments and "license" style blocks are preserved.
     *
     * @param  int $startIndex The index point where "getReal" function started
     * @return bool|string       False if there's no character
     * @throws \RuntimeException Unclosed comments will throw an error
     */
    protected function processMultiLineComments($startIndex)
    {
        $this->getChar(); // current C
        $thirdCommentString = $this->getChar();

        // kill everything up to the next */ if it's there
        if ($this->getNext('*/')) {

            $this->getChar(); // get *
            $this->getChar(); // get /
            $char = $this->getChar(); // get next real character

            // Now we reinsert conditional comments and YUI-style licensing comments
            if (($this->options['flaggedComments'] && $thirdCommentString == '!')
                || ($thirdCommentString == '@')
            ) {

                // If conditional comments or flagged comments are not the first thing in the script
                // we need to echo a and fill it with a space before moving on.
                if ($startIndex > 0) {
                    echo $this->a;
                    $this->a = " ";

                    // If the comment started on a new line we let it stay on the new line
                    if ($this->input[($startIndex - 1)] == "\n") {
                        echo "\n";
                    }
                }

                $endPoint = ($this->index - 1) - $startIndex;
                echo substr($this->input, $startIndex, $endPoint);

                return $char;
            }
        } else {
            $char = false;
        }

        if ($char === false) {
            throw new \RuntimeException('Unclosed multiline comment at position: ' . ($this->index - 2));
        }

        // if we're here c is part of the comment and therefore tossed
        if (isset($this->c)) {
            unset($this->c);
        }

        return $char;
    }

    /**
     * Pushes the index ahead to the next instance of the supplied string. If it
     * is found the first character of the string is returned and the index is set
     * to it's position.
     *
     * @param  string $string
     * @return string|false Returns the first character of the string or false.
     */
    protected function getNext($string)
    {
        // Find the next occurrence of "string" after the current position.
        $pos = strpos($this->input, $string, $this->index);

        // If it's not there return false.
        if ($pos === false) {
            return false;
        }

        // Adjust position of index to jump ahead to the asked for string
        $this->index = $pos;

        // Return the first character of that string.
        return substr($this->input, $this->index, 1);
    }

    /**
     * When a php string is detected this function crawls for the end of
     * it and saves the whole string.
     *
     * @throws \RuntimeException Unclosed strings will throw an error
     */
    protected function saveString()
    {
        $startpos = $this->index;

        // saveString is always called after a gets cleared, so we push b into
        // that spot.
        $this->a = $this->b;

        // If this isn't a string we don't need to do anything.
        if ($this->a != "'" && $this->a != '"' && $this->a != '`') {
            return;
        }

        // String type is the quote used, " or '
        $stringType = $this->a;

        // Echo out that starting quote
        echo $this->a;

        // Loop until the string is done
        while (1) {

            // Grab the very next character and load it into a
            $this->a = $this->getChar();

            switch ($this->a) {

                // If the string opener (single or double quote) is used
                // output it and break out of the while loop-
                // The string is finished!
                case $stringType:
                    break 2;

                // New lines in strings without line delimiters are bad- actual
                // new lines will be represented by the string \n and not the actual
                // character, so those will be treated just fine using the switch
                // block below.
                case "\n":
                    throw new \RuntimeException('Unclosed string at position: ' . $startpos);
                    break;

                // Escaped characters get picked up here. If it's an escaped new line it's not really needed
                case '\\':

                    // a is a slash. We want to keep it, and the next character,
                    // unless it's a new line. New lines as actual strings will be
                    // preserved, but escaped new lines should be reduced.
                    $this->b = $this->getChar();

                    // If b is a new line we discard a and b and restart the loop.
                    if ($this->b == "\n") {
                        break;
                    }

                    // echo out the escaped character and restart the loop.
                    echo $this->a . $this->b;
                    break;


                // Since we're not dealing with any special cases we simply
                // output the character and continue our loop.
                default:
                    echo $this->a;
            }
        }
    }

    /**
     * When a regular expression is detected this function crawls for the end of
     * it and saves the whole regex.
     *
     * @throws \RuntimeException Unclosed regex will throw an error
     */
    protected function saveRegex()
    {
        echo $this->a . $this->b;

        while (($this->a = $this->getChar()) !== false) {
            if ($this->a == '/') {
                break;
            }

            if ($this->a == '\\') {
                echo $this->a;
                $this->a = $this->getChar();
            }

            if ($this->a == "\n") {
                throw new \RuntimeException('Unclosed regex pattern at position: ' . $this->index);
            }

            echo $this->a;
        }

        $this->b = $this->getReal();
    }

    /**
     * Checks to see if a character is alphanumeric.
     *
     * @param  string $char Just one character
     * @return bool
     */
    protected static function isAlphaNumeric($char)
    {
        return preg_match('/^[\w\$]$/', $char) === 1 || $char == '/';
    }

    /**
     * Replace patterns in the given string and store the replacement
     *
     * @param  string $php The string to lock
     * @return bool
     */
    protected function lock($php)
    {
        /* lock things like <code>"asd" + ++x;</code> */
        $lock = '"LOCK---' . crc32(time()) . '"';

        $matches = array();
        preg_match('/([+-])(\s+)([+-])/', $php, $matches);

        if (empty($matches)) {
            return $php;
        }

        $this->locks[$lock] = $matches[2];

        $php = preg_replace('/([+-])\s+([+-])/', "$1{$lock}$2", $php);
        /* -- */

        return $php;
    }

    /**
     * Replace "locks" with the original characters
     *
     * @param  string $php The string to unlock
     * @return bool
     */
    protected function unlock($php)
    {
        if (!count($this->locks)) {
            return $php;
        }

        foreach ($this->locks as $lock => $replacement) {
            $php = str_replace($lock, $replacement, $php);
        }

        return $php;
    }

}
