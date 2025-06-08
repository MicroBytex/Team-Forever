<?php
// Start session for potential future use (e.g., user authentication)
session_start();

// Ensure JSON files exist
if (!file_exists('grupos.json')) {
    file_put_contents('grupos.json', json_encode([]));
}
if (!file_exists('users.json')) {
    file_put_contents('users.json', json_encode([]));
}

$error_message = '';
$error_message_reg = '';
$error_message_login = '';
$success_message_login = '';
$group_uploaded_success = false;
$registered_success = false;

// --- Helper Function for hCaptcha Verification (Conceptual) ---
// In a real application, you'd use cURL to verify hCaptcha server-side.
// This is a placeholder for demonstration.
function verify_hcaptcha($response) {
    // Replace with your actual hCaptcha secret key
    // You can get this from your hCaptcha dashboard.
    $secret_key = 'b7f042ec-0a80-4be0-8c0a-e0daaf248e66'; // <<< IMPORTANT: Replace this

    // In a production environment, uncomment the following cURL code:
    /*
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://hcaptcha.com/siteverify');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'secret' => $secret_key,
        'response' => $response
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $verify_response = curl_exec($ch);
    curl_close($ch);

    $decoded_response = json_decode($verify_response, true);
    return $decoded_response['success'];
    */

    // For this example, we'll just check if the response is not empty.
    // REMEMBER: THIS IS NOT SECURE FOR PRODUCTION.
    return !empty($response);
}

// --- Logic for handling group uploads ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_group') {
    $nombre_grupo = htmlspecialchars($_POST['nombre_grupo'] ?? '');
    $descripcion = htmlspecialchars($_POST['descripcion'] ?? '');
    $tags = htmlspecialchars($_POST['tags'] ?? '');
    $link_grupo = htmlspecialchars($_POST['link_grupo'] ?? '');
    $hcaptcha_response = $_POST['h-captcha-response'] ?? '';

    if (empty($nombre_grupo) || empty($descripcion) || empty($link_grupo)) {
        $error_message = "Todos los campos obligatorios deben ser completados.";
    } elseif (verify_hcaptcha($hcaptcha_response)) {
        $grupos = json_decode(file_get_contents('grupos.json'), true);
        $nuevo_grupo = [
            'id' => uniqid(), // Generate a unique ID for the group
            'nombre' => $nombre_grupo,
            'descripcion' => $descripcion,
            'tags' => array_map('trim', explode(',', $tags)), // Convert tags to an array and clean spaces
            'link' => $link_grupo
        ];
        $grupos[] = $nuevo_grupo;
        file_put_contents('grupos.json', json_encode($grupos, JSON_PRETTY_PRINT));
        $group_uploaded_success = true;
        header('Location: ' . $_SERVER['PHP_SELF'] . '?group_uploaded=true'); // Redirect to clear POST
        exit();
    } else {
        $error_message = "Por favor, completa el hCaptcha correctamente.";
    }
}

// --- Logic for handling user registration ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'register_user') {
    $username = htmlspecialchars($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $hcaptcha_response = $_POST['h-captcha-response'] ?? '';

    if (empty($username) || empty($password)) {
        $error_message_reg = "El usuario y la contraseña son obligatorios.";
    } elseif (verify_hcaptcha($hcaptcha_response)) {
        $users = json_decode(file_get_contents('users.json'), true);
        $user_exists = false;
        foreach ($users as $user) {
            if ($user['username'] === $username) {
                $user_exists = true;
                break;
            }
        }

        if (!$user_exists) {
            $nuevo_usuario = [
                'username' => $username,
                'password' => password_hash($password, PASSWORD_DEFAULT) // Hash the password
            ];
            $users[] = $nuevo_usuario;
            file_put_contents('users.json', json_encode($users, JSON_PRETTY_PRINT));
            $registered_success = true;
            header('Location: ' . $_SERVER['PHP_SELF'] . '?registered=true');
            exit();
        } else {
            $error_message_reg = "El nombre de usuario ya existe.";
        }
    } else {
        $error_message_reg = "Por favor, completa el hCaptcha correctamente.";
    }
}

