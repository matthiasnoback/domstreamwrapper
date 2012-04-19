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
    private $domNode;

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

            $this->mode = $mode;

            $this->findDOMNode();

            return true;
        }
        catch (\Exception $e) {
            if ($options & STREAM_REPORT_ERRORS) {
                trigger_error($e->getMessage(), E_USER_WARNING);
            }

            return false;
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
        $domDocument = new \DOMDocument(
            $this->getOption('version', '1.0'),
            $this->getOption('encoding', 'UTF-8')
        );
        $domDocument->load($this->getSourcePath());

        $xpathQuery = parse_url($this->path, PHP_URL_PATH);
        if (false === $xpathQuery) {
            throw new \InvalidArgumentException('Could not determine the XPath query');
        }

        $xpath = new \DOMXPath($domDocument);
        $domNodeList = $xpath->query($xpathQuery);
        if (0 === $domNodeList->length) {
            throw new \InvalidArgumentException(sprintf('Node not found using XPath query: ', $xpathQuery));
        }

        $this->domNode = $domNodeList->item(0);
    }
}
