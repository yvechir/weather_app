<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches weather data from external API.
 */
final class WeatherService
{
    private const ENDPOINT_CURRENT = '/current.json';

    public function __construct(
        private readonly HttpClientInterface                                            $http,
        #[Autowire(env: 'API_KEY')] private readonly string                             $apiKey,
        #[Autowire(param: 'app.api_url')] private string                                $baseUrl,
        #[Autowire(service: 'monolog.logger.weather')] private readonly LoggerInterface $logger,
        #[Autowire(param: 'app.api_timeout')] private readonly int                      $timeout
    )
    {
        $this->baseUrl = rtrim($this->baseUrl, '/');
    }

    /**
     * Returns normalized current weather for a given city.
     */
    public function getWeatherData(string $city): array
    {
        $endpoint = $this->baseUrl . self::ENDPOINT_CURRENT;

        try {
            $response = $this->http->request('GET', $endpoint, [
                'query' => ['key' => $this->apiKey, 'q' => $city],
                'timeout' => $this->timeout,
            ]);

            $status = $response->getStatusCode();
            $data = $response->toArray(false);

            if ($status >= 400) {
                $message = $data['error']['message'] ?? sprintf('HTTP %d from weather API', $status);

                // api error log
                $this->logger->error(sprintf(
                    '%s - Weather App ERROR for "%s": %s (HTTP %d)',
                    (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                    $city,
                    $message,
                    $status
                ));

                return ['error' => $message];
            }

            if (isset($data['error'])) {
                $message = is_array($data['error']) ? ($data['error']['message'] ?? 'Unknown API error') : (string)$data['error'];
                // if status 200, but data has an error
                $this->logger->error(sprintf(
                    '%s - Weather App ERROR for "%s": %s (HTTP %d)',
                    (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                    $city,
                    $message,
                    $status
                ));
                return ['error' => $message];
            }

            $result = [
                'city' => (string)($data['location']['name'] ?? ''),
                'country' => (string)($data['location']['country'] ?? ''),
                'temperature' => (float)($data['current']['temp_c'] ?? 0),
                'condition' => (string)($data['current']['condition']['text'] ?? ''),
                'humidity' => (int)($data['current']['humidity'] ?? 0),
                'wind_speed' => (float)($data['current']['wind_kph'] ?? 0),
                'last_updated' => (string)($data['current']['last_updated'] ?? ''),
            ];

            // valid weather logs
            $this->logger->info(sprintf(
                '%s - Weather in %s: %s°C, %s',
                (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                $result['city'],
                $result['temperature'],
                $result['condition']
            ));

            return $result;

        } catch (DecodingExceptionInterface $e) {
            // invalind JSON.
            $this->logger->error(sprintf(
                '%s - Weather App EXCEPTION while decoding: %s',
                (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                $e->getMessage()
            ));
            return ['error' => 'Invalid response from weather service'];

        } catch (TransportExceptionInterface $e) {
            // network exceptions.
            $this->logger->error(sprintf(
                '%s - Weather App EXCEPTION (network): %s',
                (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                $e->getMessage()
            ));
            return ['error' => 'Network error, please try again'];

        } catch (\Throwable $e) {
            // unexpected exceptions
            $this->logger->error(sprintf(
                '%s - Weather App EXCEPTION (unexpected): %s',
                (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                $e->getMessage()
            ));
            return ['error' => 'Unexpected error while calling weather service'];
        }
    }
}