// --- Logic for handling user login ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login_user') {
    $username = htmlspecialchars($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $hcaptcha_response = $_POST['h-captcha-response'] ?? '';

    if (empty($username) || empty($password)) {
        $error_message_login = "El usuario y la contraseña son obligatorios.";
    } elseif (verify_hcaptcha($hcaptcha_response)) {
        $users = json_decode(file_get_contents('users.json'), true);
        $authenticated = false;
        foreach ($users as $user) {
            if ($user['username'] === $username && password_verify($password, $user['password'])) {
                $authenticated = true;
                // In a real application, you would set session variables here
                // $_SESSION['loggedin'] = true;
                // $_SESSION['username'] = $username;
                break;
            }
        }

        if ($authenticated) {
            $success_message_login = "¡Inicio de sesión exitoso!";
            // Redirect or update UI as logged in
        } else {
            $error_message_login = "Usuario o contraseña incorrectos.";
        }
    } else {
        $error_message_login = "Por favor, completa el hCaptcha correctamente.";
    }
}

// Check for redirection messages
if (isset($_GET['group_uploaded']) && $_GET['group_uploaded'] == 'true') {
    $group_uploaded_success = true;
}
if (isset($_GET['registered']) && $_GET['registered'] == 'true') {
    $registered_success = true;
}

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#9dca1d">
    <title>Team Forever</title>
    <script src="https://js.hcaptcha.com/1/api.js" async defer></script>
    <link rel="icon" href="https://i.postimg.cc/7Zwkmghd/Chat-GPT-Image-8-jun-2025-02-45-12-a-m.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        body {
            font-family: sans-serif;
            margin: 0;
            padding: 0;
            background-color: #E0E0CC; /* 4chan style background color */
            color: #800; /* Main text color */
            font-size: 13px;
        }

        #homePageBody {
            margin: auto;
            text-align: left;
            width: 57.69em; /* This is what 4chan has specified... */
            min-width: 750px;
            padding: 20px 0; /* Top and bottom padding for main content */
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0; /* Adjust padding for a more compact look */
            background-color: #FED; /* Top bar background color */
            border-bottom: 1px solid #D9BFB7; /* Subtle bottom border */
        }
        .header-left {
            font-size: 24px; /* Maintain readable size for title */
            font-weight: bold;
            color: #800; /* Consistent color */
            margin-left: 20px; /* Small margin for separation */
        }
        .header-right {
            display: flex;
            align-items: center;
            margin-right: 20px; /* Small margin for separation */
        }
        .search-bar {
            padding: 5px; /* More compact */
            border: 1px solid #D9BFB7;
            border-radius: 0; /* 4chan style */
            margin-right: 10px;
            background-color: #fff; /* White background for input */
            color: #333;
        }
        .icon-container {
            display: flex;
            gap: 10px; /* Less space between icons */
            font-size: 16px; /* Slightly smaller icons */
        }
        .icon-container i {
            cursor: pointer;
            color: #800; /* 4chan link color */
        }
        .icon-container i:hover {
            color: #e00; /* 4chan link hover color */
        }

        /* General styles for content boxes */
        .box {
            border: 1px solid #D9BFB7;
            background-color: #FED;
            margin-bottom: 15px; /* Space between boxes */
        }
        .box-top {
            background-color: #D9BFB7; /* Box top border */
            color: #800;
            font-weight: bold;
            padding: 5px 10px;
            border-bottom: 1px solid #D9BFB7;
        }
        .box-content {
            padding: 10px;
        }

        /* Modal styles, adapted to 4chan style */
        .modal {
            display: none;
            position: fixed;
            z-index: 101; /* Higher than content, but below disclaimers */
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.6); /* Darker background */
            justify-content: center;
            align-items: center;
        }
        .modal-content {
            background-color: #FED; /* 4chan box background */
            border: 1px solid #800; /* More prominent border */
            margin: auto;
            padding: 20px;
            border-radius: 0; /* No rounded borders */
            width: 90%;
            max-width: 500px;
            box-shadow: 2px 2px 0 1px rgba(0,0,0,0.10); /* Subtle shadow */
            position: relative;
        }
        .close-button {
            color: #800;
            position: absolute;
            top: 5px;
            right: 10px;
            font-size: 24px; /* Slightly smaller */
            font-weight: bold;
            cursor: pointer;
        }
        .close-button:hover,
        .close-button:focus {
            color: black;
            text-decoration: none;
            cursor: pointer;
        }
        .modal-content h2 {
            text-align: center;
            margin-bottom: 15px;
            color: #800;
            font-size: 1.5em; /* Slightly larger */
        }
        .modal-content h3 {
            color: #800;
            margin-top: 20px;
            margin-bottom: 10px;
            border-bottom: 1px dashed #D9BFB7; /* Subtle separator */
            padding-bottom: 5px;
        }
        .modal-content label {
            display: block;
            margin-bottom: 5px;
            font-weight: normal; /* Not so bold */
            color: #800;
        }
        .modal-content input[type="text"],
        .modal-content input[type="password"],
        .modal-content textarea {
            width: calc(100% - 12px); /* Adjust for padding */
            padding: 5px;
            margin-bottom: 10px;
            border: 1px solid #D9BFB7;
            border-radius: 0;
            background-color: #fff;
            color: #333;
        }
        .modal-content textarea {
            resize: vertical;
            min-height: 60px; /* More compact */
        }
        .modal-content button {
            background-color: #800; /* 4chan accent color */
            color: white;
            padding: 8px 15px;
            border: none;
            border-radius: 0;
            cursor: pointer;
            font-size: 13px;
            width: 100%;
            margin-top: 10px;
        }
        .modal-content button:hover {
            background-color: #c63; /* 4chan hover color */
        }
        .hcaptcha-container {
            margin: 10px 0;
            display: flex;
            justify-content: center;
        }
        .message {
            padding: 8px;
            margin-bottom: 10px;
            border-radius: 0;
            text-align: center;
            border: 1px solid;
            font-size: 12px;
        }
        .message.success {
            background-color: #D4EDDA; /* Light green */
            color: #155724;
            border-color: #C3E6CB;
        }
        .message.error {
            background-color: #F8D7DA; /* Light red */
            color: #721C24;
            border-color: #F5C6CB;
        }

        .main-content {
            padding: 20px 0; /* Main body padding */
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            justify-content: center;
            /* Aligned with homePageBody */
            width: 57.69em;
            min-width: 750px;
            margin: auto;
        }
        .group-card {
            background-color: #FED; /* 4chan box background */
            border: 1px solid #D9BFB7;
            border-radius: 0;
            padding: 15px;
            width: calc(33.33% - 20px); /* Approximately 3 columns with gap */
            box-shadow: 2px 2px 0 1px rgba(0,0,0,0.05); /* Subtle shadow */
            text-align: left; /* Aligned left like posts */
            cursor: pointer;
            transition: none; /* No transitions for classic look */
            box-sizing: border-box; /* Include padding and border in width */
        }
        .group-card:hover {
            border-color: #c63; /* Hover border */
        }
        .group-card h3 {
            color: #800;
            margin-top: 0;
            margin-bottom: 5px;
            font-size: 1.1em;
        }
        .group-card p {
            font-size: 12px;
            color: #333;
            margin-bottom: 10px;
        }
        .group-card .tags span {
            display: inline-block;
            background-color: #E0E0CC; /* General background color */
            color: #800;
            padding: 3px 8px;
            border-radius: 0;
            font-size: 11px;
            margin: 3px 3px 0 0;
            border: 1px solid #D9BFB7;
        }

        /* Footer with 4chan style */
        #home-footer{
            font-size: 93%;
            text-align: center;
            clear: both;
            border-top: 1px solid #D9BFB7; /* hr color */
            margin-top: 40px; /* Top margin to separate from content */
            padding-top: 15px; /* Space before footer content */
            background-color: #FED; /* Footer background */
            color: #800;
        }

        #home-footer ul{
            display: table;
            margin: auto;
        }
        #home-footer li {
            background: #FED;
            display: block;
            float: left;
            border: 1px solid #D9BFB7; /* hr color */
            padding-left: 1em;
            padding-right: 1em;
            padding-bottom: 2px;
            border-left: none;
            margin-top: -1px;
            padding-top: 2px;
            font-size: 0.9em;
        }
        #home-footer li:first-child { /* Use first-child instead of specific ID if not necessary */
            border-left: 1px solid #D9BFB7;
        }
        #home-footer li a{
            color: #800;
            text-decoration: none;
        }
        #home-footer li a:hover{
            color: #c63;
        }
        .footer-text {
            padding-bottom: 15px; /* Space for copyright text */
            margin-top: 10px;
        }

        /* Basic responsive styles */
        @media (max-width: 768px) {
            #homePageBody {
                width: auto;
                min-width: unset;
                padding: 10px;
            }
            .header {
                flex-direction: column;
                align-items: flex-start;
                padding: 10px;
            }
            .header-right {
                margin-top: 10px;
                margin-right: 0;
                width: 100%;
                justify-content: space-between;
            }
            .search-bar {
                flex-grow: 1;
                margin-right: 5px;
            }
            .main-content {
                width: auto;
                min-width: unset;
                padding: 10px;
                gap: 15px;
            }
            .group-card {
                width: calc(50% - 10px); /* 2 columns on small screens */
            }
            #home-footer ul {
                display: block; /* Back to block for better stacking */
                width: auto;
                padding: 0 10px;
            }
            #home-footer li {
                float: none;
                display: block;
                border-left: 1px solid #D9BFB7; /* Restore left border for each item */
                margin-top: 5px;
            }
            #home-footer li:first-child {
                margin-top: 0;
            }
        }

        @media (max-width: 480px) {
            .group-card {
                width: 100%; /* 1 column on very small mobiles */
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-left">
            Team Forever
        </div>
        <div class="header-right">
            <input type="text" class="search-bar" placeholder="Buscar grupo...">
            <div class="icon-container">
                <i class="fas fa-plus" id="plusIcon"></i>
                <i class="fas fa-user" id="userIcon"></i>
                <i class="fas fa-cog" id="settingsIcon"></i>
            </div>
        </div>
    </div>

    <div id="homePageBody">
        <div id="addGroupModal" class="modal">
            <div class="modal-content">
                <span class="close-button" id="closeAddGroupModal">&times;</span>
                <h2>Añadir Nuevo Grupo</h2>
                <?php if ($group_uploaded_success): ?>
                    <div class="message success">¡Grupo subido exitosamente!</div>
                <?php endif; ?>
                <?php if (!empty($error_message) && isset($_POST['action']) && $_POST['action'] === 'upload_group'): ?>
                    <div class="message error"><?php echo $error_message; ?></div>
                <?php endif; ?>
                <form action="" method="POST">
                    <input type="hidden" name="action" value="upload_group">
                    <label for="nombre_grupo">Nombre del Grupo:</label>
                    <input type="text" id="nombre_grupo" name="nombre_grupo" required>

                    <label for="descripcion">Descripción:</label>
                    <textarea id="descripcion" name="descripcion" required></textarea>

                    <label for="tags">Tags (separados por coma):</label>
                    <input type="text" id="tags" name="tags" placeholder="Ej: Gaming, Anime, Programación">

                    <label for="link_grupo">Link del Grupo:</label>
                    <input type="text" id="link_grupo" name="link_grupo" required>

                    <div class="hcaptcha-container">
                        <div class="h-captcha" data-sitekey="b7f042ec-0a80-4be0-8c0a-e0daaf248e66"></div>
                    </div>

                    <button type="submit">Subir</button>
                </form>
            </div>
        </div>

        <div id="authModal" class="modal">
            <div class="modal-content">
                <span class="close-button" id="closeAuthModal">&times;</span>
                <h2>Acceso de Usuario</h2>

                <?php if ($registered_success): ?>
                    <div class="message success">¡Registro exitoso! Ya puedes iniciar sesión.</div>
                <?php endif; ?>
                <?php if (!empty($error_message_reg) && isset($_POST['action']) && $_POST['action'] === 'register_user'): ?>
                    <div class="message error"><?php echo $error_message_reg; ?></div>
                <?php endif; ?>

                <?php if (!empty($success_message_login) && isset($_POST['action']) && $_POST['action'] === 'login_user'): ?>
                    <div class="message success"><?php echo $success_message_login; ?></div>
                <?php endif; ?>
                <?php if (!empty($error_message_login) && isset($_POST['action']) && $_POST['action'] === 'login_user'): ?>
                    <div class="message error"><?php echo $error_message_login; ?></div>
                <?php endif; ?>

                <h3>Registrarse</h3>
                <form action="" method="POST">
                    <input type="hidden" name="action" value="register_user">
                    <label for="reg_username">Usuario:</label>
                    <input type="text" id="reg_username" name="username" required>

                    <label for="reg_password">Contraseña:</label>
                    <input type="password" id="reg_password" name="password" required>

                    <div class="hcaptcha-container">
                        <div class="h-captcha" data-sitekey="b7f042ec-0a80-4be0-8c0a-e0daaf248e66"></div>
                    </div>
                    <button type="submit">Registrarse</button>
                </form>

                <hr style="margin: 20px 0; border-top: 1px dashed #D9BFB7;">

                <h3>Iniciar Sesión</h3>
                <form action="" method="POST">
                    <input type="hidden" name="action" value="login_user">
                    <label for="login_username">Usuario:</label>
                    <input type="text" id="login_username" name="username" required>

                    <label for="login_password">Contraseña:</label>
                    <input type="password" id="login_password" name="password" required>

                    <div class="hcaptcha-container">
                        <div class="h-captcha" data-sitekey="b7f042ec-0a80-4be0-8c0a-e0daaf248e66"></div>
                    </div>
                    <button type="submit">Iniciar Sesión</button>
                </form>
            </div>
        </div>

        <div class="main-content">
            <?php
            // Display groups
            $grupos = json_decode(file_get_contents('grupos.json'), true);
            if (!empty($grupos)) {
                // Reverse the array to show most recent first
                $grupos = array_reverse($grupos);
                foreach ($grupos as $grupo) {
                    echo '<div class="group-card" onclick="window.open(\'' . htmlspecialchars($grupo['link']) . '\', \'_blank\');">';
                    echo '<h3>' . htmlspecialchars($grupo['nombre']) . '</h3>';
                    echo '<p>' . htmlspecialchars($grupo['descripcion']) . '</p>';
                    if (!empty($grupo['tags'])) {
                        echo '<div class="tags">';
                        foreach ($grupo['tags'] as $tag) {
                            echo '<span>' . htmlspecialchars(trim($tag)) . '</span>';
                        }
                        echo '</div>';
                    }
                    echo '</div>';
                }
            } else {
                echo '<p style="text-align: center; width: 100%; color: #800;">No hay grupos publicados aún. ¡Sé el primero en subir uno!</p>';
            }
            ?>
        </div>
    </div>
    <div id="home-footer">
        <ul>
            <li><a href="#">Inicio</a></li>
            <li><a href="#">Ayuda</a></li>
            <li><a href="#">Términos</a></li>
            <li><a href="#">Privacidad</a></li>
        </ul>
        <p class="footer-text">&copy; 2025 KaitoNeko. Todos los derechos reservados.</p>
    </div>

    <script>
        // Logic to open/close the add group modal
        document.getElementById('plusIcon').addEventListener('click', function() {
            document.getElementById('addGroupModal').style.display = 'flex';
        });
        document.getElementById('closeAddGroupModal').addEventListener('click', function() {
            document.getElementById('addGroupModal').style.display = 'none';
        });
        window.addEventListener('click', function(event) {
            if (event.target == document.getElementById('addGroupModal')) {
                document.getElementById('addGroupModal').style.display = 'none';
            }
        });

        // Logic to open/close the authentication modal
        document.getElementById('userIcon').addEventListener('click', function() {
            document.getElementById('authModal').style.display = 'flex';
        });
        document.getElementById('closeAuthModal').addEventListener('click', function() {
            document.getElementById('authModal').style.display = 'none';
        });
        window.addEventListener('click', function(event) {
            if (event.target == document.getElementById('authModal')) {
                document.getElementById('authModal').style.display = 'none';
            }
        });

        // If a form was submitted and there was an error, keep the modal open
        <?php if (!empty($error_message) && isset($_POST['action']) && $_POST['action'] === 'upload_group'): ?>
            document.getElementById('addGroupModal').style.display = 'flex';
        <?php endif; ?>
        <?php if ((!empty($error_message_reg) && isset($_POST['action']) && $_POST['action'] === 'register_user') || (!empty($error_message_login) && isset($_POST['action']) && $_POST['action'] === 'login_user')): ?>
            document.getElementById('authModal').style.display = 'flex';
        <?php endif; ?>

        // Display success messages after redirect
        <?php if ($group_uploaded_success): ?>
            document.getElementById('addGroupModal').style.display = 'flex';
            setTimeout(function() {
                // Find and hide the success message after a few seconds
                const successMsg = document.querySelector('#addGroupModal .message.success');
                if (successMsg) successMsg.style.display = 'none';
                // Optionally close the modal after some time
                // document.getElementById('addGroupModal').style.display = 'none';
            }, 5000); // Hide after 5 seconds
        <?php endif; ?>

        <?php if ($registered_success): ?>
            document.getElementById('authModal').style.display = 'flex';
            setTimeout(function() {
                const successMsg = document.querySelector('#authModal .message.success');
                if (successMsg) successMsg.style.display = 'none';
            }, 5000);
        <?php endif; ?>

        // Basic search functionality (client-side only, doesn't filter PHP)
        document.querySelector('.search-bar').addEventListener('keyup', function() {
            const searchTerm = this.value.toLowerCase();
            const groupCards = document.querySelectorAll('.group-card');
            groupCards.forEach(card => {
                const groupName = card.querySelector('h3').textContent.toLowerCase();
                const groupDescription = card.querySelector('p').textContent.toLowerCase();
                const groupTags = card.querySelectorAll('.tags span');
                let tagsMatch = false;
                groupTags.forEach(tag => {
                    if (tag.textContent.toLowerCase().includes(searchTerm)) {
                        tagsMatch = true;
                    }
                });

                if (groupName.includes(searchTerm) || groupDescription.includes(searchTerm) || tagsMatch) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        });

        // Handle settings icon (not implemented in this example, just a placeholder)
        document.getElementById('settingsIcon').addEventListener('click', function() {
            alert('Funcionalidad de ajustes no implementada.');
        });
    </script>
</body>
</html>
