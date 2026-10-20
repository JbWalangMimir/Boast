<?php

function contactColumns(mysqli $conn): array
{
    $fields  = [];
    $types   = [];
    $primary = null;

    foreach ($conn->query('SHOW COLUMNS FROM contact_messages') as $c) {
        $key          = strtolower($c['Field']);
        $fields[$key] = $c['Field'];
        $types[$key]  = $c['Type'];
        if ($c['Key'] === 'PRI' && $primary === null) {
            $primary = $c['Field'];
        }
    }

    $first = function (array $options) use ($fields): ?string {
        foreach ($options as $option) {
            if (isset($fields[$option])) {
                return $fields[$option];
            }
        }
        return null;
    };

    $quote = function (string $field): string {
        return '`' . str_replace('`', '``', $field) . '`';
    };

    $nameField = $first(['name', 'fullname', 'full_name', 'sender', 'sender_name', 'your_name', 'contact_name', 'customer_name', 'username', 'user_name']);
    $nameExpr  = $nameField !== null ? $quote($nameField) : null;

    if ($nameExpr === null) {
        $firstName = $first(['first_name', 'firstname', 'fname']);
        $lastName  = $first(['last_name', 'lastname', 'lname']);
        if ($firstName !== null) {
            $nameExpr = $lastName !== null
                ? "CONCAT_WS(' ', " . $quote($firstName) . ', ' . $quote($lastName) . ')'
                : $quote($firstName);
        }
    }

    $emailField   = $first(['email', 'email_address', 'emailaddress', 'sender_email', 'user_email', 'e_mail', 'mail']);
    $subjectField = $first(['subject', 'topic', 'title', 'reason']);
    $messageField = $first(['message', 'messages', 'msg', 'comments', 'comment', 'feedback', 'body', 'content', 'details', 'description', 'concern', 'inquiry', 'text']);
    $createdField = $first(['created_at', 'createdat', 'date_created', 'submitted_at', 'sent_at', 'date_sent', 'created_on', 'timestamp', 'datetime', 'date', 'created', 'submitted']);

    if (isset($types['status']) && stripos($types['status'], "'resolved'") !== false) {
        $statusField = $fields['status'];
    } elseif (!isset($types['status'])) {
        $conn->query("ALTER TABLE contact_messages ADD COLUMN status ENUM('new','read','resolved') NOT NULL DEFAULT 'new'");
        $statusField = 'status';
    } else {
        if (!isset($fields['admin_status'])) {
            $conn->query("ALTER TABLE contact_messages ADD COLUMN admin_status ENUM('new','read','resolved') NOT NULL DEFAULT 'new'");
        }
        $statusField = 'admin_status';
    }

    $idCol       = $quote($primary ?? 'id');
    $subjectExpr = $subjectField !== null ? $quote($subjectField) : "''";
    $createdExpr = $createdField !== null ? $quote($createdField) : 'NOW()';

    $missing = [];
    if ($nameExpr === null) {
        $missing[] = 'name';
    }
    if ($emailField === null) {
        $missing[] = 'email';
    }
    if ($messageField === null) {
        $missing[] = 'message';
    }

    $select = '';
    if (count($missing) === 0) {
        $select = $idCol . ' AS id, '
            . $nameExpr . ' AS name, '
            . $quote($emailField) . ' AS email, '
            . $subjectExpr . ' AS subject, '
            . $quote($messageField) . ' AS message, '
            . $quote($statusField) . ' AS status, '
            . $createdExpr . ' AS created_at';
    }

    return [
        'ok'      => count($missing) === 0,
        'missing' => $missing,
        'columns' => array_values($fields),
        'select'  => $select,
        'id'      => $idCol,
        'status'  => $quote($statusField),
        'created' => $createdExpr,
        'raw'     => [
            'name'    => $nameField,
            'email'   => $emailField,
            'subject' => $subjectField,
            'message' => $messageField,
        ],
    ];
}

function contactProblem(array $contact): string
{
    return 'Could not match these columns in contact_messages: ' . implode(', ', $contact['missing'])
        . '. Columns found: ' . implode(', ', $contact['columns']) . '.';
}