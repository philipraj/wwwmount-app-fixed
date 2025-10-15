<?php
// Defines the base URL for all links, now using https
define('BASE_URL', 'https://app.mountgraph.com/');

// Start the session on every page
session_start();

// Database credentials
$servername = "localhost";
$username = "wwwmount_app_user";
$password = "mGraph@2021";
$dbname = "wwwmount_app";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}
?>