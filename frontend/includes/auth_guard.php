<?php
session_start();
if (empty($_SESSION['access_token']) || empty($_SESSION['role']) || $_SESSION['role'] !== $requiredRole) {
    header('Location: ../login.php'); exit;
}
// A session alone is not proof the JWT is still valid; the backend must validate
// the Bearer token on every protected API request.
