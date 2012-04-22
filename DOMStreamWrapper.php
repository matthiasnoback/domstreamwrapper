<?php

/**
 * The DOMStreamWrapper can be registered as a stream wrapper for the protocol "dom".
 * It allows you to modify node values by using traditional file manipulation functions.
 */
class DOMStreamWrapper
{
    // provided
    public $context;
    private $path;
    private $mode;

    // collected
    private $options;
    private $sourcePath;
    /* @var \DOMDocument */
    private $domDocument;
    /* @var \DOMNode */
    private $domNode;

    private $length;
    private $position;

    /**
     * The given path should be something like:
     *
     *     dom://[filenameWithoutExtension][simpleXpathQuery]
     *
     * The mode can be any file mode (see documentation of PHP function fopen())
     *
     * @param $path
     * @param $mode
     * @param $options
     * @return bool
     */
    public function stream_open($path, $mode, $options)
    {
        try {
            $this->path = $path;

            $this->mode = str_replace(array('b', 't'), '', $mode); // remove binary/text option

            $this->domNode = $this->findDOMNode();

            if ($this->shouldTruncate()) {
                $this->domNode->nodeValue = '';
            }

            $this->position = $this->getInitialPosition();

            return true;
        }
        catch (\Exception $e) {
            if ($options & STREAM_REPORT_ERRORS) {
                trigger_error($e->getMessage(), E_USER_WARNING);
            }

            return false;
        }
    }

    public function stream_eof()
    {
        return !($this->position < $this->getLength());
    }

    public function stream_read($count)
    {
        try {
            $maxReadLength = $this->getLength() - $this->position;
            $readLength = min($count, $maxReadLength);

            if (0 === $readLength) {
                throw new \RuntimeException('Nothing to read');
            }

            $result = substr($this->domNode->nodeValue, $this->position, $readLength);

            $this->position += $readLength;

            return $result;
        }
        catch (\Exception $e) {
            return false;
        }
    }

    public function stream_tell()
    {
        return $this->position;
    }

    public function stream_seek($offset, $whence)
    {
        try {
            if (SEEK_CUR === $whence) {
                $newPosition = $this->position + $offset;
            }
            else if (SEEK_END === $whence) {
                if ($offset >= 0) {
                    throw new \InvalidArgumentException('Offset should be a negative value');
                }

                $newPosition = $this->getLength() + $offset;
            }
            else if (SEEK_SET === $whence) {
                if ($offset < 0) {
                    throw new \InvalidArgumentException('Offset should be a positive value');
                }

                $newPosition = $offset;
            }
            else {
                throw new \InvalidArgumentException('Unknown "whence"');
            }

            if ($newPosition < 0) {
                throw new \OutOfBoundsException('The new position is a negative value');
            }

            if ($newPosition >= $this->getLength()) {
                throw new \OutOfBoundsException('The new position is beyond the length of the stream');
            }

            $this->position = $newPosition;

            return true;
        }
        catch (\Exception $e) {
            return false;
        }
    }

    private function getLength()
    {
        return strlen($this->domNode->nodeValue);
    }

    private function getInitialPosition()
    {
        switch ($this->getMode()) {
            case 'r':
            case 'r+':
            case 'w':
            case 'w+':
            case 'x':
            case 'x+':
            case 'x':
            case 'c':
            case 'c+':
                return 0;
            case 'a':
            case 'a+':
                return $this->getLength();
            default:
                throw new \InvalidArgumentException('Invalid mode');
        }
    }

    private function getMode()
    {
        return $this->mode ?: 'r';
    }

    /**
     * Get an array of options from the stream context
     *
     * @return array
     */
    private function getOptions()
    {
        if (null === $this->options) {
            $defaultContext = stream_context_get_default();
            $defaultOptions = stream_context_get_options($defaultContext);
            $defaultOptions = isset($defaultOptions['dom']) ? $defaultOptions['dom'] : array();

            $givenContext = $this->context;
            $givenOptions = stream_context_get_options($givenContext);
            $givenOptions = isset($givenOptions['dom']) ? $givenOptions['dom'] : array();

            $this->options = array_merge($defaultOptions, $givenOptions);
        }

        return $this->options;
    }

