<?php

namespace DocTemplater;

use DocTemplater\Exceptions\Exception;

class CommentsResolver
{

    /** @var array */
    private $functions = [];

    /** @var array */
    private $comments;

    /**
     * CommentsResolver constructor.
     * @param array $comments
     * @throws Exceptions\TokenizerException
     */
    public function __construct(array $comments)
    {
        $tokenizer = new Tokenizer();
        $preparedComments = [];
        foreach ($comments as $id => $comment) {
            $preparedComments[$id] = $tokenizer->tokenize($comment);
        }

        $this->comments = $preparedComments;
    }

    /**
     * @param string $alias
     * @param callable $handler
     */
    public function registerFunction($alias, $handler)
    {
        $this->functions[$alias] = $handler;
    }

    /**
     * @param int $id
     * @param Context $context
     * @return array
     * @throws Exception
     */
    public function resolve($id, Context $context)
    {
        if (!isset($this->comments[$id])) {
            return $this->resolveToken(Tokenizer::TOKEN_EMPTY);
        }

        $comment = $this->comments[$id];
        return $this->resolveToken($comment['token'], isset($comment['value']) ? $comment['value'] : null, $context);
    }

    /**
     * @param string $token
     * @param string|null $value
     * @param Context|null $context
     * @return array
     * @throws Exception
     */
    protected function resolveToken($token, $value = null, Context $context = null)
    {
        switch ($token) {
            case Tokenizer::TOKEN_KEY:
                return ['type' => 'value', 'data' => $context->find($value)];
            case Tokenizer::TOKEN_FOREACH:
                $data = $context->find($value);
                if ($data instanceof \ArrayAccess) {
                    $newData = [];
                    foreach ($data as $key => $value) {
                        $newData[$key] = $value;
                    }
                    $data = $newData;
                } else if (is_array($data)) {
                    //
                } else {
                    $data = [$data];
                }

                return ['type' => 'foreach', 'context' => array_map(function ($el) use ($context) {
                    return new Context($el, $context);
                }, $data)];
            case Tokenizer::TOKEN_NUMBER:
            case Tokenizer::TOKEN_STRING:
                return ['type' => 'value', 'data' => $value];
            case Tokenizer::TOKEN_FUNC:
                $funcName = $value['name'];
                if (!isset($this->functions[$funcName])) {
                    throw new Exception("Undefined function: $funcName");
                }
                $args = $value['arguments'];
                return ['type' => 'value', 'data' => call_user_func_array($this->functions[$funcName], $this->prepareArgs($args, $context))];
            case Tokenizer::TOKEN_EMPTY:
            default:
                return ['type' => 'value', 'data' => ''];
        }
    }

    /**
     * @param array $args
     * @param Context $context
     * @return array
     * @throws Exception
     */
    protected function prepareArgs(array $args, Context $context)
    {
        $resolvedArgs = [];
        foreach ($args as $arg) {
            $resolvedArgs[] = $this->resolveToken($arg['token'], isset($arg['value']) ? $arg['value'] : null, $context)['data'];
        }

        return $resolvedArgs;
    }

}