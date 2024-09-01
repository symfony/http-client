<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HttpClient;

use Amp\Http\Client\Request as AmpRequest;
use Amp\Http\HttpMessage;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * A factory to instantiate the best possible HTTP client for the runtime.
 *
 * @author Nicolas Grekas <p@tchwork.com>
 */
final class HttpClient
{
    /**
     * @param array $defaultOptions     Default request's options
     * @param int   $maxHostConnections The maximum number of connections to a single host
     * @param int   $maxPendingPushes   The maximum number of pushed responses to accept in the queue
     *
     * @see HttpClientInterface::OPTIONS_DEFAULTS for available options
     */
    public static function create(array $defaultOptions = [], int $maxHostConnections = 6, int $maxPendingPushes = 50): HttpClientInterface
    {
        if ($amp = class_exists(AmpRequest::class) && (\PHP_VERSION_ID >= 80400 || !is_subclass_of(AmpRequest::class, HttpMessage::class))) {
            if (!\extension_loaded('curl')) {
                return new AmpHttpClient($defaultOptions, null, $maxHostConnections, $maxPendingPushes);
            }

            // Skip curl when HTTP/2 push is unsupported or buggy, see https://bugs.php.net/77535
            if (!\defined('CURLMOPT_PUSHFUNCTION')) {
                return new AmpHttpClient($defaultOptions, null, $maxHostConnections, $maxPendingPushes);
            }

            static $curlVersion = null;
            $curlVersion ??= curl_version();

            // HTTP/2 push crashes before curl 7.61
            if (0x073D00 > $curlVersion['version_number'] || !(\CURL_VERSION_HTTP2 & $curlVersion['features'])) {
                return new AmpHttpClient($defaultOptions, null, $maxHostConnections, $maxPendingPushes);
            }
        }

        if (\extension_loaded('curl')) {
            if ('\\' !== \DIRECTORY_SEPARATOR || isset($defaultOptions['cafile']) || isset($defaultOptions['capath']) || \ini_get('curl.cainfo') || \ini_get('openssl.cafile') || \ini_get('openssl.capath')) {
                return new CurlHttpClient($defaultOptions, $maxHostConnections, $maxPendingPushes);
            }

            @trigger_error('Configure the "curl.cainfo", "openssl.cafile" or "openssl.capath" php.ini setting to enable the CurlHttpClient', \E_USER_WARNING);
        }

        if ($amp) {
            return new AmpHttpClient($defaultOptions, null, $maxHostConnections, $maxPendingPushes);
        }

        @trigger_error((\extension_loaded('curl') ? 'Upgrade' : 'Install').' the curl extension or run "composer require amphp/http-client:^4.2.1" to perform async HTTP operations, including full HTTP/2 support', \E_USER_NOTICE);

        return new NativeHttpClient($defaultOptions, $maxHostConnections);
    }

    /**
     * Creates a client that adds options (e.g. authentication headers) only when the request URL matches the provided base URI.
     */
    public static function createForBaseUri(string $baseUri, array $defaultOptions = [], int $maxHostConnections = 6, int $maxPendingPushes = 50): HttpClientInterface
    {
        $client = self::create([], $maxHostConnections, $maxPendingPushes);

        return ScopingHttpClient::forBaseUri($client, $baseUri, $defaultOptions);
    }
}
