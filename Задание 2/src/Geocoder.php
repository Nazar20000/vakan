<?php

class Geocoder
{
    private string $apiKey;

    public function __construct(?string $apiKey = null)
    {
        $this->apiKey = $apiKey ?? getenv('YANDEX_API_KEY') ?: '';

        if ($this->apiKey === '') {
            throw new RuntimeException('YANDEX_API_KEY is not configured');
        }
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    public function geocode(string $address, int $limit = 5): array
    {
        $query = http_build_query([
            'apikey'   => $this->apiKey,
            'format'   => 'json',
            'geocode'  => $address,
            'lang'     => 'ru_RU',
            'results'  => $limit,
        ]);

        $url = "https://geocode-maps.yandex.ru/1.x/?{$query}";


        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);

        $raw = curl_exec($ch);

        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('cURL error: ' . $error);
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($httpCode !== 200) {
            $errorBody = $raw !== false ? $raw : 'No response body';
            curl_close($ch);
            throw new RuntimeException("Geocoder HTTP error: {$httpCode}. Response: " . substr($errorBody, 0, 200));
        }
        
        curl_close($ch);

        $data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

        return $this->parseResponse($data, $limit);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, array<string, string|null>>
     */
    private function parseResponse(array $data, int $limit): array
    {
        $result = [];

        $members = $data['response']['GeoObjectCollection']['featureMember'] ?? [];

        foreach ($members as $item) {
            if (\count($result) >= $limit) {
                break;
            }

            $geo  = $item['GeoObject'] ?? [];
            $meta = $geo['metaDataProperty']['GeocoderMetaData'] ?? [];
            $addressText = $meta['text'] ?? '';

            $components = $meta['Address']['Components'] ?? [];

            $district = null;
            $metro    = null;
            $street   = null;
            $house    = null;
            $isMoscow = false;

            foreach ($components as $comp) {
                $kind = $comp['kind'] ?? null;
                $name = $comp['name'] ?? '';

                if ($kind === 'locality' && $name === 'Москва') {
                    $isMoscow = true;
                }
                if ($kind === 'district') {
                    $district = $name;
                }
                if ($kind === 'metro') {
                    $metro = $name;
                }
                if ($kind === 'street') {
                    $street = $name;
                }
                if ($kind === 'house') {
                    $house = $name;
                }
            }

            // Пропускаем адреса не из Москвы
            if (!$isMoscow) {
                continue;
            }

            $pos = $geo['Point']['pos'] ?? '';
            $lat = $lon = null;

            if (\is_string($pos) && $pos !== '') {
                [$lon, $lat] = explode(' ', $pos);
            }

            $result[] = [
                'full_address' => $addressText,
                'district'     => $district,
                'metro'        => $metro,
                'street'       => $street,
                'house'        => $house,
                'lat'          => $lat,
                'lon'          => $lon,
            ];
        }

        return $result;
    }
}
