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

    public function geocode(string $address, int $limit = 5): array
    {
        $query = http_build_query([
            'apikey'   => $this->apiKey,
            'format'   => 'json',
            'geocode'  => $address,
            'lang'     => 'ru_RU',
            'results'  => $limit,
            'kind'     => 'house',
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
            $addressData = $meta['Address'] ?? [];
            $addressDetails = $geo['metaDataProperty']['GeocoderMetaData']['AddressDetails'] ?? [];

            $district = null;
            $metro    = null;
            $street   = null;
            $house    = null;
            $isMoscow = false;
            
            // Получаем координаты заранее для возможного поиска района
            $pos = $geo['Point']['pos'] ?? '';
            $lat = $lon = null;
            if (\is_string($pos) && $pos !== '') {
                [$lon, $lat] = explode(' ', $pos);
            }

            foreach ($components as $comp) {
                $kind = $comp['kind'] ?? null;
                $name = $comp['name'] ?? '';

                if ($kind === 'locality' && $name === 'Москва') {
                    $isMoscow = true;
                }
                
                if ($district === null) {
                    if (in_array($kind, [
                        'district', 
                        'area', 
                        'administrative_area_level_3',
                        'administrative_area_level_2',
                        'subAdministrativeArea',
                        'administrative',
                        'locality_area'
                    ])) {
                        if ($name !== 'Москва' && $name !== 'Moscow') {
                            $district = $name;
                        }
                    }
                }
                
                if ($kind === 'street') {
                    $street = $name;
                }
                if ($kind === 'house') {
                    $house = $name;
                }
            }
            
            if ($district === null && !empty($addressDetails)) {
                $adminArea = $addressDetails['Country']['AdministrativeArea'] ?? [];
                $subAdminArea = $adminArea['SubAdministrativeArea'] ?? [];
                $locality = $adminArea['Locality'] ?? [];
                $dependentLocality = $locality['DependentLocality'] ?? [];
                $districtData = $locality['District'] ?? [];
                
                // Проверяем District в Locality (основное место для районов Москвы)
                if (!empty($districtData['DistrictName'])) {
                    $districtName = $districtData['DistrictName'];
                    if ($districtName !== 'Москва' && $districtName !== 'Moscow' && !empty($districtName)) {
                        $district = $districtName;
                    }
                }
                
                // Проверяем SubAdministrativeArea
                if ($district === null && !empty($subAdminArea['SubAdministrativeAreaName'])) {
                    $subName = $subAdminArea['SubAdministrativeAreaName'];
                    if ($subName !== 'Москва' && $subName !== 'Moscow' && !empty($subName)) {
                        $district = $subName;
                    }
                }
                
                // Проверяем DependentLocality
                if ($district === null && !empty($dependentLocality['DependentLocalityName'])) {
                    $depName = $dependentLocality['DependentLocalityName'];
                    if ($depName !== 'Москва' && $depName !== 'Moscow' && !empty($depName)) {
                        $district = $depName;
                    }
                }
            }
            
            // Дополнительная проверка в addressData
            if ($district === null) {
                if (isset($addressData['SubAdministrativeAreaName']) && 
                    $addressData['SubAdministrativeAreaName'] !== 'Москва' &&
                    !empty($addressData['SubAdministrativeAreaName'])) {
                    $district = $addressData['SubAdministrativeAreaName'];
                } elseif (isset($addressData['DependentLocalityName']) && 
                         $addressData['DependentLocalityName'] !== 'Москва' &&
                         !empty($addressData['DependentLocalityName'])) {
                    $district = $addressData['DependentLocalityName'];
                } elseif (isset($addressData['DistrictName']) &&
                         $addressData['DistrictName'] !== 'Москва' &&
                         !empty($addressData['DistrictName'])) {
                    $district = $addressData['DistrictName'];
                }
            }
            
            // Если район все еще не найден, пытаемся найти его через обратный геокодинг
            if ($district === null && $lat !== null && $lon !== null) {
                $district = $this->findDistrictByCoordinates($lat, $lon);
            }

            if (!$isMoscow) {
                continue;
            }

            if ($metro === null && $lat !== null && $lon !== null) {
                $metro = $this->findNearestMetro($lat, $lon);
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

    private function findDistrictByCoordinates(string $lat, string $lon): ?string
    {
        try {
            $query = http_build_query([
                'apikey'   => $this->apiKey,
                'format'   => 'json',
                'geocode'  => "{$lon},{$lat}",
                'kind'     => 'district',
                'lang'     => 'ru_RU',
                'results'  => 1,
            ]);

            $url = "https://geocode-maps.yandex.ru/1.x/?{$query}";
            
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 5,
            ]);

            $raw = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200 && $raw !== false) {
                $data = json_decode($raw, true);
                
                if (isset($data['response']['GeoObjectCollection']['featureMember'][0])) {
                    $item = $data['response']['GeoObjectCollection']['featureMember'][0];
                    $geo = $item['GeoObject'] ?? [];
                    $name = $geo['name'] ?? '';
                    
                    // Убираем префиксы типа "район", "Район"
                    $name = preg_replace('/^(район\s+|Район\s+)/ui', '', $name);
                    $name = trim($name);
                    
                    if (!empty($name) && $name !== 'Москва' && $name !== 'Moscow') {
                        return $name;
                    }
                }
            }
        } catch (Throwable $e) {
        }
        
        return null;
    }

    private function findNearestMetro(string $lat, string $lon): ?string
    {
        try {
            $query = http_build_query([
                'apikey'   => $this->apiKey,
                'format'   => 'json',
                'geocode'  => "{$lon},{$lat}",
                'kind'     => 'metro',
                'lang'     => 'ru_RU',
                'results'  => 5,
            ]);

            $url = "https://geocode-maps.yandex.ru/1.x/?{$query}";
            
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
            ]);

            $raw = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200 && $raw !== false) {
                $data = json_decode($raw, true);
                
                if (isset($data['response']['GeoObjectCollection']['featureMember'])) {
                    foreach ($data['response']['GeoObjectCollection']['featureMember'] as $item) {
                        $geo = $item['GeoObject'] ?? [];
                        $name = $geo['name'] ?? '';
                        
                        if (!empty($name) && stripos($name, 'метро') !== false) {
                            $metroName = preg_replace('/\s*(станция\s*)?метро\s*/ui', '', $name);
                            $metroName = trim($metroName);
                            if (!empty($metroName)) {
                                return $metroName;
                            }
                            return $name;
                        }
                    }
                }
            }
        } catch (Throwable $e) {
        }
        
        return null;
    }

    private function searchMetroInResponse(string $url): ?string
    {
        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);

            $raw = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200 || $raw === false || empty($raw)) {
                return null;
            }

            $data = json_decode($raw, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                return null;
            }
            
            $features = $data['features'] ?? $data['results'] ?? [];
            
            if (!is_array($features) || empty($features)) {
                return null;
            }
            
            foreach ($features as $feature) {
                $properties = $feature['properties'] ?? $feature ?? [];
                $companyMeta = $properties['CompanyMetaData'] ?? [];
                
                $name = $properties['name'] ?? $companyMeta['name'] ?? '';
                $description = $properties['description'] ?? $companyMeta['description'] ?? '';
                $categories = $properties['Categories'] ?? $companyMeta['Categories'] ?? [];
                
                $isMetro = false;
                if (is_array($categories) && !empty($categories)) {
                    foreach ($categories as $cat) {
                        $catName = '';
                        if (is_array($cat)) {
                            $catName = $cat['name'] ?? $cat['class'] ?? '';
                        } else {
                            $catName = (string)$cat;
                        }
                        
                        if (stripos($catName, 'метро') !== false || 
                            stripos($catName, 'транспорт') !== false ||
                            stripos($catName, 'subway') !== false ||
                            stripos($catName, 'metro') !== false) {
                            $isMetro = true;
                            break;
                        }
                    }
                }
                
                if ($isMetro || 
                    stripos($name, 'метро') !== false || 
                    stripos($description, 'метро') !== false ||
                    (stripos($name, 'станция') !== false && 
                     (stripos($name, 'метро') !== false || stripos($description, 'метро') !== false))) {
                    
                    $metroName = preg_replace('/\s*(станция\s*)?метро\s*/ui', '', $name);
                    $metroName = preg_replace('/\s*станция\s*/ui', '', $metroName);
                    $metroName = preg_replace('/\s*м\.\s*/ui', '', $metroName);
                    $metroName = trim($metroName);
                    
                    if (empty($metroName) || strlen($metroName) < 3) {
                        if (!empty($description)) {
                            $metroName = preg_replace('/\s*(станция\s*)?метро\s*/ui', '', $description);
                            $metroName = trim($metroName);
                        }
                    }
                    
                    if (!empty($metroName) && strlen($metroName) > 2) {
                        return $metroName;
                    }
                    
                    if (!empty($name) && stripos($name, 'метро') !== false) {
                        return $name;
                    }
                }
            }
        } catch (Throwable $e) {
        }

        return null;
    }
}
