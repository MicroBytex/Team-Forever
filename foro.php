<?php
// Activar errores de PHP para depuración (¡DESACTIVAR EN PRODUCCIÓN!)
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

// --- Configuración ---
// Clave de API de ImgBB (¡Cuidado al exponerla en el frontend en producción!)
$imgbb_api_key = "a829ef97aa2f2e24d7871d6b3ef0b52e"; 

// Claves de hCaptcha
$hcaptcha_site_key = "b7f042ec-0a80-4be0-8c0a-e0daaf248e66"; 

// Archivos de datos
$threads_file = 'threads.json';
$replies_file = 'replies.json';

// Asegurarse de que los archivos JSON existan y estén inicializados
if (!file_exists($threads_file)) {
    file_put_contents($threads_file, json_encode([]));
}
if (!file_exists($replies_file)) {
    file_put_contents($replies_file, json_encode([]));
}

// --- Funciones auxiliares ---

/**
 * Sube una imagen a ImgBB y devuelve su URL.
 * @param string $image_path Ruta temporal del archivo de imagen.
 * @param string $api_key Clave de API de ImgBB.
 * @return string|false URL de la imagen o false en caso de error.
 */
function uploadToImgBB($image_path, $api_key) {
    if (!function_exists('curl_init')) {
        error_log("cURL no está habilitado para la subida a ImgBB.");
        return false;
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.imgbb.com/1/upload');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, [
        'key' => $api_key,
        'image' => base64_encode(file_get_contents($image_path))
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30); // 30 segundos de timeout
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        error_log("Error de cURL al subir a ImgBB: " . curl_error($ch));
        curl_close($ch);
        return false;
    }
    curl_close($ch);

    $result = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("ImgBB: Error al decodificar la respuesta JSON: " . json_last_error_msg());
        error_log("Respuesta cruda de ImgBB: " . $response);
        return false;
    }

    if (isset($result['data']['url'])) {
        return $result['data']['url'];
    } else {
        error_log("ImgBB: No se obtuvo URL de imagen. Mensaje de error: " . ($result['error']['message'] ?? 'Desconocido'));
        return false;
    }
}

// --- Lógica de procesamiento de formularios ---

// Publicar nuevo hilo (OP)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'new_thread') {
    $subject = htmlspecialchars($_POST['subject'] ?? '');
    $message = htmlspecialchars($_POST['message'] ?? '');
    $hcaptcha_response = $_POST['h-captcha-response'] ?? '';

    if (empty($message)) {
        $error_message = "El mensaje no puede estar vacío.";
    } elseif (empty($hcaptcha_response)) {
        $error_message = "Por favor, completa el hCaptcha.";
    } else {
        // Validación de hCaptcha (básica)
        $captcha_valido = !empty($hcaptcha_response); // ¡Recuerda, validación server-side es crucial!

        if ($captcha_valido) {
            $image_url = '';
            if (isset($_FILES['thread_image']) && $_FILES['thread_image']['error'] === UPLOAD_ERR_OK) {
                $file_tmp_path = $_FILES['thread_image']['tmp_name'];
                $file_name = $_FILES['thread_image']['name'];
                $file_size = $_FILES['thread_image']['size'];
                $file_type = $_FILES['thread_image']['type'];
                $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
                $max_file_size = 5 * 1024 * 1024; // 5MB

                if (in_array($file_type, $allowed_types) && $file_size <= $max_file_size) {
                    $uploaded_url = uploadToImgBB($file_tmp_path, $imgbb_api_key);
                    if ($uploaded_url) {
                        $image_url = $uploaded_url;
                    } else {
                        $error_message = "Error al subir la imagen a ImgBB.";
                    }
                } else {
                    $error_message = "Tipo de archivo no permitido o tamaño excedido (máx. 5MB, formatos: JPG, PNG, GIF).";
                }
            }

            if (!isset($error_message)) {
                $threads = json_decode(file_get_contents($threads_file), true);
                $thread_id = uniqid();
                $new_thread = [
                    'id' => $thread_id,
                    'subject' => $subject,
                    'message' => $message,
                    'image' => $image_url,
                    'timestamp' => date('Y/m/d H:i:s'), // Formato de fecha 4chan
                    'ip' => $_SERVER['REMOTE_ADDR'] // Solo para fines de ejemplo, no usar en prod sin anonimizar
                ];
                $threads[] = $new_thread;
                file_put_contents($threads_file, json_encode($threads, JSON_PRETTY_PRINT));
                header('Location: ' . $_SERVER['PHP_SELF'] . '?thread_id=' . $thread_id); // Redirigir al hilo
                exit();
            }
        } else {
            $error_message = "Por favor, completa el hCaptcha.";
        }
    }
}

