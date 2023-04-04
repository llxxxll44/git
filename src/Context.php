<?php

namespace DocTemplater;


class Context
{

    /** @var Context|null */
    private $parent;

    /** @var mixed */
    private $current;

    /** @var array */
    private $cache = [];

    /**
     * Context constructor.
     * @param mixed $context
     * @param Context $parent
     */
    public function __construct($context, $parent = null)
    {
        $this->current = $context;
        $this->parent = $parent;
    }

    /**
     * @param string $keyPath
     * @return mixed
     */
    public function find($keyPath)
    {
        if (array_key_exists($keyPath, $this->cache)) {
            return $this->cache[$keyPath];
        }

        $data = $this->extractData($keyPath);
        $this->cache[$keyPath] = $data;
        return $data;
    }

    protected function extractData($keyPath)
    {
        if (empty($keyPath)) {
            return null;
        }

        $parts = explode('.', $keyPath);
        $failed = false;
        $data = $this->current;
        foreach ($parts as $part) {
            if ((is_array($data) || $data instanceof \ArrayAccess) && isset($data[$part])) {
                $data = $data[$part];
                continue;
            }

            if (is_object($data) && isset($data->{$part})) {
                $data = $data->{$part};
                continue;
            }

            $failed = true;
            break;
        }

        if (!$failed) {
            return $data;
        }


        if (!is_null($this->parent)) {
            return $this->parent->find($keyPath);
        }

        return null;
    }

}