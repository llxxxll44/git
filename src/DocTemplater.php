<?php

namespace DocTemplater;

use DocTemplater\Exceptions\Exception;
use DOMNode;
use ZipArchive;
use DomDocument;

class DocTemplater
{
    const XMLNS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    const TYPE_DOCUMENT = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument';
    const TYPE_COMMENTS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/comments';

    /** @var ZipArchive */
    private $zip;

    /** @var CommentsResolver */
    private $commentsResolver;

    /** @var String */
    private $rootDocumentTarget;

    /**
     * DocTemplater constructor.
     * @param string $templatePath
     * @throws Exception
     */
    public function __construct($templatePath)
    {
        $zip = new ZipArchive();
        if ($zip->open($templatePath) !== true) {
            throw new Exception('Invalid document');
        }
        $this->zip = $zip;

        $rootDocumentTarget = $this->findTarget();
        $rootDocumentDirectory = dirname($rootDocumentTarget);
        $rootDocumentName = basename($rootDocumentTarget);

        $commentsTarget = $this->findTarget("{$rootDocumentDirectory}/_rels/{$rootDocumentName}.rels", self::TYPE_COMMENTS);
        if (is_null($commentsTarget)) {
            throw new Exception('No comments found in template');
        }

        $this->processComments("{$rootDocumentDirectory}/{$commentsTarget}");

        $this->rootDocumentTarget = $rootDocumentTarget;
    }

    /**
     * @param string $relationshipsFile
     * @param string $documentType
     * @return null
     * @throws Exception
     */
    protected function findTarget($relationshipsFile = '_rels/.rels', $documentType = self::TYPE_DOCUMENT)
    {
        $xmlDoc = $this->readXML($relationshipsFile);
        $relationships = $xmlDoc->getElementsByTagName('Relationship');
        foreach ($relationships as $relationship) {
            if ($relationship->getAttribute('Type') == $documentType) {
                return $relationship->getAttribute('Target');
            }
        }

        return null;
    }

    /**
     * @param string $alias
     * @param callable $handler
     */
    public function registerFunction($alias, $handler)
    {
        $this->commentsResolver->registerFunction($alias, $handler);
    }

    /**
     * @param string $xmlPath
     * @return DomDocument
     * @throws Exception
     */
    protected function readXML($xmlPath)
    {
        $fileContent = $this->zip->getFromName($xmlPath);
        if ($fileContent === false) {
            throw new Exception("$xmlPath is not a valid file path");
        }

        $domDocument = new DomDocument();
        if ($domDocument->loadXML($fileContent) === false) {
            throw new Exception("$xmlPath is not a valid xml file");
        }

        return $domDocument;
    }

    /**
     * @param string $xmlPath
     * @param DomDocument $xmlDoc
     */
    protected function saveXML($xmlPath, $xmlDoc)
    {
        $this->zip->addFromString($xmlPath, $xmlDoc->saveXML());
    }

    /**
     * @param string $commentsTarget
     * @throws Exception
     */
    protected function processComments($commentsTarget)
    {
        $commentsXML =$this->readXML($commentsTarget);
        $commentsNodes = $commentsXML->getElementsByTagNameNS(self::XMLNS, 'comment');
        $comments = [];
        
        for($i = $commentsNodes->length - 1; $i >= 0; $i--) {
            /** @var \DOMElement $node */
            $node = $commentsNodes->item($i);
            $id = $node->getAttributeNS(self::XMLNS, 'id');
            $textNodes = $node->getElementsByTagNameNS(self::XMLNS, 't');
            $comment = '';
            foreach ($textNodes as $textNode) {
                $comment .= $textNode->nodeValue;
            }
            $comments[$id] = $comment;

            $node->parentNode->removeChild($node);
        }

        $this->saveXML($commentsTarget, $commentsXML);
        $this->commentsResolver = new CommentsResolver($comments);
    }

    /**
     * @param mixed $context
     * @throws Exception
     */
    public function render($context)
    {
        $context = new Context($context);
        $xml = $this->readXML($this->rootDocumentTarget);

        $this->renderLevel($context, $xml);
        $this->saveXML($this->rootDocumentTarget, $xml);
    }

