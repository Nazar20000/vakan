<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Autoload.php';

$error        = null;
$results      = [];
$lastRequests = [];

try {
    $pdo  = Database::getConnection();
    $repo = new AddressRepository($pdo);
} catch (Throwable $e) {
    $error = 'Ошибка подключения к БД: ' . htmlspecialchars($e->getMessage());
    $repo  = null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $repo !== null) {
    $address = trim((string)($_POST['address'] ?? ''));

    if ($address === '') {
        $error = 'Пожалуйста, введите адрес.';
    } else {
        try {
            $geocoder = new Geocoder();
            $results  = $geocoder->geocode($address, 5);

            $repo->saveUnique($address);
        } catch (Throwable $e) {
            $error = 'Ошибка при запросе к геокодеру: ' . htmlspecialchars($e->getMessage());
        }
    }
}

if ($repo !== null) {
    try {
        $lastRequests = $repo->getLast(10);
    } catch (Throwable $e) {
        // Не критично для приложения
    }
}

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Геокодер адресов Москвы</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="css/style.css" rel="stylesheet">
</head>
<body>
<div class="container">
    <h1>Геокодер адресов Москвы</h1>
    <p class="muted">
        Введите адрес (улица и дом) в Москве. Приложение обратится к API Яндекс Геокодера,
        сохранит уникальный запрос в MySQL и покажет до 5 найденных результатов.
    </p>

    <?php if ($error !== null): ?>
        <div class="error"><?= $error ?></div>
    <?php endif; ?>

    <form method="post">
        <label for="address">Адрес:</label><br>
        <input
            type="text"
            id="address"
            name="address"
            placeholder="Например: Москва, Тверская 7"
            value="<?= htmlspecialchars((string)($_POST['address'] ?? '')) ?>"
        >
        <button type="submit">Поиск</button>
    </form>

    <?php if (!empty($results)): ?>
        <div class="subtitle">Результаты (максимум 5)</div>
        <table>
            <thead>
            <tr>
                <th>Полный адрес</th>
                <th>Район</th>
                <th>Метро</th>
                <th>Широта</th>
                <th>Долгота</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($results as $row): ?>
                <tr>
                    <td><?= htmlspecialchars($row['full_address'] ?? '') ?></td>
                    <td><?= htmlspecialchars($row['district'] ?? '') ?></td>
                    <td><?= htmlspecialchars($row['metro'] ?? '') ?></td>
                    <td><?= htmlspecialchars($row['lat'] ?? '') ?></td>
                    <td><?= htmlspecialchars($row['lon'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if (!empty($lastRequests)): ?>
        <div class="subtitle">Последние уникальные запросы</div>
        <table>
            <thead>
            <tr>
                <th>Адрес</th>
                <th>Когда</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($lastRequests as $row): ?>
                <tr>
                    <td><?= htmlspecialchars($row['address']) ?></td>
                    <td><?= htmlspecialchars($row['created_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
</body>
</html>
