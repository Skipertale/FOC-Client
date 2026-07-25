<?php
// config/knowledge_base.php
// Knowledge Base (Правила/Способности) — схема БД и санитизация HTML.

/**
 * Создаёт таблицы базы знаний при первом запуске.
 * Безопасно вызывать много раз.
 */
function kbEnsureSchema(PDO $pdo): void {
    // Правила
    $pdo->exec("CREATE TABLE IF NOT EXISTS kb_rules (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(190) NOT NULL,
        category VARCHAR(80) DEFAULT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        body_html MEDIUMTEXT NOT NULL,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Способности
    $pdo->exec("CREATE TABLE IF NOT EXISTS kb_abilities (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(190) NOT NULL,
        ability_type VARCHAR(40) DEFAULT NULL,
        cost VARCHAR(80) DEFAULT NULL,
        cooldown VARCHAR(80) DEFAULT NULL,
        tags VARCHAR(255) DEFAULT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        description_html MEDIUMTEXT NOT NULL,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/**
 * Очень простая санитизация HTML:
 * - режет <script>/<style>
 * - режет on* атрибуты
 * - режет javascript: в href/src
 * - оставляет только «полезные» теги для форматирования.
 */
function kbSanitizeHtml(string $html): string {
    // Уберём script/style целиком
    $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html) ?? '';
    // Уберём on* атрибуты
    $html = preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? '';
    // Уберём javascript: в ссылках/ресурсах
    $html = preg_replace('/\s(href|src)\s*=\s*("|\')\s*javascript:[^\2]*\2/i', '', $html) ?? '';

    $allowed = '<b><strong><i><em><u><s><br><p><div><span><ul><ol><li><hr>'
             . '<h1><h2><h3><h4><h5><h6>'
             . '<table><thead><tbody><tr><th><td>'
             . '<code><pre><blockquote>'
             . '<a>';

    $html = strip_tags($html, $allowed);

    // Слегка подчистим опасные атрибуты, но оставим class/style (чтобы можно было делать «плашки»)
    // Разрешим href, target, rel, class, style, colspan/rowspan
    $html = preg_replace_callback('#<([a-z0-9]+)([^>]*)>#i', function($m){
        $tag = $m[1];
        $attrs = $m[2] ?? '';

        // Вытащим все атрибуты
        preg_match_all('/\s([a-zA-Z0-9_:-]+)\s*=\s*("[^"]*"|\'[^\']*\')/', $attrs, $mm, PREG_SET_ORDER);
        $keep = [];
        $allowedAttrs = ['href','target','rel','class','style','colspan','rowspan'];

        foreach($mm as $a){
            $name = strtolower($a[1]);
            $val = $a[2];
            if(!in_array($name, $allowedAttrs, true)) continue;

            // Защитимся от javascript: ещё раз
            if(($name === 'href' || $name === 'src') && preg_match('/\s*javascript:/i', $val)) continue;

            $keep[] = ' ' . $name . '=' . $val;
        }

        return '<' . $tag . implode('', $keep) . '>';
    }, $html) ?? '';

    return $html;
}
