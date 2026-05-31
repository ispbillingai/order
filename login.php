<?php
/**
 * Login Page
 * Restaurant POS System
 */

require_once __DIR__ . '/config/database.php';

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    
    if ($user) {
        redirectToRole($user['role']);
    }
}

$error = '';

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password.';
    } else {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND active = 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password'])) {
            // Login successful
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['user_name'] = $user['full_name'];
            
            // Log activity
            $stmt = $pdo->prepare("INSERT INTO activity_log (user_id, action, ip_address) VALUES (?, 'login', ?)");
            $stmt->execute([$user['id'], $_SERVER['REMOTE_ADDR'] ?? null]);
            
            redirectToRole($user['role']);
        } else {
            $error = 'Invalid username or password.';
        }
    }
}

function redirectToRole($role) {
    switch ($role) {
        case 'admin':
            header('Location: /admin/index.php');
            break;
        case 'waiter':
            header('Location: /waiter/index.php');
            break;
        case 'cashier':
            header('Location: /cashier/index.php');
            break;
        case 'kitchen':
            header('Location: /kitchen/index.php');
            break;
        default:
            header('Location: /index.php');
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - RestoPOS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Space+Mono:wght@700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="login-page">
    <div class="login-container">
        <div class="login-logo">
            <i class="fas fa-utensils"></i>
            <h1>RestoPOS</h1>
            <p class="text-muted">Restaurant Management System</p>
        </div>
        
        <?php if ($error): ?>
            <div class="login-error">
                <i class="fas fa-exclamation-circle"></i>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" class="login-form">
            <div class="form-group">
                <i class="fas fa-user"></i>
                <input type="text" name="username" class="form-control" placeholder="Username" 
                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required autofocus>
            </div>
            
            <div class="form-group">
                <i class="fas fa-lock"></i>
                <input type="password" name="password" class="form-control" placeholder="Password" required>
            </div>
            
            <button type="submit" class="btn btn-primary btn-lg btn-block">
                <i class="fas fa-sign-in-alt"></i>
                Sign In
            </button>
        </form>
        
        <div class="mt-lg text-center text-muted" style="font-size: 0.85rem;">
            <p><strong>Demo Credentials:</strong></p>
            <p>Admin: admin / password</p>
            <p>Waiter: waiter1 / password</p>
            <p>Cashier: cashier1 / password</p>
            <p>Kitchen: kitchen1 / password</p>
        </div>
    </div>
</body>
</html>
