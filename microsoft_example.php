<?php
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/utils/autoload.php';

use TheNetworg\OAuth2\Client\Provider\Azure;
use GuzzleHttp\Client;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . SLASH . 'credentials');
$dotenv->load();

session_start();

$clientId = $_ENV['AZURE_CLIENT_ID'];
$clientSecret = $_ENV['AZURE_CLIENT_SECRET'];
$redirectUri = 'http://localhost/WEB_2024-2025/microsoft_example.php'; // Make sure this matches your Azure registration

$provider = new Azure([
    'clientId'          => $clientId,
    'clientSecret'      => $clientSecret,
    'redirectUri'       => $redirectUri,
    'defaultEndPointVersion' => '2.0'
]);

if (isset($_GET['code'])) {
    try {
        $accessToken = $provider->getAccessToken('authorization_code', [
            'code' => $_GET['code']
        ]);
        $_SESSION['access_token'] = $accessToken->jsonSerialize();
        header('Location: microsoft_example.php');
        exit;
    } catch (Exception $e) {
        echo "OAuth Error: " . $e->getMessage();
        exit;
    }
}

if (isset($_SESSION['access_token'])) {
    $accessToken = new \League\OAuth2\Client\Token\AccessToken($_SESSION['access_token']);
} else {
    $accessToken = null;
}

if (!$accessToken || $accessToken->hasExpired()) {
    $authUrl = $provider->getAuthorizationUrl([
        'scope' => ['offline_access', 'Files.Read']
    ]);
    $_SESSION['oauth2state'] = $provider->getState();
    echo "<a href='$authUrl'>Sign in with Microsoft</a>";
    exit;
}

if ($accessToken->hasExpired() && $accessToken->getRefreshToken()) {
    $accessToken = $provider->getAccessToken('refresh_token', [
        'refresh_token' => $accessToken->getRefreshToken()
    ]);
    $_SESSION['access_token'] = $accessToken->jsonSerialize();
}

$httpClient = new Client([
    'base_uri' => 'https://graph.microsoft.com/v1.0/',
    'headers' => [
        'Authorization' => 'Bearer ' . $accessToken->getToken(),
        'Accept'        => 'application/json',
    ]
]);

try {
    $response = $httpClient->get('me/drive/root/children');
    $data = json_decode($response->getBody(), true);

    if (empty($data['value'])) {
        echo "No files found.";
    } else {
        echo "<h2>Files:</h2><ul>";
        foreach ($data['value'] as $file) {
            echo "<li>" . htmlspecialchars($file['name']) . " (" . 
                 (isset($file['size']) ? htmlspecialchars($file['size']) . " bytes" : "N/A") . 
                 ")</li>";
        }
        echo "</ul>";
    }
    echo '<a href="?logout=1">Sign out</a>';
    echo '<br><a href="index.php">Back to provider selection</a>';
} catch (\GuzzleHttp\Exception\ClientException $e) {
    unset($_SESSION['access_token']);
    echo "HTTP Error: " . $e->getMessage() . "<br>";
    echo "Response: " . $e->getResponse()->getBody() . "<br>";
    echo '<a href="index.php">Try again</a>';
    exit;
} catch (Exception $e) {
    unset($_SESSION['access_token']);
    echo "General Error: " . $e->getMessage() . "<br>";
    echo '<a href="index.php">Try again</a>';
    exit;
}

if (isset($_GET['logout'])) {
    unset($_SESSION['access_token']);
    header('Location: microsoft_example.php');
    exit;
}




