<?php
/**
 * auth.php - Gestione login/logout
 */

require_once __DIR__ . '/config.php';

function isUserLoggedIn() {
    session_start();
    return isset($_SESSION['repobox_user_id']);
}

function requireLogin() {
    if (!isUserLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}

function loginUser($username, $password) {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT id, password_hash FROM repobox_users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        session_start();
        $_SESSION['repobox_user_id'] = $user['id'];
        $_SESSION['repobox_username'] = $username;
        return true;
    }
    return false;
}

function logoutUser() {
    session_start();
    unset($_SESSION['repobox_user_id'], $_SESSION['repobox_username']);
    session_destroy();
}