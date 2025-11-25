<?php

class Geocoder
{
    private string $apiKey;

    public function __construct(?string $apiKey = null)
    {

        $this->apiKey = '858e87f6-1ba0-4897-8c4f-1222ee3e6e84';

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
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new RuntimeException("Geocoder HTTP error: {$httpCode}");
        }

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

            foreach ($components as $comp) {
                $kind = $comp['kind'] ?? null;
                if ($kind === 'district') {
                    $district = $comp['name'] ?? null;
                }
                if ($kind === 'metro') {
                    $metro = $comp['name'] ?? null;
                }
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
                'lat'          => $lat,
                'lon'          => $lon,
            ];
        }

        return $result;
    }
}
