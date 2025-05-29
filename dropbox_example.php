<?php
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/utils/autoload.php';

use Stevenmaguire\OAuth2\Client\Provider\Dropbox;
use GuzzleHttp\Client;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . SLASH . 'credentials');
$dotenv->load();

session_start();

$appKey = $_ENV['DROPBOX_APP_KEY'];
$appSecret = $_ENV['DROPBOX_APP_SECRET'];
$redirectUri = 'http://localhost/WEB_2024-2025/dropbox_example.php';

$provider = new Dropbox([
    'clientId'     => $appKey,
    'clientSecret' => $appSecret,
    'redirectUri'  => $redirectUri,
]);

if (isset($_GET['code'])) {
    try {
        $accessToken = $provider->getAccessToken('authorization_code', [
            'code' => $_GET['code']
        ]);
        $_SESSION['access_token'] = $accessToken->getToken();
        header('Location: dropbox_example.php');
        exit;
    } catch (Exception $e) {
        echo "OAuth Error: " . htmlspecialchars($e->getMessage());
        exit;
    }
}

if (isset($_SESSION['access_token'])) {
    $accessToken = $_SESSION['access_token'];
} else {
    $accessToken = null;
}

if (!$accessToken) {
    $authUrl = $provider->getAuthorizationUrl([
        'token_access_type' => 'offline',
        'scope' => 'files.metadata.read'
    ]);
    $_SESSION['oauth2state'] = $provider->getState();
    echo "<a href='" . htmlspecialchars($authUrl) . "'>Sign in with Dropbox</a>";
    exit;
}

$client = new Client([
    'base_uri' => 'https://api.dropboxapi.com/2/',
    'headers' => [
        'Authorization' => 'Bearer ' . $accessToken,
        'Content-Type'  => 'application/json',
    ]
]);

try {
    $response = $client->post('files/list_folder', [
        'json' => ['path' => '']
    ]);
    $data = json_decode($response->getBody(), true);

    if (empty($data['entries'])) {
        echo "No files found.";
    } else {
        echo "<h2>Files:</h2><ul>";
        foreach ($data['entries'] as $file) {
            echo "<li>" . htmlspecialchars($file['name']) . " (" . htmlspecialchars($file['.tag']) . ")</li>";
        }
        echo "</ul>";
    }
    echo '<a href="?logout=1">Sign out</a>';
    echo '<br><a href="index.php">Back to provider selection</a>';
} catch (Exception $e) {
    unset($_SESSION['access_token']);
    echo "Error: " . htmlspecialchars($e->getMessage()) . "<br>";
    echo '<a href="dropbox_example.php">Try again</a>';
    exit;
}

if (isset($_GET['logout'])) {
    unset($_SESSION['access_token']);
    header('Location: dropbox_example.php');
    exit;
}