    /**
     * @param Context $context
     * @param DomNode $xml
     * @param DomDocument|null $root
     * @throws Exception
     */
    protected function renderLevel(Context $context, $xml, DomDocument $root = null)
    {
        if (is_null($root)) {
            $root = $xml;
        }

        $data = $this->commentsResolver->resolve(0, $context);
        $startsMap = [];
        $endsMap = [];

        $commentsStarts = $xml->getElementsByTagNameNS(self::XMLNS, 'commentRangeStart');
        /** @var \DOMElement $commentRangeStart */
        foreach ($commentsStarts as $commentRangeStart) {
            $id = $commentRangeStart->getAttributeNS(self::XMLNS, 'id');
            $startsMap[$id] = $commentRangeStart;
        }

        $commentsEnds = $xml->getElementsByTagNameNS(self::XMLNS, 'commentRangeEnd');
        /** @var \DOMElement $commentRangeEnd */
        foreach ($commentsEnds as $commentRangeEnd) {
            $id = $commentRangeEnd->getAttributeNS(self::XMLNS, 'id');
            $endsMap[$id] = $commentRangeEnd;
        }

        $commentsReferences = $xml->getElementsByTagNameNS(self::XMLNS, 'commentReference');
        for ($i = $commentsReferences->length - 1; $i >= 0; $i--) {
            /** @var \DOMElement $commentReference */
            $commentReference = $commentsReferences->item($i);
            $commentReference->parentNode->removeChild($commentReference);
        }

        $ids = array_keys($startsMap);

        while(count($ids) > 0) {
            $id = array_shift($ids);
            /** @var \DOMElement $commentRangeStart */
            $commentRangeStart = $startsMap[$id];
            /** @var \DOMElement $commentRangeEnd */
            $commentRangeEnd = $endsMap[$id];

            $resolvedData = $this->commentsResolver->resolve($id, $context);
            if ($resolvedData['type'] == 'value') {
                $node = $commentRangeStart;
                while(!is_null($node) && $node !== $commentRangeEnd) {
                    if ($node->nodeName == 'w:t') {
                        for ($i = $node->childNodes->length - 1; $i >= 0; $i--) {
                            $node->removeChild($node->childNodes->item($i));
                        }

                        $node->appendChild($root->createTextNode($resolvedData['data']));
                        break;
                    }

                    if (!is_null($node->firstChild)) {
                        $node = $node->firstChild;
                    } elseif (!is_null($node->nextSibling)) {
                        $node = $node->nextSibling;
                    } elseif (!is_null($node->parentNode)) {
                        while(!is_null($node->parentNode)) {
                            $node = $node->parentNode;
                            if (!is_null($node->nextSibling)) {
                                $node = $node->nextSibling;
                                break;
                            }
                        }
                    } else {
                        break;
                    }
                }

                $commentRangeStart->parentNode->removeChild($commentRangeStart);
                $commentRangeEnd->parentNode->removeChild($commentRangeEnd);
            } elseif ($resolvedData['type'] == 'foreach') {
                // find closest root
                //

                $startStack = [];
                $startNode = $commentRangeStart;
                while(!is_null($startNode->parentNode)) {
                    $startStack[] = $startNode->parentNode;
                    $startNode = $startNode->parentNode;
                }

                $endStack = [];
                $endNode = $commentRangeEnd;
                while(!is_null($endNode->parentNode)) {
                    $endStack[] = $endNode->parentNode;
                    $endNode = $endNode->parentNode;
                }

                $len = min(count($startStack), count($endStack));
                $startStack = array_reverse($startStack);
                $endStack = array_reverse($endStack);

                /** @var \DOMElement $foreachRoot */
                $foreachRoot = null;
                for ($i = 0; $i < $len; $i++) {
                    if ($startStack[$i] !== $endStack[$i]) {
                        break;
                    }

                    $foreachRoot = $startStack[$i];
                }

                $commentRangeStart->parentNode->removeChild($commentRangeStart);
                $commentRangeEnd->parentNode->removeChild($commentRangeEnd);

                $foreachComments = $foreachRoot->getElementsByTagNameNS(self::XMLNS, 'commentRangeStart');
                /** @var \DOMElement $foreachComment */
                foreach ($foreachComments as $foreachComment) {
                    $id = $foreachComment->getAttributeNS(self::XMLNS, 'id');
                    unset($startsMap[$id], $endsMap[$id], $ids[array_search($id, $ids)]);
                }

                foreach ($resolvedData['context'] as $newContext) {
                    $template = $foreachRoot->cloneNode(true);
                    $this->renderLevel($newContext, $template, $root);

                    $foreachRoot->parentNode->insertBefore($template, $foreachRoot);
                }

                $foreachRoot->parentNode->removeChild($foreachRoot);
            }
        }

    }

    public function save()
    {
        $this->zip->close();
        $this->zip = null;
    }

    public function __destruct()
    {
        if (!is_null($this->zip)) {
            $this->zip->close();
        }
    }

}