// Publicar respuesta
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'new_reply') {
    $thread_id = htmlspecialchars($_POST['thread_id'] ?? '');
    $message = htmlspecialchars($_POST['message'] ?? '');
    $hcaptcha_response = $_POST['h-captcha-response'] ?? '';

    if (empty($message)) {
        $error_message_reply = "El mensaje no puede estar vacío.";
    } elseif (empty($hcaptcha_response)) {
        $error_message_reply = "Por favor, completa el hCaptcha.";
    } else {
        $captcha_valido = !empty($hcaptcha_response); // ¡Recuerda, validación server-side es crucial!

        if ($captcha_valido) {
            $image_url = '';
            if (isset($_FILES['reply_image']) && $_FILES['reply_image']['error'] === UPLOAD_ERR_OK) {
                $file_tmp_path = $_FILES['reply_image']['tmp_name'];
                $file_name = $_FILES['reply_image']['name'];
                $file_size = $_FILES['reply_image']['size'];
                $file_type = $_FILES['reply_image']['type'];
                $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
                $max_file_size = 5 * 1024 * 1024; // 5MB

                if (in_array($file_type, $allowed_types) && $file_size <= $max_file_size) {
                    $uploaded_url = uploadToImgBB($file_tmp_path, $imgbb_api_key);
                    if ($uploaded_url) {
                        $image_url = $uploaded_url;
                    } else {
                        $error_message_reply = "Error al subir la imagen a ImgBB.";
                    }
                } else {
                    $error_message_reply = "Tipo de archivo no permitido o tamaño excedido (máx. 5MB, formatos: JPG, PNG, GIF).";
                }
            }

            if (!isset($error_message_reply)) {
                $replies = json_decode(file_get_contents($replies_file), true);
                $new_reply = [
                    'id' => uniqid(),
                    'thread_id' => $thread_id,
                    'message' => $message,
                    'image' => $image_url,
                    'timestamp' => date('Y/m/d H:i:s'), // Formato de fecha 4chan
                    'ip' => $_SERVER['REMOTE_ADDR'] // Solo para fines de ejemplo
                ];
                $replies[] = $new_reply;
                file_put_contents($replies_file, json_encode($replies, JSON_PRETTY_PRINT));
                header('Location: ' . $_SERVER['PHP_SELF'] . '?thread_id=' . $thread_id . '#reply-' . $new_reply['id']); // Redirigir al hilo y al post
                exit();
            }
        } else {
            $error_message_reply = "Por favor, completa el hCaptcha.";
        }
    }
}

// --- Obtener datos para mostrar ---
$current_thread_id = $_GET['thread_id'] ?? null;
$threads = json_decode(file_get_contents($threads_file), true);
$replies = json_decode(file_get_contents($replies_file), true);

$display_threads = [];
$display_replies = [];