    /**
     * Get a single option from the stream context
     * If the option is not defined and no default is provided, an exception will be thrown
     *
     * @param $name
     * @param null $default
     * @return mixed
     * @throws \InvalidArgumentException
     */
    private function getOption($name, $default = null)
    {
        $options = $this->getOptions();

        if (!isset($options[$name])) {
            if (null === $default) {
                throw new \InvalidArgumentException(sprintf('Please provide stream context option "%s"', $name));
            }

            return $default;
        }

        return $options[$name];
    }

    /**
     * Get the source path for the requested stream path
     * This path points to the XML file which will be used for find the requested DOM node
     * The source path will be validated against the request mode (read/write permissions)
     *
     * @return string
     * @throws InvalidArgumentException
     */
    private function getSourcePath()
    {
        if (null === $this->sourcePath) {
            $filenameWithoutExtension = parse_url($this->path, PHP_URL_HOST);
            if (false === $filenameWithoutExtension) {
                throw new \InvalidArgumentException('Could not determine the file name');
            }

            $filename = $filenameWithoutExtension . '.' . $this->getOption('extension', 'xml');
            $sourcePath = $this->getOption('directory') . DIRECTORY_SEPARATOR . $filename;

            if (!file_exists($sourcePath)) {
                throw new \InvalidArgumentException(sprintf('File "%s" was not found', $sourcePath));
            }

            if ($this->isWriteMode() && !is_writeable($sourcePath)) {
                throw new \InvalidArgumentException(sprintf('File "%s" is not writeable', $sourcePath));
            }

            $this->sourcePath = $sourcePath;
        }

        return $this->sourcePath;
    }

    private function isWriteMode()
    {
        return 'r' !== $this->getMode();
    }

    /**
     * Find the requested DOM node
     *
     * @throws InvalidArgumentException
     */
    private function findDOMNode()
    {
        $this->domDocument = new \DOMDocument(
            $this->getOption('version', '1.0'),
            $this->getOption('encoding', 'UTF-8')
        );
        $this->domDocument->formatOutput = $this->getOption('format_output', true);
        $this->domDocument->load($this->getSourcePath());

        $xpathQuery = parse_url($this->path, PHP_URL_PATH);
        if (false === $xpathQuery) {
            throw new \InvalidArgumentException('Could not determine the XPath query');
        }

        $xpath = new \DOMXPath($this->domDocument);
        $domNodeList = $xpath->query($xpathQuery);
        if (0 === $domNodeList->length) {

            if ($this->shouldCreateNode()) {
                $domNode = $this->attemptToCreateNode($this->domDocument, $xpath, $xpathQuery);
            }
            else {
                throw new \InvalidArgumentException(sprintf('Node not found using XPath query: ', $xpathQuery));
            }
        }
        else {
            $domNode = $domNodeList->item(0);
        }

        return $domNode;
    }

    private function shouldCreateNode()
    {
        return !in_array($this->getMode(), array('r', 'r+'));
    }

    private function shouldTruncate()
    {
        return in_array($this->getMode(), array('w', 'w+'));
    }

    private function attemptToCreateNode(\DOMDocument $domDocument, \DOMXPath $xpath, $xpathQuery)
    {
        if (!preg_match('/^(\/\w+)+$/', $xpathQuery)) {
            throw new \InvalidArgumentException('Your XPath query is too fancy for finding out how to create a node for it');
        }

        $nodeNames = explode('/', ltrim($xpathQuery, '/'));

        $nodesExistUntill = '/';
        $currentNode = $domDocument;
        foreach ($nodeNames as $nodeName) {
            $nodesExistUntill .= $nodeName;
            $nodeList = $xpath->query($nodesExistUntill);
            if (0 === $nodeList->length) {
                $node = $domDocument->createElement($nodeName);
                $currentNode->appendChild($node);
                $currentNode = $node;
            }
            else {
                $currentNode = $nodeList->item(0);
            }
        }

        return $currentNode;
    }

    private function saveFile()
    {
        $this->domDocument->save($this->getSourcePath());
    }
}
