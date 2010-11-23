<?php

/**
 * First implementation of a caching layer, which caches the page
 * contents in accordance to the request URI and the language.
 *
 * @package Chainr
 * @subpackage Filter
 * 
 * @author Christian Schmitz <csc@soulworks.de>
 * @copyright Copyright (c) 2010, Christian Schmitz, Soulworks GmbH
 */
class Chainr_Filter_CacheFilter extends Chainr_Filter implements Chainr_InputFilter, Chainr_OutputFilter {
	private $cacheFile = '';
	
	private $cacheContents = null;

	private $cacheTtl = 3600;

	private $cacheId = null;
	
	private $cacheDir = './cache/';
	
	public function __construct($options = array()) {
		parent::__construct(Chainr_Helper::getSimpleClassNameOf(__CLASS__));

		if (isset($options['ttl'])) {
			$this->cacheTtl = $options['ttl'];
		}

		if (isset($options['id'])) {
			$this->cacheId = $options['id'];
		}

		if (isset($options['cache_dir'])) {
			$this->cacheDir = $options['cache_dir'];
		}
	}
	
	public function init(Chainr_Context $context) {
		parent::init($context);
		
		if (($language = $this->getContext()->getSession()->get('language')) == null) {
			$language = 'na';
		}

		$cacheId = !is_null($this->cacheId)
			? $this->cacheId
			: sprintf('%s-%s', md5($_SERVER['REQUEST_URI']), $language);

		$this->cacheFile = $this->cacheDir . $cacheId;
	}

	/**
	 * Checks wether the current request is cacheable.
	 * 
	 * @param Chainr_Request $request
	 * @return bool
	 */
	protected function isCacheable(Chainr_Request $request) {
		// We only cache get requests currently, so we'll
		// continue at the chain
		return ($request->getMethod() == Chainr_Request::METHOD_GET);
	}

	public function executeInput(Chainr_Context $context, Chainr_ExtendedDOMElement $node) {
		$request = $context->getRequest(); /* @var $request Chainr_Request */

		if (!$this->isCacheable($context->getRequest())) {
			return false;
		}

		// Check wether a cache file exists and if it's readable
		if (file_exists($this->cacheFile) && is_readable($this->cacheFile)) {

			// Get time last modified timestamp of the cache file
			$filemtime = @filemtime($this->cacheFile); // returns FALSE if file does not exist

			if (time() - $filemtime >= $this->cacheTtl || $request->hasParameter('nocache')) {
				// Cache expired
				unlink($this->cacheFile);
			} else {
				// Cache is still valid
				$this->cacheContents = fopen($this->cacheFile, 'r');

				// Stop execution of other filters
				return true;
			}
		}

		return false;
	}

	public function executeOutput(Chainr_Context $context) {
		$response = $context->getResponse(); /* @var $response Chainr_Response */

		if (!$this->isCacheable($context->getRequest())) {
			return false;
		}

		if (!is_null($this->cacheContents)) {
			// We got some cache contents

			if (is_resource($this->cacheContents)) {
				// Cache content is a stream resource, so we'll read from it
				$cacheData = unserialize(stream_get_contents($this->cacheContents));
			} else {
				// Cache content is a string
				$cacheData = unserialize($this->cacheContents);
			}

			// Set cache data at the response
			$response->setContent($cacheData['content']);
			$response->setHttpHeaders($cacheData['headers']);
			$response->setHttpHeader('X-Chainr-Cached', '1');
			$response->setHttpStatusCode($cacheData['code']);

			// Skip execution of other filters
			return true;
		} else {
			// We didn't get any cache contents, so we'll create one
			
			$cacheData = array(
				 'code'    => $response->getHttpStatusCode(),
				 'headers' => $response->getHttpHeaders(),
				 'content' => $response->getContent()
			);

			// Serialize response data and put it at the cache
			file_put_contents($this->cacheFile, serialize($cacheData));
		}
		
		return false;
	}
}
