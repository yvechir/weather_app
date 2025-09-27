# Weather App (Symfony 7.3)

A small Symfony 7.3 app that fetches current weather from an external API, with a thin controller, a service layer, Twig + Bootstrap UI, a dedicated logging channel, and unit tests.

## Quick Start (Dev)

```
# Clone repo
git clone git@github.com:yvechir/weather_app.git 
cd weather_app

# Install dependencies
composer install

# Configure environment variables
cp .env .env.local
# Edit .env.local:
# API_KEY=<your weatherapi.com key>

# Start a dev server
symfony server:start 
```
Open in your browser (ports may vary):
```
UI: http://127.0.0.1:8000/weather-ui

JSON: http://127.0.0.1:8000/weather/London
```
# Architecture and Benefits of Autowiring & Thin Controllers
## Project Architecture

### Layers & Responsibilities

- Controller (UI/API entry): Receives HTTP requests, delegates to services, returns JSON or renders Twig.

- Service (business logic): Calls the external API, normalizes data, handles errors, writes logs.

- View (Twig): Displays data (Bootstrap), contains no business logic.

- Infrastructure: HttpClient, Monolog, parameters/ENV, DI container.

### Request Flow
```
Request → Controller → WeatherService (HttpClient) → External API
                                   ↓
                      normalized array | ['error' => ...]
                                   ↓
               JSON (API) / Twig (UI) response

```
### Key Components

- WeatherController — two actions: /weather/{city} (JSON) and /weather-ui (Twig).

- WeatherService — encapsulates everything: base URL, timeout, request, parsing, 4xx/5xx & {"error": ...} checks, exception handling (network/decoding/unexpected), logging to the weather channel.

- monolog.yaml — dedicated handler/formatter for plain message log lines into var/log/weather_log.log.

## Autowiring

The DI container automatically wires dependencies by type-hint and/or attributes, so you don’t need to manually configure every service.

In this service

```
    public function __construct(
        private readonly HttpClientInterface                                            $http,    //by type
        #[Autowire(env: 'API_KEY')] private readonly string                             $apiKey,  //from .env
        #[Autowire(param: 'app.api_url')] private string                                $baseUrl, //from parameters 
        #[Autowire(service: 'monolog.logger.weather')] private readonly LoggerInterface $logger,  //specific servive
        #[Autowire(param: 'app.api_timeout')] private readonly int                      $timeout  //from parameters 
    )
    {
        $this->baseUrl = rtrim($this->baseUrl, '/');
    }
```
### Benefits
- Less boilerplate: minimal config and no manual property assignments (PHP 8 property promotion).

- Loose coupling: easily swap in tests.

- Explicit sources: attributes show env/param/service right in the code.

- Production speed: compiled container → minimal runtime overhead.

- Scalability: add new services with little to no extra configuration.

## Thin Controllers

Principle: controllers contain no business logic; they only bridge HTTP and services.

### Benefits

- Testability: business logic is unit-tested at the service layer.

- Maintainability: less code in the web layer = fewer regressions.

- Reusability: the same service can power API, UI, CLI, and background jobs.

- Clear separation of concerns: controllers don’t know about API details/errors/logging.

- Safer refactors: external API changes affect only the service.

## Errors handling

| Situation                               | How it’s caught                        | What we return                                                  | What we log                                                                 |
| --------------------------------------- | -------------------------------------- | --------------------------------------------------------------- | --------------------------------------------------------------------------- |
| HTTP 4xx/5xx                            | `status >= 400` after `toArray(false)` | `['error' => message]`                                 | `YYYY-mm-dd HH:ii:ss - Weather App ERROR for "{city}": {msg} (HTTP {code})` |
| Valid JSON with `{"error": ...}` on 2xx | `isset($data['error'])`                | `['error' => message]`                                          | same `ERROR for "{city}"...`                                                |
| Malformed JSON                          | `catch (DecodingExceptionInterface)`   | `['error' => 'Invalid response from weather service']`          | `... EXCEPTION while decoding: {e->getMessage()}`                           |
| Network failures                        | `catch (TransportExceptionInterface)`  | `['error' => 'Network error, please try again']`                | `... EXCEPTION (network): {e->getMessage()}`                                |
| Unexpected                              | `catch (\Throwable)`                   | `['error' => 'Unexpected error while calling weather service']` | `... EXCEPTION (unexpected): {e->getMessage()}`                             |

## Design patterns

### MVC (Model–View–Controller)

- Where: WeatherController (C), Twig template weather-ui.html.twig (V), domain logic in WeatherService (acting as the model/service layer).

- Why: clear separation of concerns: the controller is thin, all data work lives outside it.

### Dependency Injection & Inversion of Control

- Where: the WeatherService constructor receives HttpClientInterface, a channelled LoggerInterface, and scalar parameters via #[Autowire(...)].

- Why: loose coupling and easy testing (mock the client/logger in tests).

### Service Layer

- Where: WeatherService encapsulates external API calls, response normalization, error handling, and logging.

- Why: keep controllers minimal and concentrate business logic in one place.

### Facade / Gateway to the external API

- Where: WeatherService::getWeatherData() hides HTTP call details, base URL, timeouts, and response format.

- Why: give clients (controllers/other services) a simple, single-method access to weather data.

### Adapter / Anti-Corruption Layer

- Where: mapping the raw API response (location/current) to our internal format:
['city','country','temperature','condition','humidity','wind_speed','last_updated'].

- Why: isolate from external API changes and maintain a stable internal contract.

### Template View

- Where: Twig (weather-ui.html.twig) renders HTML from data provided by the controller.

- Why: clean separation of presentation and logic.

### Chain of Responsibility (in the Monolog stack)

- Where: Monolog config: a dedicated handler for the weather channel with bubble: false.

- Why: write weather logs to its own file (weather_log.log) and prevent them from propagating further in the chain.

### Strategy (interchangeable implementations)

- Where: Monolog formatters/handlers are swappable; HttpClientInterface can have multiple implementations.

- Why: configurability without code changes.

### Abstract Factory (via the DI container)

- Where: the Symfony DI container creates services and manages their lifecycle.

- Why: centralized dependency creation, caching, and a compiled container for production.

## Logging

This project uses Monolog and a dedicated weather channel for weather-related events. The channel’s logs are written to their own file as plain lines (without context/extra), making them easy to read/parse.

### Where logs are stored

- Weather logs: var/log/weather_log.log (channel weather)

- Application general logs:var/log/dev.log


### How to view

```
tail -f var/log/weather_log.log

```
## Testing

This project ships with PHPUnit unit tests focused on the service layer (WeatherService). Tests run without booting the Symfony kernel — fast, isolated, and easy to maintain.

### Run tests
```
# all tests
php bin/phpunit

# a single test method (by name)
php bin/phpunit --filter testBuildsCorrectUrlQueryAndTimeout
```


### What’s covered

- Happy path — valid JSON is normalized to:
city, country, temperature, condition, humidity, wind_speed, last_updated, and an info log line is emitted.

- HTTP errors (4xx/5xx) — the API’s message is returned under error, and an error log line contains the HTTP code.

- Invalid JSON — returns a friendly error: Invalid response from weather service and logs a decoding exception.

- Network/timeout (transport) errors — returns Network error, please try again and logs a transport exception.

- Unexpected exceptions — returns Unexpected error while calling weather service and logs an unexpected exception.

- Request shape — verifies method GET, path /current.json, query params key & q, and the configured timeout.
