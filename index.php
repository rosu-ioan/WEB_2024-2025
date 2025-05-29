<?php
session_start();

echo "<h2>Sign in to view your cloud files</h2>";
echo "<ul>";
echo "<li><a href='google_cloud_example.php'>Sign in with Google Drive</a></li>";
echo "<li><a href='microsoft_example.php'>Sign in with Microsoft OneDrive</a></li>";
echo "<li><a href='dropbox_example.php'>Sign in with Dropbox</a></li>";
echo "</ul>";