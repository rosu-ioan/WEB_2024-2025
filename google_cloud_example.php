<?php
require __DIR__ . '/vendor/autoload.php';

use Google\Client;
use Google\Service\Drive;

session_start();

$client = new Client();
$client->setApplicationName('Google Drive API PHP Quickstart');
$client->setScopes('https://www.googleapis.com/auth/drive.metadata.readonly');
$client->setAuthConfig('credentials/credentials.json');
$client->setAccessType('offline');
$client->setPrompt('select_account consent');

if (isset($_GET['code'])) {
    $accessToken = $client->fetchAccessTokenWithAuthCode($_GET['code']);
    if (isset($accessToken['error'])) {
        echo "OAuth Error: " . htmlspecialchars($accessToken['error_description'] ?? $accessToken['error']);
        exit;
    }
    $client->setAccessToken($accessToken);
    $_SESSION['access_token'] = $accessToken;
    header('Location: google_cloud_example.php');
    exit;
}

if (isset($_SESSION['access_token'])) {
    $client->setAccessToken($_SESSION['access_token']);
}

if (!$client->getAccessToken() || $client->isAccessTokenExpired()) {
    unset($_SESSION['access_token']);
    $authUrl = $client->createAuthUrl();
    echo "<a href='" . htmlspecialchars($authUrl) . "'>Sign in with Google</a>";
    exit;
}

if ($client->isAccessTokenExpired() && $client->getRefreshToken()) {
    $accessToken = $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
    $client->setAccessToken($accessToken);
    $_SESSION['access_token'] = $accessToken;
}

$service = new Drive($client);

try {
    $optParams = [
        'pageSize' => 10,
        'fields' => 'nextPageToken, files(id, name, size, createdTime, webViewLink)'
    ];
    $results = $service->files->listFiles($optParams);

    if (count($results->getFiles()) == 0) {
        echo "No files found.";
    } else {
        echo "<h2>Files:</h2><ul>";
        foreach ($results->getFiles() as $file) {
            echo "<li>" . htmlspecialchars($file->getName()) .
                " (" . htmlspecialchars($file->getId()) . ")" .
                (method_exists($file, 'getSize') && $file->getSize() ? " - " . $file->getSize() . " bytes" : "") .
                (method_exists($file, 'getWebViewLink') && $file->getWebViewLink() ? " - <a href='" . htmlspecialchars($file->getWebViewLink()) . "' target='_blank'>Open</a>" : "") .
                "</li>";
        }
        echo "</ul>";
    }
    echo '<a href="?logout=1">Sign out</a>';
    echo '<br><a href="index.php">Back to provider selection</a>';
} catch (Exception $e) {
    unset($_SESSION['access_token']);
    echo "Error: " . htmlspecialchars($e->getMessage()) . "<br>";
    echo '<a href="google_cloud_example.php">Try again</a>';
    exit;
}

if (isset($_GET['logout'])) {
    unset($_SESSION['access_token']);
    header('Location: google_cloud_example.php');
    exit;
}