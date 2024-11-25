<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HttpClient\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\InvalidArgumentException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class NoPrivateNetworkHttpClientTest extends TestCase
{
    public static function getExcludeData(): array
    {
        return [
            // private
            ['0.0.0.1',     null,          true],
            ['169.254.0.1', null,          true],
            ['127.0.0.1',   null,          true],
            ['240.0.0.1',   null,          true],
            ['10.0.0.1',    null,          true],
            ['172.16.0.1',  null,          true],
            ['192.168.0.1', null,          true],
            ['::1',         null,          true],
            ['::ffff:0:1',  null,          true],
            ['fe80::1',     null,          true],
            ['fc00::1',     null,          true],
            ['fd00::1',     null,          true],
            ['10.0.0.1',    '10.0.0.0/24', true],
            ['10.0.0.1',    '10.0.0.1',    true],
            ['fc00::1',     'fc00::1/120', true],
            ['fc00::1',     'fc00::1',     true],

            ['172.16.0.1',  ['10.0.0.0/8', '192.168.0.0/16'], false],
            ['fc00::1',     ['fe80::/10', '::ffff:0:0/96'],   false],

            // public
            ['104.26.14.6',            null,                false],
            ['104.26.14.6',            '104.26.14.0/24',    true],
            ['2606:4700:20::681a:e06', null,                false],
            ['2606:4700:20::681a:e06', '2606:4700:20::/43', true],

            // no ipv4/ipv6 at all
            ['2606:4700:20::681a:e06', '::/0',      true],
            ['104.26.14.6',            '0.0.0.0/0', true],

            // weird scenarios (e.g.: when trying to match ipv4 address on ipv6 subnet)
            ['10.0.0.1', 'fc00::/7',   false],
            ['fc00::1',  '10.0.0.0/8', false],
        ];
    }

    /**
     * @dataProvider getExcludeData
     */
    public function testExcludeByIp(string $ipAddr, $subnets, bool $mustThrow)
    {
        $content = 'foo';
        $url = sprintf('http://%s/', strtr($ipAddr, '.:', '--'));

        if ($mustThrow) {
            $this->expectException(TransportException::class);
            $this->expectExceptionMessage(sprintf('IP "%s" is blocked for "%s".', $ipAddr, $url));
        }

        $previousHttpClient = $this->getMockHttpClient($ipAddr, $content);
        $client = new NoPrivateNetworkHttpClient($previousHttpClient, $subnets);
        $response = $client->request('GET', $url);

        if (!$mustThrow) {
            $this->assertEquals($content, $response->getContent());
            $this->assertEquals(200, $response->getStatusCode());
        }
    }

    /**
     * @dataProvider getExcludeData
     */
    public function testExcludeByHost(string $ipAddr, $subnets, bool $mustThrow)
    {
        $content = 'foo';
        $host = str_contains($ipAddr, ':') ? sprintf('[%s]', $ipAddr) : $ipAddr;
        $url = sprintf('http://%s/', $host);

        if ($mustThrow) {
            $this->expectException(TransportException::class);
            $this->expectExceptionMessage(sprintf('Host "%s" is blocked for "%s".', $host, $url));
        }

        $previousHttpClient = $this->getMockHttpClient($ipAddr, $content);
        $client = new NoPrivateNetworkHttpClient($previousHttpClient, $subnets);
        $response = $client->request('GET', $url);

        if (!$mustThrow) {
            $this->assertEquals($content, $response->getContent());
            $this->assertEquals(200, $response->getStatusCode());
        }
    }

    public function testCustomOnProgressCallback()
    {
        $ipAddr = '104.26.14.6';
        $url = sprintf('http://%s/', $ipAddr);
        $content = 'foo';

        $executionCount = 0;
        $customCallback = function (int $dlNow, int $dlSize, array $info) use (&$executionCount): void {
            ++$executionCount;
        };

        $previousHttpClient = $this->getMockHttpClient($ipAddr, $content);
        $client = new NoPrivateNetworkHttpClient($previousHttpClient);
        $response = $client->request('GET', $url, ['on_progress' => $customCallback]);

        $this->assertEquals(1, $executionCount);
        $this->assertEquals($content, $response->getContent());
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testNonCallableOnProgressCallback()
    {
        $ipAddr = '104.26.14.6';
        $url = sprintf('http://%s/', $ipAddr);
        $customCallback = sprintf('cb_%s', microtime(true));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Option "on_progress" must be callable, "string" given.');

        $client = new NoPrivateNetworkHttpClient(new MockHttpClient());
        $client->request('GET', $url, ['on_progress' => $customCallback]);
    }

    private function getMockHttpClient(string $ipAddr, string $content)
    {
        return new MockHttpClient(new MockResponse($content, ['primary_ip' => $ipAddr]));
    }
}
