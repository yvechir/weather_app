<?php

namespace App\Controller;

use App\Service\WeatherService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * WeatherController returns JSON or HTML response.
 */
final class WeatherController extends AbstractController
{
    /**
     * JSON endpoint returning current weather for a given city.
     */
    #[Route('/weather/{city}', name: 'weather_json', methods: ['GET'])]
    public function renderJson(string $city, WeatherService $weather): Response
    {
        return $this->json($weather->getWeatherData($city));
    }

    /**
     * HTML UI page with a form to query weather and show results.
     */
    #[Route('/weather-ui', name: 'weather_ui', methods: ['GET'])]
    public function renderUi(Request $request, WeatherService $weather): Response
    {
        $city   = $request->query->getString('city', '');
        $result = $weather->getWeatherData($city);

        return $this->render('weather/weather-ui.html.twig', [
            'city'   => $city,
            'result' => $result,
        ]);
    }
}
