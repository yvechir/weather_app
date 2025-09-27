<?php

namespace App\Tests\Service;

use App\Service\WeatherService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class WeatherServiceTest extends TestCase
{
    /**
     * Verifies the true way:
     * - Parses valid JSON.
     * - Normalizes fields (city, country, temperature, condition).
     * - Emits a single info log line with the expected snippet.
     * - Returns no 'error' key.
     */
    public function testSuccessReturnsNormalizedDataAndLogsInfo(): void
    {
        $mockResponse = new MockResponse(json_encode([
            'location' => ['name' => 'London', 'country' => 'United Kingdom'],
            'current'  => [
                'temp_c' => 20.5,
                'condition' => ['text' => 'Sunny'],
                'humidity' => 60,
                'wind_kph' => 10.2,
                'last_updated' => '2023-10-01 12:00',
            ],
        ]));

        $http = new MockHttpClient($mockResponse);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('info')
            ->with($this->callback(fn (string $msg) =>
            str_contains($msg, ' - Weather in London: 20.5°C, Sunny')
            ));
        $logger->expects($this->never())->method('error');

        $svc = new WeatherService($http, 'test-api-key', 'https://api.test.com', $logger, 30);

        $r = $svc->getWeatherData('London');

        $this->assertSame('London', $r['city']);
        $this->assertSame('United Kingdom', $r['country']);
        $this->assertSame(20.5, $r['temperature']);
        $this->assertSame('Sunny', $r['condition']);
        $this->assertArrayNotHasKey('error', $r);
    }

    /**
     * Verifies HTTP 404 handling:
     * - Returns the API’s error message in 'error'.
     * - Writes an error log line in the expected format with HTTP 404.
     */
    public function testHttp404ReturnsApiMessageAndLogsError(): void
    {
        $mockResponse = new MockResponse(
            json_encode(['error' => ['message' => 'No matching location found.']]),
            ['http_code' => 404]
        );

        $http = new MockHttpClient($mockResponse);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with($this->callback(fn (string $msg) =>
            str_contains($msg, ' - Weather App ERROR for "London": No matching location found. (HTTP 404)')
            ));

        $svc = new WeatherService($http, 'k', 'https://api.test.com', $logger, 30);

        $r = $svc->getWeatherData('London');

        $this->assertSame('No matching location found.', $r['error']);
    }

    /**
     * Verifies invalid JSON handling:
     * - Returns a friendly 'Invalid response from weather service' error.
     * - Logs an error line mentioning “EXCEPTION while decoding”.
     */
    public function testInvalidJsonReturnsErrorAndLogsDecodingException(): void
    {
        $http = new MockHttpClient(new MockResponse('not-json')); // 200 OK but invalid JSON payload

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with($this->callback(fn (string $msg) =>
            str_contains($msg, ' - Weather App EXCEPTION while decoding:')
            ));

        $svc = new WeatherService($http, 'k', 'https://api.test.com', $logger, 30);

        $r = $svc->getWeatherData('London');

        $this->assertSame('Invalid response from weather service', $r['error']);
    }

    /**
     * Verifies network/transport exception handling:
     * - When the HTTP client throws a TransportException, the service returns
     *   'Network error, please try again'.
     * - Logs a single error line mentioning “EXCEPTION (network)”.
     */
    public function testTransportExceptionReturnsNetworkErrorAndLogs(): void
    {
        $http = new MockHttpClient(function () {
            throw new TransportException('Network down');
        });

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with($this->callback(fn (string $msg) =>
            str_contains($msg, ' - Weather App EXCEPTION (network): Network down')
            ));

        $svc = new WeatherService($http, 'k', 'https://api.test.com', $logger, 30);

        $r = $svc->getWeatherData('Kyiv');

        $this->assertSame('Network error, please try again', $r['error']);
    }

    /**
     * Verifies unexpected exception handling:
     * - When an arbitrary exception is thrown (e.g., RuntimeException),
     *   the service returns 'Unexpected error while calling weather service'.
     * - Logs a single error line mentioning “EXCEPTION (unexpected)”.
     */
    public function testUnexpectedExceptionReturnsGenericErrorAndLogs(): void
    {
        $http = $this->createMock(HttpClientInterface::class);
        $http->method('request')->willThrowException(new \RuntimeException('Boom'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with($this->callback(fn (string $msg) =>
            str_contains($msg, ' - Weather App EXCEPTION (unexpected): Boom')
            ));

        $svc = new WeatherService($http, 'k', 'https://api.test.com', $logger, 30);

        $r = $svc->getWeatherData('Kyiv');

        $this->assertSame('Unexpected error while calling weather service', $r['error']);
    }

    /**
     * Verifies correct request construction:
     * - Uses GET method.
     * - Calls the expected path (/current.json) on the configured base URL.
     * - Sends 'key' and 'q' as query parameters (both in the URL and options).
     * - Passes the configured timeout value to the HTTP client.
     */
    public function testBuildsCorrectUrlQueryAndTimeout(): void
    {
        $captured = ['method' => null, 'url' => null, 'options' => null];

        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured) {
            $captured = compact('method', 'url', 'options');

            return new MockResponse(json_encode([
                'location' => ['name' => 'Kyiv', 'country' => 'Ukraine'],
                'current'  => ['temp_c' => 12.3, 'condition' => ['text' => 'Sunny']],
            ]));
        });

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info');

        $svc = new WeatherService($http, 'api-key-123', 'https://api.test.com', $logger, 30);
        $svc->getWeatherData('Kyiv');

        $this->assertSame('GET', $captured['method']);

        $parts = parse_url($captured['url']);
        $this->assertSame('https', $parts['scheme']);
        $this->assertSame('api.test.com', $parts['host']);
        $this->assertSame('/current.json', $parts['path']);

        parse_str($parts['query'] ?? '', $qs);
        $this->assertSame(['key' => 'api-key-123', 'q' => 'Kyiv'], $qs);

        $this->assertSame(['key' => 'api-key-123', 'q' => 'Kyiv'], $captured['options']['query']);

        $this->assertEquals(30, $captured['options']['timeout']);
    }
}
