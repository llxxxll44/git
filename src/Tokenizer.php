<?php

namespace DocTemplater;

use DocTemplater\Exceptions\TokenizerException;

class Tokenizer
{
    const TOKEN_EMPTY = 'empty';
    const TOKEN_NUMBER = 'number';
    const TOKEN_STRING = 'string';
    const TOKEN_FOREACH = 'foreach';
    const TOKEN_KEY = 'key';
    const TOKEN_FUNC = 'function';

    /**
     * @param string $str
     * @return array
     * @throws TokenizerException
     */
    public function tokenize($str)
    {
        $str = trim($str);
        $strLen = strlen($str);
        if ($strLen == 0) {
            return [
                'token' => self::TOKEN_EMPTY
            ];
        }
        $i = 0;
        $token = $this->parse($str, $strLen - 1, $i);
        if ($i != $strLen) {
            throw new TokenizerException("Unexpected char: $str[$i]", $str);
        }

        return $token;
    }

    /**
     * @param string $str
     * @param int $max
     * @param int $i
     * @param bool $argsContext
     * @return array
     * @throws TokenizerException
     */
    protected function parse($str, $max, &$i, $argsContext = false) {
        while($i < $max && $this->isWhiteSpace($str[$i])) $i++;

        if ($str[$i] == '-' && $i < $max && is_numeric($str[$i + 1])) {
            $i++;
            return [
                'token' => self::TOKEN_NUMBER,
                'value' => -$this->parseNumber($str, $max, $i)
            ];
        } elseif (is_numeric($str[$i])) {
            return [
                'token' => self::TOKEN_NUMBER,
                'value' => $this->parseNumber($str, $max, $i)
            ];
        } elseif ($str[$i] == '"' || $str[$i] == '\'') {
            return [
                'token' => self::TOKEN_STRING,
                'value' => $this->parseString($str, $max, $i)
            ];
        }

        $startKey = $i;
        while($i <= $max) {
            if (in_array($str[$i], ['[', ']', '(', ')', ','])) {
                break;
            }

            $i++;
        }

        if ($i == $startKey) {
            throw new TokenizerException("Syntax error", $str);
        }

        $keyLength = $i - $startKey;
        $key = substr($str, $startKey, $keyLength);

        $key = trim($key);
        $realKeyLength = strlen($key);
        if ($realKeyLength == 0 || !preg_match('/^[a-zA-Z0-9\_\.]+$/', $key) || $key[0] == '.' || $key[$realKeyLength - 1] == '.' || strpos($key, '..') !== false) {
            throw new TokenizerException("Invalid key: '$key'", $str);
        }

        if ($i > $max || ($argsContext && ($str[$i] == ',' || $str[$i] == ')'))) {
            return [
                'token' => self::TOKEN_KEY,
                'value' => $key
            ];
        } elseif (!$argsContext && $str[$i] == '[') {
            $i++;
            while($i < $max && $this->isWhiteSpace($str[$i])) $i++;
            if ($str[$i] != ']') {
                throw new TokenizerException("Unexpected char: $str[$i]", $str);
            }
            $i++;
            return [
                'token' => self::TOKEN_FOREACH,
                'value' => $key
            ];
        } elseif ($str[$i] != "(") {
            throw new TokenizerException("Unexpected char: $str[$i]", $str);
        }

        // parse function arguments
        $i++;
        $arguments = [];

        while($i < $max) {
            $arguments[] = $this->parse($str, $max, $i, true);
            while($i < $max && $this->isWhiteSpace($str[$i])) $i++;
            if($str[$i] == ',') {
                $i++;
                continue;
            }

            if($str[$i] == ')') {
                break;
            }
            throw new TokenizerException("Unexpected char: $str[$i]", $str);
        }


        if ($i <= $max && $str[$i] == ')') {
            $i++;
            return [
                'token' => self::TOKEN_FUNC,
                'value' => [
                    'name' => $key,
                    'arguments' => $arguments
                ]
            ];
        }

        throw new TokenizerException('Unexpected end of string', $str);
    }

    /**
     * @param string $char
     * @return bool
     */
    private function isWhiteSpace($char)
    {
        return $char == ' ' || $char == "\t" || $char =="\n" || $char =="\r" || $char =="\0" || $char =="\x0B";
    }

    /**
     * @param string $str
     * @param int $max
     * @param int $i
     * @return float|int
     */
    private function parseNumber($str, $max, &$i)
    {
        $dotAvailable = true;
        $output = '';
        while($i <= $max && (is_numeric($str[$i]) || ($dotAvailable && $str[$i] == '.'))) {
            if ($str[$i] == '.') {
                $output .= '.';
                $dotAvailable = false;
            } else {
                $output .= $str[$i];
            }
            $i++;
        }


        return $dotAvailable ? intval($output) : floatval($output);
    }

    /**
     * @param string $str
     * @param int $max
     * @param int $i
     * @return string
     * @throws TokenizerException
     */
    private function parseString($str, $max, &$i)
    {
        $firstChar = $str[$i++];
        $output = '';
        while($i <= $max && $str[$i] != $firstChar) {
            if ($i < $max && $str[$i] == "\\" && $str[$i + 1] == $firstChar) {
                $i++;
            }
            $output .= $str[$i];
            $i++;
        }

        if ($str[$i] != $firstChar) {
            throw new TokenizerException('Unexpected end of string literal', $str);
        }
        $i++;

        return $output;
    }

}