if ($current_thread_id) {
    // Mostrar un solo hilo y sus respuestas
    foreach ($threads as $thread) {
        if ($thread['id'] === $current_thread_id) {
            $display_threads[] = $thread;
            foreach ($replies as $reply) {
                if ($reply['thread_id'] === $current_thread_id) {
                    $display_replies[] = $reply;
                }
            }
            break;
        }
    }
    // Si no se encuentra el hilo, volver a la página principal
    if (empty($display_threads)) {
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }
} else {
    // Mostrar todos los hilos en la página principal (orden inverso para los más recientes primero)
    $display_threads = array_reverse($threads); 
}

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#9dca1d">
    <title>Team Forever Foro</title>
    <link rel="icon" href="https://i.postimg.cc/7Zwkmghd/Chat-GPT-Image-8-jun-2025-02-45-12-a-m.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://js.hcaptcha.com/1/api.js" async defer></script>
    <style>
        /* Estilos generales para 4chan-like */
        body {
            font-family: sans-serif;
            margin: 0;
            padding: 0;
            background-color: #E0E0CC; /* Fondo 4chan */
            color: #800; /* Texto principal */
            font-size: 13px;
        }

        a {
            color: #800; /* Color de enlaces */
            text-decoration: none;
        }
        a:hover {
            color: #e00; /* Color de hover de enlaces */
        }

        hr {
            border: 0;
            height: 1px;
            background: #D9BFB7;
            margin: 10px 0;
        }

        /* Colores Morados Personalizados */
        :root {
            --purple-dark: #6A057F; /* Morado oscuro para títulos */
            --purple-medium: #8A2BE2; /* Morado medio para resaltado */
            --purple-light: #E6D9F0; /* Morado claro para fondos suaves */
            --red-4chan: #cc1105;
            --green-4chan: #117743;
        }

        .header {
            background-color: var(--purple-light); /* Fondo morado claro */
            border-bottom: 1px solid var(--purple-medium); /* Borde morado */
            padding: 10px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header-left {
            font-size: 24px;
            font-weight: bold;
            color: var(--purple-dark); /* Título morado oscuro */
        }
        .header-right {
            display: flex;
            gap: 15px;
        }
        .header-right a {
            color: var(--purple-dark);
            font-weight: bold;
        }
        .header-right a:hover {
            color: var(--purple-medium);
        }

        #board-content {
            width: 57.69em;
            min-width: 750px;
            margin: 20px auto;
            padding: 10px;
            background-color: #FED; /* Fondo de foro */
            border: 1px solid #D9BFB7; /* Borde general */
        }

        /* Formulario de Nuevo Hilo/Post */
        .post-form-container {
            border: 1px solid #D9BFB7;
            background-color: var(--purple-light); /* Fondo morado claro */
            padding: 15px;
            margin-bottom: 20px;
            text-align: left;
        }
        .post-form-container h2 {
            margin-top: 0;
            color: var(--purple-dark);
        }
        .post-form-container label {
            display: block;
            margin-bottom: 5px;
            color: #800;
        }
        .post-form-container input[type="text"],
        .post-form-container input[type="file"],
        .post-form-container textarea {
            width: calc(100% - 12px);
            padding: 5px;
            margin-bottom: 10px;
            border: 1px solid #D9BFB7;
            background-color: #fff;
            color: #333;
        }
        .post-form-container textarea {
            resize: vertical;
            min-height: 80px;
        }
        .post-form-container button {
            background-color: var(--purple-medium);
            color: white;
            padding: 8px 15px;
            border: none;
            cursor: pointer;
            font-size: 13px;
            width: 100%;
            margin-top: 10px;
        }
        .post-form-container button:hover {
            background-color: var(--purple-dark);
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
            background-color: #D4EDDA;
            color: #155724;
            border-color: #C3E6CB;
        }
        .message.error {
            background-color: #F8D7DA;
            color: #721C24;
            border-color: #F5C6CB;
        }
        .captcha-container {
            margin: 10px 0;
            display: flex;
            justify-content: center;
        }

        /* Estilos de hilos y posts */
        .thread {
            text-align: left;
            background-color: #f0f0f0; /* Un ligero fondo para distinguir hilos en listados */
            margin-bottom: 10px;
            padding: 5px;
            border: 1px solid #ccc;
            overflow: auto; /* Para contener floats */
        }

        .thread-container { /* Usado en board.html para agrupar hilos */
            clear: both;
            margin: 0;
            height: auto;
        }

        .op-post { /* Estilo específico para el post original en la vista de hilo */
            background-color: #FED; /* Fondo claro para OP */
            border: 1px solid #D9BFB7;
            padding: 10px;
            margin-bottom: 10px;
            overflow: auto;
            position: relative; /* Para posicionar la imagen correctamente */
            padding-right: 140px; /* Espacio para la imagen a la derecha */
        }

        .hideIcon { /* Para el botón de ocultar/mostrar, si se implementa */
            float: left;
            margin-right: 5px;
            cursor: pointer;
            color: #800;
            font-weight: bold;
        }

        .hideIcon:hover {
            color: #e00;
        }

        .threadThumbnail { /* Imagen de miniatura en la vista de tablero (a la izquierda del texto) */
            float: left;
            margin-left: 0; 
            margin-right: 15px; /* Espacio a la derecha del thumbnail */
            margin-top: 3px;
            margin-bottom: 5px;
            border: 1px solid #aaa;
            max-height: 125px; /* Limitar altura de thumbnails */
            max-width: 125px; /* Limitar anchura de thumbnails */
        }

        .threadImg { /* Clase para la imagen principal del OP en la vista de hilo (flotada a la derecha) */
            height: auto;
            max-width: 125px; /* Tamaño por defecto para la imagen del OP en la vista de hilo */
            max-height: 125px; /* Altura máxima */
            float: right;
            margin-left: 15px; /* Espacio a la izquierda de la imagen */
            margin-bottom: 5px;
            border: 1px solid #aaa; /* Borde para la imagen del post */
        }

        .expandedImg { /* Si se implementa expansión de imagen */
            max-width: none !important;
            max-height: none !important;
        }

        .comment-info { /* Contenedor para metadatos del post */
            overflow: auto;
            margin: 0 5px 5px 5px;
            font-size: 93%;
        }

        .comment-info span {
            margin-right: 10px;
        }

        #thread-author, .post-author { /* Usar clase para autores de respuestas */
            color: var(--green-4chan);
            font-weight: bold;
        }

        #thread-title, .post-subject { /* Usar clase para asuntos de respuestas */
            color: var(--red-4chan);
            font-weight: bold;
        }

        #thread-id, .post-id { /* Usar clase para IDs de posts */
            font-weight: bold;
        }

        #thread-message, .post-message { /* Usar clase para el cuerpo del mensaje */
            font-size: inherit;
            clear: none; /* No clear left/right para que el texto fluya alrededor de la imagen */
            padding-top: 5px;
            word-wrap: break-word;
            overflow: hidden; /* Oculta si el texto es demasiado largo, aunque word-wrap ayuda */
        }

        .summary { /* Para el texto "X respuestas omitidas" */
            color: #707070;
            font-size: 85%;
            margin-left: 20px; /* Alinear con el contenido del post */
        }

        .reply-container {
            margin-left: 20px; /* Indentación para respuestas */
            margin-bottom: 5px;
        }
        .reply {
            background-color: #f0e0d6; /* Fondo para respuestas */
            border: 1px solid #D9BFB7;
            padding: 2px 5px 5px 5px;
            margin: 0 0 5px 0;
            min-width: 300px;
            overflow: auto; /* Para contener floats */
            position: relative; /* Para la imagen a la derecha */
            padding-right: 140px; /* Espacio para la imagen */
        }
        .reply-container span, .sideArrow {
            /* No se usa float aquí para mayor control */
        }
        .sideArrow { /* Para las flechas ">>" antes de una respuesta */
            color: #800; /* Color 4chan para enlaces */
            margin-right: 5px;
            float: left; /* Para que la flecha flote */
        }

        .comment { /* Contenedor del mensaje y posible imagen en una respuesta */
            margin: 5px 0px 0px 0px;
            overflow: hidden; /* Oculta si el texto es demasiado largo, sin clear */
            clear: none;
        }

        .comment .threadThumbnail { /* Imagen en una respuesta (flotada a la derecha) */
            float: right;
            margin-left: 15px; /* Espacio a la izquierda de la imagen */
            margin-right: 0;
        }
        
        /* GIF de ejemplo (ajusta la URL y la posición según tu gusto) */
        .header-gif {
            max-height: 50px; /* Ajusta el tamaño del GIF */
            vertical-align: middle;
            margin-left: 10px;
        }


        /* Footer */
        .footer {
            font-size: 93%;
            text-align: center;
            clear: both;
            border-top: 1px solid #D9BFB7;
            margin-top: 40px;
            padding-top: 15px;
            background-color: #FED;
            color: #800;
        }
        .footer ul {
            display: table;
            margin: auto;
            padding: 0;
            list-style: none;
        }
        .footer li {
            background: #FED;
            display: block;
            float: left;
            border: 1px solid #D9BFB7;
            padding-left: 1em;
            padding-right: 1em;
            padding-bottom: 2px;
            border-left: none;
            margin-top: -1px;
            padding-top: 2px;
            font-size: 0.9em;
        }
        .footer li:first-child {
            border-left: 1px solid #D9BFB7;
        }
        .footer li a {
            color: #800;
            text-decoration: none;
        }
        .footer li a:hover {
            color: #c63;
        }
        .footer-text {
            padding-bottom: 15px;
            margin-top: 10px;
        }

        /* Estilos Responsivos */
        @media (max-width: 768px) {
            #board-content {
                width: auto;
                min-width: unset;
                margin: 10px auto;
                padding: 5px;
            }
            .header {
                flex-direction: column;
                align-items: flex-start;
                padding: 10px;
            }
            .header-right {
                margin-top: 10px;
                width: 100%;
                justify-content: center;
            }
            .post-form-container input[type="text"],
            .post-form-container input[type="file"],
            .post-form-container textarea {
                width: calc(100% - 10px); /* Ajuste de padding en móvil */
            }
            .threadThumbnail, .threadImg {
                float: none;
                display: block;
                margin: 5px auto;
                max-width: 100px; /* Reducir en móvil */
                max-height: 100px;
            }
            .op-post, .reply {
                padding-right: 10px; /* Quitar padding derecho extra si la imagen está apilada */
            }
            .reply-container {
                margin-left: 10px;
            }
            .footer ul {
                display: block;
                width: auto;
                padding: 0 10px;
            }
            .footer li {
                float: none;
                display: block;
                border-left: 1px solid #D9BFB7;
                margin-top: 5px;
            }
            .footer li:first-child {
                margin-top: 0;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-left">
            <a href="<?php echo $_SERVER['PHP_SELF']; ?>">Team Forever Foro</a>
            <img src="https://i.ibb.co/your-gif-link.gif" alt="Animated Logo" class="header-gif"> 
            </div>
        <div class="header-right">
            <a href="#">Ayuda</a>
            <a href="#">Reglas</a>
        </div>
    </div>

    <div id="board-content">
        <?php if (isset($error_message)): ?>
            <div class="message error"><?php echo $error_message; ?></div>
        <?php endif; ?>
        <?php if (isset($error_message_reply)): ?>
            <div class="message error"><?php echo $error_message_reply; ?></div>
        <?php endif; ?>

        <div class="post-form-container">
            <?php if (!$current_thread_id): ?>
                <h2>Crear Nuevo Hilo</h2>
                <form action="" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="new_thread">
                    <label for="subject">Asunto:</label>
                    <input type="text" id="subject" name="subject" maxlength="100">

                    <label for="message">Mensaje:</label>
                    <textarea id="message" name="message" required></textarea>

                    <label for="thread_image">Imagen (Opcional, JPG/PNG/GIF, max 5MB):</label>
                    <input type="file" id="thread_image" name="thread_image" accept="image/jpeg,image/png,image/gif">

                    <div class="captcha-container">
                        <div class="h-captcha" data-sitekey="<?php echo $hcaptcha_site_key; ?>"></div>
                    </div>
                    <button type="submit">Publicar Hilo</button>
                </form>
            <?php else: ?>
                <h2>Responder a este Hilo</h2>
                <form action="" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="new_reply">
                    <input type="hidden" name="thread_id" value="<?php echo htmlspecialchars($current_thread_id); ?>">

                    <label for="reply_message">Mensaje:</label>
                    <textarea id="reply_message" name="message" required></textarea>

                    <label for="reply_image">Imagen (Opcional, JPG/PNG/GIF, max 5MB):</label>
                    <input type="file" id="reply_image" name="reply_image" accept="image/jpeg,image/png,image/gif">

                    <div class="captcha-container">
                        <div class="h-captcha" data-sitekey="<?php echo $hcaptcha_site_key; ?>"></div>
                    </div>
                    <button type="submit">Publicar Respuesta</button>
                </form>
            <?php endif; ?>
        </div>
        <hr>

        <div class="thread-container">
            <?php if (!empty($display_threads)): ?>
                <?php foreach ($display_threads as $thread): ?>
                    <?php if ($current_thread_id): // Si estamos en vista de hilo único, mostrar como OP post ?>
                        <div class="op-post">
                            <?php if (!empty($thread['image'])): ?>
                                <a href="<?php echo htmlspecialchars($thread['image']); ?>" target="_blank">
                                    <img src="<?php echo htmlspecialchars($thread['image']); ?>" class="threadImg" alt="Imagen del hilo">
                                </a>
                            <?php endif; ?>
                            <div class="comment-info">
                                <span id="thread-subject" class="post-subject"><?php echo htmlspecialchars($thread['subject'] ?: 'Sin Asunto'); ?></span>
                                <span id="thread-author">Anónimo</span>
                                <span id="thread-datetime"><?php echo htmlspecialchars($thread['timestamp']); ?></span>
                                <span id="thread-id">No. <a href="<?php echo $_SERVER['PHP_SELF'] . '?thread_id=' . $thread['id']; ?>"><?php echo substr($thread['id'], -8); ?></a></span>
                            </div>
                            <div id="thread-message" class="post-message">
                                <?php echo nl2br(htmlspecialchars($thread['message'])); ?>
                            </div>
                        </div>

                        <?php if (!empty($display_replies)): ?>
                            <?php foreach ($display_replies as $reply): ?>
                                <div class="reply-container" id="reply-<?php echo htmlspecialchars($reply['id']); ?>">
                                    <div class="reply">
                                        <span class="sideArrow">&gt;&gt;</span>
                                        <div class="comment-info">
                                            <span class="post-author">Anónimo</span>
                                            <span class="post-datetime"><?php echo htmlspecialchars($reply['timestamp']); ?></span>
                                            <span class="post-id">No. <?php echo substr($reply['id'], -8); ?></span>
                                        </div>
                                        <div class="comment">
                                            <?php if (!empty($reply['image'])): ?>
                                                <a href="<?php echo htmlspecialchars($reply['image']); ?>" target="_blank">
                                                    <img src="<?php echo htmlspecialchars($reply['image']); ?>" class="threadThumbnail" alt="Imagen de respuesta">
                                                </a>
                                            <?php endif; ?>
                                            <div class="post-message">
                                                <?php echo nl2br(htmlspecialchars($reply['message'])); ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p style="margin-left: 20px; color: #800;">No hay respuestas aún. ¡Sé el primero en responder!</p>
                        <?php endif; ?>

                    <?php else: // Si estamos en la vista de tablero, mostrar hilos como miniaturas ?>
                        <div class="thread">
                            <?php if (!empty($thread['image'])): ?>
                                <a href="<?php echo $_SERVER['PHP_SELF'] . '?thread_id=' . $thread['id']; ?>">
                                    <img src="<?php echo htmlspecialchars($thread['image']); ?>" class="threadThumbnail" alt="Thumbnail del hilo">
                                </a>
                            <?php endif; ?>
                            <div class="comment-info">
                                <span class="post-subject"><a href="<?php echo $_SERVER['PHP_SELF'] . '?thread_id=' . $thread['id']; ?>"><?php echo htmlspecialchars($thread['subject'] ?: 'Sin Asunto'); ?></a></span>
                                <span class="post-author">Anónimo</span>
                                <span class="post-datetime"><?php echo htmlspecialchars($thread['timestamp']); ?></span>
                                <span class="post-id">No. <a href="<?php echo $_SERVER['PHP_SELF'] . '?thread_id=' . $thread['id']; ?>"><?php echo substr($thread['id'], -8); ?></a></span>
                            </div>
                            <div class="post-message">
                                <?php
                                    // Mostrar solo un fragmento del mensaje para la vista de tablero
                                    $short_message = htmlspecialchars($thread['message']);
                                    if (strlen($short_message) > 300) {
                                        $short_message = substr($short_message, 0, 300) . '...';
                                    }
                                    echo nl2br($short_message);
                                ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php else: ?>
                <p style="text-align: center; color: #800;">No hay hilos publicados aún. ¡Sé el primero en crear uno!</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="footer">
        <ul>
            <li><a href="index.php">Inicio</a></li>
            <li><a href="#">Ayuda</a></li>
            <li><a href="terms.html">Términos</a></li>
            <li><a href="privacy.html">Privacidad</a></li>
        </ul>
        <p class="footer-text">&copy; 2025 KaitoNeko. Todos los derechos reservados.</p>
    </div>
</body>
</html>