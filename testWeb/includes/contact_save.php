<?php
require_once __DIR__ . '/contact_columns.php';

function saveContactMessage(mysqli $conn, array $post): bool
{
    $raw = contactColumns($conn)['raw'];

    if ($raw['name'] === null || $raw['email'] === null || $raw['message'] === null) {
        return false;
    }

    $field = function (array $keys, int $max) use ($post): string {
        foreach ($keys as $key) {
            if (isset($post[$key]) && is_string($post[$key])) {
                return mb_substr(trim($post[$key]), 0, $max);
            }
        }
        return '';
    };

    $name    = $field(['name', 'fullname', 'full_name', 'your_name'], 100);
    $email   = $field(['email', 'emailaddress', 'email_address'], 150);
    $subject = $field(['subject', 'topic'], 150);
    $message = $field(['message', 'msg', 'comments', 'feedback'], 5000);

    if ($name === '' || $message === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $values = [
        $raw['name']    => $name,
        $raw['email']   => $email,
        $raw['message'] => $message,
    ];
    if ($raw['subject'] !== null) {
        $values[$raw['subject']] = $subject;
    }

    $columns = implode(', ', array_map(function ($c) {
        return '`' . str_replace('`', '``', $c) . '`';
    }, array_keys($values)));
    $marks  = implode(', ', array_fill(0, count($values), '?'));
    $params = array_values($values);

    $stmt = $conn->prepare("INSERT INTO contact_messages ($columns) VALUES ($marks)");
    $stmt->bind_param(str_repeat('s', count($params)), ...$params);
    return $stmt->execute();
}