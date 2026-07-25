<?php
session_start();
require __DIR__ . '/db.php';

header('Content-Type: text/html; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    ?>
    <div class="auth-error">
        <div class="auth-error-title">Ошибка</div>
        <div class="auth-error-text">Вы не авторизованы.</div>
    </div>
    <?php
    exit;
}

$userId = (int)$_SESSION['user_id'];

// Загружаем пользователя, чтобы убедиться, что он существует
$stmt = $pdo->prepare('SELECT id, nickname, contact FROM users WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    ?>
    <div class="auth-error">
        <div class="auth-error-title">Ошибка</div>
        <div class="auth-error-text">Пользователь не найден.</div>
    </div>
    <?php
    exit;
}

// Собираем данные из формы
$nickname = isset($_POST['nickname']) ? trim($_POST['nickname']) : '';
$contact  = isset($_POST['contact'])  ? trim($_POST['contact'])  : '';

// Валидация
if ($nickname === '' || mb_strlen($nickname, 'UTF-8') < 2) {
    ?>
    <div class="auth-error">
        <div class="auth-error-title">Ошибка</div>
        <div class="auth-error-text">Никнейм должен быть не короче 2 символов.</div>
    </div>
    <?php
    exit;
}

if (mb_strlen($nickname, 'UTF-8') > 64) {
    ?>
    <div class="auth-error">
        <div class="auth-error-title">Ошибка</div>
        <div class="auth-error-text">Никнейм слишком длинный (максимум 64 символа).</div>
    </div>
    <?php
    exit;
}

if ($contact !== '' && mb_strlen($contact, 'UTF-8') > 191) {
    ?>
    <div class="auth-error">
        <div class="auth-error-title">Ошибка</div>
        <div class="auth-error-text">Контакт слишком длинный (максимум 191 символ).</div>
    </div>
    <?php
    exit;
}

// Обработка аватара (опционально)
if (!empty($_FILES['avatar']) && is_array($_FILES['avatar'])) {
    $file = $_FILES['avatar'];

    if ($file['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            ?>
            <div class="auth-error">
                <div class="auth-error-title">Ошибка</div>
                <div class="auth-error-text">Не удалось загрузить файл аватара (код ошибки <?= (int)$file['error'] ?>).</div>
            </div>
            <?php
            exit;
        }

        // Ограничение размера (например, 5 МБ)
        if ($file['size'] > 5 * 1024 * 1024) {
            ?>
            <div class="auth-error">
                <div class="auth-error-title">Ошибка</div>
                <div class="auth-error-text">Файл аватара слишком большой (максимум 5 МБ).</div>
            </div>
            <?php
            exit;
        }

        // Проверка типа
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file['tmp_name']);

        $allowed = ['image/png', 'image/jpeg'];
        if (!in_array($mime, $allowed, true)) {
            ?>
            <div class="auth-error">
                <div class="auth-error-title">Ошибка</div>
                <div class="auth-error-text">Разрешены только PNG и JPG.</div>
            </div>
            <?php
            exit;
        }

        // Папка для аватаров
        $uploadDirFs = __DIR__ . '/uploads/avatars';

        // Пытаемся создать, если её нет
        if (!is_dir($uploadDirFs)) {
            if (!mkdir($uploadDirFs, 0775, true) && !is_dir($uploadDirFs)) {
                ?>
                <div class="auth-error">
                    <div class="auth-error-title">Ошибка</div>
                    <div class="auth-error-text">
                        Не удалось создать папку для аватаров: <code>uploads/avatars</code>.
                        Проверьте права на директорию проекта.
                    </div>
                </div>
                <?php
                exit;
            }
        }

        // Проверяем, можно ли туда писать
        if (!is_writable($uploadDirFs)) {
            ?>
            <div class="auth-error">
                <div class="auth-error-title">Ошибка</div>
                <div class="auth-error-text">
                    Папка <code>uploads/avatars</code> недоступна для записи.
                    Выдайте права (например, 0775 или 0777 для теста).
                </div>
            </div>
            <?php
            exit;
        }

        // Всегда сохраняем под именем userId.png (как ожидает account.php)
        $destPathFs  = $uploadDirFs . '/' . $userId . '.png';

        // Дополнительная проверка, что файл действительно загружен через HTTP
        if (!is_uploaded_file($file['tmp_name'])) {
            ?>
            <div class="auth-error">
                <div class="auth-error-title">Ошибка</div>
                <div class="auth-error-text">Файл не является загруженным через HTTP.</div>
            </div>
            <?php
            exit;
        }

        if (!move_uploaded_file($file['tmp_name'], $destPathFs)) {
            ?>
            <div class="auth-error">
                <div class="auth-error-title">Ошибка</div>
                <div class="auth-error-text">
                    Не удалось сохранить файл аватара.<br>
                    Убедитесь, что веб-сервер имеет права на запись в <code>uploads/avatars</code>.
                </div>
            </div>
            <?php
            exit;
        }
    }
}

// Обновляем только разрешённые поля
try {
    $stmt = $pdo->prepare('UPDATE users SET nickname = :nickname, contact = :contact WHERE id = :id');
    $stmt->execute([
        'nickname' => $nickname,
        'contact'  => ($contact === '' ? null : $contact),
        'id'       => $userId,
    ]);
} catch (PDOException $e) {
    ?>
    <div class="auth-error">
        <div class="auth-error-title">Ошибка</div>
        <div class="auth-error-text">Не удалось сохранить профиль. Попробуйте позже.</div>
    </div>
    <?php
    exit;
}

// Успех
?>
<div class="alert-success">
    Профиль успешно обновлён.
</div>
